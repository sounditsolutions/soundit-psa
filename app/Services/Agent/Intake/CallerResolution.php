<?php

namespace App\Services\Agent\Intake;

/**
 * Value object returned by CallerResolver.
 *
 * Sources:
 *   'existing'         — client_id was already set on the PhoneCall at creation
 *   'call_history'     — matched a prior resolved call with the same number
 *   'content_name'     — matched by caller-identified name (with optional company corroboration)
 *   'content_company'  — matched only by caller-identified company (person unknown)
 *   'unresolved'       — no signal strong enough; pipeline should HOLD the call
 */
final readonly class CallerResolution
{
    public function __construct(
        public bool $resolved,
        public ?int $clientId = null,
        public ?int $personId = null,
        public string $source = 'unresolved',
    ) {}

    public static function unresolved(): self
    {
        return new self(false);
    }
}
