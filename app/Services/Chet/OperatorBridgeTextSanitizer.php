<?php

namespace App\Services\Chet;

use App\Services\Wiki\Mining\WikiRedactor;
use Illuminate\Support\Facades\Log;

class OperatorBridgeTextSanitizer
{
    private const MAX_TEXT_CHARS = 2000;

    public function __construct(
        private readonly WikiRedactor $redactor,
    ) {}

    public function sanitizeForPrompt(string $text, string $placeholder = '[operator message withheld - unsafe content]'): string
    {
        $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);
        $redacted = $this->redactor->redact($text);

        if ($this->redactor->scan($redacted) !== []) {
            Log::warning('[OperatorBridge] Operator message failed prompt safety scan');

            return $placeholder;
        }

        return $redacted;
    }
}
