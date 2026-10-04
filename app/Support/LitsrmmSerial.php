<?php

namespace App\Support;

/**
 * Serial-number identity for LITSRMM device matching — and the refusal to
 * treat a placeholder as one.
 *
 * A serial match is an IDENTITY CLAIM, the strongest this sync makes, and it
 * writes into the billing system of record. That is only defensible when the
 * value is actually a manufacturer identity.
 *
 * THE DANGER IS NOT THE OBVIOUS ONE. If several machines share a placeholder
 * the matcher sees ambiguity and refuses — correct, if by accident. The quiet
 * defect is the opposite case: exactly one asset and exactly one incoming
 * device both carrying "Default string" join AS CERTAIN, silently merging two
 * different machines into one asset. That case gets MORE likely as the
 * candidate pool shrinks, so a 34-device estate sits squarely in its range.
 *
 * THIS IS NOT HYPOTHETICAL HERE. Live data today: BandCDesktop reports
 * "Default string" and Zachary-PC reports "System Serial Number". They do not
 * collide only because those two placeholders happen to differ — a third
 * machine reporting "Default string" would merge into BandCDesktop's asset.
 *
 * THE RULE: a placeholder is not a weak serial, it is the ABSENCE of one. A
 * device carrying one must be treated exactly as if the field were empty and
 * fall through to hostname matching — never match on junk.
 *
 * Ported deliberately from the RMM's own server/src/rmm/serial.ts so both
 * sides of the integration refuse the same values. The duplication is a known
 * drift risk; the list is stable and the cost of disagreeing is a wrongly
 * merged asset, so it is the lesser evil.
 */
class LitsrmmSerial
{
    /**
     * Compared in SQUASHED form, so "To Be Filled By O.E.M.",
     * "TO BE FILLED BY OEM" and "tobefilledbyoem" are one entry.
     */
    private const PLACEHOLDERS = [
        'default string', 'default',
        'to be filled by o.e.m.', 'to be filled by oem', 'filled by oem',
        'system serial number', 'base board serial number',
        'chassis serial number', 'module part number',
        'serial number', 'serial',
        'not applicable', 'not specified', 'not available',
        'unknown', 'invalid', 'none', 'null', 'nil', 'empty',
        'n/a', 'na', 'oem', 'x',
        '0123456789', '123456789', '1234567890',
    ];

    /**
     * Below this a value carries too little information to be an identity.
     * Real service tags start at seven characters (Dell) and run longer (HP
     * ten, Lenovo eight), so four is generous rather than aggressive.
     */
    private const MIN_PLAUSIBLE_LENGTH = 4;

    /**
     * The value to match on, or NULL when it is not an identity.
     *
     * Punctuation is deliberately KEPT in the returned key: ABC-123 and
     * ABC123 are different serials on some vendors, and collapsing them would
     * invent matches. Punctuation is ignored only when testing against the
     * placeholder family, where the goal is the opposite — catching every
     * spelling of the same non-value.
     */
    public static function identity(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $collapsed = preg_replace('/\s+/', ' ', $raw);
        $key = mb_strtoupper(trim($collapsed ?? ''));
        if ($key === '') {
            return null;
        }

        $squashed = self::squash($key);
        if ($squashed === '' || self::isPlaceholder($squashed)) {
            return null;
        }

        if (mb_strlen($squashed) < self::MIN_PLAUSIBLE_LENGTH) {
            return null;
        }

        // A single character repeated is not an identity: 0000000, XXXXXXX.
        if (preg_match('/^(.)\1+$/u', $squashed) === 1) {
            return null;
        }

        return $key;
    }

    /**
     * The list is written readably and squashed HERE, at comparison time.
     *
     * Squashing only the incoming value and not the list is a bug that reads
     * as correct: "Default string" squashes to "defaultstring" and would then
     * miss the entry "default string" entirely, letting the exact value this
     * class exists to refuse through as an identity. It was written that way
     * first and six tests caught it.
     */
    private static function isPlaceholder(string $squashed): bool
    {
        foreach (self::PLACEHOLDERS as $placeholder) {
            if (self::squash($placeholder) === $squashed) {
                return true;
            }
        }

        return false;
    }

    /** Strip everything non-alphanumeric and casefold. */
    private static function squash(string $value): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');
    }
}
