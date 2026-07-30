<?php

namespace GetBD;

use InvalidArgumentException;
use RuntimeException;
use Exception;

final class GetBDClient
{
    private array $params;
    private string $apiKey;
    private string $baseUrl;
    private string $logFile;

    public function __construct(array $params)
    {
        $this->params = $params;
        $this->apiKey = trim($this->params['APIKey'] ?? '');
        $sandbox = ($this->params['SandboxMode'] ?? '') === 'on';
        $this->logFile = __DIR__ . '/getbd_client.log';

        if ($this->apiKey === '') {
            $this->log('ERROR', '__construct', 'Get BD API key is required');
            throw new InvalidArgumentException('Get BD API key is required');
        }

        $this->baseUrl = $sandbox
            ? 'https://sandbox-api.get.bd/api/v1/external'
            : 'https://api.get.bd/api/v1/external';

        $this->log('INFO', '__construct', 'Client initialized', [
            'sandbox' => $sandbox,
            'baseUrl' => $this->baseUrl,
        ]);
    }

    public function validateAPIKey(): void
    {
        $this->log('INFO', 'validateAPIKey', 'Validating API key');

        $response = $this->request('GET', '');

        if (($response['statusCode'] ?? null) === 401) {
            $this->log('ERROR', 'validateAPIKey', 'API Key is invalid');
            throw new Exception('API Key is invalid.');
        }

        $this->log('INFO', 'validateAPIKey', 'API key is valid');
    }

    public function registerDomain(
        string $domainName,
        int $regPeriod,
        string $fullName,
        string $nid,
        string $email,
        string $contactAddress,
        string $contactNumber,
        array $nameservers,
        array $documents = []
    ): array {
        $this->log('INFO', 'registerDomain', 'Starting domain registration', [
            'domain' => $domainName,
            'years' => $regPeriod,
            'email' => $email,
        ]);

        $orderResponse = $this->createOrder([
            'domainName' => $domainName,
            'years' => $regPeriod,
            'fullName' => $fullName,
            'nid' => $nid,
            'email' => $email,
            'contactAddress' => $contactAddress,
            'contactNumber' => $contactNumber,
            'nameServers' => $nameservers,
        ]);

        if (empty($orderResponse['success']) || empty($orderResponse['data']['id'])) {
            $message = $orderResponse['message'] ?? 'Order creation failed';
            $this->log('ERROR', 'registerDomain', 'Order creation failed', [
                'domain' => $domainName,
                'message' => $message,
            ]);
            throw new RuntimeException($message);
        }

        $orderId = (string) $orderResponse['data']['id'];
        $this->log('INFO', 'registerDomain', 'Order created successfully', [
            'domain' => $domainName,
            'orderId' => $orderId,
        ]);

        foreach ($documents as $apiType => $filePath) {
            if (!file_exists($filePath)) {
                $this->log('ERROR', 'registerDomain', 'Document file not found', [
                    'documentType' => $apiType,
                    'filePath' => $filePath,
                ]);
                throw new RuntimeException(
                    "File not found for document type [{$apiType}]: {$filePath}"
                );
            }

            $this->log('INFO', 'registerDomain', 'Uploading document', [
                'orderId' => $orderId,
                'documentType' => $apiType,
                'filePath' => $filePath,
            ]);

            $uploadResp = $this->uploadDocument([
                'orderId' => $orderId,
                'documentType' => $apiType,
                'file' => new \CURLFile(
                    $filePath,
                    mime_content_type($filePath) ?: 'application/octet-stream',
                    basename($filePath)
                ),
            ]);

            if (
                empty($uploadResp['success']) ||
                empty($uploadResp['data']['id'])
            ) {
                $message = "Failed to upload [{$apiType}]: "
                    . ($uploadResp['message'] ?? json_encode($uploadResp));
                $this->log('ERROR', 'registerDomain', 'Document upload failed', [
                    'orderId' => $orderId,
                    'documentType' => $apiType,
                    'message' => $message,
                ]);
                throw new RuntimeException($message);
            }

            $this->log('INFO', 'registerDomain', 'Document uploaded successfully', [
                'orderId' => $orderId,
                'documentType' => $apiType,
                'documentId' => $uploadResp['data']['id'],
            ]);
        }

        $this->log('INFO', 'registerDomain', 'Processing order', [
            'orderId' => $orderId,
        ]);

        $processResponse = $this->processOrder($orderId);

        if (!($processResponse['success'] ?? false)) {
            $message = $processResponse['message'] ?? 'Order processing failed';
            if (stripos($message, 'APPROVED documents') === false) {
                $this->log('ERROR', 'registerDomain', 'Order processing failed', [
                    'orderId' => $orderId,
                    'message' => $message,
                ]);
                throw new RuntimeException($message);
            }
            $this->log('WARNING', 'registerDomain', 'Order processed with warning', [
                'orderId' => $orderId,
                'message' => $message,
            ]);
        } else {
            $this->log('INFO', 'registerDomain', 'Order processed successfully', [
                'orderId' => $orderId,
                'domain' => $domainName,
            ]);
        }

        return ['success' => true];
    }

