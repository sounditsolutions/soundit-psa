<?php

namespace App\Enums;

/**
 * How the tooling gap was detected: by an operator correcting the agent (Correction)
 * or by the agent recognising and self-reporting the gap (Agent).
 */
enum ToolingGapSource: string
{
    case Correction = 'correction';
    case Agent = 'agent';

    /**
     * Normalise model-supplied input to a known source, failing safe to Agent.
     */
    public static function fromInput(?string $value): self
    {
        return self::tryFrom(trim((string) $value)) ?? self::Agent;
    }

    public function label(): string
    {
        return match ($this) {
            self::Correction => 'Operator correction',
            self::Agent => 'Agent self-report',
        };
    }
}
