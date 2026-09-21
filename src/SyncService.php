<?php
declare(strict_types=1);

final class SyncService
{
    public const DEFAULT_TASKS = [
        'state' => ['enabled' => 1, 'interval' => 1],
        'log' => ['enabled' => 1, 'interval' => 10],
        'newlog' => ['enabled' => 0, 'interval' => 2],
        'warehouse' => ['enabled' => 1, 'interval' => 60],
        'recovery' => ['enabled' => 1, 'interval' => 60],
        'version' => ['enabled' => 1, 'interval' => 1440],
    ];

    public static function ensureTasks(int $machineId): void
    {
        $stmt = db()->prepare(
            'INSERT IGNORE INTO scheduled_tasks (machine_id, operation, enabled, interval_minutes, next_run_at)
             VALUES (?, ?, ?, ?, NOW())'
        );

        foreach (self::DEFAULT_TASKS as $operation => $defaults) {
            $stmt->execute([$machineId, $operation, $defaults['enabled'], $defaults['interval']]);
        }
    }

    public static function run(array $machine, string $operation): array
    {
        $operation = strtolower(trim($operation));
        $api = machineApi($machine);

        $path = match ($operation) {
            'version' => '/version',
            'state' => '/state',
            'log' => '/log',
            'newlog' => '/newlog',
            'warehouse' => '/warehouse',
            'recovery' => '/recovery',
            default => null,
        };

        if ($path === null) {
            $result = [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'json' => null,
                'error' => 'Operazione automatica non supportata.',
                'duration_ms' => 0,
                'url' => '',
                'content_type' => '',
            ];
            self::recordStatus((int) $machine['id'], $operation, $result, 0, $result['error']);
            return $result + ['items' => 0, 'message' => $result['error']];
        }

        $result = $api->get($path);
        $items = 0;
        $message = $result['ok'] ? 'Sincronizzazione completata.' : ($result['error'] ?: ('HTTP ' . $result['status']));

        if ($result['ok']) {
            db()->prepare('UPDATE machines SET last_seen_at=NOW() WHERE id=?')->execute([$machine['id']]);

            if ($operation === 'version') {
                $version = trim((string) $result['body']);
                db()->prepare('UPDATE machines SET last_version=? WHERE id=?')->execute([$version ?: null, $machine['id']]);
                $message = 'Versione: ' . ($version ?: 'risposta vuota');
            } elseif (in_array($operation, ['log', 'newlog'], true)) {
                if (is_array($result['json'])) {
                    $archive = self::archiveEvents($machine, normalizeList($result['json']));
                    $items = $archive['inserted'];
                    $message = sprintf(
                        '%d eventi letti, %d nuovi archiviati, %d collegati a commesse.',
                        $archive['read'],
                        $archive['inserted'],
                        $archive['linked']
                    );
                } else {
                    $message = 'Risposta ricevuta ma non interpretabile come JSON.';
                }
            } elseif (in_array($operation, ['warehouse', 'recovery'], true)) {
                $items = is_array($result['json']) ? count(normalizeList($result['json'])) : 0;
                $message = $items . ' elementi ricevuti.';
            }
        }

        self::recordStatus((int) $machine['id'], $operation, $result, $items, $message);

        return $result + ['items' => $items, 'message' => $message];
    }

