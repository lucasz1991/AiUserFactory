```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
supervisorctl reread
supervisorctl update
supervisorctl restart followflow-queue:*
```

Production must use `APP_ENV=production`, `APP_DEBUG=false` and
`QUEUE_CONNECTION=database`. An example Supervisor configuration is available
at `deployment/supervisor-followflow-queue.conf.example`. The full client-side
workflow protocol does not depend on the queue worker for live progress or
step routing, while legacy and non-portable fallback workflows still do.

Geplante Aufgaben ausführen:

php artisan schedule:run

Admin-Tasks & Reports generieren:

php artisan admin:tasks

API & Integrationen

PayPal API für Verkäuferauszahlungen

ApexCharts für animierte Statistiken

Cookiebot für DSGVO-konforme Einbindung von Google Maps

Benutzerverwaltung mit Jetstream & Teams

Support & Weiterentwicklung

Feature-Wünsche und Fehlerberichte können über GitHub Issues eingereicht werden. Updates werden regelmäßig implementiert, insbesondere Sicherheits- und Performance-Optimierungen.

# AiUserFactory

## Agenten-Uebergabe: Workflow Manager und Workflow Copilot

Stand: 2026-07-15

Dieser Abschnitt ist die gemeinsame Kommunikationsebene fuer Codex, Claude und
weitere Agents. Vor Arbeiten am Workflow Manager oder Workflow Copilot zuerst
diesen Abschnitt und danach die genannten Kerndateien lesen. Nach einer
wesentlichen Aenderung den Ist-Stand, die Verifikation und den letzten Eintrag im
Arbeitsprotokoll aktualisieren.

### Soll-Ist

| Bereich | Soll | Ist |
| --- | --- | --- |
| Vorschau-Tests | Manuelle Tests und Copilot-Live-Optimierung verwenden dieselbe Oberflaeche mit Workflow-Karte, Steps, Tasks, Browserfenstern, Screenshots, Variablen, Artefakten und Logs. | Umgesetzt. Beide Wege verwenden `showRunPreviewModal` und `x-workflows.run-preview`. Das fruehere separate Copilot-Vorschaumodal wird nicht mehr geoeffnet. |
| Einfluss des Copiloten | Der Copilot kann den gemeinsamen Test beobachten, an sicheren Task-Grenzen pruefen, pausieren, fortsetzen, anweisen, zurueckspulen und stoppen. | Umgesetzt. Jeder Copilot-Task wird als wartender Checkpoint gehalten und als `checkpoint.review_pause` protokolliert. Die Steuerungen liegen im gemeinsamen Vorschaumodal und im Chat. |
| Leerer Workflow | Aus Zielbeschreibung, Erfolgskriterien und benannten Workflow-Eingaben soll ein leerer Workflow selbststaendig geplant und aufgebaut werden. | Umgesetzt. `WorkflowCopilotPlanningService` fordert eine JSON-Planung an, erlaubt nur Keys aus `WorkflowTaskCatalog`, legt Steps und Tasks an und erzeugt Loop-Endsegmente automatisch. Erst danach startet die Optimierung. |
| Kurze Arbeitszyklen | Der Copilot soll nicht viele Aenderungen blind stapeln, sondern regelmaessig Kontext und Teststatus neu pruefen. | Umgesetzt. Der Assistant-Systemprompt fordert nach hoechstens zwei aendernden Toolaufrufen eine erneute Kontext- oder Statuspruefung. Der Runtime-Supervisor prueft weiterhin jeden Task-Checkpoint. |
| Chat-Scroll | Nach neuen Nachrichten, Poll-Ergebnissen und Streaming-Text muss der Chat unten bleiben. | Umgesetzt. Ein `MutationObserver` beobachtet den Nachrichtenbereich einschliesslich Streaming-Text und scrollt nach jeder DOM-Aenderung ans Ende. |
| Serverlokale Sprache | Spracheingabe und -ausgabe sollen ohne kostenpflichtigen Anbieter und ohne oeffentlichen Voice-Port direkt auf dem Laravel-Server laufen. | Anwendungscode und UI sind produktiv ausgerollt. `whisper_local` nutzt ffmpeg plus whisper.cpp, `piper_local` Piper per CLI. Die Plesk-Runtime ist noch nicht installiert: Der Live-Status meldet deaktiviert sowie alle sechs Komponenten als fehlend; Vosk/eSpeak bleiben bis zur Installation aktiv. |
| Vollstaendiges Protokoll | Sichtbare Antworten, Toolaufrufe, Toolergebnisse, Anpassungen, Revisionen, Checkpoints, Taskversuche und alle Testlaeufe muessen exportierbar sein. | Umgesetzt. `WorkflowCopilotLogExportService` erzeugt ein ZIP mit Gesamtsnapshot, JSONL-Ereignisstrom, Chat-/Toolprotokoll, finalem Workflow und einem bereinigten Debug-ZIP pro Run. |
| Geheimnisse | Exportierte Diagnosepakete duerfen keine Passwoerter, Tokens, Cookies, Session-Payloads oder WebSocket-Endpunkte enthalten. | Umgesetzt fuer strukturierte sensible Felder und bekannte Geheimwerte. Freitext bleibt grundsaetzlich sorgfaeltig zu behandeln und darf keine unbekannten Geheimnisse enthalten. |
| Ausfuehrungsziel | Autonome Reparaturen duerfen nur auf dem System-Runner laufen. | Umgesetzt und serverseitig erzwungen. ClientController bleibt fuer normale Vorschau-Tests moeglich, aber nicht fuer Copilot-Reparatursitzungen. |

