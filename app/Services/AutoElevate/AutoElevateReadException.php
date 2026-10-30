<?php

namespace App\Services\AutoElevate;

/**
 * A degraded AutoElevate read. Thrown instead of returning a partial or empty list
 * (C-56: a degraded read must SCREAM). The message is a fixed, operator-safe label;
 * vendor bodies, headers, exception text and the key are never carried.
 */
class AutoElevateReadException extends \RuntimeException
{
    /**
     * @param  ?int  $retryAfterS  for an http_429 only: the shared vendor cooldown in seconds
     *                             (AutoElevateClient::cooldownSeconds of the vendor's Retry-After)
     */
    public function __construct(public readonly string $reason, ?\Throwable $previous = null, public readonly ?int $retryAfterS = null)
    {
        parent::__construct("AutoElevate read failed: {$reason}", 0, $previous);
    }

    /**
     * One plain sentence for the operator staring at a label, or null when the label has no
     * useful elaboration and should stand alone. Only the reasons whose meaning is NOT
     * evident from the label itself are listed; a hint that merely restates the label is
     * noise. Operator-safe by construction: fixed strings, no vendor text, no key.
     *
     * Wording note: the paging reasons are raised from the shared page walk, which serves
     * BOTH the computers read and the companies read, so these sentences say "rows" rather
     * than "machines" — a company-list failure must not tell an operator that a company
     * reports too many machines.
     */
    public function operatorHint(): ?string
    {
        return self::hintFor($this->reason);
    }

    /**
     * @see operatorHint() — the same text, for a surface that only carries the label string.
     *
     * Accepts null because the surfaces that call it treat the reason as nullable (the panel
     * partial guards its own wrapper with `@if($reason)`). A degraded read must SCREAM, and a
     * TypeError from the hint lookup would make it CRASH instead — the panel would 500 and the
     * operator would see no explanation at all, inverting C-56 exactly.
     */
    public static function hintFor(?string $reason): ?string
    {
        return match ($reason) {
            // The threshold is DERIVED, never typed as a literal: a change to either constant
            // must move the sentence with it, or the screen states a bound that is not the one
            // being enforced.
            'paging_over_cap' => 'This company reports more rows than one read can collect (over '
                .number_format(AutoElevateReadService::MAX_PAGES * AutoElevateClient::MAX_TAKE).'). '
                .'Nothing was listed because a partial list would look complete.',
            'paging_count_mismatch' => 'AutoElevate returned a different number of distinct rows than it said it held, '
                .'so either rows are missing from what it sent or it sent rows it does not admit to holding. Nothing was '
                .'listed because the list cannot be trusted either way. Retry; if it persists, the vendor is paging inconsistently.',
            'timestamp_implausible' => 'AutoElevate reported a check-in time outside any believable range, '
                .'so no machine was shown rather than showing a wrong date.',
            default => null,
        };
    }
}
