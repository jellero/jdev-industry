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
$job = null;

if (is_string($project['name']) && trim($project['name']) !== '') {
    $projectName = trim($project['name']);
    $projectBase = pathinfo($projectName, PATHINFO_FILENAME);
    $stmt = db()->prepare(
        "SELECT j.code, j.name, j.status, c.company_name
         FROM jobs j
         LEFT JOIN clients c ON c.id=j.client_id
         WHERE j.external_project IN (?, ?) OR j.code IN (?, ?)
         ORDER BY FIELD(j.status, 'in_progress','sent','ready','planned','done','cancelled'), j.id DESC
         LIMIT 1"
    );
    $stmt->execute([$projectName, $projectBase, $projectName, $projectBase]);
    $job = $stmt->fetch() ?: null;
}

$summary = [
    'connected' => $stateJson['Conneted'] ?? $stateJson['Connected'] ?? null,
    'mode' => $stateJson['Mode'] ?? null,
    'comments' => $stateJson['Comments'] ?? null,
    'warnings' => $stateJson['Warnings'] ?? null,
    'errors' => $stateJson['Errors'] ?? null,
    'activity' => isset($stateJson['ActivityA']) && is_array($stateJson['ActivityA']) ? array_values($stateJson['ActivityA']) : [],
    'project_name' => $project['name'],
    'progress' => $project['progress'],
    'job_code' => $job['code'] ?? null,
    'job_name' => $job['name'] ?? null,
    'client_name' => $job['company_name'] ?? null,
    'job_status' => $job['status'] ?? null,
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
