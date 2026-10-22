<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PrepayTransaction;
use App\Services\BenjiPays\BenjiPaysClient;
use App\Services\BenjiPays\BenjiPaysException;
use App\Services\BenjiPays\BenjiPaysReadProjection;
use App\Support\AppTimezone;
use App\Support\BenjiPaysConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Read-only data for the staff client Billing tab (card iNjqGETC).
 *
 * Nothing here writes: no invoice, contract, prepay or vendor state is
 * changed. Money is summed as decimal strings with bcadd (scale 2), never as
 * floats.
 *
 * Outstanding (ruling 6ada31bb): over invoices whose status is client-payable
 * (InvoiceStatus::isClientPayable()), the QBO partial balance where
 * Invoice::qboPartialBalanceLog() reports one, otherwise the invoice total.
 * Soft-deleted invoices are excluded by the model's SoftDeletes scope.
 */
class ClientBillingSummary
{
    public const OTHER_INVOICE_LIMIT = 25;

    public const PREPAY_ROWS = 10;

    public const BENJIPAYS_PAYMENTS = 5;

    public const BENJIPAYS_METHODS = 25;

    public const BENJIPAYS_CACHE_SECONDS = 600;

    /** A failed read is remembered this long, so a slow vendor costs one slow view, not every view. */
    public const BENJIPAYS_FAILURE_SECONDS = 60;

    public function __construct(
        private readonly BillingService $billingService,
        private readonly BenjiPaysClient $benjiPays,
    ) {}

    /** @return array<string, mixed> */
    public function build(Client $client): array
    {
        $tz = AppTimezone::get();

        return [
            'tz' => $tz,
            'invoices' => $this->invoices($client, $tz),
            'contracts' => $this->contracts($client),
            'prepay' => $this->prepay($client),
            'benjipays' => $this->benjiPays($client),
        ];
    }

    /** The statuses whose invoices are still owed (Posted, Synced). */
    public static function payableStatuses(): array
    {
        return array_values(array_filter(InvoiceStatus::cases(), fn (InvoiceStatus $s) => $s->isClientPayable()));
    }

    /**
     * Open invoices (all of them, oldest due first), then the newest
     * OTHER_INVOICE_LIMIT others, with Outstanding and Overdue summed over the
     * open ones.
     *
     * @return array<string, mixed>
     */
    private function invoices(Client $client, string $tz): array
    {
        $payable = self::payableStatuses();

        // latestQboStatusChange is what qboPartialBalanceLog() reads; loading it
        // here keeps the open list at one query however many rows it has.
        $open = Invoice::query()
            ->where('client_id', $client->id)
            ->whereIn('status', $payable)
            ->with('latestQboStatusChange')
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $others = Invoice::query()
            ->where('client_id', $client->id)
            ->whereNotIn('status', $payable)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit(self::OTHER_INVOICE_LIMIT)
            ->get();

        $totalCount = Invoice::query()->where('client_id', $client->id)->count();

        $today = Carbon::now($tz)->toDateString();
        $outstanding = '0.00';
        $overdue = '0.00';
        $overdueCount = 0;
        $partialCount = 0;
        $partialAsOf = null;
        $rows = [];

        foreach ($open as $invoice) {
            $log = $invoice->qboPartialBalanceLog();
            $balance = self::money($log !== null ? $log->qbo_balance : $invoice->total);
            $isOverdue = $invoice->due_date !== null && $invoice->due_date->toDateString() < $today;

            $outstanding = bcadd($outstanding, $balance, 2);
            if ($isOverdue) {
                $overdue = bcadd($overdue, $balance, 2);
                $overdueCount++;
            }
            if ($log !== null) {
                $partialCount++;
                if ($partialAsOf === null || $log->created_at->greaterThan($partialAsOf)) {
                    $partialAsOf = $log->created_at;
                }
            }

            $rows[] = [
                'invoice' => $invoice,
                'open' => true,
                'balance' => $balance,
                'partial_as_of' => $log?->created_at,
                'overdue' => $isOverdue,
            ];
        }

        foreach ($others as $invoice) {
            $rows[] = [
                'invoice' => $invoice,
                'open' => false,
                'balance' => $invoice->status === InvoiceStatus::Paid ? '0.00' : null,
                'partial_as_of' => null,
                'overdue' => false,
            ];
        }

        return [
            'rows' => $rows,
            'open_count' => $open->count(),
            'other_shown' => $others->count(),
            'total_count' => $totalCount,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
            'overdue_count' => $overdueCount,
            'partial_count' => $partialCount,
            'partial_as_of' => $partialAsOf,
        ];
    }

    /** Half-up (away from zero) to 2 places, the way round() treats the dashboard MRR. */
    private static function round2(string $value): string
    {
        return str_starts_with($value, '-')
            ? bcsub($value, '0.005', 2)
            : bcadd($value, '0.005', 2);
    }

