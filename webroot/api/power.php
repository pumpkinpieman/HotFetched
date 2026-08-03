<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/power_analysis.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'Invalid JSON body'], 400);
}
csrf_verify($body['csrf'] ?? null);

$projectId = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
if ($projectId === false || $projectId === null || $projectId < 1 || project_get((int)$projectId) === null) {
    json_out(['ok' => false, 'error' => 'Invalid project id'], 422);
}

function hf_power_load(int $projectId): array
{
    $stmt = db()->prepare("SELECT field_value FROM config_values WHERE project_id=? AND field_key='__power_analysis_json'");
    $stmt->execute([$projectId]);
    $raw = $stmt->fetchColumn();
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? hf_power_clean($decoded) : hf_power_default();
}

function hf_power_save(int $projectId, array $power): void
{
    $encoded = json_encode($power, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO config_values(project_id,field_key,field_value) VALUES(?,?,?)\n"
            . "ON CONFLICT(project_id,field_key) DO UPDATE SET field_value=excluded.field_value, updated_at=datetime('now')"
        )->execute([$projectId, '__power_analysis_json', $encoded]);
        $pdo->prepare("UPDATE projects SET updated_at=datetime('now') WHERE id=?")->execute([$projectId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

$action = (string)($body['action'] ?? 'get');
if ($action === 'get') {
    $power = hf_power_load((int)$projectId);
    json_out([
        'ok' => true,
        'power' => $power,
        'analysis' => ($power['psus'] || $power['loads']) ? hf_power_analyze($power) : null,
    ]);
}

if ($action === 'analyze') {
    $rawPower = is_array($body['power'] ?? null) ? $body['power'] : [];
    if (count((array)($rawPower['psus'] ?? [])) > 12) {
        json_out(['ok' => false, 'error' => 'Power analysis supports up to 12 power supplies. Remove extra PSU rows before saving.'], 422);
    }
    if (count((array)($rawPower['loads'] ?? [])) > 100) {
        json_out(['ok' => false, 'error' => 'Power analysis supports up to 100 electrical loads. Remove extra load rows before saving.'], 422);
    }
    $power = hf_power_clean($rawPower);
    if (!$power['psus']) {
        json_out(['ok' => false, 'error' => 'Add at least one power supply.'], 422);
    }
    $completePsus = array_values(array_filter(
        $power['psus'],
        static fn(array $psu): bool => (float)$psu['output_voltage'] > 0
            && (float)$psu['max_current'] > 0
            && (float)$psu['rated_power'] > 0
    ));
    if (!$completePsus) {
        json_out(['ok' => false, 'error' => 'Complete output voltage, maximum current, and rated power for at least one PSU.'], 422);
    }

    try {
        hf_power_save((int)$projectId, $power);
    } catch (Throwable $error) {
        error_log('Power analysis save failed: ' . $error->getMessage());
        json_out(['ok' => false, 'error' => 'Unable to save power analysis'], 500);
    }
    json_out(['ok' => true, 'power' => $power, 'analysis' => hf_power_analyze($power)]);
}

json_out(['ok' => false, 'error' => 'Unknown action'], 400);
