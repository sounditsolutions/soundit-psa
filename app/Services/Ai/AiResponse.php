<?php

namespace App\Services\Ai;

class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly string $stopReason = 'end_turn',
        public readonly array $toolCalls = [],
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }
}
