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
        return $this->promptFence->fence($label, $this->redacted($value, $maxChars));
    }

    /**
     * Exactly the text sanitize() puts INSIDE the fence — normalized, redacted,
     * bounded and defanged the same way — but without the delimiter lines. For
     * callers that must compare a value an agent read back THROUGH a fenced
     * projection against its raw upstream source (a read->write round trip);
     * never for emitting untrusted text into a prompt, which stays fenced.
     * Shares redacted() with sanitize() so the two forms cannot drift.
     */
    public function sanitizedText(mixed $value, int $maxChars): string
    {
        return $this->promptFence->neutralizeUntrusted($this->redacted($value, $maxChars));
    }

    private function redacted(mixed $value, int $maxChars): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = $this->promptFence->normalizeUntrusted($text);

        return mb_substr($this->redactor->redact($text), 0, $maxChars);
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
