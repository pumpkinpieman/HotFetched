<?php
declare(strict_types=1);

/**
 * Standalone Klipper conversion-kit generator.
 *
 * This module intentionally does not write project records, modify imported
 * sources, run avrdude, or touch attached hardware. It emits a reviewed set of
 * shell scripts and configuration seeds that the user executes on the Klipper
 * host after making a complete recovery backup.
 */

function hf_conversion_profiles(): array
{
    return [
        'prusa_mk3_einsy' => [
            'id' => 'prusa_mk3_einsy',
            'label' => 'Prusa MK3 / MK3S / MK3S+ — Einsy RAMBo',
            'board' => 'Einsy RAMBo 1.x',
            'main_mcu' => 'ATmega2560',
            'bridge_mcu' => 'ATmega32U2',
            'artifact' => 'klipper.elf.hex',
            'models' => [
                'mk3' => 'Original Prusa i3 MK3',
                'mk3s' => 'Original Prusa i3 MK3S',
                'mk3s_plus' => 'Original Prusa i3 MK3S+',
            ],
            'connections' => [
                'usb' => 'USB through the Einsy ATmega32U2 bridge (UART0 firmware)',
                'uart1' => 'Internal Raspberry Pi serial connection (UART1 firmware)',
            ],
            'programmers' => [
                'usbasp' => 'USBasp / compatible ISP programmer',
                'arduino_isp' => 'Arduino running ArduinoISP',
                'rpi_gpio' => 'Raspberry Pi GPIO using a configured pi_1 programmer',
            ],
        ],
    ];
}

function hf_conversion_valid_ref(string $ref): bool
{
    return $ref !== ''
        && strlen($ref) <= 80
        && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,79}\z/', $ref) === 1
        && !str_contains($ref, '..')
        && !str_contains($ref, '//')
        && !str_starts_with($ref, '/')
        && !str_ends_with($ref, '/')
        && !str_ends_with($ref, '.')
        && !str_ends_with(strtolower($ref), '.lock');
}

/** @return array{0: array<string,mixed>, 1: array<string,string>} */
function hf_conversion_validate(array $input): array
{
    $profiles = hf_conversion_profiles();
    $profileId = is_string($input['profile_id'] ?? null) ? $input['profile_id'] : '';
    $profile = $profiles[$profileId] ?? null;
    $errors = [];

    if ($profile === null) {
        $errors['profile_id'] = 'Unsupported conversion profile';
        return [[], $errors];
    }

    $model = is_string($input['printer_model'] ?? null) ? $input['printer_model'] : '';
    $connection = is_string($input['connection'] ?? null) ? $input['connection'] : '';
    $programmer = is_string($input['programmer'] ?? null) ? $input['programmer'] : '';
    $bridgeAction = is_string($input['bridge_action'] ?? null) ? $input['bridge_action'] : 'prepare';
    $klipperRef = is_string($input['klipper_ref'] ?? null) ? trim($input['klipper_ref']) : 'master';

    if (!isset($profile['models'][$model])) {
        $errors['printer_model'] = 'Select a supported printer model';
    }
    if (!isset($profile['connections'][$connection])) {
        $errors['connection'] = 'Select a supported MCU connection';
    }
    if (!isset($profile['programmers'][$programmer])) {
        $errors['programmer'] = 'Select a supported ATmega32U2 programmer';
    }
    if (!in_array($bridgeAction, ['prepare', 'skip'], true)) {
        $errors['bridge_action'] = 'Invalid bridge preparation choice';
    }
    if ($connection === 'usb' && $bridgeAction !== 'prepare') {
        $errors['bridge_action'] = 'USB operation requires preparing the Einsy ATmega32U2 bridge';
    }
    if (!hf_conversion_valid_ref($klipperRef)) {
        $errors['klipper_ref'] = 'Invalid Klipper tag or branch';
    }

    foreach (['ack_power_off', 'ack_backup', 'ack_manual_flash'] as $key) {
        $ack = $input[$key] ?? null;
        if ($ack !== true && $ack !== 1 && $ack !== '1') {
            $errors[$key] = 'Required acknowledgement is missing';
        }
    }

    if ($errors !== []) {
        return [[], $errors];
    }

    return [[
        'profile_id' => $profileId,
        'profile' => $profile,
        'printer_model' => $model,
        'printer_label' => (string)$profile['models'][$model],
        'connection' => $connection,
        'connection_label' => (string)$profile['connections'][$connection],
        'programmer' => $programmer,
        'programmer_label' => (string)$profile['programmers'][$programmer],
        'bridge_action' => $bridgeAction,
        'klipper_ref' => $klipperRef,
    ], []];
}

function hf_conversion_shell_literal(string $value): string
{
    return "'" . str_replace("'", "'\"'\"'", $value) . "'";
}

function hf_conversion_render(string $template, array $values): string
{
    $replace = [];
    foreach ($values as $key => $value) {
        $replace['{{' . $key . '}}'] = (string)$value;
    }
    return strtr($template, $replace);
}

function hf_conversion_safe_entry(string $path): bool
{
    return $path !== ''
        && !str_starts_with($path, '/')
        && !str_contains($path, "\0")
        && !str_contains($path, '//')
        && !preg_match('#(^|/)\.\.?(/|$)#', $path)
        && preg_match('#\A[A-Za-z0-9._/+-]+\z#', $path) === 1;
}

