<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid project id');
}
$project = project_get($id);
if ($project === null) {
    http_response_code(404);
    exit('Project not found');
}
$board   = board_def($project['board_id']);
$variant = $board ? board_mcu_variant($board, (string)$project['mcu_variant']) : null;
$fwKey   = $project['firmware']; // 'marlin' | 'klipper'
$detect  = $project['source_detect'] !== null ? json_decode((string)$project['source_detect'], true) : null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($project['name']) ?> — HotFetched</title>
<link rel="icon" type="image/png" href="favicon.png">
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
    <h1><a href="index.php" class="home">Hot<span>Fetched</span></a></h1>
    <div class="sub"><?= h($project['name']) ?></div>
</header>

<main class="wrap">
    <section class="panel">
        <h2>Hardware</h2>
        <div class="kv">
            <div><span>Firmware</span><b><?= h(ucfirst($project['firmware'])) ?></b></div>
            <div><span>Board</span><b><?= h($board['name'] ?? $project['board_id']) ?></b></div>
            <div><span>MCU</span><b><?= h($variant['label'] ?? ($project['mcu_variant'] ?? '—')) ?></b></div>
            <?php if ($fwKey === 'marlin' && $board): ?>
            <div><span>MOTHERBOARD</span><b><code><?= h($board['marlin']['motherboard']) ?></code></b></div>
            <div><span>PIO env</span><b><code><?= h($variant['marlin_env'] ?? '—') ?></code></b></div>
            <?php elseif ($fwKey === 'klipper' && $board): ?>
            <div><span>Klipper MCU</span><b><code><?= h($variant['klipper_mcu'] ?? '—') ?></code></b></div>
            <div><span>Flash offset</span><b><code><?= h($board['klipper']['kconfig']['FLASH_APPLICATION_ADDRESS'] ?? '—') ?></code></b></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel" id="srcPanel">
        <h2>Firmware Source</h2>

        <div class="kv" style="margin-bottom:14px">
            <div><span>State</span><b><span class="tag st-<?= h($project['source_state']) ?>" id="srcState"><?= h($project['source_state']) ?></span></b></div>
            <div><span>Ref</span><b id="srcRef"><?= h($project['source_ref'] ?? '—') ?></b></div>
        </div>

        <div id="srcError" class="src-error" <?= $project['source_error'] === null ? 'hidden' : '' ?>>
            <?= h($project['source_error'] ?? '') ?>
        </div>

        <div id="srcForms" <?= in_array($project['source_state'], ['fetching','ready'], true) ? 'hidden' : '' ?>>
            <div class="src-grid">
                <div class="src-card">
                    <h3>Import from GitHub</h3>
                    <label>Repository URL
                        <input type="url" id="ghUrl" placeholder="https://github.com/owner/repo">
                    </label>
                    <label>Tag / branch <span class="hint">(blank = default branch)</span>
                        <input type="text" id="ghRef" placeholder="e.g. 2.1.3">
                    </label>
                    <div class="actions">
                        <button class="btn primary" id="ghImportBtn">Import from GitHub</button>
                        <button class="btn" id="ghDefaultBtn">Use official <?= h(ucfirst($fwKey)) ?></button>
                    </div>
                </div>
                <div class="src-card">
                    <h3>Import default files (ZIP)</h3>
                    <label>Firmware source ZIP <span class="hint">(max 256 MB)</span>
                        <input type="file" id="zipFile" accept=".zip,application/zip">
                    </label>
                    <div class="actions">
                        <button class="btn primary" id="zipImportBtn">Upload &amp; Import</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="srcFetching" <?= $project['source_state'] === 'fetching' ? '' : 'hidden' ?>>
            <p class="empty" id="srcFetchMsg">Importing source&hellip; this can take a few minutes for a full firmware tree.</p>
            <div class="prog">
                <div class="prog-bar"><div class="prog-fill" id="progFill" style="width:0%"></div></div>
                <div class="prog-text"><span id="progPct">0%</span><span id="progDetail"></span></div>
            </div>
        </div>

        <div id="srcReady" <?= $project['source_state'] === 'ready' ? '' : 'hidden' ?>>
            <div class="kv" id="srcDetect">
                <?php if (is_array($detect)): ?>
                    <div><span>Tree root</span><b><code><?= h(($detect['root'] ?? '') === '' ? '(repo root)' : $detect['root']) ?></code></b></div>
                    <?php foreach (($detect['files'] ?? []) as $k => $v): if ($v === null) continue; ?>
                    <div><span><?= h((string)$k) ?></span><b><code><?= h((string)$v) ?></code></b></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="actions">
                <button class="btn danger" id="srcResetBtn">Replace source</button>
            </div>
        </div>

        <span class="msg" id="srcMsg"></span>
    </section>

    <section class="panel" id="cfgPanel">
        <h2>Configuration</h2>
        <?php if ($project['source_state'] !== 'ready'): ?>
            <p class="empty">Configuration editor unlocks once a firmware source is imported.</p>

        <?php else: ?>
            <p class="empty" id="cfgLoading">Loading configuration&hellip;</p>
            <form id="cfgForm" hidden>
                <div id="cfgGroups"></div>

                <div id="bsBlock" hidden>
                    <h3 class="cfg-group">Boot &amp; Status Images (128&times;64 monochrome LCD)</h3>
                    <p class="empty">For 128&times;64 monochrome LCDs. Full-color images are converted to a 1-bit bitmap &mdash; that's what the hardware displays. Enable &ldquo;Show boot screen&rdquo; or &ldquo;custom status screen image&rdquo; above to use this even before selecting a display. Tune the conversion and preview live before installing.</p>
                    <p class="cfg-warning" id="bsExtWarn" hidden>Your selected display (BTT TFT in touch mode) runs its own firmware &mdash; a Marlin boot image won't appear on it. Set the TFT boot image by copying a .bmp into the <code>BIGTREETECH</code> folder on the <em>TFT's</em> SD card instead. The image below still applies if you switch to the TFT's &ldquo;Marlin mode&rdquo; or add a mono LCD.</p>
                    <div class="src-grid">
                        <div class="src-card">
                            <label>Image target
                                <select id="bsTarget">
                                    <option value="boot">Boot screen (shown at power-on)</option>
                                    <option value="status">Status screen (shown while printing)</option>
                                </select>
                            </label>
                            <label>Image file (PNG/JPEG, max 8 MB)
                                <input type="file" id="bsFile" accept="image/png,image/jpeg">
                            </label>
                            <label class="bs-range">Threshold: <span id="bsThreshVal">128</span>
                                <input type="range" id="bsThreshold" min="0" max="255" value="128">
                            </label>
                            <label class="cfg-bool"><input type="checkbox" id="bsDither" checked> Dither (smooth gradients; off = hard edges)</label>
                            <label class="cfg-bool"><input type="checkbox" id="bsInvert"> Invert (swap light/dark)</label>
                            <div class="actions">
                                <button type="button" class="btn" id="bsPreviewBtn">Preview</button>
                                <button type="button" class="btn primary" id="bsUploadBtn">Convert &amp; Install</button>
                                <span class="msg" id="bsMsg"></span>
                            </div>
                        </div>
                        <div class="src-card" id="bsPreviewCard" hidden>
                            <h3>Preview (as the LCD will show it)</h3>
                            <img id="bsPreview" alt="Image preview" class="bs-preview">
                        </div>
                    </div>
                </div>

                <div id="tftBlock" hidden>
                    <h3 class="cfg-group">TFT Boot Logo (color touchscreen)</h3>
                    <p class="empty">Your display runs its own firmware, so this boot logo isn't compiled into Marlin &mdash; it's a color <code>.bmp</code> you copy onto the <em>TFT's</em> SD card. HotFetched converts your image to the exact 16-bit format the TFT expects and gives you the file plus install steps.</p>
                    <div class="src-grid">
                        <div class="src-card">
                            <label>TFT model
                                <select id="tftModel">
                                    <option value="btt_tft70">BTT TFT70 (1024&times;600)</option>
                                    <option value="btt_tft50">BTT TFT50 (800&times;480)</option>
                                    <option value="btt_tft43">BTT TFT43 (480&times;272)</option>
                                    <option value="btt_tft35">BTT TFT35 (480&times;320)</option>
                                </select>
                            </label>
                            <label>Boot image (PNG/JPEG, max 8 MB)
                                <input type="file" id="tftFile" accept="image/png,image/jpeg">
                            </label>
                            <div class="actions">
                                <button type="button" class="btn" id="tftPreviewBtn">Preview</button>
                                <button type="button" class="btn primary" id="tftDownloadBtn">Download booting.bmp</button>
                                <span class="msg" id="tftMsg"></span>
                            </div>
                        </div>
                        <div class="src-card" id="tftPreviewCard" hidden>
                            <h3>Preview (fitted to the panel)</h3>
                            <img id="tftPreview" alt="TFT boot logo preview" class="bs-preview">
                        </div>
                    </div>
                    <div id="tftInstructions" class="tft-steps" hidden>
                        <h3>Install on your TFT</h3>
                        <ol>
                            <li>Download <code>booting.bmp</code> above.</li>
                            <li>On a FAT32-formatted SD card, create a folder named <code id="tftFolderName">TFT70</code> at the root.</li>
                            <li>Inside it, create <code>bmp</code>, then <code>boot</code> &mdash; so the path is <code id="tftPath">TFT70/bmp/boot/</code>.</li>
                            <li>Copy <code>booting.bmp</code> into that <code>boot</code> folder.</li>
                            <li>Power off the printer, insert the SD card into the <em>TFT's own</em> card slot (not the mainboard's).</li>
                            <li>Power on. The TFT reads the folder and updates its graphics; the new logo shows on the next boot.</li>
                            <li>Once it's applied, you can remove the SD card.</li>
                        </ol>
                        <p class="empty">Filenames and folder names are case-sensitive on the TFT &mdash; keep them exactly as shown. This is independent of the Marlin firmware you build here; both can be flashed separately.</p>
                    </div>
                </div>

                <div id="sndBlock" hidden>
                    <h3 class="cfg-group">Host Event G-code (M300 tones)</h3>
                    <p class="empty">Paste these into your slicer/host event hooks (start G-code, pause script, error hook, end G-code). The power-on tune above is baked into the firmware; these run from the host.</p>
                    <div id="sndSnippets" class="snd-grid"></div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn primary">Submit Configuration</button>
                    <span class="msg" id="cfgMsg"></span>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel" id="buildPanel">
        <h2>Builds</h2>
        <?php if ($project['source_state'] !== 'ready'): ?>
            <p class="empty">Import a firmware source and submit a configuration to build.</p>
        <?php else: ?>
            <div class="actions" style="margin-bottom:12px">
                <button class="btn primary" id="buildStartBtn" type="button">Start Build</button>
                <span class="msg" id="buildMsg">100% confidence = the firmware actually compiled.</span>
            </div>
            <div id="buildCard" hidden>
                <div class="kv" style="margin-bottom:10px">
                    <div><span>Status</span><b><span class="tag" id="bStatus">—</span></b></div>
                    <div><span>Confidence</span><b id="bConf">—</b></div>
                </div>
                <div class="prog" style="max-width:none">
                    <div class="prog-bar"><div class="prog-fill" id="bFill" style="width:0%"></div></div>
                </div>
                <div id="bGates" class="gates"></div>
                <pre id="bLog" class="build-log" hidden></pre>
                <div class="actions" id="bDownloads" hidden>
                    <a class="btn primary" id="dlFw" href="#">Download firmware.bin</a>
                    <a class="btn" id="dlCfg" href="#">Export config bundle (.zip)</a>
                    <a class="btn" id="dlLog" href="#">Build log</a>
                </div>
            </div>
        <?php endif; ?>

        <table class="tbl" style="margin-top:14px" id="buildHistory" hidden>
            <thead><tr><th>#</th><th>Status</th><th>Confidence</th><th>Started</th><th>Finished</th><th></th></tr></thead>
            <tbody id="buildHistoryBody"></tbody>
        </table>
    </section>
