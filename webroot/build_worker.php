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
    @file_put_contents($fwPath, $bin);
    db()->prepare('UPDATE builds SET artifact_path = ? WHERE id = ?')->execute([$fwPath, $buildId]);
    blog('firmware.bin: ' . number_format(strlen($bin)) . ' bytes (RRF ' . $asset['tag'] . ')');

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
    @file_put_contents($tree . '/.config', $seed);
    blog('Klipper .config seed:');
    foreach (explode("\n", trim((string)$seed)) as $l) {
        blog('  ' . $l);
    }

    $cmd = 'cd ' . escapeshellarg($tree)
         . ' && env HOME=/tmp timeout 900 sh -c ' . escapeshellarg('make olddefconfig && make -j2')
         . ' >> ' . escapeshellarg($logPath) . ' 2>&1; echo $?';
    $exit = (int)trim((string)shell_exec($cmd));

    // Artifact name varies by MCU family: STM32/LPC -> klipper.bin,
    // RP2040 -> klipper.uf2, AVR -> klipper.elf.
    $artifact = (string)($board['klipper']['artifact'] ?? 'klipper.bin');
    $bin = $tree . '/out/' . $artifact;
    $built = $exit === 0 && is_file($bin);
    $detail = '';
    if (!$built) {
        $tail = (string)@shell_exec('grep -iE "error" ' . escapeshellarg($logPath) . ' | tail -6');
        $detail = $exit === 124 ? 'Build timed out (15 min)' : ((trim($tail) !== '') ? trim($tail) : 'make exited ' . $exit);
    }
    $confidence += gate('s3_make', 'Klipper MCU firmware compiles (' . $artifact . ')', 40, $built, $detail);

    if ($built) {
        @copy($bin, $buildDir . '/' . $artifact);
        // The download endpoint serves firmware by a stable name; keep the real
        // extension so users flash the right file (.uf2/.elf are not .bin).
        db()->prepare('UPDATE builds SET artifact_path = ? WHERE id = ?')
            ->execute([$buildDir . '/' . $artifact, $buildId]);
        blog($artifact . ': ' . number_format((float)filesize($bin)) . ' bytes — ' . (string)($board['klipper']['flash_note'] ?? ''));

        $printerCfg = klipper_generate_printer_cfg((string)$refTxt, $vals);
        @file_put_contents($buildDir . '/printer.cfg', $printerCfg);
        $zip = new ZipArchive();
        if ($zip->open($buildDir . '/config-bundle.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('printer.cfg', $printerCfg);
            $zip->addFromString('klipper.config', (string)$seed);
            $zip->addFromString('FLASHING.txt', (string)($board['klipper']['flash_note'] ?? '') . "\nHost side: place printer.cfg in your Klipper host config directory and set the [mcu] serial.");
            $zip->close();
        }
    }
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

// Prusa MMU2S/MMU3 are 5-port units: Marlin requires EXTRUDERS = 5 exactly.
$mmu = (string)($vals['mmu_model'] ?? 'none');
if (marlin_mmu_needs_5($mmu) && (int)($vals['extruders'] ?? 1) !== 5) {
    $conflicts[] = "{$mmu} is a 5-port unit and requires exactly 5 extruders - set Extruders to 5";
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

$confidence += gate('s2_parse', 'Configuration files parse cleanly', 10, $doc !== null && $docAdv !== null,
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
$confidence += gate('s2_treeboard', 'Board is supported by the imported Marlin version', 5, $treeHasBoard, $treeDetail);

/* The selected MMU model must exist in the IMPORTED tree. Marlin resolves it by
   token-pasting (_MMU = CAT(_, MMU_MODEL)); if the tree predates the model, the
   symbol is undefined, silently evaluates to 0, and E_STEPPERS falls back to
   EXTRUDERS - producing bogus "E2_STEP_PIN not defined" errors instead of an
   honest "unsupported" message. PRUSA_MMU3 landed in Marlin 2.1.3 / bugfix-2.1.x. */
$mmuPick = (string)($vals['mmu_model'] ?? 'none');
if ($mmuPick !== 'none') {
    $condPath = $root . ($detect['root'] !== '' ? '/' . $detect['root'] : '')
              . '/Marlin/src/inc/Conditionals-1-axes.h';
    $condTxt  = @file_get_contents($condPath);
    if ($condTxt === false) {
        // Older trees keep this in Conditionals_post.h
        $condTxt = (string)@file_get_contents(
            $root . ($detect['root'] !== '' ? '/' . $detect['root'] : '')
            . '/Marlin/src/inc/Conditionals_post.h');
    }
    $mmuKnown = $condTxt !== '' && str_contains((string)$condTxt, '_' . $mmuPick . ' ');
    $mmuDetail = $mmuKnown
        ? ''
        : "The imported Marlin version does not know {$mmuPick}. "
          . ($mmuPick === 'PRUSA_MMU3'
             ? 'MMU3 needs Marlin 2.1.3 or newer - re-import using the bugfix-2.1.x branch.'
             : 'Import a newer Marlin, or pick an MMU model this version supports.');
    $confidence += gate('s2_mmu', 'MMU model is supported by the imported Marlin version', 5, $mmuKnown, $mmuDetail);
} else {
    $confidence += gate('s2_mmu', 'MMU model is supported by the imported Marlin version', 5, true, 'No MMU selected');
}

if ($confidence < 60) {
    bstate('failed', $confidence, true);
    exit(0);
}

/* ----------------------------------------------- Stage 3: compile (40) */

if (!$thermalOverride) {
    $tempCppChk = $root . ($detect['root'] !== '' ? '/' . $detect['root'] : '') . '/Marlin/src/module/temperature.cpp';
    $chk = @file_get_contents($tempCppChk);
    if ($chk !== false && str_contains($chk, 'HotFetched thermal override')) {
        blog('Note: this tree has thermal checks disabled from a previous override build. Replace the source to restore stock checks.');
    }
}

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

// Thermal override: the user's word is final. Neuter Marlin's compile-time
// thermistor-table asserts in the imported tree so the build proceeds at
// the configured temperatures.
if ($thermalOverride) {
    $tempCpp = $root . ($detect['root'] !== '' ? '/' . $detect['root'] : '') . '/Marlin/src/module/temperature.cpp';
    $src = @file_get_contents($tempCpp);
    if ($src !== false && !str_contains($src, 'HotFetched thermal override')) {
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
        $ss = dirname((string)$advPath) . '/_Statusscreen.h';
        if (is_file($ss)) {
            $zip->addFile($ss, 'Marlin/_Statusscreen.h');
        }
        $zip->close();
    }
}

bstate($built ? 'success' : 'failed', $confidence, true);
db()->prepare("UPDATE projects SET updated_at = datetime('now') WHERE id = ?")->execute([(int)$project['id']]);
exit(0);
