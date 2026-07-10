<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$projects = db()->query(
    'SELECT p.*, (SELECT MAX(confidence) FROM builds b WHERE b.project_id = p.id AND b.status = "success") AS best_confidence
     FROM projects p ORDER BY p.updated_at DESC'
)->fetchAll();

$boards = board_defs();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HotFetched</title>
<link rel="icon" type="image/png" href="favicon.png">
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
    <h1>Hot<span>Fetched</span></h1>
    <div class="sub">Firmware configuration &amp; build workshop</div>
</header>

<main class="wrap">
    <section class="panel">
        <h2>New Project</h2>
        <form id="createForm" autocomplete="off">
            <div class="grid">
                <label>Project name
                    <input type="text" name="name" maxlength="64" required
                           pattern="[A-Za-z0-9][A-Za-z0-9 ._\-]{0,63}" placeholder="Ender3-SKR3-Klipper">
                </label>
                <label>Firmware
                    <select name="firmware" id="fwSel" required>
                        <option value="marlin">Marlin</option>
                        <option value="klipper">Klipper</option>
                    </select>
                </label>
                <label>Motherboard
                    <select name="board_id" id="boardSel" required></select>
                </label>
                <label>MCU variant <span class="hint">(check the chip silkscreen)</span>
                    <select name="mcu_variant" id="mcuSel" required></select>
                </label>
            </div>
            <div class="actions">
                <button type="submit" class="btn primary">Create Project</button>
                <span id="createMsg" class="msg"></span>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Projects</h2>
        <?php if (!$projects): ?>
            <p class="empty">No projects yet. Create one above.</p>
        <?php else: ?>
        <table class="tbl">
            <thead>
                <tr><th>Name</th><th>Firmware</th><th>Board</th><th>MCU</th><th>Source</th><th>Best build</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($projects as $p):
                $bd = board_def($p['board_id']); ?>
                <tr>
                    <td><a class="plink" href="project.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a></td>
                    <td><span class="tag fw-<?= h($p['firmware']) ?>"><?= h(ucfirst($p['firmware'])) ?></span></td>
                    <td><?= h($bd['name'] ?? $p['board_id']) ?></td>
                    <td><?= h($p['mcu_variant'] ?? '—') ?></td>
                    <td><span class="tag st-<?= h($p['source_state']) ?>"><?= h($p['source_state']) ?></span></td>
                    <td><?= $p['best_confidence'] !== null ? (int)$p['best_confidence'] . '%' : '—' ?></td>
                    <td><button class="btn danger sm" data-del="<?= (int)$p['id'] ?>" data-name="<?= h($p['name']) ?>">Delete</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</main>

<script>
const CSRF   = <?= json_encode(csrf_token()) ?>;
const BOARDS = <?= json_encode(array_values($boards), JSON_UNESCAPED_SLASHES) ?>;

const boardSel = document.getElementById('boardSel');
const mcuSel   = document.getElementById('mcuSel');
const fwSel    = document.getElementById('fwSel');

function boardSupports(b, fw) {
    const fs = b.firmware_support;
    if (!fs) return true;
    return !!fs[fw];
}

function fillBoards() {
    const fw = fwSel.value;
    const prev = boardSel.value;
    boardSel.innerHTML = '';
    const eligible = BOARDS.filter(b => boardSupports(b, fw));
    for (const b of eligible) {
        const o = document.createElement('option');
        o.value = b.id;
        o.textContent = b.name + (b.vendor && !b.name.includes(b.vendor) ? '' : '');
        boardSel.appendChild(o);
    }
    if (eligible.some(b => b.id === prev)) boardSel.value = prev;
    fillMcu();
}

function fillMcu() {
    const b = BOARDS.find(x => x.id === boardSel.value);
    mcuSel.innerHTML = '';
    if (!b) return;
    for (const v of b.mcu_variants) {
        const o = document.createElement('option');
        o.value = v.id;
        o.textContent = v.label;
        mcuSel.appendChild(o);
    }
    let note = b.note ? b.note : '';
    if (b.min_marlin) {
        const vm = 'Needs Marlin ' + b.min_marlin + '+ (or bugfix-2.1.x). Import a matching source.';
        note = note ? (note + ' ' + vm) : vm;
    }
    let noteEl = document.getElementById('boardNote');
    if (!noteEl) {
        noteEl = document.createElement('p');
        noteEl.id = 'boardNote';
        noteEl.className = 'hint';
        noteEl.style.gridColumn = '1 / -1';
        mcuSel.closest('.grid').appendChild(noteEl);
    }
    noteEl.textContent = note;
    noteEl.style.display = note ? '' : 'none';
}

boardSel.addEventListener('change', fillMcu);
fwSel.addEventListener('change', fillBoards);
fillBoards();

async function api(payload) {
    const r = await fetch('api/projects.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, csrf: CSRF })
    });
    let data;
    try { data = await r.json(); } catch { data = { ok: false, error: 'Bad response' }; }
    return data;
}

document.getElementById('createForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f   = e.target;
    const msg = document.getElementById('createMsg');
    msg.textContent = 'Creating\u2026';
    const res = await api({
        action: 'create',
        name: f.name.value.trim(),
        firmware: f.firmware.value,
        board_id: f.board_id.value,
        mcu_variant: f.mcu_variant.value
    });
    if (res.ok) {
        location.href = 'project.php?id=' + res.id;
    } else {
        msg.textContent = res.error || 'Failed';
    }
});

document.querySelectorAll('[data-del]').forEach(btn => {
    btn.addEventListener('click', async () => {
        if (!confirm('Delete project "' + btn.dataset.name + '"? This removes its firmware sources and builds.')) return;
        const res = await api({ action: 'delete', id: parseInt(btn.dataset.del, 10) });
        if (res.ok) location.reload();
        else alert(res.error || 'Delete failed');
    });
});
</script>
</body>
</html>
