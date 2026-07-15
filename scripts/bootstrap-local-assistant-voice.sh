#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 027

readonly WHISPER_VERSION="1.9.1"
readonly WHISPER_COMMIT="f049fff95a089aa9969deb009cdd4892b3e74916"
readonly WHISPER_SOURCE_SHA256="279af4ce60dbf397362868f3bacc75b56a4332ac2541cae155070093f6aaf0e3"
readonly WHISPER_MODEL_REVISION="5359861c739e955e79d9a303bcbc70fb988958b1"
readonly WHISPER_MODEL_SHA256="1be3a9b2063867b937e64e2ec7483364a79917e157fa98c5d94b5c1fffea987b"

readonly PIPER_VERSION="1.4.2"
readonly PIPER_VOICE="de_DE-thorsten-medium"
readonly PIPER_VOICE_REVISION="e21c7de8d4eab79b902f0d61e662b3f21664b8d2"
readonly PIPER_MODEL_SHA256="7e64762d8e5118bb578f2eea6207e1a35a8e0c30595010b666f983fc87bb7819"
readonly PIPER_CONFIG_SHA256="974adee790533adb273a1ac88f49027d2a1b8f0f2cf4905954a4791e79264e85"

readonly WHISPER_SOURCE_URL="https://codeload.github.com/ggml-org/whisper.cpp/tar.gz/${WHISPER_COMMIT}"
readonly WHISPER_MODEL_URL="https://huggingface.co/ggerganov/whisper.cpp/resolve/${WHISPER_MODEL_REVISION}/ggml-small.bin?download=true"
readonly PIPER_MODEL_URL="https://huggingface.co/rhasspy/piper-voices/resolve/${PIPER_VOICE_REVISION}/de/de_DE/thorsten/medium/${PIPER_VOICE}.onnx?download=true"
readonly PIPER_CONFIG_URL="https://huggingface.co/rhasspy/piper-voices/resolve/${PIPER_VOICE_REVISION}/de/de_DE/thorsten/medium/${PIPER_VOICE}.onnx.json?download=true"

log() {
    printf '[local-voice] %s\n' "$*"
}

warn() {
    printf '[local-voice] WARNUNG: %s\n' "$*" >&2
}

die() {
    printf '[local-voice] FEHLER: %s\n' "$*" >&2
    exit 1
}

