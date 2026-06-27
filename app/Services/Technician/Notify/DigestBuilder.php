<?php

namespace App\Services\Technician\Notify;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Models\TechnicianActionLog;
use App\Models\WikiFact;
use App\Services\Technician\Cockpit\CockpitQuery;

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

        $learned = WikiFact::query()
            ->where('source_type', WikiFactSource::Correction)
            ->whereNot('status', WikiFactStatus::Retired->value)
            ->where('created_at', '>=', now()->subDay())
            ->latest()
            ->get();
        $learnedCount = $learned->count();

        $isEmpty = $pending->isEmpty() && $needsYou === 0 && $done === 0 && $learnedCount === 0;

        $lines = [
            'AI Technician — daily summary',
            '',
            "Awaiting your approval: {$pending->count()}",
            "Need a human (couldn't draft): {$needsYou}",
            "Handled autonomously (last 24h): {$done}",
            "Learned from your corrections (last 24h): {$learnedCount}",
        ];

        if ($pending->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Oldest awaiting:';
            foreach ($pending->take(5) as $run) {
                // psa-uvuy: the client name and ticket subject are UNTRUSTED and flow
                // into the Teams MessageCard (markdown) sink — defang the markdown/HTML
                // control chars at the interpolation point so a crafted subject like
                // `[x](http://evil)` or `<b>` can't inject a link/HTML into the operator's
                // card. We escape ONLY these dynamic fields, not the whole line.
                $client = TeamsText::escape($run->ticket?->client?->name ?? 'Unknown client');
                $subject = TeamsText::escape($run->ticket?->subject ?? "Ticket #{$run->ticket_id}");
                $age = optional($run->created_at)->diffForHumans() ?? '';
                $lines[] = "• {$client} — {$subject} ({$age})";
            }
        }

        if ($learnedCount > 0) {
            $lines[] = '';
            $lines[] = 'What I learned (from your corrections):';
            foreach ($learned->take(5) as $fact) {
                $lines[] = '• '.TeamsText::escape($fact->statement);
            }
        }

        return new TechnicianDigest('AI Technician — daily summary', implode("\n", $lines), $isEmpty);
    }
}
