<?php

namespace App\Support;

use App\Models\Setting;

class ControlDConfig
{
    /**
     * Setting holding the Tactical RMM CLIENT-scoped custom field id that carries a
     * client's Control D organisation id.
     *
     * Deliberately a setting rather than a compile-time constant like
     * CometConfig::TACTICAL_TOKEN_FIELD_ID / ServosityConfig::TACTICAL_SERVOSITY_*.
     * Those ids belong to AGENT-model fields that ship with their integration, so
     * every instance has the same ones. This field is created by each operator in
     * their own Tactical, on the CLIENT model, whose id space is separate from the
     * agent model's and is whatever their instance happened to assign. An id is
     * instance state, not a fact about the software, and guessing one writes to
     * some other client field — so it is read at call time and unset FAILS CLOSED.
     */
    public const TACTICAL_CLIENT_ORG_FIELD_SETTING = 'controld_tactical_client_field_id';

    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('controld_api_key'),
            'stats_endpoint' => Setting::getValue('controld_stats_endpoint'),
            'tactical_client_org_field_id' => Setting::getValue(self::TACTICAL_CLIENT_ORG_FIELD_SETTING),
            default => null,
        };
    }

    /**
     * The configured Tactical CLIENT custom field id, or null when it is unset or
     * not a positive integer. Callers must treat null as a refusal.
     */
    public static function tacticalClientOrgFieldId(): ?int
    {
        $raw = trim((string) self::get('tactical_client_org_field_id'));

        return preg_match('/^[1-9][0-9]*$/', $raw) === 1 ? (int) $raw : null;
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('controld_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }

    /**
     * Analytics is available when the main API key is set and we have a stats endpoint.
     * The stats endpoint is auto-detected from the org API and cached in settings.
     */
    public static function isAnalyticsConfigured(): bool
    {
        return self::isConfigured() && ! empty(self::get('stats_endpoint'));
    }
}
