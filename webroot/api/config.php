<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'Invalid JSON body'], 400);
}

csrf_verify($body['csrf'] ?? null);

$action = (string)($body['action'] ?? '');

/** Load project + board + parsed Configuration.h, or bail with a JSON error. */
function load_config_context(array $body): array
{
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id < 1) {
        json_out(['ok' => false, 'error' => 'Invalid project id'], 422);
    }
    $p = project_get($id);
    if ($p === null) {
        json_out(['ok' => false, 'error' => 'Project not found'], 404);
    }
    if ($p['firmware'] !== 'marlin') {
        json_out(['ok' => false, 'error' => 'Configuration editor currently supports Marlin projects (Klipper ships in a later phase)'], 422);
    }
    if ($p['source_state'] !== 'ready') {
        json_out(['ok' => false, 'error' => 'Import a firmware source first'], 409);
    }
    $board = board_def((string)$p['board_id']);
    if ($board === null) {
        json_out(['ok' => false, 'error' => 'Board definition missing'], 500);
    }
    $detect = json_decode((string)$p['source_detect'], true);
    $confRel = $detect['files']['configuration'] ?? null;
    if (!is_string($confRel)) {
        json_out(['ok' => false, 'error' => 'Source detection data missing - re-import the source'], 409);
    }
    // Contain the path inside the project's source dir.
    $confPath = realpath(project_source_dir($id) . '/' . $confRel);
    $rootPath = realpath(project_source_dir($id));
    if ($confPath === false || $rootPath === false || !str_starts_with($confPath, $rootPath . '/')) {
        json_out(['ok' => false, 'error' => 'Configuration.h not found in source tree'], 409);
    }
    $doc = marlin_config_parse($confPath);
    if ($doc === null) {
        json_out(['ok' => false, 'error' => 'Unable to read Configuration.h'], 500);
    }
    return [$p, $board, $doc, $confPath];
}

/**
 * Validate submitted values against field definitions. All fields are
 * required unless their `requires` condition is unmet. Returns
 * [values, errors] with values normalized to strings.
 */
function validate_fields(array $fields, array $input): array
{
    $values = [];
    $errors = [];

    foreach ($fields as $f) {
        $key = $f['key'];

        // Conditional fields: skip when the condition doesn't hold.
        if (isset($f['requires'])) {
            $met = true;
            foreach ($f['requires'] as $rk => $rv) {
                if ((string)($input[$rk] ?? '') !== (string)$rv) {
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
                if (isset($f['min']) && $n < $f['min']) {
                    $errors[$key] = 'Minimum ' . $f['min'];
                } elseif (isset($f['max']) && $n > $f['max']) {
                    $errors[$key] = 'Maximum ' . $f['max'] . ' for this board';
                }
                $values[$key] = (string)$n;
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

switch ($action) {

    case 'get': {
        [$p, $board, $doc, ] = load_config_context($body);

        $fields  = marlin_field_defs($board);
        $current = marlin_current_values($doc);

        // Saved values (from a previous submit) override file-derived ones.
        $stmt = db()->prepare('SELECT field_key, field_value FROM config_values WHERE project_id = ?');
        $stmt->execute([(int)$p['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $current[$row['field_key']] = $row['field_value'];
        }

        json_out(['ok' => true, 'fields' => $fields, 'values' => $current]);
    }

    case 'save': {
        [$p, $board, $doc, $confPath] = load_config_context($body);

        $fields = marlin_field_defs($board);
        $input  = is_array($body['values'] ?? null) ? $body['values'] : [];
        [$values, $errors] = validate_fields($fields, $input);

        if ($errors !== []) {
            json_out(['ok' => false, 'error' => 'Validation failed', 'field_errors' => $errors], 422);
        }

        // Persist values (transactional replace).
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM config_values WHERE project_id = ?')->execute([(int)$p['id']]);
            $ins = $pdo->prepare('INSERT INTO config_values (project_id, field_key, field_value) VALUES (?, ?, ?)');
            foreach ($values as $k => $v) {
                $ins->execute([(int)$p['id'], $k, (string)$v]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[config] save failed: ' . $e->getMessage());
            json_out(['ok' => false, 'error' => 'Database error'], 500);
        }

        // Apply to Configuration.h (surgical line edits).
        $applied = marlin_apply_values($doc, $values, $board);
        if (!marlin_config_write($doc, $confPath)) {
            json_out(['ok' => false, 'error' => 'Could not write Configuration.h'], 500);
        }

        $pdo->prepare("UPDATE projects SET updated_at = datetime('now') WHERE id = ?")->execute([(int)$p['id']]);

        json_out(['ok' => true, 'applied' => $applied]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
