<?php

namespace App\Services\Chet;

use App\Services\Wiki\Mining\WikiRedactor;
use Illuminate\Support\Facades\Log;

class OperatorBridgeTextSanitizer
{
    /**
     * Presentation cap for text going into an agent prompt. Raised from 2000
     * after a silent mid-row cut of a pasted printer log produced a wrong
     * conclusion downstream (T-22782): the cap itself is fine, cutting
     * WITHOUT SAYING SO is not — pair any use of this cap with the
     * truncation metadata from sanitizeForPromptWithMeta().
     */
    public const MAX_TEXT_CHARS = 6000;

    /**
     * Storage cap for operator_inbox.text. The column is TEXT (65535 BYTES
     * on MariaDB); 16000 chars keeps a worst-case all-4-byte utf8mb4 message
     * (64000 bytes) inside it, so an oversized paste truncates predictably
     * here instead of failing the whole webhook insert on the prod driver.
     * Teams caps messages well below this, so in practice it never bites.
     */
    public const MAX_STORAGE_CHARS = 16000;

    public function __construct(
        private readonly WikiRedactor $redactor,
    ) {}

    public function sanitizeForPrompt(string $text, string $placeholder = '[operator message withheld - unsafe content]'): string
    {
        return $this->sanitizeForPromptWithMeta($text, $placeholder)['text'];
    }

    /**
     * Same pipeline (cap → redact → scan-or-withhold) with the truncation
     * facts a caller needs to present honestly. `truncated` means "text is
     * an incomplete prefix of the input" — deliberately false on the
     * withheld-placeholder path, where nothing of the original is shown at
     * all. `total_chars` is the character count of the INPUT, before the
     * cap and before redaction (redaction changes length, so a stored
     * length can never be compared to a redacted one to detect a cut).
     *
     * @return array{text: string, truncated: bool, total_chars: int}
     */
    public function sanitizeForPromptWithMeta(
        string $text,
        string $placeholder = '[operator message withheld - unsafe content]',
        int $maxChars = self::MAX_TEXT_CHARS,
    ): array {
        $totalChars = mb_strlen($text);
        $capped = mb_substr($text, 0, $maxChars);
        $redacted = $this->redactor->redact($capped);

        if ($this->redactor->scan($redacted) !== []) {
            Log::warning('[OperatorBridge] Operator message failed prompt safety scan');

            return ['text' => $placeholder, 'truncated' => false, 'total_chars' => $totalChars];
        }

        return ['text' => $redacted, 'truncated' => $totalChars > $maxChars, 'total_chars' => $totalChars];
    }

    /**
     * Ingest-side variant: identical pipeline at the storage cap, so the
     * inbox row keeps (nearly) the whole message and the 6000-char prompt
     * cap becomes presentation-only — the tail survives in the DB for any
     * reader that needs the full body.
     *
     * @return array{text: string, truncated: bool, total_chars: int}
     */
    public function sanitizeForStorage(
        string $text,
        string $placeholder = '[operator message withheld - unsafe content]',
    ): array {
        return $this->sanitizeForPromptWithMeta($text, $placeholder, self::MAX_STORAGE_CHARS);
    }
}
