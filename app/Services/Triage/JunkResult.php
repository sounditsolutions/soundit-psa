<?php

namespace App\Services\Triage;

class JunkResult
{
    public function __construct(
        public readonly bool $isJunk,
        public readonly string $confidence, // "high" or "medium"
        public readonly string $reason,
        public readonly string $pattern, // "auto_reply", "bounce", "spam", "automated_notification"
    ) {}

    public function isHighConfidence(): bool
    {
        return $this->confidence === 'high';
    }

    public function toArray(): array
    {
        return [
            'is_junk' => $this->isJunk,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'pattern' => $this->pattern,
        ];
    }
}
