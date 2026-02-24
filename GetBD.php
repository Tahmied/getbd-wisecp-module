<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/lib/GetBDClient.php';

use GetBD\GetBDClient;

class GetBD extends RegistrarModule
{

    public $config = [];
    public $error  = null;

    function __construct()
    {
        parent::__construct(__CLASS__);
    }

    public function config_fields($data = []): array
    {
        return [
            'api_key' => [
                'name'        => "API Key",
                'type'        => "password",
                'value'       => $data["api_key"] ?? '',
                'placeholder' => "Enter your Get BD API Key",
            ],
            'sandbox_mode' => [
                'name'        => "Sandbox Mode",
                'type'        => "approval",
                'description' => "Enable Sandbox Mode",
                'checked'     => $data["sandbox_mode"] ?? false,
            ],
        ];
    }

    private function getClient()
    {
        return new GetBDClient([
            'APIKey'      => $this->config["settings"]["api_key"] ?? '',
            'SandboxMode' => !empty($this->config["settings"]["sandbox_mode"]) ? 'on' : 'off'
        ]);
    }

    public function testConnection($config = [])
    {
        $this->config = $config;
        try {
            $this->getClient()->validateAPIKey();
            return true;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function questioning($sld = NULL, $tlds = [])
    {
        $sld = idn_to_ascii($sld, 0, INTL_IDNA_VARIANT_UTS46);
        if (!is_array($tlds)) $tlds = [$tlds];

        $result = [];
        $client = $this->getClient();

        foreach ($tlds as $tld) {
            $domainName = $sld . '.' . $tld;
            try {
                $response = $client->searchDomain($domainName);
                if (isset($response['data']['available']) && $response['data']['available']) {
                    $result[$tld] = ['status' => 'available'];
                } else {
                    $result[$tld] = ['status' => 'unavailable'];
                }
            } catch (\Exception $e) {
                $result[$tld] = ['status' => 'error'];
            }
        }
        return $result;
    }

    public function register($domain = '', $sld = '', $tld = '', $year = 1, $dns = [], $whois = [], $wprivacy = false, $eppCode = '')
    {
        $domainName = $sld . '.' . $tld;

        $fullName = $whois['registrant']['Name'] ?? '';
        $email    = $whois['registrant']['EMail'] ?? '';

        $address = trim(implode(', ', array_filter([
            $whois['registrant']['AddressLine1'] ?? '',
            $whois['registrant']['AddressLine2'] ?? '',
            $whois['registrant']['City'] ?? '',
            $whois['registrant']['State'] ?? '',
            $whois['registrant']['ZipCode'] ?? '',
            $whois['registrant']['Country'] ?? ''
        ])));

        $contactRaw = ($whois['registrant']['PhoneCountryCode'] ?? '') . ($whois['registrant']['Phone'] ?? '');
        $digits = preg_replace('/\D+/', '', $contactRaw);
        if (strpos($digits, '880') === 0) $digits = substr($digits, 3);
        if (strpos($digits, '0') === 0)   $digits = substr($digits, 1);

        $contact = '+880' . substr($digits, 0, 10);
        if (strlen($contact) < 14) {
            $this->error = 'Invalid Bangladeshi contact number.';
            return false;
        }

        $nid = '';
        $require_docs = $this->config["settings"]["doc-fields"][$tld] ?? [];

        if (!empty($require_docs['nid'])) {


            $rawDocs = $this->docs ?? [];
            $rawNid = $rawDocs['nid'] ?? '';


            if (is_array($rawNid)) {
                $rawNid = reset($rawNid);
            }

            $nid = preg_replace('/\D+/', '', (string) $rawNid);

            if (!in_array(strlen($nid), [10, 13, 17], true)) {

                $allData = json_encode(['docs' => $rawDocs, 'whois' => $whois]);
                $this->error = "DEBUG DATA - Parsed NID: '{$nid}' | Raw Data: {$allData}";
                return false;
            }
        }

        $nameservers = array_slice(array_values($dns), 0, 3);

        try {
            $response = $this->getClient()->registerDomain($domainName, $year, $fullName, $nid, $email, $address, $contact, $nameservers);

            if (isset($response['error'])) {
                $this->error = $response['error'];
                return false;
            }

            return ['status' => 'SUCCESS'];
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function renewal($params = [], $domain = '', $sld = '', $tld = '', $year = 1, $oduedate = '', $nduedate = '')
    {
        $domainName = $sld . '.' . $tld;
        try {
            $response = $this->getClient()->renewDomain($domainName, $year);
            if (isset($response['error'])) {
                $this->error = $response['error'];
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function transfer($domain = '', $sld = '', $tld = '', $year = 1, $dns = [], $whois = [], $wprivacy = false, $eppCode = '')
    {
        $this->error = 'Domain transfer is not supported via Get BD. Please process transfers manually.';
        return false;
    }

    public function ModifyDns($params = [], $dns = [])
    {
        $domainName = idn_to_ascii($params["domain"], 0, INTL_IDNA_VARIANT_UTS46);
        $nameservers = array_slice(array_values($dns), 0, 3);

        try {
            $response = $this->getClient()->updateNameservers($domainName, $nameservers);
            if (isset($response['error'])) {
                $this->error = $response['error'];
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function get_info($params = [])
    {
        $domainName = idn_to_ascii($params["domain"], 0, INTL_IDNA_VARIANT_UTS46);

        try {
            $response = $this->getClient()->getDomainInfo($domainName);
            if (!is_array($response) || empty($response['success']) || empty($response['data'])) {
                $this->error = 'Unable to retrieve domain information';
                return false;
            }

            $data = $response['data'];
            $localDomain = $data['localDomain'] ?? [];
            $expiryDate = substr($localDomain['expiryDate'], 0, 10);
            $fullName = $data['clientFullName'] ?? 'N/A';
            $email    = $data['clientEmail'] ?? '';
            $phone    = $data['clientContactNumber'] ?? '';
            $nid      = $data['clientNid'] ?? '';

            $nameParts = explode(' ', $fullName, 2);
            $firstName = $nameParts[0];
            $lastName  = $nameParts[1] ?? '';

            $contact = [
                'FirstName'         => $firstName,
                'LastName'          => $lastName,
                'Name'              => $fullName,
                'Company'           => $nid ? 'NID: ' . $nid : 'N/A',
                'EMail'             => $email,
                'Country'           => 'BD',
                'City'              => '',
                'State'             => '',
                'AddressLine1'      => '',
                'AddressLine2'      => '',
                'ZipCode'           => '',
                'PhoneCountryCode'  => '880',
                'Phone'             => str_replace('+880', '', $phone),
                'FaxCountryCode'    => '',
                'Fax'               => '',
            ];

            return [
                'creation_time' => substr($localDomain['activationDate'] ?? '', 0, 10),
                'end_time'      => $expiryDate,
                'ns1'           => $data['primaryDns'] ?? '',
                'ns2'           => $data['secondaryDns'] ?? '',
                'ns3'           => $data['tertiaryDns'] ?? '',
                'transferlock'  => true,
                'whois'         => [
                    'registrant'     => $contact,
                    'administrative' => $contact,
                    'technical'      => $contact,
                    'billing'        => $contact,
                ]
            ];
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function sync($params = [])
    {
        $domainName = idn_to_ascii($params["domain"], 0, INTL_IDNA_VARIANT_UTS46);

        try {
            $response = $this->getClient()->getDomainInfo($domainName);
            if (!is_array($response) || empty($response['success']) || empty($response['data'])) {
                return false;
            }

            $localDomain = $response['data']['localDomain'] ?? [];

            return [
                'creationtime' => substr($localDomain['activationDate'] ?? '', 0, 10),
                'endtime'      => substr($localDomain['expiryDate'] ?? '', 0, 10),
                'status'       => !empty($localDomain['isActive']) ? 'active' : 'expired',
            ];
        } catch (\Throwable $e) {
            return false;
        }
    }

    // Create the View Order button in the Admin Panel
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
        $domainName = idn_to_ascii($params["domain"], 0, INTL_IDNA_VARIANT_UTS46);

        try {
            $response = $this->getClient()->getDomainInfo($domainName);
            $localDomain = $response['data']['localDomain'] ?? [];

            if (empty($localDomain['orderId'])) {
                $this->error = "Order ID not found.";
                return false;
            }

            $isSandbox = !empty($this->config["settings"]["sandbox_mode"]);
            $baseUrl = $isSandbox ? 'https://sandbox.get.bd' : 'https://partner.get.bd';

            Utility::redirect($baseUrl . '/orders/' . $localDomain['orderId']);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }
}
