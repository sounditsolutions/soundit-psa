<?php

namespace App\Enums;

enum AlertSource: string
{
    case Tactical = 'tactical';
    case Ninja = 'ninja';
    case Comet = 'comet';
    case Huntress = 'huntress';

    public function label(): string
    {
        return match ($this) {
            self::Tactical => 'Tactical RMM',
            self::Ninja => 'NinjaRMM',
            self::Comet => 'Comet Backup',
            self::Huntress => 'Huntress',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Tactical => 'bi-hdd-network',
            self::Ninja => 'bi-hdd-network',
            self::Comet => 'bi-cloud-arrow-up',
            self::Huntress => 'bi-shield-check',
        };
    }
}
