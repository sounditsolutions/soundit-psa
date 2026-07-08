<?php

namespace App\Services\Chet;

use App\Services\Technician\PromptFence;
use App\Services\Wiki\Mining\WikiRedactor;

class ChetDataSurfaceTextSanitizer
{
    public function __construct(
        private readonly WikiRedactor $redactor,
        private readonly PromptFence $promptFence,
    ) {}

    public function sanitize(string $label, mixed $value, int $maxChars): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = $this->promptFence->normalizeUntrusted($text);
        $redacted = $this->redactor->redact($text);
        $redacted = mb_substr($redacted, 0, $maxChars);

        return $this->promptFence->fence($label, $redacted);
    }

    /**
     * @param  array<int, string>  $nullSentinels
     */
    public function sanitizeNullable(string $label, mixed $value, int $maxChars, array $nullSentinels = []): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        foreach ($nullSentinels as $sentinel) {
            if (mb_strtolower($text) === mb_strtolower($sentinel)) {
                return null;
            }
        }

        return $this->sanitize($label, $text, $maxChars);
    }
}
