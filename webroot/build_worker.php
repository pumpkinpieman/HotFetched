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
 *   HotFetched v3.7.9 direct UI/layout package
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

// Worker-only source patches must never leak into later builds. Keep exact
// in-memory backups and restore them on every normal or abnormal shutdown.
$GLOBALS['__hf_source_backups'] = [];
function source_patch_backup(string $path): bool
{
    if (isset($GLOBALS['__hf_source_backups'][$path])) return true;
    $data = @file_get_contents($path);
    if ($data === false) return false;
    $GLOBALS['__hf_source_backups'][$path] = [
        'data' => $data,
        'mode' => @fileperms($path) & 0777,
    ];
    return true;
}
function source_patch_restore_all(): void
{
    foreach (array_reverse($GLOBALS['__hf_source_backups'], true) as $path => $backup) {
        if (@file_put_contents($path, $backup['data']) === false) {
            error_log('[HotFetched] failed to restore worker-patched source: ' . $path);
            continue;
        }
        if (($backup['mode'] ?? 0) > 0) @chmod($path, (int)$backup['mode']);
    }
    $GLOBALS['__hf_source_backups'] = [];
}


/** Remove an isolated compiler directory without following symlinks. */
function build_tree_remove(string $path): bool
{
    if (!file_exists($path) && !is_link($path)) return true;
    if (is_file($path) || is_link($path)) return @unlink($path);
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $node) {
            $node->isDir() && !$node->isLink()
                ? @rmdir($node->getPathname())
                : @unlink($node->getPathname());
        }
    } catch (Throwable) {
        return false;
    }
    return @rmdir($path);
}

/** Return every PlatformIO environment declared by an imported Marlin tree. */
function marlin_environment_names(string $treeRoot): array
{
    $files = [];
    $main = $treeRoot . '/platformio.ini';
    if (is_file($main)) $files[$main] = true;

    $iniRoot = $treeRoot . '/ini';
    if (is_dir($iniRoot)) {
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($iniRoot, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $node) {
                if ($node->isFile() && strtolower($node->getExtension()) === 'ini' && $node->getSize() < 2_000_000) {
                    $files[$node->getPathname()] = true;
                }
            }
        } catch (Throwable) {
        }
    }

    $envs = [];
    foreach (array_keys($files) as $file) {
        $text = @file_get_contents($file);
        if ($text === false) continue;
        if (preg_match_all('/^\s*\[env:([^\]]+)\]\s*$/mi', $text, $matches)) {
            foreach ($matches[1] as $env) {
                $env = trim((string)$env);
                if ($env !== '') $envs[$env] = true;
            }
        }
    }
    $out = array_keys($envs);
    natcasesort($out);
    return array_values($out);
}

/** Resolve the board profile's environment against the imported source tree. */
function marlin_resolve_environment(string $treeRoot, array $variant): array
{
    $requested = trim((string)($variant['marlin_env'] ?? ''));
    $candidates = [$requested];
    foreach (($variant['marlin_env_aliases'] ?? []) as $alias) {
        if (is_string($alias) && trim($alias) !== '') $candidates[] = trim($alias);
    }

    // Compatibility with older HotFetched profiles. The current Marlin name for
    // the SKR 3 / SKR 3 EZ H723 target is STM32H723Vx_btt.
    $builtinAliases = [
        'STM32H723VG_btt' => ['STM32H723Vx_btt'],
        'STM32H723Vx_btt' => ['STM32H723VG_btt'],
    ];
    foreach ($builtinAliases[$requested] ?? [] as $alias) $candidates[] = $alias;
    $candidates = array_values(array_unique(array_filter($candidates, 'strlen')));

    $available = marlin_environment_names($treeRoot);
    $lookup = [];
    foreach ($available as $env) $lookup[strtolower($env)] = $env;
    foreach ($candidates as $candidate) {
        $key = strtolower($candidate);
        if (isset($lookup[$key])) {
            return [
                'ok' => true,
                'requested' => $requested,
                'resolved' => $lookup[$key],
                'available' => $available,
                'used_alias' => strcasecmp($requested, $lookup[$key]) !== 0,
            ];
        }
    }
    return [
        'ok' => false,
        'requested' => $requested,
        'resolved' => '',
        'available' => $available,
        'used_alias' => false,
    ];
}

function marlin_selected_screen(array $board, string $screenId): array
{
    foreach (($board['marlin']['screens'] ?? []) as $screen) {
        if ((string)($screen['id'] ?? '') === $screenId) return $screen;
    }
    return ['id' => 'none', 'label' => 'None / headless', 'type' => 'none'];
}

/** Confirm the object family produced by the compiler matches the selected UI. */
function marlin_verify_compiled_screen(string $pioEnvDir, array $screen, array $doc): array
{
    $type = (string)($screen['type'] ?? 'none');
    $objects = [];
    if (is_dir($pioEnvDir)) {
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($pioEnvDir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $node) {
                if ($node->isFile() && str_ends_with($node->getFilename(), '.o')) {
                    $objects[$node->getFilename()] = true;
                }
            }
        } catch (Throwable) {
        }
    }

    $enabledValue = static function (string $key) use ($doc): ?string {
        $entry = $doc['defines'][$key] ?? null;
        if ($entry === null || !($entry['enabled'] ?? false)) return null;
        return trim((string)($entry['value'] ?? ''));
    };

    $checks = [];
    $define = marlin_screen_define($screen);
    $hasHd44780 = isset($objects['marlinui_HD44780.cpp.o']) || isset($objects['ultralcd_HD44780.cpp.o']);
    $hasDogm = isset($objects['marlinui_DOGM.cpp.o']) || isset($objects['ultralcd_DOGM.cpp.o']);
    if ($type === 'char20x4') {
        $checks[] = ['name' => 'Selected character LCD define enabled',
                     'pass' => $define !== null && (bool)($doc['defines'][$define]['enabled'] ?? false)];
        $checks[] = ['name' => 'HD44780 character LCD object', 'pass' => $hasHd44780];
        $checks[] = ['name' => 'No conflicting DOGM object', 'pass' => !$hasDogm];
    } elseif (in_array($type, ['mono128x64', 'marlinui_tft'], true)) {
        $checks[] = ['name' => 'Selected graphical LCD define enabled',
                     'pass' => $define !== null && (bool)($doc['defines'][$define]['enabled'] ?? false)];
        $checks[] = ['name' => 'DOGM full-graphic LCD object', 'pass' => $hasDogm];
        $checks[] = ['name' => 'No conflicting HD44780 object', 'pass' => !$hasHd44780];
    } elseif (in_array($type, ['none', 'serial_tft'], true)) {
        $checks[] = ['name' => 'No MarlinUI LCD object compiled', 'pass' => !$hasHd44780 && !$hasDogm];
    }

    if (in_array($type, ['serial_tft', 'marlinui_tft'], true)) {
        $serial = $enabledValue('SERIAL_PORT');
        $baud = $enabledValue('BAUDRATE');
        $usb = $enabledValue('SERIAL_PORT_2');
        $baud2 = $enabledValue('BAUDRATE_2');
        $checks[] = ['name' => 'TFT UART enabled', 'pass' => $serial !== null && $serial !== '-1'];
        $checks[] = ['name' => 'TFT UART baud enabled', 'pass' => $baud !== null && (int)$baud > 0];
        $checks[] = ['name' => 'USB serial enabled on secondary port', 'pass' => $usb === '-1'];
        $checks[] = ['name' => 'USB secondary baud enabled', 'pass' => $baud2 !== null && (int)$baud2 > 0];
    }

    $failed = array_values(array_filter($checks, fn ($c) => !($c['pass'] ?? false)));
    return [
        'ok' => $failed === [],
        'type' => $type,
        'checks' => $checks,
        'detail' => $failed === [] ? '' : implode('; ', array_column($failed, 'name')),
    ];
}

