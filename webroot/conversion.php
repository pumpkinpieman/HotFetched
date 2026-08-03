<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/KlipperConversion.php';

$profiles = hf_conversion_profiles();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Klipper Conversion Assistant — HotFetched</title>
<link rel="icon" type="image/png" href="favicon.png">
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
    <a href="index.php" class="brand">
        <img src="logo.png" alt="HotFetched" class="brand-logo">
        <span class="brand-text">Hot<span class="accent">Fetched</span></span>
    </a>
    <div class="sub">Klipper conversion assistant</div>
    <a class="btn sm" href="index.php">Back to Projects</a>
</header>

<main class="wrap conversion-wrap">
    <section class="panel conversion-hero">
        <div>
            <span class="tag fw-klipper">Standalone workflow</span>
            <h1>Klipper Conversion Assistant</h1>
            <p class="hint">
                Generate a board-specific conversion kit without creating or modifying a normal
                HotFetched project. The kit contains backup, build, flash, verification, and
                recovery scripts. HotFetched never flashes attached hardware from the browser.
            </p>
        </div>
        <div class="conversion-warning">
            <b>Hardware write warning</b>
            <span>Incorrect ISP wiring or fuse writes can make the USB bridge unavailable. Back up first and never bypass an unexpected MCU signature.</span>
        </div>
    </section>

    <section class="panel">
        <h2>Conversion package</h2>
        <form id="conversionForm" autocomplete="off">
            <div class="grid">
                <label>Conversion profile
                    <select name="profile_id" id="profileSel" required></select>
                </label>
                <label>Printer model
                    <select name="printer_model" id="modelSel" required></select>
                </label>
                <label>Main MCU connection after conversion
                    <select name="connection" id="connectionSel" required></select>
                </label>
                <label>ATmega32U2 programmer
                    <select name="programmer" id="programmerSel" required></select>
                </label>
                <label>USB bridge preparation
                    <select name="bridge_action" id="bridgeSel" required>
                        <option value="prepare">Generate backup and bridge-flash workflow</option>
                        <option value="skip">Skip bridge write workflow</option>
                    </select>
                    <span class="hint" id="bridgeHint"></span>
                </label>
                <label>Klipper tag or branch
                    <input type="text" name="klipper_ref" id="klipperRef" value="master" maxlength="80"
                           pattern="[A-Za-z0-9][A-Za-z0-9._/\-]{0,79}" required>
                    <span class="hint">Official Klipper repository only; no arbitrary repository URL is accepted.</span>
                </label>
            </div>

            <div class="conversion-summary" id="conversionSummary" aria-live="polite"></div>

            <div class="conversion-acks">
                <label class="cfg-bool">
                    <input type="checkbox" name="ack_power_off" required>
                    <span>I understand printer mains power must be disconnected before attaching the ISP programmer.</span>
                </label>
                <label class="cfg-bool">
                    <input type="checkbox" name="ack_backup" required>
                    <span>I will create and verify a complete ATmega32U2 backup before any bridge write.</span>
                </label>
                <label class="cfg-bool">
                    <input type="checkbox" name="ack_manual_flash" required>
                    <span>I understand the generated scripts require manual review and confirmation and are not executed by HotFetched.</span>
                </label>
            </div>

            <div class="actions">
                <button type="submit" class="btn primary" id="generateBtn">Generate Conversion Kit</button>
                <span id="conversionMsg" class="msg" role="status"></span>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Generated workflow</h2>
        <div class="conversion-steps">
            <article class="conversion-step">
                <b>1. Identify and back up</b>
                <span>Read the ATmega32U2 flash, EEPROM, fuses, and lock byte; validate Intel HEX and SHA-256.</span>
            </article>
            <article class="conversion-step">
                <b>2. Prepare the USB bridge</b>
                <span>Download the upstream DFU/HoodSerial image and require explicit approval before writing. AVRDUDE <code>-F</code> is not used.</span>
            </article>
            <article class="conversion-step">
                <b>3. Build Klipper</b>
                <span>Compile the official Klipper source for ATmega2560 at 16 MHz using UART0 or UART1 and validate <code>klipper.elf.hex</code>.</span>
            </article>
            <article class="conversion-step">
                <b>4. Flash and stage configuration</b>
                <span>Require an explicit serial device, flash through the wiring protocol, then stage Klipper's official generic Einsy configuration.</span>
            </article>
            <article class="conversion-step">
                <b>5. Verify and calibrate</b>
                <span>Perform read-only host checks first. The staged configuration is intentionally marked not print-ready.</span>
            </article>
            <article class="conversion-step">
                <b>6. Recovery</b>
                <span>Restore the USB bridge only from a complete checksum-verified backup and retain separate official Prusa main-MCU recovery files.</span>
            </article>
        </div>
    </section>
</main>

<script>
const CSRF = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PROFILES = <?= json_encode($profiles, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

const form = document.getElementById('conversionForm');
const profileSel = document.getElementById('profileSel');
const modelSel = document.getElementById('modelSel');
const connectionSel = document.getElementById('connectionSel');
const programmerSel = document.getElementById('programmerSel');
const bridgeSel = document.getElementById('bridgeSel');
const bridgeHint = document.getElementById('bridgeHint');
const summary = document.getElementById('conversionSummary');
const msg = document.getElementById('conversionMsg');
const generateBtn = document.getElementById('generateBtn');

function fillSelect(select, entries) {
    select.innerHTML = '';
    for (const [value, label] of Object.entries(entries || {})) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        select.appendChild(option);
    }
}

