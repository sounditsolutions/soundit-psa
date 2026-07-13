<?php

namespace App\Services\Email;

final class ResolvedRecipients
{
    /**
     * @param  array<int,string>  $cc
     * @param  array<int,string>  $custom  resolved addresses OUTSIDE sources a/b/c (only
     *                                     possible when arbitrary recipients are allowed)
     */
    public function __construct(
        public readonly string $to,
        public readonly ?string $toName,
        public readonly array $cc,
        public readonly array $custom = [],
    ) {}

    /** Counts only — never addresses (safe for audit summaries). */
    public function auditDescriptor(): string
    {
        $descriptor = 'To 1, CC '.count($this->cc);
        if ($this->custom !== []) {
            $descriptor .= ', '.count($this->custom).' outside known contacts';
        }

        return $descriptor;
    }

    /**
     * THE canonical content-hash payload: the body bound to the exact resolved
     * audience (To + the resolver's ordered, deduped CC). The same body to a
     * different To/CC is a different action — used by the stage/direct idempotency
     * keys (psa-kt82) AND the approval-time grant/audit hash (psa-w4e0 revise), via
     * this single method so the formats can never drift.
     */
    public function hashPayload(string $body): string
    {
        return $body.'|to:'.$this->to.'|cc:'.implode(',', $this->cc);
    }

    /**
     * The full resolved audience (addresses included) for the durable pre-send
     * audit artifact (technician_action_logs.approved_recipients) — NOT for
     * summaries, which stay counts-only via auditDescriptor().
     *
     * @return array{to: string, cc: array<int,string>, custom: array<int,string>}
     */
    public function toAuditArray(): array
    {
        return ['to' => $this->to, 'cc' => $this->cc, 'custom' => $this->custom];
    }
}
