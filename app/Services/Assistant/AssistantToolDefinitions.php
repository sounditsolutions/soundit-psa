<?php

namespace App\Services\Assistant;

use App\Services\Triage\TriageToolDefinitions;

/**
 * Tool definitions for the AI assistant.
 * Reuses integration tool definitions from triage; defines its own PSA tools
 * with assistant-optimized descriptions.
 *
 * NOT read-only, despite what this docblock claimed until psa-uw2o: psaTools()
 * — returned whenever the conversation resolves a client — includes two WRITE
 * tools, create_ticket and add_ticket_note, which call TicketService directly
 * with no held/approval step. The no-client surface (generalTools + dnsTools +
 * wikiTools) is read-only. Keep this accurate: the previous claim is how the
 * write surface stayed invisible to review.
 */
class AssistantToolDefinitions
{
    /**
     * The tools defined in THIS file that MUTATE. Single source of truth.
     *
     * psa-uw2o.6: before this existed there were FOUR independent hardcoded
     * lists of "which assistant tools write" — the system prompt's sentence,
     * two separate assertions in the gate test, and TeamsReadOnlyToolset::
     * MUTATING. Four lists means a fifth writer added to psaTools() is
     * disclosed by none of them; one of those consumers strips writers for a
     * bot literally named ReadOnly, so the drift is not merely cosmetic.
     *
     * Anything added here must be reflected in psaTools(). The gate test
     * asserts both directions, so adding a writer WITHOUT listing it here
     * fails, and listing one that psaTools() does not offer fails too.
     */
    public const WRITE_TOOLS = ['create_ticket', 'add_ticket_note'];

    public static function getTools(bool $hasClient): array
    {
        if (! $hasClient) {
            // General assistant also gets DNS tools + wiki retrieval (global-scoped when no client)
            return array_merge(self::generalTools(), TriageToolDefinitions::dnsTools(), TriageToolDefinitions::wikiTools());
        }

        $tools = self::psaTools();

        if (TriageToolDefinitions::isNinjaAvailable()) {
            $tools = array_merge($tools, TriageToolDefinitions::ninjaTools());
        }

        if (TriageToolDefinitions::isLevelAvailable()) {
            $tools = array_merge($tools, TriageToolDefinitions::levelTools());
        }

        if (TriageToolDefinitions::isMeshAvailable()) {
            $tools = array_merge($tools, TriageToolDefinitions::meshTools());
        }

        if (TriageToolDefinitions::isCippAvailable()) {
            $tools = array_merge($tools, TriageToolDefinitions::cippTools());
        }

        // DNS tools — always available in client context too
        $tools = array_merge($tools, TriageToolDefinitions::dnsTools());

        // Wiki retrieval tools — single-owner schemas live in TriageToolDefinitions
        $tools = array_merge($tools, TriageToolDefinitions::wikiTools());

        return $tools;
    }