function selectedProfile() {
    return PROFILES[profileSel.value] || null;
}

function fillProfileOptions() {
    profileSel.innerHTML = '';
    for (const profile of Object.values(PROFILES)) {
        const option = document.createElement('option');
        option.value = profile.id;
        option.textContent = profile.label;
        profileSel.appendChild(option);
    }
    refreshProfile();
}

function refreshProfile() {
    const profile = selectedProfile();
    if (!profile) return;
    fillSelect(modelSel, profile.models);
    fillSelect(connectionSel, profile.connections);
    fillSelect(programmerSel, profile.programmers);
    refreshConnection();
}

function refreshConnection() {
    const usesUsb = connectionSel.value === 'usb';
    if (usesUsb) {
        bridgeSel.value = 'prepare';
        bridgeSel.querySelector('option[value="skip"]').disabled = true;
        bridgeHint.textContent = 'Required when Klipper will communicate through the Einsy USB bridge.';
    } else {
        bridgeSel.querySelector('option[value="skip"]').disabled = false;
        bridgeHint.textContent = 'The runtime connection is UART1. Bridge preparation may be skipped only when the existing USB bridge is known to work; the ATmega2560 bootloader flash still uses an explicit USB serial device.';
    }
    refreshSummary();
}

function refreshSummary() {
    const profile = selectedProfile();
    if (!profile) return;
    const model = profile.models[modelSel.value] || '—';
    const connection = profile.connections[connectionSel.value] || '—';
    const programmer = profile.programmers[programmerSel.value] || '—';
    const bridge = bridgeSel.options[bridgeSel.selectedIndex]?.textContent || '—';
    summary.replaceChildren();

    const rows = [
        ['Printer', model],
        ['Main MCU', profile.main_mcu],
        ['USB bridge MCU', profile.bridge_mcu],
        ['Connection', connection],
        ['Programmer', programmer],
        ['USB bridge workflow', bridge],
        ['Expected artifact', profile.artifact]
    ];
    for (const [key, value] of rows) {
        const cell = document.createElement('div');
        const label = document.createElement('span');
        const strong = document.createElement('b');
        label.textContent = key;
        strong.textContent = value;
        cell.append(label, strong);
        summary.appendChild(cell);
    }
}

profileSel.addEventListener('change', refreshProfile);
connectionSel.addEventListener('change', refreshConnection);
modelSel.addEventListener('change', refreshSummary);
programmerSel.addEventListener('change', refreshSummary);
bridgeSel.addEventListener('change', refreshSummary);
fillProfileOptions();

function filenameFromDisposition(value) {
    if (!value) return 'HotFetched-Klipper-Conversion.zip';
    const utf = value.match(/filename\*=UTF-8''([^;]+)/i);
    if (utf) {
        try { return decodeURIComponent(utf[1]); } catch { /* use fallback */ }
    }
    const plain = value.match(/filename="([^"]+)"/i);
    return plain ? plain[1] : 'HotFetched-Klipper-Conversion.zip';
}

async function readError(response) {
    const text = await response.text();
    try {
        const data = JSON.parse(text);
        if (data && typeof data === 'object' && data.fields) {
            const details = Object.values(data.fields).join('; ');
            return details || data.error || 'Request failed';
        }
        return data && typeof data === 'object' && typeof data.error === 'string'
            ? data.error
            : 'Request failed';
    } catch {
        return text.trim() || 'Request failed';
    }
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;

    generateBtn.disabled = true;
    msg.textContent = 'Generating conversion kit…';
    const data = new FormData(form);
    const payload = {
        action: 'generate',
        csrf: CSRF,
        profile_id: data.get('profile_id'),
        printer_model: data.get('printer_model'),
        connection: data.get('connection'),
        programmer: data.get('programmer'),
        bridge_action: data.get('bridge_action'),
        klipper_ref: String(data.get('klipper_ref') || '').trim(),
        ack_power_off: data.has('ack_power_off'),
        ack_backup: data.has('ack_backup'),
        ack_manual_flash: data.has('ack_manual_flash')
    };

    try {
        const response = await fetch('api/conversion.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (!response.ok) throw new Error(await readError(response));

        const contentType = String(response.headers.get('Content-Type') || '').toLowerCase();
        if (!contentType.startsWith('application/zip')) {
            throw new Error('Server returned an unexpected response instead of a ZIP archive');
        }

        const blob = await response.blob();
        if (blob.size < 1024) throw new Error('Generated archive is unexpectedly small');
        const filename = filenameFromDisposition(response.headers.get('Content-Disposition'));
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = filename;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(url), 30000);
        msg.textContent = 'Conversion kit generated. Review README-FIRST.md before running any script.';
    } catch (error) {
        msg.textContent = error instanceof Error ? error.message : 'Generation failed';
    } finally {
        generateBtn.disabled = false;
    }
});
</script>
</body>
</html>
