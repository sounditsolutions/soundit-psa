<?php

namespace App\Services\Technician\Notify;

use App\Models\TechnicianActionLog;
use App\Services\Technician\Cockpit\CockpitQuery;

final class TechnicianDigest
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly bool $isEmpty,
    ) {}
}

/**
 * Builds the operator's daily digest from the tested 1A/1B read models: pending
 * approvals (oldest-first), how many tickets need a human, and what the AI executed
 * in the last 24h. Pure reads — no side effects. (Emergencies are Phase 2.)
 */
class DigestBuilder
{
    public function __construct(private readonly CockpitQuery $cockpit) {}

    public function build(): TechnicianDigest
    {
        $pending = $this->cockpit->pendingDrafts();
        $needsYou = $this->cockpit->needsAttention()->count();
        $done = TechnicianActionLog::where('result_status', 'executed')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $isEmpty = $pending->isEmpty() && $needsYou === 0 && $done === 0;

        $lines = [
            'AI Technician — daily summary',
            '',
            "Awaiting your approval: {$pending->count()}",
            "Need a human (couldn't draft): {$needsYou}",
            "Handled autonomously (last 24h): {$done}",
        ];

        if ($pending->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Oldest awaiting:';
            foreach ($pending->take(5) as $run) {
                $client = $run->ticket?->client?->name ?? 'Unknown client';
                $subject = $run->ticket?->subject ?? "Ticket #{$run->ticket_id}";
                $age = optional($run->created_at)->diffForHumans() ?? '';
                $lines[] = "• {$client} — {$subject} ({$age})";
            }
        }

        return new TechnicianDigest('AI Technician — daily summary', implode("\n", $lines), $isEmpty);
    }
}
