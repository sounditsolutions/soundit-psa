<?php

namespace App\Enums;

enum EmergencyState: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
}
