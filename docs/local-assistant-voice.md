# Serverlokale Sprachverarbeitung

## Zweck und Sicherheitsmodell

Der Workflow-Assistant verarbeitet Spracheingaben und Sprachausgaben direkt auf
dem Laravel-/Plesk-Server:

- Speech-to-Text: `whisper.cpp` v1.9.1 mit `whisper-cli` und dem mehrsprachigen
  Modell `small`
- Text-to-Speech: `piper-tts==1.4.2` in einem eigenen Python-Venv mit der Stimme
  `de_DE-thorsten-medium`
- Audio-Normalisierung: das vom Linux-System bereitgestellte `ffmpeg`

Alle Laufzeitaufrufe sind lokale CLI-Prozesse. Das Bootstrap-Script baut weder
`whisper-server` noch startet es Piper im HTTP-Modus. Es richtet keinen
systemd-/Supervisor-Dienst ein, aendert keine Firewall und oeffnet keinen
Netzwerk-Port. Nur waehrend der Installation werden ausgehende HTTPS-Verbindungen
zu GitHub, Hugging Face und PyPI benoetigt.

## Gepinnte Artefakte

| Komponente | Pin |
| --- | --- |
| whisper.cpp | Tag `v1.9.1`, Commit `f049fff95a089aa9969deb009cdd4892b3e74916` |
| Whisper-Modell | `ggml-small.bin`, Hugging-Face-Revision `5359861c739e955e79d9a303bcbc70fb988958b1` |
| Piper | PyPI-Paket `piper-tts==1.4.2` |
| Piper-Stimme | `de_DE-thorsten-medium`, Hugging-Face-Revision `e21c7de8d4eab79b902f0d61e662b3f21664b8d2` |

Das Script prueft Quellarchiv, Whisper-Modell, Piper-Modell und
Stimmkonfiguration gegen fest hinterlegte SHA-256-Werte. Bereits vorhandene,
gueltige Downloads werden wiederverwendet. Eine Datei mit abweichender Pruefsumme
wird erst nach vollstaendigem und erfolgreichem Ersatz-Download atomar ersetzt.

`ffmpeg` bleibt absichtlich ein vom Betriebssystem gepflegtes Paket. Sein Pfad
wird in `.env` festgehalten, seine Distributionsversion aber nicht durch das
App-Script veraendert.

## Zielstruktur

Standardmaessig liegt die gesamte app-lokale Runtime unter:

```text
storage/app/voice-runtime/
|-- downloads/
|-- whisper.cpp-1.9.1/
|   `-- build/bin/whisper-cli
|-- piper-1.4.2/
|   `-- bin/piper
`-- models/
    |-- whisper/ggml-small.bin
    `-- piper/de_DE-thorsten-medium/
        |-- de_DE-thorsten-medium.onnx
        `-- de_DE-thorsten-medium.onnx.json
```

Neue Versionen verwenden eigene versionsbezogene Verzeichnisse. Der Bootstrap
loescht keine aeltere Runtime.

## Voraussetzungen

Der Bootstrap muss als Linux-Benutzer der Plesk-Subscription beziehungsweise als
Benutzer ausgefuehrt werden, dem die Laravel-Dateien gehoeren. Nicht als
Webserver-Root und nicht als separater Benutzer mit abweichenden Dateirechten
ausfuehren.

Erforderlich sind:

- Linux auf `x86_64` oder `aarch64`/`arm64`
- eine bestehende, schreibbare Laravel-`.env`
- PHP CLI 8.1 oder neuer, passend zur Plesk-Domain
- Python 3.9 oder neuer inklusive `venv`
- CMake, C++-Compiler, `curl`, `tar`, `sha256sum` und Standard-Coreutils
- `ffmpeg`
- mindestens etwa 1,5 GB freier Speicher fuer Downloads, Modelle, Venv und Build
- ausgehendes HTTPS zu `codeload.github.com`, `huggingface.co`, deren
  Download-CDNs sowie `pypi.org`
- der deployte Artisan-Befehl `assistant:voice:status`

Auf Debian/Ubuntu sind die Systempakete typischerweise:

```bash
sudo apt-get update
sudo apt-get install --yes \
  build-essential cmake curl ca-certificates ffmpeg python3 python3-venv
```

Auf AlmaLinux/Rocky Linux muessen die entsprechenden Pakete ueber `dnf`
installiert werden. Das Bootstrap-Script installiert oder aktualisiert keine
Systempakete und benoetigt selbst keine Root-Rechte.

## Installation unter Plesk

1. Laravel-Code einschliesslich `assistant:voice:status` deployen und die
   normale `.env` bereitstellen.
2. In Plesk den PHP-CLI-Pfad der Domain pruefen, zum Beispiel
   `/opt/plesk/php/8.3/bin/php`.
3. Als Subscription-Benutzer aus dem App-Verzeichnis ausfuehren:

```bash
cd /var/www/vhosts/follow-flow.de/factory.follow-flow.de

PHP_BINARY=/opt/plesk/php/8.3/bin/php \
  bash scripts/bootstrap-local-assistant-voice.sh
