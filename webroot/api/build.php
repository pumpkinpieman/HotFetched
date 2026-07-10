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
    $map = [
        'firmware' => ['firmware.bin', 'application/octet-stream'],
        'config'   => ['config-bundle.zip', 'application/zip'],
        'log'      => ['build.log', 'text/plain'],
    ];
    if (!isset($map[$type]) || $dir === false) {
        http_response_code(404);
        exit('Not found');
    }
    $file = realpath($dir . '/' . $map[$type][0]);
    if ($file === false || !str_starts_with($file, $dir . '/') && $file !== $dir . '/' . $map[$type][0]) {
        // realpath containment: file must live inside the build dir
    }
    if ($file === false || !str_starts_with($file, $dir)) {
        http_response_code(404);
        exit('Artifact not available');
    }
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$b['pname']);
    $dlName = $safeName . '-build' . $b['id'] . '-' . $map[$type][0];
    header('Content-Type: ' . $map[$type][1]);
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
        if ($p['firmware'] !== 'marlin') {
            json_out(['ok' => false, 'error' => 'Builds currently support Marlin projects (Klipper ships in a later phase)'], 422);
        }
        if ($p['source_state'] !== 'ready') {
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

        db()->prepare('INSERT INTO builds (project_id) VALUES (?)')->execute([$pid]);
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

        // Stall watchdog: active build with a silent log for 15 minutes.
        if (in_array($b['status'], ['queued', 'validating', 'building'], true)) {
            $ref = is_string($b['log_path']) && is_file($b['log_path'])
                ? (int)filemtime($b['log_path'])
                : strtotime((string)($b['started_at'] ?? $b['finished_at'] ?? 'now') . ' UTC');
            if ($ref !== false && time() - $ref > 900) {
                db()->prepare("UPDATE builds SET status = 'failed', finished_at = datetime('now') WHERE id = ? AND status IN ('queued','validating','building')")
                    ->execute([$id]);
                $stmt->execute([$id]);
                $b = $stmt->fetch();
            }
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

        json_out([
            'ok'         => true,
            'status'     => $b['status'],
            'confidence' => $b['confidence'] !== null ? (int)$b['confidence'] : null,
            'gates'      => $b['gate_json'] !== null ? json_decode((string)$b['gate_json'], true) : [],
            'log_tail'   => $tail,
            'started_at' => $b['started_at'],
            'finished_at'=> $b['finished_at'],
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
