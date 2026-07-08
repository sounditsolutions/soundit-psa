<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recompute ticket SLA deadlines from contract SLA terms.
 *
 * SLA response/resolution deadlines are stamped at ticket creation
 * (TicketService::createTicket) from the contract's `sla_terms`, anchored on
 * the creation time. When a contract's SLA terms change, or tickets are
 * imported without deadlines, the stored `response_due_at` / `due_at` values
 * drift out of sync. This command re-derives both from the current contract
 * terms, anchored on each ticket's `opened_at` (falling back to `created_at`),
 * mirroring the creation-time calculation exactly.
 */
class RecalculateTicketSla extends Command
{
    protected $signature = 'tickets:recalculate-sla
                            {--ticket= : Recalculate a single ticket by ID}
                            {--client= : Limit to tickets belonging to a specific client ID}
                            {--all : Include resolved/closed tickets (default: open tickets only)}
                            {--clear-missing : Null a deadline when the contract defines no SLA hours for the ticket priority}
                            {--dry-run : Show what would change without saving}';

    protected $description = 'Recalculate ticket SLA deadlines (response_due_at, due_at) from contract SLA terms';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $clearMissing = (bool) $this->option('clear-missing');

        // Only tickets attached to a contract can carry contract-derived SLA.
        $query = Ticket::query()
            ->whereNotNull('contract_id')
            ->with('contract');

        if ($ticketId = $this->option('ticket')) {
            $query->whereKey((int) $ticketId);
        }

        if ($clientId = $this->option('client')) {
            $query->where('client_id', (int) $clientId);
        }

        // Default to open tickets only. An explicit --ticket target is processed
        // regardless of status (you named it), and --all opts the whole sweep in.
        if (! $this->option('all') && ! $this->option('ticket')) {
            $query->whereIn('status', $this->openStatusValues());
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No tickets matched the given filters.');

            return self::SUCCESS;
        }

        $this->info("Recalculating SLA deadlines on {$total} ticket(s)".($dryRun ? ' (dry-run)' : '').'.');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        // chunkById is safe while writing — we never mutate the id/status
        // columns it pages and filters on.
        $query->chunkById(200, function ($tickets) use (&$updated, &$unchanged, &$skipped, $dryRun, $clearMissing, $bar) {
            foreach ($tickets as $ticket) {
                $contract = $ticket->contract;

                // Contract soft-deleted, or carries no SLA terms → nothing to derive.
                if (! $contract || ! $contract->hasSla()) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                $anchor = $ticket->opened_at ?? $ticket->created_at;
                if (! $anchor) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                $priority = $ticket->priority;

                $newDueAt = $this->resolveDeadline(
                    $anchor,
                    $contract->slaResolutionHours($priority),
                    $ticket->due_at,
                    $clearMissing,
                );
                $newResponseDueAt = $this->resolveDeadline(
                    $anchor,
                    $contract->slaResponseHours($priority),
                    $ticket->response_due_at,
                    $clearMissing,
                );

                $changes = [];
                if ($this->timestampsDiffer($ticket->due_at, $newDueAt)) {
                    $changes['due_at'] = $newDueAt;
                }
                if ($this->timestampsDiffer($ticket->response_due_at, $newResponseDueAt)) {
                    $changes['response_due_at'] = $newResponseDueAt;
                }

                if ($changes === []) {
                    $unchanged++;
                    $bar->advance();

                    continue;
                }

                if ($dryRun) {
                    $bar->clear();
                    $this->line($this->describeChange($ticket, $changes));
                    $bar->display();
                } else {
                    $ticket->forceFill($changes)->save();
                }

                $updated++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. updated={$updated} unchanged={$unchanged} skipped={$skipped}");

        return self::SUCCESS;
    }

    /**
     * Compute one SLA deadline.
     *
     * - hours configured            → anchor + hours
     * - no hours and --clear-missing → null (wipe a stale deadline)
     * - no hours otherwise           → leave the existing value untouched
     */
    private function resolveDeadline(Carbon $anchor, ?int $hours, ?Carbon $existing, bool $clearMissing): ?Carbon
    {
        if ($hours !== null) {
            return $anchor->copy()->addHours($hours);
        }

        return $clearMissing ? null : $existing;
    }

    private function timestampsDiffer(?Carbon $a, ?Carbon $b): bool
    {
        if ($a === null && $b === null) {
            return false;
        }

        if ($a === null || $b === null) {
            return true;
        }

        return $a->ne($b);
    }

    private function describeChange(Ticket $ticket, array $changes): string
    {
        $parts = [];
        foreach ($changes as $field => $newValue) {
            $parts[] = sprintf('%s %s → %s', $field, $this->formatTimestamp($ticket->{$field}), $this->formatTimestamp($newValue));
        }

        return "T-{$ticket->id} [{$ticket->priority->value}] ".implode('; ', $parts);
    }

    private function formatTimestamp(?Carbon $timestamp): string
    {
        return $timestamp?->toDateTimeString() ?? '(none)';
    }

    /**
     * @return list<string>
     */
    private function openStatusValues(): array
    {
        return array_values(array_map(
            fn (TicketStatus $status) => $status->value,
            array_filter(TicketStatus::cases(), fn (TicketStatus $status) => $status->isOpen()),
        ));
    }
}
