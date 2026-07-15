

Installation

Voraussetzungen

PHP 8.x

Composer

Node.js & npm

MySQL oder eine kompatible Datenbank

Laravel 10

Livewire 3

Setup

Repository klonen

git clone https://github.com/dein-repository/minifinds-admin.git
cd minifinds-admin

Abhängigkeiten installieren

composer install
npm install && npm run build

Umgebungsvariablen konfigurieren

cp .env.example .env
php artisan key:generate

Passe die .env-Datei an (Datenbankverbindung, API-Keys etc.).

Datenbank migrieren & seeden

php artisan migrate --seed

Lokalen Server starten

php artisan serve

Deployment

Für das Deployment auf einem Live-Server:

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

Stelle sicher, dass der Server Supervisor oder einen ähnlichen Prozessmanager für Queues nutzt.

Admin-Zugang

Nach der Installation existiert ein Standard-Admin-Konto:

E-Mail: admin@minifinds.de

Passwort: password

Ändere das Passwort nach dem ersten Login!

Befehle & Cronjobs

Wichtige Artisan-Befehle:

Queues verarbeiten:

php artisan queue:work

Production deployment for ClientController workflows:

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

© 2025 MiniFinds GbR | Entwickelt von LMZ Media
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

### Arbeitsprotokoll

Statuswerte: `geplant`, `in_arbeit`, `verifiziert`, `blockiert`.

| Datum | Agent | Status | Aenderung | Verifikation | Naechster Schritt |
| --- | --- | --- | --- | --- | --- |
| 2026-07-15 | Codex | verifiziert | Gemeinsame Vorschau, autonome Erstplanung, Checkpoint-Pruefpausen, Chat-Autoscroll und kompletter Audit-ZIP-Export. | PHP-Syntaxpruefung; gezielte Unit-/Featuretests mit SQLite in-memory. | Visuellen End-to-End-Test mit real konfigurierter AI-Verbindung und einer echten Browserseite durchfuehren. |

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
