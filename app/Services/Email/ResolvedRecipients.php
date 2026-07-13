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
}
