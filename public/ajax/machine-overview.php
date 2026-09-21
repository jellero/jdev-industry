<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$machine = $id ? machineById((int) $id) : null;

if (!$machine) {
    http_response_code(404);
    echo json_encode(['error' => 'Macchina non trovata.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$api = machineApi($machine);
$state = $api->get('/state');
$projects = $api->get('/project/last10');

if ($state['ok'] || $projects['ok']) {
    $stmt = db()->prepare('UPDATE machines SET last_seen_at = NOW() WHERE id = ?');
    $stmt->execute([$machine['id']]);
}

$stateJson = is_array($state['json']) ? $state['json'] : [];
$project = projectSummary($projects['json']);

$summary = [
    'connected' => $stateJson['Conneted'] ?? $stateJson['Connected'] ?? null,
    'mode' => $stateJson['Mode'] ?? null,
    'comments' => $stateJson['Comments'] ?? null,
    'warnings' => $stateJson['Warnings'] ?? null,
    'errors' => $stateJson['Errors'] ?? null,
    'activity' => isset($stateJson['ActivityA']) && is_array($stateJson['ActivityA']) ? array_values($stateJson['ActivityA']) : [],
    'project_name' => $project['name'],
    'progress' => $project['progress'],
];

$httpOk = $state['ok'] || $projects['ok'];
if (!$httpOk) {
    http_response_code(502);
}

echo json_encode([
    'ok' => $httpOk,
    'machine' => ['id' => (int) $machine['id'], 'name' => $machine['name']],
    'summary' => $summary,
    'state' => $state,
    'projects' => $projects,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
