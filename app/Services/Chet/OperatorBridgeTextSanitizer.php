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
     * The one placeholder the withhold path emits. Named because readers
     * downstream must be able to recognise a withheld body as a placeholder
     * rather than a prefix — see MAX_STORAGE_BYTES and the poll tool.
     */
    public const WITHHELD_PLACEHOLDER = '[operator message withheld - unsafe content]';

    /**
     * Storage cap for operator_inbox.text, applied to the INPUT before
     * redaction. It bounds how much of a paste we keep; it does NOT bound
     * what we store, because redaction runs after it — see
     * MAX_STORAGE_BYTES, which is the guarantee the column actually needs.
     * Teams caps messages well below this, so in practice it never bites.
     */
    public const MAX_STORAGE_CHARS = 16000;

    /**
     * Hard byte ceiling for what is WRITTEN to operator_inbox.text. The
     * column is TEXT (65535 BYTES on MariaDB), and no character cap can
     * bound it: the cap runs before redaction, and redaction replaces each
     * matched token with a marker of unrelated (often greater) length, so a
     * capped-then-redacted string can be longer than the string that was
     * capped. The only measurement the column enforces is bytes on the
     * final string, so that is what sanitizeForStorage() measures and cuts.
     * Overflow here fails the webhook INSERT and loses the operator message
     * outright — and CI is sqlite, which has no such limit, so this edge is
     * never CI-proved. Do not relax it on green tests.
     */
    public const MAX_STORAGE_BYTES = 64000;

    public function __construct(
        private readonly WikiRedactor $redactor,
    ) {}

    public function sanitizeForPrompt(string $text, string $placeholder = self::WITHHELD_PLACEHOLDER): string
    {
        return $this->sanitizeForPromptWithMeta($text, $placeholder)['text'];
    }

    /**
     * Same pipeline (cap → redact → scan-or-withhold) with the truncation
     * facts a caller needs to present honestly. `truncated` means "text is
     * an incomplete prefix of the input" — deliberately false on the
     * withheld-placeholder path, where nothing of the original is shown at
     * all — that path reports `withheld` instead, and the two are mutually
     * exclusive: a withheld body must never be presented as a prefix that
     * something could be appended to. `total_chars` is the character count
     * of the INPUT, before the cap and before redaction (redaction changes
     * length, so a stored length can never be compared to a redacted one to
     * detect a cut).
     *
     * @return array{text: string, truncated: bool, total_chars: int, withheld: bool}
     */
    public function sanitizeForPromptWithMeta(
        string $text,
        string $placeholder = self::WITHHELD_PLACEHOLDER,
        int $maxChars = self::MAX_TEXT_CHARS,
    ): array {
        $totalChars = mb_strlen($text);
        $capped = mb_substr($text, 0, $maxChars);
        $redacted = $this->redactor->redact($capped);

        if ($this->redactor->scan($redacted) !== []) {
            Log::warning('[OperatorBridge] Operator message failed prompt safety scan');

            return ['text' => $placeholder, 'truncated' => false, 'total_chars' => $totalChars, 'withheld' => true];
        }

        return ['text' => $redacted, 'truncated' => $totalChars > $maxChars, 'total_chars' => $totalChars, 'withheld' => false];
    }

    /**
     * Ingest-side variant: identical pipeline at the storage cap, so the
     * inbox row keeps (nearly) the whole message and the 6000-char prompt
     * cap becomes presentation-only — the tail survives in the DB for any
     * reader that needs the full body.
     *
     * @return array{text: string, truncated: bool, total_chars: int, withheld: bool}
     */
    public function sanitizeForStorage(
        string $text,
        string $placeholder = self::WITHHELD_PLACEHOLDER,
    ): array {
        $meta = $this->sanitizeForPromptWithMeta($text, $placeholder, self::MAX_STORAGE_CHARS);

        // The char cap bounded the INPUT; the column enforces BYTES on the
        // post-redaction string we are about to write, and redaction can
        // make that longer than what was capped. So measure the string that
        // actually gets stored. mb_strcut spends a byte budget without
        // splitting a utf8mb4 sequence, and the result is a genuine prefix
        // of the redacted text — truncated in exactly the sense the flag
        // means. A withheld placeholder is short by construction and is
        // never re-cut, so it can never be relabelled as a prefix.
        if ($meta['withheld'] || strlen($meta['text']) <= self::MAX_STORAGE_BYTES) {
            return $meta;
        }

        return [
            'text' => mb_strcut($meta['text'], 0, self::MAX_STORAGE_BYTES),
            'truncated' => true,
            'total_chars' => $meta['total_chars'],
            'withheld' => false,
        ];
    }
}
