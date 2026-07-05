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

    public static function byKey(string $personaKey): ?TeamsPersona
    {
        return self::active()->firstWhere('persona_key', $personaKey);
    }
}