    public function renewDomain(string $domain, int $years): array
    {
        $this->log('INFO', 'renewDomain', 'Starting domain renewal', [
            'domain' => $domain,
            'years' => $years,
        ]);

        $response = $this->request('POST', '/domains/renew', [
            'json' => [
                'domain' => $domain,
                'years' => $years,
            ],
        ]);

        if (empty($response['success'])) {
            $message = $response['message'] ?? 'Domain renewal failed';
            $this->log('ERROR', 'renewDomain', 'Domain renewal failed', [
                'domain' => $domain,
                'message' => $message,
            ]);
            throw new RuntimeException($message);
        }

        $this->log('INFO', 'renewDomain', 'Domain renewed successfully', [
            'domain' => $domain,
            'years' => $years,
        ]);

        return ['success' => true];
    }

    public function updateNameservers(
        string $domain,
        array $nameservers
    ): array {
        $this->log('INFO', 'updateNameservers', 'Updating nameservers', [
            'domain' => $domain,
            'nameservers' => $nameservers,
        ]);

        $response = $this->request('PUT', '/domains/update', [
            'json' => [
                'domain' => $domain,
                'nameServers' => array_values($nameservers),
            ],
        ]);

        if (empty($response['success'])) {
            $message = $response['message'] ?? 'Nameserver update failed';
            $this->log('ERROR', 'updateNameservers', 'Nameserver update failed', [
                'domain' => $domain,
                'message' => $message,
            ]);
            throw new RuntimeException($message);
        }

        $this->log('INFO', 'updateNameservers', 'Nameservers updated successfully', [
            'domain' => $domain,
            'nameservers' => $nameservers,
        ]);

        return ['success' => true];
    }

    public function getDomainInfo(string $domain): array
    {
        $this->log('INFO', 'getDomainInfo', 'Fetching domain info', [
            'domain' => $domain,
        ]);

        $response = $this->request('GET', '/domains/info', [
            'query' => ['domain' => $domain],
        ]);

        $this->log('INFO', 'getDomainInfo', 'Domain info fetched', [
            'domain' => $domain,
        ]);

        return $response;
    }

    public function searchDomain(string $domain): array
    {
        $this->log('INFO', 'searchDomain', 'Searching domain', [
            'domain' => $domain,
        ]);

        $response = $this->request('GET', '/domains/search', [
            'query' => ['domain' => $domain],
        ]);

        $this->log('INFO', 'searchDomain', 'Domain search completed', [
            'domain' => $domain,
        ]);

        return $response;
    }

    public function createOrder(array $payload): array
    {
        $this->log('INFO', 'createOrder', 'Creating order', [
            'domain' => $payload['domainName'] ?? 'N/A',
        ]);

        $response = $this->request('POST', '/orders', [
            'json' => $payload,
        ]);

        $this->log('INFO', 'createOrder', 'Order request completed', [
            'success' => $response['success'] ?? false,
        ]);

        return $response;
    }

