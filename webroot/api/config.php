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

    if ($p['source_state'] !== 'ready') {
        json_out(['ok' => false, 'error' => 'Import a firmware source first'], 409);
    }
    $board = board_def((string)$p['board_id']);
    if ($board === null) {
        json_out(['ok' => false, 'error' => 'Board definition missing'], 500);
    }
    $detect = json_decode((string)$p['source_detect'], true);

    if ($p['firmware'] === 'klipper') {
        // Klipper: values are stored and applied to printer.cfg at build time.
        return [$p, $board, null, null, null];
    }

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
        [$p, $board, $doc, , $advPath] = load_config_context($body);

        if ($p['firmware'] === 'klipper') {
            $detect = json_decode((string)$p['source_detect'], true);
            $refRel = $board['klipper']['reference_config'] ?? '';
            $root   = ($detect['root'] ?? '') !== '' ? '/' . $detect['root'] : '';
            $refTxt = (string)@file_get_contents(project_source_dir((int)$p['id']) . $root . '/config/' . $refRel);
            $fields  = klipper_field_defs($board);
            $current = klipper_current_values($refTxt);
            $stmt = db()->prepare('SELECT field_key, field_value FROM config_values WHERE project_id = ?');
            $stmt->execute([(int)$p['id']]);
            foreach ($stmt->fetchAll() as $row) {
                $current[$row['field_key']] = $row['field_value'];
            }
            json_out(['ok' => true, 'fields' => $fields, 'values' => $current, 'meta' => ['mono_screens' => [], 'event_presets' => HF_EVENT_PRESETS]]);
        }

        $adv = marlin_config_parse($advPath);
        if ($adv === null) {
            json_out(['ok' => false, 'error' => 'Unable to read Configuration_adv.h'], 500);
        }
        $fields  = array_merge(marlin_field_defs($board), marlin_field_defs_motion($board),
                               marlin_field_defs_adv($board), marlin_field_defs_tier2($board),
                               marlin_field_defs_leveling($board),
                               marlin_field_defs_extended($board));
        $current = array_merge(marlin_current_values($doc), marlin_current_values_tier1($doc, $adv),
                               marlin_current_values_tier2($doc, $adv),
                               marlin_current_values_leveling($doc),
                               marlin_current_values_extended($doc, $board));

        // Saved values (from a previous submit) override file-derived ones.
        $stmt = db()->prepare('SELECT field_key, field_value FROM config_values WHERE project_id = ?');
        $stmt->execute([(int)$p['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $current[$row['field_key']] = $row['field_value'];
        }

        $mono = [];
        $extFw = [];
        foreach (($board['marlin']['screens'] ?? []) as $s) {
            if ($s['type'] === 'mono128x64') {
                $mono[] = $s['id'];
            } elseif ($s['type'] === 'serial_tft') {
                $extFw[] = $s['id'];
            }
        }
        json_out(['ok' => true, 'fields' => $fields, 'values' => $current,
                  'meta' => ['mono_screens' => $mono, 'external_fw_screens' => $extFw,
                             'event_presets' => HF_EVENT_PRESETS]]);
    }

    case 'save': {
        [$p, $board, $doc, $confPath, $advPath] = load_config_context($body);

        if ($p['firmware'] === 'klipper') {
            $fields = klipper_field_defs($board);
            $input  = is_array($body['values'] ?? null) ? $body['values'] : [];
            [$values, $errors] = hf_validate_fields($fields, $input);
            if ($errors !== []) {
                json_out(['ok' => false, 'error' => 'Validation failed', 'field_errors' => $errors], 422);
            }
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM config_values WHERE project_id = ?')->execute([(int)$p['id']]);
            $ins = $pdo->prepare('INSERT INTO config_values (project_id, field_key, field_value) VALUES (?, ?, ?)');
            foreach ($values as $k => $v) {
                $ins->execute([(int)$p['id'], $k, (string)$v]);
            }
            $pdo->commit();
            $pdo->prepare("UPDATE projects SET updated_at = datetime('now') WHERE id = ?")->execute([(int)$p['id']]);
            json_out(['ok' => true, 'applied' => array_keys($values)]);
        }

        $adv = marlin_config_parse($advPath);
        if ($adv === null) {
            json_out(['ok' => false, 'error' => 'Unable to read Configuration_adv.h'], 500);
        }
        $fields = array_merge(marlin_field_defs($board), marlin_field_defs_motion($board),
                              marlin_field_defs_adv($board), marlin_field_defs_tier2($board),
                              marlin_field_defs_leveling($board),
                              marlin_field_defs_extended($board));
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

        // Apply to both configuration files (surgical line edits).
        $applied = array_merge(
            marlin_apply_values($doc, $values, $board),
            marlin_apply_values_motion($doc, $values),
            marlin_apply_values_tier2_conf($doc, $values),
            marlin_apply_values_leveling($doc, $values),
            marlin_apply_values_extended($doc, $values, $board)
        );
        $appliedAdv = array_merge(
            marlin_apply_values_adv($adv, $values),
            marlin_apply_values_tier2_adv($adv, $values)
        );
        if (!marlin_config_write($doc, $confPath)) {
            json_out(['ok' => false, 'error' => 'Could not write Configuration.h'], 500);
        }
        if ($appliedAdv !== [] && !marlin_config_write($adv, $advPath)) {
            json_out(['ok' => false, 'error' => 'Could not write Configuration_adv.h'], 500);
        }
        $applied = array_merge($applied, $appliedAdv);

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

        // Live-preview options (from multipart fields).
        $target  = (($_POST['target'] ?? 'boot') === 'status') ? 'status' : 'boot';
        $opts = [
            'target'    => $target,
            'threshold' => (int)($_POST['threshold'] ?? 128),
            'invert'    => (string)($_POST['invert'] ?? '0') === '1',
            'dither'    => (string)($_POST['dither'] ?? '1') === '1',
        ];
        // Preview-only requests don't write files or touch config.
        $previewOnly = (string)($_POST['preview_only'] ?? '0') === '1';

        $result = bootscreen_generate((string)$file['tmp_name'], $opts);
        if (is_string($result)) {
            json_out(['ok' => false, 'error' => $result], 422);
        }

        if ($previewOnly) {
            json_out(['ok' => true, 'preview_b64' => $result['preview_b64'], 'target' => $target]);
        }

        $adv = marlin_config_parse($advPath);
        if ($target === 'status') {
            // Write Marlin/_Statusscreen.h and enable CUSTOM_STATUS_SCREEN_IMAGE.
            $ssPath = dirname($advPath) . '/_Statusscreen.h';
            if (@file_put_contents($ssPath, $result['header']) === false) {
                json_out(['ok' => false, 'error' => 'Could not write _Statusscreen.h'], 500);
            }
            if ($adv !== null && marlin_config_set($adv, 'CUSTOM_STATUS_SCREEN_IMAGE', null, true)) {
                marlin_config_write($adv, $advPath);
            }
        } else {
            // Write Marlin/_Bootscreen.h and enable SHOW_CUSTOM_BOOTSCREEN.
            $bsPath = dirname($advPath) . '/_Bootscreen.h';
            if (@file_put_contents($bsPath, $result['header']) === false) {
                json_out(['ok' => false, 'error' => 'Could not write _Bootscreen.h'], 500);
            }
            if ($adv !== null && marlin_config_set($adv, 'SHOW_CUSTOM_BOOTSCREEN', null, true)) {
                marlin_config_write($adv, $advPath);
            }
        }

        json_out(['ok' => true, 'preview_b64' => $result['preview_b64'], 'target' => $target]);
    }

    case 'tftimage': {
        [$p, $board, , , ] = load_config_context($body);

        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_out(['ok' => false, 'error' => 'No image uploaded'], 422);
        }
        if ((int)$file['size'] > 8 * 1024 * 1024) {
            json_out(['ok' => false, 'error' => 'Image too large (8 MB max)'], 422);
        }
        $model = (string)($_POST['tft_model'] ?? 'btt_tft70');
        $previewOnly = (string)($_POST['preview_only'] ?? '0') === '1';

        $result = tft_image_generate((string)$file['tmp_name'], $model);
        if (is_string($result)) {
            json_out(['ok' => false, 'error' => $result], 422);
        }

        if ($previewOnly) {
            json_out(['ok' => true, 'preview_b64' => $result['preview_b64'],
                      'spec' => $result['spec'], 'model' => $model]);
        }

        // Serve the BMP as a download (this file goes on the TFT's SD card).
        $dl = 'booting.bmp';
        header('Content-Type: image/bmp');
        header('Content-Disposition: attachment; filename="' . $dl . '"');
        header('Content-Length: ' . (string)strlen($result['bmp']));
        echo $result['bmp'];
        exit;
    }

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
