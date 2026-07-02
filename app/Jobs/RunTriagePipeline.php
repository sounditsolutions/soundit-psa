<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\TriageRun;
use App\Services\Triage\TriagePipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RunTriagePipeline implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        private readonly int $ticketId,
        private readonly string $mode = 'triage',
        private readonly ?int $triggeredByUserId = null,
    ) {}

    public function handle(TriagePipeline $pipeline): void
    {
        // Use pessimistic locking to prevent concurrent runs on same ticket
        $ticket = DB::transaction(function () {
            $ticket = Ticket::where('id', $this->ticketId)->lockForUpdate()->first();

            if (! $ticket) {
                Log::warning('[Triage] Ticket not found', ['ticket_id' => $this->ticketId]);

                return null;
            }

            // Check if a triage run is already in progress for this ticket.
            // Ignore runs older than 15 minutes — they are stale (crashed worker).
            $existingRun = TriageRun::where('ticket_id', $this->ticketId)
                ->where('status', 'running')
                ->where('started_at', '>', now()->subMinutes(15))
                ->first();

            if ($existingRun) {
                Log::debug('[Triage] Skipping — triage already running', [
                    'ticket_id' => $this->ticketId,
                    'existing_run_id' => $existingRun->id,
                ]);

                return null;
            }

            // Clean up any stale runs (crashed worker, never completed)
            TriageRun::where('ticket_id', $this->ticketId)
                ->where('status', 'running')
                ->where('started_at', '<=', now()->subMinutes(15))
                ->update(['status' => 'failed', 'completed_at' => now(), 'errors' => json_encode([['stage' => 'pipeline', 'message' => 'Stale run — marked failed after 15 minute timeout']])]);

            return $ticket;
        });

        if (! $ticket) {
            return;
        }

        try {
            $pipeline->run($ticket, $this->mode, $this->triggeredByUserId);
        } catch (\Throwable $e) {
            Log::error('[Triage] Job failed', [
                'ticket_id' => $this->ticketId,
                'mode' => $this->mode,
                'error' => $e->getMessage(),
            ]);

            // Only rethrow for retry if we have attempts left
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }
}
