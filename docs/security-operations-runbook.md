# Security- und Operations-Runbook

Dieses Runbook beschreibt den sicheren Betrieb der Node-Anmeldung, der privaten
Cookie-Sitzungen und der AI-/Workflow-Metriken. Befehle werden im Laravel-
Projektverzeichnis ausgefuehrt. Ausgaben und Logs enthalten nur Metadaten; Tokens,
Cookie-Inhalte, Prompts, Antworten und Provider-Bodies duerfen dort nie erscheinen.

## Ueberwachung und Alarmierung

Der Scheduler muss laufen (`php artisan schedule:run` pro Minute oder
`php artisan schedule:work`). Er aktualisiert Heartbeats, prueft die Metriken alle
fuenf Minuten und loescht abgelaufene Cookie-Sitzungen taeglich.

Manuelle Checks:

```bash
php artisan operations:health --json --fail-on-alert
php artisan operations:alert --json --fail-on-critical
```

`operations:alert` schreibt deduplizierte Warnungen und kritische Alarme in den
konfigurierten Laravel-Logkanal. In Produktion muss dieser Kanal an das vorhandene
Monitoring/Paging angebunden werden. Die Grenzwerte und der Cooldown stehen als
`OPERATIONS_*`-Variablen in `.env.example`. Bei mehreren App-Instanzen muss ein
gemeinsamer Cache (beispielsweise Redis) verwendet werden, damit Heartbeats und
Alarm-Cooldowns nicht nur pro Host gelten.

Alarmiert werden unter anderem:

- fehlende/veraltete Scheduler-, Worker-, Artifact- und Cookie-Prune-Heartbeats;
- alte Client-Jobs und zu hohe Queue-/Realtime-Latenz;
- niedrige Erfolgsquoten insgesamt, je Tasktyp und je Portal-Domain;
- erreichte Copilot-/AI-Tagesbudgets oder beinahe ausgeschoepfte AI-Budgets;
- gebuendelte fehlgeschlagene Node-Enrollments sowie ueberfaellige Cookie-Payloads.

Bei Enrollment-Alarmen zuerst Quell-IP-Fingerprints und Node-UUID-Anzahl im
maschinenlesbaren Health-Report pruefen. Niemals einen Bootstrap- oder Node-Key
anfordern oder in ein Ticket kopieren. Danach Rate-Limit-/Reverse-Proxy-Logs und
die autorisierte Token-Ausgabe im Adminbereich abgleichen. Verdächtige Tokens
widerrufen und betroffene Node-Credentials rotieren.

## Cookie-Aufbewahrung und Dateipfade

Cookie-Dateien sind ausschliesslich unter `storage/app` oder explizit in
`SECURITY_COOKIE_ALLOWED_ROOTS` erlaubten privaten Roots zulaessig. `public/`,
`storage/app/public`, Traversal, unbekannte absolute Pfade und symbolische Links
werden abgewiesen. Auf dem Zielsystem muss der OS-Benutzer des Web-/Queue-Prozesses
alleinigen Zugriff erhalten (Verzeichnisse 0700, Dateien 0600, auf Windows
entsprechend restriktive NTFS-ACLs).

Die Standardfrist ist `SECURITY_COOKIE_RETENTION_DAYS=30`. Vor einer manuellen
Loeschung:

```bash
php artisan security:prune-cookie-sessions --dry-run
php artisan security:prune-cookie-sessions
php artisan operations:health --json
```

Ein Fehlercode wegen eines unsicheren historischen Pfads bedeutet: Die
verschluesselte DB-Kopie wurde fristgerecht entfernt, die externe Datei aber
absichtlich nicht automatisch angefasst. Den kanonischen Pfad separat pruefen,
die Datei nach Freigabe manuell loeschen und anschliessend den Person-Datensatz
auf einen privaten erlaubten Pfad korrigieren. Backups und Exporte brauchen eine
eigene, dokumentierte Loeschfrist.

## Kontrollierte APP_KEY-Rotation fuer Cookie-Payloads

1. Verschluesseltes Datenbank-Backup erstellen und Wiederherstellung testen.
2. Wartungsfenster beginnen; Web-, Scheduler- und Queue-Schreibzugriffe stoppen.
3. Einen neuen starken Laravel-Key erzeugen. Den bisherigen Key als ersten Wert
   in `APP_PREVIOUS_KEYS` hinterlegen und den neuen Wert als `APP_KEY` setzen.
4. Konfigurationscache leeren/neu erstellen und alle PHP-/Queue-Prozesse neu
   starten. Alte Keys niemals loggen oder in Befehlsargumente kopieren.
5. Entschluesselbarkeit pruefen und danach neu verschluesseln:

   ```bash
   php artisan security:rotate-cookie-encryption --dry-run
   php artisan security:rotate-cookie-encryption
   php artisan operations:health --json
   ```

6. Stichproben ueber die Anwendung und den Rotation-Heartbeat pruefen, dann die
   Schreibzugriffe wieder freigeben.

Der Befehl rotiert nur `persons.cookie_payload`. `APP_PREVIOUS_KEYS` darf erst
entfernt werden, wenn auch alle anderen mit `APP_KEY` verschluesselten Felder und
signierten Daten inventarisiert/rotiert wurden und kein aufbewahrungspflichtiges
Backup mehr den alten Key benoetigt. Dazu zaehlen insbesondere Node-Signing-
Secrets, Push-Abonnements, Login-/Workflow-Geheimnisse und historische Exporte.
Eine vollstaendige globale APP_KEY-Rotation bleibt deshalb ein separates,
deployment-spezifisches Change-Verfahren.

## Deployment-Abnahme

Nach jedem produktiven Rollout mindestens pruefen:

- Migrationen, Cache-Neuaufbau, Scheduler und Queue-Worker sind erfolgreich;
- `operations:health --json --fail-on-alert` ist erklaerbar und das externe
  Monitoring empfaengt einen kontrollierten Testalarm;
- ein echter Node kann sich mit einem einmaligen, kurzlebigen Token nur per
  Header anmelden; Body-Secrets, Wiederverwendung und abgelaufene Tokens scheitern;
- eine reale Cookie-Sitzung liegt in privatem Storage, wird vom Runtime-Prozess
  gelesen und nach der Frist entfernt;
- AI-Budgetgrenzen und Request-IDs sind in der Produktionsdatenbank sichtbar,
  ohne Prompt-, Antwort- oder Provider-Body-Inhalte zu speichern;
- Portal-/Browser-E2E, Reverb-Signalweg und ClientController-Paket werden auf dem
  Zielsystem mit echten Diensten und restriktiven Dateirechten getestet.
