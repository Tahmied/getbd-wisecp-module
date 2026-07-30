<?php

class RegistrarModule
{
    public $config = [];
    public $lang = [];
    public $docs = [];
    public $order = [];

    public function __construct($name)
    {
    }

    public function set_order($order = [])
    {
        $this->order = $order;
        return $this;
    }

    public function define_docs($docs = [])
    {
        $this->docs = $docs;
    }
}

class Utility
{
    public static function jencode($value)
    {
        return json_encode($value);
    }

    public static function jdecode($value, $assoc = false)
    {
        return json_decode($value, $assoc);
    }
}

class Orders
{
    public static array $sets = [];

    public static function set($id, $data)
    {
        self::$sets[] = ['id' => $id, 'data' => $data];
        return true;
    }
}

require_once dirname(__DIR__) . '/GetBD.php';

use GetBD\GetBDApiException;
use GetBD\GetBDClient;

final class FakeGetBDTransport
{
    public array $calls = [];
    private $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function __invoke(array $request): array
    {
        $this->calls[] = $request;
        return call_user_func($this->handler, $request, $this);
    }

    public function count(string $method, string $path): int
    {
        $count = 0;
        foreach ($this->calls as $call) {
            if (
                strtoupper($call['method']) === strtoupper($method)
                && parse_url($call['url'], PHP_URL_PATH) === $path
            ) {
                $count++;
            }
        }
        return $count;
    }
}

final class FakeCapabilityDatabase
{
    public array $tlds;
    public array $orders;
    public array $updates = [];

    public function __construct(array $tlds, array $orders)
    {
        $this->tlds = $tlds;
        $this->orders = $orders;
    }

    public function select($columns = '*'): FakeCapabilityQuery
    {
        return new FakeCapabilityQuery($this);
    }

    public function update(string $table, array $values): FakeCapabilityUpdate
    {
        return new FakeCapabilityUpdate($this, $table, $values);
    }
}

final class FakeCapabilityQuery
{
    private FakeCapabilityDatabase $database;
    private string $table = '';

    public function __construct(FakeCapabilityDatabase $database)
    {
        $this->database = $database;
    }

    public function from(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function where(...$arguments): self
    {
        return $this;
    }

    public function build(): bool
    {
        return true;
    }

    public function fetch_assoc(): array
    {
        return $this->table === 'tldlist'
            ? $this->database->tlds
            : $this->database->orders;
    }
}

final class FakeCapabilityUpdate
{
    private FakeCapabilityDatabase $database;
    private string $table;
    private array $values;
    private int $id = 0;

    public function __construct(FakeCapabilityDatabase $database, string $table, array $values)
    {
        $this->database = $database;
        $this->table = $table;
        $this->values = $values;
    }

    public function where(string $field, string $operator, $value): self
    {
        if ($field === 'id' && $operator === '=') {
            $this->id = (int) $value;
        }
        return $this;
    }

    public function save(): bool
    {
        $this->database->updates[] = [
            'table' => $this->table,
            'id' => $this->id,
            'values' => $this->values,
        ];
        return true;
    }
}

function response(int $status, array $body): array
{
    return ['status' => $status, 'body' => $body];
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual:   " . var_export($actual, true)
        );
    }
}

function assertTrueValue($actual, string $message): void
{
    assertSameValue(true, (bool) $actual, $message);
}

function assertFalseValue($actual, string $message): void
{
    assertSameValue(false, (bool) $actual, $message);
}

function assertContainsValue(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . "\nMissing: {$needle}\nValue: {$haystack}");
    }
}

function makeClient(FakeGetBDTransport $transport, ?callable $logger = null): GetBDClient
{
    return new GetBDClient([
        'APIKey' => 'test-secret-key',
        'V1BaseUrl' => 'https://v1.test',
        'V2BaseUrl' => 'https://v2.test',
        'Transport' => $transport,
        'Logger' => $logger,
    ]);
}