### Architektur und Zustaendigkeiten

| Datei | Zustaendigkeit |
| --- | --- |
| `app/Livewire/Admin/Network/WorkflowManager.php` | Startparameter, gemeinsames Vorschaumodal, Copilot-Steuerung und Log-Download. |
| `resources/views/livewire/admin/network/workflow-manager.blade.php` | Workflow-Editor, Startdialog und gemeinsame Vorschau-/Copilot-Oberflaeche. |
| `app/Livewire/Admin/Network/WorkflowRunPreview.php` | Datenprojektion fuer Workflow-Karte, Browserfenster, Timeline, Variablen und Artefakte. |
| `resources/views/livewire/admin/network/workflow-run-preview.blade.php` | Die eine verbindliche Vorschau fuer manuelle und autonome Runs. |
| `app/Services/Workflows/WorkflowCopilotPlanningService.php` | Kataloggebundene Erstplanung und Aufbau eines leeren Workflows. |
| `app/Services/Workflows/WorkflowCopilotSupervisorService.php` | Checkpoint-Beobachtung, Reparatur, Probe, Fortsetzung und Endverifikation. |
| `app/Services/Workflows/WorkflowCopilotSessionService.php` | Persistente Sitzungen, unveraenderliche Events, Checkpoints, Status und Locks. |
| `app/Services/Ai/WorkflowAssistantToolService.php` | Assistant-Tools, Systemprompt und Start von normalen Tests bzw. Optimierungen. |
| `app/Livewire/Tools/Chatbot.php` | Chatdurchlauf, Toolausfuehrung und persistente Chat-/Tool-Auditereignisse. |
| `resources/views/livewire/tools/chatbot.blade.php` | Chatinteraktion, Streaming, Autoscroll und Weiterleitung zur gemeinsamen Vorschau. |
| `app/Services/Ai/LocalAssistantVoiceService.php` | Isolierte ffmpeg-/Whisper-/Piper-Prozesse, Status, Locks, Timeouts und Temp-Bereinigung. |
| `scripts/bootstrap-local-assistant-voice.sh` | Gepinnter, idempotenter Linux/Plesk-Bootstrap ohne offenen Voice-Port. |
| `docs/local-assistant-voice.md` | Installation, Betrieb, Diagnose und Rollback der lokalen Voice-Runtime. |
| `app/Services/Workflows/WorkflowCopilotLogExportService.php` | Bereinigter, uebertragbarer Gesamtlog einer Optimierung. |

### Verbindliche Regeln fuer weitere Agents

1. Keine zweite Vorschau fuer den Copilot anlegen. Neue Run-Daten in
   `WorkflowRunPreview` und der bestehenden Vorschau ergaenzen.
2. Keine Task-Keys erfinden. Planung und Mutationen muessen ueber
   `WorkflowTaskCatalog` laufen.
