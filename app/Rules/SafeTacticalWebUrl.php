<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule for the Tactical *web dashboard* URL (psa-6h5r / amendment L).
 *
 * Unlike the API URL, this is a pure browser link target — PSA never fetches or
 * even resolves it server-side (only the operator's browser navigates to it), so
 * it carries NO SSRF / key-exfil risk. The bar is therefore exactly: an absolute
 * `https://` URL with a parseable host. javascript:/data:/non-URL are rejected;
 * a host that the PSA server's resolver happens not to see (split-horizon DNS) is
 * NOT rejected — DNS / private-range blocking would only cause false rejections
 * here and buys no safety (that hardening belongs on the API URL, which IS
 * fetched server-side — see App\Rules\SafeTacticalUrl).
 *
 * Empty is allowed (the field is optional); a `nullable` rule short-circuits
 * before this runs, so a non-empty value is what reaches us.
 */
class SafeTacticalWebUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // optional — `nullable` lets a blank value through.
        }

        $parts = parse_url($value);

        if ($parts === false || empty($parts['host'])) {
            $fail('Enter a valid Tactical web URL.');

            return;
        }

        if (($parts['scheme'] ?? null) !== 'https') {
            $fail('The Tactical web URL must use https://.');
        }
    }
}