function makeModule(FakeGetBDTransport $transport, array $docs = []): GetBD
{
    $module = new GetBD();
    $module->config = require dirname(__DIR__) . '/config.php';
    $module->docs = $docs;
    $module->setClient(makeClient($transport));
    return $module;
}

function registrant(array $overrides = []): array
{
    return [
        'registrant' => array_merge([
            'Name' => 'Test Registrant',
            'Company' => 'Example Ltd',
            'EMail' => 'owner@example.com',
            'AddressLine1' => '123 Test Road',
            'AddressLine2' => '',
            'City' => 'Dhaka',
            'State' => 'Dhaka',
            'ZipCode' => '1205',
            'Country' => 'BD',
            'PhoneCountryCode' => '880',
            'Phone' => '1712345678',
        ], $overrides),
    ];
}

function nameservers(): array
{
    return [
        'ns1' => 'ns1.example.net',
        'ns2' => 'ns2.example.net',
    ];
}

function createPngDocument(string $label): string
{
    $path = tempnam(sys_get_temp_dir(), 'getbd-test-');
    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary test document.');
    }
    $pngPath = $path . '-' . $label . '.png';
    rename($path, $pngPath);
    file_put_contents(
        $pngPath,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
    );
    return $pngPath;
}

function bdDocuments(): array
{
    return [
        'nid_number' => '1234567890',
        'nid_front' => createPngDocument('front'),
        'nid_back' => createPngDocument('back'),
    ];
}

function removeDocuments(array $documents): void
{
    foreach ($documents as $document) {
        if (is_string($document) && is_file($document)) {
            unlink($document);
        }
    }
}

$tests = [];

$tests['routes v2 order creation separately from v1 operations and redacts logs'] = function (): void {
    $logs = [];
    $transport = new FakeGetBDTransport(function (array $request): array {
        return response(201, [
            'success' => true,
            'data' => [
                'id' => '01ORDER',
                'status' => 'PENDING',
            ],
        ]);
    });
    $client = makeClient(
        $transport,
        function ($direction, $operation, $context) use (&$logs): void {
            $logs[] = [$direction, $operation, $context];
        }
    );

    $client->createOrder([
        'domainName' => 'example.com.bd',
        'years' => 1,
        'fullName' => 'Sensitive Name',
        'nid' => '1234567890',
        'email' => 'secret@example.com',
        'contactAddress' => 'Secret Address',
        'contactNumber' => '+8801712345678',
        'nameServers' => ['ns1.example.net', 'ns2.example.net'],
    ]);

    assertContainsValue('https://v2.test/orders', $transport->calls[0]['url'], 'Order creation did not use v2.');
    $encodedLogs = json_encode($logs);
    assertFalseValue(strpos($encodedLogs, 'test-secret-key') !== false, 'The API key was written to logs.');
    assertFalseValue(strpos($encodedLogs, '1234567890') !== false, 'The NID was written to logs.');
    assertFalseValue(strpos($encodedLogs, 'secret@example.com') !== false, 'The email was written to logs.');
};

$tests['classifies rate limiting as retryable'] = function (): void {
    $transport = new FakeGetBDTransport(function (): array {
        return response(429, [
            'success' => false,
            'message' => 'Rate limit exceeded',
        ]);
    });
    $client = makeClient($transport);

    try {
        $client->listDomains();
        throw new RuntimeException('The 429 response did not throw.');
    } catch (GetBDApiException $e) {
        assertSameValue(429, $e->getHttpStatus(), 'The HTTP status was not preserved.');
        assertTrueValue($e->isRetryable(), 'A 429 response must be retryable.');
    }
};

