<?php

namespace App\Enums;

enum PersonType: string
{
    case User = 'user';
    case ServiceAccount = 'service_account';
    case SharedMailbox = 'shared_mailbox';
    case RoomResource = 'room_resource';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::ServiceAccount => 'Service Account',
            self::SharedMailbox => 'Shared Mailbox',
            self::RoomResource => 'Room/Resource',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::User => 'bi-person',
            self::ServiceAccount => 'bi-gear',
            self::SharedMailbox => 'bi-envelope',
            self::RoomResource => 'bi-door-open',
        };
    }

    public function isBillable(): bool
    {
        return $this === self::User;
    }

    public function canHavePortal(): bool
    {
        return $this === self::User;
    }
}