/** @return array<string,string> relative path => contents */
function hf_conversion_package_files(array $options): array
{
    $modelSlug = match ($options['printer_model']) {
        'mk3' => 'MK3',
        'mk3s_plus' => 'MK3S-Plus',
        default => 'MK3S',
    };
    $connection = (string)$options['connection'];
    $bridgeEnabled = $options['bridge_action'] === 'prepare';
    $serialPath = $connection === 'uart1'
        ? '/dev/serial0'
        : '/dev/serial/by-id/REPLACE_WITH_YOUR_PRUSA_DEVICE';
    $uartConfig = $connection === 'uart1' ? 'CONFIG_AVR_SERIAL_UART1=y' : 'CONFIG_AVR_SERIAL_UART0=y';
    $generatedAt = gmdate('c');

    $vars = [
        'MODEL_LABEL' => $options['printer_label'],
        'MODEL_SLUG' => $modelSlug,
        'CONNECTION_LABEL' => $options['connection_label'],
        'CONNECTION' => $connection,
        'PROGRAMMER_LABEL' => $options['programmer_label'],
        'PROGRAMMER' => $options['programmer'],
        'BRIDGE_ACTION' => $options['bridge_action'],
        'BRIDGE_ENABLED' => $bridgeEnabled ? 'yes' : 'no',
        'KLIPPER_REF' => $options['klipper_ref'],
        'KLIPPER_REF_SHELL' => hf_conversion_shell_literal((string)$options['klipper_ref']),
        'SERIAL_PATH' => $serialPath,
        'SERIAL_PATH_SHELL' => hf_conversion_shell_literal($serialPath),
        'UART_CONFIG' => $uartConfig,
        'UART_CONFIG_SHELL' => hf_conversion_shell_literal($uartConfig),
        'GENERATED_AT' => $generatedAt,
        'HF_VERSION' => defined('HF_VERSION') ? HF_VERSION : 'unknown',
    ];

    $files = [];

    $files['README-FIRST.md'] = hf_conversion_render(<<<'MD'
# HotFetched Klipper Conversion Kit

**Printer:** {{MODEL_LABEL}}  
**Controller:** Einsy RAMBo / ATmega2560  
**Host connection after conversion:** {{CONNECTION_LABEL}}  
**ATmega32U2 programmer:** {{PROGRAMMER_LABEL}}  
**Klipper source ref:** `{{KLIPPER_REF}}`  
**Generated by:** HotFetched {{HF_VERSION}} at {{GENERATED_AT}}

This kit keeps conversion work separate from normal HotFetched projects. It does
not automatically flash hardware. Every hardware write is performed only when
you run a numbered script on the Klipper host and confirm the requested phrase.

## Before running anything

1. Disconnect printer mains power before attaching an ISP programmer.
2. Confirm the programmer wiring and orientation twice.
3. Record the existing Prusa Z offset and sheet profiles.
4. Run the backup and verification scripts before any write operation.
5. Read `docs/WIRING-AND-SAFETY.md` and `docs/WORKFLOW.md` completely.

## Order

```text
scripts/01-preflight.sh
scripts/02-backup-atmega32u2.sh
scripts/03-verify-backup.sh
scripts/04-download-atmega32u2-firmware.sh
scripts/05-flash-atmega32u2.sh        # only when bridge preparation is enabled
scripts/06-build-klipper.sh
scripts/07-flash-klipper.sh
scripts/08-stage-printer-config.sh
scripts/09-verify-host-connection.sh
```

`config/printer.cfg` produced by script 08 is based on Klipper's official generic
Einsy RAMBo reference. It is a communication and hardware starting point, not a
print-ready tuned MK3 profile. Verify pin behavior, thermistors, probe, motion,
heater controls, and calibration before enabling heaters or motion.

The conversion package is ready when generated. Hardware conversion is not
verified until you review the command logs and establish a Klipper MCU connection.
MD
, $vars) . "\n";

    $files['firmware/klipper.config'] = hf_conversion_render(<<<'CFG'
CONFIG_LOW_LEVEL_OPTIONS=y
CONFIG_MACH_AVR=y
CONFIG_MACH_atmega2560=y
CONFIG_AVR_FREQ_16000000=y
{{UART_CONFIG}}
CFG
, $vars) . "\n";

    $files['firmware/.keep'] = '';
    $files['backup/.keep'] = '';
    $files['logs/.keep'] = '';
    $files['config/.keep'] = '';
    $files['work/.keep'] = '';

    $files['scripts/lib/common.sh'] = hf_conversion_render(<<<'SH'
#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-$ROOT_DIR/backup}"
FIRMWARE_DIR="${FIRMWARE_DIR:-$ROOT_DIR/firmware}"
CONFIG_DIR="${CONFIG_DIR:-$ROOT_DIR/config}"
LOG_DIR="${LOG_DIR:-$ROOT_DIR/logs}"
WORK_DIR="$ROOT_DIR/work"
PROGRAMMER_KIND={{PROGRAMMER}}
KLIPPER_REF={{KLIPPER_REF_SHELL}}
TARGET_SERIAL={{SERIAL_PATH_SHELL}}
EXPECTED_UART_CONFIG={{UART_CONFIG_SHELL}}
BRIDGE_ACTION={{BRIDGE_ACTION}}
BRIDGE_FIRMWARE_NAME="DFU-hoodserial-combined-PrusaMK3-32u2.hex"
BRIDGE_FIRMWARE_REPOSITORY="https://github.com/PrusaOwners/mk3-32u2-firmware.git"
BRIDGE_FIRMWARE_REF="master"
KLIPPER_REPOSITORY="https://github.com/Klipper3d/klipper.git"

mkdir -p "$BACKUP_DIR" "$FIRMWARE_DIR" "$CONFIG_DIR" "$LOG_DIR" "$WORK_DIR"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"
}

confirm_phrase() {
    local expected="$1"
    local answer
    printf 'Type exactly: %s\n> ' "$expected"
    IFS= read -r answer
    [[ "$answer" == "$expected" ]] || fail "Confirmation phrase did not match"
}

build_avrdude_args() {
    AVRDUDE_ARGS=(-p m32u2)
    case "$PROGRAMMER_KIND" in
        usbasp)
            AVRDUDE_ARGS+=(
                -c "${AVRDUDE_PROGRAMMER:-usbasp}"
                -P "${AVRDUDE_PORT:-usb}"
            )
            ;;
        arduino_isp)
            AVRDUDE_ARGS+=(
                -c "${AVRDUDE_PROGRAMMER:-stk500v1}"
                -P "${AVRDUDE_PORT:-/dev/ttyACM0}"
                -b "${AVRDUDE_BAUD:-19200}"
            )
            ;;
        rpi_gpio)
            AVRDUDE_ARGS+=(
                -C "${AVRDUDE_CONFIG:-/etc/avrdude.conf}"
                -c "${AVRDUDE_PROGRAMMER:-pi_1}"
            )
            ;;
        *)
            fail "Unsupported programmer kind: $PROGRAMMER_KIND"
            ;;
    esac
}

