<?php

namespace App\Support;

/**
 * RFC 6238 time-based one-time passwords.
 *
 * Extracted verbatim from {@see ServosityConfig::generateTotp()} at the point a
 * SECOND integration needed the same algorithm (the HDB report portal service
 * subaccount, psa #340). The arithmetic is unchanged — Servosity delegates here
 * and its behaviour is bit-identical — so this is a move, not a rewrite.
 *
 * Two deliberate non-changes, because both are load-bearing for a caller that
 * already works in production:
 *
 * - base32 decoding uppercases and strips trailing `=` padding and NOTHING else.
 *   An embedded space is still a decode failure. Whitespace tolerance belongs to
 *   the entry surface (the settings form strips it on save) and not here, where
 *   silently "repairing" a seed would hide a genuinely corrupt one.
 * - an unparseable or empty secret returns null rather than throwing. A missing
 *   second factor is an ordinary configuration state, not an exception.
 */
final class Totp
{
    /** The RFC 6238 default step, and what every authenticator app assumes. */
    public const PERIOD_SECONDS = 30;

    public const DIGITS = 6;

    /**
     * The current code for a base32 secret, or null when the secret is absent or
     * undecodable.
     *
     * @param  int|null  $at  Unix timestamp to generate for; defaults to now.
     *                        Present so tests can pin the RFC's own vectors —
     *                        production callers pass nothing.
     */
    public static function code(?string $base32Secret, ?int $at = null): ?string
    {
        if ($base32Secret === null || $base32Secret === '') {
            return null;
        }

        $decoded = self::base32Decode($base32Secret);
        if (! $decoded) {
            return null;
        }

        $counter = (int) floor(($at ?? time()) / self::PERIOD_SECONDS);

        // pack('N*', 0, $counter) is the 64-bit big-endian counter as two 32-bit
        // words. The high word is 0 until the year 2106.
        $hash = hash_hmac('sha1', pack('N*', 0, $counter), $decoded, true);

        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Decode a base32-encoded string, or null if it is not base32.
     */
    public static function base32Decode(string $input): ?string
    {
        $input = strtoupper(rtrim($input, '='));
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $val = strpos($map, $input[$i]);
            if ($val === false) {
                return null;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $result;
    }
}
