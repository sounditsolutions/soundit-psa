<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Per-tenant bot-credential store for the PSA-native Teams bot (E1).
 *
 * Mirrors McpConfig: the App ID + tenant ID are non-secret plain Settings; the
 * Entra client secret is encrypted at rest via Setting::setEncrypted/getEncrypted
 * (the same handling as technician_teams_webhook_url). The operator enters all
 * three via the Integrations UI — nothing is hardcoded or committed to env.
 *
 * Ships DORMANT: enabled() defaults false.
 *
 * Multi-MSP shape: this pilot stores a single bot, but the public product is
 * multi-tenant, so the inbound JWT audience and the identity resolver work
 * against appIds() (a SET) and forAppId() rather than a single literal. Adding a
 * second MSP later is a storage change, not a code change at the call sites.
 */
class TeamsBotConfig
{
    public static function appId(): ?string
    {
        $value = Setting::getValue('teams_bot_app_id');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public static function tenantId(): ?string
    {
        $value = Setting::getValue('teams_bot_tenant_id');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** The Entra client secret, decrypted. Null when unset. */
    public static function clientSecret(): ?string
    {
        $value = Setting::getEncrypted('teams_bot_client_secret');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** Store the Entra client secret, encrypted at rest. */
    public static function setClientSecret(string $secret): void
    {
        Setting::setEncrypted('teams_bot_client_secret', $secret);
    }

    /** Fully configured only when all three credentials are present. */
    public static function configured(): bool
    {
        return self::appId() !== null
            && self::tenantId() !== null
            && self::clientSecret() !== null;
    }

    /** Master on/off for the whole Teams bridge. Absent ⇒ false (dormant). */
    public static function enabled(): bool
    {
        return (bool) Setting::getValue('teams_bot_enabled');
    }

    /**
     * The registered SET of bot App IDs — the inbound JWT audience is validated
     * against this, not a single literal (the multi-MSP seam). One entry for the
     * pilot; empty when unconfigured (so the middleware fails closed).
     *
     * @return array<int, string>
     */
    public static function appIds(): array
    {
        $appId = self::appId();

        return $appId !== null ? [$appId] : [];
    }

    /**
     * Resolve the MSP bot context for a given inbound App ID (the activity's
     * recipient.id). Returns null when the App ID is not a registered bot — the
     * caller treats that as "not for us" and fails closed. The conversation/tenant
     * context returned here is what the identity resolver scopes the sender to.
     *
     * @return array{app_id: string, tenant_id: ?string}|null
     */
    public static function forAppId(?string $appId): ?array
    {
        if ($appId === null || ! in_array($appId, self::appIds(), true)) {
            return null;
        }

        return ['app_id' => $appId, 'tenant_id' => self::tenantId()];
    }

    // ── E2b: ambient chiming-in dials (Setting-backed, tuned live in the chat) ─

    /**
     * Whether the bot may chime in UNPROMPTED (non-@mention). A second dormancy
     * layer on top of enabled(): ambient requires BOTH on. Default OFF.
     */
    public static function ambientEnabled(): bool
    {
        return (bool) Setting::getValue('teams_ambient_enabled');
    }

    /**
     * How eager the "should I speak?" gate is: low | normal | high. Shapes the gate
     * prompt's threshold. Default 'normal'; junk falls back to the safe default.
     */
    public static function ambientEagerness(): string
    {
        $value = Setting::getValue('teams_ambient_eagerness');

        return in_array($value, ['low', 'normal', 'high'], true) ? $value : 'normal';
    }

    /** Whether the teammate may show some friendly personality. Default on (light). */
    public static function ambientBanter(): bool
    {
        $value = Setting::getValue('teams_ambient_banter');

        return $value === null || (bool) $value; // default true
    }

    /** Minimum seconds between unprompted chime-ins per conversation. Default 60, floor 5. */
    public static function ambientCooldownSeconds(): int
    {
        $value = Setting::getValue('teams_ambient_cooldown_seconds');

        return is_numeric($value) ? max(5, (int) $value) : 60;
    }

    // ── Increment H: escalation chat ref ─────────────────────────────────────

    /** The Teams conversation id of the shared 'Day to Day' chat escalations are posted to. Null when unset. */
    public static function escalationConversationId(): ?string
    {
        $v = Setting::getValue('teams_escalation_conversation_id');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /** The Bot Framework serviceUrl for that chat (needed for a proactive bot post). Null when unset. */
    public static function escalationServiceUrl(): ?string
    {
        $v = Setting::getValue('teams_escalation_service_url');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    // ── GC Chet Teams bridge ────────────────────────────────────────────────

    /** The Teams conversation id GC Chet owns. Null when unset. */
    public static function chetConversationId(): ?string
    {
        $v = Setting::getValue('teams_chet_conversation_id');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /** Whether Chet owns its configured Teams conversation. Default OFF. */
    public static function chetRoutingEnabled(): bool
    {
        return (bool) Setting::getValue('teams_chet_routing_enabled');
    }

    /** @return array<int, int> */
    public static function operatorAllowlistUserIds(): array
    {
        $raw = Setting::getValue('teams_operator_allowlist_user_ids');
        $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($list)
            ? array_values(array_map('intval', array_filter($list, 'is_numeric')))
            : [];
    }
}
