<?php

namespace App\Console\Commands;

use App\Models\RecurringInvoiceProfile;
use App\Services\BillingService;
use Illuminate\Console\Command;

class GenerateRecurringInvoices extends Command
{
    protected $signature = 'billing:generate
        {--dry-run : Show what would be generated without persisting}
        {--client= : Filter to a specific client ID}';

    protected $description = 'Generate recurring invoices for due billing profiles';

    public function handle(BillingService $billingService): int
    {
        $query = RecurringInvoiceProfile::due()->with(['contract.client', 'lines']);

        if ($clientId = $this->option('client')) {
            $query->whereHas('contract', fn ($q) => $q->where('client_id', $clientId));
        }

        $profiles = $query->get();

        if ($profiles->isEmpty()) {
            $this->info('No profiles due for invoice generation.');

            return self::SUCCESS;
        }

        $this->info("Found {$profiles->count()} profile(s) due for generation.");

        if ($this->option('dry-run')) {
            $this->info('DRY RUN — no invoices will be created.');
            $this->newLine();

            $rows = [];
            foreach ($profiles as $profile) {
                $preview = $billingService->previewInvoice($profile);
                $subtotalStr = '$'.number_format($preview['subtotal'], 2);
                if (! empty($preview['would_skip'])) {
                    $subtotalStr .= ' (would skip)';
                }
                $rows[] = [
                    $profile->contract->client->name,
                    $profile->name,
                    $preview['invoice_date'],
                    count($preview['lines']).' lines',
                    $subtotalStr,
                ];
            }

            $this->table(['Client', 'Profile', 'Invoice Date', 'Lines', 'Subtotal'], $rows);

            return self::SUCCESS;
        }

        $results = $billingService->generateInvoicesForDueProfiles();

        $rows = [];
        $errors = 0;

        foreach ($results as $result) {
            if ($result['status'] === 'created') {
                $invoice = $result['invoice'];
                $rows[] = [
                    $result['client'],
                    $result['profile'],
                    $invoice->invoice_number,
                    '$'.number_format($invoice->subtotal, 2),
                    '<fg=green>Created</>',
                ];
            } elseif ($result['status'] === 'skipped') {
                $reason = ($result['reason'] ?? 'exists') === 'nothing_to_bill'
                    ? 'Skipped (nothing to bill)'
                    : 'Skipped (exists)';
                $rows[] = [
                    $result['client'],
                    $result['profile'],
                    '-',
                    '-',
                    "<fg=yellow>{$reason}</>",
                ];
            } else {
                $errors++;
                $rows[] = [
                    $result['client'],
                    $result['profile'],
                    '-',
                    '-',
                    '<fg=red>Error: '.$result['error'].'</>',
                ];
            }
        }

        $this->table(['Client', 'Profile', 'Invoice #', 'Subtotal', 'Status'], $rows);

        $created = collect($results)->where('status', 'created')->count();
        $skipped = collect($results)->where('status', 'skipped')->count();

        $this->newLine();
        $this->info("Summary: {$created} created, {$skipped} skipped, {$errors} errors.");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
