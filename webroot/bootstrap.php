<?php
declare(strict_types=1);

/**
 * HotFetched — bootstrap
 * PHP 8.3 / Apache / SQLite. Follows FarFetched conventions:
 *  - busy_timeout set BEFORE journal_mode
 *  - schema init only on fresh DB; migrations run once per process
 *  - all writes parameterized; no string interpolation into SQL
 */

const HF_VERSION = '0.1.0';

define('HF_PRIVATE_DIR', getenv('PRIVATE_DIR') ?: '/var/www/html/private');
define('HF_DB_PATH', HF_PRIVATE_DIR . '/hotfetched.sqlite');
define('HF_PROJECTS_DIR', HF_PRIVATE_DIR . '/projects');
define('HF_BOARDS_DIR', __DIR__ . '/boards');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function csrf_token(): string { return $_SESSION['csrf']; }

function csrf_verify(?string $token): void
{
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        json_out(['ok' => false, 'error' => 'CSRF token invalid']);
    }
}

function json_out(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------------------------------------------------------- DB */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(HF_PRIVATE_DIR) && !@mkdir(HF_PRIVATE_DIR, 0775, true)) {
        throw new RuntimeException('Cannot create private dir: ' . HF_PRIVATE_DIR);
    }
    if (!is_dir(HF_PROJECTS_DIR)) {
        @mkdir(HF_PROJECTS_DIR, 0775, true);
    }

    $fresh = !file_exists(HF_DB_PATH);

    $pdo = new PDO('sqlite:' . HF_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Order matters: busy_timeout BEFORE journal_mode (FarFetched lesson).
    $pdo->exec('PRAGMA busy_timeout = 8000');
    if ($fresh) {
        $pdo->exec('PRAGMA journal_mode = WAL');
        init_schema($pdo);
    }
    $pdo->exec('PRAGMA foreign_keys = ON');

    run_migrations($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projects (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            name         TEXT NOT NULL UNIQUE,
            firmware     TEXT NOT NULL CHECK(firmware IN ('marlin','klipper')),
            board_id     TEXT NOT NULL,
            mcu_variant  TEXT,
            source_type  TEXT CHECK(source_type IN ('github','zip')),
            source_ref   TEXT,
            source_state TEXT NOT NULL DEFAULT 'none'
                         CHECK(source_state IN ('none','fetching','ready','error')),
            source_error TEXT,
            source_detect TEXT,
            created_at   TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS config_values (
            project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            field_key   TEXT NOT NULL,
            field_value TEXT NOT NULL,
            updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (project_id, field_key)
        );
        CREATE TABLE IF NOT EXISTS builds (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id    INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            status        TEXT NOT NULL DEFAULT 'queued'
                          CHECK(status IN ('queued','validating','building','success','failed')),
            confidence    INTEGER,
            gate_json     TEXT,
            log_path      TEXT,
            artifact_path TEXT,
            started_at    TEXT,
            finished_at   TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_builds_project ON builds(project_id, id DESC);
    ");
}

/**
 * Idempotent, declarative column migrations. Append rows as
 * [table, column, ALTER statement]; each ALTER runs only if the column
 * is missing. Guarded to run once per process.
 */
function run_migrations(PDO $pdo): void
{
    if (!empty($GLOBALS['__hf_migrated'])) {
        return;
    }
    $GLOBALS['__hf_migrated'] = true;

    // Ensure base tables exist even on pre-existing DB files.
    init_schema($pdo);

    $columns = [
        ['projects', 'source_error',  "ALTER TABLE projects ADD COLUMN source_error TEXT"],
        ['projects', 'source_detect', "ALTER TABLE projects ADD COLUMN source_detect TEXT"],
    ];

    foreach ($columns as [$table, $column, $ddl]) {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM pragma_table_info(?) WHERE name = ?");
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetch()['c'] === 0) {
            $pdo->exec($ddl);
        }
    }
}

/* ------------------------------------------------------------- Boards */

/**
 * Load all board definitions from webroot/boards/*.json.
 * Returns map keyed by board id. Malformed files are skipped and logged.
 */
function board_defs(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (glob(HF_BOARDS_DIR . '/*.json') ?: [] as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $def = json_decode($raw, true);
        if (!is_array($def) || empty($def['id']) || empty($def['name']) || empty($def['mcu_variants'])) {
            error_log('[boards] skipping malformed board file: ' . basename($path));
            continue;
        }
        $cache[$def['id']] = $def;
    }
    ksort($cache);
    return $cache;
}

function board_def(string $id): ?array
{
    return board_defs()[$id] ?? null;
}

function board_mcu_variant(array $board, string $variantId): ?array
{
    foreach ($board['mcu_variants'] as $v) {
        if (($v['id'] ?? '') === $variantId) {
            return $v;
        }
    }
    return null;
}

/* ----------------------------------------------------------- Projects */

function project_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function project_dir(int $id): string
{
    return HF_PROJECTS_DIR . '/' . $id;
}

/**
 * Recursive delete constrained to HF_PROJECTS_DIR (path-traversal guard).
 */
function project_dir_delete(int $id): void
{
    $dir = realpath(project_dir($id));
    $root = realpath(HF_PROJECTS_DIR);
    if ($dir === false || $root === false || !str_starts_with($dir, $root . '/')) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/**
 * Validate a project name: 1-64 chars, letters/digits/space/dash/underscore/dot.
 */
function valid_project_name(string $name): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/', $name);
}

/* --------------------------------------------------- Source acquisition */

const HF_GITHUB_HOSTS      = ['github.com', 'www.github.com'];
const HF_ZIP_MAX_ENTRIES   = 30000;
const HF_ZIP_MAX_EXTRACTED = 2147483648; // 2 GiB uncompressed cap (zip-bomb guard)
const HF_CLONE_TIMEOUT_S   = 600;

/**
 * Validate + normalize a GitHub repo URL. Returns canonical
 * 'https://github.com/{owner}/{repo}' or null.
 */
function github_url_normalize(string $url): ?string
{
    $url = trim($url);
    $p = parse_url($url);
    if (!is_array($p)) {
        return null;
    }
    $host = strtolower($p['host'] ?? '');
    if (($p['scheme'] ?? '') !== 'https' || !in_array($host, HF_GITHUB_HOSTS, true)) {
        return null;
    }
    if (!empty($p['user']) || !empty($p['pass']) || !empty($p['port']) || !empty($p['query']) || !empty($p['fragment'])) {
        return null;
    }
    $segments = array_values(array_filter(explode('/', $p['path'] ?? ''), 'strlen'));
    if (count($segments) < 2) {
        return null;
    }
    [$owner, $repo] = $segments;
    $repo = preg_replace('/\.git$/', '', $repo);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $owner)
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $repo)) {
        return null;
    }
    return "https://github.com/{$owner}/{$repo}";
}

