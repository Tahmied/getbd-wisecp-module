<?php

namespace GetBD;

use InvalidArgumentException;
use RuntimeException;
use Exception;

final class GetBDClient
{
    private array  $params;
    private string $apiKey;
    private string $baseUrl;

    public function __construct(array $params)
    {
        $this->params = $params;
        $this->apiKey  = trim($this->params['APIKey'] ?? '');
        $sandbox       = ($this->params['SandboxMode'] ?? '') === 'on';

        if ($this->apiKey === '') {
            throw new InvalidArgumentException('Get BD API key is required');
        }

        $this->baseUrl = $sandbox
            ? 'https://sandbox-api.get.bd/api/v1/external'
            : 'https://api.get.bd/api/v1/external';
    }

    public function validateAPIKey(): void
    {
        $response = $this->request('GET', '');

        if (($response['statusCode'] ?? null) === 401) {
            throw new Exception('API Key is invalid.');
        }
    }

    public function registerDomain(
        string $domainName,
        int    $regPeriod,
        string $fullName,
        string $nid,
        string $email,
        string $contactAddress,
        string $contactNumber,
        array  $nameservers,
        array  $documents = []
    ): array {

        $log  = "=== registerDomain() START ===\n";
        $log .= "[" . date('Y-m-d H:i:s') . "] Domain: {$domainName} | Years: {$regPeriod} | NID: {$nid}\n";
        $log .= "Documents to upload (" . count($documents) . "):\n";
        foreach ($documents as $apiType => $path) {
            $log .= "  [{$apiType}] {$path} | exists=" . (file_exists($path) ? 'YES' : 'NO') . "\n";
        }
        file_put_contents(__DIR__ . '/api_raw.log', $log, FILE_APPEND);

        // ── Step 1: Create the order ──────────────────────────────────────────────
        file_put_contents(__DIR__ . '/api_raw.log', "--- Step 1: Creating order...\n", FILE_APPEND);

        $orderResponse = $this->createOrder([
            'domainName'     => $domainName,
            'years'          => $regPeriod,
            'fullName'       => $fullName,
            'nid'            => $nid,
            'email'          => $email,
            'contactAddress' => $contactAddress,
            'contactNumber'  => $contactNumber,
            'nameServers'    => $nameservers,
        ]);

        if (empty($orderResponse['success']) || empty($orderResponse['data']['id'])) {
            $msg = $orderResponse['message'] ?? 'Order creation failed';
            file_put_contents(__DIR__ . '/api_raw.log', "Order creation FAILED: {$msg}\n\n", FILE_APPEND);
            throw new RuntimeException($msg);
        }

        $orderId = (string) $orderResponse['data']['id'];
        file_put_contents(__DIR__ . '/api_raw.log', "Order created successfully. ID: {$orderId}\n\n", FILE_APPEND);

        // ── Step 2: Upload each document ─────────────────────────────────────────
        if (!empty($documents)) {
            file_put_contents(__DIR__ . '/api_raw.log', "--- Step 2: Uploading " . count($documents) . " document(s)...\n", FILE_APPEND);

            foreach ($documents as $apiType => $filePath) {
                if (!file_exists($filePath)) {
                    throw new RuntimeException("File not found for document type [{$apiType}]: {$filePath}");
                }

                file_put_contents(__DIR__ . '/api_raw.log', "Uploading [{$apiType}] from: {$filePath}\n", FILE_APPEND);

                $uploadResp = $this->uploadDocument([
                    'orderId'      => $orderId,
                    'documentType' => $apiType,
                    'file'         => new \CURLFile(
                        $filePath,
                        mime_content_type($filePath) ?: 'application/octet-stream',
                        basename($filePath)
                    ),
                ]);

                if (empty($uploadResp['success']) || empty($uploadResp['data']['id'])) {
                    $msg = $uploadResp['message'] ?? json_encode($uploadResp);
                    file_put_contents(__DIR__ . '/api_raw.log', "Upload FAILED [{$apiType}]: {$msg}\n\n", FILE_APPEND);
                    throw new RuntimeException("Failed to upload [{$apiType}]: {$msg}");
                }

                file_put_contents(__DIR__ . '/api_raw.log', "Upload SUCCESS [{$apiType}] — Document ID: {$uploadResp['data']['id']}\n\n", FILE_APPEND);
            }
        } else {
            file_put_contents(__DIR__ . '/api_raw.log', "--- Step 2: No documents to upload, skipping.\n\n", FILE_APPEND);
        }

        // ── Step 3: Process the order ─────────────────────────────────────────────
        file_put_contents(__DIR__ . '/api_raw.log', "--- Step 3: Processing order {$orderId}...\n", FILE_APPEND);

        $processResponse = $this->processOrder($orderId);

        file_put_contents(__DIR__ . '/api_raw.log', "Process response: " . json_encode($processResponse) . "\n", FILE_APPEND);

        if (!($processResponse['success'] ?? false)) {
            $message = $processResponse['message'] ?? 'Order processing failed';

            if (stripos($message, 'APPROVED documents') !== false) {
                file_put_contents(__DIR__ . '/api_raw.log', "Order requires document approval — admin will review. Continuing.\n\n", FILE_APPEND);
            } else {
                file_put_contents(__DIR__ . '/api_raw.log', "Order processing FAILED: {$message}\n\n", FILE_APPEND);
                throw new RuntimeException($message);
            }
        } else {
            file_put_contents(__DIR__ . '/api_raw.log', "Order processed successfully.\n", FILE_APPEND);
        }

        file_put_contents(__DIR__ . '/api_raw.log', "=== registerDomain() COMPLETE ===\n\n", FILE_APPEND);

        return ['success' => true];
    }

