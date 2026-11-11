<?php

namespace App\Services\Hdb;

/**
 * The outcome of one press-report fetch: a status, a closed-vocabulary reason,
 * and — only on a fetch that got far enough — the decoded payloads.
 *
 * WHAT THE PAYLOAD IS. `ticket` and `report` are the vendor's own decoded JSON,
 * after redaction ({@see HdbReportRedaction}) and never before it. They are
 * vendor-controlled data, so they are DATA: nothing in this object is
 * safe to interpolate into a rendered surface without escaping, and the
 * operator-facing {@see message()} deliberately draws from a fixed list rather
 * than from anything the vendor said.
 *
 * WHY A DEGRADED FETCH STILL CARRIES ITS PAYLOAD. docs/ARCHITECTURE.md
 * § "Vendor response shapes" rule 3: a degraded read must SCREAM rather than
 * return a clean empty result. Returning `[]` for diagnostics that arrived
 * missing `events` would read to a technician as "that machine logged no
 * errors", which is the false all-clear that rule exists to forbid. So the
 * bytes that DID arrive are preserved, {@see $missingSections} names what did
 * not, and the status is Degraded — which is not Fetched, and any caller
 * branching on `ok()` writes nothing.
 */
final readonly class HdbReportResult
{
    /** Everything asked for arrived. */
    public const REASON_OK = 'ok';

    /**
     * The API reports a pending, requested or pressed status, not complete.
     */
    public const REASON_UPLOAD_INCOMPLETE = 'upload_incomplete';

    /**
     * The API status is absent or unrecognized, so completeness is unknown.
     */
    public const REASON_UPLOAD_GATE_MISSING = 'upload_gate_missing';

    /**
     * A redaction flag was absent or was not a boolean. Fail closed: the fetch
     * is refused rather than guessing that redaction was not requested, because
     * the guess that costs least to be wrong about is the one that writes
     * nothing. Never silently treated as "not redacted".
     */
    public const REASON_REDACTION_FLAG_UNREADABLE = 'redaction_flag_unreadable';

    /** Diagnostics arrived missing sections this integration reads. */
    public const REASON_REPORT_SECTIONS_MISSING = 'report_sections_missing';

    /** The body is not JSON, or is JSON that is not an object. */
    public const REASON_UNREADABLE_PAYLOAD = 'unreadable_payload';

    /** An HTTP status not otherwise mapped; redirects are not followed. */
    public const REASON_UNEXPECTED_RESPONSE = 'unexpected_response';

    /** The press id offered is not a press id, or is not bound to this ticket. */
    public const REASON_NOT_AUTHORIZED = 'not_authorized';

    /** The API key is missing, rejected or lacks permission — see {@see $authReason}. */
    public const REASON_NOT_AUTHENTICATED = 'not_authenticated';

    /** Transport failure or an upstream server error. */
    public const REASON_TRANSPORT_ERROR = 'transport_error';

    /**
     * @param  array<string, mixed>|null  $ticket  normalized report metadata
     * @param  array<string, mixed>|null  $report  decoded diagnostics, post-redaction
     * @param  list<string>  $missingSections  diagnostic sections that did not arrive
     * @param  string|null  $authReason  the API authentication or permission failure reason
     * @param  list<array{png: string, width: int, height: int, index: int, total: int}>  $frames  newest sprite frames, oldest first
     * @param  string|null  $framesNote  why fewer frames came back than were tried first; null when none were dropped
     */
    private function __construct(
        public HdbReportStatus $status,
        public string $reason,
        public ?string $pressId = null,
        public ?array $ticket = null,
        public ?array $report = null,
        public array $missingSections = [],
        public ?HdbReportFetchRefusal $refusal = null,
        public ?string $authReason = null,
        public ?HdbReportRedaction $redaction = null,
        public ?string $screenshot = null,
        public ?int $retryAfterSeconds = null,
        public ?string $spriteReason = null,
        public string $source = 'portal',
        public ?int $httpStatus = null,
        public ?string $apiCode = null,
        public array $frames = [],
        public ?string $spriteDetail = null,
        public ?string $framesNote = null,
    ) {}

    /**
     * @param  array<string, mixed>  $ticket
     * @param  array<string, mixed>  $report
     */
    public static function fetched(
        string $pressId,
        array $ticket,
        array $report,
        HdbReportRedaction $redaction,
        ?string $screenshot = null,
    ): self {
        return new self(
            HdbReportStatus::Fetched,
            self::REASON_OK,
            $pressId,
            $ticket,
            $report,
            [],
            null,
            null,
            $redaction,
            $screenshot,
        );
    }

    /**
     * A fetch that arrived incomplete. The payload rides along — see the class
     * docblock for why discarding it would be the worse failure — under a
     * status that is not Fetched.
     *
     * @param  array<string, mixed>  $ticket
     * @param  array<string, mixed>  $report
     * @param  list<string>  $missingSections
     */
    public static function degraded(
        string $pressId,
        array $ticket,
        array $report,
        array $missingSections,
        HdbReportRedaction $redaction,
        ?string $screenshot = null,
    ): self {
        return new self(
            HdbReportStatus::Degraded,
            self::REASON_REPORT_SECTIONS_MISSING,
            $pressId,
            $ticket,
            $report,
            array_values($missingSections),
            null,
            null,
            $redaction,
            $screenshot,
        );
    }

    /** @param array<string, mixed> $ticket */
    public static function incomplete(string $pressId, array $ticket, string $reason = self::REASON_UPLOAD_INCOMPLETE): self
    {
        return new self(HdbReportStatus::Incomplete, $reason, $pressId, $ticket);
    }

    public static function refused(HdbReportFetchRefusal $refusal): self
    {
        return new self(HdbReportStatus::Refused, self::REASON_NOT_AUTHORIZED, null, null, null, [], $refusal);
    }

    public static function unauthenticated(string $pressId, string $authReason): self
    {
        return new self(
            HdbReportStatus::Unauthenticated,
            self::REASON_NOT_AUTHENTICATED,
            $pressId,
            null,
            null,
            [],
            null,
            $authReason,
        );
    }

    public static function unreachable(string $pressId, string $reason = self::REASON_TRANSPORT_ERROR): self
    {
        return new self(HdbReportStatus::Unreachable, $reason, $pressId);
    }

    public static function malformed(string $pressId, string $reason = self::REASON_UNREADABLE_PAYLOAD): self
    {
        return new self(HdbReportStatus::Malformed, $reason, $pressId);
    }

    /**
     * May a caller write this report onto a ticket?
     *
     * ONLY a whole fetch. Degraded is deliberately excluded: its payload exists
     * so the failure can be reported with evidence, not so it can be imported
     * as if it were complete.
     */
    public function importable(): bool
    {
        return $this->status === HdbReportStatus::Fetched;
    }

    /** API-only failure factory; legacy factories retain their contracts. */
    public static function apiFailure(HdbReportStatus $status, string $reason, ?int $retryAfterSeconds = null): self
    {
        return new self($status, $reason, retryAfterSeconds: $retryAfterSeconds);
    }

    public function fromApi(?int $httpStatus = null, ?string $code = null): self
    {
        if ($this->source === 'api' && $this->httpStatus === $httpStatus && $this->apiCode === $code) {
            return $this;
        }
        $values = get_object_vars($this);
        $values['source'] = 'api';
        $values['httpStatus'] = $httpStatus;
        $values['apiCode'] = $code;

        return new self(...$values);
    }

    /**
     * The newest Instant Replay frames, oldest first, each a PNG with its
     * size and its place in the sprite ({@see HdbSpriteFrames::extract()}),
     * and the note saying why fewer came back than were tried first.
     *
     * @param  list<array{png: string, width: int, height: int, index: int, total: int}>  $frames
     */
    public function withFrames(array $frames, ?string $note = null): self
    {
        $values = get_object_vars($this);
        $values['frames'] = array_values($frames);
        $values['framesNote'] = $frames === [] ? null : $note;

        return new self(...$values);
    }

    /**
     * Add the API sprite outcome without changing the legacy factory contracts.
     * $detail says, for an omitted image that arrived, what was measured
     * (bytes and W x H) and which limit it met.
     */
    public function withSpriteOutcome(?string $reason, ?string $detail = null): self
    {
        if ($reason !== null && ! in_array($reason, ['redacted', 'oversize', 'not_image', 'fetch_failed', 'not_advertised', 'layout_invalid', 'unsupported_format', 'over_work_ceiling'], true)) {
            throw new \InvalidArgumentException('Invalid sprite reason');
        }

        $values = get_object_vars($this);
        $values['spriteReason'] = $reason;
        $values['spriteDetail'] = $reason === null ? null : $detail;
        if ($reason === 'fetch_failed' && $this->status === HdbReportStatus::Fetched) {
            $values['status'] = HdbReportStatus::Degraded;
            $values['reason'] = 'sprite_fetch_failed';
        }

        return new self(...$values);
    }

    /** Is a later attempt expected to do better? */
    public function retryable(): bool
    {
        return $this->reason === self::REASON_UPLOAD_INCOMPLETE || $this->reason === 'rate_limited';
    }

    /**
     * The operator-facing sentence. Fixed strings selected by symbol — nothing
     * from the vendor, and nothing from the press, is interpolated.
     */
    public function message(): string
    {
        return match ($this->reason) {
            self::REASON_OK => 'The HelpDesk Buttons report was fetched.',
            self::REASON_UPLOAD_INCOMPLETE => 'The endpoint has not finished uploading this report yet. Nothing was imported; it will be retried.',
            self::REASON_UPLOAD_GATE_MISSING => 'The report metadata did not say whether the upload had finished, so nothing was imported. The portal may have changed.',
            self::REASON_REDACTION_FLAG_UNREADABLE => 'The report metadata did not carry readable redaction settings, so nothing was imported rather than risk writing data the end user asked to withhold.',
            self::REASON_REPORT_SECTIONS_MISSING => 'The report arrived without sections this integration reads, so it was NOT imported — treating it as complete would show an all-clear that was never measured. The portal may have changed.',
            self::REASON_UNREADABLE_PAYLOAD => 'The portal answered with something that is not a readable report. Nothing was imported.',
            self::REASON_UNEXPECTED_RESPONSE => 'The portal did not return the report. Nothing was imported.',
            self::REASON_NOT_AUTHORIZED => 'That report is not linked to this ticket, so it was not fetched.',
            self::REASON_NOT_AUTHENTICATED => 'Could not sign in to the HelpDesk Buttons portal, so the report was not fetched.',
            self::REASON_TRANSPORT_ERROR => 'Could not reach the HelpDesk Buttons portal. Nothing was imported.',
            default => 'The HelpDesk Buttons report fetch did not complete.',
        };
    }
}
