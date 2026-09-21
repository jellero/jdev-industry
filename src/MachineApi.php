<?php
declare(strict_types=1);

final class MachineApi
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 5
    ) {
    }

    public function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    public function upload(string $endpoint, string $filePath, ?string $originalName = null): array
    {
        if (!is_file($filePath)) {
            throw new InvalidArgumentException('File non disponibile per l\'upload.');
        }

        $name = $originalName ?: basename($filePath);
        $mime = mime_content_type($filePath) ?: 'application/octet-stream';

        return $this->request('POST', $endpoint, [
            'filename' => new CURLFile($filePath, $mime, $name),
        ]);
    }

    private function request(string $method, string $endpoint, ?array $fields = null): array
    {
        $endpoint = '/' . ltrim($endpoint, '/');
        $url = rtrim($this->baseUrl, '/') . $endpoint;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Impossibile inizializzare cURL.');
        }

        $started = microtime(true);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, */*'],
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($method === 'POST' && $fields !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $body = $body === false ? '' : (string) $body;
        $json = null;

        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $decoded;
            }
        }

        return [
            'ok' => $errno === 0 && $status >= 200 && $status < 300,
            'status' => $status,
            'url' => $url,
            'content_type' => $contentType,
            'body' => $body,
            'json' => $json,
            'error' => $error,
            'errno' => $errno,
            'duration_ms' => $durationMs,
        ];
    }
}
