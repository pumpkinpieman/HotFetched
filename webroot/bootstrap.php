<?php
declare(strict_types=1);

/**
 * HotFetched — bootstrap
 * PHP 8.3 / Apache / SQLite. Follows FarFetched conventions:
 *  - busy_timeout set BEFORE journal_mode
 *  - schema init only on fresh DB; migrations run once per process
 *  - all writes parameterized; no string interpolation into SQL
 */

const HF_VERSION = '3.6.2';

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
            firmware     TEXT NOT NULL CHECK(firmware IN ('marlin','klipper','reprap')),
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

    // The projects.firmware CHECK originally allowed only marlin|klipper. SQLite
    // can't ALTER a CHECK constraint, so rebuild the table when 'reprap' is
    // missing. Data is preserved; this runs once and is a no-op afterwards.
    $sql = (string)$pdo->query(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='projects'"
    )->fetchColumn();
    if ($sql !== '' && !str_contains($sql, "'reprap'")) {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
        $pdo->exec("
            CREATE TABLE projects_new (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                name         TEXT NOT NULL UNIQUE,
                firmware     TEXT NOT NULL CHECK(firmware IN ('marlin','klipper','reprap')),
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
            )
        ");
        $pdo->exec("
            INSERT INTO projects_new
                (id, name, firmware, board_id, mcu_variant, source_type, source_ref,
                 source_state, source_error, source_detect, created_at, updated_at)
            SELECT id, name, firmware, board_id, mcu_variant, source_type, source_ref,
                   source_state, source_error, source_detect, created_at, updated_at
            FROM projects
        ");
        $pdo->exec('DROP TABLE projects');
        $pdo->exec('ALTER TABLE projects_new RENAME TO projects');
        $pdo->commit();
        $pdo->exec('PRAGMA foreign_keys = ON');
        error_log('[HotFetched] migrated projects.firmware CHECK to allow reprap');
    }

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

function board_supports(array $board, string $firmware): bool
{
    $fs = $board['firmware_support'] ?? null;
    if (!is_array($fs)) {
        return true; // legacy board, assume both
    }
    return (bool)($fs[$firmware] ?? false);
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

function build_dir(int $projectId, int $buildId): string
{
    return project_dir($projectId) . '/builds/' . $buildId;
}

/**
 * Sweep ALL stale builds (any project): queued with no worker signs for 3
 * minutes, or active with a silent log for 15 minutes. Called on every
 * build API hit so abandoned pages can't strand rows forever.
 */
function builds_sweep_stale(): void
{
    $rows = db()->query(
        "SELECT id, status, log_path, started_at FROM builds
         WHERE status IN ('queued','validating','building')"
    )->fetchAll();
    foreach ($rows as $b) {
        $hasLog = is_string($b['log_path']) && is_file($b['log_path']);
        $ref = $hasLog
            ? (int)filemtime($b['log_path'])
            : ($b['started_at'] !== null ? (int)strtotime((string)$b['started_at'] . ' UTC') : 0);
        $limit = (!$hasLog && $b['status'] === 'queued') ? 180 : 900;
        if ($ref > 0 && time() - $ref > $limit) {
            db()->prepare("UPDATE builds SET status = 'failed', finished_at = datetime('now')
                           WHERE id = ? AND status IN ('queued','validating','building')")
                ->execute([(int)$b['id']]);
        }
    }
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
                // The specific reference_config is board-defined and resolved at
                // build/config time. Detection only records that this is a valid
                // Klipper tree and where its config/ directory lives.
                $configDir = ($rel === '' ? '' : $rel . '/') . 'config';
                return [
                    'root'  => $rel,
                    'files' => [
                        'makefile'   => ($rel === '' ? '' : $rel . '/') . 'Makefile',
                        'config_dir' => is_dir($sourceDir . '/' . $configDir) ? $configDir : null,
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

    $eSlots = count(array_filter($board['marlin']['driver_slots'] ?? [], fn ($s) => str_starts_with(strtoupper((string)$s), 'E')));
    $eSlots = max(1, $eSlots);

    return [
        ['key' => 'machine_name', 'label' => 'Machine name', 'group' => 'Machine',
         'type' => 'text', 'maxlen' => 40],

        ['key' => 'driver_x',  'label' => 'X stepper driver',  'group' => 'Stepper Drivers', 'type' => 'select', 'options' => $drivers],
        ['key' => 'driver_y',  'label' => 'Y stepper driver',  'group' => 'Stepper Drivers', 'type' => 'select', 'options' => $drivers],
        ['key' => 'driver_z',  'label' => 'Z stepper driver',  'group' => 'Stepper Drivers', 'type' => 'select', 'options' => $drivers],
        ['key' => 'driver_e0', 'label' => 'E0 stepper driver', 'group' => 'Stepper Drivers', 'type' => 'select', 'options' => $drivers],
        ['key' => 'driver_e1', 'label' => 'E1+ stepper driver (used for every extra extruder)', 'group' => 'Stepper Drivers',
         'type' => 'select', 'options' => $drivers,
         'requires' => ['extruders' => ['2', '3', '4', '5', '6', '7', '8']]],

        ['key' => 'extruders',    'label' => 'Extruders (logical tools)', 'group' => 'Extruder', 'type' => 'select',
         // Standard printer configurations expose 1-5 logical tools. The special
         // value 12 is reserved for the custom Prusa MMU3-12x firmware.
         'options' => ['1', '2', '3', '4', '5', '12'],
         'hint' => $eSlots . ' physical E slot(s) on this board; more than that requires an MMU',
         'warning_text' => 'Only select if you have 12 extruder MMU3',
         'warning_values' => ['12'],
         'warning_link' => 'https://github.com/cjbaar/Prusa-Firmware-MMU-12x',
         'warning_link_text' => 'Prusa MMU3-12x firmware'],
        ['key' => 'singlenozzle', 'label' => 'Multiple extruders share one nozzle (SINGLENOZZLE)', 'group' => 'Extruder', 'type' => 'bool',
         'requires' => ['extruders' => ['2', '3', '4', '5', '6', '7', '8']]],

        ['key' => 'bed_x',  'label' => 'Bed size X (mm)', 'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => max(2000, (int)($lim['max_bed_x'] ?? 0))],
        ['key' => 'bed_y',  'label' => 'Bed size Y (mm)', 'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => max(2000, (int)($lim['max_bed_y'] ?? 0))],
        ['key' => 'z_max',  'label' => 'Z height (mm)',   'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => max(2000, (int)($lim['max_z'] ?? 0))],

        ['key' => 'feed_x', 'label' => 'Max feedrate X (mm/s)', 'group' => 'Speed', 'type' => 'int', 'min' => 10, 'max' => (int)$lim['max_feedrate_xy']],
        ['key' => 'feed_y', 'label' => 'Max feedrate Y (mm/s)', 'group' => 'Speed', 'type' => 'int', 'min' => 10, 'max' => (int)$lim['max_feedrate_xy']],
        ['key' => 'feed_z', 'label' => 'Max feedrate Z (mm/s)', 'group' => 'Speed', 'type' => 'int', 'min' => 1,  'max' => (int)$lim['max_feedrate_z']],
        ['key' => 'feed_e', 'label' => 'Max feedrate E (mm/s)', 'group' => 'Speed', 'type' => 'int', 'min' => 5,  'max' => 120],
        ['key' => 'homing_xy', 'label' => 'Homing speed XY (mm/s)', 'group' => 'Speed', 'type' => 'int', 'min' => 5, 'max' => 150],
        ['key' => 'homing_z',  'label' => 'Homing speed Z (mm/s)',  'group' => 'Speed', 'type' => 'int', 'min' => 1, 'max' => 30],

        ['key' => 'thermal_override', 'label' => 'Override thermal limits', 'group' => 'Thermal', 'type' => 'bool',
         'warning_text' => "Only override if you know what you're doing!"],
        ['key' => 'hotend_maxtemp', 'label' => 'Nozzle max temp (°C)', 'group' => 'Thermal', 'type' => 'int', 'min' => 150, 'max' => (int)$lim['max_hotend_temp'],
         'override_key' => 'thermal_override', 'override_max' => 999],
        ['key' => 'bed_maxtemp',    'label' => 'Bed max temp (°C)',    'group' => 'Thermal', 'type' => 'int', 'min' => 60,  'max' => (int)$lim['max_bed_temp'],
         'override_key' => 'thermal_override', 'override_max' => 300],
        ['key' => 'temp_sensor_0',   'label' => 'Hotend temp sensor (TEMP_SENSOR_0)',  'group' => 'Thermal', 'type' => 'int', 'min' => 1, 'max' => 1000],
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

    // Extruders. Marlin distinguishes EXTRUDERS (logical tools) from E_STEPPERS
    // (physical motors). A Prusa MMU2/MMU3 reports 5 tools but drives only ONE
    // E motor (the unit's own selector picks the filament), so declaring
    // E1..E4_DRIVER_TYPE there would demand pins the board doesn't have.
    // Driver types must therefore follow the PHYSICAL stepper count.
    $nExt = max(1, min(12, (int)($v['extruders'] ?? 1)));
    $set('EXTRUDERS', (string)$nExt);

    $mmu       = (string)($v['mmu_model'] ?? 'none');
    $mmuSingle = in_array($mmu, ['PRUSA_MMU2', 'PRUSA_MMU2S', 'PRUSA_MMU3',
                                 'EXTENDABLE_EMU_MMU2', 'EXTENDABLE_EMU_MMU2S'], true);
    $eSteppers = $mmuSingle ? 1 : $nExt;

    $extraDrv = (string)($v['driver_e1'] ?? ($doc['defines']['E0_DRIVER_TYPE']['value'] ?? 'A4988'));
    for ($i = 1; $i <= 7; $i++) {
        $key = 'E' . $i . '_DRIVER_TYPE';
        if ($i < $eSteppers) {
            $set($key, $extraDrv, true);
        } else {
            $set($key, $doc['defines'][$key]['value'] ?? 'A4988', false);
        }
    }

    // MMU_MODEL already implies SINGLENOZZLE in Marlin, so don't fight it.
    $set('SINGLENOZZLE', null, !$mmuSingle && $nExt >= 2 && ($v['singlenozzle'] ?? '0') === '1');

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

/* ------------------------------------------ Phase 4: display/probe/audio */

const HF_TUNE_PRESETS = [
    'chime_up'   => '{ 523,120, 0,40, 659,120, 0,40, 784,180 }',
    'chime_down' => '{ 784,120, 0,40, 659,120, 0,40, 523,180 }',
    'triple'     => '{ 880,90, 0,60, 880,90, 0,60, 880,90 }',
];

const HF_EVENT_PRESETS = [
    'none'       => [],
    'single'     => [[880, 120]],
    'double'     => [[880, 100], [0, 60], [880, 100]],
    'triple'     => [[880, 90], [0, 60], [880, 90], [0, 60], [880, 90]],
    'chime_up'   => [[523, 120], [0, 40], [659, 120], [0, 40], [784, 180]],
    'chime_down' => [[784, 120], [0, 40], [659, 120], [0, 40], [523, 180]],
    'alarm'      => [[220, 350], [0, 120], [220, 350], [0, 120], [220, 350]],
];

/** Phase-4 field groups, appended to the curated Marlin fields. */
function marlin_field_defs_extended(array $board): array
{
    $screens = $board['marlin']['screens'] ?? [];
    $probes  = $board['marlin']['probes'] ?? [];

    $screenIds    = array_column($screens, 'id');
    $screenLabels = array_combine($screenIds, array_column($screens, 'label'));
    $probeIds     = array_column($probes, 'id');
    $probeLabels  = array_combine($probeIds, array_column($probes, 'label'));
    $probeActive  = array_values(array_filter($probeIds, fn ($p) => $p !== 'none'));

    $tuneOpts  = array_merge(['keep', 'silent'], array_keys(HF_TUNE_PRESETS), ['custom']);
    $tuneLabels = ['keep' => 'Keep current', 'silent' => 'Silent (no tune)',
                   'chime_up' => 'Chime up', 'chime_down' => 'Chime down',
                   'triple' => 'Triple beep', 'custom' => 'Custom sequence'];
    $evOpts = array_merge(array_keys(HF_EVENT_PRESETS), ['custom']);

    $fields = [
        ['key' => 'screen', 'label' => 'Screen / display', 'group' => 'Display',
         'type' => 'select', 'options' => $screenIds, 'option_labels' => $screenLabels],

        ['key' => 'show_bootscreen', 'label' => 'Show boot screen on startup', 'group' => 'Boot & Display Images', 'type' => 'bool'],
        ['key' => 'boot_logo_size', 'label' => 'Marlin boot logo', 'group' => 'Boot & Display Images', 'type' => 'select',
         'options' => ['full', 'small', 'animated'],
         'option_labels' => ['full' => 'Full Marlin logo', 'small' => 'Small logo (saves flash)', 'animated' => 'Animated logo (~3KB flash)'],
         'requires' => ['show_bootscreen' => ['1']]],
        ['key' => 'custom_status_image', 'label' => 'Enable custom status screen image', 'group' => 'Boot & Display Images', 'type' => 'bool'],

        ['key' => 'probe', 'label' => 'Bed probe', 'group' => 'Probe',
         'type' => 'select', 'options' => $probeIds, 'option_labels' => $probeLabels],
        ['key' => 'probe_off_x', 'label' => 'Probe offset X (mm)', 'group' => 'Probe',
         'type' => 'float', 'min' => -100, 'max' => 100, 'requires' => ['probe' => $probeActive]],
        ['key' => 'probe_off_y', 'label' => 'Probe offset Y (mm)', 'group' => 'Probe',
         'type' => 'float', 'min' => -100, 'max' => 100, 'requires' => ['probe' => $probeActive]],
        ['key' => 'probe_off_z', 'label' => 'Probe offset Z (mm)', 'group' => 'Probe',
         'type' => 'float', 'min' => -10, 'max' => 10, 'requires' => ['probe' => $probeActive]],

    ];

    // Audio only exists if the board actually has a beeper (it normally lives on
    // the LCD's EXP header). Boards without one - e.g. the SKR Pico - get no
    // audio fields at all, rather than settings that cannot compile.
    if ($board['ui']['beeper'] ?? true) {
        $fields[] = ['key' => 'speaker', 'label' => 'Speaker fitted (SPEAKER — piezo tones)', 'group' => 'Audio', 'type' => 'bool'];
        $fields[] = ['key' => 'startup_tune', 'label' => 'Power-on tune (STARTUP_TUNE)', 'group' => 'Audio',
                     'type' => 'select', 'options' => $tuneOpts, 'option_labels' => $tuneLabels];
        $fields[] = ['key' => 'startup_tune_custom', 'label' => 'Custom tune (freq,ms pairs e.g. 523,120,0,40,784,180)',
                     'group' => 'Audio', 'type' => 'text', 'maxlen' => 2000,
                     'requires' => ['startup_tune' => ['custom']]];
    }

    foreach ([
        'ev_print_start' => 'Print start sound',
        'ev_print_pause' => 'Print paused sound',
        'ev_print_error' => 'Print error sound',
        'ev_print_end'   => 'Print end sound',
        'ev_connect'     => 'Connectivity issue sound',
    ] as $key => $label) {
        $fields[] = ['key' => $key, 'label' => $label, 'group' => 'Audio (host events)',
                     'type' => 'select', 'options' => $evOpts];
        $fields[] = ['key' => $key . '_custom', 'label' => $label . ' — custom (freq,ms pairs)',
                     'group' => 'Audio (host events)', 'type' => 'text', 'maxlen' => 2000,
                     'requires' => [$key => ['custom']]];
    }
    return $fields;
}

/** Current values for phase-4 fields from a parsed Configuration.h. */
function marlin_current_values_extended(array $doc, array $board): array
{
    $d = $doc['defines'];

    $screen = 'none';
    foreach (($board['marlin']['screens'] ?? []) as $s) {
        $type = (string)($s['type'] ?? '');
        if (in_array($type, ['mono128x64', 'char20x4'], true)
            && ($d[$s['id']]['enabled'] ?? false)) {
            $screen = $s['id'];
            break;
        }
    }
    if ($screen === 'none' && ($d['SERIAL_PORT_2']['enabled'] ?? false)) {
        $screen = 'btt_serial_tft';
    }

    $probe = 'none';
    if ($d['BLTOUCH']['enabled'] ?? false) {
        $probe = 'bltouch';
    } elseif ($d['FIX_MOUNTED_PROBE']['enabled'] ?? false) {
        $probe = ($d['Z_MIN_PROBE_USES_Z_MIN_ENDSTOP_PIN']['enabled'] ?? false) ? 'fixed_probe_zmin' : 'fixed_probe_port';
    }

    [$px, $py, $pz] = marlin_extract_numbers($d['NOZZLE_TO_PROBE_OFFSET']['value'] ?? null, 3);

    $showBoot = ($d['SHOW_BOOTSCREEN']['enabled'] ?? false) ? '1' : '0';
    $bootLogo = 'full';
    if ($d['BOOT_MARLIN_LOGO_ANIMATED']['enabled'] ?? false) {
        $bootLogo = 'animated';
    } elseif ($d['BOOT_MARLIN_LOGO_SMALL']['enabled'] ?? false) {
        $bootLogo = 'small';
    }
    $customStatus = ($d['CUSTOM_STATUS_SCREEN_IMAGE']['enabled'] ?? false) ? '1' : '0';

    return [
        'show_bootscreen' => $showBoot,
        'boot_logo_size' => $bootLogo,
        'custom_status_image' => $customStatus,
        'screen' => $screen,
        'probe'  => $probe,
        'probe_off_x' => $px !== null ? rtrim(rtrim(sprintf('%.2f', $px), '0'), '.') : '0',
        'probe_off_y' => $py !== null ? rtrim(rtrim(sprintf('%.2f', $py), '0'), '.') : '0',
        'probe_off_z' => $pz !== null ? rtrim(rtrim(sprintf('%.2f', $pz), '0'), '.') : '0',
        'speaker' => ($d['SPEAKER']['enabled'] ?? false) ? '1' : '0',
        'startup_tune' => 'keep',
        'startup_tune_custom' => '',
        'ev_print_start' => 'single', 'ev_print_pause' => 'double',
        'ev_print_error' => 'alarm',  'ev_print_end' => 'chime_up',
        'ev_connect' => 'triple',
        'ev_print_start_custom' => '', 'ev_print_pause_custom' => '',
        'ev_print_error_custom' => '', 'ev_print_end_custom' => '',
        'ev_connect_custom' => '',
    ];
}

/** Apply phase-4 values to Configuration.h (display, probe, audio). */
function marlin_apply_values_extended(array &$doc, array $v, array $board): array
{
    $applied = [];
    $set = function (string $key, ?string $value, bool $enable = true) use (&$doc, &$applied): void {
        if (marlin_config_set($doc, $key, $value, $enable)) {
            $applied[] = $key;
        }
    };
    $keepValue = fn (string $key): ?string => $doc['defines'][$key]['value'] ?? null;

    // Display: enable the chosen mono screen, disable the others; serial TFT
    // enables the secondary serial port instead.
    // A BTT TFT wired to EXP1/EXP2 can run in "Marlin mode", where it emulates a
    // 12864 controller - that gives real MarlinUI menus and a beeper. The user
    // flips modes on the panel itself; the firmware just needs the 12864 define
    // enabled, plus the serial port for touch mode. So the dual-mode option sets
    // BOTH. Touch-only mode sets just the serial port (no MarlinUI, no beeper).
    $selScreen = (string)($v['screen'] ?? '');
    $mono12864 = null;
    foreach (($board['marlin']['screens'] ?? []) as $s) {
        $t = (string)($s['type'] ?? '');
        if (in_array($t, ['mono128x64', 'char20x4'], true)) {
            $set($s['id'], null, $selScreen === $s['id']);
            if ($s['id'] === 'REPRAP_DISCOUNT_FULL_GRAPHIC_SMART_CONTROLLER') {
                $mono12864 = $s['id'];
            }
        } elseif ($t === 'marlinui_tft' && $selScreen === $s['id']) {
            // Emulated 12864: enable the controller define this TFT pretends to be.
            $def = (string)($s['define'] ?? 'REPRAP_DISCOUNT_FULL_GRAPHIC_SMART_CONTROLLER');
            $set($def, null, true);
        }
    }
    $isTft = ($selScreen === 'btt_serial_tft' || $selScreen === 'btt_tft_marlin_mode');
    $set('SERIAL_PORT_2', '-1', $isTft);

    // Probe
    foreach (($board['marlin']['probes'] ?? []) as $p) {
        if ($p['id'] !== $v['probe']) {
            continue;
        }
        foreach ($p['enable'] as $k) {
            $set($k, $keepValue($k), true);
        }
        foreach ($p['disable'] as $k) {
            $set($k, $keepValue($k), false);
        }
    }
    if ($v['probe'] !== 'none') {
        $fmt = fn (string $n): string => rtrim(rtrim(sprintf('%.2f', (float)$n), '0'), '.');
        $set('NOZZLE_TO_PROBE_OFFSET', sprintf('{ %s, %s, %s }',
            $fmt($v['probe_off_x']), $fmt($v['probe_off_y']), $fmt($v['probe_off_z'])));
    }

    // Audio. STARTUP_TUNE needs ALL(HAS_BEEPER, SPEAKER) - and BEEPER_PIN comes
    // from the LCD's EXP header, so it is unavailable without a MarlinUI display.
    // Force the tune off in that case rather than emitting a config that can't build.
    // No beeper on the board at all -> never write SPEAKER/STARTUP_TUNE.
    $hasBeeper = (bool)($board['ui']['beeper'] ?? true);
    $hasUI = $hasBeeper && marlin_screen_has_marlinui($board, (string)($v['screen'] ?? ''));
    $set('SPEAKER', null, $hasBeeper && (($v['speaker'] ?? '0') === '1'));
    if (!$hasUI || $v['speaker'] !== '1') {
        $set('STARTUP_TUNE', $keepValue('STARTUP_TUNE'), false);
        return $applied;
    }
    switch ($v['startup_tune']) {
        case 'keep':
            break;
        case 'silent':
            $set('STARTUP_TUNE', $keepValue('STARTUP_TUNE'), false);
            break;
        case 'custom':
            $nums = array_map('intval', array_filter(array_map('trim', explode(',', (string)$v['startup_tune_custom'])), 'strlen'));
            if (count($nums) >= 2 && count($nums) % 2 === 0) {
                $set('STARTUP_TUNE', '{ ' . implode(', ', $nums) . ' }');
            }
            break;
        default:
            if (isset(HF_TUNE_PRESETS[$v['startup_tune']])) {
                $set('STARTUP_TUNE', HF_TUNE_PRESETS[$v['startup_tune']]);
            }
    }

    return $applied;
}


/* -------------------------------------------------- Tier 2: quality/QOL */

/** Tier-2 fields. Config.h: EEPROM, PID, runout. adv.h: linear advance, power-loss. */
function marlin_field_defs_tier2(array $board): array
{
    return [
        ['key' => 'eeprom', 'label' => 'EEPROM settings (M500/M501 save)', 'group' => 'Storage & Recovery', 'type' => 'bool'],
        ['key' => 'eeprom_auto_init', 'label' => 'Auto-init EEPROM on error', 'group' => 'Storage & Recovery', 'type' => 'bool',
         'requires' => ['eeprom' => ['1']]],
        ['key' => 'power_loss', 'label' => 'Power-loss recovery', 'group' => 'Storage & Recovery', 'type' => 'bool'],

        ['key' => 'pid_hotend', 'label' => 'Hotend PID (PIDTEMP)', 'group' => 'PID Tuning', 'type' => 'bool'],
        ['key' => 'pid_kp', 'label' => 'Hotend Kp (from M303)', 'group' => 'PID Tuning', 'type' => 'float', 'min' => 0, 'max' => 999, 'requires' => ['pid_hotend' => ['1']]],
        ['key' => 'pid_ki', 'label' => 'Hotend Ki', 'group' => 'PID Tuning', 'type' => 'float', 'min' => 0, 'max' => 999, 'requires' => ['pid_hotend' => ['1']]],
        ['key' => 'pid_kd', 'label' => 'Hotend Kd', 'group' => 'PID Tuning', 'type' => 'float', 'min' => 0, 'max' => 9999, 'requires' => ['pid_hotend' => ['1']]],
        ['key' => 'pid_bed', 'label' => 'Bed PID (PIDTEMPBED)', 'group' => 'PID Tuning', 'type' => 'bool'],
        ['key' => 'bed_kp', 'label' => 'Bed Kp (from M303 E-1)', 'group' => 'PID Tuning', 'type' => 'float', 'min' => 0, 'max' => 999, 'requires' => ['pid_bed' => ['1']]],
        ['key' => 'bed_ki', 'label' => 'Bed Ki', 'group' => 'PID Tuning', 'type' => 'float', 'min' => 0, 'max' => 999, 'requires' => ['pid_bed' => ['1']]],
        ['key' => 'bed_kd', 'label' => 'Bed Kd', 'group' => 'PID Tuning', 'type' => 'float', 'min' => 0, 'max' => 9999, 'requires' => ['pid_bed' => ['1']]],

        ['key' => 'lin_advance', 'label' => 'Linear Advance', 'group' => 'Tuning', 'type' => 'bool'],
        ['key' => 'advance_k', 'label' => 'Advance K factor', 'group' => 'Tuning', 'type' => 'float', 'min' => 0, 'max' => 10, 'requires' => ['lin_advance' => ['1']]],

        ['key' => 'runout', 'label' => 'Filament runout sensor', 'group' => 'Filament', 'type' => 'bool'],
        ['key' => 'runout_enabled', 'label' => 'Enabled on startup', 'group' => 'Filament', 'type' => 'bool', 'requires' => ['runout' => ['1']]],
    ];
}

/** Tier-2 current values from both parsed docs. */
function marlin_current_values_tier2(array $doc, array $adv): array
{
    $d = $doc['defines']; $a = $adv['defines'];
    $on = fn (array $s, string $k): string => ($s[$k]['enabled'] ?? false) ? '1' : '0';
    $bool = function (array $s, string $k, string $def = '0'): string {
        if (!isset($s[$k])) return $def;
        return strtolower(trim((string)($s[$k]['value'] ?? 'true'))) === 'false' ? '0' : '1';
    };
    $num = function (array $s, string $k): ?string {
        $e = $s[$k] ?? null;
        if ($e === null || $e['value'] === null) return null;
        return preg_match('/-?\d+(?:\.\d+)?/', (string)$e['value'], $m) ? $m[0] : null;
    };
    [$ak] = marlin_extract_numbers($a['ADVANCE_K']['value'] ?? null, 1);
    return [
        'eeprom' => $on($d, 'EEPROM_SETTINGS'),
        'eeprom_auto_init' => $on($d, 'EEPROM_AUTO_INIT'),
        'power_loss' => $on($a, 'POWER_LOSS_RECOVERY'),
        'pid_hotend' => $on($d, 'PIDTEMP'),
        'pid_kp' => $num($d, 'DEFAULT_KP') ?? '22.20',
        'pid_ki' => $num($d, 'DEFAULT_KI') ?? '1.08',
        'pid_kd' => $num($d, 'DEFAULT_KD') ?? '114.00',
        'pid_bed' => $on($d, 'PIDTEMPBED'),
        'bed_kp' => $num($d, 'DEFAULT_BED_KP') ?? '10.00',
        'bed_ki' => $num($d, 'DEFAULT_BED_KI') ?? '0.023',
        'bed_kd' => $num($d, 'DEFAULT_BED_KD') ?? '305.4',
        'lin_advance' => $on($a, 'LIN_ADVANCE'),
        'advance_k' => $ak !== null ? rtrim(rtrim(sprintf('%.3f', (float)$ak), '0'), '.') : '0.22',
        'runout' => $on($d, 'FILAMENT_RUNOUT_SENSOR'),
        'runout_enabled' => $bool($d, 'FIL_RUNOUT_ENABLED_DEFAULT', '1'),
    ];
}

/** Apply Tier-2 values to Configuration.h. */
function marlin_apply_values_tier2_conf(array &$doc, array $v): array
{
    $applied = [];
    $set = function (string $k, ?string $val, bool $en = true) use (&$doc, &$applied): void {
        if (marlin_config_set($doc, $k, $val, $en)) $applied[] = $k;
    };
    $keep = fn (string $k): ?string => $doc['defines'][$k]['value'] ?? null;

    $set('EEPROM_SETTINGS', null, ($v['eeprom'] ?? '0') === '1');
    if (($v['eeprom'] ?? '0') === '1') {
        $set('EEPROM_AUTO_INIT', null, ($v['eeprom_auto_init'] ?? '0') === '1');
    }

    $pidH = ($v['pid_hotend'] ?? '0') === '1';
    $set('PIDTEMP', $keep('PIDTEMP'), $pidH);
    if ($pidH) {
        $set('DEFAULT_KP', rtrim(rtrim(sprintf('%.2f', (float)$v['pid_kp']), '0'), '.'));
        $set('DEFAULT_KI', rtrim(rtrim(sprintf('%.3f', (float)$v['pid_ki']), '0'), '.'));
        $set('DEFAULT_KD', rtrim(rtrim(sprintf('%.2f', (float)$v['pid_kd']), '0'), '.'));
    }
    $pidB = ($v['pid_bed'] ?? '0') === '1';
    $set('PIDTEMPBED', $keep('PIDTEMPBED'), $pidB);
    if ($pidB) {
        $set('DEFAULT_BED_KP', rtrim(rtrim(sprintf('%.2f', (float)$v['bed_kp']), '0'), '.'));
        $set('DEFAULT_BED_KI', rtrim(rtrim(sprintf('%.3f', (float)$v['bed_ki']), '0'), '.'));
        $set('DEFAULT_BED_KD', rtrim(rtrim(sprintf('%.2f', (float)$v['bed_kd']), '0'), '.'));
    }

    $ro = ($v['runout'] ?? '0') === '1';
    $set('FILAMENT_RUNOUT_SENSOR', $keep('FILAMENT_RUNOUT_SENSOR'), $ro);
    if ($ro) {
        $set('FIL_RUNOUT_ENABLED_DEFAULT', ($v['runout_enabled'] ?? '1') === '1' ? 'true' : 'false');
        // Runout -> ADVANCED_PAUSE_FEATURE (in _adv.h) -> NOZZLE_PARK_FEATURE,
        // which lives here in Configuration.h. Marlin hard-errors without it.
        $set('NOZZLE_PARK_FEATURE', $keep('NOZZLE_PARK_FEATURE'), true);
    }
    return $applied;
}

/** Apply Tier-2 values to Configuration_adv.h. */
function marlin_apply_values_tier2_adv(array &$adv, array $v): array
{
    $applied = [];
    $set = function (string $k, ?string $val, bool $en = true) use (&$adv, &$applied): void {
        if (marlin_config_set($adv, $k, $val, $en)) $applied[] = $k;
    };
    $keep = fn (string $k): ?string => $adv['defines'][$k]['value'] ?? null;

    $set('POWER_LOSS_RECOVERY', $keep('POWER_LOSS_RECOVERY'), ($v['power_loss'] ?? '0') === '1');

    $la = ($v['lin_advance'] ?? '0') === '1';
    $set('LIN_ADVANCE', $keep('LIN_ADVANCE'), $la);
    if ($la) {
        $set('ADVANCE_K', rtrim(rtrim(sprintf('%.3f', (float)$v['advance_k']), '0'), '.'));
    }

    // Marlin requires ADVANCED_PAUSE_FEATURE (for M600) when the runout sensor
    // is enabled — a compile-time static_assert. Enable it to satisfy that.
    // ADVANCED_PAUSE_FEATURE in turn requires a Marlin-native LCD controller OR
    // EMERGENCY_PARSER. Boards driven by an external-firmware TFT (or no display)
    // have no native LCD, so enable EMERGENCY_PARSER to satisfy that dependency.
    // It is host/serial-side only and safe to enable regardless of display type.
    if (($v['runout'] ?? '0') === '1') {
        $set('ADVANCED_PAUSE_FEATURE', $keep('ADVANCED_PAUSE_FEATURE'), true);
        $set('EMERGENCY_PARSER', $keep('EMERGENCY_PARSER'), true);
    }
    return $applied;
}


/* ------------------------------------------------ Tier 3: bed leveling */

/** Find the first line index matching $regex at or after $from. Null if absent. */
function marlin_find_line(array $doc, string $regex, int $from = 0): ?int
{
    $n = count($doc['lines']);
    for ($i = $from; $i < $n; $i++) {
        if (preg_match($regex, (string)$doc['lines'][$i])) {
            return $i;
        }
    }
    return null;
}

/**
 * Set a #define that occurs INSIDE a specific line range. Marlin declares
 * GRID_MAX_POINTS_X / MESH_INSET separately inside each leveling block
 * (LINEAR|BILINEAR, UBL, MESH), so a key-indexed set would hit the wrong one.
 */
function marlin_config_set_range(array &$doc, string $key, ?string $value, bool $enable, int $from, int $to): bool
{
    $pat = '#^(\s*)(//)?\s*\#define\s+' . preg_quote($key, '#') . '(\s+(.*))?$#';
    for ($i = $from; $i <= $to && $i < count($doc['lines']); $i++) {
        $line = (string)$doc['lines'][$i];
        if (!preg_match($pat, $line, $m)) {
            continue;
        }
        $indent = $m[1];
        [, $comment] = marlin_split_value_comment($m[4] ?? '');
        $new = $indent . ($enable ? '' : '//') . '#define ' . $key;
        if ($value !== null && $value !== '') {
            $new .= ' ' . $value;
        }
        if ($comment !== '') {
            $new .= '  ' . $comment;
        }
        $doc['lines'][$i] = $new;
        return true;
    }
    return false;
}

/** Leveling modes -> the Marlin define that selects them. */
function marlin_leveling_modes(): array
{
    return [
        'none'     => null,
        '3point'   => 'AUTO_BED_LEVELING_3POINT',
        'linear'   => 'AUTO_BED_LEVELING_LINEAR',
        'bilinear' => 'AUTO_BED_LEVELING_BILINEAR',
        'ubl'      => 'AUTO_BED_LEVELING_UBL',
        'mesh'     => 'MESH_BED_LEVELING',
    ];
}

/** Modes that need a bed probe (MESH is probeless/manual). */
function marlin_leveling_needs_probe(string $mode): bool
{
    return in_array($mode, ['3point', 'linear', 'bilinear', 'ubl'], true);
}

/** Modes that use a probe grid (3POINT has no grid). */
function marlin_leveling_has_grid(string $mode): bool
{
    return in_array($mode, ['linear', 'bilinear', 'ubl', 'mesh'], true);
}

function marlin_field_defs_leveling(array $board): array
{
    return [
        ['key' => 'leveling', 'label' => 'Bed leveling', 'group' => 'Bed Leveling', 'type' => 'select',
         'options' => ['none', 'bilinear', 'ubl', 'linear', '3point', 'mesh'],
         'option_labels' => [
             'none'     => 'None (disabled)',
             'bilinear' => 'Bilinear ABL (probe grid - most common)',
             'ubl'      => 'UBL (probe grid + manual edit; needs EEPROM)',
             'linear'   => 'Linear ABL (probe grid, tilted plane)',
             '3point'   => '3-Point ABL (probe, plane only)',
             'mesh'     => 'Manual Mesh (no probe needed)',
         ]],
        // Grid range 3-15 is the intersection that satisfies every mode:
        // LINEAR/MESH need >=2, BILINEAR needs >=3, UBL is capped at 15.
        ['key' => 'grid_points', 'label' => 'Grid points per axis', 'group' => 'Bed Leveling',
         'type' => 'int', 'min' => 3, 'max' => 15,
         'requires' => ['leveling' => ['linear', 'bilinear', 'ubl', 'mesh']]],
        ['key' => 'fade_height', 'label' => 'Leveling fade height (mm, 0 = off)', 'group' => 'Bed Leveling',
         'type' => 'float', 'min' => 0, 'max' => 100,
         'requires' => ['leveling' => ['bilinear', 'ubl', 'linear', 'mesh']]],
        ['key' => 'level_after_g28', 'label' => 'After G28 homing', 'group' => 'Bed Leveling', 'type' => 'select',
         'options' => ['none', 'restore', 'enable'],
         'option_labels' => [
             'none'    => 'Leave leveling off',
             'restore' => 'Restore previous leveling state',
             'enable'  => 'Always enable leveling',
         ],
         'requires' => ['leveling' => ['3point', 'linear', 'bilinear', 'ubl', 'mesh']]],
        ['key' => 'z_safe_homing', 'label' => 'Z safe homing (home Z at bed center — recommended with a probe)',
         'group' => 'Bed Leveling', 'type' => 'bool'],
    ];
}

function marlin_current_values_leveling(array $doc): array
{
    $d = $doc['defines'];
    $mode = 'none';
    foreach (marlin_leveling_modes() as $key => $def) {
        if ($def !== null && ($d[$def]['enabled'] ?? false)) {
            $mode = $key;
            break;
        }
    }
    // Read the grid size from whichever block is active.
    $grid = '3';
    $start = marlin_leveling_block_start($doc, $mode);
    if ($start !== null) {
        $end = marlin_find_line($doc, '#^\s*#(elif|endif)\b#', $start + 1) ?? ($start + 60);
        for ($i = $start; $i <= $end && $i < count($doc['lines']); $i++) {
            if (preg_match('#^\s*\#define\s+GRID_MAX_POINTS_X\s+(\d+)#', (string)$doc['lines'][$i], $m)) {
                $grid = $m[1];
                break;
            }
        }
    }

    $after = 'none';
    if ($d['RESTORE_LEVELING_AFTER_G28']['enabled'] ?? false) {
        $after = 'restore';
    } elseif ($d['ENABLE_LEVELING_AFTER_G28']['enabled'] ?? false) {
        $after = 'enable';
    }

    $fade = '0';
    if ($d['ENABLE_LEVELING_FADE_HEIGHT']['enabled'] ?? false) {
        $raw = (string)($d['DEFAULT_LEVELING_FADE_HEIGHT']['value'] ?? '10.0');
        $fade = preg_match('/-?\d+(?:\.\d+)?/', $raw, $m) ? $m[0] : '10';
    }

    return [
        'leveling'        => $mode,
        'grid_points'     => $grid,
        'fade_height'     => $fade,
        'level_after_g28' => $after,
        'z_safe_homing'   => ($d['Z_SAFE_HOMING']['enabled'] ?? false) ? '1' : '0',
    ];
}

/** Line index of the #if/#elif guard that opens the given mode's option block. */
function marlin_leveling_block_start(array $doc, string $mode): ?int
{
    return match ($mode) {
        'linear', 'bilinear' => marlin_find_line($doc, '#^\s*\#if\s+ANY\(AUTO_BED_LEVELING_LINEAR,\s*AUTO_BED_LEVELING_BILINEAR\)#'),
        'ubl'                => marlin_find_line($doc, '#^\s*\#elif\s+ENABLED\(AUTO_BED_LEVELING_UBL\)#'),
        'mesh'               => marlin_find_line($doc, '#^\s*\#elif\s+ENABLED\(MESH_BED_LEVELING\)#'),
        default              => null,
    };
}

/** Apply leveling settings to Configuration.h. */
function marlin_apply_values_leveling(array &$doc, array $v): array
{
    $applied = [];
    $set = function (string $k, ?string $val, bool $en = true) use (&$doc, &$applied): void {
        if (marlin_config_set($doc, $k, $val, $en)) {
            $applied[] = $k;
        }
    };

    $mode  = (string)($v['leveling'] ?? 'none');
    $modes = marlin_leveling_modes();

    // Exactly one leveling type may be enabled — Marlin hard-errors otherwise.
    foreach ($modes as $key => $def) {
        if ($def === null) {
            continue;
        }
        $set($def, null, $key === $mode);
    }

    if ($mode === 'none') {
        // Turn off dependent options so nothing dangles.
        $set('RESTORE_LEVELING_AFTER_G28', null, false);
        $set('ENABLE_LEVELING_AFTER_G28', null, false);
        return $applied;
    }

    // Grid points go inside the ACTIVE block (each block declares its own).
    if (marlin_leveling_has_grid($mode)) {
        $start = marlin_leveling_block_start($doc, $mode);
        if ($start !== null) {
            $end = marlin_find_line($doc, '#^\s*\#(elif|endif)\b#', $start + 1) ?? ($start + 60);
            $g   = (string)max(3, min(15, (int)($v['grid_points'] ?? 3)));
            if (marlin_config_set_range($doc, 'GRID_MAX_POINTS_X', $g, true, $start, $end)) {
                $applied[] = 'GRID_MAX_POINTS_X';
            }
        }
    }

    // Fade height: 0 disables the feature entirely.
    $fade = (float)($v['fade_height'] ?? 0);
    if ($fade > 0) {
        $set('ENABLE_LEVELING_FADE_HEIGHT', null, true);
        $set('DEFAULT_LEVELING_FADE_HEIGHT', rtrim(rtrim(sprintf('%.1f', $fade), '0'), '.') . '');
    } else {
        $set('ENABLE_LEVELING_FADE_HEIGHT', null, false);
    }

    // Only ONE of these two may be on (Marlin errors if both).
    $after = (string)($v['level_after_g28'] ?? 'none');
    $set('RESTORE_LEVELING_AFTER_G28', null, $after === 'restore');
    $set('ENABLE_LEVELING_AFTER_G28', null, $after === 'enable');

    // UBL cannot function without EEPROM (mesh must persist) — Marlin errors.
    if ($mode === 'ubl') {
        $set('EEPROM_SETTINGS', null, true);
    }

    $set('Z_SAFE_HOMING', null, ($v['z_safe_homing'] ?? '0') === '1');

    return $applied;
}


/* ----------------------------------------------------------- WiFi (ESP) */

/**
 * WiFi fields. Marlin's WIFISUPPORT drives an ESP32 WiFi-101 module on the
 * board's SPI WiFi header — only boards whose pins file declares those pins
 * can use it, so this is gated on board data. Note this is NOT the same as
 * plugging an ESP-01 into a UART as a plain serial bridge (which needs no
 * Marlin feature at all, just a second serial port).
 */
function marlin_field_defs_wifi(array $board): array
{
    if (!($board['wifi']['supported'] ?? false)) {
        // Marlin's WIFISUPPORT compiles ESP3DLib/AsyncTCP/WebSockets, which are
        // ESP32-core libraries - it only builds when the MAINBOARD is an ESP32.
        // None of the boards shipped here are, so the feature is not offered.
        // (An ESP-01 in the board's WiFi header runs its own firmware and needs
        // nothing from Marlin - it's just a serial device.)
        return [];
    }
    return [
        ['key' => 'wifi', 'label' => 'WiFi module (WIFISUPPORT — ESP on the board WiFi header)',
         'group' => 'WiFi', 'type' => 'bool'],
        ['key' => 'wifi_web', 'label' => 'Web server (WEBSUPPORT)', 'group' => 'WiFi', 'type' => 'bool',
         'requires' => ['wifi' => ['1']]],
        ['key' => 'wifi_ota', 'label' => 'Over-the-air updates (OTASUPPORT)', 'group' => 'WiFi', 'type' => 'bool',
         'requires' => ['wifi' => ['1']]],
    ];
}

function marlin_current_values_wifi(array $adv, array $board): array
{
    if (!($board['wifi']['supported'] ?? false)) {
        return [];
    }
    $a = $adv['defines'];
    $on = fn (string $k): string => ($a[$k]['enabled'] ?? false) ? '1' : '0';
    return [
        'wifi'     => $on('WIFISUPPORT'),
        'wifi_web' => $on('WEBSUPPORT'),
        'wifi_ota' => $on('OTASUPPORT'),
    ];
}

/** Apply WiFi settings to Configuration_adv.h. */
function marlin_apply_values_wifi(array &$adv, array $v, array $board): array
{
    if (!($board['wifi']['supported'] ?? false)) {
        return [];
    }
    $applied = [];
    $set = function (string $k, ?string $val, bool $en = true) use (&$adv, &$applied): void {
        if (marlin_config_set($adv, $k, $val, $en)) {
            $applied[] = $k;
        }
    };

    $wifi = ($v['wifi'] ?? '0') === '1';
    $set('WIFISUPPORT', null, $wifi);
    // ESP3D_WIFISUPPORT is mutually exclusive with WIFISUPPORT and needs an
    // ESP32 motherboard — never valid on the boards we ship. Keep it off.
    $set('ESP3D_WIFISUPPORT', null, false);

    // WEBSUPPORT / OTASUPPORT are only legal with WIFISUPPORT enabled.
    $set('WEBSUPPORT', null, $wifi && ($v['wifi_web'] ?? '0') === '1');
    $set('OTASUPPORT', null, $wifi && ($v['wifi_ota'] ?? '0') === '1');

    return $applied;
}


/* ------------------------------------------------- RepRapFirmware (RRF) */

const RRF_RELEASES_API = 'https://api.github.com/repos/gloomyandy/RepRapFirmware/releases';

function board_supports_rrf(array $board): bool
{
    return (bool)($board['rrf']['supported'] ?? false);
}

/** RRF config fields. Same machine data as Marlin/Klipper, emitted as G-code. */
function rrf_field_defs(array $board): array
{
    $lim  = $board['limits'] ?? [];
    $lmax = fn (string $k, int $d): int => (($v = (int)($lim[$k] ?? 0)) > 0 ? $v : $d);
    $slots = $board['marlin']['driver_slots'] ?? ['X', 'Y', 'Z', 'E0'];

    $f = [
        ['key' => 'machine_name', 'label' => 'Machine name', 'group' => 'Machine', 'type' => 'text', 'max_len' => 40],
        ['key' => 'kinematics', 'label' => 'Kinematics', 'group' => 'Machine', 'type' => 'select',
         'options' => ['cartesian', 'corexy'],
         'option_labels' => ['cartesian' => 'Cartesian', 'corexy' => 'CoreXY']],
        ['key' => 'network', 'label' => 'Connectivity', 'group' => 'Machine', 'type' => 'select',
         'options' => ['wifi', 'sbc', 'usb'],
         'option_labels' => ['wifi' => 'WiFi (ESP module)', 'sbc' => 'SBC (Raspberry Pi)', 'usb' => 'USB / serial only']],

        ['key' => 'bed_x', 'label' => 'X travel (mm)', 'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => max(2000, $lmax('max_bed_x', 500))],
        ['key' => 'bed_y', 'label' => 'Y travel (mm)', 'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => max(2000, $lmax('max_bed_y', 500))],
        ['key' => 'z_max', 'label' => 'Z height (mm)', 'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => max(2000, $lmax('max_z', 600))],

        ['key' => 'max_velocity', 'label' => 'Max speed XY (mm/s)', 'group' => 'Speed', 'type' => 'int', 'min' => 20, 'max' => $lmax('max_feedrate_xy', 800)],
        ['key' => 'max_accel', 'label' => 'Max acceleration (mm/s2)', 'group' => 'Speed', 'type' => 'int', 'min' => 100, 'max' => 20000],
    ];

    // Per-axis steps + current, matching the board's real driver slots.
    foreach ($slots as $s) {
        $ax = strtolower($s);
        $f[] = ['key' => "steps_{$ax}", 'label' => "Steps/mm {$s}", 'group' => 'Motion',
                'type' => 'float', 'min' => 1, 'max' => 6400];
        $f[] = ['key' => "current_{$ax}", 'label' => "{$s} motor current (mA)", 'group' => 'Stepper Drivers',
                'type' => 'int', 'min' => 100, 'max' => 2000];
        $f[] = ['key' => "invert_{$ax}", 'label' => "Invert {$s} direction", 'group' => 'Motion', 'type' => 'bool'];
    }

    $f[] = ['key' => 'microsteps', 'label' => 'Microsteps (with interpolation)', 'group' => 'Stepper Drivers',
            'type' => 'select', 'options' => ['16', '32', '64', '128', '256'],
            'option_labels' => ['16' => '16', '32' => '32', '64' => '64', '128' => '128', '256' => '256']];

    $f[] = ['key' => 'hotend_max', 'label' => 'Hotend max temp (C)', 'group' => 'Thermal', 'type' => 'int',
            'min' => 150, 'max' => $lmax('max_hotend_temp', 300)];
    $f[] = ['key' => 'bed_max', 'label' => 'Bed max temp (C)', 'group' => 'Thermal', 'type' => 'int',
            'min' => 60, 'max' => $lmax('max_bed_temp', 120)];

    $f[] = ['key' => 'probe', 'label' => 'Bed probe', 'group' => 'Probe', 'type' => 'select',
            'options' => ['none', 'bltouch', 'switch'],
            'option_labels' => ['none' => 'None', 'bltouch' => 'BLTouch / MicroProbe', 'switch' => 'Simple switch (Z-min)']];
    $f[] = ['key' => 'probe_off_x', 'label' => 'Probe offset X (mm)', 'group' => 'Probe', 'type' => 'float',
            'min' => -100, 'max' => 100, 'requires' => ['probe' => ['bltouch', 'switch']]];
    $f[] = ['key' => 'probe_off_y', 'label' => 'Probe offset Y (mm)', 'group' => 'Probe', 'type' => 'float',
            'min' => -100, 'max' => 100, 'requires' => ['probe' => ['bltouch', 'switch']]];
    $f[] = ['key' => 'mesh_points', 'label' => 'Mesh grid points per axis', 'group' => 'Probe', 'type' => 'int',
            'min' => 3, 'max' => 15, 'requires' => ['probe' => ['bltouch', 'switch']]];

    return $f;
}

/** Sensible defaults for a fresh RRF project (there is no file to import from). */
function rrf_default_values(array $board): array
{
    $slots = $board['marlin']['driver_slots'] ?? ['X', 'Y', 'Z', 'E0'];
    $v = [
        'machine_name' => 'My Printer', 'kinematics' => 'corexy', 'network' => 'wifi',
        'bed_x' => '200', 'bed_y' => '200', 'z_max' => '200',
        'max_velocity' => '300', 'max_accel' => '3000',
        'microsteps' => '16', 'hotend_max' => '280', 'bed_max' => '100',
        'probe' => 'none', 'probe_off_x' => '0', 'probe_off_y' => '0', 'mesh_points' => '5',
    ];
    foreach ($slots as $s) {
        $ax = strtolower($s);
        $v["steps_{$ax}"]   = str_starts_with($ax, 'e') ? '420' : (str_starts_with($ax, 'z') ? '400' : '80');
        $v["current_{$ax}"] = str_starts_with($ax, 'e') ? '650' : '800';
        $v["invert_{$ax}"]  = '0';
    }
    return $v;
}

/**
 * Generate a RepRapFirmware config.g from the collected values.
 * RRF is configured at runtime by G-code, so this file IS the configuration —
 * there is nothing to compile.
 */
function rrf_configg_generate(array $board, array $v): string
{
    $slots = $board['marlin']['driver_slots'] ?? ['X', 'Y', 'Z', 'E0'];
    $name  = str_replace('"', "'", (string)($v['machine_name'] ?? 'My Printer'));
    $micro = (int)($v['microsteps'] ?? 16);

    // Map our slot names onto RRF drive numbers (0=X, 1=Y, 2=Z, 3+=extruders).
    $driveOf = [];
    $eIndex  = 3;
    foreach ($slots as $s) {
        $u = strtoupper($s);
        if ($u === 'X')      { $driveOf[$s] = 0; }
        elseif ($u === 'Y')  { $driveOf[$s] = 1; }
        elseif ($u === 'Z')  { $driveOf[$s] = 2; }
        elseif ($u === 'Z2') { $driveOf[$s] = 2; } // second Z shares the Z axis
        else                 { $driveOf[$s] = $eIndex++; }
    }

    $L   = [];
    $L[] = '; config.g - generated by HotFetched';
    $L[] = '; Board: ' . $board['name'];
    $L[] = '; RepRapFirmware is configured at runtime - this file IS the config.';
    $L[] = '';
    $L[] = '; --- General';
    $L[] = 'G90                                  ; absolute coordinates';
    $L[] = 'M83                                  ; relative extruder moves';
    $L[] = 'M550 P"' . $name . '"                ; machine name';
    $L[] = '';

    $L[] = '; --- Network';
    $net = (string)($v['network'] ?? 'usb');
    if ($net === 'wifi') {
        $L[] = 'M552 S1                              ; enable WiFi';
        $L[] = '; Run M587 S"ssid" P"password" ONCE from the console to store your network.';
    } elseif ($net === 'sbc') {
        $L[] = '; SBC mode - networking is handled by the Pi (DSF).';
    } else {
        $L[] = 'M552 S0                              ; networking off (USB only)';
    }
    $L[] = '';

    $L[] = '; --- Kinematics';
    $L[] = ((string)($v['kinematics'] ?? 'cartesian') === 'corexy')
         ? 'M669 K1                              ; CoreXY'
         : 'M669 K0                              ; Cartesian';
    $L[] = '';

    $L[] = '; --- Drives';
    foreach ($slots as $s) {
        $ax  = strtolower($s);
        $d   = $driveOf[$s];
        $dir = ((string)($v["invert_{$ax}"] ?? '0') === '1') ? 'S0' : 'S1';
        $L[] = sprintf('M569 P%d %s                          ; drive %d (%s) direction', $d, $dir, $d, $s);
    }
    $L[] = '';

    // M92 / M906 take axis letters, so group by axis.
    $steps = [];
    $curr  = [];
    foreach ($slots as $s) {
        $ax = strtolower($s);
        $u  = strtoupper($s);
        $letter = in_array($u, ['X', 'Y', 'Z', 'Z2'], true) ? substr($u, 0, 1) : 'E';
        $sv = $v["steps_{$ax}"] ?? null;
        $cv = $v["current_{$ax}"] ?? null;
        if ($sv !== null && !isset($steps[$letter])) {
            $steps[$letter] = rtrim(rtrim(sprintf('%.3f', (float)$sv), '0'), '.');
        }
        if ($cv !== null) {
            if ($letter === 'E') { $curr['E'][] = (int)$cv; }
            elseif (!isset($curr[$letter])) { $curr[$letter] = (int)$cv; }
        }
    }
    $m92 = '';
    foreach (['X', 'Y', 'Z'] as $l) {
        if (isset($steps[$l])) { $m92 .= $l . $steps[$l] . ' '; }
    }
    if (isset($steps['E'])) { $m92 .= 'E' . $steps['E'] . ' '; }
    $L[] = 'M92 ' . trim($m92) . '                 ; steps per mm';
    $L[] = 'M350 X' . $micro . ' Y' . $micro . ' Z' . $micro . ' E' . $micro . ' I1        ; microstepping with interpolation';

    $m906 = '';
    foreach (['X', 'Y', 'Z'] as $l) {
        if (isset($curr[$l])) { $m906 .= $l . (int)$curr[$l] . ' '; }
    }
    if (!empty($curr['E'])) { $m906 .= 'E' . implode(':', $curr['E']) . ' '; }
    $L[] = 'M906 ' . trim($m906) . 'I30            ; motor currents (mA), 30% idle';
    $L[] = 'M84 S30                              ; idle timeout';
    $L[] = '';

    $L[] = '; --- Axis limits';
    $L[] = 'M208 X0 Y0 Z0 S1                     ; minima';
    $L[] = sprintf('M208 X%d Y%d Z%d S0                ; maxima',
                   (int)($v['bed_x'] ?? 200), (int)($v['bed_y'] ?? 200), (int)($v['z_max'] ?? 200));
    $L[] = '';

    $L[] = '; --- Speeds';
    $vel = (int)($v['max_velocity'] ?? 300) * 60; // RRF wants mm/min
    $acc = (int)($v['max_accel'] ?? 3000);
    $L[] = sprintf('M203 X%d Y%d Z%d E%d          ; max speeds (mm/min)', $vel, $vel, (int)($vel / 10), $vel);
    $L[] = sprintf('M201 X%d Y%d Z%d E%d              ; accelerations (mm/s^2)', $acc, $acc, (int)($acc / 10), $acc);
    $L[] = 'M566 X400 Y400 Z20 E400              ; max instantaneous speed changes (jerk, mm/min)';
    $L[] = '';

    $L[] = '; --- Endstops';
    $L[] = 'M574 X1 S1 P"xstop"                  ; X min';
    $L[] = 'M574 Y1 S1 P"ystop"                  ; Y min';
    $probe = (string)($v['probe'] ?? 'none');
    $L[] = ($probe === 'none')
         ? 'M574 Z1 S1 P"zstop"                  ; Z min switch'
         : 'M574 Z1 S2                           ; Z homed by the probe';
    $L[] = '';

    $L[] = '; --- Heaters';
    $L[] = 'M308 S0 P"bedtemp" Y"thermistor" T100000 B4138   ; bed sensor';
    $L[] = 'M950 H0 C"bed" T0                    ; bed heater';
    $L[] = 'M140 H0                              ; map heated bed';
    $L[] = sprintf('M143 H0 S%d                          ; bed max temp', (int)($v['bed_max'] ?? 100));
    $L[] = 'M308 S1 P"e0temp" Y"thermistor" T100000 B4138   ; hotend sensor';
    $L[] = 'M950 H1 C"e0heat" T1                 ; hotend heater';
    $L[] = sprintf('M143 H1 S%d                          ; hotend max temp', (int)($v['hotend_max'] ?? 280));
    $L[] = '';

    $L[] = '; --- Fans';
    $L[] = 'M950 F0 C"fan0"                      ; part cooling fan';
    $L[] = 'M106 P0 S0 H-1                       ; fan off, not thermostatic';
    $L[] = 'M950 F1 C"fan1"                      ; hotend fan';
    $L[] = 'M106 P1 S1 H1 T45                    ; thermostatic on hotend';
    $L[] = '';

    $L[] = '; --- Tools';
    $L[] = 'M563 P0 D0 H1 F0                     ; tool 0';
    $L[] = 'G10 P0 X0 Y0 Z0 R0 S0                ; tool offsets & temps';
    $L[] = '';

    if ($probe !== 'none') {
        $L[] = '; --- Probe';
        if ($probe === 'bltouch') {
            $L[] = 'M950 S0 C"probe.servo"               ; BLTouch servo pin';
            $L[] = 'M558 P9 C"^probe.in" H5 F120 T6000   ; BLTouch, dive 5mm';
        } else {
            $L[] = 'M558 P5 C"^probe.in" H5 F120 T6000   ; switch probe, dive 5mm';
        }
        $L[] = sprintf('G31 P500 X%s Y%s Z2.0                ; probe offsets - CALIBRATE Z YOURSELF',
                       rtrim(rtrim(sprintf('%.2f', (float)($v['probe_off_x'] ?? 0)), '0'), '.') ?: '0',
                       rtrim(rtrim(sprintf('%.2f', (float)($v['probe_off_y'] ?? 0)), '0'), '.') ?: '0');
        $pts  = max(3, min(15, (int)($v['mesh_points'] ?? 5)));
        $spX  = max(1, (int)(((int)($v['bed_x'] ?? 200) - 40) / max(1, $pts - 1)));
        $spY  = max(1, (int)(((int)($v['bed_y'] ?? 200) - 40) / max(1, $pts - 1)));
        $L[]  = sprintf('M557 X20:%d Y20:%d S%d:%d           ; mesh grid (%dx%d)',
                        (int)($v['bed_x'] ?? 200) - 20, (int)($v['bed_y'] ?? 200) - 20, $spX, $spY, $pts, $pts);
        $L[]  = '';
        $L[]  = '; NOTE: G31 Z is the trigger height. Measure it on YOUR machine';
        $L[]  = '; before printing - the value above is a placeholder, not a real offset.';
        $L[]  = '';
    }

    $L[] = '; --- Pin names above (e.g. "xstop", "e0heat") follow this board\'s RRF';
    $L[] = '; pin table. Check the TeamGloomy page for this board if a pin is rejected.';
    $L[] = '';

    return implode("\n", $L) . "\n";
}

/**
 * Ask GitHub for TeamGloomy's releases and pick the asset matching this board.
 * Done at build time (not baked in) so we track whatever they actually ship.
 * Returns ['tag'=>, 'name'=>, 'url'=>, 'size'=>] | ['error'=>...]
 */
function rrf_resolve_asset(array $board, bool $allowPrerelease = false): array
{
    $inc = array_map('strtolower', (array)($board['rrf']['asset_include'] ?? []));
    $exc = array_map('strtolower', (array)($board['rrf']['asset_exclude'] ?? []));
    if ($inc === []) {
        return ['error' => 'No RRF asset pattern for this board'];
    }

    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => 20,
        'header'  => "User-Agent: HotFetched\r\nAccept: application/vnd.github+json\r\n",
    ]]);
    $raw = @file_get_contents(RRF_RELEASES_API . '?per_page=10', false, $ctx);
    if ($raw === false) {
        return ['error' => 'Could not reach the TeamGloomy release API (no network?)'];
    }
    $rels = json_decode($raw, true);
    if (!is_array($rels)) {
        return ['error' => 'Unexpected response from the release API'];
    }

    $matches = fn (string $n): bool =>
        array_reduce($inc, fn ($c, $t) => $c && str_contains($n, $t), true)
        && array_reduce($exc, fn ($c, $t) => $c && !str_contains($n, $t), true);

    $seen = [];   // every asset name we looked at, for diagnostics

    foreach ($rels as $rel) {
        if (!$allowPrerelease && !empty($rel['prerelease'])) {
            continue;
        }
        $tag    = (string)($rel['tag_name'] ?? '?');
        $assets = (array)($rel['assets'] ?? []);
        $bins   = [];
        $zips   = [];

        foreach ($assets as $a) {
            $n = strtolower((string)($a['name'] ?? ''));
            $seen[] = $n;
            if (str_ends_with($n, '.bin') || str_ends_with($n, '.uf2')) {
                if ($matches($n)) {
                    $bins[] = $a;
                }
            } elseif (str_ends_with($n, '.zip')) {
                $zips[] = $a;
            }
        }

        // 1) A per-board binary published directly as a release asset.
        if (count($bins) === 1) {
            $a = $bins[0];
            return ['tag' => $tag, 'name' => (string)$a['name'],
                    'url' => (string)$a['browser_download_url'], 'size' => (int)($a['size'] ?? 0),
                    'from' => 'asset'];
        }
        if (count($bins) > 1) {
            $names = array_map(fn ($a) => (string)$a['name'], $bins);
            return ['error' => 'Several firmware files match this board (' . implode(', ', $names)
                             . ') - narrow the board pattern.'];
        }

        // 2) Otherwise TeamGloomy may ship the per-board binaries inside a zip.
        foreach ($zips as $z) {
            $tmp = tempnam(sys_get_temp_dir(), 'rrfzip');
            $zc  = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 180,
                                                     'header' => "User-Agent: HotFetched\r\n"]]);
            $data = @file_get_contents((string)$z['browser_download_url'], false, $zc);
            if ($data === false || @file_put_contents($tmp, $data) === false) {
                @unlink($tmp);
                continue;
            }
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                @unlink($tmp);
                continue;
            }
            $hit = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string)$zip->getNameIndex($i);
                $base  = strtolower(basename($entry));
                $seen[] = 'zip:' . $base;
                if ((str_ends_with($base, '.bin') || str_ends_with($base, '.uf2')) && $matches($base)) {
                    $hit = $entry;
                    break;
                }
            }
            if ($hit !== null) {
                $bin = $zip->getFromName($hit);
                $zip->close();
                @unlink($tmp);
                if ($bin !== false && strlen($bin) > 1024) {
                    return ['tag' => $tag, 'name' => basename($hit), 'data' => $bin,
                            'size' => strlen($bin), 'from' => 'zip:' . (string)$z['name']];
                }
            }
            $zip->close();
            @unlink($tmp);
        }
    }

    // Nothing matched - report what WAS there so the pattern can be fixed.
    $sample = array_slice(array_values(array_unique($seen)), 0, 40);
    return ['error' => 'No firmware asset matched this board.',
            'seen'  => $sample];
}



/* ------------------------------------------------- MMU + Language (Tier 3) */

/** Multi-Material Unit models supported by Marlin's MMU_MODEL. */
function marlin_mmu_models(): array
{
    return [
        'none'                 => null,
        'PRUSA_MMU1'           => 'PRUSA_MMU1',
        'PRUSA_MMU2'           => 'PRUSA_MMU2',
        'PRUSA_MMU2S'          => 'PRUSA_MMU2S',
        'PRUSA_MMU3'           => 'PRUSA_MMU3',
        'EXTENDABLE_EMU_MMU2'  => 'EXTENDABLE_EMU_MMU2',
        'EXTENDABLE_EMU_MMU2S' => 'EXTENDABLE_EMU_MMU2S',
    ];
}

/**
 * Translate the user-facing MMU selection into the Marlin model written to
 * Configuration.h. Marlin already defines EXTENDABLE_EMU_MMU3 internally;
 * using it preserves the MMU3 protocol while allowing more than five tools.
 */
function marlin_effective_mmu_model(array $v): string
{
    $model = (string)($v['mmu_model'] ?? 'none');
    return $model === 'PRUSA_MMU3' && (int)($v['extruders'] ?? 1) === 12
        ? 'EXTENDABLE_EMU_MMU3'
        : $model;
}

/**
 * Enable Marlin's existing EXTENDABLE_EMU_MMU3 token in the imported source.
 * The upstream conditionals define the model and route it through MMU3, but
 * some trees omit it from HAS_EXTENDABLE_MMU, which leaves the 5/8-tool sanity
 * limits active. Returns ['ok' => bool, 'changed' => bool, 'detail' => string].
 */
function marlin_enable_extendable_mmu3(string $tree): array
{
    $paths = [
        rtrim($tree, '/') . '/Marlin/src/inc/Conditionals-1-axes.h',
        rtrim($tree, '/') . '/Marlin/src/inc/Conditionals_post.h',
    ];

    foreach ($paths as $path) {
        if (!is_file($path)) continue;
        $txt = @file_get_contents($path);
        if ($txt === false || !str_contains($txt, '_EXTENDABLE_EMU_MMU3')) continue;

        // Already enabled in the HAS_EXTENDABLE_MMU condition.
        if (preg_match('/#if[^\r\n]*_EXTENDABLE_EMU_MMU3[^\r\n]*\R\s*#define\s+HAS_EXTENDABLE_MMU\b/', $txt)) {
            return ['ok' => true, 'changed' => false, 'detail' => basename($path) . ' already supports extendable MMU3'];
        }

        $pattern = '/#if\s+_MMU\s*==\s*_EXTENDABLE_EMU_MMU2\s*\|\|\s*_MMU\s*==\s*_EXTENDABLE_EMU_MMU2S(?!\s*\|\|)/';
        $replace = '#if _MMU == _EXTENDABLE_EMU_MMU2 || _MMU == _EXTENDABLE_EMU_MMU2S || _MMU == _EXTENDABLE_EMU_MMU3';
        $patched = preg_replace($pattern, $replace, $txt, 1, $count);
        if ($patched === null || $count !== 1) continue;
        if (@file_put_contents($path, $patched) === false) {
            return ['ok' => false, 'changed' => false, 'detail' => 'Could not update ' . $path];
        }
        return ['ok' => true, 'changed' => true, 'detail' => 'Enabled EXTENDABLE_EMU_MMU3 in ' . basename($path)];
    }

    return [
        'ok' => false,
        'changed' => false,
        'detail' => 'This Marlin source does not expose EXTENDABLE_EMU_MMU3. Import a current bugfix-2.1.x / Marlin 2.1.3 source tree.',
    ];
}

/** Models that require exactly 5 extruders (Prusa 5-port units). */
function marlin_mmu_needs_5(string $m): bool
{
    return in_array($m, ['PRUSA_MMU2S', 'PRUSA_MMU3'], true);
}

/** Models that talk over a serial port (everything except the MMU1 multiplexer). */
function marlin_mmu_is_serial(string $m): bool
{
    return $m !== 'none' && $m !== 'PRUSA_MMU1';
}

/** Marlin's built-in LCD languages (from Configuration.h LCD_LANGUAGE). */
function marlin_languages(): array
{
    return [
        'en' => 'English', 'an' => 'Aragonese', 'bg' => 'Bulgarian', 'ca' => 'Catalan',
        'cz' => 'Czech', 'da' => 'Danish', 'de' => 'German', 'el' => 'Greek (Greece)',
        'el_CY' => 'Greek (Cyprus)', 'es' => 'Spanish', 'eu' => 'Basque-Euskera',
        'fi' => 'Finnish', 'fr' => 'French', 'gl' => 'Galician', 'hg' => 'Hinglish',
        'hr' => 'Croatian', 'hu' => 'Hungarian', 'id' => 'Indonesian', 'it' => 'Italian',
        'jp_kana' => 'Japanese', 'ko_KR' => 'Korean', 'nl' => 'Dutch', 'pl' => 'Polish',
        'pt' => 'Portuguese', 'pt_br' => 'Portuguese (Brazilian)', 'ro' => 'Romanian',
        'ru' => 'Russian', 'sk' => 'Slovak', 'sv' => 'Swedish', 'tr' => 'Turkish',
        'uk' => 'Ukrainian', 'vi' => 'Vietnamese', 'zh_CN' => 'Chinese (Simplified)',
        'zh_TW' => 'Chinese (Traditional)',
    ];
}

function marlin_field_defs_mmu(array $board): array
{
    $langs  = marlin_languages();
    $models = array_keys(marlin_mmu_models());

    return [
        ['key' => 'mmu_model', 'label' => 'Multi-Material Unit', 'group' => 'Multi-Material', 'type' => 'select',
         'options' => $models,
         'option_labels' => [
             'none'                 => 'None',
             'PRUSA_MMU1'           => 'Prusa MMU1 (multiplexer, no serial)',
             'PRUSA_MMU2'           => 'Prusa MMU2',
             'PRUSA_MMU2S'          => 'Prusa MMU2S (needs 5 extruders)',
             'PRUSA_MMU3'           => 'Prusa MMU3 (5 tools; 12 with MMU3-12x firmware)',
             'EXTENDABLE_EMU_MMU2'  => 'ERCF / SMuFF (MMU2 protocol)',
             'EXTENDABLE_EMU_MMU2S' => 'ERCF / SMuFF (MMU2S protocol)',
         ]],
        ['key' => 'mmu_serial_port', 'label' => 'MMU serial port (UART index on your board)',
         'group' => 'Multi-Material', 'type' => 'int', 'min' => 0, 'max' => 9,
         'requires' => ['mmu_model' => ['PRUSA_MMU2', 'PRUSA_MMU2S', 'PRUSA_MMU3', 'EXTENDABLE_EMU_MMU2', 'EXTENDABLE_EMU_MMU2S']]],
        ['key' => 'mmu_baud', 'label' => 'MMU baud rate', 'group' => 'Multi-Material', 'type' => 'select',
         'options' => ['9600', '19200', '38400', '57600', '115200', '250000'],
         'option_labels' => ['9600' => '9600', '19200' => '19200', '38400' => '38400',
                             '57600' => '57600', '115200' => '115200 (default)', '250000' => '250000'],
         'requires' => ['mmu_model' => ['PRUSA_MMU2', 'PRUSA_MMU2S', 'PRUSA_MMU3', 'EXTENDABLE_EMU_MMU2', 'EXTENDABLE_EMU_MMU2S']]],
        ['key' => 'mmu_menus', 'label' => 'Add MMU menu to the LCD', 'group' => 'Multi-Material', 'type' => 'bool',
         'requires' => ['mmu_model' => ['PRUSA_MMU2', 'PRUSA_MMU2S', 'PRUSA_MMU3', 'EXTENDABLE_EMU_MMU2', 'EXTENDABLE_EMU_MMU2S']]],

        ['key' => 'lcd_language', 'label' => 'LCD language', 'group' => 'Interface', 'type' => 'select',
         'options' => array_keys($langs), 'option_labels' => $langs],
    ];
}

function marlin_current_values_mmu(array $doc, array $adv): array
{
    $d = $doc['defines'];
    $a = $adv['defines'];

    $model = 'none';
    if (($d['MMU_MODEL']['enabled'] ?? false)) {
        $v = trim((string)($d['MMU_MODEL']['value'] ?? ''));
        if ($v === 'EXTENDABLE_EMU_MMU3') {
            $model = 'PRUSA_MMU3';
        } elseif (isset(marlin_mmu_models()[$v])) {
            $model = $v;
        }
    }
    $num = function (array $s, string $k, string $def): string {
        $e = $s[$k] ?? null;
        if ($e === null || $e['value'] === null) return $def;
        return preg_match('/\d+/', (string)$e['value'], $m) ? $m[0] : $def;
    };
    $lang = trim((string)($d['LCD_LANGUAGE']['value'] ?? 'en'));
    if (!isset(marlin_languages()[$lang])) {
        $lang = 'en';
    }
    return [
        'mmu_model'       => $model,
        'mmu_serial_port' => $num($a, 'MMU_SERIAL_PORT', '2'),
        'mmu_baud'        => $num($a, 'MMU_BAUD', '115200'),
        'mmu_menus'       => ($a['MMU_MENUS']['enabled'] ?? false) ? '1' : '0',
        'lcd_language'    => $lang,
    ];
}

/** MMU_MODEL and LCD_LANGUAGE live in Configuration.h. */
function marlin_apply_values_mmu_conf(array &$doc, array $v): array
{
    $applied = [];
    $set = function (string $k, ?string $val, bool $en = true) use (&$doc, &$applied): void {
        if (marlin_config_set($doc, $k, $val, $en)) {
            $applied[] = $k;
        }
    };

    $model = marlin_effective_mmu_model($v);
    $on    = $model !== 'none';
    $set('MMU_MODEL', $on ? $model : null, $on);

    // Every MMU model requires NOZZLE_PARK_FEATURE so the head can park if the
    // unit jams. Marlin hard-errors without it.
    if ($on) {
        $set('NOZZLE_PARK_FEATURE', $doc['defines']['NOZZLE_PARK_FEATURE']['value'] ?? null, true);
    }

    $lang = (string)($v['lcd_language'] ?? 'en');
    if (isset(marlin_languages()[$lang])) {
        $set('LCD_LANGUAGE', $lang);
    }
    return $applied;
}

/** MMU serial/baud/menus live in Configuration_adv.h. */
function marlin_apply_values_mmu_adv(array &$adv, array $v, array $board = []): array
{
    $applied = [];
    $set = function (string $k, ?string $val, bool $en = true) use (&$adv, &$applied): void {
        if (marlin_config_set($adv, $k, $val, $en)) {
            $applied[] = $k;
        }
    };

    $model = (string)($v['mmu_model'] ?? 'none');
    if (!marlin_mmu_is_serial($model)) {
        return $applied;
    }
    // NOTE: Marlin's define is MMU_BAUD (not MMU_BAUDRATE).
    $set('MMU_SERIAL_PORT', (string)(int)($v['mmu_serial_port'] ?? 2));
    $set('MMU_BAUD', (string)(int)($v['mmu_baud'] ?? 115200));
    // MMU_MENUS requires HAS_MARLINUI_MENU. An external-firmware TFT has no
    // MarlinUI, so the menu cannot exist there - never write it in that case.
    $uiOk = $board === [] || marlin_screen_has_marlinui($board, (string)($v['screen'] ?? ''));
    $set('MMU_MENUS', null, $uiOk && ($v['mmu_menus'] ?? '0') === '1');
    return $applied;
}


/* --------------------------------------------- Custom melodies (user library) */

function customtunes_file(): string
{
    return HF_PRIVATE_DIR . '/custom_tunes.json';
}

/** @return array<int,array{name:string,source:string,seq:array,created:int}> */
function customtunes_all(): array
{
    $f = customtunes_file();
    if (!is_file($f)) {
        return [];
    }
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function customtunes_save_all(array $list): bool
{
    if (!is_dir(HF_PRIVATE_DIR)) {
        @mkdir(HF_PRIVATE_DIR, 0775, true);
    }
    return @file_put_contents(customtunes_file(), json_encode(array_values($list), JSON_PRETTY_PRINT)) !== false;
}

/**
 * Add a melody to the user library. $seq is [[freqHz, ms], ...] as produced by
 * the browser-side RTTTL/CSV parsers, so the stored form is already the tone
 * sequence Marlin needs - no re-parsing at build time.
 */
function customtunes_add(string $name, string $source, array $seq): array|string
{
    $name = trim($name);
    if ($name === '') {
        return 'Give the melody a name';
    }
    if (mb_strlen($name) > 60) {
        return 'Name is too long (60 characters max)';
    }
    if ($seq === []) {
        return 'That melody has no playable notes';
    }
    if (count($seq) > 2000) {
        return 'That melody is too long (2000 notes max)';
    }
    // Validate the sequence shape rather than trusting the client.
    $clean = [];
    foreach ($seq as $pair) {
        if (!is_array($pair) || count($pair) < 2) {
            return 'Malformed melody data';
        }
        $f  = (int)$pair[0];
        $ms = (int)$pair[1];
        if ($f < 0 || $f > 20000 || $ms < 1 || $ms > 10000) {
            return 'Melody contains an out-of-range note';
        }
        $clean[] = [$f, $ms];
    }

    $list = customtunes_all();
    foreach ($list as $t) {
        if (strcasecmp((string)($t['name'] ?? ''), $name) === 0) {
            return 'A melody with that name already exists';
        }
    }
    if (count($list) >= 200) {
        return 'Melody library is full (200 max)';
    }
    $entry = ['name' => $name, 'source' => mb_substr($source, 0, 2000),
              'seq' => $clean, 'created' => time()];
    $list[] = $entry;
    if (!customtunes_save_all($list)) {
        return 'Could not write to the melody library';
    }
    return $entry;
}

function customtunes_delete(string $name): bool
{
    $list = customtunes_all();
    $out  = array_values(array_filter($list, fn ($t) => strcasecmp((string)($t['name'] ?? ''), $name) !== 0));
    if (count($out) === count($list)) {
        return false;
    }
    return customtunes_save_all($out);
}


/**
 * True when the selected screen is a MarlinUI display - i.e. Marlin draws the
 * menus itself and the EXP header provides BEEPER_PIN. External-firmware TFTs
 * (BTT TFT in touch mode) and "no display" give neither, so features like
 * MMU_MENUS and STARTUP_TUNE cannot be used with them.
 */
function marlin_screen_has_marlinui(array $board, string $screenId): bool
{
    if ($screenId === '' || $screenId === 'none') {
        return false;
    }
    foreach (($board['marlin']['screens'] ?? []) as $s) {
        if (($s['id'] ?? '') === $screenId) {
            $t = (string)($s['type'] ?? '');
            // 'marlinui_tft' = BTT TFT in Marlin mode (12864 emulation over EXP1/EXP2):
            // it DOES give MarlinUI menus and a beeper. Only touch-only and "no
            // display" lack them.
            return $t !== 'none' && $t !== 'serial_tft';
        }
    }
    return false;
}


/* ------------------------------------------------ Tier 3: remaining features */

function marlin_field_defs_tier3(array $board): array
{
    $zMax   = (int)($board['ui']['z_steppers'] ?? 1);
    $hasNeo = (bool)($board['ui']['neopixel'] ?? false);
    $probes = array_values(array_filter(
        array_column($board['marlin']['probes'] ?? [], 'id'),
        fn ($p) => $p !== 'none'
    ));

    $f = [];

    // --- Multiple Z steppers (only where the board actually has the pins) ---
    if ($zMax > 1) {
        $zOpts = array_map('strval', range(1, $zMax));
        $zMulti = array_slice($zOpts, 1); // 2..N
        $f[] = ['key' => 'z_steppers', 'label' => 'Z stepper motors', 'group' => 'Multiple Z',
                'type' => 'select', 'options' => $zOpts,
                'option_labels' => array_combine($zOpts, array_map(
                    fn ($n) => $n === '1' ? '1 (single Z)' : $n . ' Z motors', $zOpts))];
        $f[] = ['key' => 'z_align', 'label' => 'Z levelling method', 'group' => 'Multiple Z',
                'type' => 'select', 'options' => ['none', 'auto_align', 'multi_endstops'],
                'option_labels' => [
                    'none'           => 'None (motors move together)',
                    'auto_align'     => 'G34 auto-align with the probe (recommended)',
                    'multi_endstops' => 'Independent endstops per Z motor',
                ],
                'requires' => ['z_steppers' => $zMulti]];
        $f[] = ['key' => 'z_align_iterations', 'label' => 'G34 alignment iterations', 'group' => 'Multiple Z',
                'type' => 'int', 'min' => 1, 'max' => 10,
                'requires' => ['z_align' => ['auto_align']]];
    }

    // --- Input shaping (ringing/ghosting compensation) ---
    $f[] = ['key' => 'shaping_x', 'label' => 'Input shaping X', 'group' => 'Input Shaping', 'type' => 'bool'];
    $f[] = ['key' => 'shaping_freq_x', 'label' => 'X resonant frequency (Hz, from M593/ringing tower)',
            'group' => 'Input Shaping', 'type' => 'float', 'min' => 5, 'max' => 200,
            'requires' => ['shaping_x' => ['1']]];
    $f[] = ['key' => 'shaping_zeta_x', 'label' => 'X damping ratio (0-1)', 'group' => 'Input Shaping',
            'type' => 'float', 'min' => 0, 'max' => 1, 'requires' => ['shaping_x' => ['1']]];
    $f[] = ['key' => 'shaping_y', 'label' => 'Input shaping Y', 'group' => 'Input Shaping', 'type' => 'bool'];
    $f[] = ['key' => 'shaping_freq_y', 'label' => 'Y resonant frequency (Hz)', 'group' => 'Input Shaping',
            'type' => 'float', 'min' => 5, 'max' => 200, 'requires' => ['shaping_y' => ['1']]];
    $f[] = ['key' => 'shaping_zeta_y', 'label' => 'Y damping ratio (0-1)', 'group' => 'Input Shaping',
            'type' => 'float', 'min' => 0, 'max' => 1, 'requires' => ['shaping_y' => ['1']]];

    // --- Babystepping ---
    $f[] = ['key' => 'babystepping', 'label' => 'Babystepping (live Z adjust while printing)',
            'group' => 'Babystepping', 'type' => 'bool'];
    $f[] = ['key' => 'babystep_zprobe', 'label' => 'Babystep adjusts the probe Z-offset (M851)',
            'group' => 'Babystepping', 'type' => 'bool', 'requires' => ['babystepping' => ['1']]];

    // --- Motion extras ---
    $f[] = ['key' => 'arc_support', 'label' => 'Arc support (G2/G3 curves)', 'group' => 'Motion Extras', 'type' => 'bool'];
    $f[] = ['key' => 'nozzle_park', 'label' => 'Nozzle park (G27, and parking on pause)',
            'group' => 'Motion Extras', 'type' => 'bool'];
    $f[] = ['key' => 'park_z', 'label' => 'Park Z lift (mm)', 'group' => 'Motion Extras',
            'type' => 'int', 'min' => 1, 'max' => 100, 'requires' => ['nozzle_park' => ['1']]];

    // --- NeoPixel (only where the board exposes a pin) ---
    if ($hasNeo) {
        $f[] = ['key' => 'neopixel', 'label' => 'NeoPixel RGB strip', 'group' => 'Lighting', 'type' => 'bool'];
        $f[] = ['key' => 'neopixel_type', 'label' => 'LED type', 'group' => 'Lighting', 'type' => 'select',
                'options' => ['NEO_GRB', 'NEO_RGB', 'NEO_GRBW', 'NEO_RGBW'],
                'option_labels' => ['NEO_GRB' => 'NEO_GRB (most common)', 'NEO_RGB' => 'NEO_RGB',
                                    'NEO_GRBW' => 'NEO_GRBW (with white)', 'NEO_RGBW' => 'NEO_RGBW'],
                'requires' => ['neopixel' => ['1']]];
        $f[] = ['key' => 'neopixel_pixels', 'label' => 'Number of LEDs', 'group' => 'Lighting',
                'type' => 'int', 'min' => 1, 'max' => 200, 'requires' => ['neopixel' => ['1']]];
        $f[] = ['key' => 'neopixel_test', 'label' => 'Cycle colours at startup', 'group' => 'Lighting',
                'type' => 'bool', 'requires' => ['neopixel' => ['1']]];
    }

    return $f;
}

function marlin_current_values_tier3(array $doc, array $adv, array $board): array
{
    $d = $doc['defines'];
    $a = $adv['defines'];
    $on  = fn (array $s, string $k): string => ($s[$k]['enabled'] ?? false) ? '1' : '0';
    $num = function (array $s, string $k, string $def): string {
        $e = $s[$k] ?? null;
        if ($e === null || $e['value'] === null) return $def;
        return preg_match('/-?\d+(?:\.\d+)?/', (string)$e['value'], $m) ? $m[0] : $def;
    };

    // Z steppers are implied by Z2/Z3/Z4_DRIVER_TYPE being enabled.
    $z = 1;
    foreach ([2, 3, 4] as $i) {
        if ($d['Z' . $i . '_DRIVER_TYPE']['enabled'] ?? false) {
            $z = $i;
        }
    }
    $zAlign = 'none';
    if ($a['Z_STEPPER_AUTO_ALIGN']['enabled'] ?? false) {
        $zAlign = 'auto_align';
    } elseif ($a['Z_MULTI_ENDSTOPS']['enabled'] ?? false) {
        $zAlign = 'multi_endstops';
    }

    // NOZZLE_PARK_POINT is { X, Y, Z } - pull the Z component if it's a plain number.
    $parkZ = '20';
    $ppRaw = (string)($d['NOZZLE_PARK_POINT']['value'] ?? '');
    // \x7d is the closing brace; using the hex escape keeps brace-counting sane.
    if (preg_match('/,\s*(\d+)\s*\x7d/', $ppRaw, $m)) {
        $parkZ = $m[1];
    }

    $v = [
        'z_steppers'         => (string)$z,
        'z_align'            => $zAlign,
        'z_align_iterations' => $num($a, 'Z_STEPPER_ALIGN_ITERATIONS', '5'),
        'shaping_x'          => $on($a, 'INPUT_SHAPING_X'),
        'shaping_freq_x'     => $num($a, 'SHAPING_FREQ_X', '40'),
        'shaping_zeta_x'     => $num($a, 'SHAPING_ZETA_X', '0.15'),
        'shaping_y'          => $on($a, 'INPUT_SHAPING_Y'),
        'shaping_freq_y'     => $num($a, 'SHAPING_FREQ_Y', '40'),
        'shaping_zeta_y'     => $num($a, 'SHAPING_ZETA_Y', '0.15'),
        'babystepping'       => $on($a, 'BABYSTEPPING'),
        'babystep_zprobe'    => $on($a, 'BABYSTEP_ZPROBE_OFFSET'),
        'arc_support'        => $on($a, 'ARC_SUPPORT'),
        'nozzle_park'        => $on($d, 'NOZZLE_PARK_FEATURE'),
        'park_z'             => $parkZ,
    ];
    if ($board['ui']['neopixel'] ?? false) {
        $v['neopixel']        = $on($d, 'NEOPIXEL_LED');
        $v['neopixel_type']   = trim((string)($d['NEOPIXEL_TYPE']['value'] ?? 'NEO_GRB'));
        $v['neopixel_pixels'] = $num($d, 'NEOPIXEL_PIXELS', '30');
        $v['neopixel_test']   = $on($d, 'NEOPIXEL_STARTUP_TEST');
    }
    return $v;
}

/** Tier-3 settings that live in Configuration.h. */
function marlin_apply_values_tier3_conf(array &$doc, array $v, array $board): array
{
    $applied = [];
    $set = function (string $k, ?string $val, bool $en = true) use (&$doc, &$applied): void {
        if (marlin_config_set($doc, $k, $val, $en)) {
            $applied[] = $k;
        }
    };

    // Z2/Z3/Z4_DRIVER_TYPE is what tells Marlin how many Z motors exist
    // (NUM_Z_STEPPERS is derived from these, not set directly).
    $zMax = (int)($board['ui']['z_steppers'] ?? 1);
    $z    = max(1, min($zMax, (int)($v['z_steppers'] ?? 1)));
    $zDrv = (string)($v['driver_z'] ?? ($doc['defines']['Z_DRIVER_TYPE']['value'] ?? 'A4988'));
    foreach ([2, 3, 4] as $i) {
        $key = 'Z' . $i . '_DRIVER_TYPE';
        $set($key, $i <= $z ? $zDrv : ($doc['defines'][$key]['value'] ?? 'A4988'), $i <= $z);
    }

    // Nozzle park. NOTE: other features (runout, MMU) also force this on, so only
    // ever turn it OFF when nothing else needs it.
    $parkWanted = ($v['nozzle_park'] ?? '0') === '1';
    $forcedOn   = ($v['runout'] ?? '0') === '1' || (string)($v['mmu_model'] ?? 'none') !== 'none';
    $set('NOZZLE_PARK_FEATURE', $doc['defines']['NOZZLE_PARK_FEATURE']['value'] ?? null,
         $parkWanted || $forcedOn);
    if ($parkWanted || $forcedOn) {
        $pz = max(1, min(100, (int)($v['park_z'] ?? 20)));
        $set('NOZZLE_PARK_POINT', '{ (X_MIN_POS + 10), (Y_MAX_POS - 10), ' . $pz . ' }');
    }

    if ($board['ui']['neopixel'] ?? false) {
        $neo = ($v['neopixel'] ?? '0') === '1';
        $set('NEOPIXEL_LED', null, $neo);
        if ($neo) {
            $set('NEOPIXEL_TYPE', (string)($v['neopixel_type'] ?? 'NEO_GRB'));
            $set('NEOPIXEL_PIXELS', (string)max(1, min(200, (int)($v['neopixel_pixels'] ?? 30))));
            $set('NEOPIXEL_STARTUP_TEST', null, ($v['neopixel_test'] ?? '0') === '1');
        }
    }
    return $applied;
}

/** Tier-3 settings that live in Configuration_adv.h. */
function marlin_apply_values_tier3_adv(array &$adv, array $v, array $board): array
{
    $applied = [];
    $set = function (string $k, ?string $val, bool $en = true) use (&$adv, &$applied): void {
        if (marlin_config_set($adv, $k, $val, $en)) {
            $applied[] = $k;
        }
    };
    $keep = fn (string $k): ?string => $adv['defines'][$k]['value'] ?? null;
    $fmt  = fn ($x): string => rtrim(rtrim(sprintf('%.2f', (float)$x), '0'), '.') ?: '0';

    // Multiple Z
    $zMax = (int)($board['ui']['z_steppers'] ?? 1);
    $z    = max(1, min($zMax, (int)($v['z_steppers'] ?? 1)));
    $mode = $z > 1 ? (string)($v['z_align'] ?? 'none') : 'none';
    $set('Z_STEPPER_AUTO_ALIGN', $keep('Z_STEPPER_AUTO_ALIGN'), $mode === 'auto_align');
    $set('Z_MULTI_ENDSTOPS', $keep('Z_MULTI_ENDSTOPS'), $mode === 'multi_endstops');
    if ($mode === 'auto_align') {
        $set('Z_STEPPER_ALIGN_ITERATIONS', (string)max(1, min(10, (int)($v['z_align_iterations'] ?? 5))));
    }

    // Input shaping
    $sx = ($v['shaping_x'] ?? '0') === '1';
    $sy = ($v['shaping_y'] ?? '0') === '1';
    $set('INPUT_SHAPING_X', $keep('INPUT_SHAPING_X'), $sx);
    $set('INPUT_SHAPING_Y', $keep('INPUT_SHAPING_Y'), $sy);
    if ($sx) {
        $set('SHAPING_FREQ_X', $fmt($v['shaping_freq_x'] ?? 40));
        $set('SHAPING_ZETA_X', $fmt($v['shaping_zeta_x'] ?? 0.15));
    }
    if ($sy) {
        $set('SHAPING_FREQ_Y', $fmt($v['shaping_freq_y'] ?? 40));
        $set('SHAPING_ZETA_Y', $fmt($v['shaping_zeta_y'] ?? 0.15));
    }

    // Babystepping. BABYSTEP_ZPROBE_OFFSET needs a probe and is invalid with
    // MESH_BED_LEVELING, so only enable it when both conditions hold.
    $bs = ($v['babystepping'] ?? '0') === '1';
    $set('BABYSTEPPING', $keep('BABYSTEPPING'), $bs);
    $probeOn = (string)($v['probe'] ?? 'none') !== 'none';
    $meshLvl = (string)($v['leveling'] ?? 'none') === 'mesh';
    $set('BABYSTEP_ZPROBE_OFFSET', $keep('BABYSTEP_ZPROBE_OFFSET'),
         $bs && $probeOn && !$meshLvl && ($v['babystep_zprobe'] ?? '0') === '1');

    $set('ARC_SUPPORT', $keep('ARC_SUPPORT'), ($v['arc_support'] ?? '0') === '1');

    return $applied;
}


/* ------------------------------------------- Board catalog self-check */

/**
 * Validate every board JSON at startup. Data bugs (a missing limits block, a
 * Klipper seed without its arch parent, a board claiming a firmware it has no
 * config for) otherwise surface as bizarre UI behaviour or a compile failure
 * ten minutes later. Cheap to check; expensive to debug. Logged, never fatal.
 *
 * @return array<int,string> Human-readable problems (empty = catalog is clean).
 */
function boards_self_check(): array
{
    $problems = [];
    $reqLimits = ['max_bed_x', 'max_bed_y', 'max_z', 'max_hotend_temp',
                  'max_bed_temp', 'max_feedrate_xy', 'max_feedrate_z'];

    foreach (glob(__DIR__ . '/boards/*.json') as $path) {
        $name = basename($path);
        $b    = json_decode((string)@file_get_contents($path), true);
        if (!is_array($b)) {
            $problems[] = "{$name}: not valid JSON";
            continue;
        }
        $id = (string)($b['id'] ?? $name);

        foreach (['id', 'name', 'vendor', 'mcu_variants', 'firmware_support', 'limits'] as $k) {
            if (!isset($b[$k])) {
                $problems[] = "{$id}: missing '{$k}'";
            }
        }
        if (empty($b['mcu_variants'])) {
            $problems[] = "{$id}: no mcu_variants";
        }

        // Limits must exist and be positive, or fields get an impossible range.
        foreach ($reqLimits as $k) {
            $val = $b['limits'][$k] ?? null;
            if (!is_numeric($val) || (float)$val <= 0) {
                $problems[] = "{$id}: limits.{$k} is missing or not positive";
            }
        }

        $fs = $b['firmware_support'] ?? [];

        if (!empty($fs['marlin'])) {
            if (empty($b['marlin']['motherboard'])) {
                $problems[] = "{$id}: claims Marlin support but has no motherboard define";
            }
            foreach (($b['mcu_variants'] ?? []) as $mv) {
                if (empty($mv['marlin_env'])) {
                    $problems[] = "{$id}: MCU '{$mv['id']}' has no marlin_env";
                }
            }
        }

        if (!empty($fs['klipper'])) {
            $k = $b['klipper'] ?? [];
            if (empty($k['reference_config']) || empty($k['mach']) || empty($k['artifact'])) {
                $problems[] = "{$id}: incomplete klipper block";
                continue;
            }
            $seed = implode("\n", (array)($k['config_seed'] ?? []));
            $mach = (string)$k['mach'];
            // The arch CHOICE parent must be present, not just the sub-model -
            // without it Kconfig silently keeps its AVR default.
            $parent = null;
            if (str_contains($mach, 'STM32'))       { $parent = 'CONFIG_MACH_STM32'; }
            elseif (str_contains($mach, 'LPC17'))   { $parent = 'CONFIG_MACH_LPC176X'; }
            elseif (str_contains($mach, 'RP2040'))  { $parent = 'CONFIG_MACH_RPXXXX'; }
            elseif (str_contains($mach, 'atmega'))  { $parent = 'CONFIG_MACH_AVR'; }
            if ($parent !== null && !str_contains($seed, $parent . '=y')) {
                $problems[] = "{$id}: klipper seed is missing the arch parent {$parent} (would build the wrong MCU)";
            }
            if (!str_contains($seed, $mach . '=y') && !str_contains($seed, '{MACH}=y')) {
                $problems[] = "{$id}: klipper seed never selects {$mach}";
            }
        }

        if (!empty($fs['reprap']) && empty($b['rrf']['asset_include'])) {
            $problems[] = "{$id}: claims RRF support but has no asset pattern";
        }
    }
    return $problems;
}

/** Run the self-check once per process and log anything wrong. */
function boards_self_check_once(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $problems = boards_self_check();
    foreach ($problems as $p) {
        error_log('[HotFetched] board catalog: ' . $p);
    }
    if ($problems !== []) {
        error_log('[HotFetched] board catalog: ' . count($problems) . ' problem(s) found - see above.');
    }
}


/* ------------------------------------------- Tier 4: advanced motion */

function marlin_field_defs_tier4(array $board): array
{
    $shapers = ['NONE', 'ZV', 'ZVD', 'ZVDD', 'ZVDDD', 'EI', '2HEI', '3HEI', 'MZV'];
    $shaperLabels = array_combine($shapers, [
        'None', 'ZV (fastest)', 'ZVD', 'ZVDD', 'ZVDDD',
        'EI', '2HEI', '3HEI', 'MZV (robust)',
    ]);

    return [
        // --- Acceleration shaping ---
        ['key' => 's_curve', 'label' => 'S-curve acceleration (smoother ramps)',
         'group' => 'Advanced Motion', 'type' => 'bool'],
        ['key' => 'step_smoothing', 'label' => 'Adaptive step smoothing (quieter, less aliasing)',
         'group' => 'Advanced Motion', 'type' => 'bool'],

        // --- Backlash ---
        ['key' => 'backlash', 'label' => 'Backlash compensation', 'group' => 'Backlash', 'type' => 'bool'],
        ['key' => 'backlash_x', 'label' => 'X backlash (mm)', 'group' => 'Backlash', 'type' => 'float',
         'min' => 0, 'max' => 5, 'requires' => ['backlash' => ['1']]],
        ['key' => 'backlash_y', 'label' => 'Y backlash (mm)', 'group' => 'Backlash', 'type' => 'float',
         'min' => 0, 'max' => 5, 'requires' => ['backlash' => ['1']]],
        ['key' => 'backlash_z', 'label' => 'Z backlash (mm)', 'group' => 'Backlash', 'type' => 'float',
         'min' => 0, 'max' => 5, 'requires' => ['backlash' => ['1']]],
        ['key' => 'backlash_correction', 'label' => 'Correction factor (0 = off, 1 = full)',
         'group' => 'Backlash', 'type' => 'float', 'min' => 0, 'max' => 1,
         'requires' => ['backlash' => ['1']]],
        ['key' => 'backlash_smoothing', 'label' => 'Smoothing distance (mm, 0 = off)',
         'group' => 'Backlash', 'type' => 'float', 'min' => 0, 'max' => 20,
         'requires' => ['backlash' => ['1']]],

        // --- Firmware retraction ---
        ['key' => 'fwretract', 'label' => 'Firmware retraction (G10/G11)',
         'group' => 'Firmware Retraction', 'type' => 'bool'],
        ['key' => 'fwretract_auto', 'label' => 'Override slicer retractions (autoretract)',
         'group' => 'Firmware Retraction', 'type' => 'bool', 'requires' => ['fwretract' => ['1']]],
        ['key' => 'retract_length', 'label' => 'Retract length (mm)', 'group' => 'Firmware Retraction',
         'type' => 'float', 'min' => 0, 'max' => 20, 'requires' => ['fwretract' => ['1']]],
        ['key' => 'retract_feedrate', 'label' => 'Retract feedrate (mm/s)', 'group' => 'Firmware Retraction',
         'type' => 'float', 'min' => 1, 'max' => 200, 'requires' => ['fwretract' => ['1']]],
        ['key' => 'retract_zraise', 'label' => 'Z-hop on retract (mm)', 'group' => 'Firmware Retraction',
         'type' => 'float', 'min' => 0, 'max' => 10, 'requires' => ['fwretract' => ['1']]],
        ['key' => 'recover_length', 'label' => 'Extra recover length (mm)', 'group' => 'Firmware Retraction',
         'type' => 'float', 'min' => -5, 'max' => 20, 'requires' => ['fwretract' => ['1']]],
        ['key' => 'recover_feedrate', 'label' => 'Recover feedrate (mm/s)', 'group' => 'Firmware Retraction',
         'type' => 'float', 'min' => 1, 'max' => 200, 'requires' => ['fwretract' => ['1']]],

        // --- Nozzle clean ---
        ['key' => 'nozzle_clean', 'label' => 'Nozzle clean / wipe (G12)', 'group' => 'Nozzle Clean', 'type' => 'bool'],
        ['key' => 'clean_pattern', 'label' => 'Default wipe pattern', 'group' => 'Nozzle Clean', 'type' => 'select',
         'options' => ['0', '1', '2'],
         'option_labels' => ['0' => 'Line', '1' => 'Zig-zag', '2' => 'Circle'],
         'requires' => ['nozzle_clean' => ['1']]],
        ['key' => 'clean_strokes', 'label' => 'Strokes', 'group' => 'Nozzle Clean', 'type' => 'int',
         'min' => 1, 'max' => 20, 'requires' => ['nozzle_clean' => ['1']]],

        // --- Fixed-Time Motion (alternative engine) ---
        ['key' => 'ft_motion', 'label' => 'Fixed-Time Motion (FT_MOTION — replaces Input Shaping)',
         'group' => 'Fixed-Time Motion', 'type' => 'bool'],
        ['key' => 'ft_default', 'label' => 'Use FT Motion by default at power-on',
         'group' => 'Fixed-Time Motion', 'type' => 'bool', 'requires' => ['ft_motion' => ['1']]],
        ['key' => 'ft_shaper_x', 'label' => 'X shaper', 'group' => 'Fixed-Time Motion', 'type' => 'select',
         'options' => $shapers, 'option_labels' => $shaperLabels, 'requires' => ['ft_motion' => ['1']]],
        ['key' => 'ft_freq_x', 'label' => 'X peak frequency (Hz)', 'group' => 'Fixed-Time Motion',
         'type' => 'float', 'min' => 5, 'max' => 200, 'requires' => ['ft_motion' => ['1']]],
        ['key' => 'ft_shaper_y', 'label' => 'Y shaper', 'group' => 'Fixed-Time Motion', 'type' => 'select',
         'options' => $shapers, 'option_labels' => $shaperLabels, 'requires' => ['ft_motion' => ['1']]],
        ['key' => 'ft_freq_y', 'label' => 'Y peak frequency (Hz)', 'group' => 'Fixed-Time Motion',
         'type' => 'float', 'min' => 5, 'max' => 200, 'requires' => ['ft_motion' => ['1']]],
    ];
}

function marlin_current_values_tier4(array $adv): array
{
    $a   = $adv['defines'];
    $on  = fn (string $k): string => ($a[$k]['enabled'] ?? false) ? '1' : '0';
    $num = function (string $k, string $def) use ($a): string {
        $e = $a[$k] ?? null;
        if ($e === null || $e['value'] === null) return $def;
        return preg_match('/-?\d+(?:\.\d+)?/', (string)$e['value'], $m) ? $m[0] : $def;
    };
    // BACKLASH_DISTANCE_MM is { x, y, z }
    $bl = [0, 0, 0];
    if (preg_match_all('/-?\d+(?:\.\d+)?/', (string)($a['BACKLASH_DISTANCE_MM']['value'] ?? ''), $m)) {
        foreach (array_slice($m[0], 0, 3) as $i => $x) {
            $bl[$i] = $x;
        }
    }
    // FTM_DEFAULT_SHAPER_X is 'ftMotionShaper_ZV' etc.
    $shaper = function (string $k) use ($a): string {
        $raw = (string)($a[$k]['value'] ?? '');
        return preg_match('/ftMotionShaper_(\w+)/', $raw, $m) ? $m[1] : 'NONE';
    };

    return [
        's_curve'             => $on('S_CURVE_ACCELERATION'),
        'step_smoothing'      => $on('ADAPTIVE_STEP_SMOOTHING'),
        'backlash'            => $on('BACKLASH_COMPENSATION'),
        'backlash_x'          => (string)$bl[0],
        'backlash_y'          => (string)$bl[1],
        'backlash_z'          => (string)$bl[2],
        'backlash_correction' => $num('BACKLASH_CORRECTION', '0.0'),
        'backlash_smoothing'  => $num('BACKLASH_SMOOTHING_MM', '3'),
        'fwretract'           => $on('FWRETRACT'),
        'fwretract_auto'      => $on('FWRETRACT_AUTORETRACT'),
        'retract_length'      => $num('RETRACT_LENGTH', '3'),
        'retract_feedrate'    => $num('RETRACT_FEEDRATE', '45'),
        'retract_zraise'      => $num('RETRACT_ZRAISE', '0'),
        'recover_length'      => $num('RETRACT_RECOVER_LENGTH', '0'),
        'recover_feedrate'    => $num('RETRACT_RECOVER_FEEDRATE', '8'),
        'nozzle_clean'        => $on('NOZZLE_CLEAN_FEATURE'),
        'clean_pattern'       => $num('NOZZLE_CLEAN_DEFAULT_PATTERN', '1'),
        'clean_strokes'       => $num('NOZZLE_CLEAN_STROKES', '12'),
        'ft_motion'           => $on('FT_MOTION'),
        'ft_default'          => $on('FTM_IS_DEFAULT_MOTION'),
        'ft_shaper_x'         => $shaper('FTM_DEFAULT_SHAPER_X'),
        'ft_freq_x'           => $num('FTM_SHAPING_DEFAULT_FREQ_X', '37'),
        'ft_shaper_y'         => $shaper('FTM_DEFAULT_SHAPER_Y'),
        'ft_freq_y'           => $num('FTM_SHAPING_DEFAULT_FREQ_Y', '37'),
    ];
}

/** All Tier-4 settings live in Configuration_adv.h. */
function marlin_apply_values_tier4_adv(array &$adv, array $v): array
{
    $applied = [];
    $set = function (string $k, ?string $val, bool $en = true) use (&$adv, &$applied): void {
        if (marlin_config_set($adv, $k, $val, $en)) {
            $applied[] = $k;
        }
    };
    $keep = fn (string $k): ?string => $adv['defines'][$k]['value'] ?? null;
    $f    = fn ($x, int $dp = 2): string => rtrim(rtrim(sprintf('%.' . $dp . 'f', (float)$x), '0'), '.') ?: '0';

    $set('S_CURVE_ACCELERATION', $keep('S_CURVE_ACCELERATION'), ($v['s_curve'] ?? '0') === '1');
    $set('ADAPTIVE_STEP_SMOOTHING', $keep('ADAPTIVE_STEP_SMOOTHING'), ($v['step_smoothing'] ?? '0') === '1');

    // Backlash. Marlin errors unless DISTANCE_MM and CORRECTION are both present.
    $bl = ($v['backlash'] ?? '0') === '1';
    $set('BACKLASH_COMPENSATION', $keep('BACKLASH_COMPENSATION'), $bl);
    if ($bl) {
        $set('BACKLASH_DISTANCE_MM', '{ ' . $f($v['backlash_x'] ?? 0, 3) . ', '
                                         . $f($v['backlash_y'] ?? 0, 3) . ', '
                                         . $f($v['backlash_z'] ?? 0, 3) . ' }');
        $set('BACKLASH_CORRECTION', $f($v['backlash_correction'] ?? 0));
        $sm = (float)($v['backlash_smoothing'] ?? 0);
        $set('BACKLASH_SMOOTHING_MM', $sm > 0 ? $f($sm) : $keep('BACKLASH_SMOOTHING_MM'), $sm > 0);
    }

    // Firmware retraction
    $fr = ($v['fwretract'] ?? '0') === '1';
    $set('FWRETRACT', $keep('FWRETRACT'), $fr);
    if ($fr) {
        $set('FWRETRACT_AUTORETRACT', null, ($v['fwretract_auto'] ?? '0') === '1');
        $set('RETRACT_LENGTH', $f($v['retract_length'] ?? 3));
        $set('RETRACT_FEEDRATE', $f($v['retract_feedrate'] ?? 45));
        $set('RETRACT_ZRAISE', $f($v['retract_zraise'] ?? 0));
        $set('RETRACT_RECOVER_LENGTH', $f($v['recover_length'] ?? 0));
        $set('RETRACT_RECOVER_FEEDRATE', $f($v['recover_feedrate'] ?? 8));
    }

    // Nozzle clean. At least one pattern must be enabled, and the default pattern
    // must be one that IS enabled - so enable all three and pick the default.
    $nc = ($v['nozzle_clean'] ?? '0') === '1';
    $set('NOZZLE_CLEAN_FEATURE', $keep('NOZZLE_CLEAN_FEATURE'), $nc);
    if ($nc) {
        $set('NOZZLE_CLEAN_PATTERN_LINE', null, true);
        $set('NOZZLE_CLEAN_PATTERN_ZIGZAG', null, true);
        $set('NOZZLE_CLEAN_PATTERN_CIRCLE', null, true);
        $set('NOZZLE_CLEAN_DEFAULT_PATTERN', (string)(int)($v['clean_pattern'] ?? 1));
        $set('NOZZLE_CLEAN_STROKES', (string)max(1, min(20, (int)($v['clean_strokes'] ?? 12))));
    }

    // Fixed-Time Motion: an alternative motion engine with its own shapers.
    // It supersedes INPUT_SHAPING_X/Y, so the two are gated apart in Stage 1.
    $ft = ($v['ft_motion'] ?? '0') === '1';
    $set('FT_MOTION', $keep('FT_MOTION'), $ft);
    if ($ft) {
        $set('FTM_IS_DEFAULT_MOTION', null, ($v['ft_default'] ?? '0') === '1');
        $sx = strtoupper((string)($v['ft_shaper_x'] ?? 'NONE'));
        $sy = strtoupper((string)($v['ft_shaper_y'] ?? 'NONE'));
        $set('FTM_DEFAULT_SHAPER_X', 'ftMotionShaper_' . $sx);
        $set('FTM_DEFAULT_SHAPER_Y', 'ftMotionShaper_' . $sy);
        $set('FTM_SHAPING_DEFAULT_FREQ_X', $f($v['ft_freq_x'] ?? 37, 1) . 'f');
        $set('FTM_SHAPING_DEFAULT_FREQ_Y', $f($v['ft_freq_y'] ?? 37, 1) . 'f');
    }

    return $applied;
}

/* ------------------------------------------------- Bootscreen generator */

/**
 * Convert an uploaded image into a Marlin _Bootscreen.h (128x64 1-bit,
 * Floyd-Steinberg dithered). Returns ['header' => ..., 'preview_b64' => ...]
 * or an error string.
 */
function bootscreen_generate(string $imgPath, array $opts = []): array|string
{
    if (!function_exists('imagecreatefromstring')) {
        return 'GD extension not available in this build';
    }
    // Options: target ('boot'|'status'), threshold (0-255), invert (bool), dither (bool).
    $target    = ($opts['target'] ?? 'boot') === 'status' ? 'status' : 'boot';
    $threshold = max(0, min(255, (int)($opts['threshold'] ?? 128)));
    $invert    = (bool)($opts['invert'] ?? false);
    $dither    = array_key_exists('dither', $opts) ? (bool)$opts['dither'] : true;

    $raw = @file_get_contents($imgPath);
    if ($raw === false) {
        return 'Could not read uploaded image';
    }
    $src = @imagecreatefromstring($raw);
    if ($src === false) {
        return 'Unsupported image format (use PNG or JPEG)';
    }

    $W = 128;
    $H = 64;
    $sw = imagesx($src);
    $sh = imagesy($src);
    $scale = min($W / $sw, $H / $sh);
    $dw = max(1, (int)round($sw * $scale));
    $dh = max(1, (int)round($sh * $scale));

    $canvas = imagecreatetruecolor($W, $H);
    imagefilledrectangle($canvas, 0, 0, $W, $H, imagecolorallocate($canvas, 0, 0, 0));
    imagecopyresampled($canvas, $src, (int)(($W - $dw) / 2), (int)(($H - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);

    $lum = [];
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            $rgb = imagecolorat($canvas, $x, $y);
            $lum[$y][$x] = 0.2126 * (($rgb >> 16) & 0xFF) + 0.7152 * (($rgb >> 8) & 0xFF) + 0.0722 * ($rgb & 0xFF);
        }
    }
    $bits = [];
    if ($dither) {
        // Floyd-Steinberg error diffusion, thresholded.
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                $old = $lum[$y][$x];
                $new = $old < $threshold ? 0 : 255;
                $bits[$y][$x] = $new === 255 ? 1 : 0;
                $err = $old - $new;
                if ($x + 1 < $W)                 $lum[$y][$x + 1]     += $err * 7 / 16;
                if ($y + 1 < $H && $x > 0)       $lum[$y + 1][$x - 1] += $err * 3 / 16;
                if ($y + 1 < $H)                 $lum[$y + 1][$x]     += $err * 5 / 16;
                if ($y + 1 < $H && $x + 1 < $W)  $lum[$y + 1][$x + 1] += $err * 1 / 16;
            }
        }
    } else {
        // Hard threshold, no diffusion.
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                $bits[$y][$x] = $lum[$y][$x] < $threshold ? 0 : 1;
            }
        }
    }
    if ($invert) {
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                $bits[$y][$x] ^= 1;
            }
        }
    }

    // Pack rows MSB-first, 16 bytes per row.
    $rows = [];
    for ($y = 0; $y < $H; $y++) {
        $bytes = [];
        for ($bx = 0; $bx < 16; $bx++) {
            $b = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $b = ($b << 1) | $bits[$y][$bx * 8 + $bit];
            }
            $bytes[] = sprintf('B%08b', $b);
        }
        $rows[] = '  ' . implode(', ', $bytes);
    }
    $arr = implode(",\n", $rows);

    if ($target === 'status') {
        $header = "/**\n * Custom status screen image generated by HotFetched " . HF_VERSION . "\n */\n"
            . "#pragma once\n\n"
            . "#define STATUS_SCREENWIDTH {$W}\n\n"
            . "const unsigned char status_screen0_bmp[] PROGMEM = {\n"
            . $arr . "\n};\n";
    } else {
        $header = "/**\n * Custom bootscreen generated by HotFetched " . HF_VERSION . "\n */\n"
            . "#pragma once\n\n"
            . "#define CUSTOM_BOOTSCREEN_TIMEOUT 2500\n"
            . "#define CUSTOM_BOOTSCREEN_BMPWIDTH {$W}\n\n"
            . "const unsigned char custom_start_bmp[] PROGMEM = {\n"
            . $arr . "\n};\n";
    }

    // Preview: 3x upscaled PNG of the 1-bit result.
    $pv = imagecreatetruecolor($W * 3, $H * 3);
    $on  = imagecolorallocate($pv, 230, 235, 242);
    $off = imagecolorallocate($pv, 14, 17, 22);
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            imagefilledrectangle($pv, $x * 3, $y * 3, $x * 3 + 2, $y * 3 + 2, $bits[$y][$x] ? $on : $off);
        }
    }
    ob_start();
    imagepng($pv);
    $png = ob_get_clean();
    imagedestroy($pv);
    imagedestroy($canvas);

    return ['header' => $header, 'preview_b64' => base64_encode($png), 'target' => $target];
}