    public static function archiveEvents(array $machine, array $events, ?string $forcedSourceDay = null): array
    {
        $insert = db()->prepare(
            'INSERT IGNORE INTO event_logs
            (machine_id, job_id, event_key, event_time, event_type, message, project, reference, material, value_num, number_num, elapsed_time, waste, source_day, payload_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $findJob = db()->prepare(
            'SELECT id FROM jobs
             WHERE machine_id IN (?, ?) AND (external_project IN (?, ?) OR code IN (?, ?))
             ORDER BY id DESC LIMIT 1'
        );
        $markStarted = db()->prepare(
            "UPDATE jobs SET status='in_progress'
             WHERE id=? AND status IN ('planned','ready','sent')"
        );

        $inserted = 0;
        $linked = 0;
        $read = 0;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $read++;

            $project = trim((string) ($event['Project'] ?? ''));
            $projectBase = $project !== '' ? pathinfo($project, PATHINFO_FILENAME) : '';
            $jobId = null;

            if ($project !== '') {
                $findJob->execute([
                    $machine['id'],
                    null,
                    $project,
                    $projectBase,
                    $project,
                    $projectBase,
                ]);
                $jobId = $findJob->fetchColumn() ?: null;
                if ($jobId) {
                    $linked++;
                }
            }

            $eventTime = eventDateTime($event['DateTime'] ?? null);
            $sourceDay = $forcedSourceDay;
            if ($sourceDay === null && $eventTime !== null) {
                $sourceDay = substr($eventTime, 0, 10);
            }
            $sourceDay ??= date('Y-m-d');

            $guid = trim((string) ($event['Guid'] ?? ''));
            $payload = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $eventKey = hash('sha256', $guid !== '' ? ('guid:' . $guid) : (string) $payload);

            $insert->execute([
                $machine['id'],
                $jobId,
                $eventKey,
                $eventTime,
                isset($event['Type']) ? (string) $event['Type'] : null,
                isset($event['Message']) ? (string) $event['Message'] : null,
                $project !== '' ? $project : null,
                isset($event['Reference']) ? (string) $event['Reference'] : null,
                isset($event['Material']) ? (string) $event['Material'] : null,
                is_numeric($event['Value'] ?? null) ? $event['Value'] : null,
                is_numeric($event['Number'] ?? null) ? (int) $event['Number'] : null,
                is_numeric($event['ElapsedTime'] ?? null) ? $event['ElapsedTime'] : null,
                is_numeric($event['Waste'] ?? null) ? $event['Waste'] : null,
                $sourceDay,
                $payload ?: '{}',
            ]);

            if ($insert->rowCount() > 0) {
                $inserted++;
                $type = strtoupper((string) ($event['Type'] ?? ''));
                if ($jobId && in_array($type, ['CUT_COMPLETED', 'BIN_COMPLETED'], true)) {
                    $markStarted->execute([$jobId]);
                }
            }
        }

        return ['read' => $read, 'inserted' => $inserted, 'linked' => $linked];
    }

    public static function recordStatus(
        int $machineId,
        string $operation,
        array $result,
        int $items = 0,
        ?string $message = null
    ): void {
        $ok = (bool) ($result['ok'] ?? false);
        $payload = $result['json'] ?? null;
        if ($payload === null && ($result['body'] ?? '') !== '') {
            $payload = ['raw' => (string) $result['body']];
        }
        $payloadJson = $payload !== null
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $sql = 'INSERT INTO machine_sync_status
            (machine_id, operation, last_attempt_at, last_success_at, last_error_at, last_status, last_http_status, last_duration_ms, last_items, last_message, last_payload_json)
            VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                last_attempt_at=VALUES(last_attempt_at),
                last_success_at=IF(VALUES(last_status)="ok", VALUES(last_success_at), last_success_at),
                last_error_at=IF(VALUES(last_status)="error", VALUES(last_error_at), last_error_at),
                last_status=VALUES(last_status),
                last_http_status=VALUES(last_http_status),
                last_duration_ms=VALUES(last_duration_ms),
                last_items=VALUES(last_items),
                last_message=VALUES(last_message),
                last_payload_json=VALUES(last_payload_json)';

        $now = date('Y-m-d H:i:s');
        db()->prepare($sql)->execute([
            $machineId,
            $operation,
            $ok ? $now : null,
            $ok ? null : $now,
            $ok ? 'ok' : 'error',
            (int) ($result['status'] ?? 0) ?: null,
            (int) ($result['duration_ms'] ?? 0),
            max(0, $items),
            mb_substr((string) ($message ?? ($result['error'] ?? '')), 0, 500),
            $payloadJson,
        ]);
    }

    public static function statuses(int $machineId): array
    {
        $stmt = db()->prepare('SELECT * FROM machine_sync_status WHERE machine_id=? ORDER BY operation');
        $stmt->execute([$machineId]);
        return $stmt->fetchAll();
    }

    public static function tasks(int $machineId): array
    {
        self::ensureTasks($machineId);
        $stmt = db()->prepare('SELECT * FROM scheduled_tasks WHERE machine_id=? ORDER BY operation');
        $stmt->execute([$machineId]);
        return $stmt->fetchAll();
    }

    public static function operationLabel(string $operation): string
    {
        return match ($operation) {
            'state' => 'Stato macchina',
            'log' => 'Log corrente',
            'newlog' => 'Nuovi eventi',
            'warehouse' => 'Magazzino',
            'recovery' => 'Residui',
            'version' => 'Versione supervisore',
            default => $operation,
        };
    }
}
