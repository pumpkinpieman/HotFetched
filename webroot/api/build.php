<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/**
 * Return a safe browser download name while preserving the real firmware
 * extension (.hex, .bin, .uf2, .elf, and so on).
 */
function hf_download_name(string $projectName, int $buildId, string $artifactName): string
{
    $safeProject = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($projectName)) ?: 'HotFetched';
    $safeArtifact = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($artifactName)) ?: 'firmware.bin';
    return $safeProject . '-build' . $buildId . '-' . $safeArtifact;
}

/**
 * Firmware MIME selection. Intel HEX remains an opaque download instead of
 * text/plain so browsers and proxies do not append .txt or transform content.
 */
function hf_artifact_mime(string $filename): string
{
    return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
        'zip' => 'application/zip',
        'log', 'txt' => 'text/plain; charset=utf-8',
        'hex' => 'application/octet-stream',
        'bin', 'uf2', 'elf' => 'application/octet-stream',
        default => 'application/octet-stream',
    };
}

/* ------------------------------------------------ GET: artifact download */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $id = filter_var($_GET['download'] ?? null, FILTER_VALIDATE_INT);
    $type = (string)($_GET['type'] ?? 'firmware');

    if ($id === false || $id === null || $id < 1) {
        http_response_code(400);
        exit('Invalid build id');
    }

    $stmt = db()->prepare(
        'SELECT b.*, p.name AS pname, p.id AS pid '
        . 'FROM builds b JOIN projects p ON p.id = b.project_id WHERE b.id = ?'
    );
    $stmt->execute([$id]);
    $build = $stmt->fetch();
    if (!$build) {
        http_response_code(404);
        exit('Build not found');
    }

    $dir = realpath(build_dir((int)$build['pid'], (int)$build['id']));
    if ($dir === false) {
        http_response_code(404);
        exit('Build directory not found');
    }

    if ($type === 'firmware') {
        $storedPath = is_string($build['artifact_path']) ? $build['artifact_path'] : '';
        $artifactName = $storedPath !== '' ? basename($storedPath) : 'firmware.bin';
        $candidate = $storedPath !== '' ? realpath($storedPath) : false;

        // Older rows may contain a relative/stale artifact_path. Fall back to
        // resolving the real filename inside this build directory.
        if ($candidate === false) {
            $candidate = realpath($dir . '/' . $artifactName);
        }
        $file = $candidate;
        $mime = hf_artifact_mime($artifactName);
    } else {
        $map = [
            'config' => ['config-bundle.zip', 'application/zip'],
            'log'    => ['build.log', 'text/plain; charset=utf-8'],
        ];
        if (!isset($map[$type])) {
            http_response_code(404);
            exit('Artifact type not found');
        }
        [$artifactName, $mime] = $map[$type];
        $file = realpath($dir . '/' . $artifactName);
    }

    if ($file === false || !is_file($file) || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        exit('Artifact not available');
    }

    $downloadName = hf_download_name((string)$build['pname'], (int)$build['id'], $artifactName);
    $encodedName = rawurlencode($downloadName);

    // Prevent stale output, MIME guessing, compression, or proxy transforms.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, no-transform');
    header('Pragma: no-cache');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . $encodedName);
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
        $projectId = filter_var($body['project_id'] ?? null, FILTER_VALIDATE_INT);
        if ($projectId === false || $projectId === null || $projectId < 1) {
            json_out(['ok' => false, 'error' => 'Invalid project id'], 422);
        }

        $project = project_get($projectId);
        if ($project === null) {
            json_out(['ok' => false, 'error' => 'Project not found'], 404);
        }

        if ($project['firmware'] !== 'reprap' && $project['source_state'] !== 'ready') {
            json_out(['ok' => false, 'error' => 'Import a firmware source first'], 409);
        }

        $stmt = db()->prepare(
            "SELECT COUNT(*) AS c FROM builds WHERE project_id = ? "
            . "AND status IN ('queued','validating','building')"
        );
        $stmt->execute([$projectId]);
        if ((int)$stmt->fetch()['c'] > 0) {
            json_out(['ok' => false, 'error' => 'A build is already running for this project'], 409);
        }

        $stmt = db()->prepare('SELECT COUNT(*) AS c FROM config_values WHERE project_id = ?');
        $stmt->execute([$projectId]);
        if ((int)$stmt->fetch()['c'] === 0) {
            json_out(['ok' => false, 'error' => 'Submit the Configuration form before building'], 409);
        }

        db()->prepare("INSERT INTO builds (project_id, started_at) VALUES (?, datetime('now'))")
            ->execute([$projectId]);
        $buildId = (int)db()->lastInsertId();

        $php = '/usr/local/bin/php';
        if (!is_executable($php)) {
            $php = trim((string)shell_exec('command -v php')) ?: PHP_BINARY;
        }
        $script = dirname(__DIR__) . '/build_worker.php';
        shell_exec(sprintf(
            'setsid nohup %s %s %d < /dev/null > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            $buildId
        ));

        json_out(['ok' => true, 'build_id' => $buildId]);
    }

    case 'status': {
        $buildId = filter_var($body['build_id'] ?? null, FILTER_VALIDATE_INT);
        if ($buildId === false || $buildId === null || $buildId < 1) {
            json_out(['ok' => false, 'error' => 'Invalid build id'], 422);
        }

        $stmt = db()->prepare('SELECT * FROM builds WHERE id = ?');
        $stmt->execute([$buildId]);
        $build = $stmt->fetch();
        if (!$build) {
            json_out(['ok' => false, 'error' => 'Build not found'], 404);
        }

        $tail = '';
        if (is_string($build['log_path']) && is_file($build['log_path'])) {
            $size = filesize($build['log_path']) ?: 0;
            $fh = @fopen($build['log_path'], 'rb');
            if ($fh !== false) {
                if ($size > 6144) {
                    fseek($fh, -6144, SEEK_END);
                }
                $tail = (string)stream_get_contents($fh);
                fclose($fh);
            }
        }

        $artifactName = 'firmware.bin';
        if (is_string($build['artifact_path']) && $build['artifact_path'] !== '') {
            $artifactName = basename($build['artifact_path']);
        }

        $flashNote = '';
        $project = project_get((int)$build['project_id']);
        if ($project !== null) {
            $board = board_def((string)$project['board_id']);
            $variant = $board !== null
                ? board_mcu_variant($board, (string)($project['mcu_variant'] ?? ''))
                : null;

            if (is_array($variant) && !empty($variant['flash_note'])) {
                $flashNote = (string)$variant['flash_note'];
            } elseif ($project['firmware'] === 'reprap') {
                $flashNote = 'Copy firmware.bin to the SD card root and config.g into /sys, then power-cycle.';
            } elseif ($project['firmware'] === 'klipper' && is_array($board['klipper'] ?? null)) {
                $flashNote = (string)($board['klipper']['flash_note'] ?? '');
            } elseif ($project['firmware'] === 'marlin') {
                $flashNote = strtolower(pathinfo($artifactName, PATHINFO_EXTENSION)) === 'hex'
                    ? 'Flash ' . $artifactName . ' over USB serial with avrdude. Do not rename it to firmware.bin.'
                    : 'Flash ' . $artifactName . ' using the board-specific method.';
            }
        }

        json_out([
            'ok'            => true,
            'status'        => $build['status'],
            'confidence'    => $build['confidence'] !== null ? (int)$build['confidence'] : null,
            'gates'         => $build['gate_json'] !== null
                ? json_decode((string)$build['gate_json'], true)
                : [],
            'log_tail'      => $tail,
            'started_at'    => $build['started_at'],
            'finished_at'   => $build['finished_at'],
            'flash_note'    => $flashNote,
            'artifact_name' => $artifactName,
            'artifacts'     => [
                'firmware' => $build['status'] === 'success' && $build['artifact_path'] !== null,
                'config'   => $build['status'] === 'success',
                'log'      => $build['log_path'] !== null,
            ],
        ]);
    }

    case 'list': {
        $projectId = filter_var($body['project_id'] ?? null, FILTER_VALIDATE_INT);
        if ($projectId === false || $projectId === null || $projectId < 1) {
            json_out(['ok' => false, 'error' => 'Invalid project id'], 422);
        }
        $stmt = db()->prepare(
            'SELECT id, status, confidence, started_at, finished_at '
            . 'FROM builds WHERE project_id = ? ORDER BY id DESC LIMIT 25'
        );
        $stmt->execute([$projectId]);
        json_out(['ok' => true, 'builds' => $stmt->fetchAll()]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
