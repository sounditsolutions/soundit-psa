<?php

namespace App\Console\Commands;

use App\Enums\PrepayTransactionSource;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Services\PrepayService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepayBackfillFromHalo extends Command
{
    protected $signature = 'prepay:backfill-from-halo
        {--dry-run : Show what would be created without making changes}
        {--contract= : Only process a specific local contract ID}
        {--halo-contract= : Only process a specific Halo contract ID}
        {--verified-only : Only import contracts where CSV total matches prepay_used}
        {--csv= : Path to CSV file (default: base_path Client_Time Log _Detailed_ (1).csv)}';

    protected $description = 'Backfill prepay debit transactions from Halo CSV export of actionprepayhours data';

    public function handle(PrepayService $prepayService): int
    {
        $dryRun = $this->option('dry-run');
        $contractFilter = $this->option('contract');
        $haloContractFilter = $this->option('halo-contract');
        $csvPath = $this->option('csv') ?: base_path('Client_Time Log _Detailed_ (1).csv');

        if (! file_exists($csvPath)) {
            $this->error("CSV file not found: {$csvPath}");

            return self::FAILURE;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Backfilling prepay debits from Halo CSV...');

        // Parse CSV into rows grouped by Halo contract ID
        $csvRows = $this->parseCsv($csvPath);
        $this->info("Parsed {$csvRows->flatten(1)->count()} rows from CSV across {$csvRows->count()} Halo contract IDs");

        // Build mapping: halo_contract_id → local Contract
        $contractMap = Contract::whereNotNull('halo_id')
            ->whereNotNull('prepay_balance')
            ->with('client')
            ->get()
            ->keyBy('halo_id');

        // Build mapping: halo_client_id → local client_id
        $clientMap = DB::table('clients')
            ->whereNotNull('halo_id')
            ->pluck('id', 'halo_id');

        // Build mapping: halo_ticket_id → local ticket
        $ticketMap = DB::table('tickets')
            ->whereNotNull('halo_id')
            ->get(['id', 'halo_id', 'subject', 'client_id'])
            ->keyBy('halo_id');

        // Build mapping: halo_note_id → local ticket_note (action IDs in CSV are global)
        $noteMap = DB::table('ticket_notes')
            ->whereNotNull('halo_note_id')
            ->get(['id', 'halo_note_id', 'ticket_id', 'author_id', 'noted_at', 'created_at'])
            ->keyBy('halo_note_id');

        // Determine which contracts to process
        $haloContractIds = $csvRows->keys()->filter(fn ($id) => $id > 0)->sort();

        if ($haloContractFilter) {
            $haloContractIds = $haloContractIds->filter(fn ($id) => $id == $haloContractFilter);
        }

        if ($contractFilter) {
            $localContract = Contract::find($contractFilter);
            if (! $localContract?->halo_id) {
                $this->error("Contract #{$contractFilter} not found or has no halo_id");

                return self::FAILURE;
            }
            $haloContractIds = $haloContractIds->filter(fn ($id) => $id == $localContract->halo_id);
        }

        $this->info("Processing {$haloContractIds->count()} Halo contract IDs...");

        $verifiedOnly = $this->option('verified-only');

        // Pre-compute CSV totals per halo contract for verification
        $csvTotals = [];
        foreach ($csvRows as $haloId => $rows) {
            if ($haloId <= 0) {
                continue;
            }
            $csvTotals[$haloId] = round($rows->sum('prepay_hours'), 2);
        }

        $totalCreated = 0;
        $totalSkipped = 0;
        $totalHours = 0;
        $contractSummaries = [];
        $unmapped = [];
        $verifySkipped = [];

        foreach ($haloContractIds as $haloContractId) {
            $contract = $contractMap->get($haloContractId);

            if (! $contract) {
                $rows = $csvRows->get($haloContractId, collect());
                $unmapped[$haloContractId] = $rows->count();

                continue;
            }

            // Verification check: CSV total must match prepay_used from Halo snapshot
            if ($verifiedOnly) {
                $csvTotal = $csvTotals[$haloContractId] ?? 0;
                $prepayUsed = round((float) $contract->prepay_used, 2);

                if (abs($csvTotal - $prepayUsed) >= 0.02) {
                    $verifySkipped[] = "{$contract->name} (halo={$haloContractId}): csv={$csvTotal}h vs prepay_used={$prepayUsed}h";

                    continue;
                }
            }

            $rows = $csvRows->get($haloContractId, collect());
            $result = $this->processContractRows($contract, $rows, $ticketMap, $noteMap, $clientMap, $dryRun);

            if ($result['created'] > 0 || $result['skipped'] > 0) {
                $contractSummaries[] = [
                    'contract' => $contract->name,
                    'id' => $contract->id,
                    'halo_id' => $haloContractId,
                    'linked' => $result['linked'],
                    'unlinked' => $result['unlinked'],
                    'skipped' => $result['skipped'],
                    'hours' => round($result['hours'], 4),
                ];
            }

            $totalCreated += $result['created'];
            $totalSkipped += $result['skipped'];
            $totalHours += $result['hours'];
        }

        // Report unmapped contracts
        if (! empty($unmapped)) {
            $this->warn('Unmapped Halo contract IDs (no local contract with matching halo_id):');
            foreach ($unmapped as $hid => $count) {
                $this->warn("  halo_contract={$hid}: {$count} rows");
            }
        }

        // Report verification-skipped contracts
        if (! empty($verifySkipped)) {
            $this->warn('Skipped (CSV total does not match prepay_used):');
            foreach ($verifySkipped as $msg) {
                $this->warn("  {$msg}");
            }
        }

        // Report skipped account-level rows
        $accountLevel = $csvRows->filter(fn ($rows, $key) => $key <= 0)->sum(fn ($rows) => $rows->count());
        if ($accountLevel > 0) {
            $this->line("Skipped {$accountLevel} account-level rows (contract_id <= 0)");
        }

        if (empty($contractSummaries)) {
            $this->info('No new prepay debits to backfill.');

            return self::SUCCESS;
        }

        $this->table(
            ['Contract', 'ID', 'Halo ID', 'Linked', 'Unlinked', 'Skipped', 'Hours'],
            $contractSummaries,
        );

        $this->newLine();
        $this->info("Total: {$totalCreated} debit transactions (".round($totalHours, 4)."h), {$totalSkipped} skipped (already exist)");

        if ($dryRun) {
            $this->warn('No changes made. Run without --dry-run to apply.');
        } else {
            $this->info('Recalculating contract balances...');
            foreach ($contractSummaries as $s) {
                if ($s['linked'] + $s['unlinked'] > 0) {
                    $c = Contract::find($s['id']);
                    $prepayService->recalculateBalance($c);
                    $this->line("  {$c->name}: balance = {$c->fresh()->prepay_balance}h");
                }
            }
            $this->info('Done.');
        }

        return self::SUCCESS;
    }

    private function processContractRows(
        Contract $contract,
        $rows,
        $ticketMap,
        $noteMap,
        $clientMap,
        bool $dryRun,
    ): array {
        $result = ['created' => 0, 'linked' => 0, 'unlinked' => 0, 'skipped' => 0, 'hours' => 0];

        // Existing dedup: check for action IDs already imported
        // We store halo action_id in description as [action_id] for unlinked,
        // or link via ticket_note_id for linked transactions
        $existingActionIds = [];

        // Linked transactions: get halo_note_id of linked notes
        $linkedNoteIds = PrepayTransaction::where('contract_id', $contract->id)
            ->where('source', PrepayTransactionSource::TicketTime)
            ->whereNotNull('ticket_note_id')
            ->pluck('ticket_note_id');

        foreach ($linkedNoteIds as $noteId) {
            $note = DB::table('ticket_notes')->where('id', $noteId)->first(['halo_note_id']);
            if ($note?->halo_note_id) {
                $existingActionIds[$note->halo_note_id] = true;
            }
        }

        // Unlinked transactions: extract action_id from description [action_id]
        PrepayTransaction::where('contract_id', $contract->id)
            ->where('source', PrepayTransactionSource::TicketTime)
            ->whereNull('ticket_note_id')
            ->pluck('description')
            ->each(function ($desc) use (&$existingActionIds) {
                if (preg_match('/\[(\d+)\]$/', $desc, $m)) {
                    $existingActionIds[(int) $m[1]] = true;
                }
            });

        $this->info("  {$contract->name} (halo={$contract->halo_id}): {$rows->count()} CSV rows, {$result['skipped']} existing...");

        foreach ($rows as $row) {
            $actionId = (int) $row['action_id'];
            $prepayHours = (float) $row['prepay_hours'];
            $haloTicketId = (int) $row['ticket_id'];
            $haloClientId = (int) $row['client_id'];
            $actionDate = $this->parseDate($row['action_date']);

            if ($prepayHours <= 0) {
                continue;
            }

            // Dedup by global action ID
            if (isset($existingActionIds[$actionId])) {
                $result['skipped']++;

                continue;
            }

            // Try to find local ticket and note
            $localTicket = $ticketMap->get($haloTicketId);
            $localNote = $noteMap->get($actionId);

            if ($localNote) {
                $ticketSubject = $localTicket
                    ? mb_substr($localTicket->subject ?? 'No subject', 0, 60)
                    : "Halo #{$haloTicketId}";
                $description = $localTicket
                    ? "Ticket #{$localTicket->id}: {$ticketSubject}"
                    : "Halo #{$haloTicketId}: {$ticketSubject}";

                $result['linked']++;
                $result['created']++;
                $result['hours'] += $prepayHours;

                if (! $dryRun) {
                    PrepayTransaction::create([
                        'contract_id' => $contract->id,
                        'source' => PrepayTransactionSource::TicketTime,
                        'ticket_note_id' => $localNote->id,
                        'user_id' => $localNote->author_id,
                        'date' => $localNote->noted_at ?? $localNote->created_at,
                        'hours' => -$prepayHours,
                        'description' => $description,
                    ]);
                }
            } else {
                // No matching local note — create unlinked transaction
                $ticketSubject = $localTicket
                    ? mb_substr($localTicket->subject ?? 'No subject', 0, 60)
                    : 'No subject';
                $ticketRef = $localTicket ? "Ticket #{$localTicket->id}" : "Halo #{$haloTicketId}";
                $description = "{$ticketRef}: {$ticketSubject} [{$actionId}]";

                $result['unlinked']++;
                $result['created']++;
                $result['hours'] += $prepayHours;

                if (! $dryRun) {
                    PrepayTransaction::create([
                        'contract_id' => $contract->id,
                        'source' => PrepayTransactionSource::TicketTime,
                        'ticket_note_id' => null,
                        'user_id' => null,
                        'date' => $actionDate ?? now(),
                        'hours' => -$prepayHours,
                        'description' => $description,
                    ]);
                }
            }
        }

        $this->line("    → {$result['linked']} linked, {$result['unlinked']} unlinked, {$result['skipped']} skipped, total {$result['hours']}h");

        return $result;
    }

    private function parseCsv(string $path): \Illuminate\Support\Collection
    {
        $rows = collect();

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle); // skip header

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 7) {
                continue;
            }

            $contractId = (int) $data[6];

            $row = [
                'client_id' => (int) $data[0],
                'ticket_id' => (int) $data[1],
                'action_id' => (int) $data[2],
                'prepay_hours' => (float) $data[3],
                'time_taken' => (float) $data[4],
                'action_date' => $data[5],
                'contract_id' => $contractId,
            ];

            if (! $rows->has($contractId)) {
                $rows->put($contractId, collect());
            }
            $rows->get($contractId)->push($row);
        }

        fclose($handle);

        return $rows;
    }

    private function parseDate(string $dateStr): ?string
    {
        try {
            // Format: "9/14/2021 5:00 PM"
            return Carbon::createFromFormat('n/j/Y g:i A', trim($dateStr))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