/* --------------------------------------------- TFT color image (RGB565) */

/**
 * TFT display image specs. BTT TFT touch firmware reads a 16-bit (RGB565) BMP
 * with bottom-up (positive-height) row order from a per-model folder on the
 * SD card root. Dimensions match each panel.
 */
function tft_image_specs(): array
{
    return [
        'btt_tft70' => ['w' => 1024, 'h' => 600, 'folder' => 'TFT70', 'file' => 'bmp/boot/booting.bmp'],
        'btt_tft50' => ['w' => 800,  'h' => 480, 'folder' => 'TFT50', 'file' => 'bmp/boot/booting.bmp'],
        'btt_tft43' => ['w' => 480,  'h' => 272, 'folder' => 'TFT43', 'file' => 'bmp/boot/booting.bmp'],
        'btt_tft35' => ['w' => 480,  'h' => 320, 'folder' => 'TFT35', 'file' => 'bmp/boot/booting.bmp'],
    ];
}

/**
 * Convert an uploaded image into a TFT-ready 16-bit (RGB565) BMP, scaled to fit
 * the panel and letterboxed on black. Returns ['bmp' => binary, 'preview_b64' =>
 * ..., 'spec' => ...] or an error string.
 */
function tft_image_generate(string $imgPath, string $model): array|string
{
    if (!function_exists('imagecreatefromstring')) {
        return 'GD extension not available in this build';
    }
    $specs = tft_image_specs();
    if (!isset($specs[$model])) {
        return 'Unknown TFT model';
    }
    $W = $specs[$model]['w'];
    $H = $specs[$model]['h'];

    $raw = @file_get_contents($imgPath);
    if ($raw === false) {
        return 'Could not read uploaded image';
    }
    $src = @imagecreatefromstring($raw);
    if ($src === false) {
        return 'Unsupported image format (use PNG or JPEG)';
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    $scale = min($W / $sw, $H / $sh);
    $dw = max(1, (int)round($sw * $scale));
    $dh = max(1, (int)round($sh * $scale));

    $canvas = imagecreatetruecolor($W, $H);
    imagefilledrectangle($canvas, 0, 0, $W, $H, imagecolorallocate($canvas, 0, 0, 0));
    imagecopyresampled($canvas, $src, (int)(($W - $dw) / 2), (int)(($H - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);

    // Build a 16-bit BMP (BITMAPINFOHEADER, BI_BITFIELDS RGB565), bottom-up.
    $rowBytes = $W * 2;
    $pad = (4 - ($rowBytes % 4)) % 4; // BMP rows padded to 4 bytes
    $imageSize = ($rowBytes + $pad) * $H;
    $pixelOffset = 14 + 40 + 12; // file header + info header + 3 bitfield masks
    $fileSize = $pixelOffset + $imageSize;

    // BITMAPFILEHEADER (14)
    $bmp = 'BM'
         . pack('V', $fileSize)
         . pack('v', 0) . pack('v', 0)
         . pack('V', $pixelOffset);
    // BITMAPINFOHEADER (40), compression = 3 (BI_BITFIELDS), 16 bpp
    $bmp .= pack('V', 40)
         . pack('V', $W)
         . pack('V', $H)          // positive = bottom-up rows
         . pack('v', 1)
         . pack('v', 16)
         . pack('V', 3)
         . pack('V', $imageSize)
         . pack('V', 2835) . pack('V', 2835) // ~72 DPI
         . pack('V', 0) . pack('V', 0);
    // RGB565 channel masks
    $bmp .= pack('V', 0xF800) . pack('V', 0x07E0) . pack('V', 0x001F);

    // Pixel data, bottom row first.
    $padBytes = str_repeat("\x00", $pad);
    for ($y = $H - 1; $y >= 0; $y--) {
        $row = '';
        for ($x = 0; $x < $W; $x++) {
            $rgb = imagecolorat($canvas, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $v = (($r & 0xF8) << 8) | (($g & 0xFC) << 3) | ($b >> 3);
            $row .= pack('v', $v);
        }
        $bmp .= $row . $padBytes;
    }

    // Preview PNG (downscaled for the browser).
    $pvW = 320;
    $pvH = max(1, (int)round($H * $pvW / $W));
    $pv = imagecreatetruecolor($pvW, $pvH);
    imagecopyresampled($pv, $canvas, 0, 0, 0, 0, $pvW, $pvH, $W, $H);
    ob_start();
    imagepng($pv);
    $png = ob_get_clean();
    imagedestroy($pv);
    imagedestroy($canvas);

    return ['bmp' => $bmp, 'preview_b64' => base64_encode($png), 'spec' => $specs[$model]];
}

/* ------------------------------------------------------- Sound library */

function soundlib_dir(): string
{
    return HF_PRIVATE_DIR . '/soundlib';
}

function soundlib_state_write(array $state): void
{
    if (!is_dir(soundlib_dir())) {
        @mkdir(soundlib_dir(), 0775, true);
    }
    @file_put_contents(soundlib_dir() . '/.state.json', json_encode($state));
}

function soundlib_status(): array
{
    $f = soundlib_dir() . '/.state.json';
    if (!is_file($f)) {
        return ['state' => 'none'];
    }
    $st = json_decode((string)file_get_contents($f), true);
    if (!is_array($st)) {
        return ['state' => 'none'];
    }
    // Stall recovery: installing with no state change for 10 minutes.
    if (($st['state'] ?? '') === 'installing' && time() - (int)filemtime($f) > 600) {
        return ['state' => 'error', 'error' => 'Install stalled - retry'];
    }
    return $st;
}

/** @return string[] relative .mid paths */
function soundlib_index(): array
{
    $f = soundlib_dir() . '/index.json';
    if (!is_file($f)) {
        return [];
    }
    $idx = json_decode((string)file_get_contents($f), true);
    return is_array($idx) ? $idx : [];
}

/* ------------------------------------------------ Shared validation */

/**
 * Validate submitted values against field definitions. All fields are
 * required unless their `requires` condition is unmet. Returns
 * [values, errors] with values normalized to strings.
 */
function hf_validate_fields(array $fields, array $input): array
{
    $values = [];
    $errors = [];

    foreach ($fields as $f) {
        $key = $f['key'];

        // Conditional fields: skip when the condition doesn't hold.
        if (isset($f['requires'])) {
            $met = true;
            foreach ($f['requires'] as $rk => $rv) {
                $have = (string)($input[$rk] ?? '');
                $okReq = is_array($rv) ? in_array($have, array_map('strval', $rv), true) : $have === (string)$rv;
                if (!$okReq) {
                    $met = false;
                    break;
                }
            }
            if (!$met) {
                $values[$key] = $f['type'] === 'bool' ? '0' : '';
                continue;
            }
        }

        $raw = $input[$key] ?? null;

        switch ($f['type']) {
            case 'text':
                $raw = is_string($raw) ? trim($raw) : '';
                if ($raw === '') {
                    $errors[$key] = 'Required';
                } elseif (mb_strlen($raw) > ($f['maxlen'] ?? 64)) {
                    $errors[$key] = 'Too long (max ' . ($f['maxlen'] ?? 64) . ')';
                } elseif (!preg_match('/^[\x20-\x7E]+$/', $raw)) {
                    $errors[$key] = 'Printable ASCII only';
                }
                $values[$key] = str_replace(['"', '\\'], '', $raw);
                break;

            case 'int':
                $n = filter_var($raw, FILTER_VALIDATE_INT);
                if ($n === false) {
                    $errors[$key] = 'Required (whole number)';
                    $values[$key] = '';
                    break;
                }
                // Overridable ceiling: when the linked override flag is set,
                // the hard override_max replaces the board limit.
                $max = $f['max'] ?? null;
                if (isset($f['override_key'], $f['override_max'])
                    && (string)($input[$f['override_key']] ?? '') === '1') {
                    $max = $f['override_max'];
                }
                if (isset($f['min']) && $n < $f['min']) {
                    $errors[$key] = 'Minimum ' . $f['min'];
                } elseif ($max !== null && $n > $max) {
                    $errors[$key] = 'Maximum ' . $max . ' for this board';
                }
                $values[$key] = (string)$n;
                break;

            case 'float':
                $n = filter_var($raw, FILTER_VALIDATE_FLOAT);
                if ($n === false) {
                    $errors[$key] = 'Required (number)';
                    $values[$key] = '';
                    break;
                }
                if (isset($f['min']) && $n < $f['min']) {
                    $errors[$key] = 'Minimum ' . $f['min'];
                } elseif (isset($f['max']) && $n > $f['max']) {
                    $errors[$key] = 'Maximum ' . $f['max'];
                }
                $values[$key] = rtrim(rtrim(sprintf('%.3f', $n), '0'), '.');
                break;

            case 'select':
                $raw = is_string($raw) ? $raw : '';
                if (!in_array($raw, $f['options'], true)) {
                    $errors[$key] = 'Choose a valid option';
                }
                $values[$key] = $raw;
                break;

            case 'bool':
                $values[$key] = ($raw === '1' || $raw === 1 || $raw === true) ? '1' : '0';
                break;
        }
    }

    return [$values, $errors];
}

/* --------------------------------------- Tier 1: motion / TMC / endstops */

/** Tier-1 fields living in Configuration.h. */
function marlin_field_defs_motion(array $board): array
{
    $tmc = ['TMC2209', 'TMC2208', 'TMC2130', 'TMC5160'];
    return [
        ['key' => 'kinematics', 'label' => 'Kinematics', 'group' => 'Kinematics', 'type' => 'select',
         'options' => ['cartesian', 'corexy'],
         'option_labels' => ['cartesian' => 'Cartesian (bedslinger / i3)', 'corexy' => 'CoreXY']],

        ['key' => 'steps_x', 'label' => 'Steps/mm X', 'group' => 'Motion', 'type' => 'float', 'min' => 1, 'max' => 3200],
        ['key' => 'steps_y', 'label' => 'Steps/mm Y', 'group' => 'Motion', 'type' => 'float', 'min' => 1, 'max' => 3200],
        ['key' => 'steps_z', 'label' => 'Steps/mm Z', 'group' => 'Motion', 'type' => 'float', 'min' => 1, 'max' => 6400],
        ['key' => 'steps_e', 'label' => 'Steps/mm E', 'group' => 'Motion', 'type' => 'float', 'min' => 1, 'max' => 6400],

        ['key' => 'accel_max_x', 'label' => 'Max accel X (mm/s²)', 'group' => 'Motion', 'type' => 'int', 'min' => 100, 'max' => 30000],
        ['key' => 'accel_max_y', 'label' => 'Max accel Y (mm/s²)', 'group' => 'Motion', 'type' => 'int', 'min' => 100, 'max' => 30000],
        ['key' => 'accel_max_z', 'label' => 'Max accel Z (mm/s²)', 'group' => 'Motion', 'type' => 'int', 'min' => 10,  'max' => 5000],
        ['key' => 'accel_max_e', 'label' => 'Max accel E (mm/s²)', 'group' => 'Motion', 'type' => 'int', 'min' => 100, 'max' => 30000],
        ['key' => 'accel_print',   'label' => 'Print acceleration (mm/s²)',   'group' => 'Motion', 'type' => 'int', 'min' => 50, 'max' => 30000],
        ['key' => 'accel_retract', 'label' => 'Retract acceleration (mm/s²)', 'group' => 'Motion', 'type' => 'int', 'min' => 50, 'max' => 30000],
        ['key' => 'accel_travel',  'label' => 'Travel acceleration (mm/s²)',  'group' => 'Motion', 'type' => 'int', 'min' => 50, 'max' => 30000],
        ['key' => 'junction_dev',  'label' => 'Junction deviation (mm)',      'group' => 'Motion', 'type' => 'float', 'min' => 0.001, 'max' => 0.3],

        ['key' => 'invert_x',  'label' => 'Invert X motor direction',  'group' => 'Motion', 'type' => 'bool'],
        ['key' => 'invert_y',  'label' => 'Invert Y motor direction',  'group' => 'Motion', 'type' => 'bool'],
        ['key' => 'invert_z',  'label' => 'Invert Z motor direction',  'group' => 'Motion', 'type' => 'bool'],
        ['key' => 'invert_e0', 'label' => 'Invert E0 motor direction', 'group' => 'Motion', 'type' => 'bool'],

        ['key' => 'endstop_x', 'label' => 'X endstop hit state', 'group' => 'Endstops', 'type' => 'select', 'options' => ['HIGH', 'LOW']],
        ['key' => 'endstop_y', 'label' => 'Y endstop hit state', 'group' => 'Endstops', 'type' => 'select', 'options' => ['HIGH', 'LOW']],
        ['key' => 'endstop_z', 'label' => 'Z endstop hit state', 'group' => 'Endstops', 'type' => 'select', 'options' => ['HIGH', 'LOW']],
    ];
}

/** Tier-1 fields living in Configuration_adv.h (TMC block). */
function marlin_field_defs_adv(array $board): array
{
    $tmcDrivers = ['TMC2209', 'TMC2208', 'TMC2130', 'TMC5160'];
    $sgDrivers  = ['TMC2209', 'TMC2130', 'TMC5160']; // StallGuard-capable

    return [
        ['key' => 'tmc_current_x',  'label' => 'X driver current (mA RMS)',  'group' => 'Stepper Drivers (advanced)', 'type' => 'int', 'min' => 100, 'max' => 2000,
         'requires' => ['driver_x' => $tmcDrivers]],
        ['key' => 'tmc_current_y',  'label' => 'Y driver current (mA RMS)',  'group' => 'Stepper Drivers (advanced)', 'type' => 'int', 'min' => 100, 'max' => 2000,
         'requires' => ['driver_y' => $tmcDrivers]],
        ['key' => 'tmc_current_z',  'label' => 'Z driver current (mA RMS)',  'group' => 'Stepper Drivers (advanced)', 'type' => 'int', 'min' => 100, 'max' => 2000,
         'requires' => ['driver_z' => $tmcDrivers]],
        ['key' => 'tmc_current_e0', 'label' => 'E0 driver current (mA RMS)', 'group' => 'Stepper Drivers (advanced)', 'type' => 'int', 'min' => 100, 'max' => 2000,
         'requires' => ['driver_e0' => $tmcDrivers]],

        ['key' => 'sensorless_homing', 'label' => 'Sensorless homing (StallGuard — no X/Y endstop switches)', 'group' => 'Stepper Drivers (advanced)', 'type' => 'bool',
         'requires' => ['driver_x' => $sgDrivers]],
        ['key' => 'stall_x', 'label' => 'X stall sensitivity (higher = more sensitive)', 'group' => 'Stepper Drivers (advanced)', 'type' => 'int', 'min' => -64, 'max' => 255,
         'requires' => ['sensorless_homing' => ['1']]],
        ['key' => 'stall_y', 'label' => 'Y stall sensitivity', 'group' => 'Stepper Drivers (advanced)', 'type' => 'int', 'min' => -64, 'max' => 255,
         'requires' => ['sensorless_homing' => ['1']]],
    ];
}

/** Current values for Tier-1 fields from both parsed documents. */
function marlin_current_values_tier1(array $doc, array $adv): array
{
    $d = $doc['defines'];
    $a = $adv['defines'];
    $num = function (array $src, string $k): ?string {
        $e = $src[$k] ?? null;
        if ($e === null || $e['value'] === null) return null;
        return preg_match('/-?\d+(?:\.\d+)?/', (string)$e['value'], $m) ? $m[0] : null;
    };
    $flag = fn (array $src, string $k): string => ($src[$k]['enabled'] ?? false) ? '1' : '0';
    $bool = function (array $src, string $k): string {
        $v = strtolower(trim((string)($src[$k]['value'] ?? 'false')));
        return $v === 'true' ? '1' : '0';
    };

    [$sx, $sy, $sz, $se] = marlin_extract_numbers($d['DEFAULT_AXIS_STEPS_PER_UNIT']['value'] ?? null, 4);
    [$ax, $ay, $az, $ae] = marlin_extract_numbers($d['DEFAULT_MAX_ACCELERATION']['value'] ?? null, 4);

    $endstop = function (string $axis) use ($d): string {
        $hit = $d[$axis . '_MIN_ENDSTOP_HIT_STATE'] ?? null;
        if ($hit !== null) {
            return strtoupper(trim((string)$hit['value'])) === 'LOW' ? 'LOW' : 'HIGH';
        }
        $inv = $d[$axis . '_MIN_ENDSTOP_INVERTING'] ?? null; // older Marlin naming
        if ($inv !== null) {
            return strtolower(trim((string)$inv['value'])) === 'true' ? 'LOW' : 'HIGH';
        }
        return 'HIGH';
    };

    $fmt = fn ($v) => $v !== null ? rtrim(rtrim(sprintf('%.3f', (float)$v), '0'), '.') : null;

    return [
        'kinematics' => ($d['COREXY']['enabled'] ?? false) ? 'corexy' : 'cartesian',
        'steps_x' => $fmt($sx), 'steps_y' => $fmt($sy), 'steps_z' => $fmt($sz), 'steps_e' => $fmt($se),
        'accel_max_x' => $ax !== null ? (string)(int)$ax : null,
        'accel_max_y' => $ay !== null ? (string)(int)$ay : null,
        'accel_max_z' => $az !== null ? (string)(int)$az : null,
        'accel_max_e' => $ae !== null ? (string)(int)$ae : null,
        'accel_print'   => $num($d, 'DEFAULT_ACCELERATION'),
        'accel_retract' => $num($d, 'DEFAULT_RETRACT_ACCELERATION'),
        'accel_travel'  => $num($d, 'DEFAULT_TRAVEL_ACCELERATION'),
        'junction_dev'  => $num($d, 'JUNCTION_DEVIATION_MM') ?? '0.013',
        'invert_x' => $bool($d, 'INVERT_X_DIR'), 'invert_y' => $bool($d, 'INVERT_Y_DIR'),
        'invert_z' => $bool($d, 'INVERT_Z_DIR'), 'invert_e0' => $bool($d, 'INVERT_E0_DIR'),
        'endstop_x' => $endstop('X'), 'endstop_y' => $endstop('Y'), 'endstop_z' => $endstop('Z'),
        'tmc_current_x'  => $num($a, 'X_CURRENT')  ?? '800',
        'tmc_current_y'  => $num($a, 'Y_CURRENT')  ?? '800',
        'tmc_current_z'  => $num($a, 'Z_CURRENT')  ?? '800',
        'tmc_current_e0' => $num($a, 'E0_CURRENT') ?? '800',
        'sensorless_homing' => $flag($a, 'SENSORLESS_HOMING'),
        'stall_x' => $num($a, 'X_STALL_SENSITIVITY') ?? '8',
        'stall_y' => $num($a, 'Y_STALL_SENSITIVITY') ?? '8',
    ];
}

/** Apply Tier-1 values to Configuration.h. */
function marlin_apply_values_motion(array &$doc, array $v): array
{
    $applied = [];
    $set = function (string $key, ?string $value, bool $enable = true) use (&$doc, &$applied): void {
        if (marlin_config_set($doc, $key, $value, $enable)) {
            $applied[] = $key;
        }
    };
    $f = fn (string $n): string => rtrim(rtrim(sprintf('%.3f', (float)$n), '0'), '.');

    $set('COREXY', null, ($v['kinematics'] ?? 'cartesian') === 'corexy');

    $set('DEFAULT_AXIS_STEPS_PER_UNIT', sprintf('{ %s, %s, %s, %s }',
        $f($v['steps_x']), $f($v['steps_y']), $f($v['steps_z']), $f($v['steps_e'])));
    $set('DEFAULT_MAX_ACCELERATION', sprintf('{ %d, %d, %d, %d }',
        (int)$v['accel_max_x'], (int)$v['accel_max_y'], (int)$v['accel_max_z'], (int)$v['accel_max_e']));
    $set('DEFAULT_ACCELERATION', (string)(int)$v['accel_print']);
    $set('DEFAULT_RETRACT_ACCELERATION', (string)(int)$v['accel_retract']);
    $set('DEFAULT_TRAVEL_ACCELERATION', (string)(int)$v['accel_travel']);
    $set('JUNCTION_DEVIATION_MM', $f($v['junction_dev']));

    foreach (['x' => 'INVERT_X_DIR', 'y' => 'INVERT_Y_DIR', 'z' => 'INVERT_Z_DIR', 'e0' => 'INVERT_E0_DIR'] as $k => $def) {
        $set($def, ($v['invert_' . $k] ?? '0') === '1' ? 'true' : 'false');
    }

    foreach (['X', 'Y', 'Z'] as $axis) {
        $want = ($v['endstop_' . strtolower($axis)] ?? 'HIGH') === 'LOW' ? 'LOW' : 'HIGH';
        if (isset($doc['defines'][$axis . '_MIN_ENDSTOP_HIT_STATE'])) {
            $set($axis . '_MIN_ENDSTOP_HIT_STATE', $want);
        } elseif (isset($doc['defines'][$axis . '_MIN_ENDSTOP_INVERTING'])) {
            $set($axis . '_MIN_ENDSTOP_INVERTING', $want === 'LOW' ? 'true' : 'false');
        }
    }
    return $applied;
}

/** Apply Tier-1 values to Configuration_adv.h (TMC block). */
function marlin_apply_values_adv(array &$adv, array $v): array
{
    $applied = [];
    $set = function (string $key, ?string $value, bool $enable = true) use (&$adv, &$applied): void {
        if (marlin_config_set($adv, $key, $value, $enable)) {
            $applied[] = $key;
        }
    };
    $keep = fn (string $key): ?string => $adv['defines'][$key]['value'] ?? null;

    foreach (['x' => 'X_CURRENT', 'y' => 'Y_CURRENT', 'z' => 'Z_CURRENT', 'e0' => 'E0_CURRENT'] as $k => $def) {
        $mA = (int)($v['tmc_current_' . $k] ?? 0);
        if ($mA >= 100) {
            $set($def, (string)$mA);
        }
    }

    $sensorless = ($v['sensorless_homing'] ?? '0') === '1';
    $set('SENSORLESS_HOMING', $keep('SENSORLESS_HOMING'), $sensorless);
    if ($sensorless) {
        $set('X_STALL_SENSITIVITY', (string)(int)$v['stall_x']);
        $set('Y_STALL_SENSITIVITY', (string)(int)$v['stall_y']);
    }

    // Boot & display images (all in Configuration_adv.h).
    $showBoot = ($v['show_bootscreen'] ?? '1') === '1';
    $set('SHOW_BOOTSCREEN', $keep('SHOW_BOOTSCREEN'), $showBoot);
    if ($showBoot) {
        $logo = $v['boot_logo_size'] ?? 'full';
        $set('BOOT_MARLIN_LOGO_SMALL', $keep('BOOT_MARLIN_LOGO_SMALL'), $logo === 'small');
        $set('BOOT_MARLIN_LOGO_ANIMATED', $keep('BOOT_MARLIN_LOGO_ANIMATED'), $logo === 'animated');
    }
    $set('CUSTOM_STATUS_SCREEN_IMAGE', $keep('CUSTOM_STATUS_SCREEN_IMAGE'), ($v['custom_status_image'] ?? '0') === '1');

    return $applied;
}

/* ---------------------------------------------------- Klipper pipeline */

/** Editable fields for a Klipper project (applied to printer.cfg at build). */
function klipper_field_defs(array $board): array
{
    $lim = $board['limits'] ?? [];
    // Never emit a zero/negative max — a missing limit would otherwise make the
    // field impossible to satisfy (max < min). Fall back to generous defaults.
    $limMax = fn (string $k, int $default): int => (($v = (int)($lim[$k] ?? 0)) > 0 ? $v : $default);
    return [
        ['key' => 'bed_x',  'label' => 'X travel / position_max (mm)', 'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => max(2000, $limMax('max_bed_x', 500))],
        ['key' => 'bed_y',  'label' => 'Y travel / position_max (mm)', 'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => max(2000, $limMax('max_bed_y', 500))],
        ['key' => 'z_max',  'label' => 'Z height / position_max (mm)', 'group' => 'Geometry', 'type' => 'int', 'min' => 50, 'max' => max(2000, $limMax('max_z', 600))],
        ['key' => 'kl_velocity', 'label' => 'max_velocity (mm/s)',  'group' => 'Speed', 'type' => 'int', 'min' => 20,  'max' => 1000],
        ['key' => 'kl_accel',    'label' => 'max_accel (mm/s²)',    'group' => 'Speed', 'type' => 'int', 'min' => 100, 'max' => 50000],
        ['key' => 'kl_cur_x',  'label' => 'X run_current (A)',  'group' => 'Stepper Drivers', 'type' => 'float', 'min' => 0.1, 'max' => 2.0],
        ['key' => 'kl_cur_y',  'label' => 'Y run_current (A)',  'group' => 'Stepper Drivers', 'type' => 'float', 'min' => 0.1, 'max' => 2.0],
        ['key' => 'kl_cur_z',  'label' => 'Z run_current (A)',  'group' => 'Stepper Drivers', 'type' => 'float', 'min' => 0.1, 'max' => 2.0],
        ['key' => 'kl_cur_e',  'label' => 'Extruder run_current (A)', 'group' => 'Stepper Drivers', 'type' => 'float', 'min' => 0.1, 'max' => 2.0],
    ];
}

/** Prefill Klipper field values by reading the board's reference printer.cfg. */
function klipper_current_values(string $refCfg): array
{
    $grab = function (string $section, string $key) use ($refCfg): ?string {
        if (!preg_match('/^\[' . preg_quote($section, '/') . '\]\s*\n(.*?)(?=^\[|\z)/ms', $refCfg, $m)) {
            return null;
        }
        return preg_match('/^' . preg_quote($key, '/') . '\s*:\s*([^\s#]+)/m', $m[1], $v) ? $v[1] : null;
    };
    return [
        'bed_x' => $grab('stepper_x', 'position_max') ?? '235',
        'bed_y' => $grab('stepper_y', 'position_max') ?? '235',
        'z_max' => $grab('stepper_z', 'position_max') ?? '250',
        'kl_velocity' => $grab('printer', 'max_velocity') ?? '300',
        'kl_accel'    => $grab('printer', 'max_accel') ?? '3000',
        'kl_cur_x' => $grab('tmc2209 stepper_x', 'run_current') ?? '0.8',
        'kl_cur_y' => $grab('tmc2209 stepper_y', 'run_current') ?? '0.8',
        'kl_cur_z' => $grab('tmc2209 stepper_z', 'run_current') ?? '0.8',
        'kl_cur_e' => $grab('tmc2209 extruder', 'run_current') ?? '0.65',
    ];
}

/** Apply saved values onto the reference printer.cfg text (surgical). */
function klipper_generate_printer_cfg(string $refCfg, array $v): string
{
    $setIn = function (string $cfg, string $section, string $key, string $value): string {
        return preg_replace_callback(
            '/(^\[' . preg_quote($section, '/') . '\]\s*\n)(.*?)(?=^\[|\z)/ms',
            function ($m) use ($key, $value) {
                $body = preg_replace('/^(' . preg_quote($key, '/') . '\s*:\s*)[^\s#]+/m', '${1}' . $value, $m[2], 1, $n);
                return $m[1] . ($n ? $body : $m[2]);
            },
            $cfg, 1
        ) ?? $cfg;
    };
    $cfg = $refCfg;
    $cfg = $setIn($cfg, 'stepper_x', 'position_max', (string)(int)$v['bed_x']);
    $cfg = $setIn($cfg, 'stepper_y', 'position_max', (string)(int)$v['bed_y']);
    $cfg = $setIn($cfg, 'stepper_z', 'position_max', (string)(int)$v['z_max']);
    $cfg = $setIn($cfg, 'printer', 'max_velocity', (string)(int)$v['kl_velocity']);
    $cfg = $setIn($cfg, 'printer', 'max_accel', (string)(int)$v['kl_accel']);
    foreach (['stepper_x' => 'kl_cur_x', 'stepper_y' => 'kl_cur_y', 'stepper_z' => 'kl_cur_z', 'extruder' => 'kl_cur_e'] as $sec => $key) {
        $cfg = $setIn($cfg, 'tmc2209 ' . $sec, 'run_current', $v[$key]);
    }
    return "# Generated by HotFetched " . HF_VERSION . " from the board reference config\n" . $cfg;
}

/** Build the Klipper .config seed for `make olddefconfig`. */
function klipper_config_seed(array $board, array $variant): ?string
{
    $seed = $board['klipper']['config_seed'] ?? null;
    $mach = $variant['klipper_mach'] ?? ($board['klipper']['mach'] ?? null);
    if (!is_array($seed) || !is_string($mach) || $mach === '') {
        return null;
    }
    $lines = array_map(fn ($l) => str_replace('{MACH}', $mach, $l), $seed);
    return implode("\n", $lines) . "\n";
}

// Validate the shipped board catalog once per process. Problems are logged to
// the container log (docker logs HotFetched), never fatal — a malformed board
// should announce itself, not silently produce a broken config.
boards_self_check_once();
