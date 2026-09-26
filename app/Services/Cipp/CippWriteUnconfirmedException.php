<?php

namespace App\Services\Cipp;

/**
 * A CIPP write whose request was handed to the HTTP client and whose answer
 * did not confirm it: no answer at all (a transport failure after the send
 * began), or an answer that does not carry the vendor's success line. The
 * opposite of CippWriteNotSentException.
 *
 * $outcome says what the answer does establish:
 *  - NOT_APPLIED: upstream named a reason it made no write for this user.
 *  - UNKNOWN: upstream may have written; nothing in the answer says either way.
 *
 * $noAnswer marks the transport case. cURL raises the same exception for a
 * refused connection (nothing received) and a read timeout (possibly
 * received), so that message says only that no answer came back.
 *
 * $confirmedPart names a part of the write the answer did report as applied
 * (editUser's profile PATCH before a failed manager step), so a caller can
 * say so instead of hedging on the whole change.
 *
 * Only a bounded, control-stripped upstream line is kept — never the body.
 */
final class CippWriteUnconfirmedException extends CippClientException
{
    public const NOT_APPLIED = 'not_applied';

    public const UNKNOWN = 'unknown';

    private const UPSTREAM_LINE_MAX = 200;

    public readonly ?string $upstreamLine;

    public function __construct(
        public readonly string $endpoint,
        public readonly string $outcome,
        ?string $upstreamLine = null,
        public readonly bool $usageLocationMayHaveChanged = false,
        public readonly ?string $confirmedPart = null,
        public readonly bool $noAnswer = false,
        ?\Throwable $previous = null,
    ) {
        if (! in_array($outcome, [self::NOT_APPLIED, self::UNKNOWN], true)) {
            throw new \InvalidArgumentException("Unknown unconfirmed-write outcome {$outcome}");
        }

        $this->upstreamLine = $upstreamLine === null ? null : self::bound($upstreamLine);

        parent::__construct(
            ($noAnswer
                ? "CIPP write {$endpoint} got no answer and may have been received ({$outcome})"
                : "CIPP write {$endpoint} was sent but not confirmed ({$outcome})")
            .($this->upstreamLine !== null ? '; upstream: '.$this->upstreamLine : ''),
            0,
            $previous,
        );
    }

    private static function bound(string $line): string
    {
        $flat = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $line));

        return mb_substr($flat, 0, self::UPSTREAM_LINE_MAX);
    }
}
