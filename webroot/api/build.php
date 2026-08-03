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
/** Read and validate the per-build multi-artifact index. */
function hf_build_artifact_records(string $dir): array
{
    $path = $dir . '/build-artifacts.json';
    if (!is_file($path)) return [];
    $doc = json_decode((string)@file_get_contents($path), true);
    if (!is_array($doc) || !is_array($doc['items'] ?? null)) return [];
    $records = [];
    foreach ($doc['items'] as $item) {
        if (!is_array($item)) continue;
        $name = basename((string)($item['filename'] ?? ''));
        $file = $name !== '' ? realpath($dir . '/' . $name) : false;
        if ($file === false || !is_file($file) || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR)) continue;
        $records[] = [
            'id' => (string)($item['id'] ?? pathinfo($name, PATHINFO_FILENAME)),
            'label' => (string)($item['label'] ?? $name),
            'filename' => $name,
            'bytes' => (int)($item['bytes'] ?? filesize($file)),
            'sha256' => (string)($item['sha256'] ?? ''),
            'hardware_mcu' => (string)($item['hardware_mcu'] ?? ''),
            'build_target' => (string)($item['build_target'] ?? ''),
            'serial' => $item['serial'] ?? null,
            'baud' => $item['baud'] ?? null,
            'primary' => (bool)($item['primary'] ?? false),
            'flash_note' => (string)($item['flash_note'] ?? ''),
        ];
    }
    return $records;
}

function hf_primary_artifact(array $records, ?string $fallback = null): ?array
{
    foreach ($records as $record) {
        if (!empty($record['primary'])) return $record;
    }
    if ($records !== []) return $records[0];
    if ($fallback !== null && $fallback !== '') {
        return ['id' => 'primary', 'label' => 'Firmware', 'filename' => basename($fallback), 'primary' => true];
    }
    return null;
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

    $records = hf_build_artifact_records($dir);
    if ($type === 'firmware') {
        $storedPath = is_string($build['artifact_path']) ? $build['artifact_path'] : '';
        $primary = hf_primary_artifact($records, $storedPath !== '' ? basename($storedPath) : null);
        $artifactName = $primary['filename'] ?? 'firmware.bin';
        $candidate = $storedPath !== '' ? realpath($storedPath) : false;
        if ($candidate === false) $candidate = realpath($dir . '/' . $artifactName);
        $file = $candidate;
        $mime = hf_artifact_mime($artifactName);
    } elseif ($type === 'artifact') {
        $requested = basename((string)($_GET['name'] ?? ''));
        $match = null;
        foreach ($records as $record) {
            if (hash_equals($record['filename'], $requested)) { $match = $record; break; }
        }
        if ($match === null) {
            http_response_code(404);
            exit('Firmware artifact not found');
        }
        $artifactName = $match['filename'];
        $file = realpath($dir . '/' . $artifactName);
        $mime = hf_artifact_mime($artifactName);
    } else {
        $map = [
            'firmware_bundle' => ['firmware-bundle.zip', 'application/zip'],
            'config' => ['config-bundle.zip', 'application/zip'],
            'log' => ['build.log', 'text/plain; charset=utf-8'],
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

    $downloadName = $type === 'artifact'
        ? basename($artifactName)
        : hf_download_name((string)$build['pname'], (int)$build['id'], $artifactName);
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

        $buildDirPath = realpath(build_dir((int)$build['project_id'], (int)$build['id']));
        $artifactFiles = $buildDirPath !== false ? hf_build_artifact_records($buildDirPath) : [];
        $storedArtifact = is_string($build['artifact_path']) && $build['artifact_path'] !== ''
            ? basename($build['artifact_path']) : '';
        $primaryArtifact = hf_primary_artifact($artifactFiles, $storedArtifact);
        $artifactName = $primaryArtifact['filename'] ?? ($storedArtifact !== '' ? $storedArtifact : 'firmware.bin');
        $hasFirmwareBundle = $buildDirPath !== false && is_file($buildDirPath . '/firmware-bundle.zip');

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
            'artifact_files' => $artifactFiles,
            'artifacts' => [
                'firmware' => $build['status'] === 'success' && $build['artifact_path'] !== null,
                'firmware_bundle' => $build['status'] === 'success' && $hasFirmwareBundle,
                'config' => $build['status'] === 'success',
                'log' => $build['log_path'] !== null,
            ],
        ]);
    }

    case 'list': {
        $projectId = filter_var($body['project_id'] ?? null, FILTER_VALIDATE_INT);
        if ($projectId === false || $projectId === null || $projectId < 1) {
            json_out(['ok' => false, 'error' => 'Invalid project id'], 422);
        }
        $stmt = db()->prepare(
            'SELECT id, status, confidence, started_at, finished_at, artifact_path '
            . 'FROM builds WHERE project_id = ? ORDER BY id DESC LIMIT 25'
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $dir = realpath(build_dir($projectId, (int)$row['id']));
            $records = $dir !== false ? hf_build_artifact_records($dir) : [];
            $primary = hf_primary_artifact($records, is_string($row['artifact_path']) ? basename($row['artifact_path']) : null);
            $row['primary_artifact'] = $primary['filename'] ?? 'firmware.bin';
            $row['artifact_count'] = count($records) ?: (($row['artifact_path'] ?? null) !== null ? 1 : 0);
            $row['has_firmware_bundle'] = $dir !== false && is_file($dir . '/firmware-bundle.zip');
            unset($row['artifact_path']);
        }
        unset($row);
        json_out(['ok' => true, 'builds' => $rows]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
