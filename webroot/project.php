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
        <?php elseif ($fwKey !== 'marlin'): ?>
            <p class="empty">Klipper configuration generation ships in a later phase.</p>
        <?php else: ?>
            <p class="empty" id="cfgLoading">Loading configuration&hellip;</p>
            <form id="cfgForm" hidden>
                <div id="cfgGroups"></div>

                <div id="bsBlock" hidden>
                    <h3 class="cfg-group">Boot Image (128&times;64 monochrome LCD)</h3>
                    <p class="empty">Full-color images are converted to a 1-bit dithered (pixelated) bitmap &mdash; that's what the LCD hardware can display.</p>
                    <div class="src-grid">
                        <div class="src-card">
                            <label>Boot image (PNG/JPEG, max 8 MB)
                                <input type="file" id="bsFile" accept="image/png,image/jpeg">
                            </label>
                            <div class="actions">
                                <button type="button" class="btn" id="bsUploadBtn">Convert &amp; Install</button>
                                <span class="msg" id="bsMsg"></span>
                            </div>
                        </div>
                        <div class="src-card" id="bsPreviewCard" hidden>
                            <h3>Dithered preview (as the LCD will show it)</h3>
                            <img id="bsPreview" alt="Bootscreen preview" class="bs-preview">
                        </div>
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

    <section class="panel">
        <h2>Builds</h2>
        <?php
        $stmt = db()->prepare('SELECT * FROM builds WHERE project_id = ? ORDER BY id DESC LIMIT 25');
        $stmt->execute([$id]);
        $builds = $stmt->fetchAll();
        ?>
        <?php if (!$builds): ?>
            <p class="empty">No builds yet.</p>
        <?php else: ?>
        <table class="tbl">
            <thead><tr><th>#</th><th>Status</th><th>Confidence</th><th>Started</th><th>Finished</th></tr></thead>
            <tbody>
            <?php foreach ($builds as $b): ?>
                <tr>
                    <td><?= (int)$b['id'] ?></td>
                    <td><span class="tag st-<?= h($b['status']) ?>"><?= h($b['status']) ?></span></td>
                    <td><?= $b['confidence'] !== null ? (int)$b['confidence'] . '%' : '—' ?></td>
                    <td><?= h($b['started_at'] ?? '—') ?></td>
                    <td><?= h($b['finished_at'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
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
    return Object.entries(f.requires).every(([k, v]) => String(values[k] ?? '') === String(v));
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
        if (wrap) wrap.hidden = !cfgVisible(f, values);
    }
}

let CFG_META = { mono_screens: [], event_presets: {} };

