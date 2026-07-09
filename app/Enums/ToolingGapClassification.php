<?php

namespace App\Enums;

/**
 * Why the gap exists:
 *  - ToolMissing — the agent lacked the tool entirely.
 *  - ToolUnused  — the tool/data was available but the agent failed to invoke it.
 *  - ToolBroken  — an existing tool WAS invoked but misbehaved (errored, returned
 *    wrong/empty data, timed out). Reported via `request_tool` with `tool_name`.
 */
enum ToolingGapClassification: string
{
    case ToolMissing = 'tool_missing';
    case ToolUnused = 'tool_unused';
    case ToolBroken = 'tool_broken';

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
            self::ToolBroken => 'Tool broken',
        };
    }
}
