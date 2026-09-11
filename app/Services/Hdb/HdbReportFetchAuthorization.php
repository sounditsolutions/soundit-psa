<?php

namespace App\Services\Hdb;

/**
 * The decision produced by {@see HdbReportFetchAuthorizer}: may this report be
 * fetched, and if so, whose report is it?
 *
 * An allow carries the client the fetch is scoped to. That field is the point
 * of the object — GitHub #1359 is a CROSS-CLIENT hole, so the caller must have
 * the client id in hand rather than re-deriving it from whatever it happened to
 * be holding.
 *
 * A refusal carries a symbol and nothing else. No press id, no note id, no
 * ticket id: see {@see HdbReportFetchRefusal} for why the refusal must not
 * become an oracle.
 */
final readonly class HdbReportFetchAuthorization
{
    private function __construct(
        public bool $allowed,
        public ?HdbReportFetchRefusal $refusal = null,
        public ?string $pressId = null,
        public ?int $noteId = null,
        public ?int $ticketId = null,
        public ?int $clientId = null,
    ) {}

    public static function allow(string $pressId, int $noteId, int $ticketId, int $clientId): self
    {
        return new self(true, null, $pressId, $noteId, $ticketId, $clientId);
    }

    public static function refuse(HdbReportFetchRefusal $refusal): self
    {
        return new self(false, $refusal);
    }

    public function refused(): bool
    {
        return ! $this->allowed;
    }

    /** The refusal symbol for an audit row, or null on an allow. */
    public function reason(): ?string
    {
        return $this->refusal?->value;
    }
}
