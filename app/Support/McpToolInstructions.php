<?php

namespace App\Support;

use App\Models\Setting;

class McpToolInstructions
{
    public const SETTING_KEY = 'mcp_tool_custom_instructions';

    public const MAX_LENGTH = 5000;

    private const HEADING = 'MSP custom instructions:';

    /** @return array<string, string> */
    public static function all(): array
    {
        $raw = Setting::getValue(self::SETTING_KEY);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return self::normalize($decoded);
    }

    public static function forTool(string $toolName): ?string
    {
        $instructions = self::all();

        return $instructions[$toolName] ?? null;
    }

    /** @param array<string, mixed> $instructions */
    public static function replaceAll(array $instructions): void
    {
        Setting::setValue(self::SETTING_KEY, json_encode(self::normalize($instructions)));
    }

    /** @param array<string, string>|null $instructions */
    public static function appendToDescription(string $toolName, string $description, ?array $instructions = null): string
    {
        $instructions ??= self::all();
        $instruction = $instructions[$toolName] ?? null;
        if ($instruction === null) {
            return $description;
        }

        return rtrim($description)."\n\n".self::HEADING."\n".$instruction;
    }

    /** @param array<string, mixed> $instructions */
    private static function normalize(array $instructions): array
    {
        $allowed = array_flip(McpToolRegistry::allToolNames());
        $normalized = [];

        foreach (McpToolRegistry::allToolNames() as $toolName) {
            if (! array_key_exists($toolName, $instructions) || ! isset($allowed[$toolName])) {
                continue;
            }

            $value = mb_substr(trim((string) $instructions[$toolName]), 0, self::MAX_LENGTH);
            if ($value !== '') {
                $normalized[$toolName] = $value;
            }
        }

        return $normalized;
    }
}