    public function processOrder(string $orderId): array
    {
        $this->log('INFO', 'processOrder', 'Processing order', [
            'orderId' => $orderId,
        ]);

        $response = $this->request('POST', "/orders/{$orderId}/process");

        $this->log('INFO', 'processOrder', 'Process order request completed', [
            'orderId' => $orderId,
            'success' => $response['success'] ?? false,
        ]);

        return $response;
    }

    public function getDomainRate(string $domain, int $year): array
    {
        $this->log('INFO', 'getDomainRate', 'Fetching domain rate', [
            'domain' => $domain,
            'year' => $year,
        ]);

        $response = $this->request('GET', '/domains/rate', [
            'query' => [
                'domain' => $domain,
                'year' => $year,
            ],
        ]);

        $this->log('INFO', 'getDomainRate', 'Domain rate fetched', [
            'domain' => $domain,
            'year' => $year,
        ]);

        return $response;
    }

    public function listDomains(array $params = []): array
    {
        $this->log('INFO', 'listDomains', 'Listing domains', $params);

        $response = $this->request('GET', '/domains', [
            'query' => $params,
        ]);

        $this->log('INFO', 'listDomains', 'Domain list fetched');

        return $response;
    }

    public function createCustomer(array $payload): array
    {
        $this->log('INFO', 'createCustomer', 'Creating customer', [
            'email' => $payload['email'] ?? 'N/A',
        ]);

        $response = $this->request('POST', '/customers', [
            'json' => $payload,
        ]);

        $this->log('INFO', 'createCustomer', 'Customer creation request completed', [
            'success' => $response['success'] ?? false,
        ]);

        return $response;
    }

    public function uploadDocument(array $multipart): array
    {
        $this->log('INFO', 'uploadDocument', 'Uploading document', [
            'orderId' => $multipart['orderId'] ?? 'N/A',
            'documentType' => $multipart['documentType'] ?? 'N/A',
        ]);

        $response = $this->request('POST', '/documents/upload', [
            'multipart' => $multipart,
        ]);

        $this->log('INFO', 'uploadDocument', 'Document upload request completed', [
            'success' => $response['success'] ?? false,
        ]);

        return $response;
    }

    private function request(
        string $method,
        string $uri,
        array $options = []
    ): array {
        $url = rtrim($this->baseUrl, '/') . $uri;
        $headers = [
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $body = null;

        if (isset($options['json'])) {
            $body = json_encode($options['json'], JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }

        if (isset($options['query'])) {
            $url .= '?' . http_build_query($options['query']);
        }

        $this->log('DEBUG', 'request', 'Sending HTTP request', [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'payload' => isset($options['json'])
                ? $options['json']
                : (isset($options['multipart'])
                    ? array_map(fn($v) => ($v instanceof \CURLFile ? '[FILE] ' . $v->getFilename() : $v), $options['multipart'])
                    : null),
        ]);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
        ]);

        if (isset($options['multipart'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['multipart']);
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            $this->log('ERROR', 'request', 'cURL error occurred', [
                'method' => $method,
                'url' => $url,
                'errno' => $errno,
                'error' => $error,
            ]);
            throw new RuntimeException("cURL Error: " . $error);
        }

        if ($response === '' || $response === false) {
            $this->log('ERROR', 'request', 'Empty API response received', [
                'method' => $method,
                'url' => $url,
            ]);
            return [
                'status' => 'Error',
                'message' => 'Empty API response',
            ];
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('ERROR', 'request', 'Invalid JSON response received', [
                'method' => $method,
                'url' => $url,
                'response' => substr($response, 0, 500),
            ]);
            return [
                'status' => 'Error',
                'message' => 'Invalid JSON response',
            ];
        }

        $this->log('DEBUG', 'request', 'HTTP response received', [
            'method' => $method,
            'url' => $url,
            'success' => $decoded['success'] ?? 'N/A',
            'response' => $decoded,
        ]);

        return $decoded;
    }

    private function log(
        string $level,
        string $method,
        string $message,
        array $context = []
    ): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = empty($context)
            ? ''
            : ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $line = "[{$timestamp}] [{$level}] [{$method}] {$message}{$contextStr}" . PHP_EOL;

        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}