<?php

namespace App\Services\Technician;

/** A fenced, output-scanned, house-voiced reply draft awaiting approval. */
final class TechnicianDraft
{
    public function __construct(
        public readonly string $body,
        public readonly ?string $to,
        public readonly int $tokensUsed,
    ) {}
}
