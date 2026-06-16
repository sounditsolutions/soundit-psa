<?php

namespace App\Services\Tactical;

/**
 * The result of a single bounded live read (amendment C): the value (live on
 * success, the snapshot fallback on failure) plus the SignalState that records
 * which one it is. Keeping the state alongside the value is what lets the
 * insight stamp each signal honestly (Live vs Snapshot vs Unavailable) so a
 * fetch failure can never be presented as a clean/current reading.
 *
 * @template T
 */
final readonly class BoundedRead
{
    /**
     * @param  T  $value
     */
    public function __construct(
        public mixed $value,
        public SignalState $state,
    ) {}

    public function isLive(): bool
    {
        return $this->state === SignalState::Live;
    }
}
