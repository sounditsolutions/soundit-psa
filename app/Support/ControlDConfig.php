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
     * vendor primary key, not a number of ours — stored and echoed as typed, apart
     * from surrounding whitespace (see the normalisation note below).
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
     * illegal one. They are validated where the code is actually cut, against the
     * vendor's own response — the read-back that every Control D write owes anyway,
     * since the API answers 200 to a write that changed nothing.
     *
     * NORMALISATION, stated exactly rather than as "verbatim": surrounding whitespace
     * is stripped, on write by the controller and again on read here, and nothing else
     * is touched. The interior of the value — case, punctuation, separators — is the
     * vendor's and is preserved byte for byte. Trimming is THIS PANEL'S CHOSEN INPUT
     * CONTRACT, not a proven fact about Control D: whether the vendor can distinguish
     * two identifiers by leading or trailing spaces is unknown here and is not claimed.
     * The contract is chosen because a value that differs from the operator's intent
     * only by an invisible character is a support call nobody can see the cause of. An
     * identifier that genuinely needs edge whitespace cannot be entered through this
     * panel, and that limit is deliberate.
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
     * A stored setting read as a non-negative integer, or null if it is not exactly
     * one. Shared by every numeric accessor below so that "fail closed" means the same
     * thing in each of them.
     *
     * Three ways a stored string can look like an integer and not be one, all refused:
     *
     *  - Anything the digit pattern rejects AFTER the trim below: '', '+14', '1.0',
     *    '1e3', '-1', '007'. A signed or padded form is not wrong so much as
     *    unproven — it means something other than the panel wrote this value, and the
     *    panel canonicalises precisely so that it never has to be interpreted here.
     *    Note what this does NOT refuse: a stored ' 14' is trimmed first and reads
     *    back as 14, the same edge-whitespace contract the class docblock states.
     *    Only the interior is significant to the pattern.
     *  - A digit string too large for the platform int. PHP's (int) cast SATURATES at
     *    PHP_INT_MAX instead of failing, so '9223372036854775808' would otherwise be
     *    read back as a perfectly plausible 9223372036854775807. Re-rendering the cast
     *    and comparing catches it: a value that does not survive the round trip was
     *    never this integer.
     *  - A value below the caller's floor.
     *
     * There is deliberately no upper bound beyond the platform's. What a sane expiry
     * or device limit is belongs to Control D, is not in its public reference for
     * POST /provision, and a ceiling invented here would refuse a legal value. The
     * contract this returns is only "an exact integer at least $min"; the site that
     * eventually does arithmetic with it owes its own bounds check, and can now write
     * one against a value that is precisely what it appears to be.
     */
    private static function readInt(string $key, int $min): ?int
    {
        $raw = trim((string) self::get($key));

        if (preg_match('/^(0|[1-9][0-9]*)$/', $raw) !== 1) {
            return null;
        }

        $value = (int) $raw;

        if ((string) $value !== $raw) {
            return null;
        }

        return $value >= $min ? $value : null;
    }

    /**
     * The configured Tactical CLIENT custom field id, or null when it is unset or
     * not a positive integer. Callers must treat null as a refusal.
     */
    public static function tacticalClientOrgFieldId(): ?int
    {
        return self::readInt('tactical_client_org_field_id', 1);
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
        return self::readInt('code_expiry_days', 1);
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
        return self::readInt('code_device_limit_headroom', 0);
    }

    /**
     * Vendor-defined analytics level for a new code, or null when blank — including
     * whitespace-only, which is blank an operator cannot see. Not enumerated here, and
     * trimmed but otherwise unaltered; both are explained on the constant's docblock.
     */
    public static function codeAnalyticsLevel(): ?string
    {
        $raw = trim((string) self::get('code_analytics_level'));

        return $raw !== '' ? $raw : null;
    }

    /**
     * Vendor-defined intercept mode for a new code, or null when blank — including
     * whitespace-only, which is blank an operator cannot see. Not enumerated here, and
     * trimmed but otherwise unaltered; both are explained on the constant's docblock.
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
     *
     * 🔑 WHAT THIS DOES NOT MEAN, because the name is short and the answer is narrow.
     * True says only that these four local values are present and readable. It does
     * NOT mean the integration is enabled, that an API key is stored, that Control D
     * has ever agreed any of these values exist, or that the onboarding verb is
     * granted. Every one of those is a separate check owned somewhere else, and an
     * onboarding service must make all of them before it writes anything — this is
     * the first gate it passes, not the last.
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
