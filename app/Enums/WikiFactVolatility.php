<?php

namespace App\Enums;

enum WikiFactVolatility: string
{
    case Durable = 'durable';
    case Volatile = 'volatile';
}
