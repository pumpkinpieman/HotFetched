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
        const txt = ta.value.trim();
        if (!txt) return null;
        return txt.includes(':') ? rtttlToSeq(txt) : (csvToSeq(txt).length ? csvToSeq(txt) : null);
    };
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
    imp.append(ta, target, row2);
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
