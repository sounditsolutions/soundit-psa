<?php

namespace App\Services\Triage;

class ReviewResult
{
    public function __construct(
        public readonly string $assessment, // resolved, waiting_customer, waiting_us, junk, active
        public readonly string $confidence, // high, medium, low
        public readonly int $confidenceScore, // 0-100
        public readonly string $reasoning,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            assessment: $data['assessment'] ?? 'active',
            confidence: $data['confidence'] ?? 'low',
            confidenceScore: (int) ($data['confidence_score'] ?? self::inferScore($data['confidence'] ?? 'low')),
            reasoning: $data['reasoning'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'assessment' => $this->assessment,
            'confidence' => $this->confidence,
            'confidence_score' => $this->confidenceScore,
            'reasoning' => $this->reasoning,
        ];
    }

    public function isHighConfidence(): bool
    {
        return $this->confidence === 'high';
    }

    /**
     * Check if the confidence score meets the given threshold.
     */
    public function meetsThreshold(int $threshold): bool
    {
        return $this->confidenceScore >= $threshold;
    }

    /**
     * Infer a numeric score from a text confidence level (fallback if AI doesn't return score).
     */
    private static function inferScore(string $confidence): int
    {
        return match ($confidence) {
            'high' => 85,
            'medium' => 55,
            'low' => 25,
            default => 0,
        };
    }
}
