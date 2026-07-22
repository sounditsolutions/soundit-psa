<?php

namespace App\Services\Mcp;

use App\Support\McpInputSchema;

/**
 * Tool surface for the portal MCP server. Every tool operates on behalf of the
 * authenticated portal Person and is scoped server-side to that Person's client
 * (and, for tickets, honours company-wide access). No tool accepts a client id,
 * tenant id, or any other scope selector — the scope can only come from the
 * resolved identity, never from tool input.
 */
class PortalMcpToolDefinitions
{
    /**
     * @return array<int, array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public static function tools(): array
    {
        return [
            [
                'name' => 'list_my_open_tickets',
                'description' => 'List your open support tickets (newest activity first). Returns only tickets you are allowed to see.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of tickets to return (1-50, default 25).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_my_ticket',
                'description' => 'Get one of your support tickets by its numeric id, including the visible conversation history. Returns an error if the ticket does not exist or you are not allowed to see it.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_id' => [
                            'type' => 'integer',
                            'description' => 'The numeric ticket id.',
                        ],
                    ],
                    'required' => ['ticket_id'],
                ],
            ],
            [
                'name' => 'search_my_tickets',
                'description' => 'Search your support tickets by keyword (subject, description, resolution, category, or linked device). Returns only tickets you are allowed to see.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The search text.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of tickets to return (1-50, default 25).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'create_ticket',
                'description' => 'Open a new support ticket on your behalf. You are recorded as the contact.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'subject' => [
                            'type' => 'string',
                            'description' => 'A short summary of the issue or request.',
                        ],
                        'body' => [
                            'type' => 'string',
                            'description' => 'A detailed description of the issue or request.',
                        ],
                        'urgency' => [
                            'type' => 'string',
                            'enum' => ['normal', 'urgent'],
                            'description' => "How urgent the request is. 'normal' (default) or 'urgent'.",
                        ],
                    ],
                    'required' => ['subject', 'body'],
                ],
            ],
            [
                'name' => 'add_my_ticket_reply',
                'description' => 'Add a reply to one of your existing support tickets. Returns an error if the ticket does not exist or you are not allowed to reply to it.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_id' => [
                            'type' => 'integer',
                            'description' => 'The numeric id of the ticket to reply to.',
                        ],
                        'body' => [
                            'type' => 'string',
                            'description' => 'The reply text.',
                        ],
                    ],
                    'required' => ['ticket_id', 'body'],
                ],
            ],
            [
                'name' => 'list_my_assets',
                'description' => "List your organisation's active devices (assets).",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of devices to return (1-100, default 50).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    /**
     * The tools this server will actually publish — tools() minus any whose input schema
     * does not validate.
     *
     * *** THIS EXISTS SO THE LIST PATH AND THE CALL PATH CANNOT DISAGREE (psa-vydpz). ***
     * McpPortalController::listTools() dropped schema-invalid tools while callTool() gated
     * on handles(), which read the UNFILTERED list. A dropped tool therefore stayed
     * callable: unpublished but live, the same publish-vs-dispatch split this bead closes
     * on the staff server.
     *
     * It was LATENT, not exploitable — all six shipped schemas validate and tools() is
     * static PHP literals, so no config or vendor input can invalidate one. But this
     * surface carries WRITES (create_ticket, add_my_ticket_reply), and the day a seventh
     * tool lands with a malformed schema it would silently become unpublished-but-callable.
     * Fixing the shape now costs nothing; discovering it later costs a written row.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function publishableTools(): array
    {
        return array_values(array_filter(
            self::tools(),
            fn (array $tool): bool => McpInputSchema::validationErrors(
                $tool['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass]
            ) === [],
        ));
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_column(self::publishableTools(), 'name');
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::names(), true);
    }
}
