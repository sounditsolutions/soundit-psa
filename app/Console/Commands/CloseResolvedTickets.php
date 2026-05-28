<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CloseResolvedTickets extends Command
{
    protected $signature = 'tickets:close-resolved
        {--dry-run : Show what would be closed without committing}';

    protected $description = 'Auto-close tickets that have been in resolved status for a configurable number of days';

    public function handle(TicketService $ticketService): int
    {
        $days = (int) Setting::getValue('auto_close_resolved_days', 0);

        if ($days <= 0) {
            $this->info('Auto-close disabled (auto_close_resolved_days = 0).');
            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $tickets = Ticket::where('status', TicketStatus::Resolved)
            ->where('resolved_at', '<=', $cutoff)
            ->get();

        if ($tickets->isEmpty()) {
            $this->info('No resolved tickets older than ' . $days . ' days.');
            return self::SUCCESS;
        }

        $systemUserId = User::first()?->id;
        $closed = 0;
        $errors = 0;

        foreach ($tickets as $ticket) {
            if ($dryRun) {
                $this->info("Would close #{$ticket->display_id}: {$ticket->subject} (resolved {$ticket->resolved_at->diffForHumans()})");
                $closed++;
                continue;
            }

            try {
                $ticketService->changeStatus(
                    $ticket,
                    TicketStatus::Closed,
                    $systemUserId,
                    "Auto-closed after {$days} days in resolved status.",
                );
                $closed++;
            } catch (\Throwable $e) {
                $this->error("Failed to close #{$ticket->display_id}: {$e->getMessage()}");
                $errors++;
            }
        }

        $verb = $dryRun ? 'Would close' : 'Closed';
        $this->info("{$verb} {$closed} ticket(s)." . ($errors ? " {$errors} error(s)." : ''));

        if (! $dryRun && $closed > 0) {
            Log::info("[AutoClose] Closed {$closed} resolved tickets (threshold: {$days} days)");
        }

        return self::SUCCESS;
    }
}
