<?php
return [
    'meta'     => [
        'name'    => 'Get BD',
        'version' => '1.0',
        'logo'    => 'logo.png',
    ],
    'settings' => [
        'api_key'        => 'bn_live_8vgtr8iiurysfr5tqpn7sh5ih59ljxbh',
        'sandbox_mode'   => false,
        'doc-fields'     => [
            'bd'     => [
                'nid'                  => [
                    'name'        => 'National ID (NID)',
                    'description' => 'Upload a photo/scan of your NID (JPG, PNG, PDF).',
                    'type'        => 'text',
                    'api_type'    => 'NID',
                    'required'    => true,
                ],
                'passport'             => [
                    'name'        => 'Passport',
                    'description' => 'Upload a photo/scan of your Passport (JPG, PNG, PDF).',
                    'type'        => 'file',
                    'api_type'    => 'PASSPORT',
                    'required'    => false,
                ],
                'trade_license'        => [
                    'name'        => 'Trade License',
                    'description' => 'Upload your Trade License document (JPG, PNG, PDF).',
                    'type'        => 'file',
                    'api_type'    => 'TRADE_LICENSE',
                    'required'    => false,
                ],
                'authorization_letter' => [
                    'name'        => 'Authorization Letter',
                    'description' => 'Upload an Authorization Letter if applicable (JPG, PNG, PDF).',
                    'type'        => 'file',
                    'api_type'    => 'OTHER',
                    'required'    => false,
                ],
                'other'                => [
                    'name'        => 'Other Document',
                    'description' => 'Upload any other supporting document (JPG, PNG, PDF).',
                    'type'        => 'file',
                    'api_type'    => 'OTHER',
                    'required'    => false,
                ],
            ],
            'com.bd' => [
                'nid' => [
                    'name'        => 'National ID Number (NID)',
                    'description' => 'Enter your 10, 13, or 17 digit NID number.',
                    'type'        => 'text',
                    'api_type'    => 'NID',
                    'required'    => true,
                ],
            ],
            'net.bd' => [
                'nid' => [
                    'name'        => 'National ID Number (NID)',
                    'description' => 'Enter your 10, 13, or 17 digit NID number.',
                    'type'        => 'text',
                    'api_type'    => 'NID',
                    'required'    => true,
                ],
            ],
        ],
        'whidden-amount'   => 0.0,
        'whidden-currency' => '4',
        'adp'              => false,
        'cost-currency'    => 4,
    ],
];