function compiler_manifest_write(string $path, array $manifest): bool
{
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && @file_put_contents($path, $json . "\n") !== false;
}

// Fatal-error trap: restore source and never leave a build frozen if PHP dies.
register_shutdown_function(function () use ($buildId): void {
    source_patch_restore_all();
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
    if ($conf !== null) $conf = max(0, min(100, $conf));
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

// Serialize builds that share one imported source tree. A heartbeat keeps the
// stale-build sweeper from killing a worker while it waits for another compile.
$buildLockPath = project_dir((int)$project['id']) . '/.compiler.lock';
$buildLock = @fopen($buildLockPath, 'c+');
$lockAcquired = false;
$lockDeadline = time() + 2400;
$nextLockLog = 0;
while ($buildLock !== false && time() < $lockDeadline) {
    if (@flock($buildLock, LOCK_EX | LOCK_NB)) {
        $lockAcquired = true;
        break;
    }
    if (time() >= $nextLockLog) {
        blog('Waiting for the previous compiler job on this project to finish…');
        $nextLockLog = time() + 60;
    }
    sleep(5);
}
if (!$lockAcquired) {
    gate('worker_lock', 'Compiler source lock acquired', 0, false, 'Timed out waiting for another project build');
    bstate('failed', 0, true);
    exit(0);
}

/* --------------------------------------------------- Klipper pipeline */

if ($project['firmware'] === 'reprap') {
    /* ---------------------------------------- RepRapFirmware: no compile.
       RRF is configured at runtime by config.g, and TeamGloomy ships prebuilt
       per-board binaries. The "build" is: validate -> generate config.g ->
       resolve and fetch the matching firmware asset. */
    $confidence = 0;

    $stmt = db()->prepare('SELECT field_key, field_value FROM config_values WHERE project_id = ?');
    $stmt->execute([(int)$project['id']]);
    $saved = [];
    foreach ($stmt->fetchAll() as $r) {
        $saved[$r['field_key']] = $r['field_value'];
    }
    if ($saved === []) {
        gate('s1_valid', 'Configuration submitted and valid', 40, false, 'No configuration saved — submit the Configuration form first');
        bstate('failed', 0, true);
        exit(0);
    }

    $fields = rrf_field_defs($board);
    [$vals, $errors] = hf_validate_fields($fields, $saved);
    $detail = $errors === [] ? '' : implode('; ', array_map(
        fn ($k, $m) => "{$k}: {$m}", array_keys($errors), array_values($errors)));
    $confidence += gate('s1_valid', 'Configuration values valid and within limits', 40, $errors === [], $detail);
    if ($errors !== []) {
        bstate('failed', $confidence, true);
        exit(0);
    }

    $ok = board_supports_rrf($board);
    $confidence += gate('s2_board', 'Board is supported by RepRapFirmware', 20, $ok,
        $ok ? '' : (string)($board['rrf']['note'] ?? 'TeamGloomy does not publish an RRF build for this board.'));
    if (!$ok) {
        bstate('failed', $confidence, true);
        exit(0);
    }

    $cfg     = rrf_configg_generate($board, $vals);
    $cfgPath = $buildDir . '/config.g';
    $wrote   = @file_put_contents($cfgPath, $cfg) !== false;
    $confidence += gate('s3_configg', 'config.g generated', 20, $wrote,
        $wrote ? strlen($cfg) . ' bytes' : 'Could not write config.g');
    if (!$wrote) {
        bstate('failed', $confidence, true);
        exit(0);
    }

    blog('Looking up the latest TeamGloomy release for this board…');
    $asset = rrf_resolve_asset($board);
    if (isset($asset['error'])) {
        // Print what WAS published so the board's pattern can be corrected.
        if (!empty($asset['seen'])) {
            blog('Assets published in the checked releases:');
            foreach ($asset['seen'] as $n) {
                blog('   ' . $n);
            }
        }
        gate('s4_firmware', 'Prebuilt firmware resolved and downloaded', 20, false,
             $asset['error'] . ' — your config.g is still available below.');
        bstate('failed', $confidence, true);
        exit(0);
    }

    blog('Release ' . $asset['tag'] . ' — ' . $asset['name']
         . ' (' . number_format((float)$asset['size']) . ' bytes, from ' . $asset['from'] . ')');

    if (isset($asset['data'])) {
        // Came from inside a release zip - already in memory.
        $bin = (string)$asset['data'];
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 180,
                                                 'header' => "User-Agent: HotFetched\r\n"]]);
        $bin = @file_get_contents((string)$asset['url'], false, $ctx);
    }
    $got = ($bin !== false && strlen($bin) > 1024);
    $confidence += gate('s4_firmware', 'Prebuilt firmware resolved and downloaded', 20, $got,
        $got ? $asset['name'] : 'Could not download ' . $asset['name']);
    if (!$got) {
        bstate('failed', $confidence, true);
        exit(0);
    }

    $fwPath = $buildDir . '/firmware.bin';
    $fwBytes = @file_put_contents($fwPath, $bin);
    if ($fwBytes === false || $fwBytes < 1024 || !is_file($fwPath)) {
        gate('s4_artifact', 'Firmware artifact written', 0, false, 'Downloaded firmware could not be saved');
        bstate('failed', $confidence, true);
        exit(0);
    }
    db()->prepare('UPDATE builds SET artifact_path = ? WHERE id = ?')->execute([$fwPath, $buildId]);
    blog('firmware.bin: ' . number_format((float)$fwBytes) . ' bytes (RRF ' . $asset['tag'] . ')');

    $zip = new ZipArchive();
    if ($zip->open($buildDir . '/config-bundle.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $zip->addFile($cfgPath, 'sys/config.g');
        $zip->addFile($fwPath, 'firmware.bin');
        $zip->close();
    }

    blog('Flash: copy firmware.bin to the SD card root, and config.g into /sys on the same card, then power-cycle.');
    bstate('success', $confidence, true);
    exit(0);
}

