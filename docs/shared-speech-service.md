# Gemeinsamer Speech-Dienst fuer Followflow und RailTime

## Ziel und Sicherheitsgrenze

Followflow kann seine bestehenden, authentifizierten Browser-Endpunkte fuer
Spracheingabe und Sprachausgabe an denselben serverlokalen Speech-Daemon wie
RailTime weiterleiten. Der Browser spricht weiterhin nur mit Laravel:

1. Der angemeldete Admin sendet Audio oder Text an den bestehenden
   Followflow-Endpunkt.
2. Laravel validiert und begrenzt die Anfrage.
3. Nur der PHP-Server sendet `Authorization: Bearer ...` und `X-Client-ID` an
   den Daemon auf Loopback.
4. Der Daemon verarbeitet STT/TTS und antwortet an Laravel.

Das Zugriffstoken wird niemals an Blade, Livewire, Alpine oder JavaScript
uebergeben. Fuer den Daemon darf kein oeffentlicher Apache-/nginx-Proxy
eingerichtet werden. Die Client-Validierung akzeptiert nur `http`/`https` mit
`localhost`, `::1` oder einer Adresse aus `127.0.0.0/8` und folgt keinen
Weiterleitungen.
Ausgehende Proxy-Einstellungen werden fuer diesen Loopback-Kanal ausdruecklich
deaktiviert, damit das Bearer-Token auch bei global gesetzten Proxy-Variablen
nicht den Server verlassen kann.

## HTTP-Vertrag

Alle `/v1/*`-Aufrufe senden `Authorization: Bearer <Token>` und
`X-Client-ID: followflow`.

| Methode | Pfad | Nutzlast / Antwort |
| --- | --- | --- |
| `GET` | `/v1/status` | Authentifizierter Bereitschaftsstatus ohne Geheimnisse. |
| `POST` | `/v1/transcriptions` | JSON mit `audio_base64`, `filename`, `mime_type`, `language`; Antwort mit `text` und `request_id`. |
| `POST` | `/v1/speech` | JSON mit `text` und `speed`; Antwort muss eine gueltige `audio/wav`-Datei sein. |

Followflow akzeptiert hoechstens 8 MiB Upload, hoechstens 4.000 Zeichen fuer
TTS und Geschwindigkeiten von 0,5 bis 2,0. Die Web-Routen haben getrennte
Benutzer-, Stunden- und globale Limits: STT 4/min und 30/h je Benutzer sowie
12/min global; TTS 12/min und 180/h je Benutzer sowie 40/min global.

## Konfiguration

Produktiv sollte fuer jeden Client ein eigener Token verwendet und ueber eine
nur fuer PHP lesbare Datei bereitgestellt werden:

```dotenv
SPEECH_SERVICE_ENABLED=true
SPEECH_SERVICE_URL=http://127.0.0.1:8092
SPEECH_SERVICE_CLIENT_ID=followflow
SPEECH_SERVICE_TOKEN=
SPEECH_SERVICE_TOKEN_FILE=/var/www/vhosts/<followflow-webspace>/.lmz-secrets/speech-service.token
SPEECH_SERVICE_CONNECT_TIMEOUT=5
SPEECH_SERVICE_STT_TIMEOUT=300
SPEECH_SERVICE_TTS_TIMEOUT=180
```

`SPEECH_SERVICE_TOKEN` ist als kontrollierter Fallback vorhanden. In Produktion
ist `SPEECH_SERVICE_TOKEN_FILE` vorzuziehen. Die Datei muss fuer den PHP-FPM-
Benutzer lesbar sein, darf aber nicht im Document Root, Log oder Repository
liegen. Sie liegt als Modus `600` im privaten `.lmz-secrets`-Verzeichnis
innerhalb des eigenen Plesk-`WEBSPACEROOT`; dadurch bleibt sie mit dem
Plesk-Standard-`open_basedir` erreichbar. RailTime und Followflow muessen
verschiedene Plesk-Systembenutzer/Unix-UIDs verwenden. Der zentrale
Daemon-Rollout erzeugt beide Tokens getrennt und prueft in beide Richtungen,
dass der jeweils andere PHP-Benutzer die Datei nicht lesen kann.

Nach einer Aenderung:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan assistant:speech-service:status
php artisan assistant:speech-service:status --smoke
```

Der normale Statusbefehl zeigt nur Bereitschaftsfelder. `--smoke` erzeugt per
TTS eine WAV-Datei und sendet sie unmittelbar fuer einen echten STT-Rundtest
zurueck. Token, Modellpfade und Antwort-Rohdaten werden nicht ausgegeben.

## Aktivierung und fail-closed Verhalten

Den Daemon und seine per-Client-Authentifizierung zuerst bereitstellen und
testen. Erst danach `SPEECH_SERVICE_ENABLED=true` in Followflow setzen. In
diesem Modus haben die Einstellungen `whisper_local`, `piper_local`, Vosk,
eSpeak und OpenRouter fuer die beiden Audio-Endpunkte keine Fallback-Wirkung:
Ist der gemeinsame Dienst nicht erreichbar oder lehnt er eine Anfrage ab,
antwortet Followflow mit HTTP 503 und einer Korrelations-ID. Das verhindert,
dass eine bewusst zentralisierte Verarbeitung unbemerkt wieder lokale oder
externe Anbieter verwendet.

Der gemeinsame Daemon wird als einzelnes Supervisor-Programm betrieben und
nur an `127.0.0.1` gebunden. Seine Installation, Token-Hashes, Engine-Limits und
Logrotation gehoeren zur Daemon-Deployment-Dokumentation; Followflow enthaelt
nur den abgesicherten Client.

## Expliziter Rollback

Die bisherige Followflow-eigene ffmpeg-/Whisper-/Piper-CLI bleibt unveraendert
verfuegbar. Ein Rollback ist bewusst und sichtbar:

```dotenv
SPEECH_SERVICE_ENABLED=false
LOCAL_ASSISTANT_VOICE_ENABLED=true
```

Danach in den Assistant-Einstellungen `whisper_local` und `piper_local`
auswaehlen oder den bestehenden Bereitschafts-/Aktivierungsbefehl verwenden:

```bash
php artisan assistant:voice:status --activate
```

Nach jeder Umschaltung den Konfigurationscache erneuern und den jeweiligen
Status- beziehungsweise Smoke-Test ausfuehren.

## Credential-Hygiene und Rotation

Die versionierte `.env.example` enthielt zuvor umgebungsspezifisch wirkende,
nichtleere Werte. Sie verwendet jetzt nur noch sichere Defaults und leere
Credential-Platzhalter. Falls produktive Werte daraus uebernommen oder mit ihr
identisch waren, muessen mindestens `DB_PASSWORD`,
`WEBAIDETECTIVE_BASE_API_PASSWORD`, `WEBAIDETECTIVE_BASE_APP_KEY`,
`REDIS_PASSWORD` und `MAIL_PASSWORD` kontrolliert rotiert werden. `APP_KEY`
darf wegen verschluesselter Daten und Sessions nur mit geplantem
Re-Encryption-/Invalidierungsverfahren ersetzt werden.

Das Bereinigen der Git-Historie ist absichtlich nicht Teil dieses Changes: Es
waere ein koordinationspflichtiger History-Rewrite. Bis eine solche Massnahme
ausdruecklich beschlossen und auf alle Checkouts ausgerollt ist, sind
betroffene produktive Zugangsdaten als potenziell offengelegt zu behandeln.
