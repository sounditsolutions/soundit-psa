<?php

namespace App\Enums;

enum WikiPageKind: string
{
    case Overview = 'overview';
    case Environment = 'environment';
    case Runbook = 'runbook';
    case Deviation = 'deviation';
    case Vendor = 'vendor';
    case Pattern = 'pattern';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Overview',
            self::Environment => 'Environment',
            self::Runbook => 'Runbook',
            self::Deviation => 'Runbook deviation',
            self::Vendor => 'Vendor',
            self::Pattern => 'Pattern',
            self::Note => 'Notes',
        };
    }
}
