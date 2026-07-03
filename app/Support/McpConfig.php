<?php

namespace App\Support;

use App\Models\McpToken;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * MCP server config helpers. Scoped staff tokens live in the `mcp_tokens`
 * table as hash-only records. The legacy full-surface token remains in the
 * encrypted Setting for backward compatibility and CLI break-glass use.
 */
class McpConfig
{
    public static function staffToken(): ?string
    {
        return Setting::getEncrypted('mcp_staff_token');
    }

    public static function isStaffEnabled(): bool
    {
        return ! empty(self::staffToken()) || McpToken::query()->active()->exists();
    }

    public static function resolveStaffToken(string $token): ?McpStaffToken
    {
        $legacy = self::staffToken();
        if (is_string($legacy) && $legacy !== '' && hash_equals($legacy, $token)) {
            return new McpStaffToken;
        }

        $hash = hash('sha256', $token);
        $record = McpToken::query()->active()->where('token_hash', $hash)->first();
        if ($record === null) {
            return null;
        }

        $record->forceFill(['last_used_at' => now()])->saveQuietly();

        return new McpStaffToken(
            allowedTools: $record->tools === null ? null : self::normalizeToolList($record->tools),
            label: (string) $record->label,
            id: (int) $record->id,
            directive: $record->directiveOrDefault(),
            aiActor: (bool) $record->ai_actor,
            requireExplicitClientScope: (bool) $record->require_explicit_client_scope,
        );
    }

    /**
     * Generate and store a new staff token. Returns the new token (only
     * shown once — caller is responsible for handing it to the bot operator).
     *
     * @param  array<int, string>|null  $allowedTools  Null rotates the legacy full-surface token.
     */
    public static function rotateStaffToken(
        ?array $allowedTools = null,
        ?string $label = null,
        ?bool $aiActor = null,
        ?bool $requireExplicitClientScope = null,
    ): string {
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
        $record = McpToken::firstOrNew(['label' => $label]);
        $isNew = ! $record->exists;
        $useNewTokenTrustDefaults = $isNew || $record->isRevoked();
        $record->token_hash = hash('sha256', $token);
        $record->token_prefix = self::tokenPrefix($token);
        $record->tools = $tools;
        $record->last_used_at = null;
        $record->revoked_at = null;
        if ($aiActor !== null || $useNewTokenTrustDefaults) {
            $record->ai_actor = $aiActor ?? false;
        }
        if ($requireExplicitClientScope !== null || $useNewTokenTrustDefaults) {
            $record->require_explicit_client_scope = $requireExplicitClientScope ?? true;
        }
        $record->save();

        return $token;
    }

    public static function hasScopedStaffTokenLabel(string $label): bool
    {
        return McpToken::query()->active()
            ->where('label', self::normalizeLabel($label))
            ->exists();
    }

    private static function tokenPrefix(string $token): string
    {
        return Str::substr($token, 0, 12).'...';
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

    public static function normalizeLabel(?string $label): string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return 'scoped';
        }

        $label = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $label) ?? 'scoped';

        return trim($label, '-') !== '' ? trim($label, '-') : 'scoped';
    }
}