function valid_git_ref(string $ref): bool
{
    return $ref === '' || (bool)preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $ref);
}

function project_source_dir(int $id): string
{
    return project_dir($id) . '/source';
}

function project_upload_zip(int $id): string
{
    return project_dir($id) . '/upload.zip';
}

function project_fetch_log(int $id): string
{
    return project_dir($id) . '/fetch.log';
}

/**
 * Detached worker launch (FarFetched pattern) — never blocks the request.
 */
function source_worker_launch(int $projectId): void
{
    // PHP_BINARY under Apache mod_php resolves to the apache2 binary, NOT the
    // PHP CLI — always resolve the CLI explicitly.
    $php = '/usr/local/bin/php';
    if (!is_executable($php)) {
        $php = trim((string)shell_exec('command -v php')) ?: PHP_BINARY;
    }
    $script = __DIR__ . '/source_worker.php';
    $cmd = sprintf(
        'setsid nohup %s %s %d < /dev/null > /dev/null 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($script),
        $projectId
    );
    shell_exec($cmd);
}

/**
 * Extract a ZIP with zip-slip, entry-count and uncompressed-size guards.
 * Returns null on success, error string on failure.
 */
function safe_zip_extract(string $zipPath, string $destDir, ?callable $progress = null): ?string
{
    $magic = @file_get_contents($zipPath, false, null, 0, 4);
    if ($magic === false || !str_starts_with($magic, "PK\x03\x04")) {
        return 'File is not a valid ZIP archive';
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return 'Unable to open ZIP archive';
    }

    if ($zip->numFiles > HF_ZIP_MAX_ENTRIES) {
        $zip->close();
        return 'Archive exceeds maximum entry count';
    }

    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) {
            $zip->close();
            return 'Unreadable archive entry';
        }
        $name = (string)$st['name'];
        // zip-slip: reject absolute paths, drive letters, parent traversal
        if (str_starts_with($name, '/') || str_contains($name, '..') || preg_match('/^[A-Za-z]:/', $name)) {
            $zip->close();
            return 'Archive contains unsafe paths (rejected)';
        }
        $total += (int)$st['size'];
        if ($total > HF_ZIP_MAX_EXTRACTED) {
            $zip->close();
            return 'Archive uncompressed size exceeds 2 GiB cap';
        }
    }

    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true)) {
        $zip->close();
        return 'Cannot create extraction directory';
    }

    $n = $zip->numFiles;
    for ($i = 0; $i < $n; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            $zip->close();
            return 'Unreadable archive entry';
        }
        if (!$zip->extractTo($destDir, $name)) {
            $zip->close();
            return 'Extraction failed at entry ' . $i;
        }
        if ($progress !== null && ($i % 250 === 0 || $i === $n - 1)) {
            $progress($i + 1, $n);
        }
    }
    $zip->close();
    return null;
}

