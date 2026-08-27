<?php

return [
    'invoice_prefix' => 'INV',
    'default_payment_terms_days' => 30,

    // Memo stamped on NON-recurring invoices (profile_id null) at QBO push time
    // so a payment processor's memo skip rule can exclude them from
    // autopay. Customer-visible on the QBO invoice — pick wording that reads as
    // customer-facing text. Null disables stamping entirely. The wording may
    // span several lines but must not contain a BLANK line: a blank line
    // separates two entries in the retired list below, so a wording holding one
    // could never be retired as itself. Such a value is logged and NOT stamped,
    // rather than stamped into something no rotation can remove cleanly.
    'qbo_nonrecurring_skip_memo' => env('QBO_NONRECURRING_SKIP_MEMO'),

    // Skip memos this app stamped in the past, SEPARATED BY A BLANK LINE — in
    // .env, an empty line inside the double-quoted value. A wording may itself
    // span several lines (the memo matcher recognises it as a whole block), so
    // a single line break belongs to one wording and never separates two: a
    // per-line list would arm the lines of a multi-line wording as strip
    // targets and delete operator-typed memo lines that match one. A `\n`
    // escape is not expanded by phpdotenv and arrives literally; it is read as
    // a line break inside a wording, so `\n\n` separates two. Not
    // comma-separated — the wording above is free-form customer-facing prose
    // and may contain a comma, so only a blank line can delimit the list
    // without shredding it.
    // A QBO full update rewrites CustomerMemo wholesale, so a stamp can only
    // be removed while we still recognise it: when changing the wording above
    // — or clearing it to turn stamping off — move the old wording here so
    // already-stamped open invoices are unstamped on their next push instead
    // of keeping an autopay exemption nobody can revoke.
    // UPGRADING: this list used to be ONE PER LINE. A blank line now separates
    // entries and a single line break no longer does, so a value already
    // written one per line reads as ONE multi-line wording that matches nothing
    // and strips nothing. If your current value holds several wordings on
    // consecutive lines, insert a blank line between them when you upgrade, or
    // those old stamps stay on already-stamped invoices forever.
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