resolve_executable() {
    local requested="$1"
    local label="$2"
    local resolved

    if ! resolved="$(command -v "$requested" 2>/dev/null)"; then
        die "${label} wurde nicht gefunden (${requested})."
    fi

    [[ -x "$resolved" ]] || die "${label} ist nicht ausfuehrbar: ${resolved}"

    if [[ "$resolved" != /* ]]; then
        resolved="$(cd "$(dirname "$resolved")" && pwd -P)/$(basename "$resolved")"
    fi

    printf '%s\n' "$resolved"
}

sha256_of() {
    sha256sum "$1" | awk '{print $1}'
}

checksum_matches() {
    local path="$1"
    local expected="$2"

    [[ "$(sha256_of "$path")" == "$expected" ]]
}

CURRENT_DOWNLOAD=""
CURRENT_EXTRACT_DIR=""
CURRENT_SMOKE_FILE=""

cleanup() {
    local status=$?

    if [[ -n "${CURRENT_DOWNLOAD:-}" && -f "$CURRENT_DOWNLOAD" ]]; then
        rm -f -- "$CURRENT_DOWNLOAD"
    fi

    if [[ -n "${CURRENT_EXTRACT_DIR:-}" && -n "${RUNTIME_DIR:-}" && -d "$CURRENT_EXTRACT_DIR" ]]; then
        case "$CURRENT_EXTRACT_DIR" in
            "$RUNTIME_DIR"/.whisper-source.*)
                rm -rf -- "$CURRENT_EXTRACT_DIR"
                ;;
        esac
    fi

    if [[ -n "${CURRENT_SMOKE_FILE:-}" && -f "$CURRENT_SMOKE_FILE" ]]; then
        rm -f -- "$CURRENT_SMOKE_FILE"
    fi

    return "$status"
}

trap cleanup EXIT

download_verified() {
    local url="$1"
    local destination="$2"
    local expected_sha256="$3"
    local label="$4"
    local actual_sha256

    if [[ -L "$destination" ]]; then
        die "${label}: Symlinks sind als Download-Ziel nicht erlaubt: ${destination}"
    fi

    if [[ -e "$destination" && ! -f "$destination" ]]; then
        die "${label}: Download-Ziel ist keine regulaere Datei: ${destination}"
    fi

    if [[ -f "$destination" ]] && checksum_matches "$destination" "$expected_sha256"; then
        log "${label} ist bereits vorhanden und verifiziert."
        return
    fi

    if [[ -f "$destination" ]]; then
        actual_sha256="$(sha256_of "$destination")"
        warn "${label} hat eine unerwartete SHA-256 (${actual_sha256}) und wird atomar ersetzt."
    else
        log "Lade ${label} herunter."
    fi

    CURRENT_DOWNLOAD="$(mktemp "${destination}.download.XXXXXX")"
    curl \
        --fail \
        --location \
        --retry 3 \
        --retry-delay 2 \
        --connect-timeout 20 \
        --proto '=https' \
        --proto-redir '=https' \
        --show-error \
        --output "$CURRENT_DOWNLOAD" \
        "$url"

    actual_sha256="$(sha256_of "$CURRENT_DOWNLOAD")"
    if [[ "$actual_sha256" != "$expected_sha256" ]]; then
        die "${label}: SHA-256 stimmt nicht (erwartet ${expected_sha256}, erhalten ${actual_sha256})."
    fi

    chmod 0640 "$CURRENT_DOWNLOAD"
    mv -f -- "$CURRENT_DOWNLOAD" "$destination"
    CURRENT_DOWNLOAD=""
}

install_whisper_source() {
    local extracted_dir
    local installed_commit

    download_verified \
        "$WHISPER_SOURCE_URL" \
        "$WHISPER_SOURCE_ARCHIVE" \
        "$WHISPER_SOURCE_SHA256" \
        "whisper.cpp ${WHISPER_VERSION} Quellarchiv"

    if [[ -L "$WHISPER_SOURCE_DIR" ]]; then
        die "Das whisper.cpp-Quellverzeichnis darf kein Symlink sein: ${WHISPER_SOURCE_DIR}"
    fi

    if [[ -e "$WHISPER_SOURCE_DIR" && ! -d "$WHISPER_SOURCE_DIR" ]]; then
        die "Der whisper.cpp-Quellpfad ist kein Verzeichnis: ${WHISPER_SOURCE_DIR}"
    fi

    if [[ -d "$WHISPER_SOURCE_DIR" ]]; then
        [[ -f "$WHISPER_SOURCE_DIR/CMakeLists.txt" ]] || \
            die "Unvollstaendige whisper.cpp-Installation: ${WHISPER_SOURCE_DIR}"
        [[ -f "$WHISPER_SOURCE_DIR/.source-commit" ]] || \
            die "Commit-Marker fehlt in ${WHISPER_SOURCE_DIR}."

        installed_commit="$(<"$WHISPER_SOURCE_DIR/.source-commit")"
        [[ "$installed_commit" == "$WHISPER_COMMIT" ]] || \
            die "Falscher whisper.cpp-Commit in ${WHISPER_SOURCE_DIR}: ${installed_commit}"

        log "whisper.cpp ${WHISPER_VERSION} Quellcode ist bereits installiert."
        return
    fi

    CURRENT_EXTRACT_DIR="$(mktemp -d "$RUNTIME_DIR/.whisper-source.XXXXXX")"
    tar -xzf "$WHISPER_SOURCE_ARCHIVE" -C "$CURRENT_EXTRACT_DIR"
    extracted_dir="$CURRENT_EXTRACT_DIR/whisper.cpp-${WHISPER_COMMIT}"

    [[ -f "$extracted_dir/CMakeLists.txt" ]] || \
        die "Das verifizierte whisper.cpp-Archiv hat nicht die erwartete Struktur."

    printf '%s\n' "$WHISPER_COMMIT" > "$extracted_dir/.source-commit"
    chmod 0640 "$extracted_dir/.source-commit"
    mv -- "$extracted_dir" "$WHISPER_SOURCE_DIR"
    rmdir -- "$CURRENT_EXTRACT_DIR"
    CURRENT_EXTRACT_DIR=""
}

build_whisper() {
    local version_output

    log "Konfiguriere whisper.cpp ${WHISPER_VERSION}."
    cmake \
        -S "$WHISPER_SOURCE_DIR" \
        -B "$WHISPER_BUILD_DIR" \
        -DCMAKE_BUILD_TYPE=Release \
        -DWHISPER_BUILD_TESTS=OFF \
        -DWHISPER_BUILD_EXAMPLES=ON

    log "Baue ausschliesslich whisper-cli mit ${BUILD_JOBS} parallelen Job(s)."
    cmake \
        --build "$WHISPER_BUILD_DIR" \
        --config Release \
        --target whisper-cli \
        --parallel "$BUILD_JOBS"

    [[ -x "$WHISPER_BINARY" ]] || die "whisper-cli wurde nicht erzeugt: ${WHISPER_BINARY}"
    version_output="$("$WHISPER_BINARY" --version)"
    [[ "$version_output" == *"${WHISPER_VERSION}"* ]] || \
        die "whisper-cli meldet nicht die erwartete Version ${WHISPER_VERSION}: ${version_output}"
}

install_piper() {
    local installed_version

    if [[ -L "$PIPER_VENV" ]]; then
        die "Das Piper-Venv darf kein Symlink sein: ${PIPER_VENV}"
    fi

    if [[ -e "$PIPER_VENV" && ! -d "$PIPER_VENV" ]]; then
        die "Der Piper-Venv-Pfad ist kein Verzeichnis: ${PIPER_VENV}"
    fi

    if [[ ! -x "$PIPER_PYTHON" ]]; then
        log "Erzeuge Python-Venv fuer Piper ${PIPER_VERSION}."
        "$SYSTEM_PYTHON" -m venv "$PIPER_VENV"
    fi

    "$PIPER_PYTHON" -m pip --version >/dev/null 2>&1 || \
        die "pip fehlt im Piper-Venv. Ist das Paket python3-venv installiert?"

    installed_version="$(
        "$PIPER_PYTHON" -c \
            'from importlib.metadata import PackageNotFoundError, version
try:
    print(version("piper-tts"))
except PackageNotFoundError:
    pass' 2>/dev/null
    )"

    if [[ "$installed_version" != "$PIPER_VERSION" ]]; then
        log "Installiere piper-tts==${PIPER_VERSION} aus PyPI."
        "$PIPER_PYTHON" -m pip install \
            --disable-pip-version-check \
            --no-input \
            --only-binary=:all: \
            --index-url https://pypi.org/simple \
            --upgrade \
            "piper-tts==${PIPER_VERSION}"
    else
        log "piper-tts==${PIPER_VERSION} ist bereits im Venv installiert."
    fi

    installed_version="$("$PIPER_PYTHON" -c 'from importlib.metadata import version; print(version("piper-tts"))')"
    [[ "$installed_version" == "$PIPER_VERSION" ]] || \
        die "Unerwartete Piper-Version im Venv: ${installed_version}"
    [[ -x "$PIPER_BINARY" ]] || die "Piper-CLI fehlt: ${PIPER_BINARY}"
    "$PIPER_BINARY" --help >/dev/null
}

smoke_test_piper() {
    log "Pruefe Piper und die Stimme ${PIPER_VOICE} per CLI."
    CURRENT_SMOKE_FILE="$(mktemp "$RUNTIME_DIR/.piper-smoke.XXXXXX.wav")"
    printf '%s\n' 'Lokaler Sprachtest.' | \
        "$PIPER_BINARY" \
            --model "$PIPER_MODEL" \
            --config "$PIPER_CONFIG" \
            --output_file "$CURRENT_SMOKE_FILE" \
            >/dev/null

    [[ -s "$CURRENT_SMOKE_FILE" ]] || die "Piper hat keine Test-WAV-Datei erzeugt."
    rm -f -- "$CURRENT_SMOKE_FILE"
    CURRENT_SMOKE_FILE=""
}

upsert_laravel_env() {
    "$SYSTEM_PYTHON" - "$ENV_FILE" \
        "LOCAL_ASSISTANT_VOICE_ENABLED=true" \
        "LOCAL_ASSISTANT_VOICE_FFMPEG_BINARY=${FFMPEG_PATH}" \
        "LOCAL_ASSISTANT_WHISPER_BINARY=${WHISPER_BINARY}" \
        "LOCAL_ASSISTANT_WHISPER_MODEL=${WHISPER_MODEL}" \
        "LOCAL_ASSISTANT_WHISPER_LANGUAGE=de" \
        "LOCAL_ASSISTANT_PIPER_BINARY=${PIPER_BINARY}" \
        "LOCAL_ASSISTANT_PIPER_MODEL=${PIPER_MODEL}" \
        "LOCAL_ASSISTANT_PIPER_CONFIG=${PIPER_CONFIG}" \
        "LOCAL_ASSISTANT_PIPER_MODE=cli" <<'PY'
import os
import re
import stat
import sys
import tempfile
from pathlib import Path

env_path = Path(sys.argv[1])
updates = {}

for assignment in sys.argv[2:]:
    key, value = assignment.split("=", 1)
    if not re.fullmatch(r"[A-Z][A-Z0-9_]*", key):
        raise SystemExit(f"Ungueltiger .env-Schluessel: {key}")
    if "\n" in value or "\r" in value:
        raise SystemExit(f"Zeilenumbruch im .env-Wert fuer {key}")
    updates[key] = value


def dotenv_value(value: str) -> str:
    if re.fullmatch(r"[A-Za-z0-9_./:+-]+", value):
        return value

    escaped = value.replace("\\", "\\\\").replace('"', '\\"').replace("$", "\\$")
    return f'"{escaped}"'


raw = env_path.read_bytes()
try:
    content = raw.decode("utf-8")
except UnicodeDecodeError as exc:
    raise SystemExit(f"{env_path} ist nicht UTF-8: {exc}") from exc

newline = "\r\n" if "\r\n" in content else "\n"
active_assignment = re.compile(
    r"^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*="
)
seen = set()
result = []

for line in content.splitlines():
    match = active_assignment.match(line)
    key = match.group(1) if match else None

    if key not in updates:
        result.append(line)
        continue

    if key not in seen:
        result.append(f"{key}={dotenv_value(updates[key])}")
        seen.add(key)

for key, value in updates.items():
    if key not in seen:
        result.append(f"{key}={dotenv_value(value)}")

rendered = newline.join(result) + newline
original_stat = env_path.stat()
fd, temp_name = tempfile.mkstemp(
    prefix=f".{env_path.name}.local-voice.",
    dir=env_path.parent,
)

try:
    os.fchmod(fd, stat.S_IMODE(original_stat.st_mode))
    with os.fdopen(fd, "w", encoding="utf-8", newline="") as handle:
        handle.write(rendered)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temp_name, env_path)
except BaseException:
    try:
        os.close(fd)
    except OSError:
        pass
    try:
        os.unlink(temp_name)
    except FileNotFoundError:
        pass
    raise
PY
}

[[ $# -eq 0 ]] || die "Das Script akzeptiert keine Positionsargumente. Nutze die dokumentierten Umgebungsvariablen."
[[ "$(uname -s)" == "Linux" ]] || die "Dieses Bootstrap-Script ist ausschliesslich fuer Linux vorgesehen."

case "$(uname -m)" in
    x86_64|aarch64|arm64)
        ;;
    *)
        die "Nicht unterstuetzte Architektur fuer die Piper-Wheels: $(uname -m)"
        ;;
esac

for required_command in awk cmake curl dirname basename mkdir mktemp mv rm rmdir sha256sum tar uname; do
    command -v "$required_command" >/dev/null 2>&1 || \
        die "Erforderlicher Befehl fehlt: ${required_command}"
done

if ! command -v c++ >/dev/null 2>&1 && \
    ! command -v g++ >/dev/null 2>&1 && \
    ! command -v clang++ >/dev/null 2>&1; then
    die "Kein C++-Compiler gefunden. Installiere die Build-Werkzeuge der Distribution."
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
APP_ROOT="$(cd "$SCRIPT_DIR/.." && pwd -P)"
ENV_FILE="$APP_ROOT/.env"

[[ -f "$APP_ROOT/artisan" ]] || die "Laravel-Artisan fehlt unter ${APP_ROOT}/artisan."
[[ -f "$ENV_FILE" ]] || die "Laravel-.env fehlt: ${ENV_FILE}"
[[ ! -L "$ENV_FILE" ]] || die "Die .env darf fuer den atomaren Upsert kein Symlink sein: ${ENV_FILE}"
[[ -w "$ENV_FILE" ]] || die "Die .env ist nicht schreibbar: ${ENV_FILE}"
[[ -w "$(dirname "$ENV_FILE")" ]] || die "Das App-Verzeichnis ist fuer den atomaren .env-Upsert nicht schreibbar."

runtime_input="${LOCAL_ASSISTANT_VOICE_RUNTIME_DIR:-$APP_ROOT/storage/app/voice-runtime}"
if [[ "$runtime_input" != /* ]]; then
    runtime_input="$APP_ROOT/$runtime_input"
fi
[[ "$runtime_input" != *$'\n'* && "$runtime_input" != *$'\r'* ]] || \
    die "Der Runtime-Pfad darf keinen Zeilenumbruch enthalten."
[[ "$runtime_input" != "/" ]] || die "Das Root-Dateisystem darf nicht als Runtime-Verzeichnis verwendet werden."

mkdir -p "$runtime_input"
RUNTIME_DIR="$(cd "$runtime_input" && pwd -P)"
[[ -w "$RUNTIME_DIR" ]] || die "Das Runtime-Verzeichnis ist nicht schreibbar: ${RUNTIME_DIR}"

PHP_PATH="$(resolve_executable "${PHP_BINARY:-php}" "PHP CLI")"
SYSTEM_PYTHON="$(resolve_executable "${PYTHON_BINARY:-python3}" "Python 3")"
FFMPEG_PATH="$(resolve_executable "${FFMPEG_BINARY:-ffmpeg}" "ffmpeg")"

"$PHP_PATH" -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' || \
    die "PHP 8.1 oder neuer ist erforderlich: $("$PHP_PATH" -r 'echo PHP_VERSION;')"
"$SYSTEM_PYTHON" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 9) else 1)' || \
    die "Python 3.9 oder neuer ist fuer piper-tts==${PIPER_VERSION} erforderlich."
"$SYSTEM_PYTHON" -c 'import venv' >/dev/null 2>&1 || \
    die "Das Python-venv-Modul fehlt. Installiere das Distributionspaket python3-venv."
"$FFMPEG_PATH" -version >/dev/null 2>&1 || die "ffmpeg kann nicht ausgefuehrt werden: ${FFMPEG_PATH}"

BUILD_JOBS="${BUILD_JOBS:-}"
if [[ -z "$BUILD_JOBS" ]]; then
    if command -v nproc >/dev/null 2>&1; then
        BUILD_JOBS="$(nproc)"
    else
        BUILD_JOBS="2"
    fi
fi
[[ "$BUILD_JOBS" =~ ^[1-9][0-9]*$ ]] || die "BUILD_JOBS muss eine positive Ganzzahl sein."

DOWNLOAD_DIR="$RUNTIME_DIR/downloads"
WHISPER_SOURCE_DIR="$RUNTIME_DIR/whisper.cpp-${WHISPER_VERSION}"
WHISPER_SOURCE_ARCHIVE="$DOWNLOAD_DIR/whisper.cpp-${WHISPER_VERSION}-${WHISPER_COMMIT}.tar.gz"
WHISPER_BUILD_DIR="$WHISPER_SOURCE_DIR/build"
WHISPER_BINARY="$WHISPER_BUILD_DIR/bin/whisper-cli"
WHISPER_MODEL_DIR="$RUNTIME_DIR/models/whisper"
WHISPER_MODEL="$WHISPER_MODEL_DIR/ggml-small.bin"

PIPER_VENV="$RUNTIME_DIR/piper-${PIPER_VERSION}"
PIPER_PYTHON="$PIPER_VENV/bin/python"
PIPER_BINARY="$PIPER_VENV/bin/piper"
PIPER_MODEL_DIR="$RUNTIME_DIR/models/piper/${PIPER_VOICE}"
PIPER_MODEL="$PIPER_MODEL_DIR/${PIPER_VOICE}.onnx"
PIPER_CONFIG="$PIPER_MODEL_DIR/${PIPER_VOICE}.onnx.json"

mkdir -p "$DOWNLOAD_DIR" "$WHISPER_MODEL_DIR" "$PIPER_MODEL_DIR"

if ! (cd "$APP_ROOT" && "$PHP_PATH" artisan help assistant:voice:status >/dev/null 2>&1); then
    die "Der erwartete Artisan-Befehl assistant:voice:status fehlt. Deploye zuerst den zugehoerigen Laravel-Code."
fi

log "Runtime-Verzeichnis: ${RUNTIME_DIR}"
log "Es wird kein Server gestartet und kein Netzwerk-Port geoeffnet."

install_whisper_source
build_whisper
download_verified \
    "$WHISPER_MODEL_URL" \
    "$WHISPER_MODEL" \
    "$WHISPER_MODEL_SHA256" \
    "Whisper-Modell small"

install_piper
download_verified \
    "$PIPER_MODEL_URL" \
    "$PIPER_MODEL" \
    "$PIPER_MODEL_SHA256" \
    "Piper-Stimmmodell ${PIPER_VOICE}"
download_verified \
    "$PIPER_CONFIG_URL" \
    "$PIPER_CONFIG" \
    "$PIPER_CONFIG_SHA256" \
    "Piper-Stimmkonfiguration ${PIPER_VOICE}"
smoke_test_piper

upsert_laravel_env
log "Die lokalen Sprachpfade wurden idempotent in ${ENV_FILE} eingetragen."

(
    cd "$APP_ROOT"
    "$PHP_PATH" artisan config:clear
    "$PHP_PATH" artisan assistant:voice:status --activate
)

log "Serverlokale Sprachverarbeitung wurde installiert und aktiviert."
log "whisper-cli: ${WHISPER_BINARY}"
log "Whisper-Modell: ${WHISPER_MODEL}"
log "Piper-CLI: ${PIPER_BINARY}"
log "Piper-Modell: ${PIPER_MODEL}"
