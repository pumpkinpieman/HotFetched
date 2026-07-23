<?php
declare(strict_types=1);

$boardsDir = $argv[1] ?? '/var/www/html/webroot/boards';
$allowedScreenTypes = ['none', 'char20x4', 'mono128x64', 'marlinui_tft', 'serial_tft'];
$errors = [];
$boardIds = [];
$files = glob(rtrim($boardsDir, '/') . '/*.json') ?: [];

if ($files === []) {
    fwrite(STDERR, "No board JSON files found in {$boardsDir}\n");
    exit(1);
}

foreach ($files as $file) {
    $name = basename($file);
    try {
        $board = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        $errors[] = "{$name}: invalid JSON: {$e->getMessage()}";
        continue;
    }

    if (!is_array($board)) {
        $errors[] = "{$name}: top level must be an object";
        continue;
    }

    foreach (['id', 'name', 'mcu_variants', 'marlin'] as $key) {
        if (!array_key_exists($key, $board) || $board[$key] === '' || $board[$key] === []) {
            $errors[] = "{$name}: missing required key {$key}";
        }
    }

    $id = (string)($board['id'] ?? '');
    if ($id !== '') {
        if (isset($boardIds[$id])) {
            $errors[] = "{$name}: duplicate board id {$id} (also {$boardIds[$id]})";
        }
        $boardIds[$id] = $name;
    }

    $variantIds = [];
    foreach (($board['mcu_variants'] ?? []) as $idx => $variant) {
        if (!is_array($variant)) {
            $errors[] = "{$name}: mcu_variants[{$idx}] must be an object";
            continue;
        }
        $vid = (string)($variant['id'] ?? '');
        $env = (string)($variant['marlin_env'] ?? '');
        if ($vid === '' || (string)($variant['label'] ?? '') === '') {
            $errors[] = "{$name}: MCU variant {$idx} requires id and label";
        }
        if ($vid !== '' && isset($variantIds[$vid])) {
            $errors[] = "{$name}: duplicate MCU variant id {$vid}";
        }
        $variantIds[$vid] = true;
        if (($board['firmware_support']['marlin'] ?? true) && $env === '') {
            $errors[] = "{$name}: MCU variant {$vid} is missing marlin_env";
        }
        if (isset($variant['marlin_env_aliases'])) {
            if (!is_array($variant['marlin_env_aliases'])) {
                $errors[] = "{$name}: {$vid}.marlin_env_aliases must be an array";
            } else {
                foreach ($variant['marlin_env_aliases'] as $alias) {
                    if (!is_string($alias) || trim($alias) === '') {
                        $errors[] = "{$name}: {$vid}.marlin_env_aliases contains an invalid value";
                    }
                }
            }
        }
    }

    $screens = $board['marlin']['screens'] ?? [];
    if (!is_array($screens) || $screens === []) {
        $errors[] = "{$name}: marlin.screens must contain at least the none option";
        continue;
    }

    $screenIds = [];
    $hasNone = false;
    foreach ($screens as $idx => $screen) {
        if (!is_array($screen)) {
            $errors[] = "{$name}: screen {$idx} must be an object";
            continue;
        }
        $sid = (string)($screen['id'] ?? '');
        $type = (string)($screen['type'] ?? '');
        if ($sid === '' || (string)($screen['label'] ?? '') === '') {
            $errors[] = "{$name}: screen {$idx} requires id and label";
        }
        if ($sid !== '' && isset($screenIds[$sid])) {
            $errors[] = "{$name}: duplicate screen id {$sid}";
        }
        $screenIds[$sid] = true;
        if (!in_array($type, $allowedScreenTypes, true)) {
            $errors[] = "{$name}: screen {$sid} has unsupported type {$type}";
        }
        if ($sid === 'none' && $type === 'none') {
            $hasNone = true;
        }
        if (in_array($type, ['char20x4', 'mono128x64', 'marlinui_tft'], true)) {
            $define = (string)($screen['define'] ?? $sid);
            if ($define === '' || !preg_match('/^[A-Z][A-Z0-9_]+$/', $define)) {
                $errors[] = "{$name}: screen {$sid} has an invalid Marlin define";
            }
        }
    }
    if (!$hasNone) {
        $errors[] = "{$name}: marlin.screens must include id=none,type=none";
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    fwrite(STDERR, sprintf("Board validation failed: %d issue(s) across %d file(s).\n", count($errors), count($files)));
    exit(1);
}

echo sprintf("Board validation passed: %d board profiles.\n", count($files));
