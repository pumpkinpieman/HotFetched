<?php
declare(strict_types=1);

/**
 * HotFetched — build worker (CLI only, launched detached).
 * Usage: php build_worker.php {build_id}
 *
 * Confidence gates:
 *   Stage 1 (40) — static validation of saved configuration
 *   Stage 2 (20) — Configuration.h / _adv.h parse + board integrity
 *   Stage 3 (40) — real PlatformIO compile; firmware.bin produced
 * 100 means the firmware actually compiled.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/bootstrap.php';

putenv('HOME=/tmp');

$buildId = (int)($argv[1] ?? 0);
if ($buildId < 1) {
    exit(1);
}

// Fatal-error trap: never leave a build frozen if PHP dies mid-run.
register_shutdown_function(function () use ($buildId): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT status, gate_json FROM builds WHERE id = ?");
        $stmt->execute([$buildId]);
        $row = $stmt->fetch();
        if ($row && in_array($row['status'], ['queued', 'validating', 'building'], true)) {
            $gates = json_decode((string)($row['gate_json'] ?? '[]'), true) ?: [];
            $gates[] = ['id' => 'worker_fatal', 'label' => 'Build worker crashed', 'points' => 0,
                        'pass' => false, 'detail' => $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']];
            $pdo->prepare("UPDATE builds SET status = 'failed', gate_json = ?, finished_at = datetime('now') WHERE id = ?")
                ->execute([json_encode($gates), $buildId]);
        }
    } catch (Throwable) {
    }
});

$stmt = db()->prepare('SELECT * FROM builds WHERE id = ?');
$stmt->execute([$buildId]);
$build = $stmt->fetch();
if (!$build || $build['status'] !== 'queued') {
    exit(0);
}
$project = project_get((int)$build['project_id']);
if ($project === null) {
    exit(0);
}
$board   = board_def((string)$project['board_id']);
$variant = $board ? board_mcu_variant($board, (string)$project['mcu_variant']) : null;

$buildDir = build_dir((int)$project['id'], $buildId);
@mkdir($buildDir, 0775, true);
$logPath = $buildDir . '/build.log';

$gates = [];
$confidence = 0;

function blog(string $msg): void
{
    global $logPath;
    @file_put_contents($logPath, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function bstate(string $status, ?int $conf = null, bool $finished = false): void
{
    global $buildId, $gates, $logPath;
    $sql = "UPDATE builds SET status = ?, gate_json = ?, log_path = ?"
         . ($conf !== null ? ", confidence = " . (int)$conf : "")
         . ($finished ? ", finished_at = datetime('now')" : "")
         . " WHERE id = ?";
    db()->prepare($sql)->execute([$status, json_encode($gates), $logPath, $buildId]);
}

function gate(string $id, string $label, int $points, bool $pass, string $detail = ''): int
{
    global $gates;
    $gates[] = ['id' => $id, 'label' => $label, 'points' => $points, 'pass' => $pass, 'detail' => $detail];
    blog(($pass ? 'PASS' : 'FAIL') . " [{$id}] {$label}" . ($detail !== '' ? " — {$detail}" : ''));
    return $pass ? $points : 0;
}

db()->prepare("UPDATE builds SET status = 'validating', started_at = datetime('now'), log_path = ? WHERE id = ?")
    ->execute([$logPath, $buildId]);
blog('Build #' . $buildId . ' — ' . $project['name'] . ' (' . $project['firmware'] . ', ' . ($board['name'] ?? '?') . ')');

/* ------------------------------------------------ Stage 1: static (40) */

if ($board === null || $variant === null) {
    gate('s1_board', 'Board definition resolves', 10, false, 'Board or MCU variant missing');
    bstate('failed', 0, true);
    exit(0);
}

$stmt = db()->prepare('SELECT field_key, field_value FROM config_values WHERE project_id = ?');
$stmt->execute([(int)$project['id']]);
$saved = [];
foreach ($stmt->fetchAll() as $r) {
    $saved[$r['field_key']] = $r['field_value'];
}

if ($saved === []) {
    gate('s1_present', 'Configuration submitted', 10, false, 'No configuration saved — submit the Configuration form first');
    bstate('failed', 0, true);
    exit(0);
}

$fields = array_merge(marlin_field_defs($board), marlin_field_defs_extended($board));
[$vals, $errors] = hf_validate_fields($fields, $saved);

$missing = $range = $option = [];
foreach ($errors as $k => $msg) {
    if (str_starts_with($msg, 'Required')) $missing[] = $k;
    elseif (str_starts_with($msg, 'Minimum') || str_starts_with($msg, 'Maximum')) $range[] = "$k: $msg";
    else $option[] = "$k: $msg";
}
$confidence += gate('s1_present', 'All required fields present', 10, $missing === [], implode(', ', $missing));
$confidence += gate('s1_limits', 'Values within board limits', 10, $range === [], implode('; ', $range));
$confidence += gate('s1_options', 'Drivers/selections valid for board', 10, $option === [], implode('; ', $option));

