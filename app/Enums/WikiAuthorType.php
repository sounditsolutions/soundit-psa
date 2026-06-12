<?php

namespace App\Enums;

enum WikiAuthorType: string
{
    case Ai = 'ai';
    case Human = 'human';
    case System = 'system';
}
