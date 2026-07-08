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
