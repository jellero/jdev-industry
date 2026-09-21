<?php
declare(strict_types=1);

final class ApiTestService
{
    public const ENDPOINTS = [
        'version' => ['GET', '/version', 'Versione supervisore'],
        'state' => ['GET', '/state', 'Stato corrente'],
        'state_date' => ['GET', '/state/{YYYYMMDD}', 'Stato storico per data'],
        'projects' => ['GET', '/project/last10', 'Ultimi 10 progetti'],
        'log' => ['GET', '/log', 'Log corrente'],
        'log_date' => ['GET', '/logDate/{YYYYMMDD}', 'Log per data'],
        'newlog' => ['GET', '/newlog', 'Nuovi log non ancora letti'],
        'warehouse' => ['GET', '/warehouse', 'Magazzino macchina'],
        'recovery' => ['GET', '/recovery', 'Residui recuperabili'],
        'import_btl' => ['UPLOAD', '/importBtl', 'Importa BTL'],
        'convert_btl' => ['UPLOAD', '/convertBtl', 'Importa BTL con conversione'],
        'import_ts7' => ['UPLOAD', '/importTs7', 'Importa TS7'],
        'import_warehouse' => ['UPLOAD', '/importWarehouse', 'Importa magazzino JSON'],
    ];

    public static function runSuite(
        array $machine,
        string $date,
        bool $includeNewLog,
        bool $includeUploads,
        array $files
    ): array {
        $date = self::normalizeDate($date);
        $tests = [];

        foreach (['version', 'state', 'projects', 'log', 'log_date', 'state_date', 'warehouse', 'recovery'] as $key) {
            $tests[] = self::runSingle($machine, $key, $date, null);
        }

        $tests[] = $includeNewLog
            ? self::runSingle($machine, 'newlog', $date, null)
            : self::skipped('newlog', 'Escluso per sicurezza: abilitalo solo se questo gestionale è l’unico consumatore di /newlog.');

        $uploadMap = [
            'import_btl' => $files['btl_file'] ?? null,
            'convert_btl' => $files['btl_file'] ?? null,
            'import_ts7' => $files['ts7_file'] ?? null,
            'import_warehouse' => $files['warehouse_file'] ?? null,
        ];

        foreach ($uploadMap as $key => $file) {
            if (!$includeUploads) {
                $tests[] = self::skipped($key, 'Test upload non richiesto.');
                continue;
            }

            if (!self::isUploadedFile($file)) {
                $tests[] = self::skipped($key, 'File di test non fornito.');
                continue;
            }

            $tests[] = self::runSingle($machine, $key, $date, $file);
        }

        return self::report($machine, $date, $tests);
    }

    public static function runSingle(
        array $machine,
        string $key,
        string $date,
        ?array $file
    ): array {
        if (!isset(self::ENDPOINTS[$key])) {
            return self::failedUnknown($key);
        }

        [$method, $path, $label] = self::ENDPOINTS[$key];
        $date = self::normalizeDate($date);
        $path = str_replace('{YYYYMMDD}', str_replace('-', '', $date), $path);
        $api = machineApi($machine);

        if ($method === 'UPLOAD') {
            if (!self::isUploadedFile($file)) {
                return self::skipped($key, 'Per eseguire questo test serve un file valido.');
            }

            $result = $api->upload(
                $path,
                (string) $file['tmp_name'],
                (string) $file['name']
            );
        } else {
            $result = $api->get($path);
        }

        if ($key === 'version' && $result['ok']) {
            db()->prepare('UPDATE machines SET last_version=?, last_seen_at=NOW() WHERE id=?')
                ->execute([trim((string) $result['body']) ?: null, $machine['id']]);
        } elseif ($result['ok']) {
            db()->prepare('UPDATE machines SET last_seen_at=NOW() WHERE id=?')
                ->execute([$machine['id']]);
        }

        $validation = self::validate($key, $result);

        return [
            'key' => $key,
            'label' => $label,
            'method' => $method,
            'path' => $path,
            'status' => $validation['status'],
            'detail' => $validation['detail'],
            'guidance' => $validation['guidance'],
            'http_status' => (int) ($result['status'] ?? 0),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'content_type' => (string) ($result['content_type'] ?? ''),
            'url' => (string) ($result['url'] ?? ''),
            'error' => (string) ($result['error'] ?? ''),
            'body' => (string) ($result['body'] ?? ''),
            'json' => $result['json'] ?? null,
        ];
    }

