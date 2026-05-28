<?php

namespace App\Enums;

enum PhoneDirectoryListType: string
{
    case Blocked = 'blocked';
    case Allowed = 'allowed';

    public function label(): string
    {
        return match ($this) {
            self::Blocked => 'Blocked',
            self::Allowed => 'Allowed',
        };
    }
}
