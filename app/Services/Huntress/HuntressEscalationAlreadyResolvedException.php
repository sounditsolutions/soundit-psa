<?php

namespace App\Services\Huntress;

/**
 * Upstream 409 "Escalation has already been resolved" — an idempotency
 * signal, not a failure: the approved intent is satisfied without a state
 * change. Typed so callers can distinguish it from a real client error.
 */
class HuntressEscalationAlreadyResolvedException extends HuntressClientException {}
