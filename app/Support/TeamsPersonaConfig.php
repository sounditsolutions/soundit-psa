<?php

namespace App\Support;

use App\Models\TeamsPersona;
use Illuminate\Support\Collection;

/**
 * Enabled-persona lookups for the Teams AI-Staff Personas feature (multi-bot
 * seam). Every LOOKUP accessor here (byAppId/byTokenLabel/byKey) is scoped to
 * active() — enabled=true AND credential-complete — not merely enabled()=true.
 * A persona with enabled=true but a half-finished credential wizard (missing
 * bot_app_id/tenant_id/secret) must never surface through
 * TeamsBotConfig::appIds()/forAppId(). Personas ship dormant: an operator has
 * to explicitly flip enabled=true AND finish entering credentials before a
 * row joins the registered set.
 *
 * byTokenLabelForLane() is the one deliberate exception: it is enabled()-
 * scoped rather than active()-scoped, because it answers a different
 * question (which operator-inbox LANE does this token belong to, never
 * "may this identity act as a registered bot"). See its own docblock —
 * this is the psa-2wis fix.
 *
 * enabled() is memoized per-request (a static, request-lifetime cache) since
 * it's read from several call sites within a single inbound request/job.
 * flush() drops the memo; TeamsPersona::booted() calls it on saved/deleted so
 * no caller ever observes a stale snapshot after a persona is created,
 * updated, or removed mid-request.
 */
class TeamsPersonaConfig
{
    private static ?Collection $enabledMemo = null;

    /**
     * @return Collection<int, TeamsPersona>
     */
    public static function enabled(): Collection
    {
        return self::$enabledMemo ??= TeamsPersona::query()->enabled()->get();
    }

    /**
     * The credential-complete subset of enabled() — every row here has a
     * non-blank bot_app_id, a non-blank tenant_id, AND a stored client secret.
     * This is the set that may actually act as a registered bot identity;
     * enabled() alone is not enough because an operator can flip enabled=true
     * before finishing the credential wizard.
     *
     * @return Collection<int, TeamsPersona>
     */
    public static function active(): Collection
    {
        return self::enabled()
            ->filter(fn (TeamsPersona $p) => filled($p->bot_app_id) && filled($p->tenant_id) && $p->hasSecret())
            ->values();
    }

    /** Drop the memoized enabled() snapshot. Called on persona save/delete. */
    public static function flush(): void
    {
        self::$enabledMemo = null;
    }

    public static function byAppId(string $appId): ?TeamsPersona
    {
        return self::active()->firstWhere('bot_app_id', $appId);
    }

    public static function byTokenLabel(string $label): ?TeamsPersona
    {
        return self::active()->firstWhere('mcp_token_label', $label);
    }

    /**
     * ENABLED-scoped (not active()-scoped) lookup for operator-inbox LANE
     * resolution only (see OperatorBridgeToolExecutor::pollOperatorMessages()).
     * An enabled persona's authenticated MCP token must resolve to its OWN
     * lane (operator_inbox.persona = persona_key) even while its credential
     * wizard is still incomplete (missing bot_app_id/tenant_id/secret) — so
     * it can NEVER fall through to the shared LEGACY lane (persona IS NULL)
     * and drain rows that belong to the pre-P1 single-bot operator inbox.
     *
     * This is intentionally looser than byAppId()/byTokenLabel()/byKey(),
     * which gate on active() because they answer "is this a registered bot
     * identity that may act" (JWT audience routing, outbound send-as) — a
     * fundamentally different question from "which lane does this token's
     * poll belong to." Scoping THIS lookup to active() is what caused
     * psa-2wis: an enabled-but-credential-incomplete persona's poll token
     * resolved to no persona at all, silently draining the legacy operator
     * inbox instead of seeing its own (empty) lane.
     */
    public static function byTokenLabelForLane(string $label): ?TeamsPersona
    {
        return self::enabled()->firstWhere('mcp_token_label', $label);
    }

    public static function byKey(string $personaKey): ?TeamsPersona
    {
        return self::active()->firstWhere('persona_key', $personaKey);
    }
}
