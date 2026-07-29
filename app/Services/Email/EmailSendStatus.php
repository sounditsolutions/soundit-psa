<?php

namespace App\Services\Email;

/**
 * The four distinguishable outcomes of attempting an outbound ticket-reply send.
 *
 * The distinction exists because a caller's next legal move differs per outcome:
 * a not-sent skip must be acted on (not retried blindly), a pre-dispatch failure
 * is safe to retry, and a maybe-sent outcome must NOT be retried (that is a
 * double-send) — it needs out-of-band verification. See psa-330.
 */
enum EmailSendStatus: string
{
    /** Delivered to Graph and recorded locally. */
    case Sent = 'sent';

    /**
     * Nothing was transmitted — either skipped (no mailbox / no contact email)
     * or a failure before the Graph call. Safe to act on / retry.
     */
    case NotSent = 'not_sent';

    /**
     * The Graph sendMail call itself failed (timeout / 5xx). sendMail returns 202
     * with no body, so non-delivery cannot be proven — treat as maybe-sent.
     * Never auto-retry (would risk a duplicate client email); verify out-of-band.
     */
    case Indeterminate = 'indeterminate';

    /**
     * Graph accepted the send but the local outbound Email record write failed.
     * The client WAS emailed. Never resend; the gap is the local record only.
     */
    case SentUnrecorded = 'sent_unrecorded';
}
