# Session-Wiederverwendung und Workflow-Optimierung: Audit und Umsetzungsplan

Stand: 25.09.2026. AiUserFactory `8879a06`, ClientController `9f848ec`.
Status: Analyse abgeschlossen; Implementierung laeuft. AP01/AP03-Persistenzkorrekturen sind teilweise umgesetzt und werden mit Sollverhaltenstests abgesichert; die restlichen Pakete bleiben offen.

## 1. Ergebnis für die Produktentscheidung

**Teilweise ja, aber noch nicht zuverlässig und nicht durchgängig getrennt nach Person, Account und Domain.** FollowFlow besitzt persistente Chromium-Profile und verschlüsselt gespeicherte Session-Snapshots. Ein isolierter echter Chromium-Test bestätigt die Wiederherstellung von Cookie einschließlich HttpOnly, localStorage und sessionStorage für einen einzelnen Origin. Das ist eine funktionierende Basis, aber keine Garantie für vollständige, korrekt zugeordnete oder weiterhin gültige Anmeldungen.

Die wichtigsten Lücken:

1. Profile werden bevorzugt nach Mailadresse statt nach Person und Account benannt. Unterschiedliche Personen mit gleicher Mailbox können denselben Profilordner benutzen. Snapshots werden dagegen standardmäßig nach Workflow und Person benannt; ein anderer Workflow findet sie nicht automatisch unter seinem eigenen Standardschlüssel.
2. Erfasst werden Cookies und Storage der ausgewählten Seite/Frames, nicht vollständig aller zugehörigen Tabs oder Authentifizierungsdomains. Ein Multi-Origin-Restore kann mehr wiederhergestellte Origins melden, als tatsächlich initialisiert wurden.
3. „Session geladen“ wird nicht verlässlich von „angemeldet“ und „richtiger Account“ getrennt. Eine Login-Seite kann als erfolgreiche Webmail-Session gelten.
4. Zwei bereits geladene Personenmodelle können beim Speichern unterschiedliche Sessions gegenseitig überschreiben. Webmail-Persistenz aktualisiert außerdem einen Metadatenspiegel, aber nicht die vorhandene primäre Mailbox-Zeile.
5. Session-Löschung kann die falsche Domain betreffen; explizite boolesche `false`-Optionen werden derzeit ignoriert.
6. Im vollständigen Client-Bundle können Sessiondaten trotz Top-Level-Verschlüsselung als verschachtelte Klartextkopien in Ergebnissen/Events landen. Das ist ein statisch nachvollziehbarer Datenpfad, kein Nachweis eines produktiven Datenabflusses.

**Empfehlung:** Erst Geheimnisschutz, Identität, Löschgrenzen, Persistenz und sichere Wiederholungen absichern. Danach Copilot-Recovery und Workflow-Validierung verbessern. Erst auf dieser Basis Parallelität, UI-Polling und Prozesskosten optimieren. Keine neue Registrierungsautomatisierung und keine pauschale Wiederholung externer Aktionen als Bestandteil dieses Auftrags.

## 2. Umfang, Evidenz und Grenzen

Geprüft: README-Verträge, PHP-Orchestrierung und Modelle, Node-Capture/Restore/Tasks, Rust-Clienttransport, Profilwahl, Cleanup, Queue-Recovery, Revisionen, Validator und Vorschau-Polling. Drei parallele read-only Teilanalysen wurden zusammengeführt und wichtige Aussagen durch isolierte Tests ergänzt.

Kennzeichnung im Bericht:

- **R:** in synthetischer Ausführung reproduziert; keine echten Benutzer-/Providerdaten.
- **S:** statisch im aktuellen Quelltext nachvollzogen; Produktionshäufigkeit nicht gemessen.
- **H:** Optimierungshypothese; erst messen, dann umbauen.

Nicht geprüft: produktive Datenbank, installierte Client-Binaries, echte Anbieteranmeldungen, deren Sessionablauf, frühere hochgeladene ZIP-Logs oder ein vollständiger realer Copilot-End-to-End-Lauf. Die aktuellen Befunde erklären mögliche Fehlerbilder, beweisen aber nicht die Ursache eines bestimmten historischen Laufs. Keine Runtime-Datei wurde für dieses Audit geändert. Dokumentation und zwei isolierte Diagnose-Probes sind die einzigen neuen Artefakte.

Die installierte Testumgebung meldete Node `24.18.0`, Puppeteer `24.15.0`, Chrome `138.0.7204.168`, PHP `8.5.5`. `package.json` verlangt dagegen Puppeteer `^25.8.0`: Die Browserbelege gelten für die tatsächlich installierte Version; eine saubere, lockfilebasierte CI-Abnahme ist zusätzlich nötig. Bestehende PHP-Tests meldeten eine bekannte PDO-Konstanten-Deprecation aus `config/database.php:62`.

## 3. Was heute wo gespeichert wird

| Ebene | Heutige Zuordnung | Was erhalten bleibt | Einschränkung |
| --- | --- | --- | --- |
| Nativer Chromium-Profilordner | Bei vorhandener Mailbox `mailbox-<Hash(email/username)>`, sonst `person-<id>`, sonst `workflow-<id>`; bei Opt-out `run-<uuid>` | Browserinterner Zustand auf diesem Host | Nicht personensicher bei geteilter Mailadresse; nicht zwischen Hosts übertragen; Lock-Fallback erzeugt frisches Profil |
| Generische Browser-Snapshots | `persons.metadata.browser_sessions[sessionKey]`; ohne Person Verifikationsmailbox-Einstellungen | Verschlüsselte JSON-Payload plus Domain-/Zeit-/Hash-Metadaten | Automatischer Schlüssel `workflow-<id>-person-<id>`; ein Schlüssel kann verschiedene Domainzustände überschreiben |
| Webmail-Session aus Workflow | `persons.metadata.email_account.webmail_session` oder gemeinsame Verifikationsmailbox | Verschlüsselte Payload | Bestehende `person_email_accounts.webmail_session` wird dabei nicht mitgeschrieben |
| Kanonische Personen-Mailboxen | `person_email_accounts` | Accountdaten und eigene Webmail-Session | Registry bevorzugt diese Tabelle; UI synchronisiert Richtung Metadatenspiegel, Workflow-Schreiben dagegen nicht zurück |
| Ältere Scraper-Cookies | Unter anderem `Person.cookie_payload`, `cookie_file_path`, `browser_profile_path` | Separater Cookie-/Profilpfad | Nicht automatisch dieselbe Sessionquelle wie der Workflow-Runner |
| ClientController-Transport | Snapshotdatei → Rust-Ergebnis → API → serverseitig verschlüsselte Payload | Portabler Snapshot wird tatsächlich übertragen | Vollständiger Profilordner bleibt lokal; verschachtelte Raw-Kopien und fehlende Transferbestätigung problematisch |

Quellen: [Profilwahl](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowTaskRunner.php:1331), [Sessionkonfiguration](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowBrowserSessionService.php:46), [Browserpersistenz](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/Tasks/PersistBrowserSessionTask.php:10), [Webmailpersistenz](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/Tasks/PersistWebmailSessionTask.php:10), [Accountauswahl](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Persons/PersonAccountRegistry.php:365), [Accountspiegel](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Livewire/Admin/Config/PersonEmailAccountSettings.php:534).

