<?php

namespace App\Enums;

enum WhoType: int
{
    case System = 0;
    case Agent = 1;
    case EndUser = 2;

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::Agent => 'Agent',
            self::EndUser => 'End User',
        };
    }

    public function avatarColor(): string
    {
        return match ($this) {
            self::Agent => '#1a365d',
            self::EndUser => '#2d8a4e',
            self::System => '#9ca3af',
        };
    }
}