run_avrdude() {
    require_command avrdude
    build_avrdude_args
    as_root avrdude "${AVRDUDE_ARGS[@]}" "$@"
}

as_root() {
    if [[ "${EUID:-$(id -u)}" == "0" ]]; then
        "$@"
    else
        require_command sudo
        sudo -- "$@"
    fi
}

verify_intel_hex() {
    local file="$1"
    local binary
    [[ -f "$file" && ! -L "$file" && -s "$file" ]] || \
        fail "Missing, empty, or unsafe Intel HEX file: $file"

    LC_ALL=C awk '
        {
            line = $0
            sub(/\r$/, "", line)
            if (line == "") next
            if (line !~ /^:[0-9A-Fa-f]+$/) { bad = 1; next }
            if (line == ":00000001FF") eof = 1
        }
        END { exit (!bad && eof) ? 0 : 1 }
    ' "$file" || fail "Malformed Intel HEX text or missing EOF record: $file"

    # avr-objcopy validates record lengths and checksums. It is part of the
    # AVR toolchain already required to build Klipper.
    require_command avr-objcopy
    binary="$(mktemp "$WORK_DIR/.ihex-check-XXXXXX.bin")"
    if ! avr-objcopy -I ihex -O binary "$file" "$binary" >/dev/null 2>&1; then
        rm -f "$binary"
        fail "Intel HEX checksum or record validation failed: $file"
    fi
    if [[ ! -s "$binary" ]]; then
        rm -f "$binary"
        fail "Intel HEX file contains no data records: $file"
    fi
    rm -f "$binary"
}

verify_hex_byte_file() {
    local file="$1"
    local value
    [[ -f "$file" && ! -L "$file" && -s "$file" ]] || \
        fail "Missing, empty, or unsafe fuse backup: $file"
    value="$(tr -d '[:space:]' < "$file")"
    [[ "$value" =~ ^(0[xX])?[0-9A-Fa-f]{2}$ ]] || fail "Invalid fuse-byte backup: $file"
}

verify_bridge_backup_checksums() {
    local checksum_file="$BACKUP_DIR/SHA256SUMS"
    local manifest_file="$BACKUP_DIR/backup-manifest.json"
    local required=(
        firmware_backup.hex
        eeprom_backup.hex
        lowfuse.hex
        highfuse.hex
        extendedfuse.hex
        lockfuse.hex
    )
    local file

    [[ -f "$checksum_file" && ! -L "$checksum_file" && -s "$checksum_file" ]] || \
        fail "Backup checksum file is missing or unsafe"
    [[ -f "$manifest_file" && ! -L "$manifest_file" && -s "$manifest_file" ]] || \
        fail "Backup manifest is missing or unsafe"

    for file in "${required[@]}"; do
        [[ -f "$BACKUP_DIR/$file" && ! -L "$BACKUP_DIR/$file" && -s "$BACKUP_DIR/$file" ]] || \
            fail "Backup is missing, empty, or unsafe: $file"
    done

    LC_ALL=C awk '
        BEGIN {
            required["firmware_backup.hex"] = 1
            required["eeprom_backup.hex"] = 1
            required["lowfuse.hex"] = 1
            required["highfuse.hex"] = 1
            required["extendedfuse.hex"] = 1
            required["lockfuse.hex"] = 1
        }
        {
            hash = $1
            name = $2
            sub(/^\*/, "", name)
            if (length(hash) != 64 || hash !~ /^[0-9A-Fa-f]+$/) { bad = 1; next }
            if (!(name in required) || seen[name]++) { bad = 1; next }
            count++
        }
        END { exit (!bad && count == 6) ? 0 : 1 }
    ' "$checksum_file" || fail "Backup checksum manifest contains unexpected entries"

    (
        cd "$BACKUP_DIR"
        sha256sum --strict -c SHA256SUMS
    )
}
SH
, $vars) . "\n";

    $files['scripts/01-preflight.sh'] = hf_conversion_render(<<<'SH'
#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

for command in avrdude git make python3 sha256sum awk sed grep tr wc avr-objcopy install mktemp; do
    require_command "$command"
done
if ! command -v avr-gcc >/dev/null 2>&1; then
    fail "avr-gcc is missing. Install the AVR compiler toolchain before building Klipper."
fi

printf 'Printer: {{MODEL_LABEL}}\n'
printf 'Connection: {{CONNECTION_LABEL}}\n'
printf 'Programmer: {{PROGRAMMER_LABEL}}\n'
printf 'Bridge preparation: {{BRIDGE_ENABLED}}\n'
printf 'Klipper ref: %s\n' "$KLIPPER_REF"
printf '\nNo hardware write was performed.\n'
printf 'Run script 02 only with printer mains power disconnected.\n'
SH
, $vars) . "\n";

    $files['scripts/02-backup-atmega32u2.sh'] = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

