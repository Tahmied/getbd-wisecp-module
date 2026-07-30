<?php

namespace GetBD;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class GetBDApiException extends RuntimeException
{
    private int $httpStatus;
    private array $responseData;
    private bool $retryable;

    public function __construct(
        string $message,
        int $httpStatus = 0,
        array $responseData = [],
        bool $retryable = false
    ) {
        parent::__construct($message, $httpStatus);
        $this->httpStatus = $httpStatus;
        $this->responseData = $responseData;
        $this->retryable = $retryable;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getResponseData(): array
    {
        return $this->responseData;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function isNotFound(): bool
    {
        return $this->httpStatus === 404;
    }
}

final class GetBDClient
{
    private string $apiKey;
    private string $v1BaseUrl;
    private string $v2BaseUrl;
    private int $connectTimeout;
    private int $requestTimeout;
    private $transport;
    private $logger;

    public function __construct(array $params)
    {
        $this->apiKey = trim((string) ($params['APIKey'] ?? ''));
        if ($this->apiKey === '') {
            throw new InvalidArgumentException('Get BD API key is required.');
        }

        $sandbox = ($params['SandboxMode'] ?? '') === 'on'
            || ($params['SandboxMode'] ?? false) === true;

        $defaultV1 = $sandbox
            ? 'https://sandbox-api.get.bd/api/v1/external'
            : 'https://api.get.bd/api/v1/external';
        $defaultV2 = $sandbox
            ? 'https://sandbox-api.get.bd/api/v2/external'
            : 'https://api.get.bd/api/v2/external';

        $this->v1BaseUrl = rtrim((string) ($params['V1BaseUrl'] ?? $defaultV1), '/');
        $this->v2BaseUrl = rtrim((string) ($params['V2BaseUrl'] ?? $defaultV2), '/');
        $this->connectTimeout = max(1, (int) ($params['ConnectTimeout'] ?? 10));
        $this->requestTimeout = max(
            $this->connectTimeout,
            (int) ($params['RequestTimeout'] ?? 30)
        );
        $this->transport = $params['Transport'] ?? null;
        $this->logger = $params['Logger'] ?? null;
    }

    public function validateAPIKey(): void
    {
        $response = $this->listDomains([
            'page' => 1,
            'limit' => 1,
        ]);

        if ($this->responseExplicitlyFailed($response)) {
            throw new GetBDApiException(
                $this->messageFromResponse($response, 'Get BD API key validation failed.'),
                200,
                $response
            );
        }
    }

    public function createOrder(array $payload): array
    {
        return $this->request('POST', '/orders', [
            'json' => $payload,
            'operation' => 'create_order',
        ], 'v2');
    }

    public function createCustomer(array $payload): array
    {
        return $this->request('POST', '/customers', [
            'json' => $payload,
            'operation' => 'create_customer',
        ], 'v2');
    }

    public function uploadDocument(
        string $orderId,
        string $documentType,
        string $filePath
    ): array {
        if ($orderId === '') {
            throw new InvalidArgumentException('An order ID is required to upload a document.');
        }
        if ($documentType === '') {
            throw new InvalidArgumentException('A document type is required.');
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException('The document file does not exist or is not readable.');
        }

        return $this->request('POST', '/documents/upload', [
            'multipart' => [
                'orderId' => $orderId,
                'documentType' => $documentType,
                'file' => new \CURLFile(
                    $filePath,
                    function_exists('mime_content_type')
                        ? (mime_content_type($filePath) ?: 'application/octet-stream')
                        : 'application/octet-stream',
                    basename($filePath)
                ),
            ],
            'operation' => 'upload_document',
        ]);
    }

    public function processOrder(string $orderId): array
    {
        if ($orderId === '') {
            throw new InvalidArgumentException('An order ID is required to process an order.');
        }

        return $this->request(
            'POST',
            '/orders/' . rawurlencode($orderId) . '/process',
            ['operation' => 'process_order']
        );
    }

    public function getOrder(string $orderId): ?array
    {
        if ($orderId === '') {
            return null;
        }

        try {
            return $this->request(
                'GET',
                '/orders/' . rawurlencode($orderId),
                ['operation' => 'get_order']
            );
        } catch (GetBDApiException $e) {
            if (in_array($e->getHttpStatus(), [404, 405], true)) {
                return null;
            }
            throw $e;
        }
    }

    public function findOrderByDomain(string $domain): ?array
    {
        try {
            $response = $this->request('GET', '/orders', [
                'query' => [
                    'domainName' => $domain,
                    'page' => 1,
                    'limit' => 20,
                ],
                'operation' => 'find_order',
            ]);

            $match = $this->findDomainRecord($response, $domain);
            if ($match !== null) {
                return $match;
            }
        } catch (GetBDApiException $e) {
            if (!in_array($e->getHttpStatus(), [400, 404, 405], true)) {
                throw $e;
            }
        }

        try {
            $response = $this->listDomains([
                'domain' => $domain,
                'page' => 1,
                'limit' => 20,
            ]);

            return $this->findDomainRecord($response, $domain);
        } catch (GetBDApiException $e) {
            if (in_array($e->getHttpStatus(), [400, 404, 405], true)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Returns null when the provider does not expose a document-list endpoint.
     */
    public function listOrderDocuments(string $orderId): ?array
    {
        if ($orderId === '') {
            return null;
        }

        $requests = [
            ['/documents', ['orderId' => $orderId]],
            ['/orders/' . rawurlencode($orderId) . '/documents', []],
        ];

        foreach ($requests as [$path, $query]) {
            try {
                $response = $this->request('GET', $path, [
                    'query' => $query,
                    'operation' => 'list_order_documents',
                ]);

                return $this->extractCollection($response, [
                    'documents',
                    'items',
                    'results',
                    'rows',
                ]);
            } catch (GetBDApiException $e) {
                if (!in_array($e->getHttpStatus(), [400, 404, 405], true)) {
                    throw $e;
                }
            }
        }

        return null;
    }

    public function renewDomain(string $domain, int $years): array
    {
        return $this->request('POST', '/domains/renew', [
            'json' => [
                'domain' => $domain,
                'years' => $years,
            ],
            'operation' => 'renew_domain',
        ]);
    }

    public function updateNameservers(string $domain, array $nameservers): array
    {
        return $this->request('PUT', '/domains/update', [
            'json' => [
                'domain' => $domain,
                'nameServers' => array_values($nameservers),
            ],
            'operation' => 'update_nameservers',
        ]);
    }

    public function getDomainInfo(string $domain): array
    {
        return $this->request('GET', '/domains/info', [
            'query' => ['domain' => $domain],
            'operation' => 'domain_info',
        ]);
    }

    public function getDomainInfoIfExists(string $domain): ?array
    {
        try {
            return $this->getDomainInfo($domain);
        } catch (GetBDApiException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }
    }

    public function searchDomain(string $domain): array
    {
        return $this->request('GET', '/domains/search', [
            'query' => ['domain' => $domain],
            'operation' => 'search_domain',
        ]);
    }

    public function getDomainRate(string $domain, int $year): array
    {
        return $this->request('GET', '/domains/rate', [
            'query' => [
                'domain' => $domain,
                'year' => $year,
            ],
            'operation' => 'domain_rate',
        ]);
    }

    public function listDomains(array $params = []): array
    {
        return $this->request('GET', '/domains', [
            'query' => $params,
            'operation' => 'list_domains',
        ]);
    }

    public function responseExplicitlyFailed(array $response): bool
    {
        $embeddedStatus = $response['statusCode'] ?? null;
        if (is_numeric($embeddedStatus) && (int) $embeddedStatus >= 400) {
            return true;
        }

        if (array_key_exists('success', $response)) {
            return !$this->toBool($response['success']);
        }

        $status = strtolower(trim((string) ($response['status'] ?? '')));
        return in_array($status, ['error', 'failed', 'failure'], true);
    }

    public function messageFromResponse(array $response, string $fallback): string
    {
        foreach (['message', 'error', 'detail', 'title'] as $key) {
            if (isset($response[$key]) && is_scalar($response[$key])) {
                $message = trim((string) $response[$key]);
                if ($message !== '') {
                    return $message;
                }
            }
        }

        if (isset($response['errors']) && is_array($response['errors'])) {
            $messages = [];
            array_walk_recursive($response['errors'], function ($value) use (&$messages): void {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $messages[] = trim((string) $value);
                }
            });
            if ($messages) {
                return implode('; ', array_unique($messages));
            }
        }

        return $fallback;
    }

    public function extractCollection(array $response, array $preferredKeys = []): array
    {
        $data = $response['data'] ?? $response;
        if (!is_array($data)) {
            return [];
        }

        foreach ($preferredKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $this->normalizeCollection($data[$key]);
            }
        }

        foreach (['items', 'results', 'rows', 'domains', 'orders', 'documents'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $this->normalizeCollection($data[$key]);
            }
        }

        return $this->normalizeCollection($data);
    }

    private function request(
        string $method,
        string $uri,
        array $options = [],
        string $version = 'v1'
    ): array {
        $baseUrl = $version === 'v2' ? $this->v2BaseUrl : $this->v1BaseUrl;
        $url = $baseUrl . '/' . ltrim($uri, '/');
        if (!empty($options['query'])) {
            $query = http_build_query($options['query'], '', '&', PHP_QUERY_RFC3986);
            if ($query !== '') {
                $url .= '?' . $query;
            }
        }

        $headers = [
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            'User-Agent: WISECP-GetBD/2.0',
        ];
        $body = null;
        $multipart = $options['multipart'] ?? null;

        if (array_key_exists('json', $options)) {
            try {
                $body = json_encode(
                    $options['json'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
            } catch (Throwable $e) {
                throw new InvalidArgumentException(
                    'The Get BD request payload could not be encoded: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
            $headers[] = 'Content-Type: application/json';
        }

        $operation = (string) ($options['operation'] ?? strtolower($method) . '_' . trim($uri, '/'));
        $requestLog = [
            'method' => strtoupper($method),
            'url' => $this->redactUrl($url),
            'payload' => $options['json'] ?? $this->multipartLogValue($multipart),
        ];
        $this->log('request', $operation, $requestLog);

        if (is_callable($this->transport)) {
            $transportResponse = call_user_func($this->transport, [
                'method' => strtoupper($method),
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
                'json' => $options['json'] ?? null,
                'multipart' => $multipart,
                'connectTimeout' => $this->connectTimeout,
                'requestTimeout' => $this->requestTimeout,
            ]);
        } else {
            $transportResponse = $this->curlTransport(
                strtoupper($method),
                $url,
                $headers,
                $body,
                $multipart
            );
        }

        if (!is_array($transportResponse)) {
            throw new GetBDApiException('The Get BD transport returned an invalid response.', 0, [], true);
        }

        $httpStatus = (int) ($transportResponse['status'] ?? 0);
        $rawBody = $transportResponse['body'] ?? '';
        if (is_array($rawBody)) {
            $decoded = $rawBody;
        } elseif ($rawBody === '' || $rawBody === null) {
            $decoded = [];
        } else {
            $decoded = json_decode((string) $rawBody, true);
            if (!is_array($decoded)) {
                $this->log('response', $operation, [
                    'httpStatus' => $httpStatus,
                    'error' => 'Invalid JSON response',
                ]);
                throw new GetBDApiException(
                    'Get BD returned an invalid JSON response.',
                    $httpStatus,
                    [],
                    $httpStatus >= 500 || $httpStatus === 0
                );
            }
        }

        $this->log('response', $operation, [
            'httpStatus' => $httpStatus,
            'response' => $decoded,
        ]);

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $message = $this->messageFromResponse(
                $decoded,
                $httpStatus > 0
                    ? "Get BD returned HTTP {$httpStatus}."
                    : 'Get BD did not return an HTTP status.'
            );
            throw new GetBDApiException(
                $message,
                $httpStatus,
                $decoded,
                $httpStatus === 0 || $httpStatus === 408 || $httpStatus === 429 || $httpStatus >= 500
            );
        }

        return $decoded;
    }

    private function curlTransport(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        ?array $multipart
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new GetBDApiException('Unable to initialize the Get BD HTTP client.', 0, [], true);
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
        ];

        if ($multipart !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $multipart;
        } elseif ($body !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new GetBDApiException(
                'Get BD network error: ' . ($error !== '' ? $error : "cURL error {$errno}"),
                0,
                [],
                true
            );
        }

        return [
            'status' => $status,
            'body' => $response === false ? '' : $response,
        ];
    }

    private function findDomainRecord(array $response, string $domain): ?array
    {
        $normalizedDomain = strtolower(rtrim($domain, '.'));
        $records = $this->extractCollection($response, [
            'orders',
            'domains',
            'items',
            'results',
            'rows',
        ]);

        foreach ($records as $record) {
            $candidate = $this->domainFromRecord($record);
            if ($candidate !== '' && strtolower(rtrim($candidate, '.')) === $normalizedDomain) {
                return $record;
            }
        }

        $data = $response['data'] ?? [];
        if (is_array($data)) {
            $candidate = $this->domainFromRecord($data);
            if ($candidate !== '' && strtolower(rtrim($candidate, '.')) === $normalizedDomain) {
                return $data;
            }
        }

        return null;
    }

    private function domainFromRecord(array $record): string
    {
        foreach (['domainName', 'domain', 'name'] as $key) {
            if (isset($record[$key]) && is_scalar($record[$key])) {
                return (string) $record[$key];
            }
        }

        if (isset($record['localDomain']) && is_array($record['localDomain'])) {
            return $this->domainFromRecord($record['localDomain']);
        }

        return '';
    }

    private function normalizeCollection(array $value): array
    {
        if ($value === []) {
            return [];
        }

        if ($this->isList($value)) {
            return array_values(array_filter($value, 'is_array'));
        }

        foreach (['id', 'orderId', 'domain', 'domainName', 'documentType'] as $recordKey) {
            if (array_key_exists($recordKey, $value)) {
                return [$value];
            }
        }

        $records = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $records[] = $item;
            }
        }

        return $records;
    }

    private function isList(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return in_array(strtolower(trim((string) $value)), ['true', 'yes', 'active', 'success'], true);
    }

    private function multipartLogValue(?array $multipart): ?array
    {
        if ($multipart === null) {
            return null;
        }

        $result = [];
        foreach ($multipart as $key => $value) {
            $result[$key] = $value instanceof \CURLFile
                ? '[DOCUMENT]'
                : $value;
        }

        return $result;
    }

    private function redactUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        $query = $this->redact($query);
        $base = strtok($url, '?');

        return $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function redact($value, string $key = '')
    {
        $sensitive = preg_match(
            '/api.?key|authorization|token|secret|password|nid|email|phone|contact|address|postcode|file/i',
            $key
        );
        if ($sensitive) {
            return '[REDACTED]';
        }

        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $childKey => $childValue) {
            $result[$childKey] = $this->redact($childValue, (string) $childKey);
        }

        return $result;
    }

    private function log(string $direction, string $operation, array $context): void
    {
        $safeContext = $this->redact($context);

        if (is_callable($this->logger)) {
            call_user_func($this->logger, $direction, $operation, $safeContext);
            return;
        }

        if (class_exists('\\Modules') && method_exists('\\Modules', 'save_log')) {
            try {
                \Modules::save_log(
                    'Registrars',
                    'GetBD',
                    $operation . ':' . $direction,
                    $direction === 'request' ? $safeContext : [],
                    $direction === 'response' ? $safeContext : []
                );
            } catch (Throwable $e) {
                // Registrar operations must not fail because diagnostic logging failed.
            }
        }
    }
}
