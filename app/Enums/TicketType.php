<?php

namespace App\Enums;

enum TicketType: string
{
    case Incident = 'incident';
    case ServiceRequest = 'service_request';
    case Change = 'change';
    case Problem = 'problem';

    public function label(): string
    {
        return match ($this) {
            self::Incident => 'Incident',
            self::ServiceRequest => 'Service Request',
            self::Change => 'Change',
            self::Problem => 'Problem',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Incident => 'bi-exclamation-triangle',
            self::ServiceRequest => 'bi-clipboard-check',
            self::Change => 'bi-arrow-repeat',
            self::Problem => 'bi-bug',
        };
    }
}
