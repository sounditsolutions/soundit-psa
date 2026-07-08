<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class MarkdownRenderer
{
    /**
     * Convert markdown text to sanitized HTML.
     */
    public static function render(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return HtmlSanitizer::sanitize(Str::markdown($text));
    }
}
