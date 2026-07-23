# Workflow-Runtime: Analyse und Optimierungsvorschlaege

Stand: 2026-07-23 · Autor: Claude · Status: **Analyse, keine Codeaenderung**

Dieses Dokument beschreibt, wie die Workflow-Ausfuehrung der AiUserFactory heute
aufgebaut ist, wo die realen Kosten entstehen, welche Funktionen und Dateien auch
dann mitlaufen, wenn der Copilot **nicht** eingeschaltet ist, und in welcher
Reihenfolge das optimiert werden sollte. Alle Aussagen sind am Code belegt
(Datei:Zeile) oder gemessen. Es wurde nichts geaendert.

---

## 0. Zusammenfassung

1. **Die Task-Skripte sind nicht das Problem.** Innerhalb eines Node-Prozesses
   werden sie per `require()` in-process geladen und aufgerufen. Es gibt keinen
   Prozess pro Task-Skript. Der Plugin-Vertrag (`module.exports = { key, run }`)
   ist sauber. Gemessene Ladezeit aller Task-Libs: **2 ms**.
2. **Die Prozesse entstehen eine Ebene hoeher**: ein Node-Prozess **pro Liste**
   (`WorkflowStep`) — und im Copilot-/Studio-Modus sogar **pro Task-Karte**.
3. **Der groesste Zeitverlust ist nicht der Prozessstart (0,5–0,8 s), sondern die
   Poll-Latenz** zwischen den Prozessen: 3 s bzw. 10 s pro Schritt.
4. **Ohne Copilot und ohne Development laufen trotzdem teure Debug-Funktionen
   mit** — insbesondere ein **vollstaendiger DOM-Dump je Task** in ein
   oeffentlich erreichbares Verzeichnis. Das ist Befund `G1` und sowohl ein
   Performance- als auch ein Datenschutzthema.
5. Die von der Fachseite vorgeschlagene Idee „alle benoetigten Task-Skripte vor
   dem Workflow sammeln und mit einem Skript ablaufen lassen" ist im Kern
   richtig und **existiert bereits zur Haelfte** als
   `ClientWorkflowBundleCompiler` — allerdings nur fuer ClientController-Nodes,
   nicht fuer den Serverpfad.

---

## 1. Ist-Architektur

### 1.1 Begriffe und Datenmodell

| Ebene | Modell / Datei | Inhalt |
| --- | --- | --- |
| Workflow | `Workflow` | Name, Slug, `settings_json`, Erfolgskriterien |
| Liste | `WorkflowStep` | `action_key`, `position`, `config_json.tasks`, Routen |
| Task-Karte | JSON in `task_cards` | `key`, `task_key` (Katalog), Konfiguration, Routen |
| Katalog | `app/Services/Workflows/WorkflowTaskCatalog.php` (1.899 Z.) | 47 `node_script`-Eintraege + 7 `php_handler` |
| Runner | `node/workflows/run_step.cjs` (3.472 Z.) | Orchestriert die Tasks **einer** Liste |
| Task-Skript | `node/workflows/tasks/<gruppe>/<name>.cjs` | Eine Task, `run(context)` |

### 1.2 Die drei Ausfuehrungspfade

```
                    WorkflowExecutionService::startStep()
                                  |
        +-------------------------+--------------------------+
        |                         |                          |
   (A) System               (B) ClientController        (C) ClientController
   pro Liste                 pro Liste                   Voll-Bundle
        |                         |                          |
WorkflowTaskRunner::start() remoteRuntime()         ClientWorkflowBundleCompiler
        |                    + NetworkJob                    + NetworkJob
   runtime.json                'workflow_task'              'workflow_run'
        |                         |                          |
 spawnDetachedProcess       Client fuehrt                Client fuehrt
 node run_step.cjs          run_step.cjs aus             ALLE Listen aus
        |                                                    |
  status.json/result.json                              Progress-Callbacks
        |
  MonitorWorkflowStepRunJob (Poll)
```

