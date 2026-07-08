<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}

/*
 * Two content types hit this endpoint:
 *  - application/json  (import_github, status, reset, defaults)
 *  - multipart/form-data (import_zip — file upload)
 */
$isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');

if ($isMultipart) {
    $body = $_POST;
} else {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        json_out(['ok' => false, 'error' => 'Invalid JSON body'], 400);
    }
}

csrf_verify(is_string($body['csrf'] ?? null) ? $body['csrf'] : null);

$action = (string)($body['action'] ?? '');

/** Shared: load + validate project, reject if a fetch is already running. */
function load_project_for(array $body, bool $blockWhileFetching = true): array
{
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id < 1) {
        json_out(['ok' => false, 'error' => 'Invalid project id'], 422);
    }
    $p = project_get($id);
    if ($p === null) {
        json_out(['ok' => false, 'error' => 'Project not found'], 404);
    }
    if ($blockWhileFetching && $p['source_state'] === 'fetching') {
        json_out(['ok' => false, 'error' => 'A source import is already in progress'], 409);
    }
    return $p;
}

switch ($action) {

    case 'import_github': {
        $p   = load_project_for($body);
        $url = github_url_normalize((string)($body['url'] ?? ''));
        $ref = trim((string)($body['ref'] ?? ''));

        if ($url === null) {
            json_out(['ok' => false, 'error' => 'URL must be a plain https://github.com/{owner}/{repo} link'], 422);
        }
        if (!valid_git_ref($ref)) {
            json_out(['ok' => false, 'error' => 'Invalid ref (tag/branch): letters, digits, . _ / - only'], 422);
        }

        $sourceRef = $ref === '' ? $url : $url . '#' . $ref;
        $stmt = db()->prepare(
            "UPDATE projects
             SET source_type = 'github', source_ref = ?, source_state = 'fetching',
                 source_error = NULL, source_detect = NULL, updated_at = datetime('now')
             WHERE id = ?"
        );
        $stmt->execute([$sourceRef, (int)$p['id']]);
        @unlink(project_upload_zip((int)$p['id']));

        source_worker_launch((int)$p['id']);
        json_out(['ok' => true, 'state' => 'fetching']);
    }

    case 'import_zip': {
        if (!$isMultipart) {
            json_out(['ok' => false, 'error' => 'multipart/form-data required'], 400);
        }
        $p = load_project_for($body);

        $file = $_FILES['zip'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the 256 MB upload limit',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                default => 'Upload failed (code ' . (int)$code . ')',
            };
            json_out(['ok' => false, 'error' => $msg], 422);
        }

        $magic = @file_get_contents((string)$file['tmp_name'], false, null, 0, 4);
        if ($magic === false || !str_starts_with($magic, "PK\x03\x04")) {
            json_out(['ok' => false, 'error' => 'File is not a valid ZIP archive'], 422);
        }

        $dest = project_upload_zip((int)$p['id']);
        if (!is_dir(dirname($dest))) {
            @mkdir(dirname($dest), 0775, true);
        }
        if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
            json_out(['ok' => false, 'error' => 'Could not store uploaded file'], 500);
        }

        $origName = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($file['name'] ?? 'upload.zip'));
        $stmt = db()->prepare(
            "UPDATE projects
             SET source_type = 'zip', source_ref = ?, source_state = 'fetching',
                 source_error = NULL, source_detect = NULL, updated_at = datetime('now')
             WHERE id = ?"
        );
        $stmt->execute([$origName, (int)$p['id']]);

        source_worker_launch((int)$p['id']);
        json_out(['ok' => true, 'state' => 'fetching']);
    }

    case 'status': {
        $p = load_project_for($body, false);
        json_out([
            'ok'     => true,
            'state'  => $p['source_state'],
            'ref'    => $p['source_ref'],
            'type'   => $p['source_type'],
            'error'  => $p['source_error'],
            'detect' => $p['source_detect'] !== null ? json_decode($p['source_detect'], true) : null,
        ]);
    }

    case 'reset': {
        $p = load_project_for($body);
        source_dir_reset((int)$p['id']);
        @unlink(project_upload_zip((int)$p['id']));
        $stmt = db()->prepare(
            "UPDATE projects
             SET source_type = NULL, source_ref = NULL, source_state = 'none',
                 source_error = NULL, source_detect = NULL, updated_at = datetime('now')
             WHERE id = ?"
        );
        $stmt->execute([(int)$p['id']]);
        json_out(['ok' => true, 'state' => 'none']);
    }

    case 'defaults': {
        // Official upstream defaults; Marlin's tag is fetched live (unauthenticated
        // GitHub API) with a session cache. Falls back to bugfix branch on failure.
        $fw = (string)($body['firmware'] ?? '');
        if ($fw === 'klipper') {
            json_out(['ok' => true, 'url' => 'https://github.com/Klipper3d/klipper', 'ref' => 'master']);
        }
        if ($fw !== 'marlin') {
            json_out(['ok' => false, 'error' => 'Unknown firmware'], 422);
        }

        if (!empty($_SESSION['hf_marlin_latest']) && is_string($_SESSION['hf_marlin_latest'])) {
            json_out(['ok' => true, 'url' => 'https://github.com/MarlinFirmware/Marlin', 'ref' => $_SESSION['hf_marlin_latest']]);
        }

        $ref = 'bugfix-2.1.x';
        $ch = curl_init('https://api.github.com/repos/MarlinFirmware/Marlin/releases/latest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'HotFetched/' . HF_VERSION,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (is_string($resp)) {
            $j = json_decode($resp, true);
            $tag = is_array($j) ? (string)($j['tag_name'] ?? '') : '';
            if ($tag !== '' && valid_git_ref($tag)) {
                $ref = $tag;
                $_SESSION['hf_marlin_latest'] = $tag;
            }
        }
        json_out(['ok' => true, 'url' => 'https://github.com/MarlinFirmware/Marlin', 'ref' => $ref]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
