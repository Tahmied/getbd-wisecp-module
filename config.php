<?php

return [
    'meta' => [
        'name' => 'Get BD',
        'version' => '2.1.0',
        'logo' => 'logo.png',
    ],
    'settings' => [
        // The credential is intentionally left unchanged for this release.
        'api_key' => 'bn_live_ko2brkrcciginzbxobbiebb83rytzjpm',
        'sandbox_mode' => false,

        // Get BD v2 currently applies to order/customer creation. All other
        // documented legacy operations remain on v1.
        'v1-base-url' => 'https://api.get.bd/api/v1/external',
        'v2-base-url' => 'https://api.get.bd/api/v2/external',
        'sandbox-v1-base-url' => 'https://sandbox-api.get.bd/api/v1/external',
        'sandbox-v2-base-url' => 'https://sandbox-api.get.bd/api/v2/external',
        'connect-timeout' => 10,
        'request-timeout' => 30,
        // Get BD may apply accepted nameserver changes asynchronously.
        'nameserver-readback-required' => false,

        // Hosted DNS record CRUD, transfer, EPP, privacy, and WHOIS mutation
        // are intentionally not advertised because they are absent from the
        // supplied Get BD external API contract.
        'whois-types' => false,
        'dns-record-types' => [],

        'document-max-bytes' => 10485760,
        'document-extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
        'document-mime-types' => [
            'image/jpeg',
            'image/png',
            'application/pdf',
        ],
        'minimum-documents' => [
            'bd' => 2,
            'com.bd' => 2,
            'net.bd' => 2,
            'edu.bd' => 2,
        ],
        'doc-fields' => [
            'bd' => [
                'nid_number' => [
                    'name' => 'National ID Number (NID)',
                    'description' => 'Enter the registrant’s 10, 13, or 17 digit NID number.',
                    'type' => 'text',
                    'payload_field' => 'nid',
                    'required' => true,
                ],
                'nid_front' => [
                    'name' => 'NID Front',
                    'description' => 'Upload a clear JPG, PNG, or PDF copy of the front of the NID.',
                    'type' => 'file',
                    'document_type' => 'NID',
                    'required' => true,
                ],
                'nid_back' => [
                    'name' => 'NID Back',
                    'description' => 'Upload a clear JPG, PNG, or PDF copy of the back of the NID.',
                    'type' => 'file',
                    'document_type' => 'NID',
                    'required' => true,
                ],
                'passport' => [
                    'name' => 'Passport',
                    'description' => 'Optional passport copy.',
                    'type' => 'file',
                    'document_type' => 'PASSPORT',
                    'required' => false,
                ],
                'trade_license' => [
                    'name' => 'Trade License',
                    'description' => 'Optional trade license for an organization.',
                    'type' => 'file',
                    'document_type' => 'TRADE_LICENSE',
                    'required' => false,
                ],
            ],
            'com.bd' => [
                'nid_number' => [
                    'name' => 'Authorized Person’s NID Number',
                    'description' => 'Enter the authorized person’s 10, 13, or 17 digit NID number.',
                    'type' => 'text',
                    'payload_field' => 'nid',
                    'required' => true,
                ],
                'nid_document' => [
                    'name' => 'Authorized Person’s NID',
                    'description' => 'Upload the authorized person’s NID document.',
                    'type' => 'file',
                    'document_type' => 'NID',
                    'required' => true,
                ],
                'trade_license' => [
                    'name' => 'Trade License',
                    'description' => 'Upload the organization’s current trade license.',
                    'type' => 'file',
                    'document_type' => 'TRADE_LICENSE',
                    'required' => true,
                ],
            ],
            'net.bd' => [
                'nid_number' => [
                    'name' => 'Authorized Person’s NID Number',
                    'description' => 'Enter the authorized person’s 10, 13, or 17 digit NID number.',
                    'type' => 'text',
                    'payload_field' => 'nid',
                    'required' => true,
                ],
                'nid_document' => [
                    'name' => 'Authorized Person’s NID',
                    'description' => 'Upload the authorized person’s NID document.',
                    'type' => 'file',
                    'document_type' => 'NID',
                    'required' => true,
                ],
                'authorization_letter' => [
                    'name' => 'Authorization Letter',
                    'description' => 'Upload the applicable authorization or licensing document.',
                    'type' => 'file',
                    'document_type' => 'OTHER',
                    'required' => true,
                ],
                'other' => [
                    'name' => 'Additional Supporting Document',
                    'description' => 'Optional additional supporting document.',
                    'type' => 'file',
                    'document_type' => 'OTHER',
                    'required' => false,
                ],
            ],
            'edu.bd' => [
                'nid_number' => [
                    'name' => 'Authorized Person’s NID Number',
                    'description' => 'Enter the authorized person’s 10, 13, or 17 digit NID number.',
                    'type' => 'text',
                    'payload_field' => 'nid',
                    'required' => true,
                ],
                'nid_document' => [
                    'name' => 'Authorized Person’s NID',
                    'description' => 'Upload the authorized person’s NID document.',
                    'type' => 'file',
                    'document_type' => 'NID',
                    'required' => true,
                ],
                'institution_document' => [
                    'name' => 'Institution Approval Document',
                    'description' => 'Upload the government approval, registration, or affiliation document.',
                    'type' => 'file',
                    'document_type' => 'OTHER',
                    'required' => true,
                ],
                'other' => [
                    'name' => 'Additional Supporting Document',
                    'description' => 'Optional additional supporting document.',
                    'type' => 'file',
                    'document_type' => 'OTHER',
                    'required' => false,
                ],
            ],
        ],

        'renewal-pending-timeout-hours' => 72,
        'renewal-reconcile-batch' => 100,
        'whidden-amount' => 0.0,
        'whidden-currency' => '4',
        'adp' => false,
        'cost-currency' => 4,
    ],
];
