<?php
declare(strict_types=1);

$failures = [];
$pio = '/opt/pio-venv/bin/pio';
if (!is_executable($pio)) {
    $failures[] = 'PlatformIO executable is missing';
} else {
    exec(escapeshellarg($pio) . ' --version 2>&1', $out, $code);
    if ($code !== 0) {
        $failures[] = 'PlatformIO failed: ' . implode(' ', $out);
    }
}

foreach (['/var/www/html/private', '/var/www/html/private/projects', '/opt/platformio'] as $dir) {
    if (!is_dir($dir) || !is_writable($dir)) {
        $failures[] = "Not writable: {$dir}";
    }
}

$validator = '/opt/hotfetched/tools/validate_boards.php';
if (!is_file($validator)) {
    $failures[] = 'Board validator is missing';
} else {
    exec('php ' . escapeshellarg($validator) . ' /var/www/html/webroot/boards 2>&1', $out, $code);
    if ($code !== 0) {
        $failures[] = 'Board profile validation failed: ' . implode(' ', $out);
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "HotFetched compiler container healthy\n";
