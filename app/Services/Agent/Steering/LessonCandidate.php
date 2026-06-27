<?php

namespace App\Services\Agent\Steering;

final readonly class LessonCandidate
{
    public function __construct(
        public string $type,             // 'knowledge' | 'tooling' | 'none'
        public ?string $page = null,     // knowledge only
        public ?string $anchor = null,   // knowledge only
        public ?string $subjectKey = null, // knowledge only
        public ?string $statement = null,  // knowledge: the fact; tooling: short gap description
        public ?float $confidence = null,  // knowledge only
    ) {}

    public static function none(): self
    {
        return new self('none');
    }

    public function isKnowledge(): bool
    {
        return $this->type === 'knowledge';
    }

    public function isTooling(): bool
    {
        return $this->type === 'tooling';
    }
}
