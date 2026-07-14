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
        // Born-safe gate: only an activated, non-paused, non-revoked token
        // authenticates. A draft or paused token resolves to null (Unauthorized).
        $record = McpToken::query()->authenticatable()->where('token_hash', $hash)->first();
        if ($record === null) {
            return null;
        }

        $record->forceFill(['last_used_at' => now()])->saveQuietly();

        // Grant entries may carry per-tool mode suffixes (name:staged /
        // name:immediate) and legacy staged-alias names; parse them into the
        // plain canonical tool list plus the per-tool mode map.
        $grants = $record->tools === null
            ? null
            : McpToolModes::parseGrants(self::normalizeToolList($record->tools));

        return new McpStaffToken(
            allowedTools: $grants === null ? null : $grants['tools'],
            label: (string) $record->label,
            id: (int) $record->id,
            directive: $record->directiveOrDefault(),
            aiActor: (bool) $record->ai_actor,
            requireExplicitClientScope: (bool) $record->require_explicit_client_scope,
            toolModes: $grants === null ? [] : $grants['modes'],
        );
    }

    /**
     * Whether a live (non-revoked) staff token under $label is authorized to call the
     * sensitive OPERATOR-BRIDGE tool $toolName, under the SAME rule the staff MCP boundary
     * applies at request time — McpStaffController::toolAllowed()'s bridge branch, which is
     * `allowedTools !== null && allows($tool)`. A legacy full-surface token (tools === null)
     * is DENIED bridge tools by that rule, so it does NOT count here either; anything looser
     * (e.g. a plain allows(), where null = full surface = allowed) would MISREAD it.
     *
     * This is what makes an Alerts → MCP destination genuinely CONSUMABLE rather than merely
     * deliverable: poll_signals is the tool an agent uses to drain a destination's signal
     * inbox, so a token that cannot call it can never read the queued rows — the destination
     * is a black hole no matter how live its label is (psa-28j4.3 review gate).
     *
     * Liveness matches McpToken::hasLiveLabel() (draft/paused count — they can be activated to
     * drain a backlog; only revocation is terminal), so this composes cleanly with the
     * delivery-side mcpDeliveryBlockReason() check rather than contradicting it. The grant
     * projection is a faithful copy of resolveStaffToken() above, so the two cannot drift.
     */
    public static function labelCanUseBridgeTool(?string $label, string $toolName): bool
    {
        $label = trim((string) $label);
        if ($label === '') {
            return false;
        }

        return McpToken::query()
            ->where('label', $label)
            ->whereNull('revoked_at')
            ->get()
            ->contains(function (McpToken $record) use ($toolName): bool {
                $allowedTools = $record->tools === null
                    ? null
                    : McpToolModes::parseGrants(self::normalizeToolList($record->tools))['tools'];

                return $allowedTools !== null && in_array($toolName, $allowedTools, true);
            });
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
        // Programmatic / break-glass rotation yields a ready active token (the
        // new draft flow uses mintDraftToken instead). Rotating a revoked token
        // reactivates it, matching the pre-lifecycle behaviour.
        if ($record->activated_at === null) {
            $record->activated_at = now();
        }
        $record->paused_at = null;
        $record->save();

        return $token;
    }

    /**
     * Mint a fresh draft token: inactive, zero tools, safe trust defaults.
     * Returns the plaintext once. The caller redirects into the token's detail
     * page to configure and then deliberately activate it. A draft cannot
     * authenticate (see McpToken::scopeAuthenticatable), so it is never briefly
     * live with the wrong permissions during setup.
     */
    public static function mintDraftToken(string $label): string
    {
        $token = 'psa-mcp-'.Str::random(48);

        $record = new McpToken;
        $record->label = self::normalizeLabel($label);
        $record->token_hash = hash('sha256', $token);
        $record->token_prefix = self::tokenPrefix($token);
        $record->tools = [];
        $record->ai_actor = false;
        $record->require_explicit_client_scope = true;
        $record->activated_at = null;
        $record->paused_at = null;
        $record->revoked_at = null;
        $record->save();

        return $token;
    }

    public static function regenerateSecret(McpToken $token): string
    {
        $plain = 'psa-mcp-'.Str::random(48);

        $token->forceFill([
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => self::tokenPrefix($plain),
            'last_used_at' => null,
        ])->save();

        return $plain;
    }

    public static function hasScopedStaffTokenLabel(string $label): bool
    {
        return McpToken::query()->active()
            ->where('label', self::normalizeLabel($label))
            ->exists();
    }

    // ── Portal MCP server ─────────────────────────────────────────────────
    //
    // The portal MCP server (`/api/mcp/portal`) is a client-facing sibling of
    // the staff server. It carries a single shared bearer token that
    // authenticates the trusted bridge (the client Teams agent connector); the
    // end user is identified per-request by an Entra Object ID header and
    // resolved to a portal Person. Unlike the staff surface there are no scoped
    // tokens or per-tool grants — the whole surface is fixed and client-locked
    // to the resolved Person, so a single break-glass token is sufficient.

    public static function portalToken(): ?string
    {
        return Setting::getEncrypted('mcp_portal_token');
    }

    public static function isPortalEnabled(): bool
    {
        return ! empty(self::portalToken());
    }

    public static function resolvePortalToken(string $token): bool
    {
        $stored = self::portalToken();

        return is_string($stored) && $stored !== '' && hash_equals($stored, $token);
    }

    /**
     * Generate and store a new portal MCP bearer token. Returns the plaintext
     * once — the caller hands it to the bridge operator. Replaces any existing
     * token, invalidating the previous one.
     */
    public static function rotatePortalToken(): string
    {
        $token = 'psa-mcp-portal-'.Str::random(48);

        Setting::setEncrypted('mcp_portal_token', $token);

        return $token;
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