$tests['keeps a document-review registration pending and preserves duplicate document types'] = function (): void {
    $documents = bdDocuments();
    $created = false;
    $transport = new FakeGetBDTransport(function (array $request) use (&$created): array {
        $method = $request['method'];
        $path = parse_url($request['url'], PHP_URL_PATH);

        if ($method === 'GET' && $path === '/domains/info') {
            if (!$created) {
                return response(404, ['success' => false, 'message' => 'Domain not found']);
            }
            return response(200, [
                'success' => true,
                'data' => [
                    'localDomain' => [
                        'domainName' => 'example.bd',
                        'orderId' => '01ORDER',
                        'orderStatus' => 'PENDING',
                        'isActive' => false,
                    ],
                ],
            ]);
        }
        if ($method === 'GET' && $path === '/orders') {
            return response(200, ['success' => true, 'data' => []]);
        }
        if ($method === 'GET' && $path === '/domains') {
            return response(200, ['success' => true, 'data' => []]);
        }
        if ($method === 'POST' && $path === '/orders') {
            $created = true;
            return response(201, [
                'success' => true,
                'data' => ['id' => '01ORDER', 'status' => 'PENDING'],
            ]);
        }
        if ($method === 'POST' && $path === '/documents/upload') {
            return response(201, [
                'success' => true,
                'data' => ['id' => uniqid('DOC', true)],
            ]);
        }
        if ($method === 'POST' && $path === '/orders/01ORDER/process') {
            return response(400, [
                'success' => false,
                'message' => 'Order must have at least 2 APPROVED documents before processing. Currently 0 approved.',
            ]);
        }

        throw new RuntimeException("Unexpected request: {$method} {$path}");
    });
    $module = makeModule($transport, $documents);
    Orders::$sets = [];
    $module->set_order([
        'id' => 42,
        'options' => [
            'domain' => 'example.bd',
        ],
    ]);

    try {
        $result = $module->register(
            'example.bd',
            'example',
            'bd',
            1,
            nameservers(),
            registrant()
        );

        assertSameValue('FAIL', $result['status'], 'A document-review order was incorrectly activated.');
        assertContainsValue('APPROVED documents', $result['message'], 'The provider pending reason was lost.');
        assertSameValue(
            2,
            $transport->count('POST', '/documents/upload'),
            'Both NID files sharing the same provider type were not uploaded.'
        );
        assertSameValue(1, $transport->count('POST', '/orders'), 'The order was not created exactly once.');
        assertSameValue(42, Orders::$sets[0]['id'], 'The pending provider order was not attached to WISECP.');
        $savedOptions = json_decode(Orders::$sets[0]['data']['options'], true);
        assertSameValue(
            '01ORDER',
            $savedOptions['config']['getbd_order_id'],
            'The pending provider order ID was not persisted for idempotent retries.'
        );
        assertTrueValue(
            $savedOptions['dns_manage'],
            'The pending order did not retain the nameserver-management capability.'
        );
        assertFalseValue(
            $savedOptions['whois_manage'],
            'Unsupported WHOIS mutation was exposed on the pending order.'
        );
    } finally {
        removeDocuments($documents);
    }
};

