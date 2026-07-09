<?php
declare(strict_types=1);

/**
 * HotFetched — sound library installer (CLI only, launched detached).
 * Downloads the MIT-licensed ldrolez/free-midi-chords progressions pack
 * and indexes it for the melody import browser.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/bootstrap.php';

putenv('HOME=/tmp');

function soundlib_fail(string $msg): never
{
    soundlib_state_write(['state' => 'error', 'error' => $msg]);
    exit(0);
}

$dir = soundlib_dir();
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    soundlib_fail('Cannot create sound library directory');
}

// Resolve the latest release's progressions asset (unauthenticated API).
$ch = curl_init('https://api.github.com/repos/ldrolez/free-midi-chords/releases/latest');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_USERAGENT      => 'HotFetched/' . HF_VERSION,
    CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
]);
$resp = curl_exec($ch);
curl_close($ch);

$assetUrl = null;
if (is_string($resp)) {
    $j = json_decode($resp, true);
    foreach (($j['assets'] ?? []) as $a) {
        if (preg_match('/^free-midi-progressions-.*\.zip$/', (string)($a['name'] ?? ''))
            && (int)($a['size'] ?? 0) < 64 * 1024 * 1024) {
            $assetUrl = (string)$a['browser_download_url'];
            break;
        }
    }
}
if ($assetUrl === null || !str_starts_with($assetUrl, 'https://github.com/ldrolez/free-midi-chords/releases/')) {
    soundlib_fail('Could not resolve the library release asset from GitHub');
}

$zipPath = $dir . '/pack.zip';
$fh = fopen($zipPath, 'wb');
if ($fh === false) {
    soundlib_fail('Cannot write download file');
}
$ch = curl_init($assetUrl);
curl_setopt_array($ch, [
    CURLOPT_FILE           => $fh,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_USERAGENT      => 'HotFetched/' . HF_VERSION,
]);
$okDl = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);
fclose($fh);

if ($okDl !== true || filesize($zipPath) < 1024) {
    @unlink($zipPath);
    soundlib_fail('Download failed: ' . ($err ?: 'empty file'));
}

$midDir = $dir . '/midi';
$extractErr = safe_zip_extract($zipPath, $midDir);
@unlink($zipPath);
if ($extractErr !== null) {
    soundlib_fail($extractErr);
}

// Build the flat index of .mid files (relative paths).
$index = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($midDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'mid') {
        $index[] = ltrim(substr($f->getPathname(), strlen($midDir)), '/');
    }
}
sort($index);

if ($index === []) {
    soundlib_fail('Archive contained no MIDI files');
}
if (@file_put_contents($dir . '/index.json', json_encode($index, JSON_UNESCAPED_SLASHES)) === false) {
    soundlib_fail('Could not write library index');
}

soundlib_state_write(['state' => 'ready', 'count' => count($index), 'source' => $assetUrl, 'license' => 'MIT (ldrolez/free-midi-chords)']);
exit(0);
