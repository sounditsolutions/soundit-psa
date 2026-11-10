<?php

namespace App\Services\Hdb;

/**
 * The end user's redaction choices for one press.
 * HdbApiReportClient maps the API's diagnostics_redacted and instant_replay.redacted
 * booleans to the legacy internal keys consumed here (vendor swagger.yaml,
 * ReportDetail). Synthetic tests prove contract handling, not live behaviour.
 *
 * FAIL CLOSED ON AN UNREADABLE FLAG, and note which direction that is. An
 * absent or non-boolean flag does NOT mean "redaction was not requested" — it
 * means this client cannot tell. {@see fromTicket()} returns null for that
 * case, and the client turns null into a refusal
 * ({@see HdbReportResult::REASON_REDACTION_FLAG_UNREADABLE}): no diagnostics
 * and no screenshot are written. Defaulting the flags to false — the shape a
 * `(bool) ($ticket['redactScreenshots'] ?? false)` would produce — would
 * publish a desktop screenshot the end user asked to withhold the first time
 * the vendor renamed a key, and it would do it silently.
 *
 * HdbApiReportClient never fetches a redacted sprite, and HdbReportTool
 * withholds diagnostic sections when diagnostic redaction is requested.
 */
final readonly class HdbReportRedaction
{
    private function __construct(
        /** The end user asked that diagnostic detail be limited. */
        public bool $diagnostic,
        /** The end user asked that screenshots not be shown. Never fetched. */
        public bool $screenshots,
    ) {}

    /**
     * Read both flags out of the normalized internal metadata, or null when either is
     * not a readable boolean.
     *
     * STRICT on type, on purpose. JSON booleans decode to PHP booleans, so a
     * JSON flag that says `true` arrives as `true`; anything else — a
     * string `"true"`, `1`, null, absent — is a shape this client has not
     * measured, and guessing which way it points is exactly the guess C-56
     * forbids. Null here is a refusal, never a permissive default.
     *
     * @param  array<string, mixed>  $ticket
     */
    public static function fromTicket(array $ticket): ?self
    {
        $diagnostic = $ticket['redactDiagnostic'] ?? null;
        $screenshots = $ticket['redactScreenshots'] ?? null;

        if (! is_bool($diagnostic) || ! is_bool($screenshots)) {
            return null;
        }

        return new self($diagnostic, $screenshots);
    }

    /** May the Instant Replay sprite be requested at all? */
    public function allowsScreenshots(): bool
    {
        return ! $this->screenshots;
    }
}
