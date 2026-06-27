<?php

namespace App\Enums;

/**
 * Lifecycle status of a ToolingGap backlog item.
 * All gaps are born Open; human triage moves them forward.
 */
enum ToolingGapStatus: string
{
    case Open = 'open';
    case Triaged = 'triaged';
    case Resolved = 'resolved';
    case WontFix = 'wontfix';

    /**
     * Normalise option-supplied input to a known status, failing safe to Open.
     * Allows a typo from `--status=garbage` to default to Open without crashing.
     */
    public static function fromInput(?string $value): self
    {
        return self::tryFrom(trim((string) $value)) ?? self::Open;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Triaged => 'Triaged',
            self::Resolved => 'Resolved',
            self::WontFix => "Won't fix",
        };
    }
}
