<?php

namespace App\Enums;

enum OperatorMessageCategory: string
{
    case Escalation = 'escalation';
    case SteerRequest = 'steer_request';
    case DailyReport = 'daily_report';
    case Reply = 'reply';

    public function label(): string
    {
        return match ($this) {
            self::Escalation => 'Escalation',
            self::SteerRequest => 'Steer request',
            self::DailyReport => 'Daily report',
            self::Reply => 'Reply',
        };
    }
}