    /** A DB decimal (or null) as a scale-2 string bcmath accepts. */
    private static function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }
        $s = is_string($value) ? trim($value) : (string) $value;
        if (! preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            // A non-numeric money column is a data defect, not zero.
            throw new \UnexpectedValueException('Non-numeric invoice amount.');
        }

        return bcadd($s, '0', 2);
    }

    /**
     * Active contracts with their monthly amount. The monthly figure is the
     * same one the dashboard's MRR uses (DashboardController
     * getManagedServicesProfitability): BillingService::previewInvoice()
     * subtotal of each active profile, normalised by its billing period's
     * monthsPerCycle(). previewInvoice() is a read; it writes nothing.
     *
     * @return array<string, mixed>
     */
    private function contracts(Client $client): array
    {
        $contracts = Contract::query()
            ->where('client_id', $client->id)
            ->where('status', ContractStatus::Active)
            ->with(['profiles' => fn ($q) => $q->where('is_active', true)->orderBy('next_run_date'),
                'profiles.lines.sku.backupStorageTiers'])
            ->orderBy('name')
            ->get();

        $monthlyTotal = '0.00';
        $unpriced = 0;
        $recurringCount = 0;
        $rows = [];

        foreach ($contracts as $contract) {
            $monthly = null;
            // Per profile, as the dashboard MRR does: a profile that fails to preview is
            // left out and counted, and the contract's other profiles still count.
            $failed = 0;
            foreach ($contract->profiles as $profile) {
                // previewInvoice() reads profile->contract->client; hand it the loaded models.
                $profile->setRelation('contract', $contract->setRelation('client', $client));
                try {
                    $preview = $this->billingService->previewInvoice($profile);
                    $perCycle = self::money(number_format((float) $preview['subtotal'], 2, '.', ''));
                    // subtotal / monthsPerCycle(), written as subtotal x cycles-per-year / 12
                    // (cycles per year 52, 12, 6, 4, 2, 1) so it stays decimal math.
                    $cyclesPerYear = (string) (int) round(12 / $profile->billing_period->monthsPerCycle());
                    $amount = self::round2(bcdiv(bcmul($perCycle, $cyclesPerYear, 6), '12', 6));
                    $monthly = bcadd($monthly ?? '0.00', $amount, 2);
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('[ClientBilling] Recurring profile preview failed', [
                        'profile_id' => $profile->id,
                        'exception' => $e::class,
                    ]);
                }
            }
            $unpriced += $failed;
            if ($monthly !== null) {
                $monthlyTotal = bcadd($monthlyTotal, $monthly, 2);
                $recurringCount++;
            }

            $rows[] = [
                'contract' => $contract,
                'monthly' => $monthly,
                'failed' => $failed,
                'next_invoice' => $contract->profiles->first()?->next_run_date,
                'has_profile' => $contract->profiles->isNotEmpty(),
            ];
        }

        return [
            'rows' => $rows,
            'monthly_total' => $monthlyTotal,
            'recurring_count' => $recurringCount,
            'unpriced_count' => $unpriced,
        ];
    }

    /**
     * Active prepay contracts (prepay_balance set) and the PREPAY_ROWS most
     * recent ledger rows across them. Reads only; PrepayService is not called.
     *
     * @return array<string, mixed>
     */
    private function prepay(Client $client): array
    {
        $contracts = Contract::query()
            ->where('client_id', $client->id)
            ->where('status', ContractStatus::Active)
            ->whereNotNull('prepay_balance')
            ->orderBy('name')
            ->get();

        $transactions = $contracts->isEmpty() ? collect() : PrepayTransaction::query()
            ->whereIn('contract_id', $contracts->pluck('id'))
            ->with('contract:id,name,prepay_as_amount')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::PREPAY_ROWS)
            ->get();

        return ['contracts' => $contracts, 'transactions' => $transactions];
    }

    /**
     * Payment methods and the approved payments among the last
     * BENJIPAYS_PAYMENTS transactions, through the
     * existing client and projection. Null when BenjiPays is not configured
     * or the client has no QuickBooks customer id (the view shows a
     * "not linked" line; no network call is made).
     *
     * Cached per client: a success for BENJIPAYS_CACHE_SECONDS, a failure for
     * BENJIPAYS_FAILURE_SECONDS. No retry: one attempt per cache miss, and
     * the second read is skipped once the first has failed.
     *
     * @return array<string, mixed>|null
     */
    private function benjiPays(Client $client): ?array
    {
        $customerId = trim((string) $client->qbo_customer_id);
        if ($customerId === '' || ! BenjiPaysConfig::isConfigured()) {
            return null;
        }

        // v2: entries cached before the approved-payment filter are not reused.
        $key = 'client-billing:benjipays:v2:'.$client->id.':'.sha1($customerId);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $methods = BenjiPaysReadProjection::paymentMethods(
                $this->benjiPays->paymentMethods($customerId, self::BENJIPAYS_METHODS), $customerId,
            );
            $payments = BenjiPaysReadProjection::transactions(
                $this->benjiPays->transactions($customerId, null, self::BENJIPAYS_PAYMENTS), $customerId,
            );
            $result = [
                'ok' => true,
                'methods' => $methods['rows'],
                // Only payment-type rows with approved status are listed: declined and
                // void-pending rows and refund/void rows are left out. The projection drops
                // the voided flag and refund/void blocks, so a later refund or void of a
                // listed payment is not detected here.
                'payments' => array_values(array_filter($payments['rows'], fn (array $r) => $r['type'] === 'payment'
                    && $r['approved'] === true && $r['status'] === 'approved')),
                'read_at' => now('UTC')->toIso8601String(),
            ];
            Cache::put($key, $result, self::BENJIPAYS_CACHE_SECONDS);
        } catch (\Throwable $e) {
            // C-56: status and class only; no vendor text reaches the log or the view.
            Log::warning('[ClientBilling] BenjiPays read unavailable', [
                'client_id' => $client->id,
                'exception' => $e::class,
                'http_status' => $e instanceof BenjiPaysException ? $e->httpStatus : null,
            ]);
            $result = ['ok' => false, 'methods' => [], 'payments' => [], 'read_at' => now('UTC')->toIso8601String()];
            Cache::put($key, $result, self::BENJIPAYS_FAILURE_SECONDS);
        }

        return $result;
    }
}
