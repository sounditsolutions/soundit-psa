<?php

namespace App\Services\Email;

enum RecipientContext
{
    case Direct;
    case Staged;
}
