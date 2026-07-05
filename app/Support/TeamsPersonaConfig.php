<?php

namespace App\Support;

use App\Models\TeamsPersona;
use Illuminate\Support\Collection;

/**
 * Enabled-persona lookups for the Teams AI-Staff Personas feature (multi-bot
 * seam). Every accessor here is scoped to enabled()=true rows only — a
 * persona with credentials but enabled=false must never surface through
 * TeamsBotConfig::appIds()/forAppId(). Personas ship dormant: an operator has
 * to explicitly flip enabled=true before a row joins the registered set.
 */
class TeamsPersonaConfig
{
    /**
     * @return Collection<int, TeamsPersona>
     */
    public static function enabled(): Collection
    {
        return TeamsPersona::query()->enabled()->get();
    }

    public static function byAppId(string $appId): ?TeamsPersona
    {
        return self::enabled()->firstWhere('bot_app_id', $appId);
    }

    public static function byTokenLabel(string $label): ?TeamsPersona
    {
        return self::enabled()->firstWhere('mcp_token_label', $label);
    }
}
