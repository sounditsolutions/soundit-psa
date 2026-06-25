<?php

namespace App\Services\Triage;

use App\Services\Level\LevelClient;
use App\Services\Ninja\NinjaClient;
use App\Support\CippConfig;
use App\Support\CometConfig;
use App\Support\ControlDConfig;
use App\Support\MeshConfig;
use App\Support\TacticalConfig;
use App\Support\ZorusConfig;

/**
 * Generates Claude tool_use schema for the technical triage agentic loop.
 * Only includes tools for integrations that are configured and healthy.
 */
class TriageToolDefinitions
{
    /**
     * Read-only tool definitions available to the AI Technician.
     *
     * Exactly: search_tickets, get_ticket_notes, wiki_list_pages, wiki_search, wiki_get_page.
     * The agent reasons with these + propose_close (added separately by the agent — the only ACT tool).
     * MUST NOT include any set_ticket_* or tactical_run_diagnostic.
     */
    public static function readTools(): array
    {
        // Pull search_tickets and get_ticket_notes from psaTools() by name —
        // keeps schemas identical without re-authoring them.
        $readPsa = array_values(array_filter(
            self::psaTools(),
            fn (array $t): bool => in_array($t['name'], ['search_tickets', 'get_ticket_notes'], true)
        ));

        // All three wiki retrieval tools are included (no-op when wiki is off).
        return array_merge($readPsa, self::wikiTools());
    }

    /**
     * Get all available tool definitions based on configured integrations.
     */
    public static function getTools(): array
    {
        $tools = self::psaTools();

        if (self::isNinjaAvailable()) {
            $tools = array_merge($tools, self::ninjaTools());
        }

        if (self::isLevelAvailable()) {
            $tools = array_merge($tools, self::levelTools());
        }

        if (self::isMeshAvailable()) {
            $tools = array_merge($tools, self::meshTools());
        }

        if (self::isCippAvailable()) {
            $tools = array_merge($tools, self::cippTools());
        }

        if (self::isControlDAvailable()) {
            $tools = array_merge($tools, self::controldTools());
        }

        if (self::isZorusAvailable()) {
            $tools = array_merge($tools, self::zorusTools());
        }

        if (self::isTacticalAvailable()) {
            $tools = array_merge($tools, self::tacticalTools());
        }

        if (self::isCometAvailable()) {
            $tools = array_merge($tools, self::cometTools());
        }

        // DNS tools — always available (no integration required)
        $tools = array_merge($tools, self::dnsTools());

        // Wiki retrieval tools — always available (no-op at execution time when the wiki is off)
        $tools = array_merge($tools, self::wikiTools());

        return $tools;
    }