    public function renewDomain(string $domain, int $years): array
    {
        $response = $this->request('POST', '/domains/renew', [
            'json' => [
                'domain' => $domain,
                'years'  => $years
            ]
        ]);

        if (empty($response['success'])) {
            throw new RuntimeException($response['message'] ?? 'Domain renewal failed');
        }

        return [
            "success" => true
        ];
    }

    public function updateNameservers(string $domain, array $nameservers): array
    {
        $response = $this->request('PUT', '/domains/update', [
            'json' => [
                'domain'      => $domain,
                'nameServers' => array_values($nameservers)
            ]
        ]);

        if (empty($response['success'])) {
            throw new RuntimeException($response['message'] ?? 'Nameserver update failed');
        }

        return [
            "success" => true
        ];
    }

    public function getDomainInfo(string $domain): array
    {
        return $this->request('GET', '/domains/info', [
            'query' => ['domain' => $domain]
        ]);
    }

    public function searchDomain(string $domain): array
    {
        return $this->request('GET', '/domains/search', [
            'query' => ['domain' => $domain]
        ]);
    }

    public function createOrder(array $payload): array
    {
        return $this->request('POST', '/orders', [
            'json' => $payload
        ]);
    }

    public function processOrder(string $orderId): array
    {
        return $this->request('POST', "/orders/{$orderId}/process");
    }

    public function getDomainRate(string $domain, int $year): array
    {
        return $this->request('GET', '/domains/rate', [
            'query' => [
                'domain' => $domain,
                'year'   => $year
            ]
        ]);
    }

    public function listDomains(array $params = []): array
    {
        return $this->request('GET', '/domains', [
            'query' => $params
        ]);
    }

    public function createCustomer(array $payload): array
    {
        return $this->request('POST', '/customers', [
            'json' => $payload
        ]);
    }

    public function uploadDocument(array $multipart): array
    {
        return $this->request('POST', '/documents/upload', [
            'multipart' => $multipart
        ]);
    }

    private function request(string $method, string $uri, array $options = []): array
    {
        $url     = rtrim($this->baseUrl, '/') . $uri;
        $headers = [
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->apiKey
        ];

        $body = null;
        $logPayload = "NONE";

        if (isset($options['json'])) {
            $body = json_encode($options['json'], JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            $logPayload = "JSON: " . $body;
        }

        if (isset($options['query'])) {
            $url .= '?' . http_build_query($options['query']);
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body
        ]);

        if (isset($options['multipart'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['multipart']);

            // Format multipart logging so we can see what's attached without crashing print_r on CURLFile
            $mpKeys = [];
            foreach ($options['multipart'] as $key => $val) {
                if ($val instanceof \CURLFile) {
                    $mpKeys[] = "$key => [CURLFile: " . $val->name . "]";
                } else {
                    $mpKeys[] = "$key => $val";
                }
            }
            $logPayload = "MULTIPART DATA:\n" . implode("\n", $mpKeys);
        }

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        file_put_contents(
            __DIR__ . '/api_raw.log',
            "URL: $url\nMETHOD: $method\nPAYLOAD:\n$logPayload\nSTATUS: $status\nRESPONSE:\n$response\n\n",
            FILE_APPEND
        );
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException("cURL Error: " . $error);
        }

        if ($response === '' || $response === false) {
            return [
                'status' => 'Error',
                'message' => 'Empty API response',
            ];
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => 'Error',
                'message' => 'Invalid JSON response',
            ];
        }

        return $decoded;
    }
}
