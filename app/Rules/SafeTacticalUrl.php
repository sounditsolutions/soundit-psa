<?php

namespace App\Rules;

use App\Support\SafeUrlInspector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule guarding the Tactical API URL against SSRF / key-exfil
 * (amendment B2). Delegates to SafeUrlInspector — https-only, no
 * private/reserved/link-local/metadata targets (literal or DNS-resolved),
 * NXDOMAIN fails closed.
 */
class SafeTacticalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute is required.');

            return;
        }

        if ($error = SafeUrlInspector::reject($value)) {
            $fail($error);
        }
    }
}
