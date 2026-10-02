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
        $jsonNormalized = false;
        $jsonError = null;

        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $decoded;
            } else {
                $jsonError = json_last_error_msg();

                // Alcune versioni Tecnoessetre restituiscono oggetti JavaScript
                // come new Date(1234567890000), che non appartengono al JSON.
                // Manteniamo il body originale e normalizziamo solo per il parser.
                $normalizedBody = preg_replace(
                    '/\bnew\s+Date\s*\(\s*(-?\d+(?:\.\d+)?)\s*\)/i',
                    '$1',
                    $body,
                    -1,
                    $replacementCount
                );

                if ($replacementCount > 0 && is_string($normalizedBody)) {
                    $decoded = json_decode($normalizedBody, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $json = $decoded;
                        $jsonNormalized = true;
                        $jsonError = null;
                    } else {
                        $jsonError = json_last_error_msg();
                    }
                }
            }
        }

        return [
            'ok' => $errno === 0 && $status >= 200 && $status < 300,
            'status' => $status,
            'url' => $url,
            'content_type' => $contentType,
            'body' => $body,
            'json' => $json,
            'json_normalized' => $jsonNormalized,
            'json_error' => $jsonError,
            'error' => $error,
            'errno' => $errno,
            'duration_ms' => $durationMs,
        ];
    }
}
