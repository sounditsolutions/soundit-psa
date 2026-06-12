<?php

namespace App\Enums;

enum WikiRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Quarantined = 'quarantined';
}
