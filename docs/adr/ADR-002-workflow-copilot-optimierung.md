# ADR-002: Workflow-Copilot-Optimierungsalgorithmus — Fehlerursachen und Fixes

**Status:** Accepted — 8 Fixes umgesetzt (A–C, D1, E, F, G, H), 96 Tests/822 Assertions grün; Produktivlauf-Bestätigung offen
**Datum:** 2026-07-19
**Deciders:** Maintainer + Agents (Codex/Claude)
**Grundlage:** Forensische Auswertung von 10 Debug-/Copilot-Exporten (Sessions 13, 15, 17, 19; Runs 402, 424, 429) + Code-Root-Cause im Supervisor/Studio, adversarial gegen die README-Regeln geprüft (Multi-Agent-Workflow).

---

## 1. Kontext

Der autonome **Workflow-Copilot** (Workflow-Studio, autonomer Modus) kommt reproduzierbar **nicht zu erfolgreichen Ergebnissen**, und die **Testmodi wirken dauerhaft gesperrt**. Die 10 Exporte zeigen drei Klassen von Fehlern:

1. **Steuerungs-/Lebenszyklus-Defekte** (Code): Modus-Sperre wird nie gelöst; ein deaktivierter Workflow lässt sich nicht optimieren; „erfolgreiche" Läufe drehen sich endlos im Kreis.
2. **Fortschritts-/Wahrheits-Defekte** (Code): Ein Step meldet „ok/success", obwohl seine Task **fehlgeschlagen** ist — der Supervisor sieht Fehler nie.
3. **Graph-Qualitäts-Defekte** (vom Copilot **generierte** Workflows): fehlende Navigations-URL, Self-Loop-Routing, rückwärtsgerichtete `on_error`-Zyklen, unverdrahtete Loops, unerfüllbare/nicht unterstützte Erfolgskriterien.

Die Klassen 1–2 sind Code-Ursachen dafür, dass Fehler **unsichtbar** bleiben und Läufe nie **enden**; Klasse 3 ist der Grund, warum Läufe selbst bei sauberer Steuerung **fachlich nichts erreichen**.

---

## 2. Fehler-Taxonomie (Evidenz)

