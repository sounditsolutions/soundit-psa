<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * MCP server config helpers. Currently exposes a staff-scope token used by
 * the Claude Teams Teammate; future client-portal server will add a sibling.
 */
class McpConfig
{
    private const SCOPED_STAFF_TOKENS_KEY = 'mcp_staff_scoped_tokens';

    public static function staffToken(): ?string
    {
        return Setting::getEncrypted('mcp_staff_token');
    }

    public static function isStaffEnabled(): bool
    {
        return ! empty(self::staffToken()) || self::scopedStaffTokenRecords() !== [];
    }

    public static function resolveStaffToken(string $token): ?McpStaffToken
    {
        $legacy = self::staffToken();
        if (is_string($legacy) && $legacy !== '' && hash_equals($legacy, $token)) {
            return new McpStaffToken;
        }

        $hash = hash('sha256', $token);
        foreach (self::scopedStaffTokenRecords() as $record) {
            $storedHash = (string) ($record['hash'] ?? '');
            if ($storedHash !== '' && hash_equals($storedHash, $hash)) {
                return new McpStaffToken(
                    allowedTools: self::normalizeToolList((array) ($record['tools'] ?? [])),
                    label: (string) ($record['label'] ?? 'scoped'),
                );
            }
        }

        return null;
    }

    /**
     * Generate and store a new staff token. Returns the new token (only
     * shown once — caller is responsible for handing it to the bot operator).
     *
     * @param  array<int, string>|null  $allowedTools  Null rotates the legacy full-surface token.
     */
    public static function rotateStaffToken(?array $allowedTools = null, ?string $label = null): string
    {
        $token = 'psa-mcp-'.Str::random(48);

        if ($allowedTools === null) {
            Setting::setEncrypted('mcp_staff_token', $token);

            return $token;
        }

        $tools = self::normalizeToolList($allowedTools);
        if ($tools === []) {
            throw new \InvalidArgumentException('At least one allowed MCP tool is required for a scoped token.');
        }

        $label = self::normalizeLabel($label);
        $records = array_values(array_filter(
            self::scopedStaffTokenRecords(),
            fn (array $record): bool => ($record['label'] ?? null) !== $label,
        ));
        $records[] = [
            'label' => $label,
            'hash' => hash('sha256', $token),
            'tools' => $tools,
            'created_at' => now()->toIso8601String(),
        ];

        Setting::setEncrypted(self::SCOPED_STAFF_TOKENS_KEY, json_encode($records, JSON_UNESCAPED_SLASHES));

        return $token;
    }

    public static function hasScopedStaffTokenLabel(string $label): bool
    {
        $label = self::normalizeLabel($label);

        foreach (self::scopedStaffTokenRecords() as $record) {
            if (($record['label'] ?? null) === $label) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array<string, mixed>> */
    private static function scopedStaffTokenRecords(): array
    {
        try {
            $raw = Setting::getEncrypted(self::SCOPED_STAFF_TOKENS_KEY);
        } catch (\Throwable) {
            return [];
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /** @param array<int, mixed> $tools */
    private static function normalizeToolList(array $tools): array
    {
        $normalized = [];
        foreach ($tools as $tool) {
            foreach (explode(',', (string) $tool) as $part) {
                $name = trim($part);
                if ($name !== '') {
                    $normalized[$name] = true;
                }
            }
        }

        return array_keys($normalized);
    }

    private static function normalizeLabel(?string $label): string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return 'scoped';
        }

        $label = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $label) ?? 'scoped';

        return trim($label, '-') !== '' ? trim($label, '-') : 'scoped';
    }
}