    /**
     * Tools available in general (non-client) context.
     * Cross-client ticket queries for strategic/planning questions.
     */
    private static function generalTools(): array
    {
        return [
            [
                'name' => 'search_all_tickets',
                'description' => 'Search across all tickets in the PSA (not scoped to any client). Searches subject, description, and resolution. Multiple keywords are AND-matched (each must appear somewhere in a ticket). Use 1-3 distinctive keywords for best results.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => '1-3 keywords separated by spaces.',
                        ],
                        'status' => [
                            'type' => 'string',
                            'description' => 'Filter by status: new, in_progress, pending_client, pending_third_party, resolved, closed. Omit for all.',
                            'enum' => ['new', 'in_progress', 'pending_client', 'pending_third_party', 'resolved', 'closed'],
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results (default 15, max 30)',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'list_my_tickets',
                'description' => 'List tickets assigned to the current staff user. Sorted by priority then age (oldest first).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'description' => 'Filter by status. Omit for all open statuses (new, in_progress, pending_client, pending_third_party).',
                            'enum' => ['new', 'in_progress', 'pending_client', 'pending_third_party', 'resolved', 'closed'],
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results (default 20, max 50)',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'list_open_tickets',
                'description' => 'List all open tickets across the board, optionally filtered. Sorted by priority then age. Use for queue overview and workload questions. Pass updated_since to get a recently-modified feed (newest touch first) — the scalable way to find new client replies landing on existing open tickets.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'assignee' => [
                            'type' => 'string',
                            'description' => 'Filter by assignee name (partial match). Omit for all.',
                        ],
                        'updated_since' => [
                            'type' => 'string',
                            'description' => 'Only tickets last modified at or after this ISO-8601 timestamp (e.g. 2026-07-12T08:00:00Z). Returns them newest-touch first, so you can poll for new replies/changes without re-fetching every ticket. Capped at limit (max 50); if a wide window may exceed that, poll more often or narrow the window.',
                        ],
                        'priority' => [
                            'type' => 'string',
                            'description' => 'Filter by priority: p1, p2, p3, p4. Omit for all.',
                            'enum' => ['p1', 'p2', 'p3', 'p4'],
                        ],
                        'source' => [
                            'type' => 'string',
                            'description' => 'Filter by source: email, phone, portal, helpdesk_button, huntress, alert. Omit for all.',
                        ],
                        'exclude_alerts' => [
                            'type' => 'boolean',
                            'description' => 'If true, exclude tickets with source=alert. Useful for "real" tickets only.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results (default 20, max 50)',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_ticket_detail',
                'description' => 'Get details, recent notes, and a summary of any linked phone calls for a ticket by ID. Use to inspect a specific ticket. For full call transcripts, follow up with get_ticket_calls.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_id' => [
                            'type' => 'integer',
                            'description' => 'The ticket ID',
                        ],
                    ],
                    'required' => ['ticket_id'],
                ],
            ],
            [
                'name' => 'get_ticket_calls',
                'description' => 'Get the phone calls linked to a ticket, including each call\'s direction, sentiment, billing classification, summary, next steps, coaching notes, and full transcript. Use whenever the user asks about phone calls, voicemails, what a caller said, or call transcripts/summaries for a ticket.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_id' => [
                            'type' => 'integer',
                            'description' => 'The ticket ID',
                        ],
                    ],
                    'required' => ['ticket_id'],
                ],
            ],
            [
                'name' => 'get_queue_stats',
                'description' => 'Get summary statistics for the ticket queue: counts by status, priority, and age. Use for "how are we doing" and workload planning.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'find_clients',
                'description' => 'Search clients by name (partial match, case-insensitive). Returns matching clients with their IDs — use this to bootstrap from a natural-language reference like "Acme" or "Globex Corp" into a concrete client_id you can pass to other tools.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Partial client name. Searches the clients.name column.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results (default 10, max 25).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    private static function psaTools(): array
    {
        return [
            [
                'name' => 'search_tickets',
                'description' => 'Search this client\'s past tickets. Searches subject, description, and resolution columns. Multiple keywords are AND-matched (each must appear somewhere in a ticket). Use 1-3 distinctive keywords — asset names, error codes, vendor names, or key nouns. Avoid full sentences and stopwords. Try several short queries with different keyword combinations if the first does not return relevant results.',
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
                'description' => 'Get the notes and conversation history for a specific ticket. Useful for understanding how a past issue was resolved.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_id' => [
                            'type' => ['integer', 'string'],
                            'description' => 'The ticket to read: internal numeric ID, or a display ID like "#12345" (externally-synced ticket number) or "T-123"',
                        ],
                    ],
                    'required' => ['ticket_id'],
                ],
            ],
            [
                'name' => 'create_ticket',
                'description' => 'Create a new ticket for this client with a subject, description, and optional priority.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'subject' => [
                            'type' => 'string',
                            'description' => 'Short, descriptive ticket subject',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Detailed description of the issue, including context from the current investigation',
                        ],
                        'priority' => [
                            'type' => 'integer',
                            'description' => 'Priority: 1=Critical, 2=High, 3=Normal, 4=Low. Default 3 if not specified.',
                            'enum' => [1, 2, 3, 4],
                        ],
                    ],
                    'required' => ['subject', 'description'],
                ],
            ],
            [
                'name' => 'add_ticket_note',
                'description' => 'Add a private note to a ticket. The note is attributed to the current staff user.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_id' => [
                            'type' => 'integer',
                            'description' => 'The ticket ID to add the note to. Use the current ticket if in ticket context.',
                        ],
                        'body' => [
                            'type' => 'string',
                            'description' => 'The note content (supports markdown formatting)',
                        ],
                    ],
                    'required' => ['ticket_id', 'body'],
                ],
            ],
            [
                'name' => 'get_client',
                'description' => 'Get profile details for the current client, including free-form notes maintained by staff.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'get_person',
                'description' => 'Look up a contact at this client by id, email, or name (partial match). Returns job title, department, emails, M365 enrichment, and any free-form notes.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'person_id' => [
                            'type' => 'integer',
                            'description' => 'The person ID (preferred if known).',
                        ],
                        'email' => [
                            'type' => 'string',
                            'description' => 'Email address to look up.',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'First or last name, partial match. Used only if id/email not provided.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_asset',
                'description' => 'Look up a device (asset) at this client by id or hostname. Returns hardware, OS, warranty, RMM IDs, and free-form notes.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'asset_id' => [
                            'type' => 'integer',
                            'description' => 'The asset ID (preferred if known).',
                        ],
                        'hostname' => [
                            'type' => 'string',
                            'description' => 'Hostname to look up (case-insensitive exact match).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'find_persons',
                'description' => 'Search people by name (partial first/last/full name match) or email substring. If client_id is provided the search is scoped to that client; otherwise it searches across ALL clients and returns each match with its owning client_id and client_name. Use the cross-client form when you only have a person\'s name or email and don\'t yet know what client they belong to.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Name or email fragment.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results (default 10, max 25).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'find_assets',
                'description' => 'Search assets/devices by hostname, name, or serial number (partial case-insensitive). If client_id is provided the search is scoped to that client; otherwise it searches across ALL clients and returns each match with its owning client_id and client_name. Use the cross-client form when you only have a serial number, hostname, or device descriptor and don\'t yet know what client owns it.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Hostname / name / serial fragment.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results (default 10, max 25).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }
}