3. Ein leerer Workflow darf nur vor dem Sitzungs-Lock initial aufgebaut werden.
   Spaetere Aenderungen laufen revisioniert ueber die aktive Copilot-Sitzung.
4. Erfolgskriterien waehrend der Endverifikation nicht abschwaechen und keine
   Workflow-Mutationen im eingefrorenen Kontrolllauf zulassen.
5. Events sind ein Auditlog und werden nicht aktualisiert oder geloescht. Neue
   Informationen als neues Event speichern.
6. Exporte immer redigieren. Neue sensible Kontextfelder in die Redaktionslogik
   von Run-Debugpaket und Copilot-Log aufnehmen.
7. Workflow-Runtime-Aenderungen unter `node/workflows` anschliessend in den
   ClientController synchronisieren. Reine Laravel-/Blade-Aenderungen brauchen
   diesen Sync nicht.
8. Die README ist das laufende Teamprotokoll. Vor Beginn den neuesten Eintrag
   lesen, das eigene Arbeitspaket mit Agent und Status `in_arbeit` kenntlich
   machen und nach jedem abgeschlossenen Arbeitspaket Ergebnis, Tests, Risiken
   und den naechsten Schritt nachtragen. Reine Such- oder Leseschritte brauchen
   keinen eigenen Eintrag.
9. Parallel arbeitende Agents bearbeiten nicht still denselben Bereich. Im
   Arbeitsprotokoll zuerst Dateibereich und Ziel beanspruchen; bei Ueberschneidung
   den vorhandenen Stand uebernehmen und die eigene Abgrenzung dokumentieren.

### Arbeitsprotokoll

Statuswerte: `geplant`, `in_arbeit`, `verifiziert`, `blockiert`.

| Datum | Agent | Status | Aenderung | Verifikation | Naechster Schritt |
| --- | --- | --- | --- | --- | --- |
| 2026-07-15 | Codex | verifiziert | Gemeinsame Vorschau, autonome Erstplanung, Checkpoint-Pruefpausen, Chat-Autoscroll und kompletter Audit-ZIP-Export. | PHP-Syntaxpruefung; gezielte Unit-/Featuretests mit SQLite in-memory. | Visuellen End-to-End-Test mit real konfigurierter AI-Verbindung und einer echten Browserseite durchfuehren. |
| 2026-07-15 | Codex | verifiziert | Teamprotokoll-Regeln ergaenzt, drei veraltete Testannahmen an bestehende Run-/Probe-Invarianten angepasst und eine gefundene JWT-Luecke in Modellprompt, Gesamtlog und Run-Debugpaket geschlossen. | 36 Unit-/Featuretests mit SQLite in-memory, 271 Assertions; alle fachlichen Tests bestanden, zwei bekannte lokale Env-Datei-Warnungen. | Gesamte Copilot-Testsuite, Blade-Kompilierung und Diff-Pruefung ausfuehren. |
| 2026-07-15 | Codex | verifiziert | Abschlussverifikation fuer den gemeinsamen Workflow-Manager-/Copilot-Stand abgeschlossen. | Gesamte Copilot-Suite: 67 Tests/526 Assertions; zusaetzliche UI-/Toolauswahl: 13 Tests/177 Assertions; echter verschachtelter Audit-/Run-Debug-ZIP-Test: 1 Test/20 Assertions; Blade-Cache erfolgreich; `git diff --check` ohne Inhaltsfehler; Pint fuer sieben unmittelbar geaenderte Dateien gruen. Zwei lokale Warnungen betreffen fehlende Env-Dateien. Der bestehende grosse `WorkflowRunDebugPackageService` ist als Gesamtdatei nicht Pint-sauber und wurde nicht unnoetig komplett umformatiert. | Realen End-to-End-Lauf mit konfigurierter AI-Verbindung und sichtbarer Browserseite durchfuehren; Ergebnis als neuen Eintrag anhaengen. |
| 2026-07-15 | Claude | verifiziert | Analyse der beiden geplanten Detailfixes ergab Korrekturen am Plan: (1) Die Budget-Vergleiche sind KEIN Off-by-one, sondern bewusstes Design (Gate `>=` vor neuer Aktion vs. Sicherheitsnetz `>` im Steady-State); statt Logikaenderung wurde die Semantik als Docblock an `budgetExceeded` dokumentiert. (2) Die beiden bekannten Testwarnungen stammen NICHT aus dem `WorkflowCopilotObservationService` (dessen Lesestellen sind bereits mit `is_file` abgesichert), sondern aus Dotenv `safeLoad` beim App-Boot wegen fehlender lokaler `.env`; behoben durch lokale `.env.testing` (nur Kommentare, phpunit.xml behaelt Vorrang) plus `.gitignore`-Eintrag. | Gesamte Copilot-Suite mit SQLite in-memory: 69 Tests/532 Assertions gruen, 0 Warnungen (vorher 2). Ursache der Warnung per Stacktrace verifiziert (vlucas/phpdotenv Reader). | Realer End-to-End-Lauf mit konfigurierter AI-Verbindung bleibt der wichtigste offene Punkt (siehe Codex-Eintraege). |
| 2026-07-15 | Codex | blockiert | Kostenfreie serverlokale Sprachein- und -ausgabe fuer den Workflow-Chatbot nach dem Luczor-Muster. Anwendung, Einstellungen, Chat, Aktivierungsbefehl, Fake-Binary-Tests, gepinnter Linux/Plesk-Bootstrap und Betriebsdokumentation sind umgesetzt. Chat-Autoscroll prueft das Ende zusaetzlich nach 80, 250 und 600 ms. Keine Aenderungen an Claudes Copilot-Supervisor-/Observation-Arbeitsbereich. | 16 Voice-/Provider-/Chat-Tests mit 119 Assertions gruen; Workflow-UI: 10 Tests/99 Assertions; Blade-Cache und `bash -n` gruen; Git-/Hugging-Face-Pins und SHA-256-Werte live verifiziert. Commit `53742f3` liegt auf `origin/main`; die neue Voice-UI ist produktiv sichtbar. Live-Runtime-Status: deaktiviert; ffmpeg, Whisper CLI/Modell und Piper CLI/Modell/Config fehlen. SSH-Port 22 ist erreichbar, aber die uebergebene Plesk-Sitzung steht auch nach drei Pruefungen unangemeldet auf `login_up.php`. | In Plesk anmelden oder den Plesk-Subscription-/SSH-Benutzer bereitstellen. Danach `bash scripts/bootstrap-local-assistant-voice.sh` ausfuehren und produktiven Status, Mikrofontranskription sowie WAV-Ausgabe testen. |

