<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Normalize a phone number to E.164 format (US default).
     *
     * Returns null if the input cannot be parsed as a valid phone number.
     */
    public static function normalize(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        // Strip everything except digits and leading +
        $cleaned = preg_replace('/[^\d+]/', '', $number);

        if ($cleaned === '' || $cleaned === '+') {
            return null;
        }

        // Already E.164 with country code (max 15 digits per E.164)
        if (str_starts_with($cleaned, '+')) {
            return strlen($cleaned) <= 16 ? $cleaned : null;
        }

        // 11-digit starting with 1 — US with country code, no +
        if (str_starts_with($cleaned, '1') && strlen($cleaned) === 11) {
            return '+' . $cleaned;
        }

        // 10-digit — assume US local number, prepend +1
        if (strlen($cleaned) === 10) {
            return '+1' . $cleaned;
        }

        // Fallback: return with + prefix if it looks like a valid-length number
        if (strlen($cleaned) >= 10 && strlen($cleaned) <= 15) {
            return '+' . $cleaned;
        }

        return null;
    }

    /**
     * Format a phone number for display in US format.
     *
     * E.g. +14253506827 → (425) 350-6827
     *      14253506827  → (425) 350-6827
     */
    public static function format(?string $number): string
    {
        if ($number === null || $number === '') {
            return 'Unknown';
        }

        $normalized = self::normalize($number);
        if ($normalized === null) {
            return $number; // Return original if can't normalize
        }

        // US number: +1 followed by 10 digits
        if (str_starts_with($normalized, '+1') && strlen($normalized) === 12) {
            $local = substr($normalized, 2);
            return sprintf('(%s) %s-%s', substr($local, 0, 3), substr($local, 3, 3), substr($local, 6, 4));
        }

        // Fallback: return normalized
        return $normalized;
    }
}