    /** Spec §6 retrieval tools. Shared with the Assistant + MCP via AssistantToolDefinitions. */
    public static function wikiTools(): array
    {
        return [
            [
                'name' => 'wiki_list_pages',
                'description' => 'List client-environment wiki pages in scope (this client plus global): slug, title, kind, freshness. Cheap orientation before wiki_get_page.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'wiki_search',
                'description' => 'Search the client wiki for facts and pages. Returns structured WIKI_FACT records, each with a verification status — treat "unverified" and "disputed" claims as unconfirmed and weight them accordingly; disputed facts show both sides.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search keywords (hostname, vendor, symptom)'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 10, max 20)'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'wiki_get_page',
                'description' => 'Retrieve one wiki page (markdown) by slug, with any client deviation merged over the standard runbook. Page bodies may contain unverified AI-inferred prose; prefer wiki_search to check the status of a specific fact.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['slug' => ['type' => 'string', 'description' => 'Page slug, e.g. "network" or "runbooks/user-onboarding"']],
                    'required' => ['slug'],
                ],
            ],
        ];
    }

    /**
     * DNS lookup tools — always available.
     */
    public static function dnsTools(): array
    {
        return [
            [
                'name' => 'dns_lookup',
                'description' => 'Look up DNS records for a hostname. Use for diagnosing email issues, verifying domain configurations, '
                    .'checking MX/SPF/DMARC, resolving hostnames to IPs, and general network troubleshooting. '
                    .'Accepts bare domains, IPs (for PTR), or full URLs (protocol is stripped).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => [
                            'type' => 'string',
                            'description' => 'The hostname or IP to look up (e.g. "example.com", "mail.example.com", "192.0.2.1" for PTR)',
                        ],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Record type: A (IPv4), AAAA (IPv6), MX (mail servers), TXT (SPF/verification/etc), '
                                .'NS (nameservers), CNAME (aliases), SOA (zone info), SRV (service records), PTR (reverse DNS). '
                                .'For DKIM, use TXT on selector._domainkey.domain.',
                            'enum' => ['A', 'AAAA', 'MX', 'TXT', 'NS', 'CNAME', 'SOA', 'SRV', 'PTR'],
                        ],
                    ],
                    'required' => ['hostname', 'type'],
                ],
            ],
            [
                'name' => 'dns_email_health',
                'description' => 'Check email authentication records (MX, SPF, DMARC) for a domain in one call. '
                    .'Use for diagnosing email delivery issues, spoofing concerns, or verifying email configuration. '
                    .'For DKIM, use dns_lookup with TXT on selector._domainkey.domain since the selector varies.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'domain' => [
                            'type' => 'string',
                            'description' => 'The bare domain (e.g. "example.com", not "mail.example.com")',
                        ],
                    ],
                    'required' => ['domain'],
                ],
            ],
        ];
    }

    // ── PSA Tools (always available) ──

    private static function psaTools(): array
    {
        return [
            [
                'name' => 'search_tickets',
                'description' => 'Search past tickets for the same client. Searches subject, description, and resolution columns. Multiple keywords are AND-matched (each must appear somewhere in a ticket). Use 1-3 distinctive keywords — asset names ("Lexmark", "MS823DN"), error codes, vendor names, or key nouns ("printer", "vpn", "outlook"). Avoid full sentences and stopwords. Try several short queries with different keyword combinations if the first does not return relevant results.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => '1-3 keywords separated by spaces. Examples: "lexmark offline", "vpn timeout", "printer contract".',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results to return (default 10, max 20)',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_ticket_notes',
                'description' => 'Get the notes/conversation history for a specific ticket.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_id' => [
                            'type' => 'integer',
                            'description' => 'The ticket ID to get notes for',
                        ],
                    ],
                    'required' => ['ticket_id'],
                ],
            ],
            [
                'name' => 'set_ticket_priority',
                'description' => 'Sets the ticket priority. You MUST call this tool to set the priority based on your analysis. Levels: 1=Critical, 2=High, 3=Medium, 4=Low.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'priority' => [
                            'type' => 'integer',
                            'description' => 'Priority level: 1=Critical, 2=High, 3=Medium, 4=Low',
                            'enum' => [1, 2, 3, 4],
                        ],
                    ],
                    'required' => ['priority'],
                ],
            ],
            [
                'name' => 'set_ticket_status',
                'description' => 'Sets the ticket status. Use this to move a ticket through its lifecycle. '
                    .'Statuses: new, in_progress, pending_client, pending_third_party, resolved, closed. '
                    .'Typical triage flow: move New tickets to in_progress after analysis.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'description' => 'The target status',
                            'enum' => ['new', 'in_progress', 'pending_client', 'pending_third_party', 'resolved', 'closed'],
                        ],
                    ],
                    'required' => ['status'],
                ],
            ],
            [
                'name' => 'set_ticket_keywords',
                'description' => 'Set distinctive keywords on this ticket so future searches and triage runs can find related tickets. You MUST call this. Provide 4-10 short keywords that capture the essence of the issue: vendor names (Lexmark, Outlook, Cisco), error codes (0x80004005), key nouns (printer, vpn, mailbox), product/model strings (MS823DN), and the symptom (offline, crash, slow). Avoid stopwords, full sentences, and generic words like "issue" or "problem". These keywords are also matched on subsequent searches.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'keywords' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => '4-10 distinctive keywords, lowercase, no punctuation. Example: ["lexmark", "ms823dn", "printer", "offline", "contract"].',
                        ],
                    ],
                    'required' => ['keywords'],
                ],
            ],
            [
                'name' => 'set_ticket_category',
                'description' => 'Sets the ticket category and subcategory. You MUST call this tool to classify the ticket type. '
                    .'Categories: '.self::categoryDescription(),
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => [
                            'type' => 'string',
                            'description' => 'Category name',
                            'enum' => array_keys(config('tickets.categories', [])),
                        ],
                        'subcategory' => [
                            'type' => 'string',
                            'description' => 'Subcategory name (must be valid for the chosen category)',
                        ],
                    ],
                    'required' => ['category'],
                ],
            ],
        ];
    }

    /**
     * Build a human-readable category description for the tool schema.
     */
    private static function categoryDescription(): string
    {
        $categories = config('tickets.categories', []);
        $parts = [];
        foreach ($categories as $cat => $subs) {
            $parts[] = "{$cat} (".implode(', ', $subs).')';
        }

        return implode('; ', $parts);
    }

    // ── NinjaRMM Tools ──

    public static function ninjaTools(): array
    {
        return [
            [
                'name' => 'ninja_search_devices',
                'description' => 'Search NinjaRMM devices by hostname, IP address, serial number, or username. Returns matching devices for this client only.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search term (hostname, IP, serial, or username)'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 20)'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'ninja_get_device',
                'description' => 'Get detailed information about a NinjaRMM device including hardware specs, OS, and status.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'integer', 'description' => 'NinjaRMM device ID'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
            [
                'name' => 'ninja_get_device_volumes',
                'description' => 'Get disk volume information for a device (capacity, free space, health).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'integer', 'description' => 'NinjaRMM device ID'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
            [
                'name' => 'ninja_get_device_alerts',
                'description' => 'Get active alerts for a device from NinjaRMM monitoring.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'integer', 'description' => 'NinjaRMM device ID'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
            [
                'name' => 'ninja_get_device_os_patches',
                'description' => 'Get pending OS patches for a device.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'integer', 'description' => 'NinjaRMM device ID'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
            [
                'name' => 'ninja_get_device_software',
                'description' => 'Get installed software list for a device.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'integer', 'description' => 'NinjaRMM device ID'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
            [
                'name' => 'ninja_get_device_processors',
                'description' => 'Get CPU/processor information for a device (model, cores, clock speed).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'integer', 'description' => 'NinjaRMM device ID'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
            [
                'name' => 'ninja_get_device_disk_drives',
                'description' => 'Get physical disk drive information for a device (model, capacity, interface type, health).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'integer', 'description' => 'NinjaRMM device ID'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
            [
                'name' => 'ninja_get_device_network_interfaces',
                'description' => 'Get network interface information for a device (adapters, IP addresses, MAC addresses, speed).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'integer', 'description' => 'NinjaRMM device ID'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
            [
                'name' => 'ninja_get_device_windows_services',
                'description' => 'Get Windows services running on a device (service name, display name, state, start type).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'integer', 'description' => 'NinjaRMM device ID'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
            [
                'name' => 'ninja_get_device_last_user',
                'description' => 'Get the last logged-on user for a device.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'integer', 'description' => 'NinjaRMM device ID'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
        ];
    }

    // ── Level RMM Tools ──

    public static function levelTools(): array
    {
        return [
            [
                'name' => 'level_get_device',
                'description' => 'Get detailed information about a Level RMM device including hardware specs (CPUs, memory, disks, network interfaces), OS, and status.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'string', 'description' => 'Level RMM device ID'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
        ];
    }

    // ── Mesh Email Security Tools ──

    public static function meshTools(): array
    {
        return [
            [
                'name' => 'mesh_search_email_logs',
                'description' => 'Search inbound email logs in Mesh. Useful for email delivery issues, spam complaints, or security incidents. Returns sender, recipient, subject, status, verdict, and queue_id for each message.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'Sender email address to filter by'],
                        'to' => ['type' => 'string', 'description' => 'Recipient email address to filter by'],
                        'subject' => ['type' => 'string', 'description' => 'Email subject to search for'],
                        'status' => ['type' => 'string', 'description' => 'Comma-separated statuses: quarantine, bounce, defer, delete, banner'],
                        'size' => ['type' => 'integer', 'description' => 'Max results (default 20)'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'mesh_get_email_events',
                'description' => 'Get processing events for a specific email in Mesh (delivery path, filtering decisions). Use the queue_id from mesh_search_email_logs results.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'queue_id' => ['type' => 'integer', 'description' => 'Queue ID of the email (from search results)'],
                    ],
                    'required' => ['queue_id'],
                ],
            ],
        ];
    }

    // ── CIPP / M365 Tools ──

    public static function cippTools(): array
    {
        return [
            [
                'name' => 'cipp_list_users',
                'description' => 'List M365 users for the client\'s tenant via CIPP.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'cipp_list_mailboxes',
                'description' => 'List M365 mailboxes for the client\'s tenant via CIPP.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'cipp_list_licenses',
                'description' => 'List M365 license assignments for the client\'s tenant via CIPP.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'cipp_list_devices',
                'description' => 'List Intune-managed devices for the client\'s tenant via CIPP.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'cipp_list_sign_ins',
                'description' => 'List recent M365 sign-in activity for the client\'s tenant via CIPP. Useful for account access issues. Filter by user_id when investigating a specific user — otherwise busy tenants will return so many sign-ins that the target user\'s events get pushed out of the response.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string', 'description' => 'Optional. UPN (email) or Azure AD object ID — narrows the query to one user.'],
                        'days' => ['type' => 'integer', 'description' => 'Optional. Time window in days (default 7, max 30).'],
                    ],
                ],
            ],
            [
                'name' => 'cipp_list_groups',
                'description' => 'List M365 groups (security groups, distribution lists, Microsoft 365 groups) for the client\'s tenant via CIPP.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'cipp_list_user_groups',
                'description' => 'List the M365 groups a specific user belongs to. Requires a user ID (UPN or object ID from cipp_list_users).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string', 'description' => 'User principal name (email) or Azure AD object ID'],
                    ],
                    'required' => ['user_id'],
                ],
            ],
            [
                'name' => 'cipp_list_mailbox_permissions',
                'description' => 'List mailbox permissions (Full Access, Send As, Send on Behalf) for a specific user\'s mailbox.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string', 'description' => 'User principal name (email) or Azure AD object ID'],
                    ],
                    'required' => ['user_id'],
                ],
            ],
            [
                'name' => 'cipp_list_mailbox_rules',
                'description' => 'List inbox rules for a specific user\'s mailbox. Useful for investigating mail delivery issues or compromised accounts.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string', 'description' => 'User principal name (email) or Azure AD object ID'],
                    ],
                    'required' => ['user_id'],
                ],
            ],
            [
                'name' => 'cipp_list_defender_state',
                'description' => 'List Microsoft Defender protection state across devices in the client\'s tenant. Shows antivirus status, definitions, and real-time protection.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'cipp_list_conditional_access_policies',
                'description' => 'List Conditional Access policies for the client\'s tenant. Useful for access issues or security reviews.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'cipp_list_user_conditional_access',
                'description' => 'List which Conditional Access policies apply to a specific user. Pairs with cipp_list_sign_ins for "why did this sign-in succeed/fail" investigations. Requires user_id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string', 'description' => 'User principal name (email) or Azure AD object ID'],
                    ],
                    'required' => ['user_id'],
                ],
            ],
            [
                'name' => 'cipp_list_audit_logs',
                'description' => 'List M365 Unified Audit Log events for the client\'s tenant — admin actions, mailbox rule changes, role grants, mailbox operations (when mailbox auditing is enabled by Defender for Office), file access, etc. Filter by user_id to narrow to a specific user (recommended for busy tenants). Useful for compromise investigation and "what did this user do recently".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string', 'description' => 'Optional. UPN (email) or Azure AD object ID — narrows to actions by/affecting one user.'],
                        'days' => ['type' => 'integer', 'description' => 'Optional. Time window in days (default 7, max 30).'],
                    ],
                ],
            ],
            [
                'name' => 'cipp_list_message_trace',
                'description' => 'Trace mail flow for the client\'s tenant — did a message get delivered, where did it go, was it modified by a transport rule. Common ticket: "I never got this email" or "did my email reach X". Filter by sender, recipient, and time window.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'sender' => ['type' => 'string', 'description' => 'Optional. Filter by sender email address.'],
                        'recipient' => ['type' => 'string', 'description' => 'Optional. Filter by recipient email address.'],
                        'days' => ['type' => 'integer', 'description' => 'Optional. Time window in days (default 2, max 10 — Microsoft\'s trace API only retains 10 days).'],
                    ],
                ],
            ],
            [
                'name' => 'cipp_list_mail_quarantine',
                'description' => 'List currently-quarantined inbound mail for the client\'s tenant. Common ticket: "I\'m missing an email" / "release this from quarantine". Optionally filter by recipient.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'recipient' => ['type' => 'string', 'description' => 'Optional. Filter by recipient email address.'],
                    ],
                ],
            ],
            [
                'name' => 'cipp_list_user_mfa_methods',
                'description' => "Return a specific user's MFA enforcement picture. The tool injects an `enforcement` field interpreting CIPP's raw values; trust that, not the raw fields. Raw CIPP fields are subtle:\n- `PerUser` is the LEGACY per-user MFA toggle. `\"disabled\"` means the user is NOT enrolled in legacy per-user MFA (the modern default — most tenants leave this disabled and use CA or Security Defaults instead).\n- `CoveredByCA: \"Enforced\"` means a Conditional Access policy requires MFA.\n- `CoveredBySD: true` means Security Defaults is enforcing MFA.\n- `MFARegistration: true` means the user has registered at least one MFA method.\n- `MFAMethods` is method TYPES (windowsHelloForBusiness, officePhone, etc) — CIPP does not expose device names or registration timestamps.\n\nRequires user_id.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string', 'description' => 'User principal name (email) or Azure AD object ID'],
                    ],
                    'required' => ['user_id'],
                ],
            ],
            [
                'name' => 'cipp_list_oauth_apps',
                'description' => 'List enterprise applications / OAuth consents granted in the client\'s tenant. Each entry shows the app, its publisher, and (when available) which user consented. Pass user_id to narrow client-side to consents involving that user — useful for compromise investigation (illicit consent attack indicators).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string', 'description' => 'Optional. UPN or object ID — filters results client-side to consents by/for this user.'],
                    ],
                ],
            ],
        ];
    }

    // ── Integration Availability Checks ──

    public static function isNinjaAvailable(): bool
    {
        try {
            return app(NinjaClient::class)->isHealthy();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isLevelAvailable(): bool
    {
        try {
            return app(LevelClient::class)->isHealthy();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isMeshAvailable(): bool
    {
        try {
            return MeshConfig::isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isCippAvailable(): bool
    {
        try {
            return CippConfig::isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isControlDAvailable(): bool
    {
        try {
            return ControlDConfig::isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isZorusAvailable(): bool
    {
        try {
            return ZorusConfig::isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isTacticalAvailable(): bool
    {
        try {
            return TacticalConfig::isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isCometAvailable(): bool
    {
        try {
            return CometConfig::isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function controldTools(): array
    {
        $tools = [
            [
                'name' => 'controld_get_devices',
                'description' => 'List Control D DNS security devices for this client. Shows device names, DNS security profiles, agent connectivity status, and agent versions. Use for DNS filtering issues, security policy verification, or checking if a device has DNS protection active.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
        ];

        if (ControlDConfig::isAnalyticsConfigured()) {
            $tools[] = [
                'name' => 'controld_dns_queries',
                'description' => 'Query recent DNS activity log for a specific device. Shows domains queried, whether they were blocked or allowed, and filter triggers. Use for DNS resolution issues, content filtering complaints, security investigations, or checking what domains a device has been accessing.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'device_name' => [
                            'type' => 'string',
                            'description' => 'Hostname of the device to query DNS logs for',
                        ],
                        'hours' => [
                            'type' => 'integer',
                            'description' => 'Hours to look back (default 24, max 168)',
                        ],
                    ],
                    'required' => ['device_name'],
                ],
            ];
        }

        return $tools;
    }

    // ── Zorus DNS Security Tools ──

    public static function zorusTools(): array
    {
        return [
            [
                'name' => 'zorus_get_endpoints',
                'description' => 'List Zorus DNS security endpoints for this client. Shows device names, group, filtering status, CyberSight status, agent version, agent state, and last seen time. Use for web filtering complaints, DNS protection verification, or checking endpoint security coverage. Reads from local database (not live API).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
        ];
    }

    // ── Comet Backup Tools ──

    private static function cometTools(): array
    {
        return [
            [
                'name' => 'comet_get_backup_status',
                'description' => 'Get backup health status for a device. Returns last job status, last success time, storage usage, and days since last successful backup.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => [
                            'type' => 'string',
                            'description' => 'Device hostname to check backup status for',
                        ],
                    ],
                    'required' => ['hostname'],
                ],
            ],
            [
                'name' => 'comet_get_backup_jobs',
                'description' => 'Get recent backup job history for a device. Shows job status, type, timestamps, duration, and error details.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => [
                            'type' => 'string',
                            'description' => 'Device hostname to get backup jobs for',
                        ],
                        'days' => [
                            'type' => 'integer',
                            'description' => 'Number of days of history to retrieve (default: 7)',
                        ],
                    ],
                    'required' => ['hostname'],
                ],
            ],
        ];
    }

    // ── Tactical RMM Tools ──

    public static function tacticalTools(): array
    {
        return [
            [
                'name' => 'tactical_get_device',
                'description' => 'Get comprehensive device info from Tactical RMM: status, hardware (CPU, RAM, disks), OS, network (public/local IPs), logged-in user, uptime, reboot status, check summary.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname'],
                    ],
                    'required' => ['hostname'],
                ],
            ],
            [
                'name' => 'tactical_get_device_checks',
                'description' => 'Get all monitoring check results for a device: check name, pass/fail status, return code, and output. Shows what health checks are failing and why.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname'],
                    ],
                    'required' => ['hostname'],
                ],
            ],
            [
                'name' => 'tactical_get_device_network',
                'description' => 'Get network configuration: IP addresses, subnets, gateways, DNS servers, DHCP status, MAC addresses for all network adapters.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname'],
                    ],
                    'required' => ['hostname'],
                ],
            ],
            [
                'name' => 'tactical_get_device_software',
                'description' => 'Get list of installed software on a device with names, versions, and publishers.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname'],
                    ],
                    'required' => ['hostname'],
                ],
            ],
            [
                'name' => 'tactical_get_device_services',
                'description' => 'Get Windows services on a device. Can filter by status (running/stopped) or search by name.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname'],
                        'filter' => ['type' => 'string', 'description' => 'Filter: "running", "stopped", or a search term'],
                    ],
                    'required' => ['hostname'],
                ],
            ],
            [
                'name' => 'tactical_get_device_disks',
                'description' => 'Get physical disk details (model, size, health) and volume space (drive letter, total, free, percent used).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname'],
                    ],
                    'required' => ['hostname'],
                ],
            ],
            [
                'name' => 'tactical_run_diagnostic',
                'description' => 'Run a pre-approved read-only diagnostic script on the device and return output. Available diagnostics: event_log_errors, top_processes, network_test, disk_health, windows_update_history, printer_status, startup_programs, uptime_detail, dns_config, firewall_status.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname'],
                        'diagnostic' => ['type' => 'string', 'description' => 'Diagnostic to run (e.g., "event_log_errors", "top_processes", "network_test")'],
                    ],
                    'required' => ['hostname', 'diagnostic'],
                ],
            ],
        ];
    }
}
