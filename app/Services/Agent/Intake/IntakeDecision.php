<?php

namespace App\Services\Agent\Intake;

final readonly class IntakeDecision
{
    public function __construct(
        public string $decision,        // 'attach' | 'create'
        public ?int $ticketId = null,   // matched open ticket (attach only; always a validated candidate)
        public float $confidence = 0.0,
        public string $reason = '',
    ) {}

    /** Convenience constructor for a create decision. */
    public static function create(string $reason = '', float $confidence = 0.0): self
    {
        return new self('create', null, $confidence, $reason);
    }

    /** True only when decision is 'attach' AND a validated ticket id is present. */
    public function isAttach(): bool
    {
        return $this->decision === 'attach' && $this->ticketId !== null;
    }
}