| 2026-07-15 | Claude | verifiziert | UI/UX-Optimierung Workflow-Manager-Board, rein Frontend ohne Logikaenderung: (1) Der "+ Task am Listenende"-Button pro Liste erscheint erst beim Hover ueber der Liste (Tastatur-Fokus und Touch-Geraete ohne Hover sehen ihn weiterhin). (2) Klick-Einfuegen statt Drag-Pflicht: Der Button merkt sich die Ziel-Liste, oeffnet die Task-Bibliothek, und ein Klick auf einen Katalogeintrag ruft das bestehende `prepareTaskFromCatalog` mit Position null (echtes Listenende) auf; Drag and Drop bleibt unveraendert. Ein `$wire.$watch('showTaskPanel')` raeumt das Einfuegeziel bei jedem Panel-Schliessen ab (auch beim serverseitigen Schliessen im Drag-Pfad), Escape/Abbrechen/X ebenso. Bereich: `step-card.blade.php` und `workflow-manager.blade.php` (nur Alpine/Markup) plus Tailwind-Rebuild (`npm run build`, neue Varianten `group/step`, `[@media(hover:none)]`). | Adversariale 3-Linsen-Review (Alpine/Livewire, UX/A11y, Regression) mit 19 Findings; alle Major-Findings behoben (Stale-Target-Leak, irrefuehrender Hinweistext, Touch-Sichtbarkeit), Minors: Space-Taste, role=status, Roundtrip-Guard. Blade-Kompilierung gruen; UI-/Markup-/Kompositionstests 21/21 gruen; neue CSS-Klassen im Build verifiziert. Restrisiko: Escape disarmt auch beim Schliessen von Dropdowns (bewusst akzeptiert); kein manueller Browser-Test in diesem Stand. | Manuellen Browser-Smoke-Test des Klick-Einfuegens durchfuehren. |

