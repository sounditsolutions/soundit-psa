<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Jobs\RunTriagePipeline;
use App\Models\Ticket;
use App\Support\AiConfig;
use App\Support\TriageConfig;
use Illuminate\Console\Command;

class TriageReviewOpen extends Command
{
    protected $signature = 'triage:review-open
                            {--limit= : Max tickets to process (overrides setting)}
                            {--dry-run : Show which tickets would be reviewed without dispatching}';

    protected $description = 'Review open tickets via AI conversation analysis';

    public function handle(): int
    {
        if (! TriageConfig::isEnabled()) {
            $this->error('Triage is not enabled. Enable it in Settings → Integrations.');

            return self::FAILURE;
        }

        if (! TriageConfig::autoReviewEnabled()) {
            $this->warn('Auto-review is disabled. Enable triage_auto_review in Settings.');

            return self::SUCCESS;
        }

        if (! AiConfig::isConfigured()) {
            $this->error('AI provider is not configured. Review mode requires an AI API key.');

            return self::FAILURE;
        }

        $limit = (int) ($this->option('limit') ?: TriageConfig::reviewBatchSize());
        $dryRun = $this->option('dry-run');

        $this->info("Finding open tickets eligible for review (batch size: {$limit})...");

        $tickets = $this->findEligibleTickets($limit);

        if ($tickets->isEmpty()) {
            $this->info('No tickets eligible for review.');

            return self::SUCCESS;
        }

        $this->info("Found {$tickets->count()} ticket(s) to review.");

        if ($dryRun) {
            foreach ($tickets as $ticket) {
                $this->line("  {$ticket->display_id} — {$ticket->subject} (Priority: {$ticket->priority->value})");
            }
            $this->info('Dry run — no jobs dispatched.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($tickets as $ticket) {
            RunTriagePipeline::dispatch($ticket->id, 'review');
            $dispatched++;
            $this->line("  Dispatched review for {$ticket->display_id}");
        }

        $this->info("Dispatched {$dispatched} review job(s).");

        return self::SUCCESS;
    }

    /**
     * Find open tickets eligible for review.
     * Ordered by priority (highest first), then oldest unreviewed.
     */
    private function findEligibleTickets(int $limit): \Illuminate\Support\Collection
    {
        $systemUserId = TriageConfig::systemUserId();

        return Ticket::open()
            ->orderBy('priority_order') // P1 first
            ->orderBy('updated_at')     // Oldest first within priority
            ->limit($limit * 2) // Fetch extra to account for filtering
            ->get()
            ->filter(function (Ticket $ticket) use ($systemUserId) {
                // Skip if a human touched it in the last 4 hours
                $fourHoursAgo = now()->subHours(4);
                $recentHumanNote = $ticket->notes()
                    ->where('noted_at', '>=', $fourHoursAgo)
                    ->whereNotNull('author_id')
                    ->where('author_id', '!=', $systemUserId)
                    ->whereNotIn('note_type', [
                        \App\Enums\NoteType::AiTriage->value,
                        \App\Enums\NoteType::System->value,
                    ])
                    ->exists();

                return ! $recentHumanNote;
            })
            ->take($limit);
    }
}
