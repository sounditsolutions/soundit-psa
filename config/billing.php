<?php

return [
    'invoice_prefix' => 'INV',
    'default_payment_terms_days' => 30,

    'quantity_sources' => [
        'per_workstation' => [
            'label' => 'Per Workstation',
            'asset_types' => [
                'WINDOWS_WORKSTATION',  // NinjaRMM
                'MAC',                  // NinjaRMM
                'Desktop',              // Halo
                'Laptop',               // Halo
                'Workstation',          // Halo
                'All-In-One Computer',  // Halo
            ],
        ],
        'per_server' => [
            'label' => 'Per Server',
            'asset_types' => [
                'WINDOWS_SERVER',       // NinjaRMM
                'Server',               // Halo
            ],
        ],
        'per_user' => [
            'label' => 'Per User',
            'source' => 'people',
        ],
    ],
];