confirm_phrase "PRINTER MAINS POWER IS DISCONNECTED"

backup_files=(
    firmware_backup.hex
    eeprom_backup.hex
    lowfuse.hex
    highfuse.hex
    extendedfuse.hex
    lockfuse.hex
)
for file in "${backup_files[@]}"; do
    [[ ! -e "$BACKUP_DIR/$file" && ! -L "$BACKUP_DIR/$file" ]] || \
        fail "Backup file or symlink already exists: $BACKUP_DIR/$file"
done

backup_complete=0
cleanup_partial_backup() {
    if [[ "$backup_complete" != "1" ]]; then
        for file in "${backup_files[@]}"; do
            rm -f "$BACKUP_DIR/$file"
        done
    fi
}
trap cleanup_partial_backup EXIT

run_avrdude \
    -U "flash:r:$BACKUP_DIR/firmware_backup.hex:i" \
    -U "eeprom:r:$BACKUP_DIR/eeprom_backup.hex:i" \
    -U "lfuse:r:$BACKUP_DIR/lowfuse.hex:h" \
    -U "hfuse:r:$BACKUP_DIR/highfuse.hex:h" \
    -U "efuse:r:$BACKUP_DIR/extendedfuse.hex:h" \
    -U "lock:r:$BACKUP_DIR/lockfuse.hex:h" \
    2>&1 | tee "$LOG_DIR/02-backup-atmega32u2.log"

backup_complete=1
trap - EXIT
printf 'Backup complete. Run scripts/03-verify-backup.sh before any write.\n'
SH
;

    $files['scripts/03-verify-backup.sh'] = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

required=(
    firmware_backup.hex
    eeprom_backup.hex
    lowfuse.hex
    highfuse.hex
    extendedfuse.hex
    lockfuse.hex
)
for file in "${required[@]}"; do
    [[ -f "$BACKUP_DIR/$file" && ! -L "$BACKUP_DIR/$file" && -s "$BACKUP_DIR/$file" ]] || \
        fail "Backup is missing, empty, or unsafe: $file"
done
verify_intel_hex "$BACKUP_DIR/firmware_backup.hex"
verify_intel_hex "$BACKUP_DIR/eeprom_backup.hex"
for file in lowfuse.hex highfuse.hex extendedfuse.hex lockfuse.hex; do
    verify_hex_byte_file "$BACKUP_DIR/$file"
done

if [[ -e "$BACKUP_DIR/SHA256SUMS" || -e "$BACKUP_DIR/backup-manifest.json" ]]; then
    verify_bridge_backup_checksums
    printf 'Existing backup checksum manifest is valid; it was not replaced.\n'
    exit 0
fi

checksum_tmp="$BACKUP_DIR/SHA256SUMS.part"
manifest_tmp="$BACKUP_DIR/backup-manifest.json.part"
rm -f "$checksum_tmp" "$manifest_tmp"
trap 'rm -f "$checksum_tmp" "$manifest_tmp"' EXIT
(
    cd "$BACKUP_DIR"
    sha256sum "${required[@]}" > "$(basename "$checksum_tmp")"
    sha256sum --strict -c "$(basename "$checksum_tmp")"
)

lfuse="$(tr -d '[:space:]' < "$BACKUP_DIR/lowfuse.hex")"
hfuse="$(tr -d '[:space:]' < "$BACKUP_DIR/highfuse.hex")"
efuse="$(tr -d '[:space:]' < "$BACKUP_DIR/extendedfuse.hex")"
lock="$(tr -d '[:space:]' < "$BACKUP_DIR/lockfuse.hex")"
cat > "$manifest_tmp" <<EOF
{
  "target": "ATmega32U2",
  "created_at_utc": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "verified": true,
  "checksum_file": "SHA256SUMS",
  "fuses": {
    "low": "$lfuse",
    "high": "$hfuse",
    "extended": "$efuse",
    "lock": "$lock"
  }
}
EOF
mv "$checksum_tmp" "$BACKUP_DIR/SHA256SUMS"
mv "$manifest_tmp" "$BACKUP_DIR/backup-manifest.json"
trap - EXIT

verify_bridge_backup_checksums

printf 'Backup verification passed. Preserve the entire backup directory elsewhere.\n'
SH
;

    $files['scripts/04-download-atmega32u2-firmware.sh'] = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

if [[ "$BRIDGE_ACTION" == "skip" ]]; then
    printf 'Bridge preparation was skipped for this UART conversion package.\n'
    exit 0
fi

output="$FIRMWARE_DIR/$BRIDGE_FIRMWARE_NAME"
hash_file="$FIRMWARE_DIR/bridge-firmware.sha256"
source_file="$FIRMWARE_DIR/bridge-firmware.source.txt"
commit_file="$FIRMWARE_DIR/bridge-firmware.commit.txt"
for target in "$output" "$hash_file" "$source_file" "$commit_file"; do
    [[ ! -L "$target" ]] || fail "Refusing to replace symlink: $target"
done

commit="$(git -c protocol.file.allow=never ls-remote --exit-code \
    "$BRIDGE_FIRMWARE_REPOSITORY" "refs/heads/$BRIDGE_FIRMWARE_REF" | \
    awk 'NR == 1 { print $1 }')"
[[ "$commit" =~ ^[0-9A-Fa-f]{40,64}$ ]] || fail "Could not resolve the bridge firmware source commit"
bridge_url="https://raw.githubusercontent.com/PrusaOwners/mk3-32u2-firmware/${commit}/hex_files/${BRIDGE_FIRMWARE_NAME}"

