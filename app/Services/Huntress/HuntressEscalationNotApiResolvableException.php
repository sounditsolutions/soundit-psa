<?php

namespace App\Services\Huntress;

/**
 * Upstream 422 "Escalation cannot be resolved through the API" — some
 * escalation types are console-only. The approval declines with this reason;
 * nothing changed upstream.
 */
class HuntressEscalationNotApiResolvableException extends HuntressClientException {}
