<?php

namespace App\Services\Qa;

class QaScenario
{
    /**
     * @param  array<int,string>  $steps
     * @param  array<int,string>  $expectations
     * @param  array<int,string>  $watchFors
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $goal,
        public readonly string $setup,
        public readonly array $steps,
        public readonly array $expectations,
        public readonly array $watchFors,
    ) {}
}
