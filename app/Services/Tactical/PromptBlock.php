<?php

namespace App\Services\Tactical;

use Illuminate\Support\Carbon;

final readonly class PromptBlock
{
    public function __construct(
        public string $text,
        public int $estimatedTokens,
        public ?Carbon $freshAsOf,
    ) {}
}
