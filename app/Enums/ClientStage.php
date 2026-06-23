<?php

namespace App\Enums;

enum ClientStage: string
{
    case Prospect = 'prospect';
    case Active = 'active';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospect',
            self::Active => 'Active',
        };
    }
}
