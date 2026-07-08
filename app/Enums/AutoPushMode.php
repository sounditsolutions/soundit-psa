<?php

namespace App\Enums;

enum AutoPushMode: string
{
    case Push = 'push';
    case PushAndSend = 'push_and_send';

    public function label(): string
    {
        return match ($this) {
            self::Push => 'Push on generation',
            self::PushAndSend => 'Push and send on generation',
        };
    }
}
