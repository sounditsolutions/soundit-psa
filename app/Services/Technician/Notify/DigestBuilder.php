<?php

namespace App\Services\Technician\Notify;

use App\Enums\FlagAttentionCategory;
use App\Enums\TechnicianRunState;
use App\Enums\ToolingGapStatus;
use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\ToolingGap;
use App\Models\WikiFact;
use App\Services\Technician\Cockpit\CockpitQuery;
use App\Support\AgentConfig;

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

        $toolingGaps = ToolingGap::query()
            ->where('status', ToolingGapStatus::Open->value)
            ->where('created_at', '>=', now()->subDay())
            ->latest()
            ->get();
        $toolingGapCount = $toolingGaps->count();

        $escalationEnabled = AgentConfig::escalationEnabled();
        $escalations = collect();
        $escalationCount = 0;

        if ($escalationEnabled) {
            $escalations = TechnicianRun::query()
                ->where('action_type', 'flag_attention')
                ->where('created_at', '>=', now()->subDay())
                ->get();
            $escalationCount = $escalations->count();
        }

        // Intake front-door counts — gated on intakeEnabled so digest is byte-identical when off.
        $intakeEnabled = AgentConfig::intakeEnabled();
        $intakeCount = 0;
        $intakeAutoCount = 0;
        $intakeSuggestedCount = 0;

        if ($intakeEnabled) {
            $intakeRuns = TechnicianRun::query()
                ->where('action_type', 'intake_route')
                ->where('created_at', '>=', now()->subDay())
                ->get();
            $intakeCount = $intakeRuns->count();
            $intakeAutoCount = $intakeRuns->filter(
                fn ($r) => $r->state === TechnicianRunState::Done && ($r->proposed_meta['attached'] ?? false) === true
            )->count();
            $intakeSuggestedCount = $intakeRuns->filter(
                fn ($r) => $r->state === TechnicianRunState::AwaitingApproval
            )->count();
        }

        $isEmpty = $pending->isEmpty() && $needsYou === 0 && $done === 0 && $learnedCount === 0 && $toolingGapCount === 0 && $escalationCount === 0 && $intakeCount === 0;

        $lines = [
            'AI Technician — daily summary',
            '',
            "Awaiting your approval: {$pending->count()}",
            "Need a human (couldn't draft): {$needsYou}",
            "Handled autonomously (last 24h): {$done}",
            "Learned from your corrections (last 24h): {$learnedCount}",
            "Tooling gaps to review (last 24h): {$toolingGapCount}",
        ];

        if ($escalationEnabled) {
            $lines[] = "Escalations raised (last 24h): {$escalationCount}";
        }

        if ($intakeEnabled) {
            $lines[] = "Intake routed (last 24h): {$intakeCount} ({$intakeAutoCount} auto-attached, {$intakeSuggestedCount} flagged for review)";
        }

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

        if ($toolingGapCount > 0) {
            $lines[] = '';
            $lines[] = 'Tooling gaps to review:';
            foreach ($toolingGaps->take(5) as $gap) {
                // Privacy contract: only the ABSTRACT capability_gap is shown here.
                // The instance-private evidence is NEVER surfaced in the digest.
                $lines[] = '• '.TeamsText::escape($gap->capability_gap);
            }
        }

        if ($escalationCount > 0) {
            $lines[] = '';
            $lines[] = 'Escalations (by category):';
            $byCategory = $escalations->countBy(fn ($run) => FlagAttentionCategory::fromInput($run->proposed_meta['category'] ?? null)->label()
            );
            foreach ($byCategory as $label => $count) {
                $lines[] = "• {$label}: {$count}";
            }
        }

        return new TechnicianDigest('AI Technician — daily summary', implode("\n", $lines), $isEmpty);
    }
}
