<?php

namespace App\Services\Portal;

/**
 * Anthropic tool_use definitions for the client-portal chatbot (psa-2ab).
 *
 * All tools are READ-ONLY and are executed by PortalChatbotToolExecutor, which
 * hard-binds the client scope. None of these accept a client/tenant identifier
 * — scope is never taken from tool input.
 */
class PortalChatbotToolDefinitions
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function tools(): array
    {
        return [
            [
                'name' => 'get_account_summary',
                'description' => 'Get a quick count of the account: open tickets, unpaid invoices, active devices, and active service agreements. Use this to answer broad "how are things" questions or to orient before drilling in.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'list_tickets',
                'description' => 'List support tickets on this account. Returns id, subject, status, priority and dates. Use the ticket id with get_ticket to read the full conversation.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'enum' => ['open', 'closed', 'all'],
                            'description' => 'Which tickets to return. Defaults to open.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum tickets to return (default 15, max 30).',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'get_ticket',
                'description' => 'Get one ticket in full: its details plus the visible message history (public replies only). Only tickets on this account can be read.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_id' => [
                            'type' => 'integer',
                            'description' => 'The ticket id (as returned by list_tickets).',
                        ],
                    ],
                    'required' => ['ticket_id'],
                ],
            ],
            [
                'name' => 'list_invoices',
                'description' => 'List invoices for this account (posted, synced or paid only). Returns invoice number, dates, total, status and whether it is overdue. Amounts only — never internal cost data.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum invoices to return (default 15, max 30).',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'list_devices',
                'description' => 'List the active devices (computers/servers) registered to this account. Returns name, hostname, type, operating system and serial number.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => [
                            'type' => 'string',
                            'description' => 'Optional filter matched against hostname, name or serial number.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum devices to return (default 25, max 50).',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'list_agreements',
                'description' => 'List the active service agreements (contracts) for this account: name, type, status and term dates. Prepaid-hour balances are included where available.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
        ];
    }
}
