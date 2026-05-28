<?php

namespace App\Support;

use App\Models\Setting;

class AvatarHelper
{
    /**
     * Gravatar fallback options.
     * Key = value for Gravatar `d` param, Value = human label.
     */
    public const GRAVATAR_DEFAULTS = [
        '404' => 'Initials (app default)',
        'mp' => 'Mystery Person',
        'identicon' => 'Identicon',
        'monsterid' => 'Monster',
        'wavatar' => 'Wavatar',
        'retro' => 'Retro (8-bit)',
        'robohash' => 'Robot',
        'blank' => 'Blank',
    ];

    /**
     * Build a Gravatar URL for the given email.
     */
    public static function gravatarUrl(string $email, int $size = 80): string
    {
        $hash = md5(strtolower(trim($email)));
        $default = Setting::getValue('gravatar_default', '404');

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d={$default}";
    }

    /**
     * Build a DeBounce Logo URL for the given domain.
     * Returns 404 for unknown domains — onerror fallback shows initials.
     */
    public static function logoUrl(?string $domain): ?string
    {
        if (! $domain) {
            return null;
        }

        return "https://logo.debounce.com/{$domain}";
    }

    /**
     * Extract a bare domain from a website URL or email address.
     */
    public static function extractDomain(?string $website, ?string $email = null): ?string
    {
        if ($website) {
            $host = parse_url($website, PHP_URL_HOST);

            // If parse_url didn't find a host, the URL may lack a scheme
            if (! $host) {
                $host = parse_url("https://{$website}", PHP_URL_HOST);
            }

            if ($host) {
                return strtolower(ltrim($host, 'www.'));
            }
        }

        if ($email && str_contains($email, '@')) {
            return strtolower(substr($email, strpos($email, '@') + 1));
        }

        return null;
    }
}
