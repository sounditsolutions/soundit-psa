<?php

return [
    'invoice_prefix' => 'INV',
    'default_payment_terms_days' => 30,

    // Memo stamped on NON-recurring invoices (profile_id null) at QBO push time
    // so a payment processor's memo skip rule can exclude them from
    // autopay. Customer-visible on the QBO invoice — pick wording that reads as
    // customer-facing text. Null disables stamping entirely.
    'qbo_nonrecurring_skip_memo' => env('QBO_NONRECURRING_SKIP_MEMO'),

    // Skip memos this app stamped in the past, ONE PER LINE (in .env: a single
    // double-quoted value with `\n` between wordings). Not comma-separated —
    // the wording above is free-form customer-facing prose and may contain a
    // comma, so only a newline can delimit the list without shredding it.
    // A QBO full update rewrites CustomerMemo wholesale, so a stamp can only
    // be removed while we still recognise it: when changing the wording above
    // — or clearing it to turn stamping off — move the old wording here so
    // already-stamped open invoices are unstamped on their next push instead
    // of keeping an autopay exemption nobody can revoke.
    'qbo_nonrecurring_skip_memo_retired' => env('QBO_NONRECURRING_SKIP_MEMO_RETIRED'),

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
