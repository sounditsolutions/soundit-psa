<?php

namespace App\Services\Email;

final class ResolvedRecipients
{
    /** @param array<int,string> $cc */
    public function __construct(
        public readonly string $to,
        public readonly ?string $toName,
        public readonly array $cc,
    ) {}

    /** Counts only — never addresses (safe for audit summaries). */
    public function auditDescriptor(): string
    {
        return 'To 1, CC '.count($this->cc);
    }
}