| 2026-07-15 | Claude | verifiziert | OpenRouter-/AI-Connection-Tab optimiert: Testfunktion je Modell-Profil direkt auf der Seite (Textausgabe: Kurzantwort; Datenanalyse: JSON-Mode; Bildverstehen: Bild-Upload, Modell beschreibt das Bild; Bilderstellung: Testbild wird erzeugt und angezeigt; Speech-to-Text: Audio-Upload, Modell transkribiert). Tests laufen mit dem aktuell eingetragenen Modell gegen die gespeicherte API-Verbindung, Ergebnis-Panel zeigt Erfolg/Fehler, Dauer, Text bzw. Bild. KEIN Test fuer Text-to-Speech; die Felder Audioausgabe-API-URL, Stimme und Audioformat wurden aus der UI entfernt — gespeicherte Werte bleiben erhalten (saveOpenRouter merged mit Bestand, AssistantAudioOutputStreamController liest sie weiter), Hinweis auf die Sprachverarbeitung im AI-Chatbot-Tab ergaenzt. Zusatz: AiConnectionService::speechToText (toter Code, input_audio.url) auf schema-konformes input_audio {data, format} fuer data-URLs umgestellt. Neue Komponente `x-settings.openrouter-test-result`. Bereich: SettingsPage.php, settings-page.blade.php (nur OpenRouter-Tab), AiConnectionService.php (nur speechToText), tests/Feature/OpenRouterConnectionSettingsTest.php. Codex' Sprachverarbeitungs-Sektion unberuehrt. | Neuer Feature-Test mit gemocktem AiConnectionService: 10 Tests/39 Assertions gruen (Persistenz-Erhalt der Audio-Werte, Modell-Override, Fehlerpfad, Upload-Pflicht, data-URL-Weitergabe, Markup ohne Audio-Felder). Regressionslauf WorkflowCopilot+Settings gesamt: 89 Tests/619 Assertions gruen; Blade-Kompilierung gruen. Vorbestehende 9 Errors in AssistantSpeechProviderTest (fehlender app.key, auch auf sauberem Stand) lokal ueber APP_KEY in .env.testing behoben. Restrisiko: kein Live-Test gegen die echte OpenRouter-API; speechToText-Payload gegen echtes Audio-Modell noch unverifiziert. | Live-Test der fuenf Test-Buttons im Browser mit echtem API-Key durchfuehren. |

| 2026-07-15 | Codex | in_arbeit | Produktionsaktivierung der serverlokalen Sprache fortgesetzt. Der angemeldete Plesk-Admin-Tab in Chrome ist ueber das Browser-Plugin erreichbar. Der separate SSH-Terminal-Link endet extern auf dem nicht erreichbaren Port 8880; das Laravel Toolkit und sein Artisan-Reiter funktionieren dagegen. Daher entsteht statt einer zusaetzlichen Web-Adminseite der kontrollierte Befehl `assistant:voice:install`: entkoppelter Worker, Dateisperre gegen Parallellauefe, feste Ausfuehrung des gepinnten `scripts/bootstrap-local-assistant-voice.sh`, PID-/JSON-Status und begrenzte Logausgabe ueber `--status`. Ausfuehrung erfolgt ohne Root und ohne neuen Netzwerk-Port als Domain-Benutzer. | Plesk-Adminoberflaeche und Domain `factory.follow-flow.de` authentifiziert geprueft; Produktionsbefehl `assistant:voice:status` laeuft mit `/opt/plesk/php/8.3/bin/php` und bestaetigt weiterhin: deaktiviert, alle sechs Runtime-Komponenten fehlen. Terminal-Port 8880 liefert `ERR_CONNECTION_TIMED_OUT`; Artisan-Ausgabe ist funktionsfaehig. Neuer Installer: 9 Tests/35 Assertions gruen; kompletter Voice-/Provider-/Installer-Regressionslauf: 25 Tests/154 Assertions gruen; Pint und PHP-Syntaxpruefung gruen. Die Vorpruefung deckt PHP-CLI, Bash/nohup, ffmpeg, Python 3.9+ mit venv, CMake, Compiler und die benoetigten Coreutils ab. Fuer eventuell fehlende Ubuntu-Pakete ist der serverweite Plesk-Weg `Tools & Settings > Scheduled Tasks > Add Task` verifiziert; dort steht `Run a command` mit Systembenutzer `root` bereit. Es wurde kein Root-Task angelegt oder ausgefuehrt. | Plesk-Deploy des aktuellen `main` (Installer ab Commit `94c49d9`) nach Aktionsbestaetigung starten. Danach `assistant:voice:install --status` produktiv ausfuehren, gegebenenfalls fehlende Systempakete ueber den verifizierten Root-Task-Weg installieren und erst dann den Voice-Worker starten; abschliessend Whisper/Piper sowie echte Ein-/Ausgabe pruefen. |

