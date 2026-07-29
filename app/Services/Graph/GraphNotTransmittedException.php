<?php

namespace App\Services\Graph;

/**
 * A Graph failure that PROVABLY happened before the request left this process: token
 * acquisition (which precedes every authenticated request) or a connection that was never
 * established (DNS / TCP / TLS). Nothing was transmitted, so the caller may retry safely —
 * and, for sendMail, must NOT file the failure as maybe-sent: that writes an idempotency
 * record, raises false manual-verification work, and silently swallows the queued client
 * reply for the whole dedup window (psa-330).
 *
 * A failure AFTER the bytes went out — a read timeout, a 5xx — is deliberately NOT this
 * exception: sendMail answers 202 with no body, so such a failure cannot disprove delivery
 * and must stay maybe-sent. Extends GraphClientException so every existing catch site,
 * status code and response body keeps working unchanged.
 */
class GraphNotTransmittedException extends GraphClientException {}