</main>

<script>
const CSRF       = <?= json_encode(csrf_token()) ?>;
const PROJECT_ID = <?= (int)$project['id'] ?>;
const FIRMWARE   = <?= json_encode($fwKey) ?>;

const el = (i) => document.getElementById(i);

async function srcApi(payload) {
    const r = await fetch('api/source.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, csrf: CSRF, id: PROJECT_ID })
    });
    let data;
    try { data = await r.json(); } catch { data = { ok: false, error: 'Bad response' }; }
    return data;
}

function setMsg(t) { el('srcMsg').textContent = t || ''; }

function showFetching() {
    el('srcForms').hidden = true;
    el('srcFetching').hidden = false;
    el('srcError').hidden = true;
    el('srcState').textContent = 'fetching';
    el('srcState').className = 'tag st-fetching';
    setMsg('');
    startPolling();
}

function setProgress(pct, detail) {
    el('progFill').style.width = Math.max(0, Math.min(100, pct)) + '%';
    el('progPct').textContent = Math.round(pct) + '%';
    el('progDetail').textContent = detail || '';
}

let pollTimer = null;
function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(async () => {
        const s = await srcApi({ action: 'status' });
        if (!s.ok) return;
        if (s.state !== 'fetching') {
            clearInterval(pollTimer);
            pollTimer = null;
            setProgress(100, '');
            location.reload(); // server-rendered state is the source of truth
            return;
        }
        if (s.progress) {
            const mb = s.progress.mb !== null && s.progress.mb !== undefined
                ? ' — ' + s.progress.mb.toFixed(1) + ' MB downloaded' : '';
            setProgress(s.progress.pct, s.progress.phase + mb);
        }
    }, 1500);
}

// Prefill the GitHub form with the official upstream repo for this firmware.
(async () => {
    if (el('srcForms').hidden) return;
    const d = await srcApi({ action: 'defaults', firmware: FIRMWARE });
    if (d.ok) {
        if (!el('ghUrl').value) el('ghUrl').value = d.url;
        if (!el('ghRef').value) el('ghRef').value = d.ref;
    }
})();

el('ghDefaultBtn')?.addEventListener('click', async () => {
    const d = await srcApi({ action: 'defaults', firmware: FIRMWARE });
    if (d.ok) { el('ghUrl').value = d.url; el('ghRef').value = d.ref; }
});

el('ghImportBtn')?.addEventListener('click', async () => {
    setMsg('Starting GitHub import\u2026');
    const res = await srcApi({ action: 'import_github', url: el('ghUrl').value.trim(), ref: el('ghRef').value.trim() });
    if (res.ok) showFetching();
    else setMsg(res.error || 'Import failed');
});

