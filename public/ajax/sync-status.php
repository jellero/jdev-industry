<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$refresh = filter_input(INPUT_GET, 'refresh', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$machine = $id ? machineById((int) $id) : null;

if (!$machine) {
    http_response_code(404);
    echo json_encode(['error' => 'Macchina non trovata.'], JSON_UNESCAPED_UNICODE);
    exit;
}

SyncService::ensureTasks((int) $machine['id']);

if ($refresh !== false) {
    SyncService::run($machine, 'version');
    SyncService::run($machine, 'state');
    $machine = machineById((int) $machine['id']) ?? $machine;
}

echo json_encode([
    'ok' => true,
    'machine' => [
        'id' => (int) $machine['id'],
        'name' => $machine['name'],
        'base_url' => $machine['base_url'],
        'last_version' => $machine['last_version'],
        'last_seen_at' => $machine['last_seen_at'],
    ],
    'statuses' => array_map(static function (array $row): array {
        return [
            'operation' => $row['operation'],
            'label' => SyncService::operationLabel($row['operation']),
            'last_status' => $row['last_status'],
            'last_attempt_at' => $row['last_attempt_at'],
            'last_success_at' => $row['last_success_at'],
            'last_error_at' => $row['last_error_at'],
            'http_status' => $row['last_http_status'],
            'duration_ms' => $row['last_duration_ms'],
            'items' => (int) $row['last_items'],
            'message' => $row['last_message'],
        ];
    }, SyncService::statuses((int) $machine['id'])),
    'tasks' => array_map(static function (array $row): array {
        return [
            'operation' => $row['operation'],
            'label' => SyncService::operationLabel($row['operation']),
            'enabled' => (bool) $row['enabled'],
            'interval_minutes' => (int) $row['interval_minutes'],
            'last_run_at' => $row['last_run_at'],
            'next_run_at' => $row['next_run_at'],
            'last_status' => $row['last_status'],
            'last_message' => $row['last_message'],
        ];
    }, SyncService::tasks((int) $machine['id'])),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
