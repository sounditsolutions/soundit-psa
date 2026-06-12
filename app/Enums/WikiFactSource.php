<?php

namespace App\Enums;

enum WikiFactSource: string
{
    case Sync = 'sync';
    case Ticket = 'ticket';
    case Triage = 'triage';
    case Human = 'human';
}
