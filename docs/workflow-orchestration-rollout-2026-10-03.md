# Workflow-Orchestrierung: Betrieb und Rollout

Stand: 2026-10-03. Dies ist eine vorbereitete Betriebsanleitung, kein erfolgtes Deployment.

## Verifizierter Ausgangspunkt

Eine rein lesende Produktionspruefung bestaetigte einen Plesk-PHP-8.3-Prozess `php artisan queue:work` fuer `factory.follow-flow.de`, mit `database/default`. Ein zweiter, eigenstaendiger Workflow-Worker war nicht vorhanden. Dieser einzelne Defaultworker konsumiert die neuen Queues **nicht**.

Die Queue-Trennung wirkt nur mit getrennten Prozessen. Ein Worker mit `--queue=workflow-control,workflow-ai` priorisiert zwar Control, blockiert es aber weiterhin waehrend eines laufenden AI-Jobs.

| Pool | Queue-Verbindung | Queue | Job-Timeout | Reservierung |
| --- | --- | --- | --- | --- |
| Steuerung | `database-workflow-control` | `workflow-control` | bis 120 s | mindestens 150 s |
| KI und Bilder | `database-workflow-ai` | `workflow-ai` | bis 1800 s | mindestens 1860 s |
| Allgemein und Altjobs | `database` | `default` | bis 1800 s fuer Altjobs | mindestens 1860 s |

Alle Verbindungen verwenden dieselbe bestehende Jobs-Tabelle. Die unterschiedlichen Reservierungsfristen verhindern, dass ein noch legal laufender Bildjob von einem anderen Worker erneut reserviert wird. `RunWorkflowJob`, Monitor, Expire und Reconcile laufen im Steuerungspool; Supervisor, Persona-Planung und Bildgenerierung im KI-Pool. Allgemeine Aufgaben behalten `default`.

Das Beispiel `deployment/supervisor-followflow-queue.conf.example` beschreibt die kleine Ausgangstopologie mit drei Prozessen, je einem pro Pool. Es ist nicht automatisch installiert. Benutzername, PHP-Pfad, Speicherlimits und Serverkapazitaet vor Verwendung pruefen. Mehr KI-Worker erst nach Messung von RAM und Providerlimits; mehr Worker sind keine Freigabe fuer doppelte externe Aktionen.

## Kontrollierter Rollout

1. Wartungsfenster und Rueckweg festlegen; neue Starts kurz anhalten. Aktive externe Aufgaben und reservierte Altjobs erfassen. Jobs nicht pauschal loeschen oder erneut ausfuehren.
2. Getesteten Code, `composer.lock`, `package-lock.json` und echten Vite-Build gemeinsam bereitstellen. `composer install`/`npm ci` statt unkontrolliertem Produktions-Update verwenden; Build/Node-Runtime mit dem Projektminimum Node 22.12 oder hoeher. Die additive Migration `2026_10_03_210000_create_workflow_run_leases_table` vor Starts mit dem neuen Code ausfuehren.
3. Ein einziges Prozessmanagement festlegen: Plesk **oder** Supervisor. Einen gerade arbeitenden alten Worker kontrolliert auslaufen lassen; Plesk-Worker nicht gleichzeitig als zweiten Defaultpool neben Supervisor weiterbetreiben. Insbesondere lange Bildjobs nicht mitten in einem externen Effekt abbrechen.
4. Konfiguration aktualisieren (`config:cache`, sofern bisher verwendet), Worker geordnet neu starten und alle drei Pools aktivieren. `queue:restart` beendet Worker erst nach dem aktuellen Job; die Prozessverwaltung muss sie wieder starten. Keine neuen Workflow-Starts, solange die zwei neuen Pools fehlen.
5. Defaultpool zum Abarbeiten alter serialisierter `database/default`-Payloads erhalten. Bestehende verzögerte oder reservierte Jobs nicht umschreiben; Queue-Recovery erkennt Alt- und Neujobs.
6. Scheduler weiter im Minutentakt betreiben. `php artisan operations:health --strict` und `/betrieb` pruefen: drei frische Pool-Heartbeats, keine abgelaufenen Reservierungen, keine wachsende Control-Warteschlange. Control-Backlog wird ab 30 s kritisch. Ein legaler 1800-s-KI-Job darf keinen falschen KI-Heartbeat-Ausfallalarm erzeugen.
7. Mit synthetischem, seiteneffektfreiem Testworkflow pruefen: mehrere unterschiedliche Runs; langsame KI-Auswertung parallel; Monitorfortschritt ohne KI-Blockade; wiederholter Callback ohne Doppelstart; Pause/Stopp waehrend Browserstart und KI; Checkpoint erstellen und mit bestaetigter Aktion zuruecksetzen/verzweigen. Echte Buchungen, E-Mails oder Registrierungen nicht als Lasttest wiederholen.
8. Queue-Wartezeiten, Start-/Checkpoint-Latenz, DB-Lockwartezeit, Fehlerquote und Speicherverbrauch beobachten. Erst bei unauffaelligem Verlauf normale Starts freigeben.