tmp="$(mktemp "$FIRMWARE_DIR/.bridge-download-XXXXXX.hex")"
trap 'rm -f "$tmp"' EXIT

if command -v curl >/dev/null 2>&1; then
    curl --proto '=https' --proto-redir '=https' --tlsv1.2 --fail --show-error --location \
        --connect-timeout 15 --max-time 120 --max-filesize 1048576 \
        --output "$tmp" "$bridge_url"
elif command -v wget >/dev/null 2>&1; then
    wget --https-only --timeout=30 --tries=2 --output-document="$tmp" "$bridge_url"
else
    fail "curl or wget is required"
fi

size="$(wc -c < "$tmp")"
[[ "$size" =~ ^[0-9]+$ ]] || fail "Could not determine bridge firmware size"
(( size >= 1024 && size <= 1048576 )) || fail "Bridge firmware size is outside the expected range"
verify_intel_hex "$tmp"
mv "$tmp" "$output"
trap - EXIT
(
    cd "$FIRMWARE_DIR"
    sha256sum "$BRIDGE_FIRMWARE_NAME" | tee "$(basename "$hash_file")"
)
printf '%s\n' "$bridge_url" > "$source_file"
printf '%s\n' "$commit" > "$commit_file"

printf '\nThe upstream repository does not publish a signed release checksum.\n'
printf 'Review the immutable source URL, commit, and recorded SHA-256 before approving script 05.\n'
SH
;

    if ($bridgeEnabled) {
        $files['scripts/05-flash-atmega32u2.sh'] = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

[[ "${BRIDGE_FIRMWARE_APPROVED:-NO}" == "YES" ]] || fail \
    "Set BRIDGE_FIRMWARE_APPROVED=YES only after reviewing the source and SHA-256"
verify_bridge_backup_checksums

for metadata in bridge-firmware.sha256 bridge-firmware.source.txt bridge-firmware.commit.txt; do
    [[ -f "$FIRMWARE_DIR/$metadata" && ! -L "$FIRMWARE_DIR/$metadata" && -s "$FIRMWARE_DIR/$metadata" ]] || \
        fail "Bridge firmware metadata is missing or unsafe: $metadata"
done
(
    cd "$FIRMWARE_DIR"
    sha256sum --strict -c bridge-firmware.sha256
)

bridge="$FIRMWARE_DIR/$BRIDGE_FIRMWARE_NAME"
verify_intel_hex "$bridge"
confirm_phrase "FLASH ATMEGA32U2 BRIDGE"

# No -F signature override is used. avrdude must identify an ATmega32U2.
run_avrdude -U hfuse:w:0xD1:m \
    2>&1 | tee "$LOG_DIR/05a-preserve-eeprom-fuse.log"
run_avrdude \
    -U "flash:w:$bridge:i" \
    -U lfuse:w:0xFF:m \
    -U hfuse:w:0xD9:m \
    -U efuse:w:0xF4:m \
    2>&1 | tee "$LOG_DIR/05b-flash-atmega32u2.log"

run_avrdude -n -v 2>&1 | tee "$LOG_DIR/05c-verify-atmega32u2.log"
printf 'ATmega32U2 write completed. Disconnect the programmer before powering the printer.\n'
SH
;
    } else {
        $files['scripts/05-flash-atmega32u2.sh'] = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'This package was generated for internal UART operation with bridge preparation skipped.\n'
printf 'No ATmega32U2 write command is present.\n'
SH
;
    }

    $files['scripts/06-build-klipper.sh'] = hf_conversion_render(<<<'SH'
#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

require_command git
require_command make
require_command avr-gcc
require_command avr-objcopy
require_command sha256sum

jobs="${JOBS:-2}"
[[ "$jobs" =~ ^[1-9][0-9]?$ ]] || fail "JOBS must be an integer from 1 through 99"

KLIPPER_DIR="$WORK_DIR/klipper"
if [[ ! -d "$KLIPPER_DIR/.git" ]]; then
    [[ ! -e "$KLIPPER_DIR" ]] || fail "$KLIPPER_DIR exists but is not a Git checkout"
    git -c protocol.file.allow=never clone --filter=blob:none --no-checkout \
        "$KLIPPER_REPOSITORY" "$KLIPPER_DIR"
else
    origin="$(git -C "$KLIPPER_DIR" remote get-url origin 2>/dev/null || true)"
    case "$origin" in
        https://github.com/Klipper3d/klipper|https://github.com/Klipper3d/klipper.git)
            ;;
        *)
            fail "Existing Klipper checkout has an unexpected origin: ${origin:-missing}"
            ;;
    esac
fi

git -C "$KLIPPER_DIR" -c protocol.file.allow=never fetch --depth 1 origin "$KLIPPER_REF"
git -C "$KLIPPER_DIR" checkout --detach --force FETCH_HEAD
git -C "$KLIPPER_DIR" reset --hard FETCH_HEAD
git -C "$KLIPPER_DIR" clean -fdx

cp "$FIRMWARE_DIR/klipper.config" "$KLIPPER_DIR/.config"
make -C "$KLIPPER_DIR" clean
make -C "$KLIPPER_DIR" olddefconfig

resolved_config="$KLIPPER_DIR/.config"
grep -qxF 'CONFIG_MACH_AVR=y' "$resolved_config" || fail "Resolved Klipper config is not AVR"
grep -qxF 'CONFIG_MACH_atmega2560=y' "$resolved_config" || fail "Resolved Klipper config is not ATmega2560"
grep -qxF 'CONFIG_AVR_FREQ_16000000=y' "$resolved_config" || fail "Resolved Klipper config is not 16 MHz"
grep -qxF 'CONFIG_MCU="atmega2560"' "$resolved_config" || fail "Resolved Klipper MCU identity mismatch"
selected_uart="$(grep -E '^CONFIG_AVR_SERIAL_UART[0-3]=y$' "$resolved_config" || true)"
[[ "$selected_uart" == "$EXPECTED_UART_CONFIG" ]] || \
    fail "Resolved Klipper communication interface mismatch: ${selected_uart:-none}"

