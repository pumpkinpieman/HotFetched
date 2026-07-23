<?php
declare(strict_types=1);

/**
 * Return every branch and tag available from a validated GitHub repository.
 * Read-only endpoint used by the project source-version dropdown.
 */
require dirname(__DIR__) . '/bootstrap.php';

header('Cache-Control: no-store');

$firmware = strtolower(trim((string)($_GET['firmware'] ?? '')));
if (!in_array($firmware, ['marlin', 'klipper'], true)) {
    json_out(['ok' => false, 'error' => 'Unsupported firmware type'], 400);
}

$official = [
    'marlin' => [
        'url' => 'https://github.com/MarlinFirmware/Marlin',
        'ref' => 'bugfix-2.1.x',
    ],
    'klipper' => [
        'url' => 'https://github.com/Klipper3d/klipper',
        'ref' => 'master',
    ],
];

$requestedUrl = trim((string)($_GET['url'] ?? ''));
$url = github_url_normalize($requestedUrl !== '' ? $requestedUrl : $official[$firmware]['url']);
if ($url === null) {
    json_out(['ok' => false, 'error' => 'Enter a valid public GitHub repository URL'], 400);
}

$cacheDir = HF_PRIVATE_DIR . '/cache/source-versions';
if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
    json_out(['ok' => false, 'error' => 'Unable to create source-version cache'], 500);
}

$cacheKey  = hash('sha256', strtolower($url));
$cachePath = $cacheDir . '/' . $cacheKey . '.json';
$cacheTtl  = 1800; // Tags do not change often; protects GitHub and keeps the UI quick.

if (is_file($cachePath) && time() - (int)filemtime($cachePath) < $cacheTtl) {
    $cached = json_decode((string)@file_get_contents($cachePath), true);
    if (is_array($cached) && isset($cached['branches'], $cached['tags'])) {
        $cached['ok'] = true;
        $cached['cached'] = true;
        json_out($cached);
    }
}

// github_url_normalize limits this to github.com and valid owner/repo syntax.
// escapeshellarg is still used defensively before the validated URL reaches git.
$cmd = 'timeout 30 git -c protocol.file.allow=never ls-remote --refs --heads --tags '
     . escapeshellarg($url) . ' 2>&1';
$output = [];
$status = 0;
exec($cmd, $output, $status);

if ($status !== 0) {
    $detail = trim(implode("\n", array_slice($output, -3)));
    json_out([
        'ok' => false,
        'error' => $status === 124
            ? 'GitHub version lookup timed out'
            : 'Unable to read versions from this GitHub repository',
        'detail' => substr($detail, 0, 500),
    ], 502);
}

$branches = [];
$tags = [];
foreach ($output as $line) {
    if (!preg_match('/^[0-9a-f]{40,64}\s+refs\/(heads|tags)\/(.+)$/i', trim($line), $m)) {
        continue;
    }
    $name = trim($m[2]);
    if ($name === '' || str_ends_with($name, '^{}')) {
        continue;
    }
    if ($m[1] === 'heads') {
        $branches[$name] = true;
    } else {
        $tags[$name] = true;
    }
}

$branches = array_keys($branches);
$tags = array_keys($tags);

$branchPriority = static function (string $name): int {
    return match ($name) {
        'bugfix-2.1.x' => 0,
        'bugfix-2.0.x' => 1,
        'main'         => 2,
        'master'       => 3,
        default        => str_starts_with($name, 'release-') ? 4 : 10,
    };
};
usort($branches, static function (string $a, string $b) use ($branchPriority): int {
    $pa = $branchPriority($a);
    $pb = $branchPriority($b);
    return $pa === $pb ? strnatcasecmp($a, $b) : ($pa <=> $pb);
});

usort($tags, static function (string $a, string $b): int {
    $av = ltrim($a, 'vV');
    $bv = ltrim($b, 'vV');
    $aVersion = preg_match('/^\d+(?:\.\d+)+(?:[-+._][0-9A-Za-z.-]+)?$/', $av) === 1;
    $bVersion = preg_match('/^\d+(?:\.\d+)+(?:[-+._][0-9A-Za-z.-]+)?$/', $bv) === 1;
    if ($aVersion && $bVersion) {
        return version_compare($bv, $av); // newest first
    }
    if ($aVersion !== $bVersion) {
        return $aVersion ? -1 : 1;
    }
    return strnatcasecmp($b, $a);
});

$defaultRef = strcasecmp($url, $official[$firmware]['url']) === 0
    ? $official[$firmware]['ref']
    : '';

$payload = [
    'ok' => true,
    'cached' => false,
    'url' => $url,
    'default_ref' => $defaultRef,
    'branches' => $branches,
    'tags' => $tags,
    'counts' => ['branches' => count($branches), 'tags' => count($tags)],
];

@file_put_contents(
    $cachePath,
    json_encode(array_diff_key($payload, ['ok' => true, 'cached' => true]), JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

json_out($payload);
