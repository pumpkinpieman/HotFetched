<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fwverify.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}

$isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
if ($isMultipart) {
    $body = $_POST;
} else {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        json_out(['ok' => false, 'error' => 'Invalid JSON body'], 400);
    }
}

csrf_verify($body['csrf'] ?? null);

$action = (string)($body['action'] ?? '');

switch ($action) {

    /* Verify the artifact produced by one of our own builds against the
       configuration that was submitted for it. This is the closed loop. */
    case 'build': {
        $buildId = (int)($body['build_id'] ?? 0);
        if ($buildId <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid build id'], 422);
        }
        $stmt = db()->prepare('SELECT * FROM builds WHERE id = ?');
        $stmt->execute([$buildId]);
        $b = $stmt->fetch();
        if ($b === false) {
            json_out(['ok' => false, 'error' => 'Build not found'], 404);
        }
        $path = (string)($b['artifact_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            json_out(['ok' => false, 'error' => 'That build produced no firmware to verify'], 409);
        }

        $p = project_get((int)$b['project_id']);
        if ($p === null) {
            json_out(['ok' => false, 'error' => 'Project not found'], 404);
        }
        $board = board_def((string)$p['board_id']);

        // The configuration that was submitted for this build.
        $stmt = db()->prepare('SELECT field_key, field_value FROM config_values WHERE project_id = ?');
        $stmt->execute([(int)$p['id']]);
        $cfg = [];
        foreach ($stmt->fetchAll() as $row) {
            $cfg[$row['field_key']] = $row['field_value'];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            json_out(['ok' => false, 'error' => 'Could not read the firmware artifact'], 500);
        }

        $report = fwv_analyse($raw, $cfg, $board, basename($path));
        $report['artifact'] = basename($path);
        $report['firmware_expected'] = $p['firmware'];
        json_out(['ok' => true, 'report' => $report]);
    }

    /* Verify an arbitrary uploaded firmware - no config to diff against, so
       this reports only what the binary itself can prove. */
    case 'upload': {
        $file = $_FILES['firmware'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_out(['ok' => false, 'error' => 'No firmware uploaded'], 422);
        }
        if ((int)$file['size'] > 32 * 1024 * 1024) {
            json_out(['ok' => false, 'error' => 'File too large (32 MB max)'], 422);
        }
        $raw = @file_get_contents((string)$file['tmp_name']);
        if ($raw === false || strlen($raw) < 16) {
            json_out(['ok' => false, 'error' => 'That file is empty or unreadable'], 422);
        }

        // Optionally diff against a chosen project's config.
        $cfg = null;
        $board = null;
        $pid = (int)($_POST['project_id'] ?? 0);
        if ($pid > 0) {
            $p = project_get($pid);
            if ($p !== null) {
                $board = board_def((string)$p['board_id']);
                $stmt = db()->prepare('SELECT field_key, field_value FROM config_values WHERE project_id = ?');
                $stmt->execute([$pid]);
                $cfg = [];
                foreach ($stmt->fetchAll() as $row) {
                    $cfg[$row['field_key']] = $row['field_value'];
                }
            }
        }

        $name = (string)($file['name'] ?? 'firmware.bin');
        $report = fwv_analyse($raw, $cfg, $board, $name);
        $report['artifact'] = $name;
        json_out(['ok' => true, 'report' => $report]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
