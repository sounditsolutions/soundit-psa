<?php

namespace App\Console\Commands;

use App\Enums\TicketSource;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Support\HdbPressId;
use Illuminate\Console\Command;

/**
 * Backfill hdb_press_id for helpdesk_button tickets that predate the capture at
 * T2TService::addNoteFromCw(). 48 of the 50 most recent such tickets on prod
 * carry the link note, so this is the one-shot that makes history usable.
 */
class BackfillHdbPressIds extends Command
{
    protected $signature = 'hdb:backfill-press-ids
                            {--limit= : Cap the number of tickets to process}
                            {--dry-run : Report what would be written without saving}';

    protected $description = 'Parse the HelpDesk Buttons press id out of existing helpdesk_button ticket notes';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Ticket::query()
            ->where('source', TicketSource::HelpdeskButton->value)
            ->whereNull('hdb_press_id')
            ->orderBy('id');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $tickets = $query->get();

        if ($tickets->isEmpty()) {
            $this->info('No helpdesk_button tickets are missing a press id.');

            return self::SUCCESS;
        }

        $found = 0;
        $absent = 0;
        $conflicts = [];

        foreach ($tickets as $ticket) {
            // Ordered by id ASCENDING — that ordering IS the "lowest note id wins"
            // rule. The default notes() relation orders by noted_at desc, which is
            // the wrong end and not a stable tiebreak.
            $bodies = TicketNote::withTrashed()
                ->where('ticket_id', $ticket->id)
                ->orderBy('id')
                ->pluck('body');

            $result = HdbPressId::resolve($bodies);

            if ($result['status'] === HdbPressId::STATUS_CONFLICT) {
                $conflicts[] = $ticket->id;
                $this->warn(sprintf(
                    'Ticket %d: REFUSED — %d different press ids (%s)',
                    $ticket->id,
                    count($result['candidates']),
                    implode(', ', $result['candidates']),
                ));

                continue;
            }

            if ($result['status'] === HdbPressId::STATUS_ABSENT) {
                $absent++;

                continue;
            }

            $found++;

            if (! $dryRun) {
                // forceFill: hdb_press_id is deliberately not mass-assignable.
                $ticket->forceFill(['hdb_press_id' => $result['press_id']])->save();
            }
        }

        $this->info(sprintf(
            '%s %d of %d ticket(s); %d had no HDB link note (normal); %d refused for conflicting press ids.',
            $dryRun ? 'Would set' : 'Set',
            $found,
            $tickets->count(),
            $absent,
            count($conflicts),
        ));

        if ($conflicts !== []) {
            $this->warn('Conflicting tickets need a human: '.implode(', ', $conflicts));
        }

        return self::SUCCESS;
    }
}