    private static function validate(string $key, array $result): array
    {
        if (!($result['ok'] ?? false)) {
            $detail = ($result['error'] ?? '') !== ''
                ? (string) $result['error']
                : 'HTTP ' . (int) ($result['status'] ?? 0);

            return [
                'status' => 'fail',
                'detail' => $detail,
                'guidance' => self::failureGuidance($key),
            ];
        }

        $json = $result['json'] ?? null;
        $body = trim((string) ($result['body'] ?? ''));

        if ($key === 'version') {
            if ($body === '') {
                return [
                    'status' => 'warning',
                    'detail' => 'HTTP corretto, ma la versione restituita è vuota.',
                    'guidance' => 'Verifica la versione del supervisore e ripeti il test. La connettività HTTP risulta comunque disponibile.',
                ];
            }

            return [
                'status' => 'pass',
                'detail' => 'Versione restituita: ' . $body,
                'guidance' => 'Nessuna azione richiesta.',
            ];
        }

        if (in_array($key, ['state', 'state_date'], true)) {
            if (!is_array($json)) {
                return [
                    'status' => 'warning',
                    'detail' => 'HTTP corretto, ma la risposta non è JSON.',
                    'guidance' => 'Copia la risposta grezza e verifica il tracciato effettivo della versione Tecnoessetre installata.',
                ];
            }

            $expected = ['Conneted', 'Connected', 'Mode', 'Comments', 'Warnings', 'Errors', 'ActivityA'];
            $found = array_values(array_filter($expected, static fn(string $field): bool => array_key_exists($field, $json)));

            if (!$found) {
                return [
                    'status' => 'warning',
                    'detail' => 'JSON valido, ma nessun campo stato atteso è stato riconosciuto.',
                    'guidance' => 'Il servizio risponde, ma il parser va adattato al payload reale. Copia il JSON grezzo per aggiornare il mapping.',
                ];
            }

            return [
                'status' => 'pass',
                'detail' => 'JSON valido. Campi riconosciuti: ' . implode(', ', $found) . '.',
                'guidance' => 'Nessuna azione richiesta.',
            ];
        }

        if (in_array($key, ['projects', 'log', 'log_date', 'newlog', 'warehouse', 'recovery'], true)) {
            if (!is_array($json)) {
                return [
                    'status' => 'warning',
                    'detail' => 'HTTP corretto, ma la risposta non è JSON.',
                    'guidance' => 'Copia la risposta grezza: l’endpoint è raggiungibile, ma il formato va verificato.',
                ];
            }

            $items = normalizeList($json);
            $count = count($items);

            if ($key === 'projects') {
                $summary = projectSummary($json);
                $project = trim((string) ($summary['name'] ?? ''));
                if ($project === '' && $count > 0) {
                    return [
                        'status' => 'warning',
                        'detail' => $count . ' record ricevuti, ma non è stato riconosciuto il nome progetto.',
                        'guidance' => 'Copia il payload grezzo di /project/last10 per rendere deterministico il mapping di nome e avanzamento.',
                    ];
                }
                return [
                    'status' => 'pass',
                    'detail' => $count . ' record ricevuti' . ($project !== '' ? '; progetto rilevato: ' . $project : '') . '.',
                    'guidance' => 'Nessuna azione richiesta.',
                ];
            }

            return [
                'status' => 'pass',
                'detail' => $count . ' elementi ricevuti. Anche 0 elementi è un esito valido.',
                'guidance' => 'Nessuna azione richiesta.',
            ];
        }

        if (str_starts_with($key, 'import_') || $key === 'convert_btl') {
            return [
                'status' => 'pass',
                'detail' => 'Upload accettato dal supervisore.',
                'guidance' => 'Verifica sulla macchina che il file sia stato importato nel contesto previsto.',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => 'Richiesta completata.',
            'guidance' => 'Nessuna azione richiesta.',
        ];
    }

    private static function failureGuidance(string $key): string
    {
        return match ($key) {
            'version', 'state' =>
                'Verifica URL macchina, porta 8030, supervisore Tecnoessetre in esecuzione, routing e firewall tra server gestionale e PC macchina.',
            'state_date', 'log_date' =>
                'Verifica la data richiesta e il supporto dell’endpoint sulla versione installata. Se gli endpoint correnti funzionano, il problema è circoscritto alla funzione storica.',
            'projects' =>
                'Verifica che il supervisore supporti /project/last10 e che ci siano progetti disponibili. Confronta la risposta nella sezione grezza.',
            'log', 'newlog' =>
                'Verifica disponibilità del log sulla macchina. Per /newlog controlla anche che la versione sia compatibile e che non ci sia un altro consumatore.',
            'warehouse', 'recovery' =>
                'Verifica che la gestione magazzino/residui sia attiva e disponibile nella configurazione macchina.',
            'import_btl', 'convert_btl', 'import_ts7', 'import_warehouse' =>
                'Verifica formato del file, compatibilità della versione, permessi del supervisore e contenuto del file di collaudo.',
            default =>
                'Controlla connettività, versione supervisore e risposta grezza.',
        };
    }

    private static function skipped(string $key, string $reason): array
    {
        [$method, $path, $label] = self::ENDPOINTS[$key] ?? ['', '', $key];

        return [
            'key' => $key,
            'label' => $label,
            'method' => $method,
            'path' => $path,
            'status' => 'skipped',
            'detail' => $reason,
            'guidance' => 'Nessuna anomalia: il test non è stato eseguito.',
            'http_status' => 0,
            'duration_ms' => 0,
            'content_type' => '',
            'url' => '',
            'error' => '',
            'body' => '',
            'json' => null,
        ];
    }

    private static function failedUnknown(string $key): array
    {
        return [
            'key' => $key,
            'label' => $key,
            'method' => '',
            'path' => '',
            'status' => 'fail',
            'detail' => 'Test non riconosciuto.',
            'guidance' => 'Aggiorna la definizione della suite.',
            'http_status' => 0,
            'duration_ms' => 0,
            'content_type' => '',
            'url' => '',
            'error' => 'Test non riconosciuto.',
            'body' => '',
            'json' => null,
        ];
    }

    private static function report(array $machine, string $date, array $tests): array
    {
        $counts = ['pass' => 0, 'warning' => 0, 'fail' => 0, 'skipped' => 0];
        foreach ($tests as $test) {
            $status = $test['status'] ?? 'fail';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $overall = $counts['fail'] > 0
            ? 'fail'
            : ($counts['warning'] > 0 ? 'warning' : 'pass');

        $headline = match ($overall) {
            'pass' => 'Sistema pronto per le funzioni testate',
            'warning' => 'Sistema raggiungibile, ma alcuni payload richiedono verifica',
            default => 'Sono presenti problemi da risolvere',
        };

        return [
            'machine' => [
                'id' => (int) $machine['id'],
                'name' => $machine['name'],
                'base_url' => $machine['base_url'],
            ],
            'date' => $date,
            'overall' => $overall,
            'headline' => $headline,
            'counts' => $counts,
            'tests' => $tests,
        ];
    }

    private static function normalizeDate(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    }

    private static function isUploadedFile(mixed $file): bool
    {
        return is_array($file)
            && isset($file['error'], $file['tmp_name'], $file['name'])
            && (int) $file['error'] === UPLOAD_ERR_OK
            && is_uploaded_file((string) $file['tmp_name']);
    }
}
