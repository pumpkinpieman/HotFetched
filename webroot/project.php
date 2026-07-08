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

    <section class="panel">
        <h2>Firmware Source</h2>
        <div class="kv">
            <div><span>State</span><b><span class="tag st-<?= h($project['source_state']) ?>"><?= h($project['source_state']) ?></span></b></div>
            <div><span>Ref</span><b><?= h($project['source_ref'] ?? '—') ?></b></div>
        </div>
        <p class="empty">Source acquisition (GitHub link / default ZIP import) ships in the next phase. This page is the anchor for it.</p>
    </section>

    <section class="panel">
        <h2>Configuration</h2>
        <p class="empty">Configuration editor unlocks once a firmware source is imported.</p>
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
</body>
</html>
