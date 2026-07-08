<?php

namespace App\Enums;

/**
 * Whether the gap arose because the agent lacked the tool entirely (ToolMissing)
 * or had it available but failed to invoke it (ToolUnused).
 */
enum ToolingGapClassification: string
{
    case ToolMissing = 'tool_missing';
    case ToolUnused = 'tool_unused';

    /**
     * Normalise model-supplied input to a known classification, failing safe to ToolMissing
     * (the agent lacked a tool — the more conservative assumption).
     */
    public static function fromInput(?string $value): self
    {
        return self::tryFrom(trim((string) $value)) ?? self::ToolMissing;
    }

    public function label(): string
    {
        return match ($this) {
            self::ToolMissing => 'Tool missing',
            self::ToolUnused => 'Tool not used',
        };
    }
}