```

Das Script darf aus einem beliebigen Arbeitsverzeichnis aufgerufen werden; den
Laravel-Root ermittelt es relativ zu seinem eigenen Pfad. Es akzeptiert keine
Positionsargumente.

Optionale, nur fuer den Bootstrap geltende Umgebungsvariablen:

| Variable | Bedeutung | Standard |
| --- | --- | --- |
| `PHP_BINARY` | PHP-CLI der Plesk-Domain | erstes `php` in `PATH` |
| `PYTHON_BINARY` | Python fuer das Piper-Venv | erstes `python3` in `PATH` |
| `FFMPEG_BINARY` | zu verwendendes ffmpeg | erstes `ffmpeg` in `PATH` |
| `BUILD_JOBS` | parallele CMake-Build-Jobs | Ergebnis von `nproc`, sonst `2` |
| `LOCAL_ASSISTANT_VOICE_RUNTIME_DIR` | abweichender Runtime-Pfad; relative Pfade beziehen sich auf den Laravel-Root | `storage/app/voice-runtime` |

Beispiel mit begrenzter CPU-Parallelitaet:

```bash
PHP_BINARY=/opt/plesk/php/8.3/bin/php \
BUILD_JOBS=2 \
bash scripts/bootstrap-local-assistant-voice.sh
```

## Ablauf und Idempotenz

Das Script fuehrt in dieser Reihenfolge aus:

1. Betriebssystem, Architektur, PHP, Python/Venv, ffmpeg, Build-Werkzeuge,
   Schreibrechte und den Artisan-Befehl pruefen.
2. Das gepinnte whisper.cpp-Quellarchiv laden, pruefen und `whisper-cli` im
   Release-Modus bauen. CMake darf bei jedem Lauf erneut konfigurieren; ein
   unveraenderter Build kompiliert nichts Unnoetiges neu.
3. Das Whisper-Modell `small` laden und per SHA-256 pruefen.
4. Das versionsbezogene Piper-Venv erzeugen und exakt
   `piper-tts==1.4.2` aus PyPI installieren.
5. Stimme und JSON-Konfiguration laden, pruefen und eine kurze WAV-Datei per
   Piper-CLI erzeugen. Die Testdatei wird wieder entfernt.
6. Die neun Voice-Schluessel atomar in `.env` eintragen oder aktualisieren.
   Mehrfache aktive Vorkommen eines Schluessels werden dabei auf einen Eintrag
   reduziert; auskommentierte Beispielzeilen bleiben erhalten.
7. `php artisan config:clear` und danach
   `php artisan assistant:voice:status --activate` ausfuehren.

Ein Fehler beendet den Lauf mit einem Exit-Code ungleich null. Die `.env` wird
erst nach erfolgreichen Installations- und Piper-Pruefungen veraendert. Ein
erneuter Lauf verwendet alle Artefakte mit korrekter Pruefsumme wieder.

## Laravel-Konfiguration

Nach einem erfolgreichen Lauf enthaelt `.env` diese Werte mit absoluten
Linux-Pfaden:

```dotenv
LOCAL_ASSISTANT_VOICE_ENABLED=true
LOCAL_ASSISTANT_VOICE_FFMPEG_BINARY=/usr/bin/ffmpeg
LOCAL_ASSISTANT_WHISPER_BINARY=/var/www/vhosts/example/app/storage/app/voice-runtime/whisper.cpp-1.9.1/build/bin/whisper-cli
LOCAL_ASSISTANT_WHISPER_MODEL=/var/www/vhosts/example/app/storage/app/voice-runtime/models/whisper/ggml-small.bin
LOCAL_ASSISTANT_WHISPER_LANGUAGE=de
LOCAL_ASSISTANT_PIPER_BINARY=/var/www/vhosts/example/app/storage/app/voice-runtime/piper-1.4.2/bin/piper
LOCAL_ASSISTANT_PIPER_MODEL=/var/www/vhosts/example/app/storage/app/voice-runtime/models/piper/de_DE-thorsten-medium/de_DE-thorsten-medium.onnx
LOCAL_ASSISTANT_PIPER_CONFIG=/var/www/vhosts/example/app/storage/app/voice-runtime/models/piper/de_DE-thorsten-medium/de_DE-thorsten-medium.onnx.json
LOCAL_ASSISTANT_PIPER_MODE=cli
```

Das Script beendet Laravel bewusst mit geleertem Konfigurationscache. Wenn der
regulaere Produktions-Deploy anschliessend einen Konfigurationscache verlangt,
erst nach erfolgreichem Statuslauf ausfuehren:

```bash
PHP_BINARY=/opt/plesk/php/8.3/bin/php
"$PHP_BINARY" artisan config:cache
```

Webprozess und Queue-Worker muessen dieselbe `.env` lesen und auf die Runtime
zugreifen koennen. Nach Aenderungen an `.env` beziehungsweise am
Konfigurationscache langlebige Queue-Worker im normalen Deployment-Prozess neu
starten.

## Betriebspruefung

Gesamtstatus ohne erneute Aktivierung:

```bash
php artisan assistant:voice:status
```

Installierte Versionen einzeln pruefen:

```bash
storage/app/voice-runtime/whisper.cpp-1.9.1/build/bin/whisper-cli --version

