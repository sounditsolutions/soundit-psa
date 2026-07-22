<?php

return [
    'categories' => [
        'Hardware' => ['Desktop', 'Laptop', 'Printer', 'Peripheral', 'Other'],
        'Software' => ['OS', 'Application', 'Driver', 'Update', 'Other'],
        'Network' => ['Connectivity', 'DNS', 'VPN', 'Firewall', 'WiFi', 'Other'],
        'Email' => ['Delivery', 'Spam', 'Calendar', 'Permissions', 'Other'],
        'User Account' => ['Password Reset', 'Permissions', 'New User', 'Offboarding', 'Other'],
        'Security' => ['Phishing', 'Malware', 'Access Review', 'Other'],
        'Server' => ['Performance', 'Storage', 'Backup', 'Service', 'Other'],
        'Cloud' => ['Microsoft 365', 'Azure', 'SaaS', 'Other'],
    ],
    'sla_hours' => [
        'p1' => 4,
        'p2' => 8,
        'p3' => 24,
        'p4' => 72,
    ],

    /*
     * so-0ftg Part 4 — coarse map from the legacy free-text classification the
     * AI triage already produces (the 'categories' menu above) onto the
     * ITIL-informed ticket_categories taxonomy. This is deliberately NOT a new
     * classifier: set_ticket_category keeps writing the legacy pair, and this
     * table says which taxonomy node that pair coarsely pins down.
     *
     * Shape: 'Legacy Category' => ['Legacy Subcategory' => [name path...]].
     * A '' key is the category-level fallback used when the subcategory is
     * absent or has no entry of its own. Paths are taxonomy node NAMES,
     * root-first (arrays, not strings — node names may contain '/').
     *
     * Resolution (TaxonomyNodeMapper) is by name, case-insensitive, with one
     * trailing "(...)" group ignored on the DB side — so a node Chet names
     * "Email security & quarantine (Mesh)" still matches the entry below.
     * Every path segment must resolve to an ACTIVE node or the pair degrades
     * to a gap (category_id stays null) — per the locked design, only
     * confident top-volume pairs are mapped and the long tail stays a visible
     * gap. If Chet's authored node names drift from these, the affected
     * entries gap out until this table is edited to match; that is the
     * intended failure mode (visible, config-fixable), not an error.
     */
    'taxonomy_map' => [
        'Hardware' => [
            'Desktop' => ['Endpoint & Hardware', 'Desktop/Laptop'],
            'Laptop' => ['Endpoint & Hardware', 'Desktop/Laptop'],
            'Printer' => ['Endpoint & Hardware', 'Printer/Scanner/Peripherals'],
            'Peripheral' => ['Endpoint & Hardware', 'Printer/Scanner/Peripherals'],
        ],
        'Software' => [
            'OS' => ['OS & Software', 'Windows OS'],
            'Update' => ['OS & Software', 'Windows OS'],
        ],
        'Network' => [
            'DNS' => ['Network & Connectivity', 'DNS/domain'],
            'VPN' => ['Network & Connectivity', 'Firewall/VPN/RDP/remote access'],
            'Firewall' => ['Network & Connectivity', 'Firewall/VPN/RDP/remote access'],
            'WiFi' => ['Network & Connectivity', 'LAN/Wi-Fi/switching'],
        ],
        'Email' => [
            'Delivery' => ['Email & M365 Tenant', 'Mail flow/forwarding/rules'],
            'Spam' => ['Email & M365 Tenant', 'Email security & quarantine'],
            'Calendar' => ['Email & M365 Tenant', 'Mailbox/Exchange Online'],
            'Permissions' => ['Email & M365 Tenant', 'Mailbox/Exchange Online'],
        ],
        'User Account' => [
            'Password Reset' => ['Identity & Access', 'Password reset/lockout'],
            'Permissions' => ['Identity & Access', 'Permissions/group/license change'],
            'New User' => ['Identity & Access', 'Onboarding/provisioning'],
            'Offboarding' => ['Identity & Access', 'Offboarding'],
        ],
        'Security' => [
            'Phishing' => ['Security & EDR', 'Phishing/BEC'],
            'Malware' => ['Security & EDR', 'Malware/ransomware'],
        ],
        'Server' => [
            'Performance' => ['Endpoint & Hardware', 'Server/NAS/BDR appliance'],
            'Storage' => ['Endpoint & Hardware', 'Server/NAS/BDR appliance'],
            'Backup' => ['Backup & DR', 'Backup health/failure'],
        ],
        // 'Cloud' and every */Other pair are deliberately unmapped: no single
        // taxonomy node wins them often enough to serve its SOP as "the"
        // procedure. They stay gaps until Phase-1 override data says otherwise.
    ],
];
