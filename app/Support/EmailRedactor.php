<?php

namespace App\Support;

class EmailRedactor
{
    public const PLACEHOLDER = '[external address withheld]';

    public static function redact(string $text): string
    {
        return preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', self::PLACEHOLDER, $text) ?? $text;
    }
}