/**
 * Locate the firmware tree root inside a source dir (handles the
 * single-subfolder layout of GitHub release ZIPs). Returns
 * ['root' => relpath, 'files' => [...]] or null with $error set.
 */
function detect_firmware_tree(string $sourceDir, string $firmware, ?string &$error): ?array
{
    $error = null;
    $candidates = [$sourceDir];
    // GitHub ZIPs wrap everything in one top-level folder — include those.
    foreach (glob($sourceDir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $candidates[] = $d;
    }

    foreach ($candidates as $root) {
        if ($firmware === 'marlin') {
            $conf    = $root . '/Marlin/Configuration.h';
            $confAdv = $root . '/Marlin/Configuration_adv.h';
            $pio     = $root . '/platformio.ini';
            if (is_file($conf) && is_file($confAdv) && is_file($pio)) {
                $rel = substr($root, strlen($sourceDir));
                $rel = ltrim($rel, '/');
                return [
                    'root'  => $rel,
                    'files' => [
                        'configuration'     => ($rel === '' ? '' : $rel . '/') . 'Marlin/Configuration.h',
                        'configuration_adv' => ($rel === '' ? '' : $rel . '/') . 'Marlin/Configuration_adv.h',
                        'platformio_ini'    => ($rel === '' ? '' : $rel . '/') . 'platformio.ini',
                    ],
                ];
            }
        } else { // klipper
            $mk  = $root . '/Makefile';
            $src = $root . '/src';
            $kpy = $root . '/klippy';
            if (is_file($mk) && is_dir($src) && is_dir($kpy)) {
                $rel = substr($root, strlen($sourceDir));
                $rel = ltrim($rel, '/');
                $refCfg = ($rel === '' ? '' : $rel . '/') . 'config/generic-bigtreetech-skr-3.cfg';
                return [
                    'root'  => $rel,
                    'files' => [
                        'makefile'         => ($rel === '' ? '' : $rel . '/') . 'Makefile',
                        'reference_config' => is_file($sourceDir . '/' . $refCfg) ? $refCfg : null,
                    ],
                ];
            }
        }
    }

    $error = $firmware === 'marlin'
        ? 'Not a Marlin source tree (expected Marlin/Configuration.h, Marlin/Configuration_adv.h, platformio.ini)'
        : 'Not a Klipper source tree (expected Makefile, src/, klippy/)';
    return null;
}

/**
 * Recursive delete of a project's source dir, contained under HF_PROJECTS_DIR.
 */
function source_dir_reset(int $id): void
{
    $dir  = project_source_dir($id);
    if (!is_dir($dir)) {
        return;
    }
    $real = realpath($dir);
    $root = realpath(HF_PROJECTS_DIR);
    if ($real === false || $root === false || !str_starts_with($real, $root . '/')) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($real);
}

/* ------------------------------------------------- Marlin config engine */

/**
 * Parse a Marlin configuration header into an editable document.
 * Returns ['lines' => string[], 'defines' => [KEY => ['line' => int,
 * 'enabled' => bool, 'value' => ?string]]]. When a key appears more than
 * once, an enabled occurrence wins over a commented one.
 */
function marlin_config_parse(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $lines = preg_split("/\r\n|\n|\r/", $raw);
    $defines = [];

    foreach ($lines as $i => $line) {
        if (!preg_match('#^(\s*)(//)?\s*\#define\s+([A-Za-z_]\w*)(\s+(.*))?$#', $line, $m)) {
            continue;
        }
        $key     = $m[3];
        $enabled = ($m[2] ?? '') !== '//';
        [$value, ] = marlin_split_value_comment($m[5] ?? '');
        $entry = ['line' => $i, 'enabled' => $enabled, 'value' => $value === '' ? null : $value];

        if (!isset($defines[$key]) || (!$defines[$key]['enabled'] && $enabled)) {
            $defines[$key] = $entry;
        }
    }
    return ['lines' => $lines, 'defines' => $defines];
}

/**
 * Split a define's remainder into [value, trailingComment], respecting
 * string literals and brace/paren nesting so "//" inside them is kept.
 */
function marlin_split_value_comment(string $rest): array
{
    $rest = rtrim($rest);
    $inStr = false;
    $depth = 0;
    $len = strlen($rest);
    for ($i = 0; $i < $len - 1; $i++) {
        $ch = $rest[$i];
        if ($ch === '"' && ($i === 0 || $rest[$i - 1] !== '\\')) {
            $inStr = !$inStr;
        } elseif (!$inStr) {
            if ($ch === '{' || $ch === '(') $depth++;
            elseif ($ch === '}' || $ch === ')') $depth--;
            elseif ($ch === '/' && $rest[$i + 1] === '/' && $depth <= 0) {
                return [rtrim(substr($rest, 0, $i)), substr($rest, $i)];
            }
        }
    }
    return [$rest, ''];
}

/**
 * Set/enable/disable a define in a parsed document (surgical: only the
 * one line is rewritten; indentation and trailing comment are preserved).
 * Returns false if the key is not present in the file.
 */
function marlin_config_set(array &$doc, string $key, ?string $value, bool $enable = true): bool
{
    if (!isset($doc['defines'][$key])) {
        return false;
    }
    $idx  = $doc['defines'][$key]['line'];
    $line = $doc['lines'][$idx];

    if (!preg_match('#^(\s*)(//)?\s*\#define\s+([A-Za-z_]\w*)(\s+(.*))?$#', $line, $m)) {
        return false;
    }
    $indent = $m[1];
    [, $comment] = marlin_split_value_comment($m[5] ?? '');

    $new = $indent . ($enable ? '' : '//') . '#define ' . $key;
    if ($value !== null && $value !== '') {
        $new .= ' ' . $value;
    }
    if ($comment !== '') {
        $new .= '  ' . $comment;
    }
    $doc['lines'][$idx] = $new;
    $doc['defines'][$key]['enabled'] = $enable;
    $doc['defines'][$key]['value']   = $value;
    return true;
}

function marlin_config_write(array $doc, string $path): bool
{
    return @file_put_contents($path, implode("\n", $doc['lines'])) !== false;
}

/** Pull the N numeric magnitudes out of an array/expression value. */
function marlin_extract_numbers(?string $value, int $count): array
{
    if ($value === null) {
        return array_fill(0, $count, null);
    }
    preg_match_all('/-?\d+(?:\.\d+)?/', $value, $m);
    $nums = array_map('floatval', $m[0]);
    return array_pad(array_slice($nums, 0, $count), $count, null);
}

/**
 * Curated editable fields for a Marlin project, validated against the
 * board definition. Each: key, label, group, type, and constraints.
 * type: text | int | select | bool
 */
function marlin_field_defs(array $board): array
{
    $lim     = $board['limits'];
    $drivers = $board['marlin']['valid_drivers'];

    return [
        ['key' => 'machine_name', 'label' => 'Machine name', 'group' => 'Machine',
         'type' => 'text', 'maxlen' => 40],

        ['key' => 'driver_x',  'label' => 'X stepper driver',  'group' => 'Stepper Drivers', 'type' => 'select', 'options' => $drivers],
        ['key' => 'driver_y',  'label' => 'Y stepper driver',  'group' => 'Stepper Drivers', 'type' => 'select', 'options' => $drivers],
        ['key' => 'driver_z',  'label' => 'Z stepper driver',  'group' => 'Stepper Drivers', 'type' => 'select', 'options' => $drivers],
        ['key' => 'driver_e0', 'label' => 'E0 stepper driver', 'group' => 'Stepper Drivers', 'type' => 'select', 'options' => $drivers],
        ['key' => 'driver_e1', 'label' => 'E1 stepper driver', 'group' => 'Stepper Drivers', 'type' => 'select', 'options' => $drivers,
         'requires' => ['extruders' => '2']],

        ['key' => 'extruders',    'label' => 'Extruders', 'group' => 'Extruder', 'type' => 'select', 'options' => ['1', '2']],
        ['key' => 'singlenozzle', 'label' => 'Dual extruder, single nozzle (SINGLENOZZLE)', 'group' => 'Extruder', 'type' => 'bool',
         'requires' => ['extruders' => '2']],

        ['key' => 'bed_x',  'label' => 'Bed size X (mm)', 'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => (int)$lim['max_bed_x']],
        ['key' => 'bed_y',  'label' => 'Bed size Y (mm)', 'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => (int)$lim['max_bed_y']],
        ['key' => 'z_max',  'label' => 'Z height (mm)',   'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => (int)$lim['max_z']],

        ['key' => 'feed_x', 'label' => 'Max feedrate X (mm/s)', 'group' => 'Speed', 'type' => 'int', 'min' => 10, 'max' => (int)$lim['max_feedrate_xy']],
        ['key' => 'feed_y', 'label' => 'Max feedrate Y (mm/s)', 'group' => 'Speed', 'type' => 'int', 'min' => 10, 'max' => (int)$lim['max_feedrate_xy']],
        ['key' => 'feed_z', 'label' => 'Max feedrate Z (mm/s)', 'group' => 'Speed', 'type' => 'int', 'min' => 1,  'max' => (int)$lim['max_feedrate_z']],
        ['key' => 'feed_e', 'label' => 'Max feedrate E (mm/s)', 'group' => 'Speed', 'type' => 'int', 'min' => 5,  'max' => 120],
        ['key' => 'homing_xy', 'label' => 'Homing speed XY (mm/s)', 'group' => 'Speed', 'type' => 'int', 'min' => 5, 'max' => 150],
        ['key' => 'homing_z',  'label' => 'Homing speed Z (mm/s)',  'group' => 'Speed', 'type' => 'int', 'min' => 1, 'max' => 30],

        ['key' => 'hotend_maxtemp', 'label' => 'Nozzle max temp (°C)', 'group' => 'Thermal', 'type' => 'int', 'min' => 150, 'max' => (int)$lim['max_hotend_temp']],
        ['key' => 'bed_maxtemp',    'label' => 'Bed max temp (°C)',    'group' => 'Thermal', 'type' => 'int', 'min' => 60,  'max' => (int)$lim['max_bed_temp']],
        ['key' => 'temp_sensor_0',   'label' => 'Hotend temp sensor (TEMP_SENSOR_0)',  'group' => 'Thermal', 'type' => 'int', 'min' => -100, 'max' => 10000],
        ['key' => 'temp_sensor_bed', 'label' => 'Bed temp sensor (TEMP_SENSOR_BED)',   'group' => 'Thermal', 'type' => 'int', 'min' => -100, 'max' => 10000],
    ];
}

/**
 * Read current values for all curated fields out of a parsed
 * Configuration.h document.
 */
function marlin_current_values(array $doc): array
{
    $d = $doc['defines'];
    $get = fn (string $k) => $d[$k] ?? null;

    $strip = function (?array $e): ?string {
        if ($e === null || $e['value'] === null) return null;
        return trim((string)$e['value'], "\" \t");
    };
    $num = function (?array $e): ?string {
        if ($e === null || $e['value'] === null) return null;
        return preg_match('/-?\d+/', (string)$e['value'], $m) ? $m[0] : null;
    };
    $driver = function (?array $e): ?string {
        if ($e === null || $e['value'] === null || !$e['enabled']) return null;
        return trim((string)$e['value']);
    };

    [$fx, $fy, $fz, $fe] = marlin_extract_numbers($get('DEFAULT_MAX_FEEDRATE')['value'] ?? null, 4);

    // HOMING_FEEDRATE_MM_M is mm/min, typically as (N*60) — normalize to mm/s.
    $homing = $get('HOMING_FEEDRATE_MM_M')['value'] ?? null;
    $hxy = $hz = null;
    if ($homing !== null) {
        if (preg_match_all('/\((\d+)\s*\*\s*60\)/', $homing, $m) && count($m[1]) >= 3) {
            $hxy = $m[1][0];
            $hz  = $m[1][2];
        } else {
            [$a, , $c] = marlin_extract_numbers($homing, 3);
            $hxy = $a !== null ? (string)(int)round($a / 60) : null;
            $hz  = $c !== null ? (string)(int)round($c / 60) : null;
        }
    }

    $mn = $get('CUSTOM_MACHINE_NAME');

    return [
        'machine_name' => $mn !== null && $mn['enabled'] ? $strip($mn) : null,
        'driver_x'  => $driver($get('X_DRIVER_TYPE')),
        'driver_y'  => $driver($get('Y_DRIVER_TYPE')),
        'driver_z'  => $driver($get('Z_DRIVER_TYPE')),
        'driver_e0' => $driver($get('E0_DRIVER_TYPE')),
        'driver_e1' => $driver($get('E1_DRIVER_TYPE')),
        'extruders'    => $num($get('EXTRUDERS')),
        'singlenozzle' => ($get('SINGLENOZZLE')['enabled'] ?? false) ? '1' : '0',
        'bed_x' => $num($get('X_BED_SIZE')),
        'bed_y' => $num($get('Y_BED_SIZE')),
        'z_max' => $num($get('Z_MAX_POS')),
        'feed_x' => $fx !== null ? (string)(int)$fx : null,
        'feed_y' => $fy !== null ? (string)(int)$fy : null,
        'feed_z' => $fz !== null ? (string)(int)$fz : null,
        'feed_e' => $fe !== null ? (string)(int)$fe : null,
        'homing_xy' => $hxy,
        'homing_z'  => $hz,
        'hotend_maxtemp'  => $num($get('HEATER_0_MAXTEMP')),
        'bed_maxtemp'     => $num($get('BED_MAXTEMP')),
        'temp_sensor_0'   => $num($get('TEMP_SENSOR_0')),
        'temp_sensor_bed' => $num($get('TEMP_SENSOR_BED')),
    ];
}

/**
 * Apply validated field values to a parsed Configuration.h document,
 * including board-locked defines (MOTHERBOARD, SERIAL_PORT).
 * Returns list of applied define names.
 */
function marlin_apply_values(array &$doc, array $v, array $board): array
{
    $applied = [];
    $set = function (string $key, ?string $value, bool $enable = true) use (&$doc, &$applied): void {
        if (marlin_config_set($doc, $key, $value, $enable)) {
            $applied[] = $key;
        }
    };

    // Board-locked
    $set('MOTHERBOARD', (string)$board['marlin']['motherboard']);
    $set('SERIAL_PORT', (string)($board['marlin']['serial_ports'][0] ?? 1));

    // Machine
    $name = str_replace('"', '', (string)$v['machine_name']);
    $set('CUSTOM_MACHINE_NAME', '"' . $name . '"');

    // Drivers
    $set('X_DRIVER_TYPE',  (string)$v['driver_x']);
    $set('Y_DRIVER_TYPE',  (string)$v['driver_y']);
    $set('Z_DRIVER_TYPE',  (string)$v['driver_z']);
    $set('E0_DRIVER_TYPE', (string)$v['driver_e0']);

    $dual = ($v['extruders'] === '2');
    $set('EXTRUDERS', $dual ? '2' : '1');
    if ($dual) {
        $set('E1_DRIVER_TYPE', (string)$v['driver_e1']);
        $set('SINGLENOZZLE', null, $v['singlenozzle'] === '1');
    } else {
        $set('E1_DRIVER_TYPE', $doc['defines']['E1_DRIVER_TYPE']['value'] ?? 'A4988', false);
        $set('SINGLENOZZLE', null, false);
    }

    // Geometry
    $set('X_BED_SIZE', (string)(int)$v['bed_x']);
    $set('Y_BED_SIZE', (string)(int)$v['bed_y']);
    $set('Z_MAX_POS',  (string)(int)$v['z_max']);

    // Speed
    $set('DEFAULT_MAX_FEEDRATE', sprintf('{ %d, %d, %d, %d }',
        (int)$v['feed_x'], (int)$v['feed_y'], (int)$v['feed_z'], (int)$v['feed_e']));
    $set('HOMING_FEEDRATE_MM_M', sprintf('{ (%d*60), (%d*60), (%d*60) }',
        (int)$v['homing_xy'], (int)$v['homing_xy'], (int)$v['homing_z']));

    // Thermal
    $set('HEATER_0_MAXTEMP', (string)(int)$v['hotend_maxtemp']);
    $set('BED_MAXTEMP',      (string)(int)$v['bed_maxtemp']);
    $set('TEMP_SENSOR_0',    (string)(int)$v['temp_sensor_0']);
    $set('TEMP_SENSOR_BED',  (string)(int)$v['temp_sensor_bed']);

    return $applied;
}
