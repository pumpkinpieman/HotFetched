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

switch ($action) {

    case 'create': {
        $name        = trim((string)($body['name'] ?? ''));
        $firmware    = (string)($body['firmware'] ?? '');
        $boardId     = (string)($body['board_id'] ?? '');
        $mcuVariant  = (string)($body['mcu_variant'] ?? '');

        if (!valid_project_name($name)) {
            json_out(['ok' => false, 'error' => 'Invalid project name (1-64 chars: letters, digits, space, . _ -)'], 422);
        }
        if (!in_array($firmware, ['marlin', 'klipper'], true)) {
            json_out(['ok' => false, 'error' => 'Firmware must be marlin or klipper'], 422);
        }
        $board = board_def($boardId);
        if ($board === null) {
            json_out(['ok' => false, 'error' => 'Unknown board'], 422);
        }
        if (board_mcu_variant($board, $mcuVariant) === null) {
            json_out(['ok' => false, 'error' => 'Unknown MCU variant for this board'], 422);
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO projects (name, firmware, board_id, mcu_variant) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$name, $firmware, $boardId, $mcuVariant]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                json_out(['ok' => false, 'error' => 'A project with that name already exists'], 409);
            }
            error_log('[projects] create failed: ' . $e->getMessage());
            json_out(['ok' => false, 'error' => 'Database error'], 500);
        }

        $id = (int)db()->lastInsertId();
        if (!is_dir(project_dir($id))) {
            @mkdir(project_dir($id), 0775, true);
        }
        json_out(['ok' => true, 'id' => $id]);
    }

    case 'delete': {
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || $id < 1) {
            json_out(['ok' => false, 'error' => 'Invalid project id'], 422);
        }
        if (project_get($id) === null) {
            json_out(['ok' => false, 'error' => 'Project not found'], 404);
        }
        $stmt = db()->prepare('DELETE FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        project_dir_delete($id);
        json_out(['ok' => true]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
