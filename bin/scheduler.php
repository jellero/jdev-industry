<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Questo script può essere eseguito solo da CLI.\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

$lock = (int) db()->query("SELECT GET_LOCK('jdev_industry_scheduler', 0)")->fetchColumn();
if ($lock !== 1) {
    fwrite(STDOUT, "[" . date('c') . "] scheduler già in esecuzione; uscita.\n");
    exit(0);
}

try {
    foreach (machines(true) as $machine) {
        SyncService::ensureTasks((int) $machine['id']);
    }

    $sql = "SELECT t.*, m.name, m.base_url, m.api_timeout_seconds, m.poll_seconds, m.active,
                   m.last_version, m.last_seen_at, m.notes
            FROM scheduled_tasks t
            JOIN machines m ON m.id=t.machine_id
            WHERE t.enabled=1
              AND m.active=1
              AND (t.next_run_at IS NULL OR t.next_run_at <= NOW())
            ORDER BY COALESCE(t.next_run_at, '1970-01-01 00:00:00'), t.id";
    $tasks = db()->query($sql)->fetchAll();

    $update = db()->prepare(
        'UPDATE scheduled_tasks
         SET last_run_at=?, next_run_at=?, last_status=?, last_message=?
         WHERE id=?'
    );

    foreach ($tasks as $task) {
        $started = date('Y-m-d H:i:s');
        $next = date('Y-m-d H:i:s', time() + max(1, (int) $task['interval_minutes']) * 60);

        try {
            $result = SyncService::run($task, (string) $task['operation']);
            $status = $result['ok'] ? 'ok' : 'error';
            $message = (string) ($result['message'] ?? $result['error'] ?? '');
        } catch (Throwable $e) {
            $status = 'error';
            $message = $e->getMessage();
        }

        $update->execute([
            $started,
            $next,
            $status,
            mb_substr($message, 0, 500),
            $task['id'],
        ]);

        fwrite(
            STDOUT,
            sprintf(
                "[%s] %s / %s: %s - %s\n",
                date('c'),
                $task['name'],
                $task['operation'],
                strtoupper($status),
                $message
            )
        );
    }

    if (!$tasks) {
        fwrite(STDOUT, "[" . date('c') . "] nessuna operazione in scadenza.\n");
    }
} finally {
    db()->query("SELECT RELEASE_LOCK('jdev_industry_scheduler')");
}
