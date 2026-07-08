<?php
declare(strict_types=1);

/**
 * HotFetched — source acquisition worker (CLI only, launched detached).
 * Usage: php source_worker.php {project_id}
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/bootstrap.php';

// git needs a writable HOME for config resolution when run as www-data.
putenv('HOME=/tmp');

$projectId = (int)($argv[1] ?? 0);
if ($projectId < 1) {
    fwrite(STDERR, "usage: source_worker.php {project_id}\n");
    exit(1);
}

$project = project_get($projectId);
if ($project === null || $project['source_state'] !== 'fetching') {
    exit(0); // stale launch — nothing to do
}

function worker_fail(int $id, string $msg): never
{
    $stmt = db()->prepare(
        "UPDATE projects SET source_state = 'error', source_error = ?, updated_at = datetime('now') WHERE id = ?"
    );
    $stmt->execute([$msg, $id]);
    exit(0);
}

$sourceDir = project_source_dir($projectId);
source_dir_reset($projectId);

/* ------------------------------------------------------------ Acquire */

if ($project['source_type'] === 'github') {

    $ref = '';
    $url = (string)$project['source_ref'];
    if (str_contains($url, '#')) {
        [$url, $ref] = explode('#', $url, 2);
    }
    // Re-validate defensively — never trust stored values into a shell.
    $url = github_url_normalize($url);
    if ($url === null || !valid_git_ref($ref)) {
        worker_fail($projectId, 'Stored source reference failed validation');
    }

    if (!is_dir(dirname($sourceDir)) && !@mkdir(dirname($sourceDir), 0775, true)) {
        worker_fail($projectId, 'Cannot create project directory');
    }

    $branchArg = $ref !== '' ? '--branch ' . escapeshellarg($ref) . ' ' : '';
    $cmd = 'timeout ' . HF_CLONE_TIMEOUT_S
         . ' git -c protocol.file.allow=never clone --depth 1 --single-branch '
         . $branchArg
         . escapeshellarg($url) . ' ' . escapeshellarg($sourceDir) . ' 2>&1';

    $out = shell_exec($cmd) ?? '';
    if (!is_dir($sourceDir . '/.git')) {
        $tail = trim(substr($out, -400));
        worker_fail($projectId, 'git clone failed: ' . ($tail !== '' ? $tail : 'unknown error (timeout?)'));
    }

} elseif ($project['source_type'] === 'zip') {

    $zipPath = project_upload_zip($projectId);
    if (!is_file($zipPath)) {
        worker_fail($projectId, 'Uploaded ZIP not found');
    }
    $err = safe_zip_extract($zipPath, $sourceDir);
    @unlink($zipPath);
    if ($err !== null) {
        worker_fail($projectId, $err);
    }

} else {
    worker_fail($projectId, 'Unknown source type');
}

/* ------------------------------------------------------------- Detect */

$detectError = null;
$detected = detect_firmware_tree($sourceDir, (string)$project['firmware'], $detectError);
if ($detected === null) {
    worker_fail($projectId, (string)$detectError);
}

$stmt = db()->prepare(
    "UPDATE projects
     SET source_state = 'ready', source_error = NULL, source_detect = ?, updated_at = datetime('now')
     WHERE id = ?"
);
$stmt->execute([json_encode($detected, JSON_UNESCAPED_SLASHES), $projectId]);
exit(0);
