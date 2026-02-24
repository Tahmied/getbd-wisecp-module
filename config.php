<?php 
return [
    'meta'     => [
        'name'    => 'Get BD',
        'version' => '1.0',
        'logo'    => 'logo.png',
    ],
    'settings' => [
        'api_key'          => 'bn_live_8vgtr8iiurysfr5tqpn7sh5ih59ljxbh',
        'sandbox_mode'     => false,
        'doc-fields'       => [
            'com.bd' => [
                'nid' => [
                    'name'        => 'National ID (NID)',
                    'description' => 'Please enter your 10, 13, or 17 digit NID Number.',
                    'type'        => 'text',
                    'required'    => true,
                ],
            ],
            'net.bd' => [
                'nid' => [
                    'name'        => 'National ID (NID)',
                    'description' => 'Please enter your 10, 13, or 17 digit NID Number.',
                    'type'        => 'text',
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
