<?php

namespace App\Services\Chet;

/**
 * MCP tool definitions for the GC Chet Teams bridge. These are wired only at the
 * staff MCP boundary so they do not leak into the in-app assistant or native Teams
 * teammate tool surfaces.
 */
class OperatorBridgeTools
{
    /** @return array<int, string> */
    public static function names(): array
    {
        return array_column(self::definitions(), 'name');
    }

    public static function handles(string $name): bool
    {
        return in_array($name, self::names(), true);
    }

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'find_staff',
                'description' => 'Search staff Users (the MSP technicians/operators, not client contacts) by name or email substring. Returns id, name, email, microsoft_id (the Entra object id), and is_active.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Name or email fragment.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 10, max 25).'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_staff',
                'description' => 'Fetch one staff User by id. Returns id, name, email, microsoft_id (the Entra object id), and is_active, or an error if no such user exists.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'The staff User id.'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'post_to_operator',
                'description' => 'Post a message to the operator Teams chat. The recipient is resolved server-side from category; callers cannot direct delivery. Text is output-scanned and Teams-escaped before posting. Returns {posted, remote_message_id}.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => [
                            'type' => 'string',
                            'enum' => ['escalation', 'steer_request', 'daily_report', 'reply'],
                            'description' => 'escalation | steer_request | daily_report | reply',
                        ],
                        'message' => ['type' => 'string', 'description' => 'The message body.'],
                        'ticket_id' => ['type' => 'integer', 'description' => 'Optional PSA ticket id for server-derived context.'],
                    ],
                    'required' => ['category', 'message'],
                ],
            ],
            [
                'name' => 'poll_operator_messages',
                'description' => 'Drain inbound operator messages for the configured Chet Teams chat. Pass cursor equal to the highest message id whose processing was confirmed; the server acks rows up to that cursor and returns the next still-undelivered batch plus next_cursor.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'cursor' => ['type' => 'string', 'description' => 'Last confirmed message id. Omit or leave empty to re-pull the current undelivered batch.'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }
}