if ($project['firmware'] === 'klipper') {
    $stmt = db()->prepare('SELECT field_key, field_value FROM config_values WHERE project_id = ?');
    $stmt->execute([(int)$project['id']]);
    $saved = [];
    foreach ($stmt->fetchAll() as $r) {
        $saved[$r['field_key']] = $r['field_value'];
    }
    if ($saved === []) {
        gate('s1_present', 'Configuration submitted', 40, false, 'No configuration saved — submit the Configuration form first');
        bstate('failed', 0, true);
        exit(0);
    }
    $fields = klipper_field_defs($board);
    [$vals, $errors] = hf_validate_fields($fields, $saved);
    $confidence += gate('s1_valid', 'Configuration values valid and within limits', 40, $errors === [],
        implode('; ', array_map(fn ($k, $m) => "$k: $m", array_keys($errors), $errors)));
    if ($confidence < 40) {
        bstate('failed', $confidence, true);
        exit(0);
    }

    $detect = json_decode((string)$project['source_detect'], true);
    $root   = realpath(project_source_dir((int)$project['id']));
    $tree   = $root . (($detect['root'] ?? '') !== '' ? '/' . $detect['root'] : '');
    $refRel = (string)($board['klipper']['reference_config'] ?? '');
    $refTxt = @file_get_contents($tree . '/config/' . $refRel);
    $seed   = klipper_config_seed($board, $variant);

    $confidence += gate('s2_ref', 'Board reference printer.cfg present', 10, is_string($refTxt) && $refTxt !== '',
        is_string($refTxt) ? '' : 'config/' . $refRel . ' not found in source tree');
    $confidence += gate('s2_seed', 'MCU firmware options resolve for this board', 10, $seed !== null,
        $seed !== null ? '' : 'Board definition lacks a Klipper config seed');
    if ($confidence < 60) {
        bstate('failed', $confidence, true);
        exit(0);
    }

    bstate('building', $confidence);
    $klipperConfig = $tree . '/.config';
    $klipperConfigExisted = is_file($klipperConfig);
    if ($klipperConfigExisted) source_patch_backup($klipperConfig);
    $configWrote = @file_put_contents($klipperConfig, $seed) !== false;
    blog('Klipper .config seed:');
    foreach (explode("\n", trim((string)$seed)) as $line) blog('  ' . $line);

    // Remove every prior output before compiling so an old klipper.bin cannot be
    // mistaken for a successful build after a compiler failure.
    build_tree_remove($tree . '/out');
    $compileStarted = time();
    $cmd = 'cd ' . escapeshellarg($tree)
         . ' && env HOME=/tmp timeout 900 sh -c ' . escapeshellarg('make olddefconfig && make -j2')
         . ' >> ' . escapeshellarg($logPath) . ' 2>&1; echo $?';
    $exit = $configWrote ? (int)trim((string)shell_exec($cmd)) : 1;

    $artifact = (string)($board['klipper']['artifact'] ?? 'klipper.bin');
    $bin = $tree . '/out/' . $artifact;
    $compilerOk = $configWrote && $exit === 0 && is_file($bin) && (int)@filesize($bin) > 0
        && (int)@filemtime($bin) >= $compileStarted - 2;
    $compileDetail = '';
    if (!$compilerOk) {
        $tail = (string)@shell_exec('grep -iE "error|failed" ' . escapeshellarg($logPath) . ' | tail -8');
        $compileDetail = !$configWrote ? 'Could not write Klipper .config'
            : ($exit === 124 ? 'Build timed out (15 min)'
            : ((trim($tail) !== '') ? trim($tail) : 'make exited ' . $exit . ' or produced no fresh artifact'));
    }
    $confidence += gate('s3_make', 'Clean Klipper MCU compile', 30, $compilerOk, $compileDetail);

    $dest = $buildDir . '/' . $artifact;
    @unlink($dest);
    $artifactOk = $compilerOk && @copy($bin, $dest) && is_file($dest)
        && (int)@filesize($dest) === (int)@filesize($bin);
    $artifactDetail = $artifactOk
        ? $artifact . ', ' . number_format((float)filesize($dest)) . ' bytes, SHA-256 ' . hash_file('sha256', $dest)
        : 'Klipper artifact copy or size verification failed';
    $confidence += gate('s3_artifact', 'Fresh Klipper artifact exported and verified', 5, $artifactOk, $artifactDetail);

    $manifestPath = $buildDir . '/compiler-manifest.json';
    $manifest = [
        'hotfetched_version' => defined('HF_VERSION') ? HF_VERSION : null,
        'build_id' => $buildId,
        'built_at_utc' => gmdate('c'),
        'firmware' => 'klipper',
        'board' => ['id' => (string)$board['id'], 'name' => (string)$board['name']],
        'mcu' => [
            'variant_id' => (string)($variant['id'] ?? ''),
            'label' => (string)($variant['label'] ?? ''),
            'klipper_mcu' => (string)($variant['klipper_mcu'] ?? ''),
            'klipper_machine' => (string)($variant['klipper_mach'] ?? ''),
        ],
        'artifact' => $artifactOk ? [
            'filename' => $artifact,
            'bytes' => (int)filesize($dest),
            'sha256' => hash_file('sha256', $dest),
        ] : null,
        'compiler' => ['exit_code' => $exit, 'clean_output_directory' => true],
    ];
    $manifestOk = compiler_manifest_write($manifestPath, $manifest);
    $boardPostOk = $compilerOk && $artifactOk && $manifestOk
        && (string)($variant['klipper_mcu'] ?? '') !== '';
    $confidence += gate('s3_board', 'Klipper board and MCU target verified after compilation', 5, $boardPostOk,
        $boardPostOk ? 'MCU seed, fresh artifact, and compiler manifest verified' : 'Klipper board/MCU post-build verification failed');

    $built = $compilerOk && $artifactOk && $boardPostOk;
    if ($built) {
        db()->prepare('UPDATE builds SET artifact_path = ? WHERE id = ?')->execute([$dest, $buildId]);
        blog($artifact . ': ' . number_format((float)filesize($dest)) . ' bytes — ' . (string)($board['klipper']['flash_note'] ?? ''));

        $printerCfg = klipper_generate_printer_cfg((string)$refTxt, $vals);
        @file_put_contents($buildDir . '/printer.cfg', $printerCfg);
        $zip = new ZipArchive();
        if ($zip->open($buildDir . '/config-bundle.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('printer.cfg', $printerCfg);
            $zip->addFromString('klipper.config', (string)$seed);
            $zip->addFile($manifestPath, 'compiler-manifest.json');
            $zip->addFile($logPath, 'build.log');
            $zip->addFromString('FLASHING.txt', (string)($board['klipper']['flash_note'] ?? '') . "\nHost side: place printer.cfg in your Klipper host config directory and set the [mcu] serial.");
            $zip->close();
        }
    }
    build_tree_remove($tree . '/out');
    if (!$klipperConfigExisted) @unlink($klipperConfig);
    bstate($built ? 'success' : 'failed', $confidence, true);
    db()->prepare("UPDATE projects SET updated_at = datetime('now') WHERE id = ?")->execute([(int)$project['id']]);
    exit(0);
}

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

$fields = array_merge(marlin_field_defs($board), marlin_field_defs_motion($board),
                      marlin_field_defs_adv($board), marlin_field_defs_tier2($board),
                      marlin_field_defs_leveling($board), marlin_field_defs_wifi($board),
                      marlin_field_defs_mmu($board), marlin_field_defs_tier3($board),
                      marlin_field_defs_tier4($board), marlin_field_defs_extended($board));
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
// FT_MOTION is an alternative motion engine with its OWN input shapers. Running
// it alongside INPUT_SHAPING_X/Y would shape the motion twice.
if (($vals['ft_motion'] ?? '0') === '1'
    && (($vals['shaping_x'] ?? '0') === '1' || ($vals['shaping_y'] ?? '0') === '1')) {
    $conflicts[] = 'Fixed-Time Motion has its own input shapers - turn off the separate Input Shaping X/Y (or turn off FT Motion). Using both would shape the motion twice';
}
// Backlash compensation with all-zero distances does nothing - flag the mistake.
if (($vals['backlash'] ?? '0') === '1'
    && (float)($vals['backlash_x'] ?? 0) === 0.0
    && (float)($vals['backlash_y'] ?? 0) === 0.0
    && (float)($vals['backlash_z'] ?? 0) === 0.0) {
    $conflicts[] = 'Backlash compensation is on but every axis distance is 0 - measure your backlash and enter it, or turn the feature off';
}

// G34 auto-align probes the bed, so it needs a probe.
if ((string)($vals['z_align'] ?? 'none') === 'auto_align' && ($vals['probe'] ?? 'none') === 'none') {
    $conflicts[] = 'G34 Z auto-align uses the probe to measure each Z motor - select a bed probe, or use independent Z endstops instead';
}
// BABYSTEP_ZPROBE_OFFSET needs a probe and is invalid with manual mesh levelling.
if (($vals['babystep_zprobe'] ?? '0') === '1') {
    if (($vals['probe'] ?? 'none') === 'none') {
        $conflicts[] = 'Babystepping the probe Z-offset requires a bed probe';
    }
    if ((string)($vals['leveling'] ?? 'none') === 'mesh') {
        $conflicts[] = 'Babystepping the probe Z-offset cannot be combined with Manual Mesh levelling';
    }
}

// MMU_MENUS and STARTUP_TUNE both need a MarlinUI display: the menus need
// HAS_MARLINUI_MENU, and BEEPER_PIN lives on the LCD's EXP header. An
// external-firmware TFT (BTT touch mode) or no display provides neither.
$uiPresent = marlin_screen_has_marlinui($board, (string)($vals['screen'] ?? ''));
if (!$uiPresent) {
    if (($vals['mmu_menus'] ?? '0') === '1') {
        $conflicts[] = 'The MMU LCD menu needs a MarlinUI display - your selected screen runs its own firmware (or none), so Marlin has no menu to add it to. Untick it, or pick a Marlin-native LCD';
    }
    $st = (string)($vals['startup_tune'] ?? 'keep');
    if ($st !== 'keep' && $st !== 'silent') {
        $conflicts[] = 'A power-on tune needs a beeper on the LCD EXP header - your selected screen provides none. Set the power-on tune to "Keep current" or "Silent", or pick a Marlin-native LCD';
    }
} elseif (($vals['speaker'] ?? '0') !== '1') {
    $st = (string)($vals['startup_tune'] ?? 'keep');
    if ($st !== 'keep' && $st !== 'silent') {
        $conflicts[] = 'A power-on tune requires SPEAKER to be enabled - tick "Speaker fitted", or set the tune to "Keep current"/"Silent"';
    }
}

// Without an MMU, each extruder needs a real E driver slot on the board.
// (With an MMU the extra "extruders" are logical tools driven by one E motor.)
$mmuSel = (string)($vals['mmu_model'] ?? 'none');
$mmuMulti = in_array($mmuSel, ['PRUSA_MMU2', 'PRUSA_MMU2S', 'PRUSA_MMU3',
                               'EXTENDABLE_EMU_MMU2', 'EXTENDABLE_EMU_MMU2S'], true);
$eSlotCount = count(array_filter($board['marlin']['driver_slots'] ?? [],
                                 fn ($s) => str_starts_with(strtoupper((string)$s), 'E')));
$nExtSel = (int)($vals['extruders'] ?? 1);
if (!$mmuMulti && $eSlotCount > 0 && $nExtSel > $eSlotCount) {
    $conflicts[] = "This board has only {$eSlotCount} E driver slot(s), so it cannot drive {$nExtSel} extruders - reduce the count, or use a multi-material unit (which drives many tools from one E motor)";
}

// Standard Prusa MMUs use 5 tools.
// PRUSA_MMU3 may also use 12 tools with the custom MMU-12x firmware.
$mmu = (string)($vals['mmu_model'] ?? 'none');

if (marlin_mmu_needs_5($mmu)) {
    $allowedExtruders = $mmu === 'PRUSA_MMU3'
        ? [5, 12]
        : [5];

    if (!in_array($nExtSel, $allowedExtruders, true)) {
        $conflicts[] = $mmu === 'PRUSA_MMU3'
            ? 'PRUSA_MMU3 requires 5 extruders, or 12 when using the custom MMU-12x firmware'
            : "{$mmu} is a 5-port unit and requires exactly 5 extruders - set Extruders to 5";
    }
}
if ($mmu !== 'none' && $mmu !== 'PRUSA_MMU1' && (int)($vals['extruders'] ?? 1) < 2) {
    $conflicts[] = 'A multi-material unit needs more than one extruder - raise the Extruders count';
}

// TEMP_SENSOR_0 = 0 means "not used" in Marlin -> HOTENDS becomes 0, and every
// hotend function (wait_for_hotend, setTargetHotend, TEMP_WINDOW...) disappears.
// Anything needing a hotend then fails deep in the compile, so catch it here.
$ts0 = (int)($vals['temp_sensor_0'] ?? 1);
$exs = (int)($vals['extruders'] ?? 1);
if ($ts0 === 0 && $exs >= 1) {
    $conflicts[] = 'Hotend temp sensor (TEMP_SENSOR_0) is 0, which means "no sensor" - Marlin then builds with zero hotends and features like filament runout, PID and linear advance cannot compile. Set a real sensor type (1 = generic 100k thermistor).';
}

// Probe-based leveling needs an actual probe. We can't infer the user's hardware,
// so fail fast here rather than letting the compiler do it 90 seconds later.
$lvl = (string)($vals['leveling'] ?? 'none');
if (marlin_leveling_needs_probe($lvl) && ($vals['probe'] ?? 'none') === 'none') {
    $conflicts[] = "Bed leveling '{$lvl}' needs a bed probe - select a probe, or use Manual Mesh which needs none";
}
if ($lvl === 'ubl' && ($vals['eeprom'] ?? '0') !== '1') {
    // Auto-enabled on apply, but surface it so the user knows why EEPROM turned on.
    blog('Note: UBL requires EEPROM_SETTINGS - enabling it automatically.');
}
if (($vals['singlenozzle'] ?? '0') === '1' && (int)($vals['extruders'] ?? 1) < 2) {
    $conflicts[] = 'SINGLENOZZLE requires 2 or more extruders';
}
if ((int)($vals['homing_xy'] ?? 0) > (int)($vals['feed_x'] ?? PHP_INT_MAX)) {
    $conflicts[] = 'Homing XY speed exceeds max feedrate X';
}
// Per-sensor max temps extracted from Marlin's own thermistor tables
// (thermistor_N.h, highest table entry). Mirrors the compiler's
// static_assert: HEATER_0_MAXTEMP + HOTEND_OVERSHOOT (15) must fit the
// table. High-temp sensors (Dyze 66: 850C, Slice 67: 500C, ...) pass
// at their real limits; unknown/thermocouple ids are left to Marlin.
$thermalOverride = ($saved['thermal_override'] ?? '0') === '1';
$thermistorTableMax = [1 => 320, 2 => 848, 3 => 864, 4 => 430, 5 => 713, 6 => 350, 7 => 941, 8 => 704, 9 => 936, 10 => 929, 11 => 938, 12 => 180, 13 => 300, 14 => 275, 15 => 275, 17 => 309, 18 => 713, 20 => 1100, 21 => 500, 22 => 352, 23 => 938, 30 => 938, 51 => 350, 52 => 500, 55 => 500, 60 => 272, 61 => 420, 66 => 850, 67 => 500, 68 => 500, 70 => 270, 71 => 300, 75 => 200, 99 => 350, 201 => 490, 202 => 864, 331 => 300, 332 => 150, 501 => 713, 502 => 300, 503 => 300, 504 => 330, 505 => 938, 512 => 300, 666 => 794, 2000 => 125];
$sensor0 = (int)($vals['temp_sensor_0'] ?? 0);
$maxT    = (int)($vals['hotend_maxtemp'] ?? 0);
if (!$thermalOverride && isset($thermistorTableMax[$sensor0]) && $maxT + 15 > $thermistorTableMax[$sensor0]) {
    $allowed = $thermistorTableMax[$sensor0] - 15;
    $conflicts[] = "Nozzle max temp {$maxT}C + 15C overshoot exceeds thermistor table {$sensor0} (max {$thermistorTableMax[$sensor0]}C) - set {$allowed}C or lower, pick a higher-rated sensor, or enable the thermal override";
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

// HotFetched v3.7.2 bootscreen safety net. The UI can preserve old database
// values, so sanitize the actual source tree immediately before compilation.
if ($doc !== null && $confPath !== false) {
    $selectedScreen = (string)($vals['screen'] ?? 'none');
    $selectedType = 'none';
    foreach (($board['marlin']['screens'] ?? []) as $s) {
        if ((string)($s['id'] ?? '') === $selectedScreen) {
            $selectedType = (string)($s['type'] ?? 'none');
            break;
        }
    }
    $bitmapCapable = in_array($selectedType, ['mono128x64', 'marlinui_tft'], true);
    $showBoot = (($vals['show_bootscreen'] ?? '0') === '1');
    $marlinDir = dirname((string)$confPath);
    $bootHeader = $marlinDir . '/_Bootscreen.h';
    $statusHeader = $marlinDir . '/_Statusscreen.h';
    $changed = [];

    $forceEnabled = function (string $key, bool $enable) use (&$doc, &$changed): void {
        if (!isset($doc['defines'][$key])) return;
        $current = (bool)($doc['defines'][$key]['enabled'] ?? false);
        if ($current === $enable) return;
        $value = $doc['defines'][$key]['value'] ?? null;
        if (marlin_config_set($doc, $key, $value, $enable)) $changed[] = $key;
    };

    if (!$showBoot) $forceEnabled('SHOW_BOOTSCREEN', false);
    if (!$showBoot || !$bitmapCapable || !is_file($bootHeader)) {
        $forceEnabled('SHOW_CUSTOM_BOOTSCREEN', false);
    }
    if (!$bitmapCapable || !is_file($statusHeader)) {
        $forceEnabled('CUSTOM_STATUS_SCREEN_IMAGE', false);
    }

    if ($changed !== []) {
        $ok = source_patch_backup((string)$confPath)
           && marlin_config_write($doc, (string)$confPath);
        blog($ok
            ? 'Bootscreen sanitizer: disabled incompatible/stale ' . implode(', ', $changed)
            : 'Bootscreen sanitizer failure: could not update Configuration.h');
        if ($ok) $doc = marlin_config_parse((string)$confPath);
    }
}

// A project saved before MMU12 support may still contain PRUSA_MMU3 in
// Configuration.h. Correct it here as well as on form save so the next build
// works immediately after replacing the webroot files.
$mmuConfigWriteOk = true;
$effectiveMmu = marlin_effective_mmu_model($vals);
if ($doc !== null && $effectiveMmu === 'EXTENDABLE_EMU_MMU3') {
    $currentMmu = trim((string)($doc['defines']['MMU_MODEL']['value'] ?? ''));
    if ($currentMmu !== $effectiveMmu) {
        $mmuConfigWriteOk = $confPath !== false && source_patch_backup((string)$confPath);
        if ($mmuConfigWriteOk) {
            marlin_config_set($doc, 'MMU_MODEL', $effectiveMmu, true);
            $mmuConfigWriteOk = marlin_config_write($doc, (string)$confPath);
        }
        blog($mmuConfigWriteOk
            ? 'MMU12 config: MMU_MODEL set to EXTENDABLE_EMU_MMU3'
            : 'MMU12 config failure: could not update Configuration.h');
    }
}

$confidence += gate('s2_parse', 'Configuration files parse cleanly', 5, $doc !== null && $docAdv !== null,
    $doc === null ? 'Configuration.h unreadable' : ($docAdv === null ? 'Configuration_adv.h unreadable' : ''));

$mbOk = false;
$mbDetail = '';
$expectedMb = (string)$board['marlin']['motherboard'];
if ($doc !== null) {
    $mb = $doc['defines']['MOTHERBOARD'] ?? null;
    $mbOk = $mb !== null && $mb['enabled'] && trim((string)$mb['value']) === $expectedMb;
    $mbDetail = $mbOk ? '' : 'MOTHERBOARD is ' . trim((string)($mb['value'] ?? '(unset)')) . ', expected ' . $expectedMb . ' — re-submit the Configuration form';
}
$confidence += gate('s2_board', 'MOTHERBOARD matches selected board', 5, $mbOk, $mbDetail);

// The imported source must actually define this board symbol, or the compile
// dies deep in pins.h with a cryptic "Unknown MOTHERBOARD" #error. Verify the
// symbol exists in the tree's boards.h so we fail here, clearly, instead.
$boardsH = $root !== false ? $root . '/Marlin/src/core/boards.h' : '';
$treeHasBoard = false;
if (is_file($boardsH)) {
    $bh = (string)@file_get_contents($boardsH);
    $treeHasBoard = preg_match('/^\s*#define\s+' . preg_quote($expectedMb, '/') . '\s+\d+/m', $bh) === 1;
}
$minMarlin = (string)($board['min_marlin'] ?? '');
$verHint = $minMarlin !== '' ? "Marlin {$minMarlin}+ (or bugfix-2.1.x)" : 'a newer Marlin (2.1.2+ / bugfix-2.1.x)';
$treeDetail = $treeHasBoard ? '' : $expectedMb . " is not defined in this Marlin source. This board needs {$verHint}. Import a matching source via Replace source, or pick a board this tree supports.";
$confidence += gate('s2_treeboard', 'Board is supported by the imported Marlin version', 3, $treeHasBoard, $treeDetail);

$treeRoot = $root . ($detect['root'] !== '' ? '/' . $detect['root'] : '');
$envResolution = marlin_resolve_environment($treeRoot, $variant);
$envDetail = '';
if ($envResolution['ok']) {
    $envDetail = $envResolution['used_alias']
        ? 'Profile requested ' . $envResolution['requested'] . '; imported source provides ' . $envResolution['resolved']
        : (string)$envResolution['resolved'];
} else {
    $sample = array_slice($envResolution['available'], 0, 12);
    $envDetail = 'Environment ' . ($envResolution['requested'] !== '' ? $envResolution['requested'] : '(unset)')
        . ' is not declared by this source tree'
        . ($sample !== [] ? '. Available examples: ' . implode(', ', $sample) : '. No PlatformIO environments were discovered.');
}
$confidence += gate('s2_env', 'Selected MCU compiler environment exists', 2, (bool)$envResolution['ok'], $envDetail);

/* The selected MMU model must exist in the IMPORTED tree. Marlin resolves it by
   token-pasting (_MMU = CAT(_, MMU_MODEL)); if the tree predates the model, the
   symbol is undefined, silently evaluates to 0, and E_STEPPERS falls back to
   EXTRUDERS - producing bogus "E2_STEP_PIN not defined" errors instead of an
   honest "unsupported" message. PRUSA_MMU3 landed in Marlin 2.1.3 / bugfix-2.1.x. */
$mmuPick = marlin_effective_mmu_model($vals);
$mmu12Prepare = null;
if ($mmuPick === 'EXTENDABLE_EMU_MMU3') {
    foreach (['Conditionals-1-axes.h', 'Conditionals_post.h'] as $candidate) {
        $candidatePath = $treeRoot . '/Marlin/src/inc/' . $candidate;
        if (is_file($candidatePath)) source_patch_backup($candidatePath);
    }
    $mmu12Prepare = marlin_enable_extendable_mmu3($treeRoot);
    blog(($mmu12Prepare['ok'] ? 'MMU12 source: ' : 'MMU12 source failure: ') . $mmu12Prepare['detail']);
}
if ($mmuPick !== 'none') {
    $condPath = $treeRoot . '/Marlin/src/inc/Conditionals-1-axes.h';
    $condTxt  = @file_get_contents($condPath);
    if ($condTxt === false) {
        // Older trees keep this in Conditionals_post.h
        $condTxt = (string)@file_get_contents(
            $treeRoot . '/Marlin/src/inc/Conditionals_post.h');
    }
    $mmuKnown = $condTxt !== '' && str_contains((string)$condTxt, '_' . $mmuPick . ' ');
    if ($mmuPick === 'EXTENDABLE_EMU_MMU3'
        && (!($mmu12Prepare['ok'] ?? false) || !$mmuConfigWriteOk)) {
        $mmuKnown = false;
    }
    $mmuDetail = $mmuKnown
        ? (($mmu12Prepare['changed'] ?? false) ? (string)$mmu12Prepare['detail'] : '')
        : (!$mmuConfigWriteOk
            ? 'Could not update Configuration.h for the 12-tool MMU3 model.'
            : (($mmu12Prepare['detail'] ?? '') !== ''
                ? (string)$mmu12Prepare['detail']
                : "The imported Marlin version does not know {$mmuPick}. "
                  . ($mmuPick === 'PRUSA_MMU3'
                     ? 'MMU3 needs Marlin 2.1.3 or newer - re-import using the bugfix-2.1.x branch.'
                     : 'Import a newer Marlin, or pick an MMU model this version supports.')));
    $confidence += gate('s2_mmu', 'MMU model is supported by the imported Marlin version', 5, $mmuKnown, $mmuDetail);
} else {
    $confidence += gate('s2_mmu', 'MMU model is supported by the imported Marlin version', 5, true, 'No MMU selected');
}

if ($confidence < 60) {
    bstate('failed', $confidence, true);
    exit(0);
}

/* ----------------------------------------------- Stage 3: compile (40) */

$env = (string)($envResolution['resolved'] ?? '');
$pio = '/opt/pio-venv/bin/pio';
if (!is_executable($pio)) {
    $pio = trim((string)shell_exec('command -v pio'));
}

if ($env === '' || $pio === '') {
    $confidence += gate('s3_compile', 'PlatformIO compile', 25, false, 'PlatformIO or environment unavailable');
    bstate('failed', $confidence, true);
    exit(0);
}

bstate('building', $confidence);

// Thermal override: the user's word is final. Neuter Marlin's compile-time
// thermistor-table asserts in the imported tree so the build proceeds at
// the configured temperatures.
if ($thermalOverride) {
    $tempCpp = $root . ($detect['root'] !== '' ? '/' . $detect['root'] : '') . '/Marlin/src/module/temperature.cpp';
    $src = @file_get_contents($tempCpp);
    if ($src !== false && !str_contains($src, 'HotFetched thermal override')) {
        if (!source_patch_backup($tempCpp)) {
            blog('Thermal override: source backup failed; refusing to mutate the imported tree.');
            $confidence += gate('s3_compile', 'PlatformIO compile produces firmware', 25, false, 'Could not back up temperature.cpp before thermal override');
            bstate('failed', $confidence, true);
            exit(0);
        }
        $patched = preg_replace(
            '/#define CHECK_MAXTEMP_\(N,M,S\) static_assert\(.*?;/s',
            '#define CHECK_MAXTEMP_(N,M,S) static_assert(true, "HotFetched thermal override");',
            $src,
            1,
            $n
        );
        if ($n === 1 && @file_put_contents($tempCpp, $patched) !== false) {
            blog('Thermal override: compile-time thermistor-table checks disabled in temperature.cpp.');
        } else {
            blog('Thermal override: assert pattern not found (custom tree?) - proceeding unpatched.');
        }
    } elseif ($src !== false) {
        blog('Thermal override: checks already disabled in this tree.');
    }
}

$defValue = static function (?array $entry, string $fallback = 'disabled'): string {
    if ($entry === null || !($entry['enabled'] ?? false)) return $fallback;
    $value = trim((string)($entry['value'] ?? ''));
    return $value !== '' ? $value : 'enabled';
};
$activeDisplays = [];
foreach (($board['marlin']['screens'] ?? []) as $screenDef) {
    $displayDefine = marlin_screen_define($screenDef);
    if ($displayDefine !== null && ($doc['defines'][$displayDefine]['enabled'] ?? false)) {
        $activeDisplays[$displayDefine] = true;
    }
}
blog('Interface: SERIAL_PORT=' . $defValue($doc['defines']['SERIAL_PORT'] ?? null)
    . ', BAUDRATE=' . $defValue($doc['defines']['BAUDRATE'] ?? null)
    . ', SERIAL_PORT_2=' . $defValue($doc['defines']['SERIAL_PORT_2'] ?? null)
    . ', BAUDRATE_2=' . $defValue($doc['defines']['BAUDRATE_2'] ?? null));
blog('Display defines: ' . ($activeDisplays === [] ? 'none' : implode(', ', array_keys($activeDisplays))));
blog('MMU: model=' . $mmuPick
    . ', port=' . $defValue($docAdv['defines']['MMU_SERIAL_PORT'] ?? null)
    . ', baud=' . $defValue($docAdv['defines']['MMU_BAUD'] ?? null));
$selectedScreen = marlin_selected_screen($board, (string)($vals['screen'] ?? 'none'));
$selectedScreenDefine = marlin_screen_define($selectedScreen);
blog('Compiler target: board=' . (string)$board['id']
    . ', MCU=' . (string)($variant['id'] ?? '?')
    . ', environment=' . $env
    . ($envResolution['used_alias'] ? ' (resolved from ' . $envResolution['requested'] . ')' : ''));
blog('Screen target: ' . (string)($selectedScreen['label'] ?? $selectedScreen['id'] ?? 'none')
    . ', type=' . (string)($selectedScreen['type'] ?? 'none')
    . ($selectedScreenDefine !== null ? ', define=' . $selectedScreenDefine : ''));
blog("Compiling with PlatformIO env {$env} in a new isolated build directory.");

// Never reuse .pio objects from another screen, board, MCU, or build. PlatformIO
// packages remain cached globally, but compiler products are isolated per build.
$isolatedBuildRoot = $buildDir . '/pio-build';
build_tree_remove($isolatedBuildRoot);
@mkdir($isolatedBuildRoot, 0775, true);
foreach (['firmware.bin', 'firmware.hex', 'firmware.uf2', 'compiler-manifest.json'] as $oldArtifact) {
    @unlink($buildDir . '/' . $oldArtifact);
}
$compileStarted = time();
$projectRoot = $root . ($detect['root'] !== '' ? '/' . $detect['root'] : '');
$cmd = 'cd ' . escapeshellarg($projectRoot)
     . ' && env HOME=/tmp'
     . ' PLATFORMIO_CORE_DIR=' . escapeshellarg(getenv('PLATFORMIO_CORE_DIR') ?: '/opt/platformio')
     . ' PLATFORMIO_BUILD_DIR=' . escapeshellarg($isolatedBuildRoot)
     . ' PLATFORMIO_SETTING_ENABLE_TELEMETRY=No'
     . ' PLATFORMIO_DISABLE_UPGRADE_CHECK=Yes'
     . ' CCACHE_DIR=' . escapeshellarg(getenv('CCACHE_DIR') ?: '/opt/ccache')
     . ' timeout 2400 ' . escapeshellarg($pio) . ' run -e ' . escapeshellarg($env)
     . ' >> ' . escapeshellarg($logPath) . ' 2>&1; echo $?';

$exit = (int)trim((string)shell_exec($cmd));
$pioDir = $isolatedBuildRoot . '/' . $env;
$elfPath = $pioDir . '/firmware.elf';
$compilerOk = $exit === 0 && is_file($elfPath) && (int)@filesize($elfPath) > 0
    && (int)@filemtime($elfPath) >= $compileStarted - 2;
$compileDetail = '';
if (!$compilerOk) {
    $tail = (string)@shell_exec('grep -iE "error|#error|failed" ' . escapeshellarg($logPath) . ' | tail -10');
    $compileDetail = $exit === 124
        ? 'Compile timed out (40 min)'
        : ((trim($tail) !== '') ? trim($tail) : 'Compiler exited ' . $exit . ' or did not produce a fresh firmware.elf');
}
$confidence += gate('s3_compile', 'Clean isolated PlatformIO compile and link', 25, $compilerOk, $compileDetail);

// PlatformIO's output extension depends on the MCU family:
// STM32/LPC -> .bin, AVR -> .hex, RP2040 -> .uf2.
$fwSrc = '';
$fwName = '';
foreach (['firmware.bin', 'firmware.hex', 'firmware.uf2'] as $candidate) {
    $candidatePath = $pioDir . '/' . $candidate;
    if (is_file($candidatePath) && (int)@filesize($candidatePath) > 0
        && (int)@filemtime($candidatePath) >= $compileStarted - 2) {
        $fwSrc = $candidatePath;
        $fwName = $candidate;
        break;
    }
}
$artifactDest = $fwName !== '' ? $buildDir . '/' . $fwName : '';
$artifactOk = $compilerOk && $fwSrc !== '' && @copy($fwSrc, $artifactDest)
    && is_file($artifactDest) && (int)@filesize($artifactDest) === (int)@filesize($fwSrc);
$artifactDetail = $artifactOk
    ? $fwName . ', ' . number_format((float)filesize($artifactDest)) . ' bytes, SHA-256 ' . hash_file('sha256', $artifactDest)
    : ($fwSrc === '' ? 'No fresh firmware.bin/.hex/.uf2 was produced' : 'Firmware could not be copied or size verification failed');
$confidence += gate('s3_artifact', 'Fresh firmware artifact exported and verified', 5, $artifactOk, $artifactDetail);

$screenCheck = $compilerOk
    ? marlin_verify_compiled_screen($pioDir, $selectedScreen, $doc)
    : ['ok' => false, 'type' => (string)($selectedScreen['type'] ?? 'none'), 'checks' => [], 'detail' => 'Compiler did not finish'];
$screenDetail = $screenCheck['ok']
    ? 'Compiler output matches ' . (string)($selectedScreen['type'] ?? 'none')
    : (string)$screenCheck['detail'];
$confidence += gate('s3_screen', 'Selected screen and serial interfaces are present in compiler output', 5, (bool)$screenCheck['ok'], $screenDetail);

$boardPostOk = $compilerOk && is_dir($pioDir)
    && (string)($variant['id'] ?? '') !== ''
    && (string)($envResolution['resolved'] ?? '') === $env
    && $mbOk && $treeHasBoard;
$manifestPath = $buildDir . '/compiler-manifest.json';
$manifest = [
    'hotfetched_version' => defined('HF_VERSION') ? HF_VERSION : null,
    'build_id' => $buildId,
    'built_at_utc' => gmdate('c'),
    'firmware' => 'marlin',
    'board' => [
        'id' => (string)$board['id'],
        'name' => (string)$board['name'],
        'motherboard' => $expectedMb,
    ],
    'mcu' => [
        'variant_id' => (string)($variant['id'] ?? ''),
        'label' => (string)($variant['label'] ?? ''),
        'requested_environment' => (string)($envResolution['requested'] ?? ''),
        'resolved_environment' => $env,
        'used_environment_alias' => (bool)($envResolution['used_alias'] ?? false),
    ],
    'screen' => [
        'id' => (string)($selectedScreen['id'] ?? 'none'),
        'label' => (string)($selectedScreen['label'] ?? ''),
        'type' => (string)($selectedScreen['type'] ?? 'none'),
        'define' => $selectedScreenDefine,
        'verification' => $screenCheck,
    ],
    'serial' => [
        'SERIAL_PORT' => $defValue($doc['defines']['SERIAL_PORT'] ?? null),
        'BAUDRATE' => $defValue($doc['defines']['BAUDRATE'] ?? null),
        'SERIAL_PORT_2' => $defValue($doc['defines']['SERIAL_PORT_2'] ?? null),
        'BAUDRATE_2' => $defValue($doc['defines']['BAUDRATE_2'] ?? null),
    ],
    'artifact' => $artifactOk ? [
        'filename' => $fwName,
        'bytes' => (int)filesize($artifactDest),
        'sha256' => hash_file('sha256', $artifactDest),
    ] : null,
    'compiler' => [
        'platformio_environment' => $env,
        'exit_code' => $exit,
        'isolated_build' => true,
        'linked_elf_bytes' => is_file($elfPath) ? (int)filesize($elfPath) : 0,
    ],
];
$manifestOk = compiler_manifest_write($manifestPath, $manifest);
$boardPostOk = $boardPostOk && $manifestOk;
$boardPostDetail = $boardPostOk
    ? 'Board, MCU environment, MOTHERBOARD, linked ELF, and compiler manifest verified'
    : (!$manifestOk ? 'Compiler manifest could not be written' : 'Board/MCU/environment post-build verification failed');
$confidence += gate('s3_board', 'Board and MCU target verified after compilation', 5, $boardPostOk, $boardPostDetail);

$built = $compilerOk && $artifactOk && (bool)$screenCheck['ok'] && $boardPostOk;
if ($built) {
    db()->prepare('UPDATE builds SET artifact_path = ? WHERE id = ?')
        ->execute([$artifactDest, $buildId]);
    blog($fwName . ': ' . number_format((float)filesize($artifactDest)) . ' bytes');

    $zip = new ZipArchive();
    if ($zip->open($buildDir . '/config-bundle.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $zip->addFile((string)$confPath, 'Marlin/Configuration.h');
        $zip->addFile((string)$advPath, 'Marlin/Configuration_adv.h');
        $zip->addFile($manifestPath, 'compiler-manifest.json');
        $zip->addFile($logPath, 'build.log');
        $bs = dirname((string)$advPath) . '/_Bootscreen.h';
        if (is_file($bs)) $zip->addFile($bs, 'Marlin/_Bootscreen.h');
        $ss = dirname((string)$advPath) . '/_Statusscreen.h';
        if (is_file($ss)) $zip->addFile($ss, 'Marlin/_Statusscreen.h');
        $zip->close();
    }
}

// Compiler objects were inspected and recorded in compiler-manifest.json. Remove
// the per-build object tree to prevent disk growth and eliminate stale reuse.
build_tree_remove($isolatedBuildRoot);

bstate($built ? 'success' : 'failed', $confidence, true);
db()->prepare("UPDATE projects SET updated_at = datetime('now') WHERE id = ?")->execute([(int)$project['id']]);
exit(0);