el('zipImportBtn')?.addEventListener('click', () => {
    const f = el('zipFile').files[0];
    if (!f) { setMsg('Choose a ZIP file first'); return; }
    const fd = new FormData();
    fd.append('action', 'import_zip');
    fd.append('csrf', CSRF);
    fd.append('id', String(PROJECT_ID));
    fd.append('zip', f);

    // XHR (not fetch) for real upload progress events.
    el('srcForms').hidden = true;
    el('srcFetching').hidden = false;
    el('srcError').hidden = true;
    setMsg('');
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/source.php');
    xhr.upload.addEventListener('progress', (e) => {
        if (!e.lengthComputable) return;
        const pct = e.loaded / e.total * 100;
        setProgress(pct, 'Uploading — ' + (e.loaded / 1048576).toFixed(1) + ' / ' + (e.total / 1048576).toFixed(1) + ' MB');
    });
    xhr.addEventListener('load', () => {
        let res;
        try { res = JSON.parse(xhr.responseText); } catch { res = { ok: false, error: 'Bad response' }; }
        if (res.ok) {
            setProgress(0, 'Extracting…');
            el('srcState').textContent = 'fetching';
            el('srcState').className = 'tag st-fetching';
            startPolling();
        } else {
            el('srcForms').hidden = false;
            el('srcFetching').hidden = true;
            setMsg(res.error || 'Import failed');
        }
    });
    xhr.addEventListener('error', () => {
        el('srcForms').hidden = false;
        el('srcFetching').hidden = true;
        setMsg('Upload failed');
    });
    xhr.send(fd);
});

el('srcResetBtn')?.addEventListener('click', async () => {
    if (!confirm('Remove the imported source tree? Configuration edits tied to it will be lost.')) return;
    const res = await srcApi({ action: 'reset' });
    if (res.ok) location.reload();
    else setMsg(res.error || 'Reset failed');
});

if (<?= json_encode($project['source_state'] === 'fetching') ?>) {
    startPolling();
}

/* ---------------------------- Configuration editor ---------------------- */

const cfgForm = el('cfgForm');
let CFG_FIELDS = [];

async function cfgApi(payload) {
    const r = await fetch('api/config.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, csrf: CSRF, id: PROJECT_ID })
    });
    let data;
    try { data = await r.json(); } catch { data = { ok: false, error: 'Bad response' }; }
    return data;
}

function cfgVisible(f, values) {
    if (!f.requires) return true;
    return Object.entries(f.requires).every(([k, v]) => {
        const want = Array.isArray(v) ? v.map(String) : [String(v)];
        return want.includes(String(values[k] ?? ''));
    });
}

function cfgCollect() {
    const values = {};
    for (const f of CFG_FIELDS) {
        const input = document.getElementById('cf_' + f.key);
        if (!input) continue;
        values[f.key] = f.type === 'bool' ? (input.checked ? '1' : '0') : input.value;
    }
    return values;
}

function cfgApplyVisibility() {
    const values = cfgCollect();
    for (const f of CFG_FIELDS) {
        const wrap = document.getElementById('cfw_' + f.key);
        const input = document.getElementById('cf_' + f.key);
        if (!wrap) continue;
        const hide = !cfgVisible(f, values);
        wrap.hidden = hide;
        // Hidden controls must be disabled too: a hidden+required+empty input
        // blocks HTML5 form submission ("not focusable") and shouldn't validate.
        if (input) input.disabled = hide;
        // Overridable ceilings: relax the browser-side max + hint live.
        if (input && f.override_key && f.override_max !== undefined) {
            const over = String(values[f.override_key] ?? '') === '1';
            const max = over ? f.override_max : f.max;
            input.max = max;
            const cap = wrap.querySelector('span');
            if (cap) cap.textContent = f.label + ` (${f.min}\u2013${max})`;
        }
    }
}

let CFG_META = { mono_screens: [], event_presets: {} };

/* Piezo preview: square-wave synthesis with a single global voice.
   Starting any playback stops the previous one; buttons toggle play/stop. */
let audioCtx = null;
const player = { nodes: [], btn: null, timer: null };

function stopPlayback() {
    for (const n of player.nodes) {
        try { n.stop(0); } catch {}
    }
    player.nodes = [];
    clearTimeout(player.timer);
    if (player.btn) {
        player.btn.textContent = player.btn.dataset.playLabel || '\u25B6';
        player.btn = null;
    }
}

function playSeq(seq, btn) {
    stopPlayback();
    if (!seq || !seq.length) return;
    audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
    let t = audioCtx.currentTime + 0.03;
    let totalMs = 0;
    for (const [f, ms] of seq) {
        if (f > 0) {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'square';
            osc.frequency.value = f;
            gain.gain.setValueAtTime(0.12, t);
            gain.gain.setValueAtTime(0.0001, t + ms / 1000 - 0.005);
            osc.connect(gain).connect(audioCtx.destination);
            osc.start(t);
            osc.stop(t + ms / 1000);
            player.nodes.push(osc);
        }
        t += ms / 1000;
        totalMs += ms;
    }
    if (btn) {
        player.btn = btn;
        btn.dataset.playLabel = btn.dataset.playLabel || btn.textContent;
        btn.textContent = '\u25A0 Stop';
        player.timer = setTimeout(stopPlayback, totalMs + 80);
    }
}

/* Toggle helper for every play button. */
function togglePlay(btn, seqGetter) {
    if (player.btn === btn) { stopPlayback(); return; }
    Promise.resolve(seqGetter()).then(seq => { if (seq) playSeq(seq, btn); });
}

function csvToSeq(csv) {
    const nums = String(csv).split(',').map(s => parseInt(s.trim(), 10)).filter(n => !isNaN(n));
    const seq = [];
    for (let i = 0; i + 1 < nums.length; i += 2) seq.push([nums[i], nums[i + 1]]);
    return seq;
}

function eventSeq(key) {
    const sel = document.getElementById('cf_' + key);
    if (!sel) return [];
    if (sel.value === 'custom') {
        const cust = document.getElementById('cf_' + key + '_custom');
        return cust ? csvToSeq(cust.value) : [];
    }
    return CFG_META.event_presets[sel.value] || [];
}

