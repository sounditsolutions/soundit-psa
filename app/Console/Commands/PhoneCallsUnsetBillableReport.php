<?php

namespace App\Console\Commands;

use App\Enums\CallStatus;
use App\Models\PhoneCall;
use Illuminate\Console\Command;

/**
 * Read-only report of linked calls whose billability was never set (card 6ad7c912).
 *
 * Lists calls with is_billable null, ticket_id set and status Completed, created more
 * than --older-than hours ago, grouped by the linked ticket's client_id and by the
 * call's stamped contract_id ("unstamped" when null). Each group shows a call count
 * and total effective hours (PhoneCall::effectiveDurationSeconds() / 3600, 2dp), then a
 * total line. It writes nothing and debits nothing. It prints ids and counts only:
 * no caller numbers and no names.
 */
class PhoneCallsUnsetBillableReport extends Command
{
    protected $signature = 'prepay:unset-call-billable-report
        {--older-than=24 : Only calls created more than this many hours ago}
        {--client= : Only calls whose linked ticket belongs to this client id}';

    protected $description = 'Read-only: count linked completed calls with unset billability, per client and stamped contract';

    public function handle(): int
    {
        $olderThan = $this->option('older-than');
        if (! is_numeric($olderThan) || (float) $olderThan < 0) {
            $this->error('--older-than must be a non-negative number of hours.');

            return self::FAILURE;
        }
        $client = $this->option('client');
        if ($client !== null && ! ctype_digit((string) $client)) {
            $this->error('--client must be a client id.');

            return self::FAILURE;
        }

        $cutoff = now()->subSeconds((int) round((float) $olderThan * 3600));

        $calls = PhoneCall::query()
            ->join('tickets', 'tickets.id', '=', 'phone_calls.ticket_id')
            ->whereNull('phone_calls.is_billable')
            ->whereNotNull('phone_calls.ticket_id')
            ->where('phone_calls.status', CallStatus::Completed->value)
            ->where('phone_calls.created_at', '<', $cutoff)
            ->when($client !== null, fn ($q) => $q->where('tickets.client_id', (int) $client))
            ->orderBy('phone_calls.id')
            ->get([
                'phone_calls.id', 'phone_calls.duration', 'phone_calls.recording_duration',
                'phone_calls.contract_id', 'tickets.client_id as ticket_client_id',
            ]);

        $groups = [];
        foreach ($calls as $call) {
            $clientKey = $call->ticket_client_id === null ? 'none' : (string) $call->ticket_client_id;
            $contractKey = $call->contract_id === null ? 'unstamped' : (string) $call->contract_id;
            $key = $clientKey."\0".$contractKey;
            $groups[$key] ??= ['client' => $clientKey, 'contract' => $contractKey, 'count' => 0, 'seconds' => 0];
            $groups[$key]['count']++;
            $groups[$key]['seconds'] += (int) ($call->effectiveDurationSeconds() ?? 0);
        }
        ksort($groups, SORT_NATURAL);

        $rows = array_map(fn (array $g) => [
            $g['client'], $g['contract'], $g['count'], number_format($g['seconds'] / 3600, 2, '.', ''),
        ], array_values($groups));
        $totalSeconds = array_sum(array_column($groups, 'seconds'));

        $this->line("Linked completed calls with unset billability, created more than {$olderThan}h ago:");
        if ($rows !== []) {
            $this->table(['client_id', 'contract_id', 'calls', 'effective_hours'], $rows);
        }
        $this->line(sprintf('TOTAL calls=%d effective_hours=%s', $calls->count(), number_format($totalSeconds / 3600, 2, '.', '')));

        return self::SUCCESS;
    }
}