## Wiederherstellung und Rueckweg

Run-Leases sind zeitlich begrenzt und tokengebunden; ein alter Worker darf die Lease eines neueren Workers nicht freigeben. Das ersetzt keinen Nachweis, ob eine externe Aktion bereits ausgefuehrt wurde. Bei unklarem Zustand am sicheren Checkpoint pausieren und Run-/Taskbelege pruefen.

Bei Rollback zuerst neue Starts anhalten und Worker geordnet auslaufen lassen. Neue Queuepayloads behalten ihre Namen, auch nach einem Code-Rollback; deshalb die neuen Pools/Verbindungen zum sicheren Drain erhalten oder eine ausdruecklich gepruefte Rueckmigration vorbereiten. Keine `queue:clear`-/`queue:flush`-Abkuerzung. Die additive Lease-Tabelle kann fuer einen Code-Rollback bestehen bleiben; sie erst entfernen, wenn kein ausfuehrender Prozess sie mehr verwendet.

## Grenzen dieser lokalen Abnahme

Lokale PHP-/Node-Tests und ein Build sind kein Produktions-Lasttest und keine Garantie fuer fremde Portale oder Provider. MySQL-8-Verifikation erfolgt zusaetzlich ueber die CI; eine lokale MariaDB-Abnahme darf nicht als MySQL-8-Abnahme bezeichnet werden. Kontrolllauf, Replay-Schutz, manuelle Freigaben, Katalogbindung und sensible Exportredaktion bleiben verbindlich.

Grosse Klassen werden schrittweise entlang bereits getesteter Verantwortlichkeiten entkoppelt. Die neue Run-Koordination, Kontextpersistenz und Entscheidungsvalidierung sind eigene kleine Bausteine. Eine vollstaendige Aufteilung der Runtime oder Migration aller Verlaufsdaten ist nicht Voraussetzung fuer diesen Rollout und bleibt ein eigener, messungsgetriebener AP14/AP15-Schritt.

PHP- und npm-Produktionsabhaengigkeiten sind nach kompatiblen Patchupdates ohne Auditbefund. Das vollstaendige npm-Audit bleibt wegen des ungepatchten DEV-`braces`-Advisory [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) rot. Dies ist kein freigegebener Release-Gate-Bypass. Kein erzwungener inkompatibler Paketwechsel und keine Deaktivierung des CI-Audits; verbleibenden Buildkettenbefund vor Release nach bestehender Security-Policy behandeln.

Referenzen: [Laravel Queues](https://laravel.com/framework/docs/12.x/queues), [Laravel Pessimistic Locking](https://laravel.com/framework/docs/12.x/queries#pessimistic-locking), bestehender Session-Plan `docs/workflow-session-audit-und-umsetzungsplan-2026-09-25.md`.
