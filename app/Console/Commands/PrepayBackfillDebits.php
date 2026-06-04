<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Models\TicketNote;
use App\Services\PrepayService;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepayBackfillDebits extends Command
{
    protected $signature = 'prepay:backfill-debits
        {--dry-run : Show what would be created without making changes}
        {--since=2026-02-18 : Only process notes created on or after this date}';

    protected $description = 'Retroactively create prepay debit transactions for billable ticket time';

    public function handle(TicketService $ticketService, PrepayService $prepayService): int
    {
        $dryRun = $this->option('dry-run');
        $since = $this->option('since');

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Backfilling prepay debits for notes since {$since}...");

        // Find all prepay contracts (hours-based)
        $contracts = Contract::whereNotNull('prepay_balance')
            ->where(fn ($q) => $q->where('prepay_as_amount', false)->orWhereNull('prepay_as_amount'))
            ->get();

        $totalNotes = 0;
        $totalBillable = 0;
        $totalSkipped = 0;
        $totalHours = 0;
        $contractSummaries = [];

        foreach ($contracts as $contract) {
            // Find ticket notes with time on this contract's tickets
            $notes = TicketNote::select('ticket_notes.*')
                ->join('tickets', 'ticket_notes.ticket_id', '=', 'tickets.id')
                ->where('tickets.contract_id', $contract->id)
                ->whereNull('tickets.halo_id')
                ->where('ticket_notes.time_minutes', '>', 0)
                ->where('ticket_notes.created_at', '>=', $since)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('prepay_transactions')
                        ->whereColumn('prepay_transactions.ticket_note_id', 'ticket_notes.id');
                })
                ->with('ticket.latestTriageRun')
                ->get();

            if ($notes->isEmpty()) {
                continue;
            }

            $contractBillable = 0;
            $contractHours = 0;
            $contractSkipped = 0;

            foreach ($notes as $note) {
                $totalNotes++;
                $ticket = $note->ticket;

                // Determine billability using same logic as live flow
                $isBillable = $note->is_billable ?? $ticketService->defaultBillable($ticket);

                if (! $isBillable) {
                    $contractSkipped++;
                    $totalSkipped++;

                    continue;
                }

                $hours = round($note->time_minutes / 60, 4);
                $contractBillable++;
                $totalBillable++;
                $contractHours += $hours;
                $totalHours += $hours;

                if (! $dryRun) {
                    // Set is_billable on the note if it wasn't explicitly set
                    if ($note->is_billable === null) {
                        $note->updateQuietly(['is_billable' => true]);
                    }

                    $subject = mb_substr($ticket->subject ?? 'No subject', 0, 60);
                    PrepayTransaction::create([
                        'contract_id' => $contract->id,
                        'source' => \App\Enums\PrepayTransactionSource::TicketTime,
                        'ticket_note_id' => $note->id,
                        'user_id' => $note->author_id,
                        'date' => $note->noted_at ?? $note->created_at,
                        'hours' => -$hours,
                        'description' => "Ticket #{$ticket->id}: {$subject}",
                    ]);
                }
            }

            if ($contractBillable > 0) {
                $contractSummaries[] = [
                    'contract' => $contract->name,
                    'id' => $contract->id,
                    'notes' => $notes->count(),
                    'billable' => $contractBillable,
                    'skipped' => $contractSkipped,
                    'hours' => round($contractHours, 2),
                    'current_balance' => (float) $contract->prepay_balance,
                    'new_balance' => round((float) $contract->prepay_balance - $contractHours, 2),
                ];
            }
        }

        // Display summary table
        if (empty($contractSummaries)) {
            $this->info('No billable time found to backfill.');

            return self::SUCCESS;
        }

        $this->table(
            ['Contract', 'ID', 'Notes', 'Billable', 'Skipped', 'Hours', 'Current Balance', 'New Balance'],
            array_map(fn ($s) => [
                $s['contract'],
                $s['id'],
                $s['notes'],
                $s['billable'],
                $s['skipped'],
                $s['hours'],
                $s['current_balance'],
                $s['new_balance'],
            ], $contractSummaries),
        );

        $this->newLine();
        $this->info("Total: {$totalNotes} notes examined, {$totalBillable} billable (".round($totalHours, 2)."h), {$totalSkipped} skipped (covered by managed services)");

        if ($dryRun) {
            $this->warn('No changes made. Run without --dry-run to apply.');
        } else {
            // Recalculate balances from the ledger
            $this->info('Recalculating contract balances...');
            foreach ($contractSummaries as $s) {
                $prepayService->recalculateBalance(Contract::find($s['id']));
            }
            $this->info('Done. Balances recalculated from transaction ledger.');
        }

        return self::SUCCESS;
    }
}
