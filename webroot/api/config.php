<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}

$isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
if ($isMultipart) {
    $body = $_POST;
} else {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        json_out(['ok' => false, 'error' => 'Invalid JSON body'], 400);
    }
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
    $advRel  = $detect['files']['configuration_adv'] ?? null;
    if (!is_string($confRel) || !is_string($advRel)) {
        json_out(['ok' => false, 'error' => 'Source detection data missing - re-import the source'], 409);
    }
    // Contain paths inside the project's source dir.
    $rootPath = realpath(project_source_dir($id));
    $confPath = realpath(project_source_dir($id) . '/' . $confRel);
    $advPath  = realpath(project_source_dir($id) . '/' . $advRel);
    if ($confPath === false || $advPath === false || $rootPath === false
        || !str_starts_with($confPath, $rootPath . '/') || !str_starts_with($advPath, $rootPath . '/')) {
        json_out(['ok' => false, 'error' => 'Configuration files not found in source tree'], 409);
    }
    $doc = marlin_config_parse($confPath);
    if ($doc === null) {
        json_out(['ok' => false, 'error' => 'Unable to read Configuration.h'], 500);
    }
    return [$p, $board, $doc, $confPath, $advPath];
}

switch ($action) {

    case 'get': {
        [$p, $board, $doc, , ] = load_config_context($body);

        $fields  = array_merge(marlin_field_defs($board), marlin_field_defs_extended($board));
        $current = array_merge(marlin_current_values($doc), marlin_current_values_extended($doc, $board));

        // Saved values (from a previous submit) override file-derived ones.
        $stmt = db()->prepare('SELECT field_key, field_value FROM config_values WHERE project_id = ?');
        $stmt->execute([(int)$p['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $current[$row['field_key']] = $row['field_value'];
        }

        $mono = [];
        foreach (($board['marlin']['screens'] ?? []) as $s) {
            if ($s['type'] === 'mono128x64') {
                $mono[] = $s['id'];
            }
        }
        json_out(['ok' => true, 'fields' => $fields, 'values' => $current,
                  'meta' => ['mono_screens' => $mono, 'event_presets' => HF_EVENT_PRESETS]]);
    }

    case 'save': {
        [$p, $board, $doc, $confPath, ] = load_config_context($body);

        $fields = array_merge(marlin_field_defs($board), marlin_field_defs_extended($board));
        $input  = is_array($body['values'] ?? null) ? $body['values'] : [];
        [$values, $errors] = hf_validate_fields($fields, $input);

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
        $applied = array_merge(
            marlin_apply_values($doc, $values, $board),
            marlin_apply_values_extended($doc, $values, $board)
        );
        if (!marlin_config_write($doc, $confPath)) {
            json_out(['ok' => false, 'error' => 'Could not write Configuration.h'], 500);
        }

        $pdo->prepare("UPDATE projects SET updated_at = datetime('now') WHERE id = ?")->execute([(int)$p['id']]);

        json_out(['ok' => true, 'applied' => $applied]);
    }

    case 'bootscreen': {
        [$p, , , , $advPath] = load_config_context($body);

        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_out(['ok' => false, 'error' => 'No image uploaded'], 422);
        }
        if ((int)$file['size'] > 8 * 1024 * 1024) {
            json_out(['ok' => false, 'error' => 'Image too large (8 MB max)'], 422);
        }

        $result = bootscreen_generate((string)$file['tmp_name']);
        if (is_string($result)) {
            json_out(['ok' => false, 'error' => $result], 422);
        }

        // Write Marlin/_Bootscreen.h next to Configuration_adv.h.
        $bsPath = dirname($advPath) . '/_Bootscreen.h';
        if (@file_put_contents($bsPath, $result['header']) === false) {
            json_out(['ok' => false, 'error' => 'Could not write _Bootscreen.h'], 500);
        }

        // Enable SHOW_CUSTOM_BOOTSCREEN in Configuration_adv.h.
        $adv = marlin_config_parse($advPath);
        if ($adv !== null && marlin_config_set($adv, 'SHOW_CUSTOM_BOOTSCREEN', null, true)) {
            marlin_config_write($adv, $advPath);
        }

        json_out(['ok' => true, 'preview_b64' => $result['preview_b64']]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
