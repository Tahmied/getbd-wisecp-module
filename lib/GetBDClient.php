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
        int $regPeriod,
        string $fullName,
        string $nid,
        string $email,
        string $contactAddress,
        string $contactNumber,
        array  $nameservers
    ): array {

        $orderResponse = $this->createOrder([
            'domainName'     => $domainName,
            'years'          => $regPeriod,
            'fullName'       => $fullName,
            'nid'            => $nid,
            'email'          => $email,
            'contactAddress' => $contactAddress,
            'contactNumber'  => $contactNumber,
            'nameServers'    => $nameservers
        ]);

        if (
            empty($orderResponse['success']) ||
            empty($orderResponse['data']['id'])
        ) {
            throw new RuntimeException($orderResponse['message'] ?? 'Order creation failed');
        }

        $orderId = (string) $orderResponse['data']['id'];

        $processResponse = $this->processOrder($orderId);

        if (!($processResponse['success'] ?? false)) {
            $message = $processResponse['message'] ?? null;

            if ($message !== 'Order must have at least 2 APPROVED documents before processing. Currently 0 approved. Please review and approve documents first.') {
                throw new RuntimeException($message ?? 'Order processing failed');
            }
        }

        return [
            "success" => true
        ];
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
        if (isset($options['json'])) {
            $body = json_encode($options['json'], JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
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
        }

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        file_put_contents(
            __DIR__ . '/api_raw.log',
            "URL: $url\nSTATUS: $status\nRESPONSE:\n$response\n\n",
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