Wichtig für das Sollmodell: Cookie-Domain und Storage-Origin sind verschiedene Grenzen. Ein Origin umfasst Schema, Host und Port. `sessionStorage` ist zusätzlich an den Tab gebunden; zwei Tabs desselben Origins dürfen deshalb unterschiedliche Werte haben. Separate Profilordner je Domain sind nicht pauschal richtig, weil ein Login mehrere autorisierte SSO-Origins benötigt. Quellen: [MDN: Cookies](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Cookies), [MDN: sessionStorage](https://developer.mozilla.org/en-US/docs/Web/API/Window/sessionStorage).

## 4. Befundkatalog

### F01 — Uneinheitliche Identität und nicht garantierte Personenisolation [S, P1]

Profilidentität, Snapshotidentität und Mailboxidentität verwenden unterschiedliche Schlüssel. Der vollständige Client-Bundle-Compiler hydriert außerdem nur den rohen Run-Kontext plus Browser-Sessions; der Server-/Fallbackpfad lädt zusätzlich Person und Account. Bei einem üblichen Start nur mit `person_id` können somit `person-N` auf dem Client und `mailbox-HASH` auf dem System entstehen.

Ein weiterer bedingter Fehler: `personForRun($run, $step)` bevorzugt `step.config_json.person_id`, während andere Stellen die Run-Person zum Speichern und zum Ermitteln des automatischen Sessionkeys benutzen. Ein widersprüchlicher Legacy-/Custom-Step kann also Accountdaten von A laden und das Ergebnis B zuordnen. Das ist kein Beleg, dass die heutige UI solche Steps regulär erzeugt.

Quellen: [Profilkey](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowTaskRunner.php:1336), [Bundle-Compiler](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/ClientWorkflowBundleCompiler.php:22), [Personauflösung/Runtime](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowExecutionService.php:4421), [Persistenzziel](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowExecutionService.php:4209).

### F02 — Falsche Domainauswahl und destruktive Löschgrenzen [R/S, P1]

Bei einer expliziten, unbekannten Session-ID liefert `open_browser_session` korrekt keinen Treffer. **Ohne Key**, bei erfolgloser Domainsuche, fällt die Auswahl aber auf die zuletzt gespeicherte fremde Session zurück. Mit passendem Key wird ein zusätzlich angegebener Domainkonflikt nicht abgewehrt. Die automatische Standardladung setzt normalerweise einen Key; der unsichere keylose Fallback betrifft insbesondere direkte/Legacy-Aufrufe, nicht jeden automatischen Run.

Reproduziert im echten Browser: aktive Domain A, Löschziel B, `clear_storage:false`, `clear_cookies:false` → beide Ergebnisflags werden `true`, localStorage und sessionStorage von A sind gelöscht. Ursache sind `||` statt nullish Boolean-Auswahl und unbedingtes Löschen auf der aktuellen Seite. PHP-seitige Löschung kombiniert Key- oder Domainmatch und ist ebenfalls auf eindeutige Grenzen zu prüfen.

Quellen: [Sessionauswahl](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/browser/open_browser_session.cjs:95), [Storage löschen](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/data/delete_browser_session.cjs:184), [Boolean-Optionen](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/data/delete_browser_session.cjs:231), [DB-Löschmatch](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/Tasks/PersistBrowserSessionTask.php:140).

### F03 — Unvollständiger Capture und zu optimistischer Restore [R/S, P1]

`captureBrowserSession` sammelt Storage nur aus `page.frames()`. Die relevanten Cookie-Domains werden aus dieser Seite und ihren Frames abgeleitet. Andere eigene Tabs und frühere/geschlossene SSO-Origins fehlen. Autosave wählt ein einzelnes konfiguriertes beziehungsweise Hauptfenster.

Reproduziert: Zwei geöffnete Testtabs, beide mit Cookie/Storage → Hauptseiten-Capture enthält nur Origin 1. Kombinierter Snapshot mit zwei Origins → Restore meldet zwei Origins, aber nach späterem Aufruf von Origin 2 fehlen dessen localStorage und sessionStorage. Der Preload-Hook wird nach der ersten Navigation entfernt; gezählt werden vorbereitete statt nachgewiesen initialisierte Origins.

Quellen: [Capture](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/lib/webmail_session_capture.cjs:124), [Restore](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/lib/browser_session_restore.cjs:207), [Autosave-Fenster](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/run_step.cjs:687).

### F04 — Geladen ist nicht authentifiziert; falsche Accountbestätigung [R/S, P1]

Ein synthetischer Login mit Text „E-Mail-Adresse Passwort Anmelden“ ohne gespeicherte Session wird von `webmail.check_session` als Erfolg bewertet. Allgemeine Texte wie „e-mail“ reichen als positives Signal. Generische Open-Aktionen prüfen hauptsächlich technische Wiederherstellung, nicht zuverlässig angemeldete Identität. `check_session` verwendet zudem einen separaten Restore, der Storage nach einer Weiterleitung ohne ausreichende Originprüfung einsetzen kann.

Quellen: [Heuristik](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/webmail/check_session.cjs:63), [zweiter Restore](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/webmail/check_session.cjs:28), [Open-Erfolg](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/browser/open_browser_session.cjs:196).

### F05 — Browserdaten sind nicht vollständig portabel [S, P2]

JSON-Capture umfasst Cookies, localStorage und sessionStorage, aber keinen vollständigen IndexedDB-/Service-Worker-/Cache-Zustand. Cookie-Capture entfernt unter anderem `partitionKey`; Restore übernimmt Partitionierungsinformationen ebenfalls nicht. Dadurch ist ein verlustfreier CHIPS-Roundtrip nicht gegeben. Die native Profilpersistenz kann auf demselben Host mehr Daten bewahren, ersetzt aber keinen portablen Snapshot. Restore mischt gespeicherte Schlüssel in bestehenden Zustand; alte nicht überschriebenen Werte bleiben erhalten.

Quellen: [Cookiefilter](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/lib/webmail_session_capture.cjs:154), [Restore-Cookiefelder](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/lib/browser_session_restore.cjs:86), [Storage-Merge](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/lib/browser_session_restore.cjs:147).

### F06 — Verlorene Updates und widersprüchliche Accountspeicher [R/S, P1]

Zwei separat geladene Instanzen derselben Person: A speichert `session-a`, B danach `session-b`. Beide melden Erfolg, in der DB bleibt nur `session-b`. Ursache: vollständiges Read-Modify-Write von `metadata`, kein frisches gesperrtes Lesen beziehungsweise revisionsgeprüftes Einzelupdate. Dasselbe Muster betrifft gemeinsam gespeicherte Einstellungen.

Zweiter reproduzierter Fall: Webmail-Persistenz aktualisiert `persons.metadata.email_account.webmail_session`, die schon vorhandene primäre `person_email_accounts.webmail_session` bleibt alt. Die Registry bevorzugt die Tabelle; eine spätere UI-Spiegelsynchronisation kann den neuen Metadatenstand wieder verdrängen. `PersistMailAccountTask` schreibt ebenfalls primär den Metadatenspiegel.

Quellen: [Browser-Write](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/Tasks/PersistBrowserSessionTask.php:22), [Webmail-Write](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/Tasks/PersistWebmailSessionTask.php:22), [Account-Write](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/Tasks/PersistMailAccountTask.php:8).

### F07 — Snapshots werden zu früh, zu spät oder nur teilweise übernommen [S, P1]

Ein früher erfolgreicher Persist-Task verhindert späteres Autosave, auch wenn sich der Loginzustand verändert hat. Fehlerpfade erstellen ebenfalls Captures; ein fehlgeschlagener Capture wird als erfolgreicher „skipped“-Task zurückgegeben. Autosave läuft am Ende eines Runtime-Segments, nicht zwingend erst am Ende des gesamten Workflows. Die einmalige Lade-Markierung gilt runweit statt je Browserkontextgeneration.

Node und Rust aggregieren pro Typ nur den letzten Snapshot beziehungsweise Löschauftrag. PHP übernimmt Browser-Persistenz aus Top-Level-Feldern, nicht vollständig aus allen Taskresultaten. Mehrere Sessionupdates in einem Segment/Bundle können so verloren gehen. Lokale Finalisierung verschlüsselt und löscht die Quelldatei vor der späteren DB-Persistenz; nach einem DB-Fehler fehlt sie für den Retry. Captures eines fehlgeschlagenen Segments werden außerdem nicht auf allen PHP-Ergebnispfaden übernommen.

Quellen: [letztes Resultat](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/run_step.cjs:611), [Autosave](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/run_step.cjs:662), [Ergebnisübernahme](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowExecutionService.php:3922), [Dateifinalisierung](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowExecutionService.php:4288).

### F08 — Clienttransport funktioniert, hinterlässt aber Raw-Kopien [S, P0]

Die Übertragung ist vorhanden: Rust liest die Snapshotdatei, setzt `remoteBrowserSessionPayload`/`remoteWebmailSessionPayload`, die API verschlüsselt diese Top-Level-Felder, PHP kann die verschlüsselte Payload ohne Zugriff auf den entfernten Dateipfad persistieren. Es wäre falsch, hier fehlenden Cross-Machine-Transfer zu behaupten.

Das Problem liegt in Kopien: Dieselben Raw-Felder bleiben in `workflow` und `steps` beziehungsweise Fortschrittsobjekten erhalten. Die API bereinigt nur Top-Level und persistiert verschachtelte Ergebnisse/Events über normale Array-Casts. Lokale Runtime-/Bundle-/Checkpointdateien und die Outbox können ebenfalls geheimnishaltige Kontexte enthalten. Kein ausreichender gezielter Client-Cleanup wurde gefunden. Fehlende Snapshotdateien werden beim Enrichment still übersprungen; explizites Transfer-ACK mit Schema/Hash fehlt.

Quellen: [Rust-Enrichment](/Users/lucaszacharias/Desktop/pojekte/follow-flow/ClientController/src-tauri/src/lib.rs:2821), [Kontextkopie](/Users/lucaszacharias/Desktop/pojekte/follow-flow/ClientController/src-tauri/src/lib.rs:3334), [Progress](/Users/lucaszacharias/Desktop/pojekte/follow-flow/ClientController/src-tauri/src/lib.rs:3614), [Bundle-Ergebnis](/Users/lucaszacharias/Desktop/pojekte/follow-flow/ClientController/src-tauri/src/lib.rs:4064), [API-Verschlüsselung/Persistenz](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Http/Controllers/Api/ClientControllerApiController.php:389), [Progress-Persistenz](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Http/Controllers/Api/ClientControllerApiController.php:617).

### F09 — Profilkonflikte, Start-Races und Retention [S, P1]

Der Personen-Scheduler blockiert teilweise parallele browsergebundene Personenruns; das ist kein zentraler Profil-Lease für alle manuellen, Studio-, Copilot- und Clientstarts. Ein Lock-Konflikt im Browserlauncher führt zu einem neuen `-retry-...`-Profilordner und damit potenziell zu einem leeren Loginzustand.

Nicht-Copilot-`advance()` prüft aktiven Step und startet den Prozess ohne durchgehenden atomaren Claim. Der vorhandene Unique-Constraint verhindert nicht jede doppelte Prozessausführung eines bereits gefundenen StepRuns. Profil-Cleanup verwendet standardmäßig sieben Tage und rekursive Datei-mtimes, aber keinen fachlichen Lease/Retentionvertrag. Ein aktives oder bewusst lange aufbewahrtes Profil darf nicht allein nach diesem Kriterium entfernt werden. Der getrennte Cookie-Pruner ist keine Ablaufverwaltung für generische Workflow-Snapshots.

Quellen: [Scheduler-Prüfung](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Automation/PersonWorkflowDispatcher.php:228), [frisches Ersatzprofil](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/resources/node/register/lib/browser-launcher.cjs:54), [Advance](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowExecutionService.php:358), [Pruner](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Console/Commands/PruneWorkflowProcessArtifacts.php:20).

### F10 — Restart/Retry/Endprüfung ohne vollständige Wirkungserfassung [S, P0 für Autonomie]

Supervisor-Guards lesen `side_effect_ledger_json` und `result.sideEffects`. In `node/workflows` wurden keine Produzenten für `sideEffects`/`side_effects` gefunden; ein generischer Klick meldet keine externe Wirkung. Ein leerer Ledger beweist damit keine sichere Wiederholbarkeit. Endverifikation startet grundsätzlich einen neuen Lauf von vorne. Ohne Idempotenz-/Abgleichvertrag können beispielsweise Submit, Kontoanlage oder Versand wiederholt werden. Es ist nicht belegt, dass in einem konkreten Produktionslauf eine Doppelwirkung eingetreten ist.

Quellen: [Restart-Guard](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowCopilotSupervisorService.php:1449), [Probe-Guard](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowCopilotSupervisorService.php:1561), [Verifikationsstart](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowCopilotSupervisorService.php:2273), [Klick](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/tasks/browser/click.cjs:25).

### F11 — Unvollständige Statusdatei kann terminal erscheinen [S, P1]

Node und PHP schreiben JSON direkt in die Zieldatei. Ein gleichzeitiger Leser kann unvollständiges JSON sehen. `readRun()` macht daraus einen leeren Zustand und `isRunning=false`; Monitoring wechselt anschließend in die terminale Ergebnisauswertung. Das ist ein nachvollziehbarer Race-Pfad, keine gemessene Ausfallhäufigkeit.

Quellen: [Node-Writer](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/node/workflows/run_step.cjs:96), [PHP-Reader](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowTaskRunner.php:269), [PHP-Writer](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowTaskRunner.php:1632), [Monitor](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowExecutionService.php:1089).

### F12 — Technischer Queuefehler wird zur dauerhaften manuellen Pause [S, P1]

Recovery setzt nach Supervisor-Jobfehler `paused`, `queue_failed`, `requires_manual_resume=true`. Der Reconciler berücksichtigt diese pausierten Sitzungen nicht mehr automatisch. Damit ist „pausiert und danach passiert nichts“ für diesen Pfad erwartbares Verhalten, auch wenn die ursprüngliche Ursache vorübergehend war. Die Beispielkonfiguration nutzt einen Queueworker; langsame AI- und kurze Kontrolljobs konkurrieren um dieselbe Defaultqueue. Bestehende Supervisor-Leases und Recoverymechanismen sind zu erweitern, nicht zu ersetzen.

Quelle: [Queue-Recovery](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowCopilotQueueRecoveryService.php:28).

### F13 — AI-Erstplanung hält Datenbank-Locks [S, P1]

`planning->planAndApply()` wird in `revisions->apply()` ausgeführt. Letzteres hält Workflow-/Session-`lockForUpdate()` über den Mutation-Callback; der Planer kann dabei bis zu 90 Sekunden auf AI warten. Kontrollbefehle oder konkurrierende Revisionen können unnötig blockieren.

Quellen: [Aufrufer](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowCopilotSupervisorService.php:2192), [Revisionstransaktion](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowRevisionService.php:78), [AI-Planung](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowCopilotPlanningService.php:57).

### F14 — Variablenprüfung kennt Produzenten, aber nicht alle Ausführungspfade [S, P2]

Der Validator sammelt Variablenproduzenten über alle Steps und prüft anschließend global auf Existenz. Ein Produzent hinter dem Verbraucher oder ausschließlich im anderen IF-Zweig kann dadurch als ausreichend gelten. Vorhandene Routing-/Loop-/Konfigurationsprüfungen bleiben wertvoll; nötig ist zusätzliche Pfad- und Typanalyse.

Quelle: [Validator](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowDefinitionValidator.php:37).

### F15 — UI-Polling und Runtime-Aufbau bieten Optimierungspotenzial [S/H, P2/P3]

Vorschau, Manager, Chat und Archiv pollen alle zwei bis fünf Sekunden. Der Manager stößt dabei auch Runtime-Refresh an; die Vorschau lädt/berechnet viele Daten wiederholt. Runbezogene Push-Projektionen mit Cursor fehlen. Lokaler und Remote-Runtimeaufbau sind teilweise dupliziert; segmentierte Tests erzeugen wiederholt Node-Prozesse, auch wenn Browser weiterverwendet werden. Umfang der tatsächlichen Kostenersparnis ist noch zu messen.

Quellen: [Manager-Refresh](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Livewire/Admin/Network/WorkflowManager.php:2420), [Preview-Projektion](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Livewire/Admin/Network/WorkflowRunPreview.php:166), [Runtimeaufbau](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/app/Services/Workflows/WorkflowTaskRunner.php:42).

## 5. Verbindlicher Zielvertrag

Die folgenden Bezeichnungen sind vorgeschlagene Datenverträge, **keine neuen ausführbaren Task-Keys**. Bestehende Tasks bleiben im `WorkflowTaskCatalog` registriert.

### 5.1 Eine Identität für Run, Profil, Snapshot und Mailbox

```text
SessionIdentity = owner_scope + owner_id + account_ref + context_scope + slot
owner_scope     = person | verification_mailbox
account_ref     = stabile Account-ID, nicht veränderliche Mailadresse
context_scope   = benannte Auth-Gruppe mit explizit erlaubten Origins
slot            = default oder explizit benannter getrennter Zustand
native_profile  = host_id + SessionIdentity (lokale Ressource)
snapshot        = SessionIdentity + revision (hostübertragbar)
```

Eine Persona mit zwei Accounts derselben Domain erhält zwei getrennte Kontexte. Zwei Personas bleiben auch bei identischer Mailadresse getrennt, außer es wird ausdrücklich ein gemeinsamer Verifikationsaccount gewählt. Nicht vorhandene oder widersprüchliche Personen-/Account-IDs führen zu einem erklärten Fehler, niemals still zum globalen Verifikationsaccount. Laufidentität ist unveränderlich; ein Identitätswechsel verlangt einen neuen, isolierten Browserkontext.

`workflow_id` ist Herkunft/Verwendungszweck, nicht standardmäßig Eigentümer der Anmeldung. Wiederverwendung über Workflows derselben expliziten Identität ist möglich; ein Workflow kann einen separaten Slot anfordern. Domain-/Originberechtigungen werden vor Laden, Capture, Weiterleitung und Löschen geprüft. Bloße Domainverwandtschaft ist keine Accountberechtigung.

### 5.2 Versionierter Snapshot und Status

Vorgeschlagenes Manifest: `schema_version`, `identity`, `revision`, `source_run_id`, `source_attempt_id`, `captured_at`, `validated_at`, `capabilities`, `allowed_origins`, `cookie_records`, `origins[origin].localStorage`, `windows[window_id].origins[origin].sessionStorage`, `payload_hash`, `auth_state`, `verified_account_ref`. Cookie-Schlüssel enthalten mindestens Name/Domain/Path und bei unterstützter Runtime Partitionierungsidentität. Sensible Inhalte liegen ausschließlich in verschlüsselten Payloads, nicht in öffentlichen Metadaten.

Zustände getrennt führen: `captured`, `persisted`, `restore_prepared`, `restore_applied`, `authenticated`, `account_verified`, `expired`, `invalid`, `challenge_required`, `transfer_failed`, `revoked`. Erfolg des Speicherns ist kein Beweis einer gültigen Anmeldung. `candidate` und `last_known_good` bleiben getrennt; fehlgeschlagene Loginversuche ersetzen nicht automatisch die letzte bestätigte Session. Bewusster Logout/Delete widerruft dagegen die Wiederverwendung auch von `last_known_good`. Monotone Revision und Tombstone verhindern, dass verspätete alte Saves/ACKs gelöschte Anmeldungen wiederbeleben.

Capabilities explizit nennen, z. B. cookies/localStorage/tab-sessionStorage unterstützt, IndexedDB nicht portabel. Keine pauschale Garantie „kompletter Browserzustand“. Native Profile nur kontrolliert unter Lease verwenden, nie live zwischen Rechnern kopieren. Unterstützte Puppeteer-/CDP-Felder durch versionsgetesteten Adapter normalisieren; [offizielle BrowserContext-Cookie-API](https://pptr.dev/api/puppeteer.browsercontext.cookies) als Referenz, nicht als ungeprüfter Drop-in-Ersatz.

### 5.3 Persistenz und Ausführung

Ein normalisierter Sessionstore mit Unique-Key über die Identität und optimistischer Revision ersetzt langfristig das gesamte Metadaten-Read-Modify-Write. Server ist Autorität für Eigentümerzuordnung; ein Client darf sie nicht aus Ergebnisfeldern umdefinieren. Geordnete `session_updates[]` enthalten eindeutige Update-ID, Identität, Revisionserwartung, Operation und verschlüsselte Artifactreferenz. Jeder Eintrag wird bestätigt oder mit eindeutigem Fehler abgelehnt.

Profil-Lease, Task-Start-Claim, Effect-Ledger und Resultat-Idempotenz sind unterschiedliche Dinge und werden nicht zu einem einzigen unklaren Lock zusammengefasst. Jede Wiederaufnahme verwendet denselben fachlichen Taskversuch oder erklärt explizit den neuen Versuch. UI-Schließen darf Lauf und Recovery nicht beenden; UI-Polling darf keine zusätzliche fachliche Aktion auslösen.

## 6. Abarbeitbare Implementierungspakete

Reihenfolge und Abhängigkeiten stehen in Abschnitt 7. Für jedes Paket zuerst den Fehler als **Sollverhaltenstest** pinnen, dann minimal implementieren, Regressionen prüfen, README-Protokoll aktualisieren. Die beiden Audit-Probes erwarten teils absichtlich den Fehler und ersetzen keine solchen Solltests.

### AP01 — Geheimnishaltige Ergebnisse und Artefakte absichern

**Priorität:** P0. **Befund:** F08. **Abhängigkeit:** keine; vor umfassenden neuen Session-E2E-Logs umsetzen.

**Dateien:** ClientController `src-tauri/src/lib.rs` (Enrichment, Merge, Progress, Outbox); AiUserFactory `ClientControllerApiController`, `NetworkJob`, `NetworkJobProgressEvent`, vorhandene Debug-/Copilot-Redaktion; neue gezielte Tests.

- [ ] Realistisches verschachteltes Bundle mit synthetischen Cookie-/Token-Sentinels erstellen: Top-Level, `workflow`, `steps`, `tasks`, `browserCleanup`, Progress und Retry-Outbox.
- [ ] Geheimnishaltigen Transport von Diagnoseobjekten trennen. Snapshot nur einmal in einem geschützten Artifactkanal über authentifizierten Transport senden; im Ergebnis nur ID/Hash/zulässige Metadaten behalten. Bestehendes Top-Level-Protokoll übergangsweise sicher adaptieren.
- [ ] Vor jeder Persistenz/Projektion rekursiv redigieren; nicht nur vor ZIP-Export. Resultat-, Progress- und Fehlermeldungspfade einschließlich Nested-JSON-Strings berücksichtigen. Auch URLs auf sensible Querywerte prüfen.
- [ ] Lokale Runtimeartefakte mit minimalen Dateirechten und definierter Lebensdauer behandeln. Verschlüsselungsstrategie für Outbox/Checkpoint mit verfügbarer Schlüsselverwaltung festlegen; Schlüssel nicht daneben speichern. Tempdateien nur solange erforderlich entschlüsselt halten.
- [ ] Vorhandene historische Raw-Daten zunächst über einen redigierten Inventar-/Dry-run-Bericht identifizieren. Keine stillen Änderungen des append-only Auditlogs. Bereinigung, Re-Encryption oder Tokenrotation nur als separat freigegebene Bestandsmaßnahme planen.

**Abnahme:** Sentinel fehlt in DB-Diagnosefeldern, Eventpayloads, UI, Export und Logs; korrekt autorisierte Snapshotübernahme funktioniert weiterhin. Outbox-Retry und doppelte Zustellung verlieren den Snapshot nicht. Fehlgeschlagene Redaktion führt nicht zum Raw-Fallback. Synthetische Daten genügen; keine echten Secrets auslesen.

### AP02 — Einheitliche Personen-, Account- und Browseridentität

**Priorität:** P1. **Befund:** F01. **Abhängigkeit:** AP01 für neue Diagnosefelder.

**Dateien:** `WorkflowBrowserSessionService`, `WorkflowExecutionService::workflowRuntimeContext/personForRun`, `WorkflowTaskRunner::browserProfileKey`, `ClientWorkflowBundleCompiler`, `PersonAccountRegistry`; neuer gemeinsamer Identity-/RuntimeContext-Builder.

- [ ] Zielvertrag aus 5.1 typisiert/versioniert einführen. Person-/Accountauflösung einmal beim Runstart; Identität danach unveränderlich weiterreichen.
- [ ] Server, Client-Fallback und vollständiges Bundle denselben fachlichen Kontext bauen lassen. Nur Pfade, unterstützte Capabilities und Transportadapter dürfen abweichen.
- [ ] Profilkey aus stabilen IDs statt nur E-Mail erzeugen; gemeinsamen Verifikationsscope ausdrücklich modellieren. Ungültige Personen-ID nicht still als globalen Scope behandeln.
- [ ] Widerspruch zwischen Step- und Run-Person vor Browserstart ablehnen. Falls Step-Identitätswechsel später fachlich nötig sind, als eigenen isolierten Kontext implementieren, nicht implizit überschreiben.
- [ ] Cross-Workflow-Sessionreuse für denselben Account/Scope ermöglichen; Legacy-Workflowkeys nur über explizite Migration/Zuordnung übernehmen. Alte Mailbox-Hash-Profile bei mehrdeutiger Besitzzuordnung quarantänisieren statt beiden Personen zuzuweisen.

**Abnahme:** Gleiche E-Mail bei A/B ergibt getrennte Profile; derselbe Account mit geänderter Mailadresse bleibt stabil; zwei Accounts derselben Person/Domain sind getrennt; drei Ausführungsziele liefern dieselbe fachliche Identität; widersprüchlicher Step startet nicht. Gemeinsame Verifikation funktioniert nur bei explizitem Scope.

### AP03 — Exakte Auswahl und nicht destruktive Löschung

**Priorität:** P1, gezielter früher Bugfix. **Befund:** F02. **Abhängigkeit:** Hotfix ohne AP02 möglich, Identitätschecks anschließend mit AP02 konsolidieren.

**Dateien:** `open_browser_session.cjs`, `delete_browser_session.cjs`, `PersistBrowserSessionTask`, zugehörige Node-/PHP-Tests.

- [ ] Kein passender Key/Account/Domain → `session_not_found`; kein Fallback auf fachlich unzulässigen Kandidaten. Expliziter Key mit widersprüchlichem Domain-/Accountconstraint → erklärter Konflikt.
- [ ] Innerhalb zulässiger Kandidaten deterministisch nach Revision/Bestätigung auswählen, nicht nach zufälliger Array-Reihenfolge.
- [ ] Boolean-Aliase nullish auswerten; `false`, `'false'` und fehlend getrennt testen.
- [ ] Löschauftrag in explizite erlaubte Cookie-Scopes und Origins auflösen. Aktuelle Seite/Frames nur dann leeren, wenn sie Ziel und Identität entsprechen. Domain-Eltern-/Subdomainmatch nicht als pauschale Löschfreigabe behandeln.
- [ ] Persistenter Snapshot, aktiver Browserzustand und natives Profil sind getrennte Löschziele. Ergebnis nennt für jedes Ziel `deleted/not_found/failed`; Teilerfolg niemals als vollständige Abmeldung darstellen.
- [ ] Bewussten Logout/Delete als Widerruf mit Tombstone/Revision führen, einschließlich Sperre des last-good-Fallbacks. Später eintreffende veraltete Save-Ergebnisse nicht als neue Anmeldung akzeptieren.

**Abnahme:** B löschen verändert A nicht; `false` verändert nichts im betreffenden Speicher; exakter Key löscht nicht andere Schlüssel allein wegen Domainähnlichkeit; Teilfehler werden sichtbar; erneute Löschung ist idempotent.

### AP04 — Versionierter Capture/Restore und echte Loginprüfung

**Priorität:** P1, umfassende Datenportabilität P2. **Befunde:** F03–F05. **Abhängigkeit:** AP02, sichere Auswahl aus AP03.

**Dateien:** `webmail_session_capture.cjs`, `browser_session_restore.cjs`, Browser-/Webmail-Open und Check-Tasks, `resources/node/session/webmail_session.cjs`, `WorkflowTaskCatalog`, Runtime-Capabilityvertrag.

- [ ] Snapshot-v2 gemäß 5.2 implementieren; v1 lesen, fehlende Capabilities ausdrücklich markieren. v1 nicht still als vollständig migriert deklarieren.
- [ ] Nur zum aktuellen Accountkontext gehörende Tabs/Frames erfassen. localStorage je Origin, sessionStorage je logischem Fenster und Origin speichern. Auth-Origin-Allowlist/SSO-Manifeste berücksichtigen; keine beliebigen offenen Tabs einsammeln.
- [ ] Restore zentralisieren, auch im Webmail-Check und älteren Sessionpfad. Vor Storageinjektion exakten Origin prüfen; Redirect auf fremden Origin erhält keine Tokens.
- [ ] localStorage je Browserkontext + Origin + Restoregeneration, sessionStorage je Tab/Fenster + Origin + Restoregeneration genau einmal initialisieren; `prepared/applied/readback_verified/failed` getrennt zählen. Preload nur bis zur gezielten Anwendung behalten; nicht bei späteren Navigationen veraltete Werte wieder einsetzen.
- [ ] Kontrollierten Replace-vs-Merge-Modus definieren. Alte Accountdaten im erlaubten Scope entfernen oder frischen accountgebundenen Kontext nutzen; niemals fremde Scopes pauschal leeren.
- [ ] Cookieattribute einschließlich Partitionierungsidentität über Runtimeadapter erhalten; abgelaufene/abgewiesene Cookies zählen und durch Readback prüfen. IndexedDB-Portabilität als eigenes Capability-Inkrement entscheiden, nicht implizit versprechen.
- [ ] Auth-Prüfung mit positiven Accountsignalen sowie negativen Login-/Challengeindikatoren. Accountkennung muss zur erwarteten Identität passen. Vision kann unklare UI ergänzen, aber ein generisches Wort wie „E-Mail“ darf keine Authentifizierung beweisen.

**Abnahme:** Zwei Origins, zwei Tabs gleichen Origins mit verschiedenem sessionStorage, SSO-Zwischenseite und verzögertes Iframe funktionieren gemäß Manifest. Login/Logout/abgelaufene Session/falscher Account/Challenge werden getrennt erkannt. Unbekannte Origins bleiben unangetastet. UI zeigt begrenzte Snapshot-Capabilities.

### AP05 — Transaktionaler Sessionstore und eine Accountquelle

**Priorität:** P1. **Befund:** F06. **Abhängigkeit:** AP02; Schema gemeinsam mit AP04 abstimmen.

**Dateien:** `PersistBrowserSessionTask`, `PersistWebmailSessionTask`, `PersistMailAccountTask`, `PersonAccountRegistry`, `PersonEmailAccountSettings`, `PersonEmailAccount`; additive Migrationen und neuer zentraler Sessionstore.

- [ ] Kurzfristig betroffene Metadaten in kurzer Transaktion frisch laden/sperren und nur eigenes Feld ändern. Keine bereits geladene veraltete Modelinstanz als Schreibbasis verwenden; Settingspfad ebenfalls absichern.
- [ ] Langfristig Sessiondatensätze mit eindeutigem Identity-Key, Revision, verschlüsselter Payload, Authzustand, `last_used_at` und validierter Herkunft normalisieren. Unique-Index so gestalten, dass `NULL`-Semantik keine doppelten Scopes erlaubt.
- [ ] Conditional Update auf erwartete Revision; ältere/doppelte Updates dürfen neueren Stand nicht verdrängen. Konflikt als fachliches Resultat, nicht stilles Last-write-wins.
- [ ] Mailboxänderungen durch einen Service auf dem konkreten `PersonEmailAccount` ausführen. Metadatenspiegel nur noch abgeleitet bedienen; kein automatisches Zurückspiegeln alter Sessiondaten. Gemeinsame Verifikation bleibt eigener Store/Owner.
- [ ] Bestehende Einträge verlustarm migrieren, Identitätskonflikte melden, Decrypt-/Schemafehler sichtbar kennzeichnen. Nicht lesbare Payload nicht still verschwinden lassen und nicht als leere gültige Session speichern.

**Abnahme:** Zwei konkurrierende Saves für A/B bleiben beide erhalten; same-key Konflikt liefert eindeutige Revision; paralleles Profilmetadatenupdate bleibt erhalten; Table/Mirror stimmt überein; stale UI-Save überschreibt keine aktuelle Session. Zusätzlich echte Mehrverbindungsabnahme gegen dieselbe DB-Engine wie Produktion, nicht nur SQLite.

### AP06 — Alle Sessionupdates zuverlässig übernehmen und bestätigen

**Priorität:** P1. **Befund:** F07/F08. **Abhängigkeit:** AP01, AP02, AP05.

**Dateien:** `run_step.cjs` (Autosave/Persistenzfelder), `WorkflowExecutionService` (Finalisierung/Resultatanwendung), `WorkflowTaskRunner`, Rust-Enrichment/-Bundleaggregation, API-Ergebnisvertrag.

- [ ] `session_updates[]` statt nur letzter Top-Level-Payload einführen. Save/Delete-Reihenfolge, Window/Origin/Identität, eindeutige Update-ID und erwartete Revision enthalten. v1-Ergebnis in genau ein v2-Update adaptieren.
- [ ] Upload/Ingestion prüft Größe, Schema, Hash, zulässige Identität und Provenienz. Lokale Artefaktpfade innerhalb freigegebener Runverzeichnisse validieren; keine beliebigen clientgelieferten Pfade öffnen oder löschen.
- [ ] Reihenfolge: vollständiges privates Artifact schreiben → ingestieren/validieren → verschlüsselt atomar persistieren → ACK → temporäres Artifact entsorgen. Nach DB-/Netzfehler bleibt Wiederholung möglich; Duplicate-ACK ist harmlos.
- [ ] Autosave über Zustandsänderung/Generation steuern: früher Save verhindert nicht den späteren neuen Stand. Restore-Ledger gemäß AP04 führen; Context-ID/Generation beim CDP-Reconnect prüfen. Verbindung zum weiterhin lebenden Kontext injiziert nicht erneut alte Tokens; nur neue Kontextgeneration oder nachgewiesen fehlender Restore initialisiert erneut.
- [ ] Fehlercaptures als Candidate/Diagnose behandeln; last-known-good nur nach definierter Auth-/Accountprüfung ersetzen. Sichere Checkpoint-/Logoutgrenzen beachten. Nicht jeden Task mit teurem Vollcapture beenden, ohne vorher Persistenzanforderung und Kosten zu klären.
- [ ] Fehlende Dateiquelle, abgebrochener Transfer und fehlgeschlagene Persistenz ausdrücklich an Run/UI melden. Bei sessionkritischem Workflow ist „nicht gespeichert“ kein stiller Gesamterfolg.
- [ ] Delete-/Logout-Tombstones und ihre Revision vor jedem verspäteten Save/ACK berücksichtigen. Nur eine ausdrücklich neue zulässige Anmeldung darf widerrufenen Zustand ersetzen; Fehlercapture oder altes Retry-Ergebnis nicht.

**Abnahme:** Save A → Save B → Delete A in einem Bundle wird vollständig, geordnet und einmalig angewandt. DB-Fehler vor ACK verliert keine Quelle. Client A → Server → Client B reproduziert alle unterstützten Daten; fehlende Datei ergibt `transfer_failed`. Früher Save plus spätere Loginänderung speichert den neueren bestätigten Stand.

### AP07 — Zentraler Profil-Lease und idempotenter Taskstart

**Priorität:** P1; Voraussetzung vor höherer Parallelität. **Befund:** F09. **Abhängigkeit:** AP02.

**Dateien:** `WorkflowExecutionService::start/advanceRun/startWorkflowTaskStep`, `PersonWorkflowDispatcher`, `WorkflowTaskRunner`, Browserlauncher, Rust-Start/Recovery, `PruneWorkflowProcessArtifacts`; neue Claim-/Lease-Persistenz.

- [ ] Alle Startwege über dieselbe Profilreservierung führen. Besitzer, Host, Run, Generation/Fencing-Token, Ablauf und Heartbeat erfassen. Leaseverlust verhindert weitere geschützte Writes.
- [ ] Chromium-Lock nicht durch stillen neuen Identity-Ordner umgehen. `waiting_for_profile` mit Grund, begrenztem Backoff und sichtbarem Besitzer; bewusst frischer Testkontext nur als expliziter Modus.
- [ ] Run-Cursor/Taskversuch in kurzer DB-Transaktion claimen; Prozess erst nach erfolgreichem Claim starten. Externe Start-ID dauerhaft festhalten; Absturz zwischen Claim und Start/ACK über Reconciliation behandeln, nicht blind zweiten Prozess starten.
- [ ] Globale Kapazitätsreservierung sowie Host-/Profilkapazität atomar prüfen. Vorhandene Copilot-Leases weiterverwenden, Zuständigkeiten klar abgrenzen.
- [ ] Cleanup an Lease, `last_used_at`, Lifecycle und explizite Retention koppeln. Kein Löschen aktiver Profile. Crash-/verwaiste Locks kontrolliert erkennen; keine globale Entfernung von Chromium-Lockdateien.

**Abnahme:** Zwei parallele Startquellen erzeugen höchstens einen Prozess pro Claim; zwei Workflows derselben Identität warten statt neues Profil zu verwenden; unterschiedliche Personen können parallel laufen; Workercrash wird wiedergefunden; aktives Profil übersteht Cleanup. Konkurrenztest mit Barriere und mehreren echten DB-Verbindungen erforderlich.

### AP08 — Externe Wirkungen vor Replay, Reparatur und Verifikation absichern

**Priorität:** P0 für autonome Wiederholungen. **Befund:** F10. **Abhängigkeit:** separater Vertrag sofort; Integration mit AP07/AP09, vor AP10-Autoretry.

**Dateien:** `WorkflowTaskCatalog`, Node-Taskresultate/Executor, `WorkflowCopilotSupervisorService` (Probe, Restart, technische Wiederholung, Endverifikation), bestehender Ledger; neue EffectPolicy-/Reconciliation-Komponente.

- [ ] Katalog um Wirkungsklasse `read_only/local_state/external_write/unknown` und Wiederholungsvertrag erweitern. Generischer Klick standardmäßig nicht als risikolos einstufen; fachlicher Kontext entscheidet.
- [ ] Vor potenzieller Wirkung dauerhaft `pending` mit Operation-/Versuchs-ID speichern, nachher `confirmed` oder `unknown`. API-Idempotenzschlüssel nutzen, soweit Anbieter sie unterstützen; nicht bloß nachträglich einen Erfolgslog schreiben.
- [ ] Alle Wiederholungseinstiege auf denselben Gate-Service führen. Unbekannter Ausgang nach Submit/Timeout verlangt fachlichen Abgleich; nicht blind wiederholen. Ohne bestätigbare Idempotenz keine universelle Exactly-once-Garantie behaupten.
- [ ] Endverifikation weiter mit eingefrorener Definition und unveränderten Erfolgskriterien durchführen. Testkonten/Fixture-Reset, lesende Ergebnisverifikation oder ausdrücklich idempotente Schritte festlegen. Fachliches Ziel nicht schwächen, nur weil ein erneuter kompletter Schreibdurchlauf gefährlich wäre.
- [ ] Copilot darf Reparaturaufgaben erstellen und bis zum überprüften Ziel weiterarbeiten, solange Aktionen innerhalb des erlaubten Budgets/Scopes sicher ausführbar sind. Menschliche Challenge, fehlende Berechtigung oder unklarer Schreibausgang sind begründete Haltepunkte, keine Endlosschleifen.

**Abnahme:** Lokales Fixture zählt Wirkungen. Crash direkt nach Submit, Queue-Redelivery, Probe, Strukturreparatur und Endprüfung erzeugen zusammen höchstens eine bestätigte identische Operation. Unklarer Ausgang wird reconciled oder erklärt blockiert. Manuelles „Neustarten“ umgeht den Schutz nicht.

### AP09 — Atomische Statusdateien und robuste Beobachtung

**Priorität:** P1. **Befund:** F11. **Abhängigkeit:** unabhängig; Vertrag mit AP06/AP07 abstimmen.

**Dateien:** `run_step.cjs::writeJson`, `WorkflowTaskRunner::writeJsonFile/readRun`, Monitoring in `WorkflowExecutionService`, Rust-Checkpoint-/Resultatleser.

- [ ] Gemeinsames Schreibmuster: private Tempdatei im selben Verzeichnis, vollständiger Write, erforderlichenfalls Flush, atomisches Replace. Fehlerpfade und Windows-Dateisemantik separat testen.
- [ ] Snapshotversion, monotone Sequenz, Writer-/Run-/Attempt-ID und expliziter Lifecycle. Pro Datei genau ein autoritativer Writer.
- [ ] Reader unterscheidet fehlend, ungültig, veraltet, laufend, terminal. Kurzfristig kaputter Status behält letzten gültigen Stand und löst begrenzten Retry aus, nicht sofort fachlichen Fehler.
- [ ] Callback und Polling vereinheitlicht idempotent anwenden; ältere Sequenzen ignorieren. Terminaler Zustand braucht validiertes Ergebnis beziehungsweise ausdrücklichen, reconcilierten Prozessausfall.

**Abnahme:** Gleichzeitiger großer Write/Read, Abbruch mitten im Write und verspäteter alter Snapshot erzeugen keinen falschen Abschluss. Callback plus Monitor übernimmt dasselbe terminale Ergebnis einmal. Dauerhaft fehlende Daten führen nach definierter Frist zu konkreter Diagnose, nicht endlosem „läuft“.

### AP10 — Erklärte Retry-Zustände und selbstständige Recovery

**Priorität:** P1. **Befund:** F12. **Abhängigkeit:** AP07–AP09; AP08-Gates vor automatischer Wiederholung externer Aktionen.

**Dateien:** `WorkflowCopilotQueueRecoveryService`, Supervisor/Sessionservice/-Job, Scheduler, Queuekonfiguration, gemeinsame Vorschau/Chatprojektion.

- [ ] `retry_wait`, `human_required`, `user_paused`, `configuration_error`, `budget_exhausted` und fachlicher Fehler unterscheiden. Reasoncode, letzter Fortschritt, `next_retry_at`, Versuchszähler und konkrete nächste Aktion persistieren.
- [ ] Nur klassifiziert vorübergehende und sicher wiederholbare Fehler automatisch retrien: begrenzter exponentieller Backoff mit Jitter, Deadline, globalem Kosten-/Versuchsbudget und Retry-After-Unterstützung.
- [ ] Reconciler holt fällige Retries atomar ab; doppelte Scheduler-/Queuezustellung erzeugt keinen doppelten Start. Timer bleibt über Worker-/Serverrestart erhalten.
- [ ] User-Pause/Stop, fehlende Credentials, CAPTCHA/2FA und Scope-/Berechtigungsprobleme nicht automatisch aufheben oder umgehen. Sichtbar erklären, was fehlt; nach zulässiger Eingabe vom richtigen Checkpoint weiterarbeiten.
- [ ] Kurze Steuer-/Monitorjobs von langsamen AI-Jobs trennen. Workerzahl erst nach Claims/Leases erhöhen; Timeout, Queue-retry_after und Supervisor-Stopfristen konsistent konfigurieren.

**Abnahme:** Temporärer 429/Timeout zeigt nächsten Versuch und wird einmalig wiederaufgenommen; dauerhaft ungültige Konfiguration erklärt Blocker; Userpause bleibt bestehen; Queue-Neustart verliert Termin nicht; geschlossenes UI stoppt Recovery nicht. Nach ausgeschöpftem Budget klare Endlage statt stiller Pause.

### AP11 — Modellplanung außerhalb von Datenbanktransaktionen

**Priorität:** P1. **Befund:** F13. **Abhängigkeit:** bestehendes Revisionssystem beibehalten; parallel zu AP09 möglich.

**Dateien:** `WorkflowCopilotPlanningService`, `WorkflowRevisionService`, Erstplanungszweig des Supervisors; gezielte Lock-/Revisionskonflikttests.

- [ ] Bereits vorhandene Methoden `plan()` und `applyPlan()` im Supervisor entkoppelt aufrufen: `plan()` außerhalb von `revisions->apply()`, innerhalb nur die validierte Anwendung. Normalisierten Plan mit Snapshot-Hash, erwarteter Revision und tatsächlicher Provider-Usage persistieren.
- [ ] Unter kurzer Transaktion erneut Status, Eigentümer, Sessionlease und Revision prüfen; nur validierte Mutation anwenden. Zwischenzeitlicher Stop/Pause verhindert Apply und Start.
- [ ] Konflikte nachvollziehbar verwerfen oder neu planen; niemals neuere Änderungen überschreiben. Retry eines bereits gültig gespeicherten Plans darf nicht unnötig einen zweiten Modellaufruf auslösen.
- [ ] Vision → strukturierter Befund → Datenanalyse/Planung als bestehende Rollenverteilung erhalten. Dieselben Katalog-/Routing-/Variablenregeln und redigierte Beobachtungen verwenden.

**Abnahme:** Künstlich zehn Sekunden blockierender Planer blockiert Pause/Stop nicht. Revision wird während Planung geändert → kontrollierter Konflikt, keine Überschreibung. Planretry erzeugt keine doppelte Mutation und keine doppelte Kostenzuordnung.

### AP12 — Pfadbewusste Workflow- und Variablenvalidierung

**Priorität:** P2. **Befund:** F14. **Abhängigkeit:** keine Sessionmigration; eigenen Dateibereich getrennt bearbeiten.

**Dateien:** `WorkflowDefinitionValidator`, `WorkflowTaskCatalog`, vorhandener Routing-/Loopvertrag, Copilot-Promptkontext und Editor-Diagnosen.

- [ ] Einen kanonischen Taskgraph aus tatsächlicher Routingsemantik ableiten; nicht zusätzliche abweichende IF-/Looplogik erfinden.
- [ ] „Sicher belegt“ und „möglicherweise belegt“ je Kante berechnen; Branch-Merge mit Schnittmenge garantierter Variablen, Loopscope und potenziell null Iterationen berücksichtigen.
- [ ] Typen/Optionalität für Inputs und Outputs aus dem Katalog prüfen. Dynamische Fälle als begründete Warnung, definitiv fehlende Daten als Fehler; Workfloweingaben als initiale Produzenten einbeziehen.
- [ ] Diagnose mit Task-Key, Feld, betroffener Route und konkreter Reparatur im Editor, Preflight und Copilot identisch anbieten.

**Abnahme:** Consumer vor Producer, Producer nur im anderen IF-Zweig, leerer Loop und Loopvariable außerhalb des Scopes werden korrekt erkannt; gültige optionale Zweige/Workfloweingaben bleiben zulässig. Fehlerhafte Definition startet keinen externen Schreibschritt.

### AP13 — Gemeinsame Liveprojektion, Sessioninspektor und Copilot-UX

**Priorität:** P2. **Befund:** F15 plus Transparenz aus F01–F12. **Abhängigkeit:** Status-/Identitätsverträge AP02/AP04/AP10 stabilisieren.

**Dateien:** `WorkflowRunPreview`, `WorkflowManager`, `WorkflowCopilotRuns`, Chatbot, zugehörige Blade-/App-Shell-Komponenten, private Realtimekanäle/Projektion. Keine zweite Vorschau anlegen.

- [ ] Autorisierten Run-/Sessionkanal mit Eventcursor/Revision ergänzen. Kleine inkrementelle Projektion statt vollständiger Aufbereitung aller Details pro Poll; Screenshots/Logs/Variablen lazy laden.
- [ ] UI nur lesend aktualisieren; Fortschritt durch Callback/Scheduler. Langsamer Poll als Reconnect-Fallback, Pause unsichtbarer Panels, Stop nach terminalem Zustand.
- [ ] Sessioninspektor zeigt Person, Accountreferenz, Authgruppe/Origins, Profilhost, Revision, gespeichert/geladen/authentifiziert/accountbestätigt, Zeitpunkt, unterstützte Datentypen und nächsten Retry. Keine Cookie-/Tokenwerte in DOM/Livewire-Payload.
- [ ] Copilot rechts als feste, volle verfügbare Höhe nutzende Layoutspalte behandeln, die Platz im Grid/Flexlayout reserviert. Kein Contentoverlay auf Desktop; auf kleinen Viewports expliziter zugänglicher Wechsel/Drawer. Bestehenden aktuellen Shellstand zuerst visuell prüfen, nicht frühere UI-Annahmen ungeprüft implementieren.
- [ ] „Fortsetzen“, „erneut prüfen“, „Neustart mit bestehender Session“ und „frischer Testkontext“ klar unterscheiden. Neustart zeigt betroffene Identität und Replayfolgen, verwendet AP08-Gates und bewahrt Audit/Revisionen. Globales Löschen nicht hinter einem harmlosen Neustartlabel verstecken.
- [ ] Erfolg erst bei technischer Fertigstellung und bestätigten fachlichen Kriterien. Wait/Pause zeigt Grund, nächste Aktion und zuständige Instanz; benötigte Nutzereingabe ist direkt erreichbar.

**Abnahme:** UI geschlossen → gleiches Laufergebnis; zwei Vorschauen starten keine Zusatzaktionen; Reconnect lädt fehlende Events; nicht autorisierter Kanal verweigert Zugriff. Responsive Prüfung bei 390/768/1280/1440 px, Tastaturbedienung, Scroll-/Fokuszustand und kein verdeckter Inhalt. Request-/Query-/Payloadmessung vor/nach Änderung statt behaupteter Prozentersparnis.

### AP14 — Runtimevertrag vereinheitlichen und erst dann beschleunigen

**Priorität:** P2 für Parität, P3 für größere Architekturänderung. **Befund:** F15 [H hinsichtlich Nutzen]. **Abhängigkeit:** AP02, AP06–AP09.

**Dateien:** `WorkflowTaskRunner::start/remoteRuntime`, `ClientWorkflowBundleCompiler`, Node-Executor, Client-Protokoll; Benchmarks/Golden-Contracttests.

- [ ] Versionierten RuntimeSpec-Builder einführen. Fachliche Tasks, Inputs, Routen, Identität und Ergebnisvertrag teilen; targetabhängige Pfade/Secrets/Capabilities in Adapter auslagern.
- [ ] Baseline für 10/100/1000 Tasks, Normal/Studio/Copilot und Loops erfassen: Prozessstarts, CDP-Reconnects, p50/p95 Tasklatenz, Snapshotdauer/-größe, Queries, UI-Requests und tatsächliche AI-Usage. Keine Geheimnisse oder unbeschränkte Labelwerte in Metriken.
- [ ] Vorhandene Preloads, Status-Throttles und Completion-Callbacks erhalten. Rein zeitliche Tasks nicht unnötig an einen Browser binden, wenn der Katalog das ohne semantische Änderung erlaubt.
- [ ] Nur bei gemessenem Nutzen sichere Bündelung oder langlebigen Runworker prototypisieren. Checkpoint, Pause/Stop, Routing, Wirkungsschutz, Recovery und Ressourcenlimits sind Vorbedingungen, nicht spätere Ergänzungen.
- [ ] Unnötige Vision-Aufrufe weiter anhand verlässlicher Checkpoints vermeiden; unklare/fehlgeschlagene UI oder Accountvalidierung trotzdem beobachten. Eingefrorene Endverifikation und Erfolgskriterien nicht für Geschwindigkeit schwächen.

**Abnahme:** Golden-Tests zeigen fachliche System-/Clientparität; gleiche Routen und Checkpoints vor/nach Optimierung; keine zusätzliche Nebenwirkung; messbare Verbesserung im gewählten Engpass. Kein Big-Bang-Wechsel zusammen mit Sessionmigration.

### AP15 — Migration, Runtimekompatibilität und gestufte Freigabe

**Priorität:** P1 als Freigabegate aller Vertragsänderungen. **Abhängigkeit:** begleitet AP01–AP14; Teilmigrationen jeweils separat freigeben.

**Dateien:** additive Migrationen, Featureflags/Konfiguration, Client-Runtimesync und Releaseworkflow, Fingerprint-/Kompatibilitätstests, Betriebsdokumentation.

- [ ] Read-only Inventar: alte Sessionkeys, Accountkonflikte, Profileigentümer, nicht entschlüsselbare Payloads und Clientcapabilities zählen. Nur redigierte Zusammenfassungen; keine Sessionwerte exportieren.
- [ ] Neue Tabellen/Spalten additiv; Migration pro eindeutigem Owner idempotent. Unklare Profile/Keys in Quarantäne, nicht raten. v1-Leseadapter erhalten, v2-Schreibpfad hinter Flag; kontrolliertes Dual-Write nur über denselben zentralen Store, nicht zwei unabhängige Wahrheiten.
- [ ] Kompatibilitätsmatrix Server/Client/Snapshotversion festlegen. Fehlende erforderliche Capabilities vor Ausführung ablehnen; Legacy ohne Fingerprint nicht still für neue sicherheitskritische Verträge zulassen.
- [ ] Nodeänderungen mit `npm run sync:workflow-runtime` im ClientController synchronisieren, generiertes Paket bauen und Fingerprint/Dateigleichheit prüfen. Das lokale generierte Verzeichnis war im Audit nicht vorhanden; daraus folgt weder vorhandene noch fehlende Synchronität eines installierten Clients.
- [ ] Rollout: CI-Fixtures → ausgewählte Testperson/Account → kleine freigegebene Gruppe → breiter. Telemetrie für Restore, Authbestätigung, Konflikte, verlorene Updates, Retries und unklare Effekte beobachten.
- [ ] Rollback je Paket dokumentieren: v2-Daten erhalten, Schreibflag zurücknehmen, inkompatible Clients stoppen; keine destruktive Downmigration produktiver Sessions. Rohdaten-Leaks oder alte falsche Isolation nicht als akzeptablen Rollback wieder aktivieren.

**Abnahme:** Migration zweimal gleiches Ergebnis; v1/v2-Readmatrix getestet; Client mit falscher Version erhält verständlichen Fehler vor Browserstart; Dry-run verändert nichts; Rollback verliert keine neue Session; verschlüsselte Wiederherstellungs-/Backupstrategie separat geprüft.

## 7. Reihenfolge, Gates und sinnvolle Parallelisierung

| Welle | Pakete | Freigabekriterium |
| --- | --- | --- |
| 0: Sicherheitsbasis | AP01; AP03-Hotfix; AP08-Vertrag/Tests plus konservatives Replaygate | Keine Raw-Sessionkopien in Diagnosefeldern; keine Löschung fremder Origins; autonome externe Wiederholungen bis vollständiger AP08-Integration gesperrt |
| 1: Verlässliche Identität/Steuerung | AP02; AP09; AP11 | Ein Ownervertrag für alle Runner; Statuslesefehler nicht terminal; Pause/Stop unabhängig von AI-Lock |
| 2: Persistenz und Isolation | AP04; AP05; AP07 | Multi-Origin-/Tabvertrag, konkurrierende Saves, Accountquelle und Profil-Leases grün |
| 3: Durchgängige Ausführung | AP06; AP08-Integration; AP10 | Alle Snapshotupdates bestätigt; Replaygate überall; transienter Fehler kann sicher weiterlaufen |
| 4: Qualität und Bedienung | AP12; AP13 | Pfadfehler vor Start erkannt; gemeinsame verständliche Vorschau, Recovery ohne offenes UI |
| 5: Gemessene Beschleunigung | AP14 | Semantik stabil, Verbesserung durch Benchmark belegt |
| Begleitend | AP15 | Jede Welle nur mit passender Migration/Kompatibilität ausrollen |

Parallel möglich: AP01 Rust/API, AP03 Node-Löschung, AP09 Status-I/O und AP12 Validator mit klaren Dateiansprüchen. AP02/AP05/AP06 greifen in gemeinsame Execution-/Sessiondienste ein und brauchen sequenzielle Integration beziehungsweise abgestimmte Schnittstellen. Nicht mehrere Agents gleichzeitig unkoordiniert `WorkflowExecutionService` oder `run_step.cjs` groß umbauen lassen.

Keine belastbare Zeitabschätzung ohne Entscheidung über IndexedDB-Portabilität und Betriebs-/Clientversionen. AP03/AP09 sind begrenzte Korrekturen; AP02/AP04/AP05/AP06/AP08 sind Vertragsänderungen und sollten jeweils getrennte überprüfbare Änderungen erhalten.

## 8. Abnahmematrix für das Codemodell

| ID | Szenario | Zwingendes Ergebnis | Paket |
| --- | --- | --- | --- |
| T01 | Person A/B, gleicher Provider, sogar gleiche E-Mail | Keine geteilten Profile/Snapshots ohne expliziten gemeinsamen Owner | AP02/AP07 |
| T02 | Eine Person, zwei Accounts derselben Domain | Richtiger Account geladen und positiv bestätigt | AP02/AP04 |
| T03 | Gleicher Account in Workflow X/Y | Wiederverwendung nach Policy, kein willkürlicher workflowabhängiger Miss | AP02/AP05 |
| T04 | Unbekannte Domain/ID; Key passt, Domain widerspricht | Not-found/Konflikt, keine fremde Navigation oder Tokeninjektion | AP03 |
| T05 | Cookie + HttpOnly + local/sessionStorage, frischer Kontext | Roundtrip und getrennte Readbackmetriken | AP04 |
| T06 | Zwei Tabs, gleicher Origin, verschiedene sessionStorage-Werte | Fensterzustände nicht verschmolzen | AP04 |
| T07 | Zwei Origins/SSO, zweite Navigation später | Beide erlaubten Zustände angewandt; fremder Origin unverändert | AP04 |
| T08 | Partitionierter/abgelaufener/abgewiesener Cookie | Partition erhalten oder explizit unsupported; keine falsche Erfolgsmeldung | AP04 |
| T09 | Loginseite, abgemeldet, falscher Account, Challenge | Keine falsche Accountbestätigung; nachvollziehbarer Zustand | AP04/AP10 |
| T10 | B löschen während A aktiv; typed/string false | A unangetastet, Flags respektiert, Teilerfolg sichtbar | AP03 |
| T11 | Zwei stale Personeninstanzen speichern verschiedene Keys | Beide Updates erhalten; fremde Metadaten ebenfalls | AP05 |
| T12 | Neue Webmail-Session bei bestehender primärer Mailbox | Kanonischer Account und Spiegel widerspruchsfrei | AP05 |
| T13 | Alter Save → Loginänderung → Autosave; Fehlercapture | Neuester bestätigter Stand; last-good nicht durch Fehler ersetzt | AP06 |
| T14 | Mehrere Save/Delete-Updates, ACK verloren/dupliziert; Delete → alter Save → Restart | Geordnet, vollständig und einmalig angewandt; Widerruf bleibt wirksam, keine Wiederbelebung | AP03/AP06 |
| T15 | Server → Client A → Server → Client B | Alle deklarierten Capabilities erhalten, gleiche Identität | AP02/AP04/AP06 |
| T16 | Raw-Sentinel in verschachteltem Bundle/Progress/Outbox | Kein Raw-Sentinel in DB-Diagnostik/UI/Export/Logs | AP01 |
| T17 | Manuell + Scheduler/Studio starten dasselbe Profil | Lease wartet; kein leerer Retry-Profilordner | AP07 |
| T18 | Parallel-advance, Doppelcallback, Prozesscrash | Ein Start/Commit pro Claim; Recovery findet ursprünglichen Versuch | AP07/AP09 |
| T19 | Teilweise/fehlende/veraltete Statusdatei | Nicht irrtümlich terminal; begrenzte Diagnose/Recovery | AP09 |
| T20 | Submit erfolgreich, Antwort verloren, dann Restart/Verifikation | Kein blinder Doppelsubmit; Abgleich oder begründeter Halt | AP08 |
| T21 | 429/Timeout versus Userpause/Budget/CAPTCHA | Nur sichere transiente Fehler automatisch erneut; Grenzen bleiben wirksam | AP10 |
| T22 | AI plant langsam, inzwischen Pause oder Revision | Steuerung bleibt schnell; kein veraltetes Apply | AP11 |
| T23 | Producer nur später/anderer Branch/leerer Loop | Pfadbezogene Diagnose mit Reparaturhinweis | AP12 |
| T24 | Zwei UI-Panels/reconnect/UI geschlossen | Gleicher Lauf, keine Extraaktion, autorisierter Cursor-Reconnect | AP13 |
| T25 | Cleanup + aktive Lease; Schema-/Clientmismatch | Kein aktives Profil gelöscht, inkompatibler Start verhindert | AP07/AP15 |

Zusätzliche Fehler-Injektionen: DB-Ausfall vor/nach Commit, Snapshot zu groß/beschädigt, Schlüsselrotation/Decryptfehler, Clock-Skew, Leaseverlust, Client offline und Rückkehr, Browserkontext neu erstellt. Tests dürfen weder fremde Accounts anlegen noch echte Mails/Posts/Bestellungen auslösen; lokale Fixtures mit zählbaren Wirkungen verwenden.

## 9. Tatsächlich ausgeführte Verifikation und Reproduktion

### 9.1 Bestehende Tests

| Suite | Ergebnis | Aussagegrenze |
| --- | --- | --- |
| `WorkflowBrowserSessionSettingsTest` + `WorkflowBrowserProfileTest` | 9 Tests, 37 Assertions grün | Konfiguration/bisherige Profilregeln, nicht vollständige Isolation |
| `WorkflowBrowserProfileTest` + `WorkflowRuntimeFingerprintTest` | 13 Tests, 171 Assertions grün | Profiltest überlappt vorherige Zeile; nicht aufsummieren |
| Callback + QueueRecovery + ExecutionInvariant + ConcurrencyCap | 39 Tests, 207 Assertions grün | Vorhandene Verträge; keine echte Mehrverbindungs-Raceabnahme |
| Node Browser-Session-/Delete-Tests | 11/11 grün | Bisher getestete Fälle, die beschriebenen Lücken fehlen teilweise |
| Node CompletionCallback/Observability/Preload | 11/11 grün | Existierende Optimierungen bestätigt |
| Zusätzlicher isolierter PHP-Probe | 2 Tests, 11 Assertions grün | Charakterisiert Lost-Update und veraltete Mailboxzeile absichtlich als Fehlerbeleg |
| Zusätzlicher echter Chromium-Probe | 4 Diagnosefälle erfolgreich charakterisiert | Einzelorigin klappt; Multitab-/Multiorigin-/Löschdefekt reproduziert |

Die grünen Bestandsprüfungen widerlegen die Befunde nicht: Sie prüfen andere beziehungsweise bisherige Verträge. PHP-Bestandssuite mit explizitem synthetischem Test-APP_KEY wiederholt; ohne diesen scheiterte zuvor ein Callbacktest an einer leeren Assertion-Nadel, nicht an einem Callbackfunktionsfehler.

Abschließender gemeinsamer Wiederholungslauf exakt mit den folgenden Befehlen: **57 PHP-Tests / 406 Assertions ohne Fehler (eine bekannte Deprecation), 22 Node-Tests ohne Fehler**. Beide erhaltenen Probes danach beziehungsweise separat ebenfalls erfolgreich ausgeführt; alle 58 lokalen Dokumentlinks auf Existenz und gültige Zeilennummer geprüft.

Ausführung aus dem AiUserFactory-Verzeichnis, ausschließlich gegen SQLite in-memory:

```bash
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
DB_CONNECTION=sqlite DB_DATABASE=:memory: \
php -d max_execution_time=0 vendor/bin/phpunit \
  tests/Feature/WorkflowBrowserSessionSettingsTest.php \
  tests/Unit/WorkflowBrowserProfileTest.php \
  tests/Unit/WorkflowRuntimeFingerprintTest.php \
  tests/Feature/WorkflowRuntimeCallbackTest.php \
  tests/Feature/WorkflowCopilotQueueRecoveryTest.php \
  tests/Feature/WorkflowCopilotExecutionInvariantTest.php \
  tests/Feature/WorkflowRunConcurrencyCapTest.php

node --test \
  tests/Node/browser_session_tasks.test.cjs \
  node/workflows/tasks/data/delete_browser_session.test.cjs \
  tests/Node/workflow_completion_callback.test.cjs \
  tests/Node/workflow_observability.test.cjs \
  tests/Node/workflow_task_preload.test.cjs
```

### 9.2 Erhaltene Diagnose-Probes

Der [Browser-Probe](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/docs/audits/session-roundtrip-probe-2026-09-25.cjs) startet einen temporären Puppeteer-Browser mit frischen Kontexten. Requests der Seiten werden abgefangen, nur zwei synthetische `.test`-Origins werden beantwortet. Kein vorhandenes Profil, keine Anmeldedaten, keine Anbieterkonten. Der Browser wird im `finally` geschlossen.

```bash
node docs/audits/session-roundtrip-probe-2026-09-25.cjs
```

Beobachtet:

```text
same-origin roundtrip: cookie=true, localStorage=true, sessionStorage=true
multi-tab capture: 2 tabs, only origin 1 captured
multi-origin restore: reported=2, origin 2 later has no saved storage
delete B with both flags=false: actual flags=true, storage of A deleted
```

Der [PHP-Probe](/Users/lucaszacharias/Desktop/pojekte/follow-flow/AiUserFactory/docs/audits/FollowflowSessionIsolationProbeTest.php) verwendet nur Composer-Autoload, einen minimalen Illuminate-Container, einen synthetischen Verschlüsselungsschlüssel und zwei Tabellen in SQLite `:memory:`. Kein Laravel-App-Bootstrap und kein dotenv. Separat ausführen, nicht als Bestandteil der normalen App-Suite: Er ersetzt globale Container-/Facadeinstanzen nur für diesen isolierten Prozess.

```bash
php vendor/bin/phpunit --no-configuration --do-not-cache-result \
  --bootstrap vendor/autoload.php \
  docs/audits/FollowflowSessionIsolationProbeTest.php
```

Beobachtet: erster Save `[session-a]`, zweiter Save `[session-b]`; beide melden Erfolg. Webmail-Metadatenspiegel aktualisiert, primäre Accountzeile unverändert.

**Achtung:** Die Probes sind Momentaufnahmen des Istverhaltens und enthalten absichtlich Assertions auf die Defekte. Nach Korrektur müssen normale Regressionstests das Sollverhalten verlangen; einen dann fehlschlagenden historischen Probe nicht durch Wiedereinführen des Bugs „reparieren“. Deutlich markieren oder archivieren, wenn der betroffene Stand überholt ist.

## 10. Bestehende Funktionen erhalten statt erneut implementieren

Bereits vorhanden und zum Teil gezielt getestet: Completion-Callbacks, Node-Modul-Preload, Observability-Gating, private DOM-Artefakte, Status-Throttling, begrenzte Eventlisten, zentrale Taskkatalogauflösung, Supervisor-Leases/Recheck, Recovery-Reconciler, wiederaufnehmbare Checkpoints und eingefrorene Endverifikation. Erfolgreiche eindeutige Checkpoints überspringen bereits unnötige Vision-Aufrufe. Ältere Optimierungsberichte sind in diesen Punkten nicht mehr der Iststand.

Client-Release synchronisiert und vergleicht die Runtime; Rust prüft Fingerprints vor der Ausführung, akzeptiert bisher teilweise noch Legacy-Bundles ohne Hash. Die im Audit nicht vorhandenen generierten Runtime-Dateien sind gitignored und werden beim Build erzeugt. Eine Codeanalyse belegt nicht den Stand einer bereits installierten Clientversion.

## 11. Übergabeanweisung an das implementierende Codemodell

> Lies zuerst README-Regeln und diesen Bericht. Implementiere pro Änderung ein abgegrenztes AP-Paket und beanspruche die betroffenen Dateien im Arbeitsprotokoll. Prüfe vor jedem Fix, ob die referenzierten Stellen im aktuellen Commit noch gelten. Beginne mit AP01 und AP03 sowie dem AP08-Sicherheitsvertrag. Erstelle zuerst synthetische Sollverhaltenstests für die jeweiligen Befunde. Keine echten Providerkonten oder externen Schreiboperationen zum Testen verwenden. Keine bestehende Session/Profile ungeprüft löschen oder migrieren. Bewahre Taskkatalog, Routingsemantik, gemeinsame Vorschau, append-only Auditlog und unveränderliche Endverifikation. Nach Nodeänderungen Clientruntime synchronisieren und Kompatibilität testen. Dokumentiere je Paket geänderte Dateien, Testergebnis, Migration/Rollback, Restrisiko und nächsten Schritt. Behaupte keine vollständige Sessionwiederverwendung, bevor Identitäts-, Multi-Origin-/Tab-, Konkurrenz-, Cross-Machine- und Authprüfungen aus Abschnitt 8 bestanden sind.

Definition of Done für das Gesamtziel: Eine eindeutig ausgewählte Person mit einem eindeutig ausgewählten Account kann in einem späteren freigegebenen Workflow auf einem unterstützten Ausführungsziel ihre unterstützten Browserdaten wiederverwenden; der richtige Account wird bestätigt, andere Personen/Accounts bleiben unverändert, jeder Speicher-/Transferfehler ist sichtbar, und Copilot setzt sicher wiederholbare Schritte bis zur unveränderten fachlichen Zielprüfung fort. Nicht automatisch lösbare Berechtigungs-/Challenge-/Wirkungskonflikte enden mit einer konkreten benötigten Aktion statt einer unerklärten Pause.