/* RTTTL (Nokia ringtone text) -> [[freq,ms],...] */
function rtttlToSeq(text) {
    const parts = String(text).trim().split(':');
    if (parts.length < 3) return null;
    const defs = { d: 4, o: 5, b: 63 };
    for (const kv of parts[1].split(',')) {
        const [k, v] = kv.split('=').map(s => s.trim().toLowerCase());
        if (k && v) defs[k] = parseInt(v, 10);
    }
    const whole = 4 * 60000 / defs.b; // whole-note ms
    const NOTES = { c: 0, 'c#': 1, d: 2, 'd#': 3, e: 4, f: 5, 'f#': 6, g: 7, 'g#': 8, a: 9, 'a#': 10, b: 11, h: 11 };
    const seq = [];
    for (let tok of parts.slice(2).join(':').split(',')) {
        tok = tok.trim().toLowerCase();
        const m = tok.match(/^(\d{1,2})?(p|[a-h]#?)(\.)?(\d)?(\.)?$/);
        if (!m) continue;
        const dur = parseInt(m[1] || defs.d, 10);
        let ms = whole / dur;
        if (m[3] || m[5]) ms *= 1.5;
        if (m[2] === 'p') {
            seq.push([0, Math.round(ms)]);
            continue;
        }
        const octave = parseInt(m[4] || defs.o, 10);
        const semis = NOTES[m[2]] + (octave - 4) * 12 - 9; // relative to A4
        const freq = Math.round(440 * Math.pow(2, semis / 12));
        seq.push([freq, Math.round(ms)]);
    }
    return seq.length ? seq : null;
}

/* Standard MIDI file -> [[freq,ms],...] — monophonic melody reduction.
   Reads all tracks, keeps the highest note sounding at any time, honors
   set-tempo events, inserts rests for gaps, caps at 64 tones. */
function midiToSeq(buf) {
    const d = new DataView(buf);
    if (d.getUint32(0) !== 0x4D546864) return null; // 'MThd'
    const division = d.getUint16(12);
    if (division & 0x8000) return null; // SMPTE timing unsupported
    let pos = 14;
    const events = []; // {tick, type:'on'|'off'|'tempo', note, usPerQN}
    while (pos + 8 <= d.byteLength) {
        if (d.getUint32(pos) !== 0x4D54726B) break; // 'MTrk'
        const len = d.getUint32(pos + 4);
        let p = pos + 8;
        const end = p + len;
        let tick = 0;
        let running = 0;
        while (p < end) {
            let delta = 0, b;
            do { b = d.getUint8(p++); delta = (delta << 7) | (b & 0x7F); } while (b & 0x80);
            tick += delta;
            let status = d.getUint8(p);
            if (status & 0x80) { p++; running = status; } else { status = running; }
            const type = status & 0xF0;
            if (type === 0x90 || type === 0x80) {
                const note = d.getUint8(p++);
                const vel = d.getUint8(p++);
                if (type === 0x90 && vel > 0) events.push({ tick, type: 'on', note });
                else events.push({ tick, type: 'off', note });
            } else if (status === 0xFF) {
                const meta = d.getUint8(p++);
                let mlen = 0;
                do { b = d.getUint8(p++); mlen = (mlen << 7) | (b & 0x7F); } while (b & 0x80);
                if (meta === 0x51 && mlen === 3) {
                    events.push({ tick, type: 'tempo',
                        usPerQN: (d.getUint8(p) << 16) | (d.getUint8(p + 1) << 8) | d.getUint8(p + 2) });
                }
                p += mlen;
            } else if (type === 0xC0 || type === 0xD0) { p += 1; }
            else if (status === 0xF0 || status === 0xF7) {
                let slen = 0;
                do { b = d.getUint8(p++); slen = (slen << 7) | (b & 0x7F); } while (b & 0x80);
                p += slen;
            } else { p += 2; }
        }
        pos = end;
    }
    if (!events.length) return null;
    events.sort((a, b2) => a.tick - b2.tick);

    let usPerQN = 500000;
    const held = new Set();
    const segments = []; // {notes:[], durMs}
    let lastTick = 0;
    let lastUs = 0;
    const tickToUs = (dt) => dt * usPerQN / division;
    let curNotes = [];
    let curStartUs = 0;

    const sameSet = (a, b) => a.length === b.length && a.every((v, i) => v === b[i]);
    const close = (untilUs) => {
        const durMs = Math.round((untilUs - curStartUs) / 1000);
        if (durMs >= 15) segments.push({ notes: curNotes, durMs: Math.min(durMs, 4000) });
    };

    for (const ev of events) {
        const nowUs = lastUs + tickToUs(ev.tick - lastTick);
        lastTick = ev.tick;
        lastUs = nowUs;
        if (ev.type === 'tempo') { usPerQN = ev.usPerQN; continue; }
        if (ev.type === 'on') held.add(ev.note);
        else held.delete(ev.note);
        const now = [...held].sort((a, b2) => a - b2);
        if (!sameSet(now, curNotes)) {
            close(nowUs);
            curNotes = now;
            curStartUs = nowUs;
        }
        if (segments.length >= 48) break;
    }
    close(lastUs);

    // Chords -> ascending arpeggios so harmony survives a single piezo voice.
    const toFreq = (n) => Math.round(440 * Math.pow(2, (n - 69) / 12));
    const seq = [];
    for (const s of segments) {
        if (!s.notes.length) {
            seq.push([0, Math.min(s.durMs, 2000)]);
            continue;
        }
        if (s.notes.length === 1) {
            seq.push([toFreq(s.notes[0]), Math.min(s.durMs, 3000)]);
            continue;
        }
        const per = Math.max(70, Math.min(220, Math.floor(s.durMs / s.notes.length)));
        let used = 0;
        for (const n of s.notes) {
            seq.push([toFreq(n), per]);
            used += per;
        }
        const hold = s.durMs - used;
        if (hold > 120) seq[seq.length - 1][1] += Math.min(hold, 1500);
        if (seq.length >= 64) break;
    }
    while (seq.length && seq[0][0] === 0) seq.shift();
    while (seq.length && seq[seq.length - 1][0] === 0) seq.pop();
    return seq.length ? seq.slice(0, 64) : null;
}

/* Monophonic melody extraction from decoded audio (MP3/WAV) via
   autocorrelation pitch tracking. Works for whistled/hummed/single-line
   audio; full mixes have no single pitch to find. */
function detectPitchSeq(samples, sampleRate) {
    const frame = 2048, hop = 512;
    const minF = 80, maxF = 1500;
    const minLag = Math.floor(sampleRate / maxF);
    const maxLag = Math.ceil(sampleRate / minF);
    const frames = [];
    for (let start = 0; start + frame <= samples.length; start += hop) {
        let rms = 0;
        for (let i = 0; i < frame; i++) rms += samples[start + i] * samples[start + i];
        rms = Math.sqrt(rms / frame);
        if (rms < 0.015) { frames.push(0); continue; }
        let bestLag = 0, best = 0;
        for (let lag = minLag; lag <= maxLag; lag++) {
            let s = 0;
            for (let i = 0; i < frame - lag; i++) s += samples[start + i] * samples[start + i + lag];
            if (s > best) { best = s; bestLag = lag; }
        }
        let e = 0;
        for (let i = 0; i < frame; i++) e += samples[start + i] * samples[start + i];
        frames.push(best / e > 0.35 && bestLag > 0 ? sampleRate / bestLag : 0);
    }
    // Median-of-3 smoothing
    const sm = frames.map((v, i) => {
        const a = [frames[i - 1] ?? v, v, frames[i + 1] ?? v].sort((x, y) => x - y);
        return a[1];
    });
    // Segment into notes: snap to semitone, merge stable runs, gaps -> rests
    const frameMs = hop / sampleRate * 1000;
    const seq = [];
    let curMidi = null, curMs = 0;
    const push = () => {
        if (curMs < 60) { curMidi = null; curMs = 0; return; }
        if (curMidi === null) {
            if (seq.length) seq.push([0, Math.min(Math.round(curMs), 2000)]);
        } else {
            seq.push([Math.round(440 * Math.pow(2, (curMidi - 69) / 12)), Math.min(Math.round(curMs), 3000)]);
        }
        curMidi = null;
        curMs = 0;
    };
    for (const f of sm) {
        const midi = f > 0 ? Math.round(69 + 12 * Math.log2(f / 440)) : null;
        if (midi === curMidi) { curMs += frameMs; continue; }
        push();
        curMidi = midi;
        curMs = frameMs;
    }
    push();
    while (seq.length && seq[0][0] === 0) seq.shift();
    while (seq.length && seq[seq.length - 1][0] === 0) seq.pop();
    return seq.length ? seq.slice(0, 64) : null;
}

async function audioFileToSeq(arrayBuffer) {
    audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
    const decoded = await audioCtx.decodeAudioData(arrayBuffer.slice(0));
    // Mix down + downsample by striding for speed on long files
    const ch = decoded.getChannelData(0);
    const stride = Math.max(1, Math.floor(decoded.sampleRate / 22050));
    const n = Math.min(Math.floor(ch.length / stride), 22050 * 30); // cap 30s
    const mono = new Float32Array(n);
    for (let i = 0; i < n; i++) mono[i] = ch[i * stride];
    return detectPitchSeq(mono, decoded.sampleRate / stride);
}

/* ------------------------- Exporters (seq -> .mid / .rtttl / .csv) */
function seqToMidiBlob(seq) {
    const division = 480, usPerQN = 500000; // 120 bpm
    const bytes = [];
    const vlq = (n) => { const s = [n & 0x7F]; n >>= 7; while (n) { s.unshift(0x80 | (n & 0x7F)); n >>= 7; } return s; };
    const track = [0, 0xFF, 0x51, 0x03, 0x07, 0xA1, 0x20];
    let pendingDelta = 0;
    for (const [f, ms] of seq) {
        const ticks = Math.max(1, Math.round(ms * 1000 / usPerQN * division));
        if (f === 0) { pendingDelta += ticks; continue; }
        const note = Math.max(0, Math.min(127, Math.round(69 + 12 * Math.log2(f / 440))));
        track.push(...vlq(pendingDelta), 0x90, note, 100, ...vlq(ticks), 0x80, note, 0);
        pendingDelta = 0;
    }
    track.push(0, 0xFF, 0x2F, 0x00);
    const u32 = (n) => [n >>> 24 & 255, n >>> 16 & 255, n >>> 8 & 255, n & 255];
    const u16 = (n) => [n >>> 8 & 255, n & 255];
    bytes.push(0x4D, 0x54, 0x68, 0x64, ...u32(6), ...u16(0), ...u16(1), ...u16(division));
    bytes.push(0x4D, 0x54, 0x72, 0x6B, ...u32(track.length), ...track);
    return new Blob([new Uint8Array(bytes)], { type: 'audio/midi' });
}

function seqToRtttl(seq, name) {
    const bpm = 120, whole = 4 * 60000 / bpm;
    const NAMES = ['c', 'c#', 'd', 'd#', 'e', 'f', 'f#', 'g', 'g#', 'a', 'a#', 'b'];
    const durOf = (ms) => {
        let best = 4, bd = Infinity;
        for (const d2 of [1, 2, 4, 8, 16, 32]) {
            const diff = Math.abs(whole / d2 - ms);
            if (diff < bd) { bd = diff; best = d2; }
        }
        return best;
    };
    const toks = seq.map(([f, ms]) => {
        const d2 = durOf(ms);
        if (f === 0) return d2 + 'p';
        const midi = Math.round(69 + 12 * Math.log2(f / 440));
        const oct = Math.max(4, Math.min(7, Math.floor(midi / 12) - 1));
        return d2 + NAMES[midi % 12] + oct;
    });
    return (name || 'HotFetched') + ':d=4,o=5,b=' + bpm + ':' + toks.join(',');
}

function downloadBlob(blob, filename) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
}

function m300Lines(seq) {
    if (!seq.length) return '; (no sound)';
    return seq.map(([f, p]) => f === 0 ? `G4 P${p}` : `M300 S${f} P${p}`).join('\n');
}

function sndRender() {
    const box = el('sndSnippets');
    if (!box) return;
    box.innerHTML = '';
    const events = [
        ['ev_print_start', 'Print start'], ['ev_print_pause', 'Print paused'],
        ['ev_print_error', 'Print error'], ['ev_print_end', 'Print end'],
        ['ev_connect', 'Connectivity issue'],
    ];
    for (const [key, label] of events) {
        const input = document.getElementById('cf_' + key);
        if (!input) continue;
        const seq = eventSeq(key);
        const card = document.createElement('div');
        card.className = 'src-card';
        const h = document.createElement('h3');
        h.textContent = label + ' \u2014 ' + input.value;
        const pre = document.createElement('pre');
        pre.className = 'snd-code';
        pre.textContent = m300Lines(seq);
        const row = document.createElement('div');
        row.className = 'actions';
        const play = document.createElement('button');
        play.type = 'button';
        play.className = 'btn sm';
        play.textContent = '\u25B6 Play';
        play.addEventListener('click', () => togglePlay(play, () => eventSeq(key)));
        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'btn sm';
        copy.textContent = 'Copy';
        copy.addEventListener('click', () => navigator.clipboard.writeText(pre.textContent));
        row.append(play, copy);
        card.append(h, pre, row);
        box.appendChild(card);
    }

    // Import card: RTTTL / freq-ms CSV -> apply as a custom sequence to a chosen slot.
    const imp = document.createElement('div');
    imp.className = 'src-card snd-import-card';
    imp.innerHTML = '<h3>Import melody (RTTTL ringtone text or freq,ms CSV)</h3>';
    const ta = document.createElement('textarea');
    ta.className = 'snd-import';
    ta.placeholder = 'Beep:d=8,o=5,b=120:c,e,g  \u2014 or \u2014  523,120,0,40,784,180';
    const fileIn = document.createElement('input');
    fileIn.type = 'file';
    fileIn.accept = '.mid,.midi,.rtttl,.txt,.mp3,.wav,.ogg,.m4a';
    let midiSeq = null;
    const target = document.createElement('select');
    for (const [k, lbl] of [['startup', 'Power-on tune (firmware)'], ...events]) {
        const o = document.createElement('option');
        o.value = k;
        o.textContent = 'Apply to: ' + (lbl || k);
        target.appendChild(o);
    }
    const row2 = document.createElement('div');
    row2.className = 'actions';
    const tryBtn = document.createElement('button');
    tryBtn.type = 'button';
    tryBtn.className = 'btn sm';
    tryBtn.textContent = '\u25B6 Preview';
    const applyBtn = document.createElement('button');
    applyBtn.type = 'button';
    applyBtn.className = 'btn sm primary';
    applyBtn.textContent = 'Apply';
    const impMsg = document.createElement('span');
    impMsg.className = 'msg';
    const parseImport = () => {
        if (midiSeq) return midiSeq;
        const txt = ta.value.trim();
        if (!txt) return null;
        return txt.includes(':') ? rtttlToSeq(txt) : (csvToSeq(txt).length ? csvToSeq(txt) : null);
    };
    fileIn.addEventListener('change', () => {
        midiSeq = null;
        impMsg.textContent = '';
        const f = fileIn.files[0];
        if (!f) return;
        const isMidi  = /\.midi?$/i.test(f.name);
        const isAudio = /\.(mp3|wav|ogg|m4a)$/i.test(f.name);
        const reader = new FileReader();
        reader.onload = async () => {
            if (isMidi) {
                midiSeq = midiToSeq(reader.result);
                impMsg.textContent = midiSeq
                    ? 'MIDI parsed: ' + midiSeq.length + ' tones \u2014 Preview, then Apply'
                    : 'Could not parse MIDI file';
            } else if (isAudio) {
                impMsg.textContent = 'Analyzing audio (melody extraction)\u2026';
                try {
                    midiSeq = await audioFileToSeq(reader.result);
                } catch { midiSeq = null; }
                impMsg.textContent = midiSeq
                    ? 'Melody extracted: ' + midiSeq.length + ' tones \u2014 Preview, then Apply. Works best on single-instrument/whistled audio.'
                    : 'No clear melody found (full mixes with vocals+drums cannot be converted \u2014 try a monophonic recording)';
            } else {
                ta.value = String(reader.result).trim();
                impMsg.textContent = 'File loaded \u2014 Preview, then Apply';
            }
        };
        if (isMidi || isAudio) reader.readAsArrayBuffer(f);
        else reader.readAsText(f);
    });
    ta.addEventListener('input', () => { midiSeq = null; if (fileIn.value) fileIn.value = ''; });
    tryBtn.addEventListener('click', () => togglePlay(tryBtn, () => {
        const seq = parseImport();
        if (!seq) impMsg.textContent = 'Could not parse melody';
        return seq;
    }));
    applyBtn.addEventListener('click', () => {
        const seq = parseImport();
        if (!seq) { impMsg.textContent = 'Could not parse melody'; return; }
        const csv = seq.flat().join(',');
        if (target.value === 'startup') {
            const sel = document.getElementById('cf_startup_tune');
            const cust = document.getElementById('cf_startup_tune_custom');
            if (sel && cust) { sel.value = 'custom'; cust.value = csv; }
        } else {
            const sel = document.getElementById('cf_' + target.value);
            const cust = document.getElementById('cf_' + target.value + '_custom');
            if (sel && cust) { sel.value = 'custom'; cust.value = csv; }
        }
        impMsg.textContent = 'Applied \u2014 remember to Submit Configuration';
        cfgApplyVisibility();
        cfgExtrasVisibility();
    });
    row2.append(tryBtn, applyBtn, impMsg);

    // Exporters: current melody -> downloadable .mid / .rtttl / .csv
    const exRow = document.createElement('div');
    exRow.className = 'actions';
    const exLabel = document.createElement('span');
    exLabel.className = 'msg';
    exLabel.textContent = 'Export melody:';
    const mkEx = (label, fn) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn sm';
        b.textContent = label;
        b.addEventListener('click', () => {
            const seq = parseImport();
            if (!seq) { impMsg.textContent = 'Nothing to export \u2014 import or paste a melody first'; return; }
            fn(seq);
        });
        return b;
    };
    exRow.append(exLabel,
        mkEx('.mid',   (s) => downloadBlob(seqToMidiBlob(s), 'melody.mid')),
        mkEx('.rtttl', (s) => downloadBlob(new Blob([seqToRtttl(s, 'HotFetched')], { type: 'text/plain' }), 'melody.rtttl')),
        mkEx('.txt',   (s) => downloadBlob(new Blob([s.flat().join(',')], { type: 'text/plain' }), 'melody.txt')));

    // Sound library (free-midi-chords, MIT) — install once, then browse.
    const lib = document.createElement('div');
    lib.className = 'lib-box';
    const libHead = document.createElement('div');
    libHead.className = 'actions';
    const libBtn = document.createElement('button');
    libBtn.type = 'button';
    libBtn.className = 'btn sm';
    libBtn.textContent = 'Sound library\u2026';
    const libMsg = document.createElement('span');
    libMsg.className = 'msg';
    libHead.append(libBtn, libMsg);
    const libPanel = document.createElement('div');
    libPanel.hidden = true;

    const libSearch = document.createElement('input');
    libSearch.type = 'text';
    libSearch.placeholder = 'Search moods: triumphant, mysterious, sad, hopeful\u2026';
    libSearch.className = 'lib-search';
    const libCat = document.createElement('select');
    const libList = document.createElement('div');
    libList.className = 'lib-list';
    const libCredit = document.createElement('div');
    libCredit.className = 'lib-credit';
    libCredit.textContent = 'Library: ldrolez/free-midi-chords (MIT license)';
    libPanel.append(libSearch, libCat, libList, libCredit);
    lib.append(libHead, libPanel);

    async function libApi(payload) {
        const r = await fetch('api/soundlib.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...payload, csrf: CSRF })
        });
        try { return await r.json(); } catch { return { ok: false, error: 'Bad response' }; }
    }

    let libTimer = null;
    async function libRefresh() {
        const q = libSearch.value.trim();
        const res = await libApi({ action: 'list', q, category: libCat.value });
        if (!res.ok) { libMsg.textContent = res.error || 'List failed'; return; }
        if (libCat.options.length <= 1 && res.categories) {
            libCat.innerHTML = '<option value="">All categories</option>';
            for (const cname of res.categories) {
                const o = document.createElement('option');
                o.value = cname;
                o.textContent = cname;
                libCat.appendChild(o);
            }
        }
        libList.innerHTML = '';
        const loadEntry = async (rel) => {
            libMsg.textContent = 'Loading\u2026';
            const fr = await libApi({ action: 'file', path: rel });
            if (!fr.ok) { libMsg.textContent = fr.error || 'Load failed'; return null; }
            const bin = Uint8Array.from(atob(fr.data_b64), ch => ch.charCodeAt(0));
            const seq = midiToSeq(bin.buffer);
            libMsg.textContent = seq ? '' : ('Could not parse ' + fr.name);
            return seq;
        };
        for (const rel of res.files) {
            const row = document.createElement('div');
            row.className = 'lib-item';
            const name = document.createElement('span');
            name.className = 'lib-name';
            name.textContent = rel.replace(/\.mid$/i, '');
            const play = document.createElement('button');
            play.type = 'button';
            play.className = 'btn sm';
            play.textContent = '\u25B6';
            play.title = 'Play';
            play.addEventListener('click', () => togglePlay(play, () => loadEntry(rel)));
            const applyRow = document.createElement('button');
            applyRow.type = 'button';
            applyRow.className = 'btn sm primary';
            applyRow.textContent = 'Apply';
            applyRow.title = 'Apply to the event selected in \u201CApply to\u201D above';
            applyRow.addEventListener('click', async () => {
                const seq = await loadEntry(rel);
                if (!seq) return;
                midiSeq = seq;
                applyBtn.click();
                impMsg.textContent = 'Applied \u201C' + name.textContent.split(' - ').pop()
                    + '\u201D to ' + target.options[target.selectedIndex].textContent.replace('Apply to: ', '')
                    + ' \u2014 remember to Submit Configuration';
            });
            row.append(name, play, applyRow);
            libList.appendChild(row);
        }
        libMsg.textContent = res.files.length + ' shown of ' + res.total;
    }

    libSearch.addEventListener('input', () => {
        clearTimeout(libTimer);
        libTimer = setTimeout(libRefresh, 300);
    });
    libCat.addEventListener('change', libRefresh);

    libBtn.addEventListener('click', async () => {
        if (!libPanel.hidden) { libPanel.hidden = true; return; }
        const st = await libApi({ action: 'status' });
        if (st.state === 'ready') {
            libPanel.hidden = false;
            libRefresh();
        } else if (st.state === 'installing') {
            libMsg.textContent = 'Installing library\u2026 (a few minutes, ~11k melodies)';
            setTimeout(() => libBtn.click(), 4000);
        } else {
            if (!confirm('Download the free-midi-chords progression library (~5 MB zip, ~11,000 MIDI files, MIT license) into this server\u2019s private storage?')) return;
            const r = await libApi({ action: 'install' });
            libMsg.textContent = r.ok ? 'Installing library\u2026' : (r.error || 'Install failed');
            if (r.ok) setTimeout(() => libBtn.click(), 4000);
        }
    });

    imp.append(ta, fileIn, target, row2, exRow, lib);
    box.appendChild(imp);
}

