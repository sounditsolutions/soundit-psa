<?php

namespace App\Services\Technician\Emergency;

final class EmergencyAssessment
{
    /** @param array<string> $reasons */
    public function __construct(
        public readonly bool $isEmergency,
        public readonly int $severity,
        public readonly array $reasons,
        public readonly string $signature,
    ) {}
}