make -C "$KLIPPER_DIR" -j"$jobs" 2>&1 | tee "$LOG_DIR/06-build-klipper.log"

source_hex="$KLIPPER_DIR/out/klipper.elf.hex"
verify_intel_hex "$source_hex"
for target in klipper.elf.hex klipper.elf.hex.sha256 klipper-source-commit.txt \
              klipper-source-ref.txt klipper.config.resolved; do
    [[ ! -L "$FIRMWARE_DIR/$target" ]] || fail "Refusing to replace symlink: $FIRMWARE_DIR/$target"
done

install -m 0644 "$source_hex" "$FIRMWARE_DIR/klipper.elf.hex"
(
    cd "$FIRMWARE_DIR"
    sha256sum klipper.elf.hex | tee klipper.elf.hex.sha256
)
git -C "$KLIPPER_DIR" rev-parse --verify 'HEAD^{commit}' > "$FIRMWARE_DIR/klipper-source-commit.txt"
printf '%s\n' "$KLIPPER_REF" > "$FIRMWARE_DIR/klipper-source-ref.txt"
install -m 0644 "$KLIPPER_DIR/.config" "$FIRMWARE_DIR/klipper.config.resolved"

printf 'Klipper firmware built: %s\n' "$FIRMWARE_DIR/klipper.elf.hex"
SH
, $vars) . "\n";

    $files['scripts/07-flash-klipper.sh'] = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

require_command avrdude
hex="$FIRMWARE_DIR/klipper.elf.hex"
verify_intel_hex "$hex"
for metadata in klipper.elf.hex.sha256 klipper-source-commit.txt klipper-source-ref.txt \
                klipper.config.resolved; do
    [[ -f "$FIRMWARE_DIR/$metadata" && ! -L "$FIRMWARE_DIR/$metadata" && -s "$FIRMWARE_DIR/$metadata" ]] || \
        fail "Klipper build metadata is missing or unsafe: $metadata"
done
(
    cd "$FIRMWARE_DIR"
    sha256sum --strict -c klipper.elf.hex.sha256
)

device="${KLIPPER_FLASH_DEVICE:-}"
[[ -n "$device" ]] || fail \
    "Set KLIPPER_FLASH_DEVICE to the exact /dev/serial/by-id/... device"
[[ "$device" == /dev/* ]] || fail "KLIPPER_FLASH_DEVICE must be an absolute /dev path"
[[ "$device" != *[[:space:]]* ]] || fail "KLIPPER_FLASH_DEVICE contains whitespace"
[[ -e "$device" ]] || fail "Flash device does not exist: $device"
[[ -c "$device" ]] || fail "Flash device is not a character device: $device"

confirm_phrase "FLASH KLIPPER TO ATMEGA2560"

restart_klipper=0
if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet klipper; then
    as_root systemctl stop klipper
    restart_klipper=1
fi
restore_service() {
    if [[ "$restart_klipper" == "1" ]]; then
        as_root systemctl start klipper || true
    fi
}
trap restore_service EXIT

# Klipper documents the wiring protocol at 115200 baud for ATmega2560 application flashing.
as_root avrdude -c wiring -p atmega2560 -P "$device" -b 115200 -D \
    -U "flash:w:$hex:i" 2>&1 | tee "$LOG_DIR/07-flash-klipper.log"

printf 'Klipper MCU firmware write completed.\n'
SH
;

    $files['scripts/08-stage-printer-config.sh'] = hf_conversion_render(<<<'SH'
#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

KLIPPER_DIR="$WORK_DIR/klipper"
source_cfg="$KLIPPER_DIR/config/generic-einsy-rambo.cfg"
[[ -f "$source_cfg" && ! -L "$source_cfg" && -s "$source_cfg" ]] || \
    fail "Build Klipper first; official generic Einsy config was not found"
[[ "$(grep -Ec '^serial:[[:space:]]*' "$source_cfg")" == "1" ]] || \
    fail "Official generic Einsy config has an unexpected MCU serial layout"
grep -qxF '[mcu]' "$source_cfg" || fail "Official generic Einsy config is missing [mcu]"

output="$CONFIG_DIR/printer.cfg"
hash_file="$CONFIG_DIR/printer.cfg.sha256"
[[ ! -L "$output" && ! -L "$hash_file" ]] || fail "Refusing to replace a configuration symlink"
tmp="$(mktemp "$CONFIG_DIR/.printer-XXXXXX.cfg")"
trap 'rm -f "$tmp"' EXIT
{
    printf '# Staged by HotFetched {{HF_VERSION}} for {{MODEL_LABEL}}\n'
    printf '# Source: Klipper config/generic-einsy-rambo.cfg\n'
    printf '# NOT PRINT READY: verify every pin, probe, thermistor, fan, direction, and limit.\n\n'
    awk -v serial={{SERIAL_PATH_SHELL}} '
        /^serial:[[:space:]]*/ { print "serial: " serial; next }
        { print }
    ' "$source_cfg"
} > "$tmp"
grep -qxF '[mcu]' "$tmp" || fail "Staged configuration is missing [mcu]"
grep -qxF 'serial: {{SERIAL_PATH}}' "$tmp" || fail "Staged configuration serial path mismatch"
mv "$tmp" "$output"
trap - EXIT
(
    cd "$CONFIG_DIR"
    sha256sum printer.cfg | tee "$(basename "$hash_file")"
)

printf 'Staged %s\n' "$output"
printf 'Review the entire file before starting Klipper, moving an axis, or enabling a heater.\n'
SH
, $vars) . "\n";

    $files['scripts/09-verify-host-connection.sh'] = hf_conversion_render(<<<'SH'
#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

printf 'Expected configured host path: %s\n' "$TARGET_SERIAL"
if [[ "$TARGET_SERIAL" == *REPLACE_WITH* ]]; then
    printf '\nAvailable stable serial identifiers:\n'
    find /dev/serial/by-id -maxdepth 1 -type l -print 2>/dev/null || true
    printf '\nReplace the placeholder serial path in config/printer.cfg.\n'
else
    [[ -e "$TARGET_SERIAL" ]] || fail "Expected serial path is not present: $TARGET_SERIAL"
    ls -l "$TARGET_SERIAL"
fi

if command -v systemctl >/dev/null 2>&1; then
    systemctl --no-pager --full status klipper || true
fi
printf '\nNo motion or heater command was sent.\n'
SH
, $vars) . "\n";

    $files['recovery/restore-atmega32u2.sh'] = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../scripts" && pwd)"
