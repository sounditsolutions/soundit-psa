<?php

namespace App\Enums;

enum CallStatus: string
{
    case Ringing = 'ringing';
    case InProgress = 'in-progress';
    case Completed = 'completed';
    case Missed = 'missed';
    case Voicemail = 'voicemail';

    public function label(): string
    {
        return match ($this) {
            self::Ringing => 'Ringing',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Missed => 'Missed',
            self::Voicemail => 'Voicemail',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Ringing => 'bg-info',
            self::InProgress => 'bg-warning text-dark',
            self::Completed => 'bg-success',
            self::Missed => 'bg-danger',
            self::Voicemail => 'bg-secondary',
        };
    }
}
