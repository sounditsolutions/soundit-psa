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
     * WHAT PINS THIS, precisely — the previous version of this docblock said
     * "the gate test asserts both directions", which was true of only one of
     * the two things that can make this constant wrong, and did not say which:
     *
     *   1. Agreement with psaTools() — asserted both ways, and always was. But
     *      that is a definition file agreeing with a definition file. It cannot
     *      see whether a tool actually mutates.
     *   2. Agreement with the EXECUTOR's classification — silently unasserted
     *      until psa-uw2o.18. AssistantToolExecutor's dispatch table is what
     *      decides whether a call writes; nothing compared it to this list. A
     *      reviewer flipped get_client from Read to Write and the whole gate and
     *      Teams suite stayed green while the prompt went on telling the model
     *      that two of its tools write, not three.
     *
     * Both are pinned now, by test_the_offered_writers_are_exactly_the_declared_write_tools:
     * the names getTools(true) OFFERS, intersected with the names the executor
     * CLASSIFIES as writes, must equal this constant exactly — and the same
     * intersection over getTools(false) must be empty, because that surface tells
     * the model it is read-only. Crossing the two independent sources is what
     * stops it being a tautology.
     *
     * That invariant covers the conditionally-merged VENDOR lanes too
     * (ninjaTools/levelTools/meshTools/cippTools), which getTools(true) includes
     * only when the integration is live — the test forces all four on, because
     * otherwise it would pass by never seeing them.
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
                            'description' => 'Max results per page (default 20, max 100).',
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => 'Results to skip for paging (default 0). The result includes a pagination block (total, has_more) so you can page.',
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
                            'description' => 'Max results per page (default 20, max 100).',
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => 'Results to skip for paging (default 0). The result includes a pagination block (total, has_more) so you can page.',
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
                            'description' => 'Only tickets last modified at or after this ISO-8601 timestamp (e.g. 2026-07-12T08:00:00Z). Returns them newest-touch first, so you can poll for new replies/changes without re-fetching every ticket. Capped at limit (max 100); if a wide window may exceed that, page with offset or narrow the window.',
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
                            'description' => 'Max results per page (default 20, max 100).',
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => 'Results to skip for paging (default 0). The result includes a pagination block (total, has_more) so you can page.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_ticket_detail',
                'description' => 'Get details, recent notes, and a summary of any linked phone calls for a ticket by ID. Use to inspect a specific ticket. Ticket-level attachments and each note\'s attachments are listed as metadata refs (attachment_id, filename, mime_type, size_bytes, is_inline — inline means an image embedded in an email body); fetch bytes with get_ticket_attachment. Includes an applicable_sop block: the ticket\'s taxonomy category path and that category\'s FULL standard-operating-procedure text, with its authoring status (a hint only — draft SOPs are still served), last-updated time, and an edit deep-link. A gap marker (no_category / no_sop_text) means the ticket needs a category assigned or the SOP needs authoring — worth fixing as you work. On a client-scoped read it also returns a flat assets array (the ticket\'s linked devices: id, hostname, type, is_active, is_primary) and a related block of id+name stubs for the client, contact, and assignee, so a single read orients you; pass expand: ["assets"] for fuller linked-device rows. On the unscoped cross-client staff read those two blocks are withheld, and so are the top-level client and contact name fields (the same names the stubs carry) — the response carries client_scoped_detail saying so rather than an empty device list — and expand: ["assets"] is refused; get that client\'s devices from find_assets instead. For full call transcripts, follow up with get_ticket_calls.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_id' => [
                            'type' => 'integer',
                            'description' => 'The ticket ID',
                        ],
                        'expand' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => ['assets']],
                            'description' => 'Opt-in deeper related data. Supported: "assets" (fuller rows for the linked devices, under expanded.assets).',
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
                            'description' => 'Max results per page (default 20, max 100).',
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => 'Results to skip for paging (default 0). The result includes a pagination block (total, has_more) so you can page.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_ticket_notes',
                'description' => 'Get the notes and conversation history for a specific ticket. Useful for understanding how a past issue was resolved. Each note carries an attachments list of metadata refs (attachment_id, filename, mime_type, size_bytes, is_inline — inline means an image embedded in an email body, e.g. a pasted screenshot); pass an attachment_id to get_ticket_attachment to see the file itself.',
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
                'name' => 'get_ticket_attachment',
                'description' => 'Fetch the binary content of an image or file attachment on one of THIS client\'s tickets, returned base64-encoded so you can actually see what a client or operator put on the ticket — error screenshots (the normal way non-technical people report problems), photos, PDFs. get_ticket_notes and get_ticket_detail list each note\'s attachments as refs carrying attachment_id — pass that id together with the ticket_id it appears on. (Note bodies may also reference attachments as /attachments/{id}/{filename}; that {id} works too.) Images are downscaled and re-encoded for direct visual inspection; the response carries media_type + data_base64. The attachment must belong to the given ticket (its ticket or a note on it) or the fetch is refused.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_id' => [
                            'type' => ['integer', 'string'],
                            'description' => 'The ticket the attachment appears on: internal numeric ID, or a display ID like "#12345" or "T-123".',
                        ],
                        'attachment_id' => [
                            'type' => 'integer',
                            'description' => 'The attachment ID — the {id} in the /attachments/{id}/{filename} URL shown in a note body.',
                        ],
                    ],
                    'required' => ['ticket_id', 'attachment_id'],
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
                'description' => 'Get profile details for the current client, including free-form notes maintained by staff. Also returns the client\'s in-service device fleet as a flat assets array (id, hostname, type, is_active; capped at 50 rows) with assets_count carrying the uncapped active total — if assets_count exceeds the rows shown, list the remainder with find_assets: omit query, leave include_inactive unset, and start at offset=50 — this block and that list share one order (active devices by hostname, then id), so offset=50 resumes exactly where these rows stop — then keep paging while has_more is true.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'get_person',
                'description' => 'Look up a contact at this client by id, email, or name (partial match). Returns only ACTIVE contacts by default — a deactivated/offboarded person is reported as "not found" unless you set include_inactive, so a routine lookup never surfaces a terminated employee for routing. Returns job title, department, emails, M365 enrichment, and any free-form notes, plus a related block (client stub and the person\'s assigned devices as id+name stubs). The related device list is ACTIVE-ONLY unless you set include_inactive, which is threaded through to it — read related.assets_count (uncapped, over the same fence as the list) and related.assets_inactive_excluded before concluding a person has no hardware: an empty list with assets_inactive_excluded above zero means their devices were DEACTIVATED, not that they were never assigned any. pass expand: ["assets"] for fuller device rows.',
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
                        'include_inactive' => [
                            'type' => 'boolean',
                            'description' => 'Resolve deactivated/offboarded contacts too. Defaults to false (active only).',
                        ],
                        'expand' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => ['assets']],
                            'description' => 'Opt-in deeper related data. Supported: "assets" (fuller rows for the person\'s assigned devices, under expanded.assets).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_asset',
                'description' => 'Look up a device (asset) at this client by id or hostname. Returns only ACTIVE (in-service) devices by default — a deactivated device is reported as "not found" unless you set include_inactive; retired/soft-deleted assets are never returned. Returns hardware, OS, warranty, RMM IDs, and free-form notes, plus a related block (owning client stub, assigned users as id+name stubs, tickets_count, and the 5 most recent linked tickets as stubs); pass expand: ["tickets"] for up to 20 fuller linked-ticket rows.',
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
                        'include_inactive' => [
                            'type' => 'boolean',
                            'description' => 'Resolve deactivated devices too. Defaults to false (active only). Retired/soft-deleted assets are never returned.',
                        ],
                        'expand' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => ['tickets']],
                            'description' => 'Opt-in deeper related data. Supported: "tickets" (up to 20 recent linked tickets with status/priority/dates, under expanded.tickets).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'find_persons',
                'description' => 'Search people by first name, last name, or email substring (partial, case-insensitive). If client_id is provided the search is scoped to that client; otherwise it searches across ALL clients and returns each match with its owning client_id and client_name. Returns only ACTIVE contacts by default — deactivated/offboarded people are excluded from routine discovery so they do not surface for routing or contact decisions; set include_inactive to true for a deliberate historical or former-employee lookup (every result carries is_active either way). Use the cross-client form when you only have a person\'s name or email and don\'t yet know what client they belong to.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Name or email fragment.',
                        ],
                        'include_inactive' => [
                            'type' => 'boolean',
                            'description' => 'Include deactivated/offboarded contacts. Defaults to false (active only).',
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
                'description' => 'Search assets/devices by hostname, name, or serial number (partial case-insensitive) — or OMIT query entirely to list assets outright (the way to answer "what devices does this client have"; never probe with a junk query, a zero-match search is indistinguishable from a client with no assets). If client_id is provided the search/list is scoped to that client; otherwise it runs across ALL clients and returns each match with its owning client_id and client_name. Returns only ACTIVE (in-service) assets by default; set include_inactive to true to ALSO include DEACTIVATED (is_active=false) assets — retired/soft-deleted assets are never returned by this tool (every result carries is_active either way). The response carries total (full matching count) and has_more — when has_more is true the list is truncated at your limit, not complete. Page with offset while has_more is true (offset=25 after a 25-row page, then 50, and so on) — this is the only way past a capped list, including get_client\'s 50-row fleet block, which this tool orders identically (active only, hostname then id): continue it with query omitted, include_inactive unset, and offset=50. Use the cross-client form when you only have a serial number, hostname, or device descriptor and don\'t yet know what client owns it.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Hostname / name / serial fragment. OPTIONAL: omit to list all assets in scope (capped at limit; check total/has_more).',
                        ],
                        'include_inactive' => [
                            'type' => 'boolean',
                            'description' => 'Include deactivated (is_active=false) assets. Defaults to false (active only). Retired/soft-deleted assets are never returned.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results per page (default 10 for a search, 25 for a list-all; max 25).',
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => 'Results to skip for paging (default 0). When has_more is true, re-issue the same call with offset = offset + count to get the next page.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'verify_device_absent',
                'description' => 'Offboarding check: ask every integration LIVE whether a device is really gone, instead of reading our own synced rows. Use this to confirm a teardown — "did this machine actually get removed from the portals?" — never the snapshot-backed zorus_*/screenconnect_* reads, which cannot tell a deleted device from an unsynced one. Each integration returns one of FOUR verdicts: present (a live read found it upstream), absent (a live read proved it gone), cannot_determine (the vendor could NOT be asked), or not_applicable (integration off, or this client/asset carries no link into it). Every arm also states its method — live or snapshot — plus a reason and evidence. cannot_determine is a real answer, not a soft absent: ScreenConnect ALWAYS returns it because that integration is webhook-receive-only here with no outbound API, so a session deleted in the portal is byte-identical to one that merely stopped emitting webhooks; its snapshot fields are returned for context only and must never be read as presence. The overall verdict is absent ONLY when every applicable integration answered absent live — one cannot_determine anywhere makes the whole reading cannot_determine, because a partial sweep does not prove a teardown. Resolves DEACTIVATED devices by default (unlike get_asset), since an offboarded device is normally already deactivated in the PSA; retired/soft-deleted assets are never returned.',
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
                        'include_inactive' => [
                            'type' => 'boolean',
                            'description' => 'Defaults to TRUE for this tool — deactivated devices are in scope, because they are what a teardown check is about. Pass false to deliberately narrow to active devices only.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }
}