$tests['polls a pending order, processes it once approved, and activates without creating a duplicate'] = function (): void {
    $active = false;
    $transport = new FakeGetBDTransport(function (array $request) use (&$active): array {
        $method = $request['method'];
        $path = parse_url($request['url'], PHP_URL_PATH);

        if ($method === 'GET' && $path === '/domains/info') {
            return response(200, [
                'success' => true,
                'data' => [
                    'primaryDns' => 'ns1.example.net',
                    'secondaryDns' => 'ns2.example.net',
                    'localDomain' => [
                        'domainName' => 'example.bd',
                        'orderId' => '01ORDER',
                        'orderStatus' => $active ? 'COMPLETED' : 'PENDING',
                        'isActive' => $active,
                        'activationDate' => $active ? '2026-07-30T10:00:00Z' : null,
                        'expiryDate' => $active ? '2027-07-30T10:00:00Z' : null,
                    ],
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/orders/01ORDER/process') {
            $active = true;
            return response(200, [
                'success' => true,
                'data' => ['id' => '01ORDER', 'status' => 'PROCESSING'],
            ]);
        }
        if ($method === 'GET' && $path === '/documents') {
            return response(200, ['success' => true, 'data' => ['documents' => []]]);
        }

        throw new RuntimeException("Unexpected request: {$method} {$path}");
    });
    $module = makeModule($transport);

    $sync = $module->sync(['domain' => 'example.bd']);
    assertSameValue('active', $sync['status'], 'The approved order did not activate during polling.');
    assertSameValue('2027-07-30', $sync['endtime'], 'The registry expiry was not synchronized.');

    $result = $module->register(
        'example.bd',
        'example',
        'bd',
        1,
        nameservers(),
        registrant()
    );
    assertSameValue('SUCCESS', $result['status'], 'Idempotent activation did not return success.');
    assertTrueValue(
        $result['change']['options']['dns_manage'],
        'Activation did not enable the WISECP DNS tab capability.'
    );
    assertSameValue(
        'ns1.example.net',
        $result['change']['options']['ns1'],
        'Activation did not synchronize the first nameserver into WISECP options.'
    );
    assertFalseValue(
        $result['change']['options']['whois_manage'],
        'Activation exposed unsupported WHOIS mutation.'
    );
    assertSameValue(0, $transport->count('POST', '/orders'), 'The active domain created a duplicate order.');
};

$tests['resumes a persisted pending order when domain lookup cannot find the reservation'] = function (): void {
    $documents = bdDocuments();
    $frontName = basename($documents['nid_front']);
    $backName = basename($documents['nid_back']);
    $transport = new FakeGetBDTransport(
        function (array $request) use ($frontName, $backName): array {
            $method = $request['method'];
            $path = parse_url($request['url'], PHP_URL_PATH);

            if ($method === 'GET' && $path === '/domains/info') {
                return response(404, ['success' => false, 'message' => 'Domain not found']);
            }
            if ($method === 'GET' && $path === '/orders/01PERSISTED') {
                return response(200, [
                    'success' => true,
                    'data' => [
                        'id' => '01PERSISTED',
                        'domainName' => 'example.bd',
                        'status' => 'PENDING',
                    ],
                ]);
            }
            if ($method === 'GET' && $path === '/documents') {
                return response(200, [
                    'success' => true,
                    'data' => [
                        'documents' => [
                            ['documentType' => 'NID', 'originalName' => $frontName],
                            ['documentType' => 'NID', 'originalName' => $backName],
                        ],
                    ],
                ]);
            }
            if ($method === 'POST' && $path === '/orders/01PERSISTED/process') {
                return response(400, [
                    'success' => false,
                    'message' => 'Order must have at least 2 APPROVED documents before processing.',
                ]);
            }

            throw new RuntimeException("Unexpected request: {$method} {$path}");
        }
    );
    $module = makeModule($transport, $documents);
    $module->set_order([
        'id' => 43,
        'options' => [
            'domain' => 'example.bd',
            'config' => [
                'getbd_order_id' => '01PERSISTED',
                'getbd_status' => 'awaiting',
            ],
        ],
    ]);

    try {
        $result = $module->register(
            'example.bd',
            'example',
            'bd',
            1,
            nameservers(),
            registrant()
        );

        assertSameValue('FAIL', $result['status'], 'The persisted pending order did not remain awaiting.');
        assertSameValue(0, $transport->count('POST', '/orders'), 'A duplicate order was created.');
        assertSameValue(0, $transport->count('POST', '/documents/upload'), 'Existing documents were duplicated.');
        assertSameValue(
            1,
            $transport->count('POST', '/orders/01PERSISTED/process'),
            'The existing order was not resumed.'
        );
    } finally {
        removeDocuments($documents);
    }
};

$tests['maps inactive pending and explicit expired domains correctly'] = function (): void {
    $expired = false;
    $transport = new FakeGetBDTransport(function (array $request) use (&$expired): array {
        $path = parse_url($request['url'], PHP_URL_PATH);
        if ($path !== '/domains/info') {
            throw new RuntimeException("Unexpected request: {$request['method']} {$path}");
        }

        return response(200, [
            'success' => true,
            'data' => [
                'localDomain' => [
                    'domainName' => 'example.bd',
                    'orderId' => '01ORDER',
                    'orderStatus' => $expired ? 'COMPLETED' : 'PROCESSING',
                    'isActive' => false,
                    'activationDate' => $expired ? '2020-01-01' : null,
                    'expiryDate' => $expired ? '2021-01-01' : null,
                ],
            ],
        ]);
    });
    $module = makeModule($transport);

    $pending = $module->sync(['domain' => 'example.bd']);
    assertSameValue('awaiting', $pending['status'], 'A pending domain was classified as expired.');

    $expired = true;
    $actualExpired = $module->sync(['domain' => 'example.bd']);
    assertSameValue('expired', $actualExpired['status'], 'An explicitly elapsed active term was not expired.');
};

$tests['verifies renewal expiry and avoids a duplicate renewal call'] = function (): void {
    $expiry = '2027-07-30';
    $transport = new FakeGetBDTransport(function (array $request) use (&$expiry): array {
        $method = $request['method'];
        $path = parse_url($request['url'], PHP_URL_PATH);
        if ($method === 'GET' && $path === '/domains/info') {
            return response(200, [
                'success' => true,
                'data' => [
                    'localDomain' => [
                        'domainName' => 'example.bd',
                        'orderId' => '01ORDER',
                        'isActive' => true,
                        'activationDate' => '2026-07-30',
                        'expiryDate' => $expiry,
                    ],
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/domains/renew') {
            $expiry = '2028-07-30';
            return response(200, ['success' => true]);
        }

        throw new RuntimeException("Unexpected request: {$method} {$path}");
    });
    $module = makeModule($transport);

    $first = $module->renewal(
        [],
        'example.bd',
        'example',
        'bd',
        1,
        '2027-07-30',
        '2028-07-30'
    );
    assertSameValue('2028-07-30', $first['change']['duedate'], 'Renewal did not use registry expiry.');

    $second = $module->renewal(
        [],
        'example.bd',
        'example',
        'bd',
        1,
        '2027-07-30',
        '2028-07-30'
    );
    assertTrueValue((bool) $second, 'An already-renewed domain did not return success.');
    assertSameValue(1, $transport->count('POST', '/domains/renew'), 'Renewal was charged twice.');
};

$tests['stores an asynchronous renewal marker without advancing the local due date'] = function (): void {
    $transport = new FakeGetBDTransport(function (array $request): array {
        $method = $request['method'];
        $path = parse_url($request['url'], PHP_URL_PATH);
        if ($method === 'GET' && $path === '/domains/info') {
            return response(200, [
                'success' => true,
                'data' => [
                    'localDomain' => [
                        'domainName' => 'example.bd',
                        'orderId' => '01ORDER',
                        'isActive' => true,
                        'activationDate' => '2026-07-30',
                        'expiryDate' => '2027-07-30',
                    ],
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/domains/renew') {
            return response(202, ['success' => true, 'message' => 'Accepted']);
        }

        throw new RuntimeException("Unexpected request: {$method} {$path}");
    });
    $module = makeModule($transport);

    $result = $module->renewal(
        [],
        'example.bd',
        'example',
        'bd',
        1,
        '2027-07-30',
        '2028-07-30'
    );

    assertSameValue('2027-07-30', $result['change']['duedate'], 'Pending renewal advanced the local due date.');
    assertSameValue(
        '2028-07-30',
        $result['change']['options']['getbd_pending_renewal']['target_expiry'],
        'Pending renewal target was not persisted.'
    );
};

$tests['validates and verifies nameserver updates'] = function (): void {
    $transport = new FakeGetBDTransport(function (array $request): array {
        $method = $request['method'];
        $path = parse_url($request['url'], PHP_URL_PATH);
        if ($method === 'PUT' && $path === '/domains/update') {
            return response(200, ['success' => true]);
        }
        if ($method === 'GET' && $path === '/domains/info') {
            return response(200, [
                'success' => true,
                'data' => [
                    'primaryDns' => 'NS1.EXAMPLE.NET.',
                    'secondaryDns' => 'ns2.example.net',
                    'localDomain' => [
                        'domainName' => 'example.bd',
                        'orderId' => '01ORDER',
                        'isActive' => true,
                        'activationDate' => '2026-07-30',
                        'expiryDate' => '2027-07-30',
                    ],
                ],
            ]);
        }

        throw new RuntimeException("Unexpected request: {$method} {$path}");
    });
    $module = makeModule($transport);

    assertTrueValue(
        $module->ModifyDns(['domain' => 'example.bd'], nameservers()),
        'A verified nameserver update failed.'
    );
    assertSameValue(1, $transport->count('PUT', '/domains/update'), 'Nameservers were not submitted once.');
};

$tests['rejects invalid phone and nameserver input before any API mutation'] = function (): void {
    $transport = new FakeGetBDTransport(function (array $request): array {
        $method = $request['method'];
        $path = parse_url($request['url'], PHP_URL_PATH);
        if ($method === 'GET' && $path === '/domains/info') {
            return response(404, ['success' => false, 'message' => 'Domain not found']);
        }
        if ($method === 'GET' && in_array($path, ['/orders', '/domains'], true)) {
            return response(200, ['success' => true, 'data' => []]);
        }

        throw new RuntimeException("No mutating API request should have been made: {$method} {$path}");
    });
    $documents = bdDocuments();
    $module = makeModule($transport, $documents);

    try {
        $result = $module->register(
            'example.bd',
            'example',
            'bd',
            1,
            ['ns1' => 'only-one.example.net'],
            registrant(['Phone' => '111'])
        );
        assertFalseValue($result, 'Invalid registration input was accepted.');
        assertSameValue(0, $transport->count('POST', '/orders'), 'Invalid input created an order.');
        assertSameValue(0, $transport->count('PUT', '/domains/update'), 'Invalid input changed nameservers.');
    } finally {
        removeDocuments($documents);
    }
};

$tests['enables only GetBD-owned TLD client capabilities'] = function (): void {
    eval('class Models { public static $init; }');
    $database = new FakeCapabilityDatabase(
        [
            [
                'id' => 1,
                'name' => 'bd',
                'module' => 'GetBD',
                'dns_manage' => 0,
                'paperwork' => 1,
                'epp_code' => 1,
                'whois_privacy' => 1,
            ],
            [
                'id' => 2,
                'name' => 'com.bd',
                'module' => 'OtherRegistrar',
                'dns_manage' => 0,
                'paperwork' => 1,
                'epp_code' => 1,
                'whois_privacy' => 1,
            ],
            [
                'id' => 3,
                'name' => 'net.bd',
                'module' => '',
                'dns_manage' => 0,
                'paperwork' => 1,
                'epp_code' => 1,
                'whois_privacy' => 1,
            ],
        ],
        [
            ['product_id' => 3],
        ]
    );
    Models::$init = (object) ['db' => $database];

    assertTrueValue(
        GetBD::synchronizeClientCapabilities(require dirname(__DIR__) . '/config.php'),
        'The WISECP client capability synchronization failed.'
    );
    assertSameValue(2, count($database->updates), 'An unrelated registrar TLD was modified.');
    assertSameValue(1, $database->updates[0]['id'], 'The GetBD-owned .bd TLD was not updated.');
    assertSameValue(3, $database->updates[1]['id'], 'The TLD referenced by a GetBD order was not repaired.');
    assertSameValue(
        [
            'dns_manage' => 1,
            'paperwork' => 0,
            'epp_code' => 0,
            'whois_privacy' => 0,
        ],
        $database->updates[0]['values'],
        'The client capability set does not match GetBD API support.'
    );
};

$passed = 0;
$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        $passed++;
        echo "[PASS] {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "[FAIL] {$name}\n";
        echo '       ' . str_replace("\n", "\n       ", $e->getMessage()) . "\n";
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
