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
        ];
    }
}
