<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/* ------------------------------------------------ GET: artifact download */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $id   = filter_var($_GET['download'] ?? null, FILTER_VALIDATE_INT);
    $type = (string)($_GET['type'] ?? 'firmware');
    if ($id === false || $id === null || $id < 1) {
        http_response_code(400);
        exit('Invalid build id');
    }
    $stmt = db()->prepare('SELECT b.*, p.name AS pname, p.id AS pid FROM builds b JOIN projects p ON p.id = b.project_id WHERE b.id = ?');
    $stmt->execute([$id]);
    $b = $stmt->fetch();
    if (!$b) {
        http_response_code(404);
        exit('Build not found');
    }
    $dir = realpath(build_dir((int)$b['pid'], (int)$b['id']));
    if ($dir === false) {
        http_response_code(404);
        exit('Not found');
    }
    // Firmware artifact keeps its real name (.bin/.uf2/.elf); resolve from the
    // stored artifact_path. Config/log are fixed names.
    if ($type === 'firmware') {
        $artifactName = is_string($b['artifact_path']) ? basename($b['artifact_path']) : 'firmware.bin';
        $target = [$artifactName, 'application/octet-stream'];
    } else {
        $map = [
            'config' => ['config-bundle.zip', 'application/zip'],
            'log'    => ['build.log', 'text/plain'],
        ];
        if (!isset($map[$type])) {
            http_response_code(404);
            exit('Not found');
        }
        $target = $map[$type];
    }
    $file = realpath($dir . '/' . $target[0]);
    if ($file === false || !str_starts_with($file, $dir)) {
        http_response_code(404);
        exit('Artifact not available');
    }
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$b['pname']);
    $dlName = $safeName . '-build' . $b['id'] . '-' . $target[0];
    header('Content-Type: ' . $target[1]);
    header('Content-Disposition: attachment; filename="' . $dlName . '"');
    header('Content-Length: ' . (string)filesize($file));
    readfile($file);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'Invalid JSON body'], 400);
}

csrf_verify($body['csrf'] ?? null);

builds_sweep_stale();

$action = (string)($body['action'] ?? '');

switch ($action) {

    case 'start': {
        $pid = filter_var($body['project_id'] ?? null, FILTER_VALIDATE_INT);
        if ($pid === false || $pid === null || $pid < 1) {
            json_out(['ok' => false, 'error' => 'Invalid project id'], 422);
        }
        $p = project_get($pid);
        if ($p === null) {
            json_out(['ok' => false, 'error' => 'Project not found'], 404);
        }
        // RRF has no source tree to import — its firmware is prebuilt upstream.
        if ($p['firmware'] !== 'reprap' && $p['source_state'] !== 'ready') {
            json_out(['ok' => false, 'error' => 'Import a firmware source first'], 409);
        }
        $stmt = db()->prepare("SELECT COUNT(*) AS c FROM builds WHERE project_id = ? AND status IN ('queued','validating','building')");
        $stmt->execute([$pid]);
        if ((int)$stmt->fetch()['c'] > 0) {
            json_out(['ok' => false, 'error' => 'A build is already running for this project'], 409);
        }
        $stmt = db()->prepare('SELECT COUNT(*) AS c FROM config_values WHERE project_id = ?');
        $stmt->execute([$pid]);
        if ((int)$stmt->fetch()['c'] === 0) {
            json_out(['ok' => false, 'error' => 'Submit the Configuration form before building'], 409);
        }

        db()->prepare("INSERT INTO builds (project_id, started_at) VALUES (?, datetime('now'))")->execute([$pid]);
        $buildId = (int)db()->lastInsertId();

        $php = '/usr/local/bin/php';
        if (!is_executable($php)) {
            $php = trim((string)shell_exec('command -v php')) ?: PHP_BINARY;
        }
        $script = dirname(__DIR__) . '/build_worker.php';
        shell_exec(sprintf('setsid nohup %s %s %d < /dev/null > /dev/null 2>&1 &',
            escapeshellarg($php), escapeshellarg($script), $buildId));

        json_out(['ok' => true, 'build_id' => $buildId]);
    }

    case 'status': {
        $id = filter_var($body['build_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || $id < 1) {
            json_out(['ok' => false, 'error' => 'Invalid build id'], 422);
        }
        $stmt = db()->prepare('SELECT * FROM builds WHERE id = ?');
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) {
            json_out(['ok' => false, 'error' => 'Build not found'], 404);
        }

        $tail = '';
        if (is_string($b['log_path']) && is_file($b['log_path'])) {
            $size = filesize($b['log_path']) ?: 0;
            $fh = @fopen($b['log_path'], 'rb');
            if ($fh !== false) {
                if ($size > 6144) {
                    fseek($fh, -6144, SEEK_END);
                }
                $tail = (string)stream_get_contents($fh);
                fclose($fh);
            }
        }

        // Board-specific flashing guidance for the success banner.
        $flashNote = '';
        $artifactName = 'firmware.bin';
        $proj = project_get((int)$b['project_id']);
        if ($proj !== null) {
            $bd = board_def((string)$proj['board_id']);
            if ($bd !== null) {
                if ($proj['firmware'] === 'reprap') {
                    $flashNote = 'Copy firmware.bin to the SD card root and config.g into /sys on the same card, then power-cycle.';
                    $artifactName = 'firmware.bin';
                } elseif ($proj['firmware'] === 'klipper' && isset($bd['klipper'])) {
                    $flashNote = (string)($bd['klipper']['flash_note'] ?? '');
                    $artifactName = (string)($bd['klipper']['artifact'] ?? 'klipper.bin');
                } elseif ($proj['firmware'] === 'marlin') {
                    $flashNote = (string)($bd['marlin']['flash_note'] ?? 'Copy the firmware to the SD card and reset the board to flash.');
                    $artifactName = 'firmware.bin';
                }
            }
        }
        if (is_string($b['artifact_path']) && $b['artifact_path'] !== '') {
            $artifactName = basename($b['artifact_path']);
        }

        json_out([
            'ok'         => true,
            'status'     => $b['status'],
            'confidence' => $b['confidence'] !== null ? (int)$b['confidence'] : null,
            'gates'      => $b['gate_json'] !== null ? json_decode((string)$b['gate_json'], true) : [],
            'log_tail'   => $tail,
            'started_at' => $b['started_at'],
            'finished_at'=> $b['finished_at'],
            'flash_note' => $flashNote,
            'artifact_name' => $artifactName,
            'artifacts'  => [
                'firmware' => $b['status'] === 'success' && $b['artifact_path'] !== null,
                'config'   => $b['status'] === 'success',
                'log'      => $b['log_path'] !== null,
            ],
        ]);
    }

    case 'list': {
        $pid = filter_var($body['project_id'] ?? null, FILTER_VALIDATE_INT);
        if ($pid === false || $pid === null || $pid < 1) {
            json_out(['ok' => false, 'error' => 'Invalid project id'], 422);
        }
        $stmt = db()->prepare('SELECT id, status, confidence, started_at, finished_at FROM builds WHERE project_id = ? ORDER BY id DESC LIMIT 25');
        $stmt->execute([$pid]);
        json_out(['ok' => true, 'builds' => $stmt->fetchAll()]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
