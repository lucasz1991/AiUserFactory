# Workflow-Prozess-Hygiene – Betrieb auf dem Linux-Plesk-Server

Dieses Dokument beschreibt die Betriebs-Voraussetzungen und Stellschrauben, die
verhindern, dass Workflow-Tests (Node + Chromium) den Server durch akkumulierende
Prozesse, RAM-Erschöpfung (OOM) oder einen erschöpften PHP-FPM-Pool lahmlegen.

Die zugehörigen Code-Fixes (Selbst-Aufräumung geparkter Browser, TTL-Reaper,
Prozess-Cap, entkoppelte Reaper, Poll-Drosselung) sind bereits umgesetzt. Die
folgenden Punkte müssen **auf dem Server** konfiguriert werden.

## 1. Scheduler-Cron ist Pflicht (kritisch)

Die Prozess-Hygiene (Sync, Supervise/Reaper, Expire, Reconcile) läuft jetzt
**synchron im Scheduler-Prozess** (`app/Console/Kernel.php`), damit sie auch ohne
laufenden Queue-Worker greift. Voraussetzung: Der Laravel-Scheduler muss jede
Minute laufen. In Plesk unter *Geplante Aufgaben* (oder crontab des Abo-Users):

```
* * * * * cd /var/www/vhosts/<domain>/httpdocs/AiUserFactory && php artisan schedule:run >> /dev/null 2>&1
```

Ohne diesen Cron werden gestallte Läufe nie final, geparkte Browser nie
aufgeräumt und das tägliche Pruning nie ausgeführt.

## 2. Queue-Worker als überwachter Daemon

Auch wenn die Reaper jetzt im Scheduler laufen, brauchen die eigentlichen
Workflow-/Copilot-Jobs einen Worker. Auf dem Server einen dauerhaften
`queue:work` als systemd-Service mit `Restart=always` einrichten:

```ini
# /etc/systemd/system/aiuserfactory-queue.service
[Unit]
Description=AiUserFactory Queue Worker
After=network.target mariadb.service

[Service]
User=<plesk-abo-user>
WorkingDirectory=/var/www/vhosts/<domain>/httpdocs/AiUserFactory
ExecStart=/usr/bin/php artisan queue:work --sleep=1 --tries=1 --max-time=3600 --max-jobs=500
Restart=always
RestartSec=5
MemoryMax=1G

[Install]
WantedBy=multi-user.target
```

`--max-time`/`--max-jobs` sorgen dafür, dass der Worker regelmäßig neu startet und
keinen Speicher leakt. Der Copilot-Supervisor ist speicherhungrig und
selbst-redispatchend – bei Bedarf eine zweite Worker-Unit für eine eigene
`copilot`-Queue betreiben, damit er Monitor/Sync/Expire nicht aushungert.

## 3. Neue Einstellungen (settings-Tabelle)

Alle optional; die Defaults sind sicher gewählt.

| Setting | Default | Wirkung |
| --- | --- | --- |
| `browser_keep_alive_max_idle_seconds` | `900` (15 min) | Nach dieser Leerlaufzeit schließt ein geparkter Browser sich selbst und der Node-Prozess endet. `0` deaktiviert (nicht empfohlen). Wird auf 60–3600 s begrenzt. |
| `max_concurrent_workflow_runs` | `5` | Harte Obergrenze gleichzeitig aktiver Läufe. Ein neuer Lauf wird oberhalb abgewiesen. `0` deaktiviert den Cap. |
| `headless_enabled` | auf Linux `true`, sonst `false` | Chromium headless. Auf Servern ohne X-Display Pflicht. Explizit gesetzter Wert hat Vorrang. |
| `chromium_no_sandbox` | `false` | Reicht `--no-sandbox` an Chromium durch. Auf gemanagten Hosts ohne User-Namespaces oft nötig, damit Chromium überhaupt startet. Sicherheits-Tradeoff – nur setzen, wenn der Launch sonst scheitert. |

## 4. PHP-FPM-Pool der Domain

Ein offener Studio-Tab pollt jetzt nur noch bei aktivem Lauf/Copilot eng (2 s),
sonst träge (15 s). Trotzdem:

- `pm.max_children` der Domain nicht zu klein lassen (Plesk-Default 5 ist knapp).
  Erst **nach** der RAM-Entlastung durch die Prozess-Fixes erhöhen (z. B. 10–15),
  sonst verschärft man den OOM.
- `request_terminate_timeout = 120s` setzen, damit ein hängender Request einen
  Worker nicht dauerhaft blockiert.

## 5. RAM-Schutz gegen OOM-Killer

Damit ein Chromium-Ausreißer nie wieder MySQL oder PHP-FPM mitreißt:

- Workflow-Node-Prozesse in eine systemd-Slice mit `MemoryMax` (z. B. 60 % RAM)
  starten, **oder**
- `mysqld` per `OOMScoreAdjust=-800` (systemd-Override) vor dem OOM-Killer
  schützen, damit im Ernstfall die Chromium-Prozesse getroffen werden, nicht die
  Datenbank.

Ein Chromium-Kill mitten im Lauf führt dank der neuen Crash-Handler zu einem
sauberen `failed`-Status statt zu einem eingefrorenen `running`-Phantom.

## 6. Chromium-Flags

`--disable-dev-shm-usage` wird bereits immer gesetzt (verhindert Chromium-Hänger
bei kleinem `/dev/shm`). Bei Startproblemen zusätzlich `chromium_no_sandbox`
aktivieren (siehe oben).

## 7. Notfall: Server hängt trotzdem

Sofortmaßnahme, ohne Neustart des ganzen Servers:

```bash
# Alle Workflow-Node-Prozesse und ihre Chromium-Kinder beenden
pkill -f 'node.*run_step.cjs'
pkill -f 'browser-profiles/workflows'
# Danach den Reaper einmal manuell anstoßen
php artisan schedule:run
```

Das tägliche Pruning kann jederzeit manuell laufen:

```bash
php artisan workflow:prune-artifacts --dry-run   # nur anzeigen
php artisan workflow:prune-artifacts             # tatsächlich aufräumen
```
