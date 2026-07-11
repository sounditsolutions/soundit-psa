<?php

namespace App\Enums;

/**
 * Why the gap exists:
 *  - ToolMissing — the agent lacked the tool entirely.
 *  - ToolUnused  — the tool/data was available but the agent failed to invoke it.
 *  - ToolBroken  — an existing tool WAS invoked but misbehaved (errored, returned
 *    wrong/empty data, timed out). Reported via `request_tool` with `tool_name`.
 *  - ToolUngranted / ToolUnconfigured — SYSTEM-assigned by request_tool
 *    auto-classification (psa-ve9v) when a "missing" report names a tool that
 *    already exists in the MCP catalog: the remedy is an operator token grant
 *    (ungranted) or instance integration config (unconfigured), not a build.
 *    Agents never self-select these; `tool_name` carries the matched tool.
 */
enum ToolingGapClassification: string
{
    case ToolMissing = 'tool_missing';
    case ToolUnused = 'tool_unused';
    case ToolBroken = 'tool_broken';
    case ToolUngranted = 'tool_ungranted';
    case ToolUnconfigured = 'tool_unconfigured';

    /**
     * Normalise model-supplied input to a known classification, failing safe to ToolMissing
     * (the agent lacked a tool — the more conservative assumption).
     */
    public static function fromInput(?string $value): self
    {
        return self::tryFrom(trim((string) $value)) ?? self::ToolMissing;
    }

    /**
     * Like fromInput, but the system-assigned cases (ToolUngranted /
     * ToolUnconfigured) are not agent-selectable — they fail back to
     * ToolMissing and only auto-classification can assign them.
     */
    public static function fromAgentInput(?string $value): self
    {
        $parsed = self::fromInput($value);

        return match ($parsed) {
            self::ToolUngranted, self::ToolUnconfigured => self::ToolMissing,
            default => $parsed,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ToolMissing => 'Tool missing',
            self::ToolUnused => 'Tool not used',
            self::ToolBroken => 'Tool broken',
            self::ToolUngranted => 'Tool not granted',
            self::ToolUnconfigured => 'Integration not configured',
        };
    }
}
