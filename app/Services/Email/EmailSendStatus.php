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
     * Nothing was transmitted, and that is PROVEN: a skip (no mailbox / no contact
     * email), a failure before the Graph call, or a Graph REJECTION (a 4xx answer —
     * bad credentials, mailbox not found, malformed payload, throttled). sendMail
     * queues nothing on a rejection, so no client email exists: safe to act on, and
     * safe to retry once the named cause is fixed. Must never be filed as maybe-sent —
     * that would write an idempotency row and block the legitimate retry.
     */
    case NotSent = 'not_sent';

    /**
     * The Graph sendMail call failed in a way that does NOT prove non-delivery — a 5xx,
     * a 408, or a transport failure indistinguishable from a read timeout. sendMail
     * returns 202 with no body, so treat as maybe-sent.
     * Never auto-retry (would risk a duplicate client email); verify out-of-band.
     */
    case Indeterminate = 'indeterminate';

    /**
     * Graph accepted the send but the local outbound Email record write failed.
     * The client WAS emailed. Never resend; the gap is the local record only.
     */
    case SentUnrecorded = 'sent_unrecorded';
}