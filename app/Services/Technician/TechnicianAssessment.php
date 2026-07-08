<?php

namespace App\Services\Technician;

/** The "can I own this ticket?" result (spec §4.2/§7). */
final class TechnicianAssessment
{
    /**
     * @param  string[]  $reasons
     */
    public function __construct(
        public readonly float $confidence,
        public readonly bool $ownable,
        public readonly array $reasons,
        public readonly int $tokensUsed,
    ) {}
}
