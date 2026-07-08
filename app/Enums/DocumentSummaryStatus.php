<?php

namespace App\Enums;

enum DocumentSummaryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Summary Ready',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'bg-secondary',
            self::Processing => 'bg-info',
            self::Completed => 'bg-success',
            self::Failed => 'bg-danger',
            self::Skipped => 'bg-warning text-dark',
        };
    }
}