/* Piezo preview: square-wave synthesis of freq/ms sequences (Web Audio). */
let audioCtx = null;
function playSeq(seq) {
    if (!seq.length) return;
    audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
    let t = audioCtx.currentTime + 0.03;
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
        }
        t += ms / 1000;
    }
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
    const seq = [];
    let lastTick = 0;
    let lastUs = 0;
    const tickToUs = (dt) => dt * usPerQN / division;
    let curNote = null;
    let curStartUs = 0;

    const emit = (untilUs) => {
        const durMs = Math.round((untilUs - curStartUs) / 1000);
        if (durMs < 15) return;
        if (curNote === null) seq.push([0, Math.min(durMs, 3000)]);
        else seq.push([Math.round(440 * Math.pow(2, (curNote - 69) / 12)), Math.min(durMs, 3000)]);
    };

    for (const ev of events) {
        const nowUs = lastUs + tickToUs(ev.tick - lastTick);
        lastTick = ev.tick;
        lastUs = nowUs;
        if (ev.type === 'tempo') { usPerQN = ev.usPerQN; continue; }
        if (ev.type === 'on') held.add(ev.note);
        else held.delete(ev.note);
        const top = held.size ? Math.max(...held) : null;
        if (top !== curNote) {
            emit(nowUs);
            curNote = top;
            curStartUs = nowUs;
        }
        if (seq.length >= 64) break;
    }
    emit(lastUs);
    while (seq.length && seq[0][0] === 0) seq.shift();
    while (seq.length && seq[seq.length - 1][0] === 0) seq.pop();
    return seq.length ? seq.slice(0, 64) : null;
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
        play.addEventListener('click', () => playSeq(eventSeq(key)));
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
    imp.className = 'src-card';
    imp.innerHTML = '<h3>Import melody (RTTTL ringtone text or freq,ms CSV)</h3>';
    const ta = document.createElement('textarea');
    ta.className = 'snd-import';
    ta.placeholder = 'Beep:d=8,o=5,b=120:c,e,g  \u2014 or \u2014  523,120,0,40,784,180';
    const fileIn = document.createElement('input');
    fileIn.type = 'file';
    fileIn.accept = '.mid,.midi,.rtttl,.txt';
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
        const isMidi = /\.midi?$/i.test(f.name);
        const reader = new FileReader();
        reader.onload = () => {
            if (isMidi) {
                midiSeq = midiToSeq(reader.result);
                impMsg.textContent = midiSeq
                    ? 'MIDI parsed: ' + midiSeq.length + ' tones \u2014 Preview, then Apply'
                    : 'Could not parse MIDI file';
            } else {
                ta.value = String(reader.result).trim();
                impMsg.textContent = 'File loaded \u2014 Preview, then Apply';
            }
        };
        if (isMidi) reader.readAsArrayBuffer(f);
        else reader.readAsText(f);
    });
    ta.addEventListener('input', () => { midiSeq = null; if (fileIn.value) fileIn.value = ''; });
    tryBtn.addEventListener('click', () => {
        const seq = parseImport();
        if (seq) playSeq(seq);
        else impMsg.textContent = 'Could not parse melody';
    });
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
        for (const rel of res.files) {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'lib-item';
            row.textContent = rel.replace(/\.mid$/i, '');
            row.addEventListener('click', async () => {
                libMsg.textContent = 'Loading\u2026';
                const fr = await libApi({ action: 'file', path: rel });
                if (!fr.ok) { libMsg.textContent = fr.error || 'Load failed'; return; }
                const bin = Uint8Array.from(atob(fr.data_b64), ch => ch.charCodeAt(0));
                midiSeq = midiToSeq(bin.buffer);
                if (midiSeq) {
                    ta.value = '';
                    impMsg.textContent = fr.name + ': ' + midiSeq.length + ' tones \u2014 Preview, then Apply';
                    libMsg.textContent = '';
                    playSeq(midiSeq);
                } else {
                    libMsg.textContent = 'Could not parse ' + fr.name;
                }
            });
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

    imp.append(ta, fileIn, target, row2, lib);
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
    if (el('bsBlock')) el('bsBlock').hidden = !(screen && CFG_META.mono_screens.includes(screen.value));
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
        b.addEventListener('click', () => playSeq(startupSeq()));
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
            } else {
                input = document.createElement('input');
                input.type = (f.type === 'int' || f.type === 'float') ? 'number' : 'text';
                if (f.type === 'int' || f.type === 'float') {
                    if (f.min !== undefined) input.min = f.min;
                    if (f.max !== undefined) input.max = f.max;
                    if (f.type === 'float') input.step = '0.01';
                }
                if (f.maxlen) input.maxLength = f.maxlen;
                input.value = values[f.key] ?? '';
            }
            input.id = 'cf_' + f.key;
            input.required = f.type !== 'bool';
            input.addEventListener('change', () => { cfgApplyVisibility(); cfgExtrasVisibility(); });
            wrap.appendChild(input);

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

    el('bsUploadBtn')?.addEventListener('click', async () => {
        const f = el('bsFile').files[0];
        if (!f) { el('bsMsg').textContent = 'Choose an image first'; return; }
        el('bsMsg').textContent = 'Converting\u2026';
        const fd = new FormData();
        fd.append('action', 'bootscreen');
        fd.append('csrf', CSRF);
        fd.append('id', String(PROJECT_ID));
        fd.append('image', f);
        let res;
        try {
            const r = await fetch('api/config.php', { method: 'POST', body: fd });
            res = await r.json();
        } catch { res = { ok: false, error: 'Upload failed' }; }
        if (res.ok) {
            el('bsMsg').textContent = 'Installed _Bootscreen.h + enabled SHOW_CUSTOM_BOOTSCREEN \u2713';
            el('bsPreview').src = 'data:image/png;base64,' + res.preview_b64;
            el('bsPreviewCard').hidden = false;
        } else {
            el('bsMsg').textContent = res.error || 'Conversion failed';
        }
    });

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
</script>
</body>
</html>
