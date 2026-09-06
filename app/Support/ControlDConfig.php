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

    /**
     * Control D profile enforced as a new sub-organisation's Global Profile. An opaque
     * vendor primary key, not a number of ours — stored and echoed verbatim.
     */
    public const DEFAULT_PROFILE_SETTING = 'controld_default_profile_id';

    /**
     * Defaults a client's provisioning code is cut with.
     *
     * Expiry and headroom are OURS: days, and the slack added to a client's asset count
     * to get the code's device limit. Both are plain integers and are validated here.
     *
     * Analytics level and intercept mode are the VENDOR'S value space, and this file
     * deliberately does not enumerate it. Cutting a code under a sub-organisation uses
     * POST /provision, which is not in Control D's public reference, so an allow-list
     * written here would be a guess that either rejects a legal value or blesses an
     * illegal one. They are stored verbatim and validated where the code is actually
     * cut, against the vendor's own response — the read-back that every Control D write
     * owes anyway, since the API answers 200 to a write that changed nothing.
     */
    public const CODE_EXPIRY_DAYS_SETTING = 'controld_code_expiry_days';

    public const CODE_DEVICE_LIMIT_HEADROOM_SETTING = 'controld_code_device_limit_headroom';

    public const CODE_ANALYTICS_LEVEL_SETTING = 'controld_code_analytics_level';

    public const CODE_INTERCEPT_MODE_SETTING = 'controld_code_intercept_mode';

    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('controld_api_key'),
            'stats_endpoint' => Setting::getValue('controld_stats_endpoint'),
            'tactical_client_org_field_id' => Setting::getValue(self::TACTICAL_CLIENT_ORG_FIELD_SETTING),
            'default_profile_id' => Setting::getValue(self::DEFAULT_PROFILE_SETTING),
            'code_expiry_days' => Setting::getValue(self::CODE_EXPIRY_DAYS_SETTING),
            'code_device_limit_headroom' => Setting::getValue(self::CODE_DEVICE_LIMIT_HEADROOM_SETTING),
            'code_analytics_level' => Setting::getValue(self::CODE_ANALYTICS_LEVEL_SETTING),
            'code_intercept_mode' => Setting::getValue(self::CODE_INTERCEPT_MODE_SETTING),
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

    /**
     * The profile enforced on a new sub-organisation, or null when unset. Null is a
     * refusal, not "no profile": onboarding a client with no enforced profile would
     * create a sub-organisation with unfiltered DNS, which is the opposite of the point.
     */
    public static function defaultProfileId(): ?string
    {
        $raw = trim((string) self::get('default_profile_id'));

        return $raw !== '' ? $raw : null;
    }

    /**
     * Days a provisioning code stays valid, or null when unset. Null is a refusal —
     * treating an unset expiry as "never" would mint a permanent enrolment code from
     * a blank form field.
     */
    public static function codeExpiryDays(): ?int
    {
        $raw = trim((string) self::get('code_expiry_days'));

        return preg_match('/^[1-9][0-9]*$/', $raw) === 1 ? (int) $raw : null;
    }

    /**
     * Devices allowed on a code ON TOP OF the client's asset count, or null when unset.
     *
     * Zero is legal and distinct from unset: it means the code admits exactly the assets
     * already on record and one more machine cannot enrol. That is why this returns
     * ?int rather than defaulting to 0 — a blank field must not silently become the
     * tightest possible limit.
     */
    public static function codeDeviceLimitHeadroom(): ?int
    {
        $raw = trim((string) self::get('code_device_limit_headroom'));

        return preg_match('/^(0|[1-9][0-9]*)$/', $raw) === 1 ? (int) $raw : null;
    }

    /**
     * Vendor-defined analytics level for a new code, verbatim, or null when blank.
     * Not enumerated here — see the constant's docblock.
     */
    public static function codeAnalyticsLevel(): ?string
    {
        $raw = trim((string) self::get('code_analytics_level'));

        return $raw !== '' ? $raw : null;
    }

    /**
     * Vendor-defined intercept mode for a new code, verbatim, or null when blank.
     * Not enumerated here — see the constant's docblock.
     */
    public static function codeInterceptMode(): ?string
    {
        $raw = trim((string) self::get('code_intercept_mode'));

        return $raw !== '' ? $raw : null;
    }

    /**
     * Whether the panel carries everything onboarding needs before it may run.
     *
     * The four required values are the ones whose absence has a wrong answer rather
     * than no answer: the Tactical field id (step 5 writes to it), the enforced profile
     * (step 2), and the code's expiry and headroom (step 4). Analytics level and
     * intercept mode are optional HERE — when blank, onboarding sends nothing for them
     * rather than inventing a value.
     */
    public static function isOnboardingConfigured(): bool
    {
        return self::tacticalClientOrgFieldId() !== null
            && self::defaultProfileId() !== null
            && self::codeExpiryDays() !== null
            && self::codeDeviceLimitHeadroom() !== null;
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
