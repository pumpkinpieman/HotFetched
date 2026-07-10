<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'Invalid JSON body'], 400);
}

csrf_verify($body['csrf'] ?? null);

$action = (string)($body['action'] ?? '');

switch ($action) {

    case 'status': {
        json_out(['ok' => true] + soundlib_status());
    }

    case 'install': {
        $st = soundlib_status();
        if ($st['state'] === 'installing') {
            json_out(['ok' => false, 'error' => 'Install already in progress'], 409);
        }
        soundlib_state_write(['state' => 'installing']);
        $php = '/usr/local/bin/php';
        if (!is_executable($php)) {
            $php = trim((string)shell_exec('command -v php')) ?: PHP_BINARY;
        }
        $script = dirname(__DIR__) . '/soundlib_worker.php';
        shell_exec(sprintf('setsid nohup %s %s < /dev/null > /dev/null 2>&1 &',
            escapeshellarg($php), escapeshellarg($script)));
        json_out(['ok' => true, 'state' => 'installing']);
    }

    case 'list': {
        $st = soundlib_status();
        if ($st['state'] !== 'ready') {
            json_out(['ok' => false, 'error' => 'Library not installed'], 409);
        }
        $index = soundlib_index();
        $q   = mb_strtolower(trim((string)($body['q'] ?? '')));
        $cat = (string)($body['category'] ?? '');

        $out = [];
        foreach ($index as $rel) {
            if ($cat !== '' && !str_starts_with($rel, $cat . '/')) {
                continue;
            }
            if ($q !== '' && !str_contains(mb_strtolower($rel), $q)) {
                continue;
            }
            $out[] = $rel;
            if (count($out) >= 100) {
                break;
            }
        }
        $cats = [];
        foreach ($index as $rel) {
            $parts = explode('/', $rel);
            if (count($parts) >= 2) {
                $cats[$parts[0] . '/' . $parts[1]] = true;
            }
        }
        ksort($cats);
        json_out(['ok' => true, 'files' => $out, 'total' => count($index), 'categories' => array_keys($cats)]);
    }

    case 'file': {
        $rel = (string)($body['path'] ?? '');
        // Index paths are relative to the midi/ subdirectory.
        $root = realpath(soundlib_dir() . '/midi');
        $abs  = $root !== false ? realpath($root . '/' . $rel) : false;
        if ($root === false || $abs === false || !str_starts_with($abs, $root . '/')
            || !str_ends_with(strtolower($abs), '.mid') || !is_file($abs)) {
            json_out(['ok' => false, 'error' => 'File not found'], 404);
        }
        json_out(['ok' => true, 'name' => basename($abs), 'data_b64' => base64_encode((string)file_get_contents($abs))]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