$conflicts = [];
if (($vals['probe'] ?? 'none') !== 'none') {
    if (abs((float)($vals['probe_off_x'] ?? 0)) >= (float)($vals['bed_x'] ?? 0)
        || abs((float)($vals['probe_off_y'] ?? 0)) >= (float)($vals['bed_y'] ?? 0)) {
        $conflicts[] = 'Probe offset exceeds bed size';
    }
}
if (($saved['singlenozzle'] ?? '0') === '1' && ($vals['extruders'] ?? '1') !== '2') {
    $conflicts[] = 'SINGLENOZZLE requires 2 extruders';
}
if ((int)($vals['homing_xy'] ?? 0) > (int)($vals['feed_x'] ?? PHP_INT_MAX)) {
    $conflicts[] = 'Homing XY speed exceeds max feedrate X';
}
$confidence += gate('s1_conflicts', 'No conflicting settings', 10, $conflicts === [], implode('; ', $conflicts));

if ($confidence < 40) {
    bstate('failed', $confidence, true);
    exit(0);
}

/* --------------------------------------------- Stage 2: integrity (20) */

$detect  = json_decode((string)$project['source_detect'], true);
$root    = realpath(project_source_dir((int)$project['id']));
$confRel = $detect['files']['configuration'] ?? '';
$advRel  = $detect['files']['configuration_adv'] ?? '';
$confPath = $root !== false ? realpath($root . '/' . $confRel) : false;
$advPath  = $root !== false ? realpath($root . '/' . $advRel) : false;

$doc    = ($confPath !== false && str_starts_with((string)$confPath, $root . '/')) ? marlin_config_parse($confPath) : null;
$docAdv = ($advPath !== false && str_starts_with((string)$advPath, $root . '/')) ? marlin_config_parse($advPath) : null;

$confidence += gate('s2_parse', 'Configuration files parse cleanly', 10, $doc !== null && $docAdv !== null,
    $doc === null ? 'Configuration.h unreadable' : ($docAdv === null ? 'Configuration_adv.h unreadable' : ''));

$mbOk = false;
$mbDetail = '';
if ($doc !== null) {
    $mb = $doc['defines']['MOTHERBOARD'] ?? null;
    $mbOk = $mb !== null && $mb['enabled'] && trim((string)$mb['value']) === (string)$board['marlin']['motherboard'];
    $mbDetail = $mbOk ? '' : 'MOTHERBOARD is ' . trim((string)($mb['value'] ?? '(unset)')) . ', expected ' . $board['marlin']['motherboard'] . ' — re-submit the Configuration form';
}
$confidence += gate('s2_board', 'MOTHERBOARD matches selected board', 10, $mbOk, $mbDetail);

if ($confidence < 60) {
    bstate('failed', $confidence, true);
    exit(0);
}

/* ----------------------------------------------- Stage 3: compile (40) */

$env = (string)($variant['marlin_env'] ?? '');
$pio = '/opt/pio-venv/bin/pio';
if (!is_executable($pio)) {
    $pio = trim((string)shell_exec('command -v pio'));
}

if ($env === '' || $pio === '') {
    $confidence += gate('s3_compile', 'PlatformIO compile', 40, false, 'PlatformIO or environment unavailable');
    bstate('failed', $confidence, true);
    exit(0);
}

bstate('building', $confidence);
blog("Compiling with PlatformIO env {$env} — first build downloads the STM32 toolchain and can take several minutes.");

$srcRoot = $root . ($detect['root'] !== '' ? '' : '');
$cmd = 'cd ' . escapeshellarg($root . ($detect['root'] !== '' ? '/' . $detect['root'] : ''))
     . ' && env HOME=/tmp PLATFORMIO_CORE_DIR=' . escapeshellarg(getenv('PLATFORMIO_CORE_DIR') ?: '/opt/platformio')
     . ' PLATFORMIO_SETTING_ENABLE_TELEMETRY=No'
     . ' timeout 2400 ' . escapeshellarg($pio) . ' run -e ' . escapeshellarg($env)
     . ' >> ' . escapeshellarg($logPath) . ' 2>&1; echo $?';

$exit = (int)trim((string)shell_exec($cmd));

$fwSrc = $root . ($detect['root'] !== '' ? '/' . $detect['root'] : '') . '/.pio/build/' . $env . '/firmware.bin';
$built = $exit === 0 && is_file($fwSrc);

$detail = '';
if (!$built) {
    $tail = (string)@shell_exec('grep -iE "error|#error" ' . escapeshellarg($logPath) . ' | tail -8');
    $detail = $exit === 124 ? 'Compile timed out (40 min)' : ((trim($tail) !== '') ? trim($tail) : 'Compiler exited ' . $exit);
}
$confidence += gate('s3_compile', 'PlatformIO compile produces firmware.bin', 40, $built, $detail);

if ($built) {
    @copy($fwSrc, $buildDir . '/firmware.bin');
    db()->prepare('UPDATE builds SET artifact_path = ? WHERE id = ?')
        ->execute([$buildDir . '/firmware.bin', $buildId]);
    blog('firmware.bin: ' . number_format((float)filesize($fwSrc)) . ' bytes');

    // Config bundle for export/download
    $zip = new ZipArchive();
    if ($zip->open($buildDir . '/config-bundle.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $zip->addFile((string)$confPath, 'Marlin/Configuration.h');
        $zip->addFile((string)$advPath, 'Marlin/Configuration_adv.h');
        $bs = dirname((string)$advPath) . '/_Bootscreen.h';
        if (is_file($bs)) {
            $zip->addFile($bs, 'Marlin/_Bootscreen.h');
        }
        $zip->close();
    }
}

bstate($built ? 'success' : 'failed', $confidence, true);
db()->prepare("UPDATE projects SET updated_at = datetime('now') WHERE id = ?")->execute([(int)$project['id']]);
exit(0);