function startupSeq() {
    const sel = document.getElementById('cf_startup_tune');
    if (!sel) return [];
    if (sel.value === 'custom') {
        const cust = document.getElementById('cf_startup_tune_custom');
        return cust ? csvToSeq(cust.value) : [];
    }
    const presets = {
        chime_up: [[523,120],[0,40],[659,120],[0,40],[784,180]],
        chime_down: [[784,120],[0,40],[659,120],[0,40],[523,180]],
        triple: [[880,90],[0,60],[880,90],[0,60],[880,90]],
    };
    return presets[sel.value] || [];
}

function cfgExtrasVisibility() {
    const screen = document.getElementById('cf_screen');
    // Show the image uploader when a mono screen is selected OR when the user
    // has enabled a custom boot/status image (they may attach the display later,
    // and the boot logo renders on any graphical LCD, not just the mono presets).
    const monoScreen = screen && CFG_META.mono_screens.includes(screen.value);
    const showBoot = document.getElementById('cf_show_bootscreen');
    const customStatus = document.getElementById('cf_custom_status_image');
    const wantsImage = (showBoot && showBoot.checked) || (customStatus && customStatus.checked);
    if (el('bsBlock')) el('bsBlock').hidden = !(monoScreen || wantsImage);

    // External-firmware TFTs (BTT TFT touch mode) don't render a Marlin-compiled
    // _Bootscreen.h — their boot image is a color BMP on the TFT's own SD card.
    // Show the dedicated TFT panel and flag the mono uploader as not-for-this-screen.
    const extScreens = (CFG_META.external_fw_screens || []);
    const isExternal = screen && extScreens.includes(screen.value);
    const warn = el('bsExtWarn');
    if (warn) warn.hidden = !isExternal;
    if (el('tftBlock')) el('tftBlock').hidden = !isExternal;
    if (el('sndBlock')) el('sndBlock').hidden = false;
    sndRender();

    // Play button next to the startup tune selector (added once).
    const st = document.getElementById('cf_startup_tune');
    if (st && !document.getElementById('stPlayBtn')) {
        const b = document.createElement('button');
        b.type = 'button';
        b.id = 'stPlayBtn';
        b.className = 'btn sm';
        b.textContent = '\u25B6 Play';
        b.style.marginTop = '6px';
        b.addEventListener('click', () => togglePlay(b, () => startupSeq()));
        st.parentElement.appendChild(b);
    }
}

