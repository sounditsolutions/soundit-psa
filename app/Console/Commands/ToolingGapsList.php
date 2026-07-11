<?php

namespace App\Console\Commands;

use App\Enums\ToolingGapStatus;
use App\Models\ToolingGap;
use Illuminate\Console\Command;

class ToolingGapsList extends Command
{
    protected $signature = 'tooling-gaps:list
        {--status=open : Filter by status (open|triaged|resolved|wontfix|all)}
        {--with-evidence : Include the instance-private evidence column (off by default — privacy)}';

    protected $description = 'List tooling-gap backlog entries for triage (abstract capability shown; evidence hidden unless --with-evidence).';

    public function handle(): int
    {
        $statusOption = $this->option('status');
        $all = ($statusOption === 'all');
        $withEvidence = (bool) $this->option('with-evidence');

        $query = ToolingGap::with('ticket')->latest();

        if (! $all) {
            // fromInput keeps a typo (e.g. "garbage") from crashing — defaults to Open
            $status = ToolingGapStatus::fromInput($statusOption);
            $query->where('status', $status->value);
            $statusLabel = ' with status "'.$status->label().'"';
        } else {
            $statusLabel = '';
        }

        $gaps = $query->get();

        if ($gaps->isEmpty()) {
            $this->info('No tooling gaps'.$statusLabel.'.');

            return self::SUCCESS;
        }

        $headers = ['ID', 'Ticket', 'Tool', 'Capability gap', 'Classification', 'Source', 'Status', 'Created'];
        if ($withEvidence) {
            $headers[] = 'Evidence';
        }

        $rows = $gaps->map(function (ToolingGap $gap) use ($withEvidence): array {
            $ticket = $gap->ticket_id !== null ? "#{$gap->ticket_id}" : '—';
            $row = [
                $gap->id,
                $ticket,
                $gap->tool_name ?? '—',
                $gap->capability_gap,
                $gap->classification->label(),
                $gap->source->label(),
                $gap->status->label(),
                $gap->created_at->diffForHumans(),
            ];
            if ($withEvidence) {
                $row[] = $gap->evidence ?? '—';
            }

            return $row;
        })->all();

        $this->table($headers, $rows);

        $count = $gaps->count();
        $this->info("{$count} tooling gap(s).");

        return self::SUCCESS;
    }
}