| 2026-07-15 | Codex | in_arbeit | Produktions-`main` bis Commit `c3f597d` ueber Plesk deployt und den Voice-Installer real gestartet. Die Root-Vorpruefung ergab `cmake`, `ffmpeg` und C++-Compiler als fehlend; diese wurden einmalig ueber Plesk Scheduled Tasks mit `apt-get install -y cmake ffmpeg build-essential` installiert. Whisper 1.9.1 wurde danach vollstaendig gebaut und das verifizierte Small-Modell geladen. Fuer Piper wurde zusaetzlich das vom Lauf konkret verlangte `python3.12-venv` installiert. Der Wiederanlauf zeigte eine Idempotenzluecke: Das zuvor abgebrochene Piper-Venv blieb ohne `pip` liegen. Beanspruchter Fixbereich: `LocalAssistantVoiceInstaller.php`, `bootstrap-local-assistant-voice.sh` und zugehoeriger Unit-Test; keine Copilot-/Workflow-Dateien. | Beide Root-Aufgaben liefen erfolgreich und wurden nur ueber `Run Now` ausgefuehrt, nicht als persistente Tasks gespeichert. Produktionslog und JSON-Status wurden zwischen den Laeufen geprueft. Der lokale Fix verlangt im Preflight nun `ensurepip` und ersetzt ein unvollstaendiges Piper-Venv vor der Wiederaufnahme. Voice-/Provider-/Installer-Regressionslauf mit SQLite in-memory: 27 Tests/158 Assertions gruen; Git-for-Windows `bash -n`, Pint fuer beide PHP-Dateien und `git diff --check` gruen. Der erste breite Testaufruf traf erwartungsgemaess auf die lokale, nicht konfigurierte MySQL-Datenbank `forge` und wurde gemaess README mit SQLite wiederholt. | Fix committen, pushen und ueber Plesk deployen; Installer erneut starten und bis zur echten STT-/TTS-Verifikation ueberwachen. |

Neue Eintraege immer unten anhaengen. Ein Eintrag gilt erst als `verifiziert`,
wenn die ausgefuehrten Testkommandos und verbleibende Risiken genannt sind.

### Relevante Tests

```bash
php artisan test tests/Unit/ChatbotViewMarkupTest.php
php artisan test tests/Unit/WorkflowCopilotUiMarkupTest.php
php artisan test tests/Feature/WorkflowCopilotLiveUiTest.php
php artisan test tests/Feature/WorkflowCopilotToolServiceTest.php
php artisan test tests/Feature/WorkflowCopilotLogExportServiceTest.php
```

Falls die lokale Standard-Datenbank auf eine nicht vorhandene MySQL-Datenbank
zeigt, die Tests mit `DB_CONNECTION=sqlite` und `DB_DATABASE=:memory:` starten.

### Bekannte offene Verifikation

- Der automatisierte Test deckt Planung, Persistenz, UI-Zustand und ZIP-Inhalt
  ab. Ein echter Browserlauf mit sichtbaren Browserfenstern und externer AI wurde
  in diesem Arbeitsstand nicht automatisch gestartet.
- Die Qualitaet der Erstdefinition haengt vom konfigurierten Datenmodell und von
  der Genauigkeit der Zielbeschreibung ab. Ungueltige oder unbekannte Task-Keys
  werden verworfen; ohne mindestens einen gueltigen Task startet keine Sitzung.
- Die Copilot-Live-Optimierung bleibt absichtlich auf `execution_target=system`.