# shellcheck source=../scripts/lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

verify_bridge_backup_checksums
confirm_phrase "PRINTER MAINS POWER IS DISCONNECTED"
confirm_phrase "RESTORE ORIGINAL ATMEGA32U2 BACKUP"

# Preserve EEPROM across the flash erase, restore flash/EEPROM, then original fuses and lock byte.
run_avrdude -U hfuse:w:0xD1:m
run_avrdude \
    -U "flash:w:$BACKUP_DIR/firmware_backup.hex:i" \
    -U "eeprom:w:$BACKUP_DIR/eeprom_backup.hex:i"
run_avrdude \
    -U "lfuse:w:$BACKUP_DIR/lowfuse.hex:h" \
    -U "hfuse:w:$BACKUP_DIR/highfuse.hex:h" \
    -U "efuse:w:$BACKUP_DIR/extendedfuse.hex:h" \
    -U "lock:w:$BACKUP_DIR/lockfuse.hex:h" \
    2>&1 | tee "$LOG_DIR/recovery-restore-atmega32u2.log"

printf 'ATmega32U2 backup restore completed. Disconnect the programmer before power-up.\n'
SH
;

    $files['recovery/README.md'] = <<<'MD'
# Recovery

`restore-atmega32u2.sh` restores only the USB bridge MCU from the backup created
by scripts 02 and 03. It does not restore the ATmega2560 Prusa application
firmware. Keep an official Prusa firmware image and the normal Prusa flashing
procedure available separately before converting.

The restore script refuses to run without a complete SHA-256-verified backup and
an exact confirmation phrase. It never uses AVRDUDE's `-F` signature override.
MD
;

    $files['config/avrdude-pi_1.conf.snippet'] = <<<'CONF'
# Example only. Do not blindly replace /etc/avrdude.conf.
# GPIO numbering and avrdude linuxgpio support vary by Raspberry Pi OS release.
programmer
  id   = "pi_1";
  desc = "Raspberry Pi GPIO programmer for Einsy ATmega32U2";
  type = "linuxgpio";
  reset = 12;
  sck   = 24;
  mosi  = 23;
  miso  = 18;
;
CONF
;

    $files['docs/WIRING-AND-SAFETY.md'] = hf_conversion_render(<<<'MD'
# Wiring and safety

## Scope

This package targets {{MODEL_LABEL}} with an Einsy RAMBo, ATmega2560 main MCU,
and ATmega32U2 USB bridge. Do not use it for Mini RAMBo, RAMBo, Buddy, xBuddy,
clone boards with different USB circuitry, or a board whose chip markings differ.

## Non-negotiable precautions

- Disconnect printer mains power before connecting the ISP programmer.
- Do not power the Einsy simultaneously from the printer PSU and a programmer
  unless the programmer and board documentation explicitly require it.
- Verify pin 1 orientation at both ends of every ISP cable.
- Preserve the complete `backup/` directory outside this kit before flashing.
- Never add AVRDUDE `-F` to bypass an unexpected device signature.
- Disconnect the ISP programmer before restoring printer power.

## Einsy ATmega32U2 ICSP signals

```text
Einsy pin 1: MISO
Einsy pin 2: USBVCC / 5 V
Einsy pin 3: SCK
Einsy pin 4: MOSI
Einsy pin 5: RESET
Einsy pin 6: GND
```

Confirm the board revision and connector orientation against current hardware
documentation. The package does not attempt to detect wiring mistakes.
MD
, $vars) . "\n";

    $files['docs/WORKFLOW.md'] = hf_conversion_render(<<<'MD'
# Conversion workflow

## Stage A — Recovery preparation

Run scripts 01 through 03. Copy the resulting backup directory to another
machine. Stop if AVRDUDE reports an unexpected signature or any backup file is
empty.

## Stage B — USB bridge preparation

Bridge preparation selected: **{{BRIDGE_ENABLED}}**.

When enabled, script 04 resolves the current upstream branch to a commit, retrieves
the community ATmega32U2 DFU/HoodSerial image from that immutable commit over
HTTPS, and records the commit, source URL, and SHA-256. Review all three, then run
script 05 with `BRIDGE_FIRMWARE_APPROVED=YES`. The script does not use `-F`.

Internal UART operation does not require the replacement bridge for runtime, but
an operational USB connection is commonly used to flash the ATmega2560 initially.

## Stage C — Klipper MCU firmware

Script 06 creates an isolated checkout under this package's `work/` directory,
checks out the official Klipper repository at `{{KLIPPER_REF}}`, applies
the supplied ATmega2560 configuration, and verifies `out/klipper.elf.hex`.

Script 07 requires an explicit `KLIPPER_FLASH_DEVICE` and writes the application
with AVRDUDE's `wiring` protocol at 115200 baud. No device path is guessed.

## Stage D — Host configuration

Script 08 stages Klipper's official generic Einsy RAMBo configuration and changes
only the MCU serial path to `{{SERIAL_PATH}}`. This generic file is not a tuned
Prusa printer profile. Review and calibrate the printer before motion or heat.

Script 09 performs read-only host checks. It sends no motion or heater commands.
MD
, $vars) . "\n";

    $files['docs/SOURCES.md'] = <<<'MD'
