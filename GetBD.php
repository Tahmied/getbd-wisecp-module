<?php

require_once __DIR__ . '/lib/GetBDClient.php';

use GetBD\GetBDApiException;
use GetBD\GetBDClient;

class GetBD extends RegistrarModule
{
    public $config = [];
    public $error = null;

    private ?GetBDClient $client = null;
    private static bool $clientCapabilitiesSynchronized = false;

    public function __construct()
    {
        parent::__construct(__CLASS__);
        self::synchronizeClientCapabilities($this->config);
    }

    public function config_fields($data = []): array
    {
        return [
            'api_key' => [
                'name' => 'API Key',
                'type' => 'password',
                'value' => $data['api_key'] ?? '',
                'placeholder' => 'Enter your Get BD API key',
            ],
            'sandbox_mode' => [
                'name' => 'Sandbox Mode',
                'type' => 'approval',
                'description' => 'Use the Get BD sandbox API endpoints',
                'checked' => $data['sandbox_mode'] ?? false,
            ],
        ];
    }

    /**
     * Test seam for the standalone suite. WISECP does not call this method.
     */
    public function setClient(GetBDClient $client): void
    {
        $this->client = $client;
    }

    private function getClient(): GetBDClient
    {
        if ($this->client instanceof GetBDClient) {
            return $this->client;
        }

        $settings = $this->config['settings'] ?? [];
        $sandbox = !empty($settings['sandbox_mode']);
        $params = [
            'APIKey' => $settings['api_key'] ?? '',
            'SandboxMode' => $sandbox ? 'on' : 'off',
            'ConnectTimeout' => $settings['connect-timeout'] ?? 10,
            'RequestTimeout' => $settings['request-timeout'] ?? 30,
        ];

        $v1Key = $sandbox ? 'sandbox-v1-base-url' : 'v1-base-url';
        $v2Key = $sandbox ? 'sandbox-v2-base-url' : 'v2-base-url';
        if (!empty($settings[$v1Key])) {
            $params['V1BaseUrl'] = $settings[$v1Key];
        }
        if (!empty($settings[$v2Key])) {
            $params['V2BaseUrl'] = $settings[$v2Key];
        }

        $this->client = new GetBDClient($params);
        return $this->client;
    }

    public function testConnection($config = [])
    {
        $this->config = $config;
        $this->client = null;
        $this->error = null;

        try {
            $this->getClient()->validateAPIKey();
            return true;
        } catch (\Throwable $e) {
            $this->error = $this->publicError($e);
            return false;
        }
    }

    public function questioning($sld = null, $tlds = [])
    {
        $asciiSld = $this->asciiLabel((string) $sld);
        if ($asciiSld === false || $asciiSld === '') {
            $this->error = 'The domain name is invalid.';
            return false;
        }

        if (!is_array($tlds)) {
            $tlds = [$tlds];
        }

        $result = [];
        foreach ($tlds as $tld) {
            $normalizedTld = ltrim(strtolower(trim((string) $tld)), '.');
            $domain = $this->normalizeDomain($asciiSld . '.' . $normalizedTld);
            if ($domain === false) {
                $result[$tld] = ['status' => 'error'];
                continue;
            }

            try {
                $response = $this->getClient()->searchDomain($domain);
                if ($this->getClient()->responseExplicitlyFailed($response)) {
                    $result[$tld] = ['status' => 'error'];
                    continue;
                }

                $available = $this->firstValue(
                    $response,
                    [
                        ['data', 'available'],
                        ['available'],
                        ['data', 'isAvailable'],
                        ['isAvailable'],
                    ]
                );
                $result[$tld] = [
                    'status' => $this->toBool($available) ? 'available' : 'unavailable',
                ];
            } catch (\Throwable $e) {
                $result[$tld] = ['status' => 'error'];
            }
        }

        return $result;
    }

    public function register(
        $domain = '',
        $sld = '',
        $tld = '',
        $year = 1,
        $dns = [],
        $whois = [],
        $wprivacy = false,
        $eppCode = ''
    ) {
        $this->error = null;
        $domainName = $this->normalizeDomain(
            $domain !== '' ? (string) $domain : (string) $sld . '.' . (string) $tld
        );
        if ($domainName === false) {
            $this->error = 'The domain name is invalid.';
            return false;
        }

        try {
            $client = $this->getClient();
            $state = $this->remoteState($domainName, $this->knownOrderId());
            if ($state['status'] === 'active') {
                return $this->registrationSuccess($state);
            }
            if ($state['status'] === 'failed') {
                $this->error = $state['message'] ?: 'The existing Get BD order has failed or was cancelled.';
                return false;
            }

            $registration = $this->registrationData(
                $domainName,
                (string) $tld,
                (int) $year,
                is_array($dns) ? $dns : [],
                is_array($whois) ? $whois : []
            );
            if ($registration === false) {
                return false;
            }

            $orderId = (string) ($state['orderId'] ?? '');
            $newOrder = false;
            if ($orderId === '') {
                try {
                    $orderResponse = $client->createOrder($registration['payload']);
                    if ($client->responseExplicitlyFailed($orderResponse)) {
                        throw new \RuntimeException(
                            $client->messageFromResponse($orderResponse, 'Get BD order creation failed.')
                        );
                    }

                    $orderId = $this->extractOrderId($orderResponse);
                    if ($orderId === '') {
                        throw new \RuntimeException('Get BD created the order without returning an order ID.');
                    }
                    $newOrder = true;
                } catch (GetBDApiException $e) {
                    if ($e->getHttpStatus() !== 409) {
                        throw $e;
                    }

                    // A concurrent/manual retry may reserve the domain between
                    // the preflight lookup and POST. Resolve that remote order
                    // instead of treating the conflict as a new failure.
                    $state = $this->remoteState($domainName);
                    $orderId = (string) ($state['orderId'] ?? '');
                    if ($state['status'] === 'active') {
                        return $this->registrationSuccess($state);
                    }
                    if ($orderId === '') {
                        throw $e;
                    }
                }
            }

            if ($newOrder) {
                $this->uploadDocuments($orderId, $registration['documents']);
            } else {
                $this->uploadMissingDocuments($orderId, $registration['documents']);
            }

            $pendingMessage = $this->attemptOrderProcessing($orderId);
            $state = $this->remoteState($domainName, $orderId);
            if ($state['status'] === 'active') {
                return $this->registrationSuccess($state);
            }
            if ($state['status'] === 'failed') {
                $this->error = $state['message'] ?: 'The Get BD order failed after processing.';
                return false;
            }

            return $this->registrationPending(
                $orderId,
                $pendingMessage ?: $state['message']
            );
        } catch (\Throwable $e) {
            if ($this->isDocumentApprovalPending($e->getMessage())) {
                $state = $this->safeRemoteState($domainName);
                return $this->registrationPending(
                    (string) ($state['orderId'] ?? ''),
                    $e->getMessage()
                );
            }

            $this->error = $this->publicError($e);
            return false;
        }
    }