function cfgRender(fields, values, meta) {
    CFG_FIELDS = fields;
    CFG_META = meta || CFG_META;
    const groups = {};
    for (const f of fields) (groups[f.group] ??= []).push(f);

    const root = el('cfgGroups');
    root.innerHTML = '';
    for (const [group, fs] of Object.entries(groups)) {
        const h = document.createElement('h3');
        h.className = 'cfg-group';
        h.textContent = group;
        root.appendChild(h);

        const grid = document.createElement('div');
        grid.className = 'grid';
        for (const f of fs) {
            const wrap = document.createElement('label');
            wrap.id = 'cfw_' + f.key;

            const cap = document.createElement('span');
            cap.textContent = f.label + ((f.type === 'int' || f.type === 'float') && f.min !== undefined ? ` (${f.min}\u2013${f.max})` : '');
            wrap.appendChild(cap);

            let input;
            if (f.type === 'select') {
                input = document.createElement('select');
                for (const o of f.options) {
                    const opt = document.createElement('option');
                    opt.value = o;
                    opt.textContent = (f.option_labels && f.option_labels[o]) || o;
                    input.appendChild(opt);
                }
                if (values[f.key] !== null && values[f.key] !== undefined) input.value = values[f.key];
            } else if (f.type === 'bool') {
                input = document.createElement('input');
                input.type = 'checkbox';
                input.checked = values[f.key] === '1';
                wrap.classList.add('cfg-bool');
                if (f.warning_text) wrap.classList.add('cfg-danger');
            } else {
                input = document.createElement('input');
                input.type = (f.type === 'int' || f.type === 'float') ? 'number' : 'text';
                if (f.type === 'int' || f.type === 'float') {
                    if (f.min !== undefined) input.min = f.min;
                    if (f.max !== undefined) input.max = f.max;
                    if (f.type === 'float') input.step = 'any';
                }
                if (f.maxlen) input.maxLength = f.maxlen;
                input.value = values[f.key] ?? '';
            }
            input.id = 'cf_' + f.key;
            input.required = f.type !== 'bool';
            input.addEventListener('change', () => { cfgApplyVisibility(); cfgExtrasVisibility(); });
            wrap.appendChild(input);

            if (f.warning_text) {
                const warn = document.createElement('span');
                warn.className = 'cfg-warning';
                warn.textContent = f.warning_text;
                wrap.appendChild(warn);
            }

            const err = document.createElement('span');
            err.className = 'cfg-err';
            err.id = 'cfe_' + f.key;
            wrap.appendChild(err);

            grid.appendChild(wrap);
        }
        root.appendChild(grid);
    }
    cfgApplyVisibility();
    cfgExtrasVisibility();
    el('cfgLoading').hidden = true;
    cfgForm.hidden = false;
}