Pfad **(C)** ist bereits das Zielbild („ein Bundle, ein Lauf"), aber:

* gilt nur fuer Nodes mit Capability `workflow_bundle_v1`
  (`WorkflowExecutionService.php:2032`),
* faellt bei **einer einzigen** nicht portablen Task auf Pfad (B) zurueck
  (`ClientWorkflowBundleCompiler.php:43`, `WorkflowExecutionService.php:2077`),
* existiert fuer den Serverpfad (A) gar nicht.

### 1.3 Prozess-Topologie pro Lauf

`WorkflowTaskRunner::runtimeTasks()` (`WorkflowTaskRunner.php:459`) entscheidet,
wie viele Tasks ein Prozess bekommt:

```php
$tasks = $this->runtimeTasks(
    $step,
    $startTaskKey,
    (bool) $runtimeContext['copilotSupervised'],   // <-- $singleTask
    ! (bool) $runtimeContext['studioSingleTask'],  // <-- $atomicLoop
);
```

Bei `$singleTask === true` wird auf **eine** Karte gekuerzt
(`WorkflowTaskRunner.php:501`, Ausnahme: ein `loop.for_each_element` wird als
ganzes Segment atomar gehalten).

Daraus folgt fuer das mitgelieferte Beispiel
`docs/examples/google-suche-ergebnisse.csv` (6 Listen, 14 Task-Karten):

| Modus | Node-Prozesse | Rechnung |
| --- | --- | --- |
| Normal (Copilot aus) | **7** | 6 Listen + 1 geparkter Browser-Owner |
| Copilot / Studio ueberwacht | **15** | 14 Karten + 1 geparkter Browser-Owner |

Der Browser selbst wird **nicht** neu gestartet: der erste Prozess parkt sich als
Owner (`run_step.cjs:2259` `keepWorkflowBrowserAlive`, Idle-Limit 15 min), alle
Folgeprozesse machen `puppeteer.connect(wsEndpoint)` (`run_step.cjs:1785`).

### 1.4 Task-Skript-Vertrag

```js
// node/workflows/tasks/browser/click.cjs
async function run(context = {}) { /* context.page, context.input, ... */ }
module.exports = { key: 'browser.click', run };
```

Aufruf in `run_step.cjs:2775`:

```js
const scriptPath = path.resolve(basePath, task.node_script);
const module = require(scriptPath);
result = await module.run(context);
```

Das ist ein tragfaehiges Plugin-Modell. **Hier besteht kein Handlungsbedarf.**

---

## 2. Messungen

Gemessen am 2026-07-23 auf der lokalen Windows-Entwicklungsmaschine:

| Groesse | Wert | Anmerkung |
| --- | --- | --- |
| `require('puppeteer-extra')` + Stealth-Plugin | **384 ms** | pro Prozess, unvermeidbar bei Prozess-pro-Schritt |
| alle Runner-Libs (`preview`, `selector`, `browser-launcher`) | **2 ms** | Task-Skripte sind billig |
| Prozessstart gesamt (geschaetzt inkl. Spawn, CDP-Connect, JSON-I/O) | 0,5–0,8 s | |
| Poll-Takt Monitor, erste 60 s | **3 s** | `WorkflowExecutionService.php:50` |
| Poll-Takt Monitor danach | **10 s** | `WorkflowExecutionService.php:56` |
| Watchdog-Schwelle | 120 s | `WorkflowExecutionService.php:47` |

**Ableitung:** Im Copilot-Modus entstehen bei 14 Tasks rund **42 s reine
Wartezeit** (14 × 3 s Poll), waehrend die Prozessstarts zusammen nur ca. 7–11 s
kosten. Die Warteschleife ist etwa **4–6× teurer als der Prozessstart**.
Auf dem Linux-Plesk-Produktivsystem liegt der Prozessstart erfahrungsgemaess
niedriger, die Poll-Latenz ist identisch — das Verhaeltnis verschiebt sich also
noch weiter zugunsten des Poll-Problems.

---

## 3. Befunde zur Ausfuehrung

### B1 — Prozess-Granularitaet ist zu fein

Ein Betriebssystemprozess pro Liste (bzw. pro Task) ist die teuerste
Isolationsgrenze, die man waehlen kann. Jeder Start zahlt Node-Boot,
Stealth-Plugin-Ladung, CDP-Connect, `configureBrowserTimezone` ueber alle Pages,
`runtime.json`-Schreiben und ein initiales `status.json`.

**Belege:** `WorkflowTaskRunner.php:172` (`spawnDetachedProcess`),
`WorkflowTaskRunner.php:1351` (PowerShell `Start-Process` bzw.
`sh -lc setsid nohup`).

### B2 — Rueckkanal ist Datei + Datenbank-Poll statt Push

`run_step.cjs` schreibt `result.json`; PHP erfaehrt davon erst beim naechsten
`MonitorWorkflowStepRunJob`-Tick. Der ClientController-Pfad macht es bereits
richtig (Progress-/Result-Callbacks, siehe README: *„The full client-side workflow
protocol does not depend on the queue worker for live progress or step routing"*).
Der Serverpfad hat diesen Kanal nicht.

### B3 — `writeStatus()` serialisiert bei jedem Task den kompletten Payload

`run_step.cjs:264` (`statusPayload`) baut bei **jedem** Aufruf neu:

* die **komplette** Task-Liste (`runtime.tasks.map(...)`), jede Task einzeln durch
  `redactPublicSecrets()`,
* das **komplette** Event-Array,
* das **komplette** `debugArtifacts`-Array (zweimal — `debugArtifacts` **und**
  `debug_artifacts`),
* dazu `publicWorkflow()` mit rekursivem `cleanForJson()` (Tiefe 8).

Aufrufstellen: 14 in `run_step.cjs`, davon zwei je Task-Karte (`task-started`,
`task-completed`) plus der Preview-Timer alle 3 s (`run_step.cjs:2195`).
Fuer das Artefakt-Manifest existiert bereits ein Throttle
(`DEBUG_MANIFEST_WRITE_INTERVAL_MS = 2000`, `run_step.cjs:68`) — fuer
`status.json` nicht.

### B4 — Zwei Wahrheiten fuer `node_script`

* `WorkflowTaskCatalog.php` enthaelt alle 47 `node_script`-Pfade.
* `WorkflowTaskRunner::normalizeRuntimeTask()` (`WorkflowTaskRunner.php:1089`)
  enthaelt **nochmal** eine `match`-Liste mit ~18 Keys, die `runner` und
  `node_script` ueberschreibt.

Zwei Registraturen ohne Nutzen, mit Drift-Risiko. Zusaetzlich wird nirgends
geprueft, ob eine `node_script`-Datei ueberhaupt existiert; der Fehler erscheint
erst zur Laufzeit als *„Task-Script exportiert keine run()-Funktion"*
(`run_step.cjs:2779`).

### B5 — Dieselben Daten-Tasks dreimal implementiert

| Implementierung | Ort | Genutzt? |
| --- | --- | --- |
| PHP-Klassen | `app/Services/Workflows/Tasks/*.php` | `PersistMailAccountTask` ja (`WorkflowExecutionService.php:3967`); `ResolvePersonDataTask`, `ReadLoginDataTask`, `ReadAccountDataTask` **nirgends instanziiert** |
| Node-Fallback | `run_step.cjs:1657` `runDataTask()` | nur fuer `runner === 'php'` |
| Node-Task-Skripte | `node/workflows/tasks/data/*.cjs` | ja — `normalizeRuntimeTask()` biegt die Keys hierher um |

Der tote Parallelpfad blaeht Katalog und Copilot-Kontext auf.

### B6 — Kein Preload, kein Fail-Fast

Task-Module werden erst beim ersten Auftreten geladen. Ein fehlendes oder
kaputtes Skript faellt mitten im Lauf auf, nicht beim Start.

### B7 — Zwei Compiler, ein Vertrag

`WorkflowTaskRunner::remoteRuntime()` und `::start()` bauen dasselbe
Runtime-Objekt zweimal (`WorkflowTaskRunner.php:23` und `:73`), teilweise mit
abweichenden Feldern (`executionTarget`, `browserProfilePath`,
`chromiumNoSandbox`, `devDebug`-Variante). `ClientWorkflowBundleCompiler` ruft
`remoteRuntime()` je Liste auf und klammert sie zu einem Bundle.

---

## 4. Dev-/Copilot-Gating: Was heute mitlaeuft, obwohl es nicht soll

**Anforderung:** Wenn der Copilot nicht eingeschaltet ist, sollen keine
unnoetigen Funktionen, Dateien und Logs mitlaufen; steuerbar ueber die
Development-Einstellung des Workflows.

### 4.1 Was heute korrekt gesteuert ist

`WorkflowTaskRunner::devDebugRuntimeConfig()` (`WorkflowTaskRunner.php:1185`):

```php
$copilotObservation = (int) $run->workflow_copilot_session_id > 0
    || (int) data_get($run->context_json, 'workflow_copilot_session_id', 0) > 0;

$enabled = $localArtifacts && (
    $copilotObservation
    || filter_var($settings['dev_mode'] ?? false, FILTER_VALIDATE_BOOL)
    || filter_var($settings['development'] ?? false, FILTER_VALIDATE_BOOL)
);
```

Und `run_step.cjs:1243` (`phaseCaptureEnabled`) prueft `devDebugEnabled()`.

**Ergebnis:** Die *phasenbasierten* Debug-Artefakte (`step_*_before.html`,
`step_*_after.png`) und das Artefakt-Manifest laufen bei Copilot aus **und**
Development aus korrekt **nicht** mit. Der Schalter existiert also und wirkt —
aber er wirkt nur auf diesen einen Pfad.

Der Schalter selbst: `settings_json.dev_mode`, UI-Label „Development"
(`resources/views/components/workflows/workflow-form.blade.php:50-62`), gesetzt in
`WorkflowsIndex.php:298` und `:428`.

### 4.2 G1 — Der Live-DOM-Dump ist **nicht** gated (kritisch)

`node/workflows/tasks/lib/preview.cjs:339` ruft in `captureWindow()`
**bedingungslos** `captureDebugDom()` auf:

```js
await windowConfig.page.screenshot({ path: windowConfig.livePreviewPath, fullPage: false });
...
const debugDom = await captureDebugDom(windowConfig, { url, title, targetId })
```

`captureDebugDom()` (`preview.cjs:276`) serialisiert **je Frame**:

* `document.documentElement.outerHTML` — die komplette Seite,
* `document.body.innerText` — der komplette sichtbare Text,
* alle Formularfelder inkl. `value` (Passwort- und Hidden-Felder werden
  ausgenommen, der Rest nicht),
* `window.__workflowDebug` / `window.__workflowMailListScanDebug`,

und schreibt das Ergebnis mit `fs.writeFileSync` nach `live-dom.json`
(`preview.cjs:300-301`).

Die einzige Bedingung ist `enabled(context)` — und das ist die **Live-Vorschau**
(`preview.cjs:25`), nicht der Development-Schalter.

**Wie oft passiert das?** `captureTaskPreview(context, result, force = true)` wird
mit `force = true` aufgerufen und umgeht damit die Intervallsperre
(`preview.cjs:317`). Fast jedes Task-Skript ruft es 2–4× auf (u. a. `click.cjs`,
`open_url.cjs`, `hover.cjs`, `press_key.cjs`, `navigate_back.cjs`, `close.cjs`).
Dazu der 3-Sekunden-Preview-Timer.

**Folgen:**

* **Volumen.** Eine reale Ergebnisseite liegt haeufig im Bereich mehrerer hundert
  KB bis ueber 1 MB HTML je Frame; JSON-kodiert eher mehr. Das faellt pro Task
  und pro Preview-Tick an.
* **Zielverzeichnis ist der oeffentliche Disk.** `livePreviewPath` zeigt auf
  `storage_path('app/public/workflow-task-runs/{runId}/live.png')`
  (`WorkflowTaskRunner.php:81`, `:127`); `debugDomPathFor()` legt `live-dom.json`
  **daneben** (`preview.cjs:98-107`). Ueber den `storage:link` ist das per URL
  erreichbar. Der Dump enthaelt den Seiteninhalt eingeloggter Webmail-Sitzungen.
* **Es laeuft auch dann, wenn Copilot und Development aus sind.**

`G1` ist damit der wichtigste Einzelbefund dieses Abschnitts — sowohl fuer die
Performance als auch fuer den Datenschutz (Teamprotokoll-Regel 6: „Exporte immer
redigieren").

### 4.3 G2 — Live-Vorschau ist global, nicht pro Workflow

`live_preview_enabled` kommt aus den globalen Prozess-Einstellungen
(`WorkflowTaskRunner.php:28`, `:78`; UI `app/Livewire/Admin/Config/ProcessSettings.php`),
Default `true`. Es gibt **keinen** Workflow-Schalter dafuer und keine Kopplung
daran, ob ueberhaupt jemand die Vorschau geoeffnet hat. Der Screenshot-Timer
(`run_step.cjs:2182`) laeuft also bei jedem Lauf, auch nachts, auch ohne Zuschauer
— und jeder Tick schreibt zusaetzlich ein `writeStatus(...)`
(`run_step.cjs:2195`).

### 4.4 G3 — `status.json` transportiert Debug-Felder immer

`statusPayload()` (`run_step.cjs:307-309`) haengt `events`, `debugArtifacts` und
`debug_artifacts` **immer** an, auch wenn `devDebug.enabled === false` und die
Arrays leer sind. Die Redaktionslaeufe darueber laufen trotzdem.

### 4.5 G4 — stdout/stderr werden immer als Dateien angelegt

`WorkflowTaskRunner.php:85-86` legt pro Lauf `stdout.log` und `stderr.log` an, und
`spawnDetachedProcess()` leitet immer dorthin um (`:1363-1364`, `:1374-1376`) —
unabhaengig von Copilot und Development. Bei Prozess-pro-Task sind das zwei
zusaetzliche Dateien **je Task-Karte**.

### 4.6 G5 — Der oeffentliche Disk wird nie aufgeraeumt

`PruneWorkflowProcessArtifacts` (`app/Console/Commands/PruneWorkflowProcessArtifacts.php:32-41`,
taeglich 04:20 via `app/Console/Kernel.php:65`) raeumt:

* `storage/app/workflow-task-runs` (privat) — ja,
* `storage/app/browser-profiles/workflows` — ja,
* `storage/app/public/workflow-task-runs` — **nein**,
* `storage/app/workflow-runs/{uuid}/debug-artifacts` — **nein**.

Genau dort liegen die Screenshots und die `live-dom.json` aus `G1`. Sie wachsen
unbegrenzt.

### 4.7 G6 — Die `dev_capture_*`-Flags werden hart auf `true` gesetzt

`WorkflowsIndex.php:299-300` und `:429-430` schreiben bei jedem Speichern:

```php
$settings['dev_capture_dom_before_step'] = true;
$settings['dev_keep_artifacts'] = true;
```

unabhaengig vom Development-Schalter. Solange `dev_mode` aus ist, greift die
uebergeordnete `$enabled`-Pruefung und es passiert nichts — aber sobald jemand
Development einschaltet, sind sofort **alle** Capture-Arten aktiv, weil auch die
uebrigen Flags in `devDebugRuntimeConfig()` mit `?? true` defaulten
(`WorkflowTaskRunner.php:1210-1214`). Eine feinere Abstufung ist nicht moeglich.

### 4.8 Zielbild: eine Observability-Stufe statt vieler Einzelflags

Vorschlag: `settings_json.observability` mit vier Stufen, abgeleitet statt
einzeln geschaltet.

| Stufe | Wann | Screenshots | DOM-Dump | Phasen-Artefakte | status.json | stdout/stderr |
| --- | --- | --- | --- | --- | --- | --- |
| `off` | Standard, Copilot aus, Development aus | nein | nein | nein | nur bei Zustandswechsel, gedrosselt | nur bei Fehler behalten |
| `preview` | Vorschau-Modal ist offen | im Intervall | nein | nein | wie `off` + Fensterstatus | wie `off` |
| `debug` | Development-Schalter an | im Intervall + je Task | ja | ja | vollstaendig | behalten |
| `copilot` | aktive Copilot-Sitzung | wie `debug` | ja | ja + Vision | vollstaendig | behalten |

Effektive Stufe = Maximum aus (Workflow-Einstellung, aktive Copilot-Sitzung,
angehaengter Vorschau-Zuschauer). Damit bleibt die Copilot-Automatik erhalten,
ohne dass sie ueber ein Dauer-`true` in den Normalbetrieb durchschlaegt.

**„Zuschauer angehaengt" ohne Neustart:** Der Runner liest ohnehin schon Dateien
zur Laufzeit nach (`run_step.cjs:2430` liest `status.json` im Shutdown-Pfad). Eine
kleine `control.json` im Lauf-Verzeichnis, die PHP beim Oeffnen bzw. Schliessen
des Vorschau-Modals schreibt und die der Preview-Tick liest, reicht aus. So
laeuft die Vorschau nur, solange wirklich jemand hinsieht.

### 4.9 Konkrete Aenderungspunkte fuer das Gating

| # | Datei:Zeile | Aenderung |
| --- | --- | --- |
| G1a | `node/workflows/tasks/lib/preview.cjs:339` | `captureDebugDom()` nur bei `context.devDebug?.captureDom === true` aufrufen |
| G1b | `WorkflowTaskRunner.php:107-148` | `devDebug`-Objekt (bzw. `observability`) in die Runtime aufnehmen, damit `preview.cjs` es sieht — heute kennt `preview.cjs` nur `context.preview` |
| G1c | `node/workflows/tasks/lib/preview.cjs:98-107` | DOM-Dump in das **private** Lauf-Verzeichnis schreiben, nicht neben das Public-PNG |
| G2 | `WorkflowTaskRunner.php:28`, `:78` | `livePreviewEnabled` = globale Einstellung **und** (Development **oder** Copilot **oder** Zuschauer angehaengt) |
| G3 | `run_step.cjs:307-309` | `events`/`debugArtifacts` nur anhaengen, wenn Stufe >= `debug`; `debug_artifacts`-Doppelung entfernen |
| G4 | `WorkflowTaskRunner.php:85`, `:1351` | bei Stufe `off` nach erfolgreichem Lauf loeschen oder gar nicht erst anlegen |
| G5 | `PruneWorkflowProcessArtifacts.php:32` | `storage/app/public/workflow-task-runs` und `storage/app/workflow-runs/*/debug-artifacts` in den Prune aufnehmen |
| G6 | `WorkflowsIndex.php:299`, `:429` | `dev_capture_*` an `dev_mode` koppeln statt hart `true`; in `WorkflowTaskRunner.php:1210-1214` Default auf `false` drehen |

Diese Gruppe ist **unabhaengig** vom Umbau der Prozessarchitektur umsetzbar und
sollte zuerst kommen: sie ist klein, risikoarm und wirkt sofort auf jeden Lauf.

---

## 5. Optimierungsplan fuer die Ausfuehrung

### Stufe 0 — risikoarm, keine Schnittstellenaenderung

* **0a — Push statt Poll.** `run_step.cjs` meldet Ergebnis und Fortschritt ueber
  einen signierten internen HTTP-Callback; `MonitorWorkflowStepRunJob` bleibt nur
  als Watchdog. Groesster Einzelgewinn (siehe B2, Abschnitt 2).
* **0b — `status.json` drosseln.** Throttle wie beim Artefakt-Manifest; den
  statischen Teil (Workflow-/Task-Konfiguration) einmal redigieren und cachen
  statt bei jedem Event neu (siehe B3).
* **0c — Task-Module vorladen.** Beim Prozessstart alle in `runtime.tasks`
  referenzierten `node_script` einmal `require`-en: Fail-Fast statt
  Laufzeitfehler, keine Lazy-Latenz mitten im Lauf (siehe B6). Das ist die
  Fachidee „Bibliothek vorher sammeln" auf Modulebene.
* **0d — Eine Registry.** `normalizeRuntimeTask()` entfernen, `node_script` nur
  aus dem Katalog; Test, der fuer jeden Katalogeintrag prueft, dass die Datei
  existiert und `run` exportiert (siehe B4).

### Stufe 1 — Session-Runner statt Prozess-pro-Schritt

Ein **langlebiger Node-Prozess pro Workflow-Lauf**. PHP startet ihn einmal mit
dem kompilierten Bundle; er haelt Browser, Puppeteer, geladene Task-Module und
Kontext im Speicher und arbeitet die Listen durch. Steuerung ueber einen
Kommandokanal (stdin-JSONL, Named Pipe oder Unix-Socket):
`run_task`, `pause`, `resume`, `rewind`, `inject_task`, `stop`.

Aus 7 bzw. 15 Prozessen wird **einer**. Der geparkte Keep-Alive-Prozess entfaellt,
weil der Session-Runner ohnehin lebt — er ist heute faktisch schon ein halber
Daemon (`run_step.cjs:2292` `setInterval` alle 3 s), er nimmt nur keine Arbeit an.

**Stufe 1b — Copilot entkoppeln.** Der Copilot braucht *Checkpoints*, keine
*Prozessgrenzen*. Statt `$singleTask = true` haelt der Session-Runner nach jedem
Task an, meldet Screenshot und DOM per Callback und wartet auf `resume` oder
`repair`. Semantik identisch, Kosten praktisch null. Das ist der groesste Hebel
fuer den Copilot-Modus und macht zugleich die Debug-Erfassung planbar: sie ist
dann an den Checkpoint gebunden statt an jeden Preview-Tick.

### Stufe 2 — ein Ausfuehrungsvertrag, zwei Transporte

`ClientWorkflowBundleCompiler` wird der einzige Compiler; `start()` und
`remoteRuntime()` werden reine Transport-Adapter (lokaler Spawn vs. NetworkJob).
Bundle mit `schemaVersion` **und** `runtimeHash` (SHA-256 ueber `node/workflows`)
versehen — damit wird Teamprotokoll-Regel 7 (ClientController-Sync) maschinell
pruefbar statt manuell. Nebenbei loest das den `portable = false`-Fallback: bis auf
`PersistMailAccountTask` sind alle `php_handler`-Tasks bereits durch Node-Skripte
ersetzt (siehe B5).

### Stufe 3 — Aufraeumen

Tote PHP-Task-Klassen und `runDataTask()` entfernen, Katalog auf eine
Implementierung pro Task reduzieren.

### Was **nicht** empfohlen wird

Die Task-Skripte zu **einer** Datei zu buendeln (Rollup/esbuild). Der Ladeaufwand
betraegt gemessen 2 ms — der Gewinn ist null und die Uebersichtlichkeit geht
verloren. Der Nutzen der Bibliotheksidee liegt in **Registry + Preload + ein
langlebiger Prozess**, nicht im Zusammenfassen der Dateien.

---

## 6. Bewaehrte Muster aus anderer Software

| Muster | Software | Uebertragung |
| --- | --- | --- |
| Durable Execution | Temporal, AWS Step Functions, Azure Durable Functions | Worker-Prozess ist langlebig, *der Zustand* wird persistiert — nicht der Prozess pro Schritt. Die Event-History (`events[]`, Checkpoints) existiert hier bereits; es fehlt nur der langlebige Worker. |
| Graph einmal laden, Node-Registry | n8n, Node-RED, Make | Alle Task-Typen vorab registrieren, Graph in einem Prozess durchlaufen. Nur im „queue mode" ein Worker pro *Execution*, nie pro Node. Entspricht Stufe 1. |
| Worker-Pool mit wiederverwendetem Browser | Playwright, Cypress | Teure Initialisierung (Browser, Stealth) einmal pro Worker. Falls Prozess-pro-Lauf bleibt: Warm-Pool von 1–2 vorgeladenen Runnern spart die 384 ms. |
| External-Task-Pattern | Camunda / Zeebe | Engine (PHP) haelt Zustand und Routing, externer Worker (Node) holt Jobs per Long-Poll und meldet `complete`/`fail`. Passende Alternative zu 0a, falls die PHP/Node-Trennung bewusst bleiben soll. |
| Daemon statt CLI-Aufruf | Language Server Protocol, Chromedriver, ffmpeg-Server | Wenn ein CLI pro Aufruf teure Initialisierung zahlt, wird es zum Daemon mit JSON-Protokoll. Stufe 1 in Reinform. |
| Sampling statt Dauererfassung | OpenTelemetry, Sentry | Tracing-Stufen pro Lauf statt global an/aus; teure Erfassung nur bei Bedarf. Entspricht Abschnitt 4.8. |

---

## 7. Risiken und Gegenmassnahmen

| Risiko | Gegenmassnahme |
| --- | --- |
| Langlebiger Prozess: Speicherlecks, Zombies | `ManagedProcessInventory` / `ManagedProcessSupervisor` existieren; Idle-Limit (15 min) beibehalten; Heap-Grenze setzen |
| Ein Crash reisst den ganzen Lauf mit | Resume-from-Checkpoint statt Resume-from-Step; `WorkflowStudioCheckpointService` ist vorhanden |
| Push-Callback braucht erreichbaren internen Endpunkt | Signatur (HMAC) + `APP_URL`; auf Plesk unkritisch, lokal via XAMPP konfigurierbar |
| Weniger Debug-Daten im Normalbetrieb erschweren Fehlersuche | Development-Schalter bleibt; zusaetzlich „letzte N Laeufe mit `debug` erzwingen" als Notfalloption |
| Gating-Aenderung koennte Copilot-Beobachtung schwaechen | `copilotObservation` bleibt uebergeordnete Stufe; Invariantentest `WorkflowCopilotExecutionInvariantTest` erweitern |

---

## 8. Messgroessen fuer Vorher/Nachher

Pro Lauf erfassen:

1. Anzahl gestarteter Node-Prozesse
2. Summe der Prozess-Startzeiten
3. Summe der Wartezeit zwischen Task-Ende und naechstem Task-Start
4. Anzahl und Gesamtgroesse geschriebener Dateien unter
   `storage/app/workflow-task-runs`, `storage/app/public/workflow-task-runs` und
   `storage/app/workflow-runs`
5. Anzahl `writeStatus`-Aufrufe und geschriebene Bytes

Kennzahl 3 zeigt die Wirkung von 0a, Kennzahl 4 die Wirkung des gesamten
Abschnitts 4.

---

## 9. Empfohlene Reihenfolge

| Paket | Inhalt | Aufwand | Wirkung |
| --- | --- | --- | --- |
| **P1** | Gating G1–G6 (Abschnitt 4.9) | klein | sofort auf jeden Lauf; behebt Datenschutzthema |
| **P2** | 0b, 0c, 0d | klein | Stabilitaet, Fail-Fast, weniger I/O |
| **P3** | 0a Push-Callback + Monitor als Watchdog | mittel | groesster Zeitgewinn |
| **P4** | 1b Copilot-Checkpoints ohne Prozessgrenze | mittel | Copilot-Modus von 15 auf 1 Prozess |
| **P5** | 1 Session-Runner | gross | Normalbetrieb von 7 auf 1 Prozess |
| **P6** | 2 + 3 Compiler-Vereinheitlichung, Aufraeumen | mittel | Wartbarkeit, Ende des `portable=false`-Fallbacks |

---

## 10. Offene Fragen

1. Soll die Live-Vorschau bei Stufe `off` vollstaendig entfallen oder mit stark
   erhoehtem Intervall (z. B. 15 s) weiterlaufen, damit die Uebersichtsliste
   weiterhin ein Vorschaubild zeigt?
2. Sollen bereits erzeugte `live-dom.json`-Dateien im oeffentlichen Verzeichnis
   einmalig geloescht werden (eigener Einmal-Befehl), oder reicht der erweiterte
   Prune?
3. Bleibt der Prozess-pro-Liste-Pfad als Rueckfallebene erhalten, oder wird er
   nach Einfuehrung des Session-Runners entfernt?
