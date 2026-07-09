<?php

namespace App\Services\Briefing;

/**
 * Immutable value object holding one technician's assembled briefing: the email
 * subject + markdown body, the snapshot counts (persisted for auditing), and an
 * {@see self::$isEmpty} flag so the caller can skip technicians with nothing to
 * report. Mirrors the shape of {@see \App\Services\Technician\Notify\TechnicianDigest}.
 */
final class BriefingContent
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly int $openTicketCount,
        public readonly int $alertCount,
        public readonly int $voicemailCount,
        public readonly int $slaRiskCount,
        public readonly bool $aiSuggestionsIncluded,
        public readonly bool $isEmpty,
    ) {}
}
