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

    <section class="panel">
        <h2>Configuration</h2>
        <p class="empty"><?= $project['source_state'] === 'ready'
            ? 'Configuration editor ships in the next phase.'
            : 'Configuration editor unlocks once a firmware source is imported.' ?></p>
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
</script>
</body>
</html>
