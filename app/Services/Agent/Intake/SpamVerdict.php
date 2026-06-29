<?php

namespace App\Services\Agent\Intake;

/**
 * Value object returned by SpamAssessor.
 *
 * isSpam=false is the SAFE side: the assessor fail-softs to notSpam() on any AI error
 * or malformed response, so a never-block default is structurally guaranteed. confidence
 * is only meaningful when isSpam=true (it drives the AUTO mark+block threshold check).
 */
final readonly class SpamVerdict
{
    public function __construct(
        public bool $isSpam,
        public float $confidence = 0.0,   // 0..1, meaningful only when isSpam=true
        public string $reason = '',
    ) {}

    /** The safe verdict — never block. Returned on any assessment failure. */
    public static function notSpam(): self
    {
        return new self(false);
    }
}
