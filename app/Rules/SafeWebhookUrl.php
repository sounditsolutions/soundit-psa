<?php

namespace App\Rules;

use App\Support\SafeUrlInspector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule guarding an operator-set outbound webhook URL against SSRF
 * (psa-ncl1 / CO-7). Mirrors SafeTacticalUrl: delegates to SafeUrlInspector —
 * https-only, no private/reserved/link-local/metadata targets (IP literal or
 * DNS-resolved), NXDOMAIN fails closed.
 *
 * Used for the AI Technician Teams webhook (TechnicianConfig::teamsWebhookUrl),
 * which TeamsNotifier POSTs to. This is the save-time half of the defence; the
 * request-time peer-IP pin in TeamsNotifier::post() closes the DNS-rebind TOCTOU
 * this save-time check cannot.
 */
class SafeWebhookUrl implements ValidationRule
{
    public function __construct(
        private readonly string $fieldLabel = 'Tactical API URL',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute is required.');

            return;
        }

        if ($error = SafeUrlInspector::reject($value, null, $this->fieldLabel)) {
            $fail($error);
        }
    }
}