| # | Muster | Sessions | Evidenz |
|---|---|---|---|
| A | **Modus dauerhaft gesperrt** | alle | `mode_locked_at` wird in `WorkflowStudioControlService::lock` gesetzt und **nirgends** je zurückgesetzt; `latestOrOpen` verwendet tote Sessions (`paused`/`failed`/`queue_failed` …) wieder → `assertUserControl` blockt jede manuelle Aktion. |
| B | **Deaktivierter Workflow → kein Lauf** | 17 | `queue.supervisor_failed` ×2, `error_summary: "Dieser Workflow ist deaktiviert."`, `workflow_run_id=null`, `manual_resume_required=true`; wf#11 `is_active=false`. Guard [`WorkflowExecutionService:130`](../../app/Services/Workflows/WorkflowExecutionService.php). |
| C | **No-Progress-Endlosschleife** | 19 | 77 Zyklen über **dieselben 3 Tasks** (~26× je), ein einziger `state_signature` ×77, `screenshot_changed=false` ×77, `next_action=complete_step` ×77. `max_same_state_repeats=2` greift nie. |
| D | **False-Success-Masking** | 13,15,19 | Run-429 step-1554 `ok=true/success`, während Task `task-open-instagram` `status=FAILED "Keine URL fuer Navigation uebergeben."`; Run-402 Run `status=completed` trotz fehlgeschlagenem Session-Load-Task. Step-Erfolg ist von Task-Erfolg entkoppelt (jede Route, auch `on_error`, gilt als „ok"). |
| E | **Fehlende URL in `browser.open_url`** | 19 | `final-workflow.json` `task-open-instagram` hat **keinen** `url`/`config`-Key → Navigation bricht ab → Seite bleibt `about:blank`/`unknown_browser_state` → alle Folge-Tasks scheitern auf leerer Seite. |
| F | **Rückwärts-`on_error`-Zyklen** | 15,19 | `task-open-instagram on_error → step-session-save`; `task-save-browser-session on_error → step-browser-init` (rückwärts) → geschlossener Kreis ohne Ausstieg, live in Run-429 25× durchlaufen. |
| G | **Self-Loop-Routing** | 15,19 | `task.next` zeigt auf den **eigenen** Step; nur die Checkpoint-Ebene erzwingt `complete_step` → deklarierte Routen (Erfolg-`end`, Branches) werden ignoriert. |
| H | **Verifikation kann nie bestehen** | 13 | `verification.failed` ×4, alle 4 Assertions `type='unsupported'` ("Assertion-Typ wird nicht deterministisch unterstuetzt") → `technical_pass` hart `false` → Endlos-Reparatur. |
| I | **Reparatur-Oszillation bis Budget-Ende** | 13 | `repairing↔running↔verifying` ×5, identischer `task.failed "Keine gespeicherte Browser-Session gefunden."` ×10 → `budget_exhausted`. |
| J | **Leere Ausgabe / Loop unverdrahtet** | 13,15 | Deklarierte Ausgabe `top_results` nie zurückgegeben; Extraktions-Loop-Body (read+append) übersprungen. |

---

## 3. Entscheidung — umgesetzte Fixes (direkte Optimierung)

Umgesetzt wurden die drei Code-Ursachen, die Fehler **unsichtbar** bzw. Läufe **endlos/gesperrt** machen. Alle respektieren die README-Regeln (nur `execution_target=system` für Copilot-Reparatur, Events append-only, keine zweite Vorschau, kataloggebundene Task-Keys).

### Fix A — Modus-Sperre lösen (Muster A) · *hoch*
- **`WorkflowStudioSessionService::latestOrOpen`**: verwendet keine beendete/tote Sitzung mehr wieder (`whereNull('finished_at')` + Ausschluss von `failed/cancelled/timed_out/lost/budget_exhausted`) → ein neuer Test öffnet eine **frische, entsperrte** Sitzung.
- **`WorkflowStudioControlService::assertUserControl`** (+ `WorkflowStudioTaskEditor::definitionIsEditable`): Sperre gilt nur bei `! finished_at` → eine beendete autonome Sitzung blockt nie mehr.
- **Neu `WorkflowStudioControlService::release()`** + Livewire-Aktion **`WorkflowStudio::unlockControlMode()`** + Button („Modus gesperrt · entsperren") → manueller Override; erzeugt append-only Event `control.unlocked`.

### Fix B — Deaktivierten Workflow optimieren dürfen (Muster B) · *hoch*
- **`WorkflowExecutionService::start`** (Guard): Copilot-/Studio-Optimierungs- und Testläufe (`$copilotSession`/`$studioSession`/`requestedBy ∈ {workflow-copilot, workflow-studio}`) dürfen einen inaktiven Entwurfs-Workflow ausführen. Nur echte Fremdläufe (Manager/Scheduler) eines deaktivierten Workflows bleiben blockiert. Behebt den `manual_resume_required`-Strand aus Session 17.

### Fix C — No-Progress-Schleife brechen (Muster C) · *hoch*
- **`markCheckpointObserved`**: Zähler zählt jetzt einen **Revisit** derselben Task-Signatur unter **unveränderter** Seite (`state_signature`) — statt nur bei identischem Vorgänger-Fingerprint (der bei rotierenden Tasks jeden Zyklus auf 0 sprang). Set wird bei Seitenwechsel (echter Fortschritt) zurückgesetzt.
- **`processCheckpoint` (Erfolgs-Zweig)**: neue **No-Progress-Sperre** — erreicht `same_state_repeats` das Limit, wird der „erfolgreiche" Checkpoint als fachlicher Fehler in `repairFailedCheckpoint` umgeleitet (analog Consent-/Business-Gap) und als `checkpoint.no_progress` protokolliert, statt 77× fortzusetzen.

*Verifikation:* `php -l` auf allen 6 Dateien sauber; `php artisan view:cache` kompiliert alle Views. **Kein Produktivlauf** (lokal keine `.env`) — die Wirkung ist an einem echten Workflow-11-Lauf zu bestätigen.

---

## 4. Die tieferen Ursachen — Analyse und Umsetzungsstand

Die Fixes A–C beheben Sperre, Start und Endlosschleife. Damit Läufe **fachlich erfolgreich** werden, mussten zusätzlich die Klasse-2/3-Defekte adressiert werden. **Umgesetzt sind inzwischen D1, E, F, G und H** (siehe Action Items); offen bleiben D2 und I. Die Analyse je Muster:

### Muster D — False-Success-Masking (Wurzel „scheinbarer Erfolg")
Der Step-Runner meldet `ok/success`, sobald **irgendeine** Route (inkl. `on_error`) gefolgt wird; der Supervisor liest nur Step-Erfolg und nie den **Task-Status**.
- **Option D1 (empfohlen):** Der Supervisor-Checkpoint wertet zusätzlich den **Task-Status** aus (`task.status === FAILED` trotz Step-`ok`) und behandelt das als fachlichen Gap → Reparatur. Ändert keine Run-Semantik (README: `on_error` ist eine gültige Verzweigung), macht aber Fehler für die Optimierung **sichtbar**. Andockpunkt: `successfulCheckpointBusinessGap` (bereits vorhandenes Muster).
- **Option D2:** Step-Runner trennt `route_followed` von `task_ok` im Ergebnis; Run-Completion nur bei erreichtem Erfolgs-`end`, nicht bei ausgeschöpfter `on_error`-Kette. Größerer Eingriff (PHP+Node+ClientController-Sync, Regel 7).

### Muster E/F/G/J — Copilot generiert defekte Graphen
Der Planer/Reparateur emittiert Tasks ohne Pflicht-Parameter (URL), Self-Loops und rückwärts-`on_error`-Zyklen.
- **Empfehlung:** Struktur-Validierung nach Planung/Revision (in `WorkflowStructuralOperationNormalizer`, siehe ADR-001): (a) `browser.open_url` **erfordert** eine nicht-leere `url` (sonst Ablehnung mit Grundcode); (b) `task.next`/Routen dürfen nicht auf den **eigenen** Step zeigen; (c) `on_error`-Kanten mit Rückwärtssprung brauchen einen **Versuchszähler/Ausstieg**, sonst Ablehnung; (d) deklarierte Ausgabe (`top_results`) erzwingt `data.workflow_return` + verdrahteten Loop-Body.

### Muster H — Verifikation kann nie bestehen
Erfolgskriterien nutzen einen Assertion-Typ, den der deterministische Verifier nicht kennt (`type='unsupported'`).
- **Empfehlung:** Im `WorkflowSuccessCriteriaEvaluator` (Extraktion siehe ADR-001) unterstützte Assertion-Typen erweitern **oder** unbekannte Kriterien bei der Erstplanung ablehnen/normalisieren, statt sie hart auf `false` zu setzen (das erzwingt Endlos-Reparatur trotz technisch korrektem Lauf).

### Muster I — Reparatur-Oszillation
Fehlende Vorbedingung (nie gespeicherte Browser-Session) wird jede Runde identisch reproduziert.
- **Empfehlung:** Reparatur erkennt eine **fehlende Vorbedingung** (chicken-and-egg) als nicht-reparierbar in-place und hält mit `run.unrepairable` + Diagnose an, statt bis `budget_exhausted` zu oszillieren. Fix C reduziert dies bereits, adressiert aber nicht die Vorbedingungs-Kette selbst.

---

## 5. Trade-off-Analyse

- **A–C zuerst**, weil sie die *Sichtbarkeit* und *Terminierung* herstellen: Ohne Fix C laufen Optimierungen 77× im Kreis; ohne Fix A ist das UI unbenutzbar; ohne Fix B startet ein Entwurf gar nicht. Sie sind niedrigriskant (Read-Side-Session-Logik, ein Guard, ein additiver Zähler + ein Reparatur-Abzweig nach bestehendem Muster).
- **D–J danach**, weil sie Run-Semantik bzw. Planungsqualität ändern und ohne die A–C-Basis nicht sinnvoll testbar sind. D1 ist der Hebel mit dem größten „Warum-kein-Erfolg"-Effekt bei moderatem Risiko.
- **Kein Widerspruch zur README-Semantik:** IF-/Branch-Routen bleiben gültige fachliche Verzweigungen; nur `on_error`-**Zyklen ohne Ausstieg** und **Self-Loops** werden als Defekt behandelt.

---

## 6. Konsequenzen

**Leichter:** Der autonome Modus lässt sich wieder starten/entsperren; Entwurfs-Workflows sind optimierbar; endlose Zyklen enden nach `max_same_state_repeats` mit `checkpoint.no_progress` + Reparatur statt stiller 77-fach-Wiederholung; jede Sitzung startet frisch entsperrt.

**Weiter offen (Klasse 2/3):** Solange D–J nicht umgesetzt sind, kann ein Lauf technisch „laufen", aber fachlich nichts erreichen (leere Seite, leere Ausgabe, nie bestehende Verifikation). Die Fixes A–C machen diese Defekte jetzt aber **sichtbar** (No-Progress/Reparatur-Events) statt sie zu verschleiern.

**Zu beobachten:** Ein produktiver Workflow-11-Lauf nach Deployment muss zeigen: (1) Modus start-/entsperrbar; (2) inaktiver Workflow startet; (3) der Zyklus endet mit `checkpoint.no_progress` und einer Reparatur der fehlenden URL, statt 77 Runden.

---

## 7. Action Items

- [x] Fix A — Modus-Sperre lösen (`WorkflowStudioSessionService`, `WorkflowStudioControlService`, `WorkflowStudioTaskEditor`, `WorkflowStudio` + Blade).
- [x] Fix B — Guard für Optimierungs-/Testläufe (`WorkflowExecutionService`).
- [x] Fix C — Revisit-Zähler + No-Progress-Sperre (`WorkflowCopilotSupervisorService`).
- [x] Fix D1 — maskierte Task-Fehler sichtbar machen (`checkpoint.task_failure_masked`; Routen-Semantik unveraendert).
- [x] Fix E — `browser.open_url` deklariert die URL jetzt als Pflichtfeld (`url_required`), der Validator lehnt URL-lose Navigations-Tasks ab.
- [x] Fix F — neue Diagnose `unbounded_backward_retry_route` fuer Fehlerrouten mit unbegrenztem Rueckwaertssprung.
- [x] Fix G — `unsafe_self_route` erkennt zusaetzlich den Selbstbezug auf Listenebene.
- [x] Fix H — Freitext-Muster fuer `title`, `page_state`, `technical_status`, `business_status` ergaenzt; `unsupported`-Meldung mit Formulierungshilfe.
- [ ] **Produktivlauf Workflow 11** nach Deploy: Modus start-/entsperrbar, inaktiver Start, `checkpoint.no_progress` statt Endlosschleife, URL-Pflicht greift.
- [ ] Fix I — fehlende Vorbedingung als nicht-reparierbar erkennen (`run.unrepairable`), gegen die Reparatur-Oszillation aus Session 13.
- [ ] Option D2 — Step-Erfolg und Task-Erfolg im Runner trennen (groesserer Eingriff: PHP + Node + ClientController-Sync, Regel 7).
- [ ] Muster J — verbleibende Loop-/Ausgabe-Verdrahtung: die bestehenden Checks `workflow_return_source_missing`, `loop_body_empty` und `collection_producer_missing` decken einen Teil ab; die vollstaendige Kette (deklarierte Ausgabe erzwingt verdrahteten Loop-Body) fehlt noch.

*(Erzeugt aus verifiziertem Multi-Agent-Forensik-Audit von 10 Exporten; jeder umgesetzte Fix trägt geprüfte Datei-/Zeilen-Evidenz.)*
