<?php

namespace App\Support;

/**
 * Shared ConnectWise Manage Basic Auth parsing.
 *
 * CW format: Authorization: Basic base64("CompanyId+PublicKey:PrivateKey")
 * We only validate the PrivateKey portion (everything after the last colon).
 *
 * Used by both VerifyT2TApiKey and VerifyHuntressApiKey middleware.
 */
class CwAuthHelper
{
    /**
     * Extract the PrivateKey from a CW-format Basic Auth header value.
     *
     * Returns null if the header is missing, malformed, or not Basic auth.
     */
    public static function extractPrivateKey(string $authHeader): ?string
    {
        if (! str_starts_with($authHeader, 'Basic ')) {
            return null;
        }

        $decoded = base64_decode(substr($authHeader, 6), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        // PrivateKey is everything after the last colon
        return substr($decoded, strrpos($decoded, ':') + 1);
    }
}
