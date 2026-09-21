<?php
declare(strict_types=1);

final class EconomicsService
{
    public static function company(): array
    {
        $row = db()->query('SELECT * FROM company_settings WHERE id=1')->fetch();
        return $row ?: [
            'id' => 1,
            'company_name' => (string) config('app.name', 'JDEV Industry'),
            'address' => null,
            'vat_number' => null,
            'email' => null,
            'phone' => null,
            'logo_path' => null,
            'print_footer' => null,
        ];
    }

    public static function rules(): array
    {
        return db()->query(
            "SELECT r.*, m.name AS machine_name
             FROM pricing_rules r
             LEFT JOIN machines m ON m.id=r.machine_id
             ORDER BY r.active DESC, r.name, r.id"
        )->fetchAll();
    }

    public static function basisLabel(string $basis): string
    {
        return match ($basis) {
            'hour' => 'Ora macchina',
            'cut' => 'Taglio completato',
            'scheme' => 'Schema completato',
            'job' => 'Quota fissa commessa',
            default => $basis,
        };
    }

    public static function metrics(int $jobId, ?string $from = null, ?string $to = null): array
    {
        $where = ['job_id=?'];
        $params = [$jobId];

        if ($from) {
            $where[] = 'event_time >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to) {
            $where[] = 'event_time <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $sql = "SELECT
                    COUNT(*) AS event_count,
                    COALESCE(SUM(elapsed_time),0) AS elapsed_seconds,
                    SUM(CASE WHEN event_type='CUT_COMPLETED' THEN 1 ELSE 0 END) AS cut_count,
                    SUM(CASE WHEN event_type='BIN_COMPLETED' THEN 1 ELSE 0 END) AS scheme_count,
                    COALESCE(SUM(CASE WHEN event_type='BIN_COMPLETED' THEN waste ELSE 0 END),0) AS waste_total,
                    MIN(event_time) AS first_event_at,
                    MAX(event_time) AS last_event_at
                FROM event_logs
                WHERE " . implode(' AND ', $where);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        $materialsSql = "SELECT material, COUNT(*) AS events,
                               COALESCE(SUM(CASE WHEN event_type='CUT_COMPLETED' THEN value_num ELSE 0 END),0) AS worked_value,
                               COALESCE(SUM(CASE WHEN event_type='BIN_COMPLETED' THEN waste ELSE 0 END),0) AS waste_value
                        FROM event_logs
                        WHERE " . implode(' AND ', $where) . "
                          AND material IS NOT NULL AND material <> ''
                        GROUP BY material
                        ORDER BY material";
        $stmt = db()->prepare($materialsSql);
        $stmt->execute($params);

        return [
            'event_count' => (int) ($row['event_count'] ?? 0),
            'elapsed_seconds' => (float) ($row['elapsed_seconds'] ?? 0),
            'hours' => (float) ($row['elapsed_seconds'] ?? 0) / 3600,
            'cut_count' => (int) ($row['cut_count'] ?? 0),
            'scheme_count' => (int) ($row['scheme_count'] ?? 0),
            'waste_total' => (float) ($row['waste_total'] ?? 0),
            'first_event_at' => $row['first_event_at'] ?? null,
            'last_event_at' => $row['last_event_at'] ?? null,
            'materials' => $stmt->fetchAll(),
        ];
    }

    public static function calculate(int $jobId, ?string $from = null, ?string $to = null): array
    {
        $stmt = db()->prepare(
            "SELECT j.*, c.company_name, m.name AS machine_name
             FROM jobs j
             LEFT JOIN clients c ON c.id=j.client_id
             LEFT JOIN machines m ON m.id=j.machine_id
             WHERE j.id=?"
        );
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            throw new RuntimeException('Commessa non trovata.');
        }

        $metrics = self::metrics($jobId, $from, $to);
        $machineId = $job['machine_id'] ? (int) $job['machine_id'] : null;

        $sql = "SELECT * FROM pricing_rules
                WHERE active=1
                  AND (machine_id IS NULL" . ($machineId ? " OR machine_id=?" : "") . ")
                ORDER BY machine_id IS NULL, id";
        $stmt = db()->prepare($sql);
        $stmt->execute($machineId ? [$machineId] : []);
        $rules = $stmt->fetchAll();

        $automatic = [];
        $automaticTotal = 0.0;

        foreach ($rules as $rule) {
            $quantity = self::ruleQuantity($jobId, $rule, $metrics, $from, $to);
            if ($quantity <= 0 && $rule['basis'] !== 'job') {
                continue;
            }

            if ($rule['basis'] === 'job' && $metrics['event_count'] === 0 && ($from || $to)) {
                continue;
            }

            $amount = $quantity * (float) $rule['unit_price'];
            $automaticTotal += $amount;
            $automatic[] = [
                'name' => $rule['name'],
                'basis' => $rule['basis'],
                'basis_label' => self::basisLabel($rule['basis']),
                'material_match' => $rule['material_match'],
                'quantity' => $quantity,
                'unit' => self::basisUnit($rule['basis']),
                'unit_price' => (float) $rule['unit_price'],
                'amount' => $amount,
            ];
        }

        $manualWhere = ['job_id=?'];
        $manualParams = [$jobId];
        if ($from) {
            $manualWhere[] = 'cost_date >= ?';
            $manualParams[] = $from;
        }
        if ($to) {
            $manualWhere[] = 'cost_date <= ?';
            $manualParams[] = $to;
        }

        $stmt = db()->prepare(
            'SELECT * FROM job_cost_items WHERE ' . implode(' AND ', $manualWhere) . ' ORDER BY cost_date, id'
        );
        $stmt->execute($manualParams);
        $manual = $stmt->fetchAll();
        $manualTotal = 0.0;

        foreach ($manual as &$item) {
            $amount = (float) $item['quantity'] * (float) $item['unit_price'];
            if ($item['category'] === 'discount' && $amount > 0) {
                $amount *= -1;
            }
            $item['amount'] = $amount;
            $manualTotal += $amount;
        }
        unset($item);

        return [
            'job' => $job,
            'metrics' => $metrics,
            'automatic_items' => $automatic,
            'manual_items' => $manual,
            'automatic_total' => $automaticTotal,
            'manual_total' => $manualTotal,
            'total' => $automaticTotal + $manualTotal,
            'from' => $from,
            'to' => $to,
        ];
    }