# Sources and boundaries

- Klipper official repository: `https://github.com/Klipper3d/klipper`
- Klipper bootloader notes: `https://www.klipper3d.org/Bootloaders.html`
- Klipper official generic Einsy configuration:
  `config/generic-einsy-rambo.cfg` in the Klipper repository
- Community ATmega32U2 firmware:
  `https://github.com/PrusaOwners/mk3-32u2-firmware`
- Referenced conversion series:
  `https://myrigs3d.com/blogs/infos/revive-your-prusa-mk3s-with-klipper-1-5-flash-bootloader`

HotFetched does not redistribute the community ATmega32U2 binary in this package.
The download script resolves the upstream branch to a commit, downloads from the
immutable commit URL, and records the commit and received SHA-256. No server-side
or browser-triggered hardware flashing is implemented.
MD
;

    $files['docs/KNOWN-LIMITATIONS.md'] = <<<'MD'
# Known limitations

- The generated package supports the Einsy RAMBo profile only.
- Klipper is built in the package-local `work/klipper` directory; the scripts do
  not reuse or clean the host's normal Klipper installation.
- Hardware revision and wiring cannot be validated remotely.
- The ATmega32U2 upstream project does not publish signed release checksums.
- Raspberry Pi GPIO programming support varies across Raspberry Pi OS and
  AVRDUDE versions; the included programmer block is an example, not an
  automatic system-file modification.
- The staged generic Einsy `printer.cfg` is not print-ready.
- The package does not restore the ATmega2560 Prusa application firmware.
- A successful firmware build does not prove that hardware flashing succeeded.
MD
;

    foreach ($files as $path => $contents) {
        if (!hf_conversion_safe_entry($path)) {
            throw new RuntimeException('Unsafe conversion package path: ' . $path);
        }
        if (!is_string($contents)) {
            throw new RuntimeException('Invalid conversion package contents: ' . $path);
        }
    }

    $fileHashes = [];
    foreach ($files as $path => $contents) {
        $fileHashes[$path] = hash('sha256', $contents);
    }
    ksort($fileHashes);

    $manifest = [
        'schema' => 1,
        'generator' => 'HotFetched',
        'hotfetched_version' => $vars['HF_VERSION'],
        'generated_at_utc' => $generatedAt,
        'profile_id' => $options['profile_id'],
        'printer_model' => $options['printer_model'],
        'printer_label' => $options['printer_label'],
        'board' => 'Einsy RAMBo',
        'main_mcu' => 'ATmega2560',
        'bridge_mcu' => 'ATmega32U2',
        'connection' => $connection,
        'programmer' => $options['programmer'],
        'bridge_action' => $options['bridge_action'],
        'bridge_firmware_source_policy' => 'resolve_branch_to_immutable_commit_at_download',
        'klipper_ref' => $options['klipper_ref'],
        'expected_klipper_artifact' => 'klipper.elf.hex',
        'normal_project_records_modified' => false,
        'server_executes_hardware_tools' => false,
        'automatic_hardware_flash' => false,
        'uses_avrdude_force_override' => false,
        'files' => $fileHashes,
    ];
    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($manifestJson)) {
        throw new RuntimeException('Could not encode conversion manifest');
    }
    $files['manifest.json'] = $manifestJson . "\n";

    return $files;
}

/** @return array{path:string,download_name:string,root_name:string} */
function hf_conversion_create_zip(array $options): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('PHP ZipArchive extension is required');
    }

    $modelSlug = match ($options['printer_model']) {
        'mk3' => 'MK3',
        'mk3s_plus' => 'MK3S-Plus',
        default => 'MK3S',
    };
    $rootName = 'HotFetched-Prusa-' . $modelSlug . '-Einsy-Klipper-Conversion';
    $downloadName = $rootName . '.zip';
    $tmp = tempnam(sys_get_temp_dir(), 'hf-conversion-');
    if ($tmp === false) {
        throw new RuntimeException('Could not allocate temporary conversion archive');
    }
    $zipPath = $tmp;

    $files = hf_conversion_package_files($options);
    $zip = new ZipArchive();
    $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        @unlink($zipPath);
        throw new RuntimeException('Could not create conversion ZIP archive');
    }

    try {
        foreach ($files as $relative => $contents) {
            $entry = $rootName . '/' . $relative;
            if (!$zip->addFromString($entry, $contents)) {
                throw new RuntimeException('Could not add conversion file: ' . $relative);
            }
            $mode = str_ends_with($relative, '.sh') ? 0100755 : 0100644;
            if (!$zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, $mode << 16)) {
                throw new RuntimeException('Could not set conversion file attributes: ' . $relative);
            }
        }
    } catch (Throwable $e) {
        $zip->close();
        @unlink($zipPath);
        throw $e;
    }

    $closed = $zip->close();
    clearstatcache(true, $zipPath);
    if (!$closed || !is_file($zipPath) || (int)filesize($zipPath) < 1024) {
        @unlink($zipPath);
        throw new RuntimeException('Conversion ZIP archive validation failed');
    }

    return ['path' => $zipPath, 'download_name' => $downloadName, 'root_name' => $rootName];
}