if (cfgForm) {
    (async () => {
        const res = await cfgApi({ action: 'get' });
        if (res.ok) cfgRender(res.fields, res.values, res.meta);
        else el('cfgLoading').textContent = res.error || 'Failed to load configuration';
    })();

    const bsThresh = el('bsThreshold');
    bsThresh?.addEventListener('input', () => { el('bsThreshVal').textContent = bsThresh.value; });

    async function bsSend(previewOnly) {
        const f = el('bsFile').files[0];
        if (!f) { el('bsMsg').textContent = 'Choose an image first'; return; }
        el('bsMsg').textContent = previewOnly ? 'Rendering preview\u2026' : 'Converting\u2026';
        const fd = new FormData();
        fd.append('action', 'bootscreen');
        fd.append('csrf', CSRF);
        fd.append('id', String(PROJECT_ID));
        fd.append('image', f);
        fd.append('target', el('bsTarget').value);
        fd.append('threshold', bsThresh.value);
        fd.append('dither', el('bsDither').checked ? '1' : '0');
        fd.append('invert', el('bsInvert').checked ? '1' : '0');
        fd.append('preview_only', previewOnly ? '1' : '0');
        let res;
        try {
            const r = await fetch('api/config.php', { method: 'POST', body: fd });
            res = await r.json();
        } catch { res = { ok: false, error: 'Upload failed' }; }
        if (res.ok) {
            el('bsPreview').src = 'data:image/png;base64,' + res.preview_b64;
            el('bsPreviewCard').hidden = false;
            if (previewOnly) {
                el('bsMsg').textContent = 'Preview only \u2014 not yet installed';
            } else if (res.target === 'status') {
                el('bsMsg').textContent = 'Installed _Statusscreen.h + enabled CUSTOM_STATUS_SCREEN_IMAGE \u2713';
            } else {
                el('bsMsg').textContent = 'Installed _Bootscreen.h + enabled SHOW_CUSTOM_BOOTSCREEN \u2713';
            }
        } else {
            el('bsMsg').textContent = res.error || 'Conversion failed';
        }
    }
    el('bsPreviewBtn')?.addEventListener('click', () => bsSend(true));
    el('bsUploadBtn')?.addEventListener('click', () => bsSend(false));

    // TFT color boot logo: preview (JSON) or download (BMP file).
    const tftFolders = {
        btt_tft70: 'TFT70', btt_tft50: 'TFT50', btt_tft43: 'TFT43', btt_tft35: 'TFT35'
    };
    function tftUpdateInstructions() {
        const m = el('tftModel') ? el('tftModel').value : 'btt_tft70';
        const folder = tftFolders[m] || 'TFT70';
        if (el('tftFolderName')) el('tftFolderName').textContent = folder;
        if (el('tftPath')) el('tftPath').textContent = folder + '/bmp/boot/';
    }
    el('tftModel')?.addEventListener('change', tftUpdateInstructions);
    tftUpdateInstructions();

    async function tftPreview() {
        const f = el('tftFile').files[0];
        if (!f) { el('tftMsg').textContent = 'Choose an image first'; return; }
        el('tftMsg').textContent = 'Rendering preview\u2026';
        const fd = new FormData();
        fd.append('action', 'tftimage');
        fd.append('csrf', CSRF);
        fd.append('id', String(PROJECT_ID));
        fd.append('image', f);
        fd.append('tft_model', el('tftModel').value);
        fd.append('preview_only', '1');
        let res;
        try {
            const r = await fetch('api/config.php', { method: 'POST', body: fd });
            res = await r.json();
        } catch { res = { ok: false, error: 'Upload failed' }; }
        if (res.ok) {
            el('tftPreview').src = 'data:image/png;base64,' + res.preview_b64;
            el('tftPreviewCard').hidden = false;
            el('tftInstructions').hidden = false;
            el('tftMsg').textContent = 'Preview ready \u2014 download the BMP when it looks right';
        } else {
            el('tftMsg').textContent = res.error || 'Preview failed';
        }
    }

    function tftDownload() {
        const f = el('tftFile').files[0];
        if (!f) { el('tftMsg').textContent = 'Choose an image first'; return; }
        el('tftMsg').textContent = 'Generating BMP\u2026';
        // Build a form POST that returns the file as a download.
        const fd = new FormData();
        fd.append('action', 'tftimage');
        fd.append('csrf', CSRF);
        fd.append('id', String(PROJECT_ID));
        fd.append('image', f);
        fd.append('tft_model', el('tftModel').value);
        fd.append('preview_only', '0');
        fetch('api/config.php', { method: 'POST', body: fd })
            .then(r => r.ok ? r.blob() : r.json().then(j => Promise.reject(j.error || 'Failed')))
            .then(blob => {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = 'booting.bmp';
                document.body.appendChild(a); a.click();
                document.body.removeChild(a); URL.revokeObjectURL(url);
                el('tftMsg').textContent = 'Downloaded booting.bmp \u2014 follow the steps below';
                el('tftInstructions').hidden = false;
            })
            .catch(err => { el('tftMsg').textContent = typeof err === 'string' ? err : 'Download failed'; });
    }
    el('tftPreviewBtn')?.addEventListener('click', tftPreview);
    el('tftDownloadBtn')?.addEventListener('click', tftDownload);

    cfgForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        document.querySelectorAll('.cfg-err').forEach(s => s.textContent = '');
        el('cfgMsg').textContent = 'Applying\u2026';
        const res = await cfgApi({ action: 'save', values: cfgCollect() });
        if (res.ok) {
            el('cfgMsg').textContent = 'Applied ' + res.applied.length + ' defines to Configuration.h \u2713';
        } else if (res.field_errors) {
            el('cfgMsg').textContent = 'Fix the highlighted fields';
            for (const [k, msg] of Object.entries(res.field_errors)) {
                const s = document.getElementById('cfe_' + k);
                if (s) s.textContent = msg;
            }
        } else {
            el('cfgMsg').textContent = res.error || 'Save failed';
        }
    });
}