    public static function snapshot(int $jobId): array
    {
        $calculation = self::calculate($jobId);
        $json = json_encode($calculation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        db()->prepare(
            'INSERT INTO job_cost_snapshots
             (job_id, automatic_total, manual_total, total, details_json, calculated_at)
             VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
               automatic_total=VALUES(automatic_total),
               manual_total=VALUES(manual_total),
               total=VALUES(total),
               details_json=VALUES(details_json),
               calculated_at=VALUES(calculated_at)'
        )->execute([
            $jobId,
            round($calculation['automatic_total'], 2),
            round($calculation['manual_total'], 2),
            round($calculation['total'], 2),
            $json ?: '{}',
        ]);

        return $calculation;
    }

    public static function snapshotForJob(int $jobId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM job_cost_snapshots WHERE job_id=?');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function reportJobs(string $from, string $to, int $clientId = 0, int $machineId = 0): array
    {
        $where = ['e.event_time >= ?', 'e.event_time <= ?'];
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];

        if ($clientId > 0) {
            $where[] = 'j.client_id=?';
            $params[] = $clientId;
        }
        if ($machineId > 0) {
            $where[] = 'j.machine_id=?';
            $params[] = $machineId;
        }

        $stmt = db()->prepare(
            "SELECT DISTINCT j.id
             FROM jobs j
             JOIN event_logs e ON e.job_id=j.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY j.id"
        );
        $stmt->execute($params);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));

        $rows = [];
        $totals = [
            'jobs' => 0, 'hours' => 0.0, 'cuts' => 0, 'schemes' => 0,
            'waste' => 0.0, 'automatic' => 0.0, 'manual' => 0.0, 'total' => 0.0,
        ];

        foreach ($ids as $id) {
            $calc = self::calculate($id, $from, $to);
            $rows[] = $calc;
            $totals['jobs']++;
            $totals['hours'] += $calc['metrics']['hours'];
            $totals['cuts'] += $calc['metrics']['cut_count'];
            $totals['schemes'] += $calc['metrics']['scheme_count'];
            $totals['waste'] += $calc['metrics']['waste_total'];
            $totals['automatic'] += $calc['automatic_total'];
            $totals['manual'] += $calc['manual_total'];
            $totals['total'] += $calc['total'];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    private static function ruleQuantity(
        int $jobId,
        array $rule,
        array $metrics,
        ?string $from,
        ?string $to
    ): float {
        $material = trim((string) ($rule['material_match'] ?? ''));
        if ($material === '') {
            return match ($rule['basis']) {
                'hour' => $metrics['hours'],
                'cut' => (float) $metrics['cut_count'],
                'scheme' => (float) $metrics['scheme_count'],
                'job' => 1.0,
                default => 0.0,
            };
        }

        if ($rule['basis'] === 'job') {
            if (!$from && !$to) {
                return 1.0;
            }

            $stmt = db()->prepare('SELECT MIN(event_time) FROM event_logs WHERE job_id=?');
            $stmt->execute([$jobId]);
            $first = $stmt->fetchColumn();
            if (!$first) {
                return 0.0;
            }

            $day = substr((string) $first, 0, 10);
            if ($from && $day < $from) {
                return 0.0;
            }
            if ($to && $day > $to) {
                return 0.0;
            }
            return 1.0;
        }

        $where = ['job_id=?', 'material=?'];
        $params = [$jobId, $material];

        if ($from) {
            $where[] = 'event_time >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to) {
            $where[] = 'event_time <= ?';
            $params[] = $to . ' 23:59:59';
        }

        if ($rule['basis'] === 'hour') {
            $sql = 'SELECT COALESCE(SUM(elapsed_time),0) FROM event_logs WHERE ' . implode(' AND ', $where);
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return (float) $stmt->fetchColumn() / 3600;
        }

        $where[] = $rule['basis'] === 'cut'
            ? "event_type='CUT_COMPLETED'"
            : "event_type='BIN_COMPLETED'";
        $stmt = db()->prepare('SELECT COUNT(*) FROM event_logs WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    private static function basisUnit(string $basis): string
    {
        return match ($basis) {
            'hour' => 'h',
            'cut' => 'tagli',
            'scheme' => 'schemi',
            'job' => 'commessa',
            default => '',
        };
    }
}
