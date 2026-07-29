<?php

namespace App\Services\Email;

use App\Models\Email;

/**
 * Immutable result of an outbound ticket-reply send attempt.
 *
 * Carries enough for a caller to pick its next legal move honestly: the
 * outcome, the recorded Email (only when Sent), a human reason, and — for the
 * failing outcomes — the original throwable so a legacy caller can preserve its
 * prior exception-driven behavior. See psa-330 (false "email sent" receipt).
 */
final class EmailSendOutcome
{
    private function __construct(
        public readonly EmailSendStatus $status,
        public readonly ?Email $email = null,
        public readonly ?string $reason = null,
        public readonly ?\Throwable $cause = null,
        // Only meaningful for NotSent: true = transient, retrying the same call may work;
        // false = a skip (no mailbox / no contact email) that needs a fix first, not a retry.
        public readonly bool $retryable = false,
    ) {}

    public static function sent(Email $email): self
    {
        return new self(EmailSendStatus::Sent, email: $email);
    }

    public static function notSent(string $reason, bool $retryable = false, ?\Throwable $cause = null): self
    {
        return new self(EmailSendStatus::NotSent, reason: $reason, cause: $cause, retryable: $retryable);
    }

    public static function indeterminate(string $reason, ?\Throwable $cause = null): self
    {
        return new self(EmailSendStatus::Indeterminate, reason: $reason, cause: $cause);
    }

    public static function sentUnrecorded(string $reason, ?\Throwable $cause = null): self
    {
        return new self(EmailSendStatus::SentUnrecorded, reason: $reason, cause: $cause);
    }

    /** True when the client was (or may have been) emailed — resending is unsafe. */
    public function reachedClient(): bool
    {
        return $this->status === EmailSendStatus::Sent
            || $this->status === EmailSendStatus::Indeterminate
            || $this->status === EmailSendStatus::SentUnrecorded;
    }
}