    public function renewal(
        $params = [],
        $domain = '',
        $sld = '',
        $tld = '',
        $year = 1,
        $oduedate = '',
        $nduedate = ''
    ) {
        $this->error = null;
        $domainName = $this->normalizeDomain(
            $domain !== '' ? (string) $domain : (string) $sld . '.' . (string) $tld
        );
        if ($domainName === false) {
            $this->error = 'The domain name is invalid.';
            return false;
        }

        $years = (int) $year;
        if ($years < 1 || $years > 10) {
            $this->error = 'The renewal period must be between 1 and 10 years.';
            return false;
        }

        try {
            $before = $this->remoteState($domainName);
            $beforeExpiry = $before['expiryDate'] ?? '';
            if ($this->dateMeetsTarget($beforeExpiry, (string) $nduedate)) {
                return $this->renewalSuccessResult($beforeExpiry);
            }

            $response = $this->getClient()->renewDomain($domainName, $years);
            if ($this->getClient()->responseExplicitlyFailed($response)) {
                throw new \RuntimeException(
                    $this->getClient()->messageFromResponse($response, 'Domain renewal failed.')
                );
            }

            $after = $this->remoteState($domainName);
            $afterExpiry = $after['expiryDate'] ?? '';
            if (
                $this->dateMeetsTarget($afterExpiry, (string) $nduedate)
                || $this->dateAdvanced($beforeExpiry, $afterExpiry)
            ) {
                return $this->renewalSuccessResult($afterExpiry);
            }

            $pending = [
                'domain' => $domainName,
                'years' => $years,
                'baseline_expiry' => $beforeExpiry,
                'target_expiry' => $this->normalizeDate((string) $nduedate),
                'requested_at' => date('Y-m-d H:i:s'),
            ];

            return [
                'change' => [
                    'duedate' => $this->normalizeDate((string) $oduedate) ?: $beforeExpiry,
                    'status_msg' => 'Get BD accepted the renewal and registry confirmation is pending.',
                    'options' => [
                        'getbd_pending_renewal' => $pending,
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            $this->restoreRenewalDueDate((string) $oduedate);
            $this->error = $this->publicError($e);
            return false;
        }
    }

    public function transfer(
        $domain = '',
        $sld = '',
        $tld = '',
        $year = 1,
        $dns = [],
        $whois = [],
        $wprivacy = false,
        $eppCode = ''
    ) {
        $this->error = 'Domain transfer is not supported by the Get BD external API.';
        return false;
    }

    /**
     * WISECP calls ModifyDns for registrar nameserver changes. It is not hosted
     * DNS-zone management.
     */
    public function ModifyDns($params = [], $dns = [])
    {
        $this->error = null;
        $domainName = $this->normalizeDomain((string) ($params['domain'] ?? ''));
        if ($domainName === false) {
            $this->error = 'The domain name is invalid.';
            return false;
        }

        $nameservers = $this->normalizeNameservers(is_array($dns) ? $dns : []);
        if ($nameservers === false) {
            return false;
        }

        try {
            $response = $this->getClient()->updateNameservers($domainName, $nameservers);
            if ($this->getClient()->responseExplicitlyFailed($response)) {
                throw new \RuntimeException(
                    $this->getClient()->messageFromResponse($response, 'Nameserver update failed.')
                );
            }

            $readbackRequired = !empty($this->config['settings']['nameserver-readback-required']);
            try {
                $state = $this->remoteState($domainName);
                $remoteNameservers = $state['nameservers'] ?? [];
                if (
                    $readbackRequired
                    && $remoteNameservers
                    && !$this->sameNameservers($nameservers, $remoteNameservers)
                ) {
                    $this->error = 'Get BD accepted the nameserver update, but the new values are not visible yet.';
                    return false;
                }
            } catch (\Throwable $readbackError) {
                if ($readbackRequired) {
                    throw $readbackError;
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->error = $this->publicError($e);
            return false;
        }
    }

    public function get_info($params = [])
    {
        $this->error = null;
        $domainName = $this->normalizeDomain((string) ($params['domain'] ?? ''));
        if ($domainName === false) {
            $this->error = 'The domain name is invalid.';
            return false;
        }

        try {
            $state = $this->remoteState($domainName, $this->knownOrderId($params));
            if (!in_array($state['status'], ['active', 'expired'], true)) {
                $this->error = $state['message'] ?: 'The domain is not active in the Get BD registry.';
                return false;
            }

            $data = $state['data'] ?? [];
            $fullName = trim((string) $this->firstValue($data, [
                ['clientFullName'],
                ['customer', 'fullName'],
                ['fullName'],
            ]));
            $email = trim((string) $this->firstValue($data, [
                ['clientEmail'],
                ['customer', 'email'],
                ['email'],
            ]));
            $phone = trim((string) $this->firstValue($data, [
                ['clientContactNumber'],
                ['customer', 'phone'],
                ['contactNumber'],
                ['phone'],
            ]));
            $nid = trim((string) $this->firstValue($data, [
                ['clientNid'],
                ['customer', 'nid'],
                ['nid'],
            ]));
            $company = trim((string) $this->firstValue($data, [
                ['clientCompanyName'],
                ['customer', 'companyName'],
                ['companyName'],
            ]));
            $address = trim((string) $this->firstValue($data, [
                ['clientContactAddress'],
                ['customer', 'address'],
                ['contactAddress'],
                ['address'],
            ]));
            $city = trim((string) $this->firstValue($data, [
                ['customer', 'city'],
                ['city'],
            ]));
            $stateName = trim((string) $this->firstValue($data, [
                ['customer', 'state'],
                ['state'],
            ]));
            $postcode = trim((string) $this->firstValue($data, [
                ['customer', 'postcode'],
                ['postcode'],
            ]));
            $country = trim((string) $this->firstValue($data, [
                ['customer', 'country'],
                ['country'],
            ]));

            [$firstName, $lastName] = $this->splitName($fullName);
            $phoneDigits = preg_replace('/\D+/', '', $phone);
            if (strpos($phoneDigits, '880') === 0) {
                $phoneDigits = substr($phoneDigits, 3);
            }

            $contact = [
                'FirstName' => $firstName,
                'LastName' => $lastName,
                'Name' => $fullName,
                'Company' => $company !== '' ? $company : ($nid !== '' ? 'NID: ' . $nid : ''),
                'EMail' => $email,
                'Country' => $this->countryCode($country),
                'City' => $city,
                'State' => $stateName,
                'AddressLine1' => $address,
                'AddressLine2' => '',
                'ZipCode' => $postcode,
                'PhoneCountryCode' => '880',
                'Phone' => $phoneDigits,
                'FaxCountryCode' => '',
                'Fax' => '',
            ];

            $result = [
                'creation_time' => $state['activationDate'] ?? '',
                'end_time' => $state['expiryDate'] ?? '',
                'transferlock' => false,
                'whois' => [
                    'registrant' => $contact,
                    'administrative' => $contact,
                    'technical' => $contact,
                    'billing' => $contact,
                ],
            ];
            foreach (array_values($state['nameservers'] ?? []) as $index => $nameserver) {
                if ($index >= 4) {
                    break;
                }
                $result['ns' . ($index + 1)] = $nameserver;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->error = $this->publicError($e);
            return false;
        }
    }

    public function sync($params = [])
    {
        $this->error = null;
        $domainName = $this->normalizeDomain((string) ($params['domain'] ?? ''));
        if ($domainName === false) {
            $this->error = 'The domain name is invalid.';
            return false;
        }

        try {
            $state = $this->remoteState($domainName, $this->knownOrderId($params));
            if ($this->shouldProcessOrder($state)) {
                $pendingMessage = $this->attemptOrderProcessing((string) $state['orderId']);
                $state = $this->remoteState($domainName, (string) $state['orderId']);
                if ($pendingMessage !== '') {
                    $state['message'] = $pendingMessage;
                }
            }

            if ($state['status'] === 'failed') {
                $this->error = $state['message'] ?: 'The Get BD order failed or was cancelled.';
                return [
                    'creationtime' => $state['activationDate'] ?? '',
                    'endtime' => $state['expiryDate'] ?? '',
                    'status' => 'unknown',
                ];
            }

            return [
                'creationtime' => $state['activationDate'] ?? '',
                'endtime' => $state['expiryDate'] ?? '',
                'status' => $state['status'],
            ];
        } catch (\Throwable $e) {
            $this->error = $this->publicError($e);
            return false;
        }
    }

    /**
     * Enables WISECP's inherited domain-import screen without duplicating the
     * large generic import implementation in RegistrarModule.
     */
    public function domains()
    {
        $this->error = null;
        $result = [];
        $seen = [];
        $page = 1;
        $maxPages = 100;

        try {
            do {
                $response = $this->getClient()->listDomains([
                    'page' => $page,
                    'limit' => 100,
                ]);
                $rows = $this->getClient()->extractCollection($response, [
                    'domains',
                    'items',
                    'results',
                    'rows',
                ]);

                foreach ($rows as $row) {
                    $domain = $this->extractDomainName($row);
                    if ($domain === '' || isset($seen[$domain])) {
                        continue;
                    }
                    $seen[$domain] = true;
                    $state = $this->stateFromData($row);
                    $result[] = [
                        'domain' => $domain,
                        'creation_date' => $state['activationDate'] ?? '',
                        'end_date' => $state['expiryDate'] ?? '',
                        'order_id' => 0,
                        'user_data' => [],
                    ];
                }

                $totalPages = (int) $this->firstValue($response, [
                    ['meta', 'totalPages'],
                    ['data', 'meta', 'totalPages'],
                ]);
                if ($totalPages < 1) {
                    $totalPages = count($rows) === 100 ? $page + 1 : $page;
                }
                $page++;
            } while ($page <= $totalPages && $page <= $maxPages);

            return $result;
        } catch (\Throwable $e) {
            $this->error = $this->publicError($e);
            return false;
        }
    }

    public function custom_admin_buttons()
    {
        return [
            'view_order' => [
                'text' => 'View Order in GetBD',
                'type' => 'blank',
            ],
        ];
    }

    public function view_order($params = [])
    {
        $this->error = null;
        $domainName = $this->normalizeDomain((string) ($params['domain'] ?? ''));
        if ($domainName === false) {
            $this->error = 'The domain name is invalid.';
            return false;
        }

        try {
            $state = $this->remoteState($domainName, $this->knownOrderId($params));
            $orderId = (string) ($state['orderId'] ?? '');
            if ($orderId === '') {
                $this->error = 'Get BD order ID was not found.';
                return false;
            }

            $isSandbox = !empty($this->config['settings']['sandbox_mode']);
            $baseUrl = $isSandbox ? 'https://sandbox.get.bd' : 'https://partner.get.bd';
            Utility::redirect($baseUrl . '/orders/' . rawurlencode($orderId));
            return true;
        } catch (\Throwable $e) {
            $this->error = $this->publicError($e);
            return false;
        }
    }

    /**
     * Reconciles asynchronous renewals that were accepted before the registry
     * expiry date became visible.
     */
    public static function reconcilePendingRenewals(): bool
    {
        if (
            !class_exists('Models')
            || !isset(Models::$init)
            || !isset(Models::$init->db)
            || !class_exists('Orders')
        ) {
            return false;
        }

        try {
            $query = Models::$init->db->select()->from('users_products');
            $query->where('type', '=', 'domain', '&&');
            $query->where('(');
            $query->where('module', '=', 'GetBD', '||');
            $query->where('module', '=', 'Get BD');
            $query->where(')');
            $rows = $query->build() ? $query->fetch_assoc() : [];
        } catch (\Throwable $e) {
            return false;
        }

        if (!$rows) {
            return true;
        }

        $module = new self();
        $limit = max(1, (int) ($module->config['settings']['renewal-reconcile-batch'] ?? 100));
        $timeoutHours = max(
            1,
            (int) ($module->config['settings']['renewal-pending-timeout-hours'] ?? 72)
        );
        $processed = 0;

        foreach ($rows as $row) {
            if ($processed >= $limit) {
                break;
            }

            $options = $row['options'] ?? [];
            if (is_string($options)) {
                $options = class_exists('Utility')
                    ? Utility::jdecode($options, true)
                    : json_decode($options, true);
            }
            if (!is_array($options) || empty($options['getbd_pending_renewal'])) {
                continue;
            }

            $pending = $options['getbd_pending_renewal'];
            $domain = (string) ($pending['domain'] ?? ($options['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }
            $processed++;

            try {
                $state = $module->remoteState($domain);
                $target = (string) ($pending['target_expiry'] ?? '');
                $baseline = (string) ($pending['baseline_expiry'] ?? '');
                $expiry = (string) ($state['expiryDate'] ?? '');

                if (
                    $module->dateMeetsTarget($expiry, $target)
                    || $module->dateAdvanced($baseline, $expiry)
                ) {
                    unset($options['getbd_pending_renewal']);
                    Orders::set((int) $row['id'], [
                        'duedate' => $expiry,
                        'options' => Utility::jencode($options),
                        'status_msg' => '',
                        'unread' => 1,
                    ]);
                    continue;
                }

                $requestedAt = strtotime((string) ($pending['requested_at'] ?? '')) ?: time();
                if ((time() - $requestedAt) >= ($timeoutHours * 3600)) {
                    Orders::set((int) $row['id'], [
                        'status_msg' => 'Get BD renewal is still awaiting registry confirmation.',
                        'unread' => 1,
                    ]);
                }
            } catch (\Throwable $e) {
                // A later hourly run will retry temporary provider/API failures.
            }
        }

        return true;
    }

    /**
     * WISECP renders the client-area DNS tab from tldlist.dns_manage, not from
     * the registrar's ModifyDns method. Keep GetBD-owned TLDs aligned with the
     * operations that the external API actually supports.
     *
     * This is intentionally scoped to TLDs already assigned to this module (or
     * referenced by an existing GetBD order). It never creates TLDs, changes
     * pricing, or takes ownership of another registrar's TLD.
     */
    public static function synchronizeClientCapabilities(array $moduleConfig = []): bool
    {
        if (self::$clientCapabilitiesSynchronized) {
            return true;
        }
        if (
            !class_exists('Models')
            || !isset(Models::$init)
            || !isset(Models::$init->db)
        ) {
            return false;
        }

        if (!$moduleConfig) {
            $moduleConfig = require __DIR__ . '/config.php';
        }
        $supportedTlds = array_keys($moduleConfig['settings']['doc-fields'] ?? []);
        $supportedTlds = array_values(array_unique(array_filter(array_map(
            static fn($tld) => ltrim(strtolower(trim((string) $tld)), '.'),
            $supportedTlds
        ))));
        if (!$supportedTlds) {
            return false;
        }

        try {
            $tldQuery = Models::$init->db->select('id,name,module,dns_manage,paperwork,epp_code,whois_privacy')
                ->from('tldlist');
            $tldRows = $tldQuery->build() ? $tldQuery->fetch_assoc() : [];

            $orderProductIds = [];
            $orderQuery = Models::$init->db->select('product_id')->from('users_products');
            $orderQuery->where('type', '=', 'domain', '&&');
            $orderQuery->where('(');
            $orderQuery->where('module', '=', 'GetBD', '||');
            $orderQuery->where('module', '=', 'Get BD');
            $orderQuery->where(')');
            if ($orderQuery->build()) {
                foreach ($orderQuery->fetch_assoc() as $orderRow) {
                    $productId = (int) ($orderRow['product_id'] ?? 0);
                    if ($productId > 0) {
                        $orderProductIds[$productId] = true;
                    }
                }
            }

            foreach ($tldRows as $tldRow) {
                $id = (int) ($tldRow['id'] ?? 0);
                $name = ltrim(strtolower(trim((string) ($tldRow['name'] ?? ''))), '.');
                if ($id < 1 || !in_array($name, $supportedTlds, true)) {
                    continue;
                }

                $moduleName = strtolower(preg_replace(
                    '/[^a-z0-9]+/i',
                    '',
                    (string) ($tldRow['module'] ?? '')
                ));
                $ownedByGetBD = $moduleName === 'getbd' || isset($orderProductIds[$id]);
                if (!$ownedByGetBD) {
                    continue;
                }

                $capabilities = [
                    'dns_manage' => 1,
                    // GetBD documents are supplied through the registrar module,
                    // so WISECP's separate manual paperwork gate must stay off.
                    'paperwork' => 0,
                    'epp_code' => 0,
                    'whois_privacy' => 0,
                ];
                $needsUpdate = false;
                foreach ($capabilities as $field => $value) {
                    if ((int) ($tldRow[$field] ?? -1) !== $value) {
                        $needsUpdate = true;
                        break;
                    }
                }
                if ($needsUpdate) {
                    Models::$init->db->update('tldlist', $capabilities)
                        ->where('id', '=', $id)
                        ->save();
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        self::$clientCapabilitiesSynchronized = true;
        return true;
    }

    private function registrationData(
        string $domain,
        string $tld,
        int $year,
        array $dns,
        array $whois
    ) {
        if ($year < 1 || $year > 10) {
            $this->error = 'The registration period must be between 1 and 10 years.';
            return false;
        }

        $registrant = $whois['registrant'] ?? [];
        if (!is_array($registrant)) {
            $registrant = [];
        }

        $fullName = trim((string) ($registrant['Name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim(
                (string) ($registrant['FirstName'] ?? '')
                . ' '
                . (string) ($registrant['LastName'] ?? '')
            );
        }
        $email = trim((string) ($registrant['EMail'] ?? ''));
        if ($fullName === '') {
            $this->error = 'The registrant full name is required.';
            return false;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error = 'The registrant email address is invalid.';
            return false;
        }

        $contactNumber = $this->normalizeBangladeshPhone(
            (string) ($registrant['PhoneCountryCode'] ?? ''),
            (string) ($registrant['Phone'] ?? '')
        );
        if ($contactNumber === false) {
            return false;
        }

        $nameservers = $this->normalizeNameservers($dns);
        if ($nameservers === false) {
            return false;
        }

        $documentData = $this->collectDocuments($this->normalizeTld($tld, $domain));
        if ($documentData === false) {
            return false;
        }

        $addressParts = [
            $registrant['AddressLine1'] ?? '',
            $registrant['AddressLine2'] ?? '',
            $registrant['City'] ?? '',
            $registrant['State'] ?? '',
            $registrant['ZipCode'] ?? '',
            $registrant['Country'] ?? '',
        ];
        $contactAddress = trim(implode(', ', array_filter(array_map('trim', $addressParts))));
        if ($contactAddress === '') {
            $this->error = 'The registrant address is required.';
            return false;
        }

        $country = trim((string) ($registrant['Country'] ?? 'Bangladesh'));
        if (strcasecmp($country, 'BD') === 0) {
            $country = 'Bangladesh';
        }

        return [
            'payload' => [
                'domainName' => $domain,
                'years' => $year,
                'fullName' => $fullName,
                'nid' => $documentData['nid'],
                'email' => $email,
                'contactAddress' => $contactAddress,
                'contactNumber' => $contactNumber,
                'nameServers' => $nameservers,
                'companyName' => trim((string) ($registrant['Company'] ?? '')),
                'city' => trim((string) ($registrant['City'] ?? '')),
                'state' => trim((string) ($registrant['State'] ?? '')),
                'postcode' => trim((string) ($registrant['ZipCode'] ?? '')),
                'country' => $country,
            ],
            'documents' => $documentData['documents'],
        ];
    }

    private function collectDocuments(string $tld)
    {
        $settings = $this->config['settings'] ?? [];
        $fields = $settings['doc-fields'][$tld] ?? [];
        $rawDocuments = is_array($this->docs ?? null) ? $this->docs : [];
        $nid = '';
        $documents = [];
        $seenFiles = [];

        foreach ($fields as $fieldKey => $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = strtolower((string) ($field['type'] ?? 'text'));
            $required = !empty($field['required']);
            $rawValue = $rawDocuments[$fieldKey] ?? null;
            if (is_array($rawValue)) {
                $rawValue = reset($rawValue);
            }
            $rawValue = is_scalar($rawValue) ? trim((string) $rawValue) : '';

            if ($type === 'text') {
                if (($field['payload_field'] ?? '') === 'nid') {
                    $parsed = preg_replace('/\D+/', '', $rawValue);
                    if ($parsed === '' && !$required) {
                        continue;
                    }
                    if (!in_array(strlen($parsed), [10, 13, 17], true)) {
                        $this->error = 'The NID number must contain 10, 13, or 17 digits.';
                        return false;
                    }
                    $nid = $parsed;
                } elseif ($required && $rawValue === '') {
                    $this->error = "Required field '{$field['name']}' was not provided.";
                    return false;
                }
                continue;
            }

            if ($type !== 'file') {
                continue;
            }
            if ($rawValue === '') {
                if ($required) {
                    $this->error = "Required document '{$field['name']}' was not uploaded.";
                    return false;
                }
                continue;
            }

            $path = $this->resolveDocumentPath($rawValue);
            if ($path === false) {
                $this->error = "Uploaded document '{$field['name']}' could not be found.";
                return false;
            }
            if (isset($seenFiles[$path])) {
                $this->error = "The same file cannot be used for both '{$seenFiles[$path]}' and '{$field['name']}'.";
                return false;
            }
            $seenFiles[$path] = $field['name'];

            if (!$this->validateDocumentFile($path, (string) $field['name'])) {
                return false;
            }

            $documentType = strtoupper(trim((string) (
                $field['document_type'] ?? $field['api_type'] ?? ''
            )));
            if ($documentType === '') {
                $this->error = "Document type is not configured for '{$field['name']}'.";
                return false;
            }

            $documents[] = [
                'field' => (string) $fieldKey,
                'type' => $documentType,
                'path' => $path,
                'name' => basename($path),
            ];
        }

        if ($nid === '') {
            $this->error = 'A valid NID number is required for Get BD registration.';
            return false;
        }

        $minimumMap = $settings['minimum-documents'] ?? [];
        $minimum = max(0, (int) ($minimumMap[$tld] ?? 2));
        if (count($documents) < $minimum) {
            $this->error = "Get BD requires at least {$minimum} supporting documents for .{$tld}.";
            return false;
        }

        return [
            'nid' => $nid,
            'documents' => $documents,
        ];
    }

    private function resolveDocumentPath(string $rawPath)
    {
        $candidates = [$rawPath];
        if (!$this->isAbsolutePath($rawPath)) {
            if (!empty($_SERVER['DOCUMENT_ROOT'])) {
                $candidates[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\')
                    . DIRECTORY_SEPARATOR
                    . ltrim($rawPath, '/\\');
            }
            if (defined('ROOT_DIR')) {
                $candidates[] = rtrim((string) ROOT_DIR, '/\\')
                    . DIRECTORY_SEPARATOR
                    . ltrim($rawPath, '/\\');
            }
            $candidates[] = getcwd() . DIRECTORY_SEPARATOR . ltrim($rawPath, '/\\');
        }

        foreach (array_unique($candidates) as $candidate) {
            $realPath = realpath($candidate);
            if ($realPath !== false && is_file($realPath) && is_readable($realPath)) {
                return $realPath;
            }
        }

        return false;
    }

    private function validateDocumentFile(string $path, string $label): bool
    {
        $settings = $this->config['settings'] ?? [];
        $maxBytes = max(1, (int) ($settings['document-max-bytes'] ?? 10485760));
        $size = filesize($path);
        if ($size === false || $size < 1) {
            $this->error = "Document '{$label}' is empty or unreadable.";
            return false;
        }
        if ($size > $maxBytes) {
            $this->error = "Document '{$label}' exceeds the maximum allowed file size.";
            return false;
        }

        $allowedExtensions = $settings['document-extensions'] ?? ['jpg', 'jpeg', 'png', 'pdf'];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $this->error = "Document '{$label}' must be a JPG, PNG, or PDF file.";
            return false;
        }

        $allowedMimes = $settings['document-mime-types'] ?? [
            'image/jpeg',
            'image/png',
            'application/pdf',
        ];
        $mime = function_exists('mime_content_type') ? mime_content_type($path) : false;
        if ($mime !== false && !in_array(strtolower($mime), $allowedMimes, true)) {
            $this->error = "Document '{$label}' has an unsupported file type.";
            return false;
        }

        return true;
    }

    private function uploadDocuments(string $orderId, array $documents): void
    {
        foreach ($documents as $document) {
            $response = $this->getClient()->uploadDocument(
                $orderId,
                (string) $document['type'],
                (string) $document['path']
            );
            if ($this->getClient()->responseExplicitlyFailed($response)) {
                throw new \RuntimeException(
                    $this->getClient()->messageFromResponse(
                        $response,
                        "Failed to upload document '{$document['field']}'."
                    )
                );
            }
            if ($this->extractDocumentId($response) === '') {
                throw new \RuntimeException(
                    "Get BD did not return a document ID for '{$document['field']}'."
                );
            }
        }
    }

    private function uploadMissingDocuments(string $orderId, array $documents): void
    {
        $remoteDocuments = $this->getClient()->listOrderDocuments($orderId);
        if ($remoteDocuments === null) {
            // Re-uploading blindly can create duplicates. Existing orders are
            // therefore resumed without mutations when listing is unavailable.
            return;
        }

        $remaining = $documents;
        foreach ($remoteDocuments as $remote) {
            $remoteType = strtoupper(trim((string) $this->firstValue($remote, [
                ['documentType'],
                ['type'],
            ])));
            $remoteName = strtolower(basename((string) $this->firstValue($remote, [
                ['originalName'],
                ['fileName'],
                ['filename'],
                ['name'],
            ])));

            foreach ($remaining as $index => $document) {
                $sameType = $remoteType !== '' && $remoteType === strtoupper((string) $document['type']);
                $sameName = $remoteName !== ''
                    && $remoteName === strtolower(basename((string) $document['name']));
                if ($sameType && ($sameName || $remoteName === '')) {
                    unset($remaining[$index]);
                    break;
                }
            }
        }

        $this->uploadDocuments($orderId, array_values($remaining));
    }

    private function attemptOrderProcessing(string $orderId): string
    {
        if ($orderId === '') {
            return 'Get BD reserved the domain but did not expose an order ID for processing.';
        }

        try {
            $response = $this->getClient()->processOrder($orderId);
            if ($this->getClient()->responseExplicitlyFailed($response)) {
                $message = $this->getClient()->messageFromResponse(
                    $response,
                    'Get BD order processing failed.'
                );
                if ($this->isDocumentApprovalPending($message) || $this->isAlreadyProcessed($message)) {
                    return $message;
                }
                throw new \RuntimeException($message);
            }

            return '';
        } catch (GetBDApiException $e) {
            if ($this->isDocumentApprovalPending($e->getMessage()) || $this->isAlreadyProcessed($e->getMessage())) {
                return $e->getMessage();
            }
            throw $e;
        }
    }

    private function remoteState(string $domain, string $knownOrderId = ''): array
    {
        $client = $this->getClient();
        $domainInfo = null;
        try {
            $domainInfo = $client->getDomainInfoIfExists($domain);
        } catch (GetBDApiException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
        }

        $state = $domainInfo !== null
            ? $this->stateFromResponse($domainInfo)
            : $this->emptyState();
        $state['domain'] = $domain;

        if ($knownOrderId !== '' && empty($state['orderId'])) {
            $state['orderId'] = $knownOrderId;
        }

        $order = null;
        $orderId = (string) ($state['orderId'] ?? '');
        if (
            $orderId !== ''
            && empty($state['orderStatus'])
            && !in_array($state['status'], ['active', 'expired'], true)
        ) {
            $orderResponse = $client->getOrder($orderId);
            if ($orderResponse !== null) {
                $order = $orderResponse['data'] ?? $orderResponse;
            }
        }
        if ($order === null && $orderId === '') {
            $order = $client->findOrderByDomain($domain);
        }
        if (is_array($order)) {
            $state = $this->mergeOrderIntoState($state, $order);
        }

        return $this->finalizeState($state);
    }

    private function safeRemoteState(string $domain): array
    {
        try {
            return $this->remoteState($domain);
        } catch (\Throwable $e) {
            return $this->emptyState();
        }
    }

    private function stateFromResponse(array $response): array
    {
        if ($this->getClient()->responseExplicitlyFailed($response)) {
            $message = $this->getClient()->messageFromResponse($response, 'Get BD domain lookup failed.');
            if (stripos($message, 'not found') !== false) {
                return $this->emptyState();
            }
            throw new \RuntimeException($message);
        }

        $data = $response['data'] ?? $response;
        return $this->stateFromData(is_array($data) ? $data : []);
    }

    private function stateFromData(array $data): array
    {
        $local = isset($data['localDomain']) && is_array($data['localDomain'])
            ? $data['localDomain']
            : $data;
        $order = isset($data['order']) && is_array($data['order'])
            ? $data['order']
            : [];

        $state = $this->emptyState();
        $state['data'] = $data;
        $state['orderId'] = trim((string) $this->firstValue($data, [
            ['localDomain', 'orderId'],
            ['order', 'id'],
            ['orderId'],
        ]));
        $state['orderStatus'] = strtoupper(trim((string) $this->firstValue($data, [
            ['order', 'status'],
            ['localDomain', 'orderStatus'],
            ['orderStatus'],
        ])));
        $state['domainStatus'] = strtoupper(trim((string) $this->firstValue($data, [
            ['localDomain', 'domainStatus'],
            ['domainStatus'],
            ['localDomain', 'registryStatus'],
            ['registryStatus'],
        ])));
        $state['activationDate'] = $this->normalizeDate((string) $this->firstValue($local, [
            ['activationDate'],
            ['registrationDate'],
            ['createdAt'],
        ]));
        $state['expiryDate'] = $this->normalizeDate((string) $this->firstValue($local, [
            ['expiryDate'],
            ['expirationDate'],
            ['expiresAt'],
        ]));
        $state['nameservers'] = $this->extractNameservers($data);
        $state['message'] = trim((string) $this->firstValue($data, [
            ['order', 'failureReason'],
            ['order', 'message'],
            ['localDomain', 'message'],
            ['message'],
        ]));

        if (array_key_exists('isActive', $local)) {
            $state['hasActiveFlag'] = true;
            $state['isActive'] = $this->toBool($local['isActive']);
        }
        if ($order && $state['orderId'] === '') {
            $state['orderId'] = trim((string) ($order['id'] ?? $order['orderId'] ?? ''));
        }

        return $this->finalizeState($state);
    }

    private function mergeOrderIntoState(array $state, array $order): array
    {
        $state['orderId'] = (string) (
            $state['orderId']
            ?: ($order['id'] ?? $order['orderId'] ?? '')
        );
        $state['orderStatus'] = strtoupper(trim((string) (
            $order['status']
            ?? $order['orderStatus']
            ?? $state['orderStatus']
        )));
        $state['message'] = trim((string) (
            $order['failureReason']
            ?? $order['message']
            ?? $state['message']
        ));

        return $state;
    }

    private function finalizeState(array $state): array
    {
        $orderStatus = strtoupper((string) ($state['orderStatus'] ?? ''));
        $domainStatus = strtoupper((string) ($state['domainStatus'] ?? ''));

        if (!empty($state['isActive']) || in_array($domainStatus, ['ACTIVE', 'REGISTERED'], true)) {
            $state['status'] = 'active';
            return $state;
        }
        if (in_array($domainStatus, ['EXPIRED'], true)) {
            $state['status'] = 'expired';
            return $state;
        }
        if (in_array($orderStatus, ['FAILED', 'CANCELLED', 'REJECTED'], true)) {
            $state['status'] = 'failed';
            return $state;
        }
        if (
            !empty($state['activationDate'])
            && !empty($state['expiryDate'])
            && strtotime($state['expiryDate'] . ' 23:59:59') < time()
        ) {
            $state['status'] = 'expired';
            return $state;
        }
        if (in_array($orderStatus, ['PENDING', 'PROCESSING', 'COMPLETED', 'AWAITING'], true)) {
            $state['status'] = 'awaiting';
            return $state;
        }
        if (!empty($state['orderId'])) {
            $state['status'] = 'awaiting';
            return $state;
        }
        if (!empty($state['hasActiveFlag']) && empty($state['isActive'])) {
            $state['status'] = empty($state['activationDate']) ? 'awaiting' : 'unknown';
            return $state;
        }

        $state['status'] = 'unknown';
        return $state;
    }

    private function emptyState(): array
    {
        return [
            'status' => 'unknown',
            'orderId' => '',
            'orderStatus' => '',
            'domainStatus' => '',
            'activationDate' => '',
            'expiryDate' => '',
            'nameservers' => [],
            'message' => '',
            'isActive' => false,
            'hasActiveFlag' => false,
            'data' => [],
        ];
    }

    private function registrationSuccess(array $state): array
    {
        $managementOptions = [
            'dns_manage' => true,
            'whois_manage' => false,
            'epp_code_manage' => false,
        ];
        foreach (array_values($state['nameservers'] ?? []) as $index => $nameserver) {
            if ($index >= 4) {
                break;
            }
            $managementOptions['ns' . ($index + 1)] = $nameserver;
        }

        $result = [
            'status' => 'SUCCESS',
            'config' => [
                'getbd_order_id' => (string) ($state['orderId'] ?? ''),
                'getbd_status' => 'active',
            ],
        ];
        $change = [
            'options' => $managementOptions,
        ];
        if (!empty($state['activationDate'])) {
            $change['cdate'] = $state['activationDate'];
        }
        if (!empty($state['expiryDate'])) {
            $change['duedate'] = $state['expiryDate'];
        }
        $result['change'] = $change;

        return $result;
    }

    private function registrationPending(string $orderId, string $message = ''): array
    {
        if ($message === '') {
            $message = 'Awaiting Get BD document verification or registry activation.';
        }
        $this->persistPendingRegistration($orderId);

        return [
            'status' => 'FAIL',
            'message' => $message,
            'config' => [
                'getbd_order_id' => $orderId,
                'getbd_status' => 'awaiting',
            ],
        ];
    }

    private function knownOrderId(array $params = []): string
    {
        $candidates = [
            $params['config']['getbd_order_id'] ?? '',
            $params['getbd_order_id'] ?? '',
        ];

        $orderOptions = $this->order['options'] ?? [];
        if (is_string($orderOptions)) {
            $orderOptions = class_exists('Utility')
                ? Utility::jdecode($orderOptions, true)
                : json_decode($orderOptions, true);
        }
        if (is_array($orderOptions)) {
            $candidates[] = $orderOptions['config']['getbd_order_id'] ?? '';
            $candidates[] = $orderOptions['getbd_order_id'] ?? '';
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * WISECP creates the activation-check event before it saves a FAIL result's
     * returned config. Persist the provider order ID here so a process restart
     * or manual activation retry remains idempotent.
     */
    private function persistPendingRegistration(string $orderId): void
    {
        $orderId = trim($orderId);
        $wisecpOrderId = (int) ($this->order['id'] ?? 0);
        if ($orderId === '' || $wisecpOrderId < 1 || !class_exists('Orders')) {
            return;
        }

        $options = $this->order['options'] ?? [];
        if (is_string($options)) {
            $options = class_exists('Utility')
                ? Utility::jdecode($options, true)
                : json_decode($options, true);
        }
        if (!is_array($options)) {
            $options = [];
        }
        if (!isset($options['config']) || !is_array($options['config'])) {
            $options['config'] = [];
        }
        $options['dns_manage'] = true;
        $options['whois_manage'] = false;
        $options['epp_code_manage'] = false;
        $options['config']['getbd_order_id'] = $orderId;
        $options['config']['getbd_status'] = 'awaiting';

        try {
            $encoded = class_exists('Utility')
                ? Utility::jencode($options)
                : json_encode($options);
            Orders::set($wisecpOrderId, ['options' => $encoded]);
            $this->order['options'] = $options;
        } catch (\Throwable $e) {
            // Remote lookup by domain remains the fallback persistence strategy.
        }
    }

    private function shouldProcessOrder(array $state): bool
    {
        if (empty($state['orderId']) || $state['status'] !== 'awaiting') {
            return false;
        }

        $orderStatus = strtoupper((string) ($state['orderStatus'] ?? ''));
        return $orderStatus === '' || in_array($orderStatus, ['PENDING', 'AWAITING'], true);
    }

    private function extractOrderId(array $response): string
    {
        return trim((string) $this->firstValue($response, [
            ['data', 'id'],
            ['data', 'orderId'],
            ['id'],
            ['orderId'],
        ]));
    }

    private function extractDocumentId(array $response): string
    {
        return trim((string) $this->firstValue($response, [
            ['data', 'id'],
            ['data', 'documentId'],
            ['id'],
            ['documentId'],
        ]));
    }

    private function extractDomainName(array $row): string
    {
        return strtolower(trim((string) $this->firstValue($row, [
            ['domainName'],
            ['domain'],
            ['localDomain', 'domainName'],
            ['localDomain', 'domain'],
            ['name'],
        ])));
    }

    private function extractNameservers(array $data): array
    {
        $nameservers = $this->firstValue($data, [
            ['nameServers'],
            ['nameservers'],
            ['localDomain', 'nameServers'],
            ['localDomain', 'nameservers'],
        ]);
        if (!is_array($nameservers)) {
            $nameservers = [];
        }

        foreach ([
            ['primaryDns'],
            ['secondaryDns'],
            ['tertiaryDns'],
            ['quaternaryDns'],
            ['localDomain', 'primaryDns'],
            ['localDomain', 'secondaryDns'],
            ['localDomain', 'tertiaryDns'],
            ['localDomain', 'quaternaryDns'],
        ] as $path) {
            $value = trim((string) $this->valueAtPath($data, $path));
            if ($value !== '') {
                $nameservers[] = $value;
            }
        }

        $normalized = [];
        foreach ($nameservers as $nameserver) {
            if (!is_scalar($nameserver)) {
                continue;
            }
            $value = strtolower(rtrim(trim((string) $nameserver), '.'));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeNameservers(array $dns)
    {
        $normalized = [];
        foreach ($dns as $nameserver) {
            if (!is_scalar($nameserver) || trim((string) $nameserver) === '') {
                continue;
            }

            $ascii = $this->asciiDomain((string) $nameserver);
            if ($ascii === false) {
                $this->error = "Invalid nameserver: {$nameserver}.";
                return false;
            }
            $ascii = strtolower(rtrim($ascii, '.'));
            if (
                !filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                || strpos($ascii, '.') === false
            ) {
                $this->error = "Invalid nameserver: {$nameserver}.";
                return false;
            }
            $normalized[] = $ascii;
        }
        $normalized = array_values(array_unique($normalized));

        if (count($normalized) < 2 || count($normalized) > 3) {
            $this->error = 'Get BD requires between two and three unique nameservers.';
            return false;
        }

        return $normalized;
    }

    private function sameNameservers(array $expected, array $actual): bool
    {
        $normalize = static function (array $values): array {
            $values = array_map(
                static fn($value) => strtolower(rtrim(trim((string) $value), '.')),
                $values
            );
            $values = array_values(array_unique(array_filter($values)));
            sort($values, SORT_STRING);
            return $values;
        };

        return $normalize($expected) === $normalize($actual);
    }

    private function normalizeBangladeshPhone(string $countryCode, string $phone)
    {
        $digits = preg_replace('/\D+/', '', $countryCode . $phone);
        if (strpos($digits, '880') === 0) {
            $digits = substr($digits, 3);
        }
        if (strpos($digits, '0') === 0) {
            $digits = substr($digits, 1);
        }
        $normalized = '+880' . $digits;

        if (!preg_match('/^\+8801[3-9]\d{8}$/', $normalized)) {
            $this->error = 'A valid Bangladeshi mobile number in +8801XXXXXXXXX format is required.';
            return false;
        }

        return $normalized;
    }

    private function normalizeDomain(string $domain)
    {
        $ascii = $this->asciiDomain($domain);
        if ($ascii === false) {
            return false;
        }
        $ascii = strtolower(rtrim(trim($ascii), '.'));
        if (
            $ascii === ''
            || strlen($ascii) > 253
            || !filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || strpos($ascii, '.') === false
        ) {
            return false;
        }

        return $ascii;
    }

    private function asciiDomain(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (function_exists('idn_to_ascii')) {
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
            $converted = idn_to_ascii($value, 0, $variant);
            return $converted === false ? false : $converted;
        }

        return preg_match('/^[\x20-\x7E]+$/', $value) ? $value : false;
    }

    private function asciiLabel(string $value)
    {
        $ascii = $this->asciiDomain($value);
        if ($ascii === false || strpos($ascii, '.') !== false) {
            return false;
        }
        return filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            ? strtolower($ascii)
            : false;
    }

    private function normalizeTld(string $tld, string $domain): string
    {
        $tld = ltrim(strtolower(trim($tld)), '.');
        if ($tld !== '') {
            return $tld;
        }

        $configured = array_keys($this->config['settings']['doc-fields'] ?? []);
        usort($configured, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($configured as $candidate) {
            if (substr($domain, -strlen('.' . $candidate)) === '.' . $candidate) {
                return $candidate;
            }
        }

        return (string) pathinfo($domain, PATHINFO_EXTENSION);
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '' || $date === '0000-00-00 00:00:00') {
            return '';
        }
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }

    private function dateMeetsTarget(string $actual, string $target): bool
    {
        $actual = $this->normalizeDate($actual);
        $target = $this->normalizeDate($target);
        return $actual !== '' && $target !== '' && strtotime($actual) >= strtotime($target);
    }

    private function dateAdvanced(string $before, string $after): bool
    {
        $before = $this->normalizeDate($before);
        $after = $this->normalizeDate($after);
        return $before !== '' && $after !== '' && strtotime($after) > strtotime($before);
    }

    private function renewalSuccessResult(string $expiry): array
    {
        $expiry = $this->normalizeDate($expiry);
        if ($expiry === '') {
            return true;
        }

        return [
            'change' => [
                'duedate' => $expiry,
                'status_msg' => '',
                'options' => [
                    'getbd_pending_renewal' => null,
                ],
            ],
        ];
    }

    private function restoreRenewalDueDate(string $oldDueDate): void
    {
        $oldDueDate = $this->normalizeDate($oldDueDate);
        $orderId = (int) ($this->order['id'] ?? 0);
        if ($oldDueDate === '' || $orderId < 1 || !class_exists('Orders')) {
            return;
        }

        try {
            Orders::set($orderId, ['duedate' => $oldDueDate]);
        } catch (\Throwable $e) {
            // The original renewal error remains the actionable failure.
        }
    }

    private function isDocumentApprovalPending(string $message): bool
    {
        $message = strtolower($message);
        return (
            strpos($message, 'approved document') !== false
            || strpos($message, 'approve documents') !== false
            || (
                strpos($message, 'document') !== false
                && strpos($message, 'pending') !== false
            )
        );
    }

    private function isAlreadyProcessed(string $message): bool
    {
        $message = strtolower($message);
        return (
            strpos($message, 'already processed') !== false
            || strpos($message, 'already completed') !== false
            || strpos($message, 'already registered') !== false
        );
    }

    private function publicError(\Throwable $error): string
    {
        $message = trim($error->getMessage());
        if ($error instanceof GetBDApiException) {
            if ($error->getHttpStatus() === 401) {
                return 'Get BD rejected the API key.';
            }
            if ($error->getHttpStatus() === 403) {
                return 'The Get BD partner account is not approved or is inactive.';
            }
            if ($error->getHttpStatus() === 429) {
                return 'Get BD API rate limit exceeded. The operation can be retried later.';
            }
            if ($error->isRetryable()) {
                return $message !== '' ? $message : 'A temporary Get BD API error occurred.';
            }
        }

        return $message !== '' ? $message : 'An unexpected Get BD registrar error occurred.';
    }

    private function firstValue(array $source, array $paths)
    {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($source, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function valueAtPath(array $source, array $path)
    {
        $value = $source;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['true', 'yes', 'active', 'success', 'available'],
            true
        );
    }

    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/', $name, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function countryCode(string $country): string
    {
        $country = strtoupper(trim($country));
        if ($country === 'BANGLADESH' || $country === '') {
            return 'BD';
        }
        return strlen($country) === 2 ? $country : 'BD';
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && (
                $path[0] === '/'
                || $path[0] === '\\'
                || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            );
    }
}

if (class_exists('Hook')) {
    Hook::add('HourlyCronJob', 40, [
        'class' => 'GetBD',
        'method::static' => 'synchronizeClientCapabilities',
    ]);
    Hook::add('HourlyCronJob', 50, [
        'class' => 'GetBD',
        'method::static' => 'reconcilePendingRenewals',
    ]);
}
