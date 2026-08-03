<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/KlipperConversion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}

$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if (is_int($contentLength) && $contentLength > 16384) {
    json_out(['ok' => false, 'error' => 'Request body is too large'], 413);
}

$input = fopen('php://input', 'rb');
if ($input === false) {
    json_out(['ok' => false, 'error' => 'Could not read request body'], 400);
}
$raw = stream_get_contents($input, 16385);
fclose($input);
if (!is_string($raw) || strlen($raw) > 16384) {
    json_out(['ok' => false, 'error' => 'Request body is too large'], 413);
}
try {
    $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    json_out(['ok' => false, 'error' => 'Invalid JSON body'], 400);
}
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'JSON body must be an object'], 400);
}

csrf_verify(is_string($body['csrf'] ?? null) ? $body['csrf'] : null);
if (!is_string($body['action'] ?? null) || $body['action'] !== 'generate') {
    json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}

[$options, $errors] = hf_conversion_validate($body);
if ($errors !== []) {
    json_out([
        'ok' => false,
        'error' => 'Conversion options failed validation',
        'fields' => $errors,
    ], 422);
}

// The archive is generated without reading or writing session state. Release
// the session lock so another HotFetched tab is not blocked during generation
// or while the ZIP is being streamed.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $archive = hf_conversion_create_zip($options);
} catch (Throwable $e) {
    error_log('[conversion] package generation failed: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not generate conversion package'], 500);
}

$path = (string)$archive['path'];
$name = basename((string)$archive['download_name']);
$size = is_file($path) ? filesize($path) : false;
if ($size === false || $size < 1024) {
    if (is_file($path)) {
        @unlink($path);
    }
    json_out(['ok' => false, 'error' => 'Generated archive failed validation'], 500);
}
register_shutdown_function(static function () use ($path): void {
    if (is_file($path)) {
        @unlink($path);
    }
});

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/zip');
header('X-Content-Type-Options: nosniff');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, no-store, no-cache, must-revalidate, no-transform');
header('Pragma: no-cache');
header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . (string)$size);

$sent = readfile($path);
if ($sent === false) {
    error_log('[conversion] failed to stream ' . $path);
}
exit;
