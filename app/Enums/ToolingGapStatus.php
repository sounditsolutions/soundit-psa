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