storage/app/voice-runtime/piper-1.4.2/bin/python -c \
  'from importlib.metadata import version; print(version("piper-tts"))'

ffmpeg -version | head -n 1
```

Piper-CLI ohne Netzwerk testen:

```bash
RUNTIME="$PWD/storage/app/voice-runtime"

printf '%s\n' 'Dies ist ein lokaler Sprachtest.' | \
  "$RUNTIME/piper-1.4.2/bin/piper" \
    --model "$RUNTIME/models/piper/de_DE-thorsten-medium/de_DE-thorsten-medium.onnx" \
    --config "$RUNTIME/models/piper/de_DE-thorsten-medium/de_DE-thorsten-medium.onnx.json" \
    --output_file "$RUNTIME/piper-test.wav"
```

Whisper-CLI mit einer vorhandenen Audiodatei testen:

```bash
RUNTIME="$PWD/storage/app/voice-runtime"

ffmpeg -y -i /pfad/zur/eingabe.wav \
  -ar 16000 -ac 1 -c:a pcm_s16le "$RUNTIME/whisper-test.wav"

"$RUNTIME/whisper.cpp-1.9.1/build/bin/whisper-cli" \
  --model "$RUNTIME/models/whisper/ggml-small.bin" \
  --language de \
  --file "$RUNTIME/whisper-test.wav"
```

Die beiden manuell erzeugten Test-WAV-Dateien nach der Pruefung entfernen. Die
Tests benoetigen keine ein- oder ausgehende Netzwerkverbindung.

## Rechte und Betrieb

- `.env` und Runtime nicht in den Webroot verschieben. `storage/app` bleibt der
  vorgesehene private Ablageort.
- Runtime-Verzeichnisse werden mit der Script-`umask 027` angelegt. Der
  Plesk-/Queue-Benutzer braucht Lese- und Ausfuehrungsrechte auf Binaries, Venv
  und Modelle.
- Keine Modelle in Git aufnehmen. Sie sind gross und werden anhand der Pins neu
  bereitgestellt.
- Sprachjobs verbrauchen CPU und RAM im PHP-/Queue-Kontext. Parallelitaet und
  Job-Timeouts anhand realer Audiodauer beobachten.
- Das CLI-Modell laedt Piper beziehungsweise Whisper pro Prozessaufruf. Es wird
  absichtlich kein dauerhafter HTTP-Dienst als Performance-Abkuerzung gestartet.

## Deaktivierung und Rollback

Fuer eine sofortige fachliche Deaktivierung:

```dotenv
LOCAL_ASSISTANT_VOICE_ENABLED=false
```

Danach ausfuehren:

```bash
php artisan config:clear
```

Die Runtime zunaechst liegen lassen. So ist die Aktivierung ohne erneuten
Download reversibel. Erst wenn Webprozess, Queue-Worker und geplante Jobs die
Sprachpfade nicht mehr verwenden, kann ein Administrator das konkrete
`storage/app/voice-runtime`-Verzeichnis kontrolliert archivieren oder entfernen.

## Fehlerdiagnose

| Fehler | Pruefung und Massnahme |
| --- | --- |
| `assistant:voice:status` fehlt | Laravel-Code mit dem vorgesehenen Artisan-Befehl zuerst deployen. Das Script bricht vor Downloads ab. |
| `python3 -m venv` scheitert | `python3-venv` fuer exakt die eingesetzte Python-Version installieren. |
| Piper findet kein Binary-Wheel | Architektur, Python-Version und glibc-basierte Distribution pruefen; die offiziellen 1.4.2-Wheels sind fuer Linux x86_64 und ARM64 vorgesehen. |
| CMake findet keinen Compiler | `build-essential` beziehungsweise die C/C++-Development-Tools der Distribution installieren. |
| SHA-256 stimmt nicht | Download nicht manuell freigeben. Proxy/CDN und hinterlegte Revision pruefen; erst einen bewusst aktualisierten Pin samt neuer Pruefsumme deployen. |
| Artisan meldet fehlende Ausfuehrungsrechte | Eigentum und Gruppenrechte der Subscription, des Venvs, des CMake-Builds und aller uebergeordneten Verzeichnisse pruefen. |
| Web funktioniert, Queue nicht | Sicherstellen, dass Queue-Worker dieselbe App, `.env`, PHP-Version und denselben Unix-Benutzer beziehungsweise eine berechtigte Gruppe verwenden; Worker neu starten. |
| Prozesse versuchen einen HTTP-Sprachdienst | `LOCAL_ASSISTANT_PIPER_MODE=cli` und den aktuellen Konfigurationscache pruefen. Fuer diesen Betrieb ist keine Voice-URL und kein Voice-Port vorgesehen. |

Bei einem Versionswechsel muessen Versionsnummer, Quell- beziehungsweise
Modellrevisionen und SHA-256-Werte gemeinsam im Bootstrap-Script aktualisiert und
zuerst in einer Staging-Umgebung getestet werden. Niemals nur einen `main`-Link
auf Produktion umstellen.