/* ------------------------------- Build pipeline -------------------------- */

const buildBtn = el('buildStartBtn');
let BUILD_ID = null;
let buildTimer = null;

async function buildApi(payload) {
    const r = await fetch('api/build.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, csrf: CSRF })
    });
    try { return await r.json(); } catch { return { ok: false, error: 'Bad response' }; }
}

function renderGates(gates) {
    const box = el('bGates');
    box.innerHTML = '';
    for (const g of gates || []) {
        const row = document.createElement('div');
        row.className = 'gate ' + (g.pass ? 'gate-pass' : 'gate-fail');
        const mark = document.createElement('span');
        mark.textContent = g.pass ? '\u2713' : '\u2717';
        const label = document.createElement('span');
        label.textContent = g.label + ' (' + (g.pass ? '+' + g.points : '0/' + g.points) + ')';
        row.append(mark, label);
        if (!g.pass && g.detail) {
            const d = document.createElement('div');
            d.className = 'gate-detail';
            d.textContent = g.detail;
            row.appendChild(d);
        }
        box.appendChild(row);
    }
}

async function buildPoll() {
    if (!BUILD_ID) return;
    const s = await buildApi({ action: 'status', build_id: BUILD_ID });
    if (!s.ok) return;
    el('buildCard').hidden = false;
    el('bStatus').textContent = s.status;
    el('bStatus').className = 'tag st-' + s.status;
    const conf = s.confidence ?? 0;
    el('bConf').textContent = (s.confidence === null ? '—' : conf + '%');
    el('bFill').style.width = conf + '%';
    renderGates(s.gates);
    if (s.log_tail) {
        const pre = el('bLog');
        pre.hidden = false;
        const atBottom = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 30;
        pre.textContent = s.log_tail;
        if (atBottom) pre.scrollTop = pre.scrollHeight;
    }
    const done = s.status === 'success' || s.status === 'failed';
    if (done) {
        clearInterval(buildTimer);
        buildTimer = null;
        buildBtn.disabled = false;
        el('buildMsg').textContent = s.status === 'success'
            ? 'Build succeeded at ' + conf + '% \u2014 flash firmware.bin from SD (rename not needed; SKR 3 accepts firmware.bin).'
            : 'Build stopped at ' + conf + '% \u2014 fix the failed gate and re-run.';
        if (s.status === 'success') {
            el('bDownloads').hidden = false;
            el('dlFw').href  = 'api/build.php?download=' + BUILD_ID + '&type=firmware';
            el('dlCfg').href = 'api/build.php?download=' + BUILD_ID + '&type=config';
            el('dlLog').href = 'api/build.php?download=' + BUILD_ID + '&type=log';
        }
        buildHistoryRefresh();
    }
}

async function buildHistoryRefresh() {
    const res = await buildApi({ action: 'list', project_id: PROJECT_ID });
    if (!res.ok || !res.builds.length) return;
    el('buildHistory').hidden = false;
    const tb = el('buildHistoryBody');
    tb.innerHTML = '';
    for (const b of res.builds) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td>' + b.id + '</td>'
            + '<td><span class="tag st-' + b.status + '">' + b.status + '</span></td>'
            + '<td>' + (b.confidence === null ? '\u2014' : b.confidence + '%') + '</td>'
            + '<td>' + (b.started_at || '\u2014') + '</td>'
            + '<td>' + (b.finished_at || '\u2014') + '</td>'
            + '<td>' + (b.status === 'success'
                ? '<a class="plink" href="api/build.php?download=' + b.id + '&type=firmware">firmware</a> \u00b7 <a class="plink" href="api/build.php?download=' + b.id + '&type=config">config</a>'
                : (b.status === 'failed' ? '<a class="plink" href="api/build.php?download=' + b.id + '&type=log">log</a>' : '')) + '</td>';
        tb.appendChild(tr);
    }
}

if (buildBtn) {
    buildBtn.addEventListener('click', async () => {
        buildBtn.disabled = true;
        el('buildMsg').textContent = 'Starting\u2026';
        el('bDownloads').hidden = true;
        const res = await buildApi({ action: 'start', project_id: PROJECT_ID });
        if (!res.ok) {
            el('buildMsg').textContent = res.error || 'Start failed';
            buildBtn.disabled = false;
            return;
        }
        BUILD_ID = res.build_id;
        el('buildMsg').textContent = 'Build #' + BUILD_ID + ' running\u2026 first build downloads the STM32 toolchain (several minutes).';
        buildTimer = setInterval(buildPoll, 2500);
        buildPoll();
    });
    buildHistoryRefresh();
}
</script>
</body>
</html>
