<?php

namespace App\Enums;

enum EmailDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Inbound',
            self::Outbound => 'Outbound',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Inbound => 'bg-info',
            self::Outbound => 'bg-primary',
        };
    }
}
