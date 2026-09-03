<?php

namespace App\Services\Qbo;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceStatusChangeSource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\QboBankAccount;
use App\Models\QboExpense;
use App\Models\Setting;
use App\Models\Sku;
use App\Services\InvoiceVoidService;
use App\Support\InvoiceStatusChangeContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QboSyncService
{
    public function __construct(
        private readonly QboClient $qboClient,
    ) {}

    // ── Customer Matching ──

    public function fetchQboCustomers(): array
    {
        $result = $this->qboClient->query('SELECT * FROM Customer MAXRESULTS 1000');

        return collect($result['QueryResponse']['Customer'] ?? [])
            ->map(fn ($c) => [
                'Id' => $c['Id'],
                'DisplayName' => $c['DisplayName'] ?? '',
                'PrimaryEmailAddr' => $c['PrimaryEmailAddr']['Address'] ?? null,
            ])
            ->sortBy('DisplayName')
            ->values()
            ->all();
    }

    public function matchClientToQbo(Client $client, string $qboCustomerId): void
    {
        $client->update([
            'qbo_customer_id' => $qboCustomerId,
        ]);
    }

    public function autoMatchClients(): array
    {
        $qboCustomers = $this->fetchQboCustomers();
        $clients = Client::operational()->whereNull('qbo_customer_id')->get();

        // QBO customer IDs already mapped to a client — skip these to avoid unique constraint violations
        $alreadyMappedQboIds = Client::whereNotNull('qbo_customer_id')
            ->pluck('qbo_customer_id')
            ->flip();

        $matched = [];
        $unmatched = [];
        $ambiguous = [];

        foreach ($clients as $client) {
            $normalizedName = $this->normalizeName($client->name);

            $matches = collect($qboCustomers)->filter(function ($qc) use ($normalizedName, $alreadyMappedQboIds) {
                return $this->normalizeName($qc['DisplayName']) === $normalizedName
                    && ! $alreadyMappedQboIds->has($qc['Id']);
            });

            if ($matches->count() === 1) {
                $qboCustomer = $matches->first();
                try {
                    $client->update([
                        'qbo_customer_id' => $qboCustomer['Id'],
                        'qbo_display_name' => $qboCustomer['DisplayName'],
                    ]);
                    $alreadyMappedQboIds[$qboCustomer['Id']] = true;
                    $matched[] = [
                        'client' => $client->name,
                        'qbo_name' => $qboCustomer['DisplayName'],
                        'qbo_id' => $qboCustomer['Id'],
                    ];
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    Log::warning('[QBO AutoMatch] Skipped duplicate', [
                        'client' => $client->name,
                        'qbo_id' => $qboCustomer['Id'],
                    ]);
                    $unmatched[] = ['client' => $client->name];
                }
            } elseif ($matches->count() > 1) {
                $ambiguous[] = [
                    'client' => $client->name,
                    'qbo_matches' => $matches->pluck('DisplayName')->all(),
                ];
            } else {
                $unmatched[] = ['client' => $client->name];
            }
        }

        return compact('matched', 'unmatched', 'ambiguous');
    }

    // ── Item/SKU Sync ──

    public function importQboItems(): array
    {
        $result = $this->qboClient->query("SELECT * FROM Item WHERE Type = 'Service' MAXRESULTS 1000");
        $items = $result['QueryResponse']['Item'] ?? [];

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $hash = $this->hashQboItem($item);
            $existing = Sku::withTrashed()->where('qbo_item_id', $item['Id'])->first();

            if ($existing) {
                // Skip if nothing changed
                if ($existing->qbo_sync_hash === $hash && ! $existing->trashed()) {
                    $skipped++;

                    continue;
                }

                $existing->update([
                    'name' => $item['Name'] ?? $existing->name,
                    'description' => $item['Description'] ?? $existing->description,
                    'unit_price' => (float) ($item['UnitPrice'] ?? $existing->unit_price),
                    'unit_cost' => (float) ($item['PurchaseCost'] ?? $existing->unit_cost),
                    'is_taxable' => (bool) ($item['Taxable'] ?? $existing->is_taxable),
                    'qbo_sync_hash' => $hash,
                    'qbo_synced_at' => now(),
                    'qbo_sync_error' => null,
                    'deleted_at' => null, // Restore if soft-deleted
                ]);
                $updated++;
            } else {
                // Generate a unique sku_code from QBO item name
                $baseCode = Str::upper(Str::slug($item['Name'] ?? 'QBO-ITEM', '-'));
                $skuCode = Str::limit($baseCode, 47, '');
                $suffix = 0;
                while (Sku::withTrashed()->where('sku_code', $skuCode)->exists()) {
                    $suffix++;
                    $skuCode = Str::limit($baseCode, 44, '').'-'.$suffix;
                }

                Sku::create([
                    'name' => $item['Name'] ?? 'Unnamed QBO Item',
                    'description' => $item['Description'] ?? null,
                    'sku_code' => $skuCode,
                    'unit_price' => (float) ($item['UnitPrice'] ?? 0),
                    'unit_cost' => (float) ($item['PurchaseCost'] ?? 0),
                    'is_taxable' => (bool) ($item['Taxable'] ?? true),
                    'qbo_item_id' => $item['Id'],
                    'qbo_sync_hash' => $hash,
                    'qbo_synced_at' => now(),
                ]);
                $created++;
            }
        }

        return compact('created', 'updated', 'skipped');
    }

    public function pushItemToQbo(Sku $sku): void
    {
        if ($sku->qbo_item_id) {
            // Update existing — start from the full QBO item to preserve all
            // fields we don't manage (accounts, tax codes, etc.), then overlay
            // only the fields PSA owns.
            $existing = $this->qboClient->get("item/{$sku->qbo_item_id}");
            $data = $existing['Item'] ?? $existing;
            $data['Name'] = $sku->name;
            $data['Description'] = $sku->description ?? ($data['Description'] ?? '');
            $data['UnitPrice'] = (float) $sku->unit_price;
            $data['PurchaseCost'] = (float) $sku->unit_cost;
            $data['Taxable'] = $sku->is_taxable;
        } else {
            // Create new — Service items require IncomeAccountRef. When
            // PurchaseCost is set, QBO also wants ExpenseAccountRef so cost
            // tracking flows into the right ledger. Resolution order for
            // each: per-SKU override → global default setting → first
            // matching account in QBO.
            $incomeId = $sku->qbo_income_account_id ?: $this->resolveIncomeAccountId();
            $data = [
                'Name' => $sku->name,
                'Description' => $sku->description ?? '',
                'Type' => 'Service',
                'UnitPrice' => (float) $sku->unit_price,
                'PurchaseCost' => (float) $sku->unit_cost,
                'Taxable' => $sku->is_taxable,
                'IncomeAccountRef' => ['value' => $incomeId],
            ];

            if ((float) $sku->unit_cost > 0) {
                $expenseId = $sku->qbo_expense_account_id ?: $this->resolveExpenseAccountId();
                $data['ExpenseAccountRef'] = ['value' => $expenseId];
            }
        }

        try {
            $response = $this->qboClient->post('item', $data);
        } catch (QboClientException $e) {
            $sku->update(['qbo_sync_error' => $e->getMessage()]);
            throw $e;
        }

        $qboItem = $response['Item'] ?? $response;

        $sku->update([
            'qbo_item_id' => $qboItem['Id'] ?? $sku->qbo_item_id,
            'qbo_sync_hash' => $this->hashQboItem($qboItem),
            'qbo_synced_at' => now(),
            'qbo_sync_error' => null,
        ]);
    }

    private function hashQboItem(array $item): string
    {
        return hash('sha256', json_encode([
            'Name' => $item['Name'] ?? '',
            'Description' => $item['Description'] ?? '',
            'UnitPrice' => (float) ($item['UnitPrice'] ?? 0),
            'PurchaseCost' => (float) ($item['PurchaseCost'] ?? 0),
            'Taxable' => (bool) ($item['Taxable'] ?? true),
        ]));
    }

    /**
     * List all active QBO accounts. Cached for 6 hours.
     * Pass true to bust the cache and re-fetch.
     *
     * @return array<int, array{Id: string, Name: string, AccountType: string, AccountSubType?: string}>
     */
    public function listAccounts(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget('qbo:accounts');
        }

        return Cache::remember('qbo:accounts', now()->addHours(6), function () {
            $resp = $this->qboClient->query(
                'SELECT Id, Name, AccountType, AccountSubType FROM Account WHERE Active = true ORDERBY Name MAXRESULTS 500'
            );

            return $resp['QueryResponse']['Account'] ?? [];
        });
    }

    /**
     * Income-type accounts only, suitable for IncomeAccountRef on Service items.
     *
     * @return array<int, array{Id: string, Name: string, AccountType: string}>
     */
    public function listIncomeAccounts(bool $refresh = false): array
    {
        return array_values(array_filter(
            $this->listAccounts($refresh),
            fn ($a) => ($a['AccountType'] ?? '') === 'Income',
        ));
    }

    /**
     * Cost-of-Goods-Sold and Expense accounts, suitable for ExpenseAccountRef.
     *
     * @return array<int, array{Id: string, Name: string, AccountType: string}>
     */
    public function listExpenseAccounts(bool $refresh = false): array
    {
        return array_values(array_filter(
            $this->listAccounts($refresh),
            fn ($a) => in_array(($a['AccountType'] ?? ''), ['Cost of Goods Sold', 'Expense'], true),
        ));
    }

    /**
     * Get the QBO income account id for new Service items.
     * Cached as setting after first lookup.
     */
    private function resolveIncomeAccountId(): string
    {
        $cached = Setting::getValue('qbo_default_income_account_id');
        if ($cached) {
            return $cached;
        }

        $resp = $this->qboClient->query(
            "SELECT Id, Name FROM Account WHERE AccountType = 'Income' MAXRESULTS 5"
        );
        $accounts = $resp['QueryResponse']['Account'] ?? [];

        if (empty($accounts)) {
            throw new QboClientException(
                'No income account found in QBO. Add an Income-type account in QuickBooks (Chart of Accounts) before pushing SKUs.'
            );
        }

        $id = (string) $accounts[0]['Id'];
        Setting::setValue('qbo_default_income_account_id', $id);

        return $id;
    }

    /**
     * Get the QBO expense account id for Service items with a purchase cost.
     * Prefers Cost of Goods Sold; falls back to Expense type.
     */
    private function resolveExpenseAccountId(): string
    {
        $cached = Setting::getValue('qbo_default_expense_account_id');
        if ($cached) {
            return $cached;
        }

        foreach (['Cost of Goods Sold', 'Expense'] as $type) {
            $resp = $this->qboClient->query(
                "SELECT Id, Name FROM Account WHERE AccountType = '{$type}' MAXRESULTS 5"
            );
            $accounts = $resp['QueryResponse']['Account'] ?? [];
            if (! empty($accounts)) {
                $id = (string) $accounts[0]['Id'];
                Setting::setValue('qbo_default_expense_account_id', $id);

                return $id;
            }
        }

        throw new QboClientException(
            'No Cost of Goods Sold or Expense account found in QBO. Add one in QuickBooks before pushing SKUs with a purchase cost.'
        );
    }

    // ── Invoice Sync ──

    public function pushInvoiceToQbo(Invoice $invoice): void
    {
        $invoice->loadMissing(['client', 'lines']);

        // Validate client has QBO customer linked
        if (! $invoice->client->qbo_customer_id) {
            $error = "Client \"{$invoice->client->name}\" has no QBO customer linked. Go to Settings → QBO Client Matching.";
            $invoice->update(['qbo_sync_error' => $error]);
            throw new QboClientException($error);
        }

        $qboData = $this->buildQboInvoice($invoice);
        $isUpdate = (bool) $invoice->qbo_invoice_id;

        // UPDATE path: fetch current QBO invoice for SyncToken
        if ($isUpdate) {
            try {
                $current = $this->qboClient->get("invoice/{$invoice->qbo_invoice_id}");
            } catch (QboClientException $e) {
                $invoice->update(['qbo_sync_error' => $e->getMessage()]);
                throw $e;
            }

            $currentInvoice = $current['Invoice'] ?? $current;
            $qboData['Id'] = $currentInvoice['Id'];
            $qboData['SyncToken'] = $currentInvoice['SyncToken'];
            $this->applyCustomerMemoForUpdate($qboData, $invoice, $currentInvoice);
        }

        try {
            $response = $this->qboClient->post('invoice', $qboData);
        } catch (QboClientException $e) {
            // Retry once on 409 SyncToken conflict (same pattern as voidInvoiceInQbo)
            if ($isUpdate && $e->getHttpStatus() === 409) {
                Log::warning('[QboSync] SyncToken conflict updating invoice, retrying', [
                    'invoice_id' => $invoice->id,
                ]);
                $retry = $this->qboClient->get("invoice/{$invoice->qbo_invoice_id}");
                $retryInvoice = $retry['Invoice'] ?? $retry;
                $qboData['Id'] = $retryInvoice['Id'];
                $qboData['SyncToken'] = $retryInvoice['SyncToken'];
                $this->applyCustomerMemoForUpdate($qboData, $invoice, $retryInvoice);
                $response = $this->qboClient->post('invoice', $qboData);
            } else {
                $invoice->update(['qbo_sync_error' => $e->getMessage()]);
                throw $e;
            }
        }

        $qboInvoice = $response['Invoice'] ?? $response;

        if ($isUpdate) {
            // UPDATE: refresh tax/total + sync timestamp, keep current status.
            // Route through the same locked write-point as CREATE
            // (transitionToSynced: false) so a void that commits mid-push cannot
            // be re-inflated by this stale tax/total either — every push writer
            // re-checks at the write, not only the newest one (psa-946hr).
            $invoice->recordPushResult([
                'tax' => $qboInvoice['TxnTaxDetail']['TotalTax'] ?? $invoice->tax,
                'total' => $qboInvoice['TotalAmt'] ?? $invoice->subtotal,
                'qbo_synced_at' => now(),
                'qbo_sync_error' => null,
            ], transitionToSynced: false);
        } else {
            // CREATE: store QBO IDs and transition to Synced — but never clobber
            // a Paid/Void status a concurrent Mark-as-Paid/void (psa-8yhp) may
            // have committed while our API call was in flight. recordPushResult
            // re-reads under lock and preserves a terminal status; the id is
            // still recorded so the QBO invoice is not orphaned.
            $invoice->recordPushResult([
                'qbo_invoice_id' => $qboInvoice['Id'] ?? null,
                'qbo_doc_number' => $qboInvoice['DocNumber'] ?? null,
                'tax' => $qboInvoice['TxnTaxDetail']['TotalTax'] ?? 0,
                'total' => $qboInvoice['TotalAmt'] ?? $invoice->subtotal,
                'qbo_synced_at' => now(),
                'qbo_sync_error' => null,
            ]);
        }
    }

    public function syncInvoiceStatusFromQbo(Invoice $invoice): void
    {
        if (! $invoice->qbo_invoice_id) {
            return;
        }

        // Don't re-sync voided invoices — PSA wins for void
        if ($invoice->status === InvoiceStatus::Void) {
            return;
        }

        try {
            $response = $this->qboClient->get("invoice/{$invoice->qbo_invoice_id}");
        } catch (QboClientException $e) {
            $invoice->update(['qbo_sync_error' => $e->getMessage()]);
            throw $e;
        }

        $qboInvoice = $response['Invoice'] ?? $response;

        // Detect a QBO-side void before updating totals. The void service
        // snapshots the original amounts into pre_void_* and zeroes the
        // reportable money fields so aggregates exclude this invoice; the
        // "PSA wins for void" early-return above means this fires once.
        if ($this->qboInvoiceIsVoided($qboInvoice)) {
            Log::info('[QboSync] Void detected for invoice #'.$invoice->invoice_number, [
                'invoice_id' => $invoice->id,
            ]);
            app(InvoiceVoidService::class)->void($invoice);
            $invoice->update([
                'qbo_synced_at' => now(),
                'qbo_sync_error' => null,
            ]);

            return;
        }

        // Subtotal from QBO's SubTotalLineDetail
        $subTotalLine = collect($qboInvoice['Line'] ?? [])
            ->firstWhere('DetailType', 'SubTotalLineDetail');

        $updates = [
            'subtotal' => (float) ($subTotalLine['Amount'] ?? $invoice->subtotal),
            'tax' => $qboInvoice['TxnTaxDetail']['TotalTax'] ?? $invoice->tax,
            'total' => (float) ($qboInvoice['TotalAmt'] ?? $invoice->total),
            'qbo_synced_at' => now(),
            'qbo_sync_error' => null,
        ];

        // QBO wins for payment status, in BOTH directions (#1173).
        //
        // The default when QBO sends no Balance at all is the invoice total,
        // i.e. "fully owed" — which used to be inert (it only failed to set
        // Paid) and now is not: it would revert a Paid invoice on a malformed
        // payload. So the revert arm requires Balance to have actually been
        // present. Absent, nothing moves and the row is left as it was.
        //
        // The cast is deliberately NOT applied to an unreported balance. A
        // present-but-non-numeric Balance — 'unknown', null, a nested object
        // from an API change — casts to 0.0, which the forward arm reads as
        // "settled in full" and writes Paid onto an invoice nobody has paid.
        // That is how a row acquires a Paid status with nothing behind it,
        // which is the defect this issue exists to close; closing it in one
        // direction only would leave the other open. Unreported falls back to
        // the local total, which is what the ?? was always reaching for.
        $balanceReported = array_key_exists('Balance', $qboInvoice) && is_numeric($qboInvoice['Balance']);
        $balance = $balanceReported ? (float) $qboInvoice['Balance'] : (float) $invoice->total;

        $context = null;

        if ($balance == 0 && $invoice->status !== InvoiceStatus::Paid) {
            $updates['status'] = InvoiceStatus::Paid;
            $context = new InvoiceStatusChangeContext(
                source: InvoiceStatusChangeSource::QboPull,
                reason: 'QuickBooks reports the balance settled in full.',
                qboBalance: 0.0,
            );
        } elseif ($balanceReported && $balance > 0 && $invoice->status === InvoiceStatus::Paid
            && $invoice->stripe_invoice_id === null) {
            // A Stripe-backed invoice is never reverted, on the same rule
            // Invoice::canMarkPaid() already applies: when Stripe holds the
            // payment, Stripe is the authority for it. Nothing receipts a
            // Stripe payment into QBO, so QBO's untouched balance is not
            // evidence the client has not paid — reverting on it would re-offer
            // Pay Online for the FULL amount on an invoice already settled
            // (a second charge), and flap back to Paid on the next Stripe pull.
            //
            // T-22802: the pull was one-way, so an invoice that reached Paid
            // wrongly — by a payment reversal in QBO, or (the nine that started
            // this) by an import that asserted Paid and was never checked —
            // stayed Paid for ever, hidden from the client's owed total with no
            // record of what had set it.
            //
            // A QBO-side VOID cannot reach here: qboInvoiceIsVoided() above
            // returns first, so "not voided" is structural, not a second test.
            //
            // Posted, not Synced, is the target. Both are payable, but only
            // Posted is reachable by Invoice::scopeOverdue() — an invoice that
            // is open and past due has to be able to say so, and reverting to
            // Synced would hide it from the overdue list for ever.
            $updates['status'] = InvoiceStatus::Posted;
            $context = new InvoiceStatusChangeContext(
                source: InvoiceStatusChangeSource::QboPull,
                // QBO's total from THIS read, not the stale local one — the
                // partial-vs-full wording has to describe the same invoice the
                // balance came from, and $updates['total'] is what is about to
                // be written to the row.
                reason: $this->revertReason($balance, (float) $updates['total']),
                qboBalance: $balance,
            );

            Log::warning('[QboSync] Paid invoice reverted to open — QBO reports a balance', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'client_id' => $invoice->client_id,
                'qbo_balance' => $balance,
                'invoice_total' => (float) $invoice->total,
                'previously_synced_at' => $invoice->qbo_synced_at?->toIso8601String(),
            ]);
        } elseif ($balanceReported && $balance > 0 && $invoice->status->isClientPayable()) {
            // A balance-only REFRESH — no status move (#1173).
            //
            // The revert arm above can only fire while the invoice is Paid, so
            // after the first revert nothing would ever record a new balance:
            // an invoice reverted at 450.00 owed keeps telling the client
            // "450.00 still owed as of <that day>" after they pay another 200,
            // and qboPartialBalanceLog()'s "up to one pull cycle stale" would
            // be false. Only invoices QuickBooks has already spoken about are
            // refreshed — there is a QBO-sourced row whose figure is now wrong —
            // and only when the number actually moved, so an ordinary open
            // invoice gets no rows and an unchanged cycle writes nothing.
            $lastQboLog = $invoice->latestQboStatusChange()->first();

            if ($lastQboLog !== null && (float) $lastQboLog->qbo_balance !== $balance) {
                $context = new InvoiceStatusChangeContext(
                    source: InvoiceStatusChangeSource::QboPull,
                    reason: $this->revertReason($balance, (float) $updates['total']),
                    qboBalance: $balance,
                );
            }
        }

        // Route the final write through the locked guard: a local void that
        // committed during the GET above must not be re-inflated by this stale
        // read-back tax/total or flipped to Paid (psa-qfhc5). Mirrors the
        // push-path guard (recordPushResult). The context rides with it so the
        // audit row is written inside the same transaction as the status.
        $wasVoid = $invoice->recordStatusPullResult($updates, $context);

        // Line-item detail sync (description, quantity, unit_price, amount) —
        // skipped when the guarded write found the row Void, so a mid-round-trip
        // void's zeroed line amounts are not re-inflated (ProfitabilityService
        // sums invoice_lines.amount with no status filter, relying on that
        // zeroing). The locked-read decides this: the bead contract is "drop
        // line writes when the LOCKED row is Void". A void committing in the
        // sub-window after that read still reports live here — InvoiceVoidService
        // now takes the invoice-first row lock (psa-bl36l R5), but these line
        // updates run OUTSIDE that protocol, so closing the sub-window hard still
        // needs this writer to join it (tracked separately, psa-oc5q2); it
        // self-heals on the next void/sync.
        if (! $wasVoid) {
            $this->syncLineItemsFromQbo($invoice, $qboInvoice);
        }
    }

    /**
     * QBO marks a voided invoice by writing "Voided" into PrivateNote —
     * appended to any existing customer memo — and zeroing TotalAmt. An
     * exact-string match on PrivateNote misses voids when a prior memo
     * existed, so match on containment plus the zeroed total; requiring
     * both signals also keeps a live invoice whose memo merely mentions
     * "Voided" from being treated as void.
     */
    private function qboInvoiceIsVoided(array $qboInvoice): bool
    {
        return str_contains($qboInvoice['PrivateNote'] ?? '', 'Voided')
            && (float) ($qboInvoice['TotalAmt'] ?? 0) == 0.0;
    }

    private function syncLineItemsFromQbo(Invoice $invoice, array $qboInvoice): void
    {
        $qboLines = collect($qboInvoice['Line'] ?? [])
            ->filter(fn ($line) => ($line['DetailType'] ?? '') === 'SalesItemLineDetail');

        $psaLines = $invoice->lines()->orderBy('sort_order')->get();

        if ($qboLines->count() !== $psaLines->count()) {
            Log::warning('[QboSync] Line count mismatch for invoice #'.$invoice->invoice_number, [
                'qbo_lines' => $qboLines->count(),
                'psa_lines' => $psaLines->count(),
            ]);
        }

        foreach ($psaLines as $i => $psaLine) {
            $qboLine = $qboLines->values()->get($i);
            if (! $qboLine) {
                break;
            }

            $detail = $qboLine['SalesItemLineDetail'] ?? [];
            $psaLine->update([
                'description' => $qboLine['Description'] ?? $psaLine->description,
                'quantity' => (float) ($detail['Qty'] ?? $psaLine->quantity),
                'unit_price' => (float) ($detail['UnitPrice'] ?? $psaLine->unit_price),
                'amount' => (float) ($qboLine['Amount'] ?? $psaLine->amount),
            ]);
        }
    }

    public function voidInvoiceInQbo(Invoice $invoice): void
    {
        if (! $invoice->qbo_invoice_id) {
            return;
        }

        try {
            $response = $this->qboClient->get("invoice/{$invoice->qbo_invoice_id}");
        } catch (QboClientException $e) {
            $invoice->update(['qbo_sync_error' => $e->getMessage()]);
            throw $e;
        }

        $qboInvoice = $response['Invoice'] ?? $response;

        // Idempotency: if already voided in QBO, just update sync timestamp
        if ($this->qboInvoiceIsVoided($qboInvoice)) {
            $invoice->update([
                'qbo_synced_at' => now(),
                'qbo_sync_error' => null,
            ]);

            return;
        }

        $voidData = [
            'Id' => $qboInvoice['Id'],
            'SyncToken' => $qboInvoice['SyncToken'],
        ];

        try {
            $this->qboClient->post('invoice?operation=void', $voidData);
        } catch (QboClientException $e) {
            // Retry once on 409 SyncToken conflict
            if ($e->getHttpStatus() === 409) {
                Log::warning('[QboSync] SyncToken conflict voiding invoice, retrying', [
                    'invoice_id' => $invoice->id,
                ]);
                $retryResponse = $this->qboClient->get("invoice/{$invoice->qbo_invoice_id}");
                $retryInvoice = $retryResponse['Invoice'] ?? $retryResponse;
                $this->qboClient->post('invoice?operation=void', [
                    'Id' => $retryInvoice['Id'],
                    'SyncToken' => $retryInvoice['SyncToken'],
                ]);
            } else {
                $invoice->update(['qbo_sync_error' => $e->getMessage()]);
                throw $e;
            }
        }

        Log::info('[QboSync] Invoice voided in QBO', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);

        $invoice->update([
            'qbo_synced_at' => now(),
            'qbo_sync_error' => null,
        ]);
    }

    /**
     * How the revert should read to whoever finds it — a technician on the
     * phone, or the client reading the portal line built from it (#1173).
     *
     * A partial and a full reversal are different facts and must not share a
     * sentence: "still owed" understates a full reversal and overstates a
     * part-paid invoice.
     */
    private function revertReason(float $balance, float $total): string
    {
        $amount = number_format($balance, 2);

        return $balance < $total
            ? "QuickBooks reports {$amount} of ".number_format($total, 2).' still owed — the payment was partial or partly reversed.'
            : "QuickBooks reports the full {$amount} still owed — the payment was reversed or never applied.";
    }

    /**
     * Re-check invoices the PSA already believes are Paid against QuickBooks
     * (#1173, T-22802).
     *
     * syncAllUnpaidInvoices() below walks Invoice::unpaid(), which excludes
     * Paid by definition, so before this existed a Paid row was never looked at
     * again — the defect that let nine invoices sit Paid-in-PSA/open-in-QBO for
     * six months. This is a SEPARATE pass rather than a widening of that scope
     * on purpose: it is the expensive one (on Sound IT's own ledger it is 2,550
     * rows against 52), it needs its own bound, and keeping the unpaid walk
     * byte-identical keeps its behaviour out of this change.
     *
     * BOUNDED. One GET per invoice, so an unbounded first pass would be
     * thousands of sequential QBO calls inside a scheduler slot that holds a
     * 10-minute overlap lock — it would run past the lock, collide with the
     * next tick, and stand a fair chance of hitting QBO's rate limit. $limit
     * caps each run; never-synced rows are taken first (they are exactly the
     * imported-and-never-verified class), then least-recently-synced. A backlog
     * therefore drains over consecutive runs, oldest-doubt-first, instead of
     * all at once. A fixed minority share of each run is reserved for rows
     * whose last attempt failed, so a transient QBO error costs an invoice one
     * cycle of priority rather than removing it from the re-check for ever.
     *
     * REPORTED. The return carries the counts the operator needs to see the
     * drain finish — including `remaining`, measured AFTER the pass, which is
     * how anyone knows a first run of 2,550 is 250 done and 2,300 to go rather
     * than complete. Every individual revert is logged at warning level by
     * syncInvoiceStatusFromQbo().
     *
     * @return array{checked:int, reverted:int, errors:int, never_checked:int, failing:int}
     */
    public function syncPaidInvoicesFromQbo(int $limit = 250): array
    {
        // Stripe-backed invoices are not candidates at all: Stripe holds their
        // payment and nothing receipts it into QBO, so a QBO balance says
        // nothing about whether the client has paid. Same rule
        // Invoice::canMarkPaid() already applies. Excluded from the counts too,
        // so the reported backlog is only rows this pass will actually check.
        $query = fn () => Invoice::paid()
            ->whereNotNull('qbo_invoice_id')
            ->whereNull('stripe_invoice_id');

        // Never-synced first, then oldest sync. Written as an explicit CASE
        // rather than relying on where NULLs sort: MySQL and sqlite put them
        // first on an ASC order and Postgres puts them last, and this must not
        // depend on which engine an installation runs.
        $orderWithin = fn ($q) => $q
            ->orderByRaw('CASE WHEN qbo_synced_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('qbo_synced_at')
            ->orderBy('id');

        // Rows already carrying a sync error get a RESERVED SLICE of the run,
        // NOT a place behind every clean row. Sorting them last reads like a
        // demotion and is in fact an exile: the clean bucket is the whole Paid
        // ledger (2,550 rows here against a 250-row budget) and this pass
        // re-cycles it for ever by least-recently-synced, so a row sorted
        // behind it is never selected again — one transient 503, expired token
        // or rate-limit answer and the invoice drops out of the very re-check
        // this pass exists to perform, still wrongly Paid, still hidden from
        // the client's owed total. A bounded share instead caps what a
        // permanently failing row (an imported qbo_invoice_id QBO now 404s on)
        // can consume — it cannot burn the whole budget on the same prefix
        // while the ~2.2k never-checked backlog waits — while guaranteeing a
        // transient failure is retried on the very next run. A successful sync
        // clears the error and the row rejoins the ordinary rotation.
        $retrySlice = min($limit, max(1, intdiv($limit, 5)));

        $retries = $orderWithin($query()->whereNotNull('qbo_sync_error'))
            ->limit($retrySlice)
            ->get();

        // Whatever the retry slice does not use returns to the clean rows, so a
        // ledger with nothing failing still spends the full budget on the
        // backlog. Retries run last: a first pass still leads with the
        // never-verified rows.
        $invoices = $orderWithin($query()->whereNull('qbo_sync_error'))
            ->limit(max(0, $limit - $retries->count()))
            ->get()
            ->concat($retries);

        $results = ['checked' => 0, 'reverted' => 0, 'errors' => 0, 'never_checked' => 0, 'failing' => 0];

        foreach ($invoices as $invoice) {
            try {
                $this->syncInvoiceStatusFromQbo($invoice);
                $results['checked']++;

                // Read the row the guarded write refreshed in place, not a
                // second query: this is what actually committed. Counted on
                // Posted specifically, not on "no longer Paid" — a QBO-side
                // void also leaves Paid, and calling that a revert would report
                // a cancelled invoice as a newly-owed one.
                if ($invoice->status === InvoiceStatus::Posted) {
                    $results['reverted']++;
                }
            } catch (\Throwable $e) {
                Log::error("[QboSync] Failed to re-check paid invoice {$invoice->invoice_number}", [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
                $results['errors']++;
            }
        }

        // Counted AFTER the pass so it reflects the rows this run just
        // stamped. This is the backlog that matters — Paid invoices QuickBooks
        // has never once been asked about — and it reaching zero is how an
        // operator knows the first drain is done. It stays at zero afterwards;
        // the pass keeps cycling by least-recently-synced from then on.
        $results['never_checked'] = $query()->whereNull('qbo_synced_at')->count();

        // Every candidate whose last attempt failed, whether or not QuickBooks
        // has ever answered about it. Deliberately NOT narrowed to the
        // never-synced backlog: a row that synced successfully once and then
        // started failing keeps its qbo_synced_at, so the narrower count would
        // report zero while that invoice was being re-checked only out of the
        // reserved slice — the operator has to be able to see both. Without
        // this, a never_checked that stops falling also looks identical to one
        // the pass simply has not reached yet.
        $results['failing'] = $query()
            ->whereNotNull('qbo_sync_error')
            ->count();

        Log::info('[QboSync] Paid-invoice re-check pass complete', $results);

        return $results;
    }

    public function syncAllUnpaidInvoices(): int
    {
        $invoices = Invoice::unpaid()
            ->whereNotNull('qbo_invoice_id')
            ->get();

        $updated = 0;

        foreach ($invoices as $invoice) {
            try {
                $this->syncInvoiceStatusFromQbo($invoice);
                $updated++;
            } catch (\Throwable $e) {
                Log::error("[QboSync] Failed to sync invoice {$invoice->invoice_number}", [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $updated;
    }

    public function pushAllDraftInvoices(): array
    {
        $invoices = Invoice::where('status', InvoiceStatus::Draft)
            ->whereNull('stripe_invoice_id')
            ->with('client')
            ->get();

        $results = ['pushed' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($invoices as $invoice) {
            if (! $invoice->client->qbo_customer_id) {
                Log::warning("[QboSync] Skipping invoice {$invoice->invoice_number}: client has no QBO customer ID");
                $results['skipped']++;

                continue;
            }

            try {
                $this->pushInvoiceToQbo($invoice);
                $results['pushed']++;
            } catch (\Throwable $e) {
                Log::error("[QboSync] Failed to push invoice {$invoice->invoice_number}", [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
                $results['errors']++;
            }
        }

        return $results;
    }

    // ── Bank Balance Sync ──

    /**
     * Pull current balances for every Bank-type QBO account and upsert them
     * into `qbo_bank_accounts`, keyed by the QBO account id. Bank accounts
     * are few, so a single query page covers them. Each run overwrites the
     * stored balance with QBO's latest — this is a current-snapshot sync,
     * not a historical ledger.
     *
     * @return array{synced: int}
     */
    public function syncBankBalances(): array
    {
        $result = $this->qboClient->query(
            "SELECT * FROM Account WHERE AccountType = 'Bank' ORDERBY Name MAXRESULTS 1000"
        );
        $accounts = $result['QueryResponse']['Account'] ?? [];

        $synced = 0;

        foreach ($accounts as $account) {
            if (empty($account['Id'])) {
                continue;
            }

            QboBankAccount::updateOrCreate(
                ['qbo_account_id' => (string) $account['Id']],
                [
                    'name' => $account['Name'] ?? 'Unnamed Account',
                    'account_sub_type' => $account['AccountSubType'] ?? null,
                    'classification' => $account['Classification'] ?? null,
                    'current_balance' => (float) ($account['CurrentBalance'] ?? 0),
                    'currency' => $account['CurrencyRef']['value'] ?? null,
                    'active' => (bool) ($account['Active'] ?? true),
                    'qbo_synced_at' => now(),
                ],
            );
            $synced++;
        }

        return compact('synced');
    }

    // ── Expense Sync ──

    /**
     * Pull expense (Purchase) transactions from QBO and upsert them into
     * `qbo_expenses`, keyed by the QBO purchase id. Optionally bound to
     * transactions on or after $since (a `Y-m-d` date). Paginated with
     * STARTPOSITION so large expense volumes don't overflow a single
     * request. Upsert-by-id means re-running over an overlapping window is
     * idempotent.
     *
     * @return array{synced: int, pages: int}
     */
    public function syncExpenses(?string $since = null): array
    {
        $pageSize = 1000;
        $startPosition = 1;
        $pages = 0;
        $synced = 0;

        $where = '';
        if ($since !== null && $since !== '') {
            // TxnDate is a QBO date literal. Accept only a leading Y-m-d date
            // and drop everything else, so the value can't break out of the
            // quoted clause. Unparseable input simply omits the filter.
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $since, $matches)) {
                $where = " WHERE TxnDate >= '{$matches[0]}'";
            }
        }

        do {
            $sql = "SELECT * FROM Purchase{$where} ORDERBY TxnDate DESC STARTPOSITION {$startPosition} MAXRESULTS {$pageSize}";
            $result = $this->qboClient->query($sql);
            $purchases = $result['QueryResponse']['Purchase'] ?? [];
            $pages++;

            foreach ($purchases as $purchase) {
                if (empty($purchase['Id'])) {
                    continue;
                }

                QboExpense::updateOrCreate(
                    ['qbo_purchase_id' => (string) $purchase['Id']],
                    [
                        'txn_date' => $purchase['TxnDate'] ?? null,
                        'payment_type' => $purchase['PaymentType'] ?? null,
                        'account_name' => $purchase['AccountRef']['name'] ?? null,
                        'payee_name' => $purchase['EntityRef']['name'] ?? null,
                        'total_amount' => (float) ($purchase['TotalAmt'] ?? 0),
                        'currency' => $purchase['CurrencyRef']['value'] ?? null,
                        'doc_number' => $purchase['DocNumber'] ?? null,
                        'memo' => $purchase['PrivateNote'] ?? null,
                        'qbo_synced_at' => now(),
                    ],
                );
                $synced++;
            }

            $startPosition += $pageSize;
        } while (count($purchases) === $pageSize);

        return compact('synced', 'pages');
    }

    // ── Helpers ──

    private function buildQboInvoice(Invoice $invoice): array
    {
        $lines = [];

        foreach ($invoice->lines as $line) {
            $lineData = [
                'Amount' => (float) $line->amount,
                'DetailType' => 'SalesItemLineDetail',
                'Description' => $line->description,
                'SalesItemLineDetail' => [
                    'Qty' => (float) $line->quantity,
                    'UnitPrice' => (float) $line->unit_price,
                ],
            ];

            // QBO item ref — prefer sku linkage, fall back to snapshotted qbo_item_ref
            $itemRef = $line->sku?->qbo_item_id ?? $line->qbo_item_ref;
            if ($itemRef) {
                $lineData['SalesItemLineDetail']['ItemRef'] = ['value' => $itemRef];
            }

            // Tax code from invoice line (snapshotted at generation time)
            $lineData['SalesItemLineDetail']['TaxCodeRef'] = [
                'value' => $line->is_taxable ? 'TAX' : 'NON',
            ];

            $lines[] = $lineData;
        }

        $qboData = [
            'CustomerRef' => ['value' => $invoice->client->qbo_customer_id],
            'DocNumber' => $invoice->invoice_number,
            'TxnDate' => $invoice->invoice_date->format('Y-m-d'),
            'DueDate' => $invoice->due_date->format('Y-m-d'),
            'Line' => $lines,
        ];

        if ($memo = $this->nonRecurringSkipMemo($invoice)) {
            $qboData['CustomerMemo'] = ['value' => $memo];
        }

        return $qboData;
    }

    /**
     * The autopay-skip memo for this invoice, or null when it should not be
     * stamped. Only NON-recurring invoices (profile_id null) are stamped, and
     * only when the wording is configured — the payment processor's memo skip
     * rule then excludes them from auto-processing (#736).
     *
     * A wording is free-form prose and may span lines, but one containing a
     * BLANK line is refused rather than stamped: a blank line is what separates
     * two entries in `qbo_nonrecurring_skip_memo_retired`, so such a wording
     * could never be retired as itself — moved there verbatim it would arm each
     * of its halves as an independent one-line strip target and silently delete
     * operator-typed memo text that matches one. Not stamping is the
     * recoverable direction (rewrite the wording without the blank line and
     * push again); deleting operator text is not. The wording is still listed
     * by knownSkipMemos(), so a stamp written before this check is stripped on
     * the next push instead of becoming permanent.
     *
     * The wording is folded by foldEscapedNewlines() before it is inspected AND
     * before it is returned, so what we stamp is exactly what retiredSkipMemos()
     * produces from the same value: a wording carrying a literal `\n` would
     * otherwise be stamped unfolded and never match its folded retired form,
     * leaving a stamp no rotation can remove (#736).
     */
    private function nonRecurringSkipMemo(Invoice $invoice): ?string
    {
        if ($invoice->profile_id !== null) {
            return null;
        }

        $memo = $this->foldEscapedNewlines((string) config('billing.qbo_nonrecurring_skip_memo', ''));

        if ($memo === '') {
            return null;
        }

        // trim() already removed leading/trailing blank lines, so any blank
        // line left is an interior one — the retired-list separator.
        foreach ($this->memoLines($memo) as $line) {
            if (trim($line) === '') {
                Log::warning('[QboSync] Skip memo wording contains a blank line, which the retired list cannot represent — not stamping', [
                    'invoice_id' => $invoice->id,
                ]);

                return null;
            }
        }

        return $memo;
    }

    /**
     * A configured value with any literal `\n` folded into a real line break,
     * then trimmed. phpdotenv does not expand the escape, so a value written
     * that way following the documentation arrives as the two literal
     * characters. Every reader of a configured wording must fold identically:
     * a wording stamped in one form and matched in the other is a stamp we can
     * never remove, and the literal backslash-n would be customer-visible on
     * the invoice besides (#736).
     */
    private function foldEscapedNewlines(string $value): string
    {
        return trim(str_replace('\n', "\n", $value));
    }

    /**
     * Every skip memo this app may have stamped: the configured one plus the
     * retired values ops rotated out. A stamp is only removable while we can
     * still recognise it, so clearing or rotating the configured wording
     * without listing the old value in `qbo_nonrecurring_skip_memo_retired`
     * leaves every already-stamped invoice carrying it (#736).
     *
     * @return list<string>
     */
    private function knownSkipMemos(): array
    {
        $retired = $this->retiredSkipMemos(config('billing.qbo_nonrecurring_skip_memo_retired', []));

        $known = [];
        $configured = (string) config('billing.qbo_nonrecurring_skip_memo', '');

        foreach (array_merge([$configured], $retired) as $value) {
            // ONE form per wording: the folded one, which is the only form
            // this app stamps. Recognition is made notation-insensitive by
            // NORMALISING rather than by enumerating notations — a wording
            // re-listed carrying a literal `\n` and the same wording written
            // with a real line break fold to the same text, so a stamp is
            // matched whichever notation ops rotated it into the retired list
            // in (#736). No stamp exists in any other form: the stamp path
            // folds before writing, so an unfolded stamp could only have been
            // left by a release that never shipped.
            $form = $this->foldEscapedNewlines((string) $value);

            if ($form !== '' && ! in_array($form, $known, true)) {
                $known[] = $form;
            }
        }

        return $known;
    }

    /**
     * The retired wordings a configured value carries: an array as given, or a
     * string in which wordings are SEPARATED BY A BLANK LINE. A wording is
     * free-form customer-facing prose and nothing restricts it to one line, so
     * a single line break belongs to the wording it sits in and never
     * separates two: splitting per line would hand stripSkipMemos() the lines
     * of a multi-line wording as independent one-line strip targets, and an
     * operator-typed memo line equal to one of them would then be silently
     * deleted. A comma is never a delimiter either, and never a fallback, for
     * the same reason. An ambiguous list is read as one multi-line wording
     * rather than several one-line ones, because failing to strip a stamp is
     * recoverable (list it again) and deleting operator text is not.
     *
     * A literal `\n` is read as a line break, because phpdotenv does not turn
     * it into one, so a value written that way following the documentation
     * arrives with the two literal characters — `\n\n` therefore separates two
     * wordings. Wordings come back FOLDED, in the one form this app stamps, so
     * a retired wording is recognised whichever notation it is listed in. A
     * stamp we fail to recognise is one we can never remove (#736).
     *
     * An ARRAY entry is split on blank lines exactly like the string form. It
     * carries the same literal `\n` when it was pasted here from the env value,
     * so an entry holding `\n\n` is two wordings; kept whole it would be one
     * known value with a blank line INSIDE it, and stripSkipMemos() matches
     * that blank against any blank line in the operator's memo — a block that
     * spans, and silently deletes, operator-typed text.
     *
     * A blank line is therefore not representable INSIDE a wording here, which
     * is why nonRecurringSkipMemo() refuses to stamp a wording that contains
     * one: every wording we ever stamp can be listed here as itself, so moving
     * one here verbatim can never shred it into fragments that strip
     * operator-typed text.
     *
     * @return list<string>
     */
    private function retiredSkipMemos(mixed $retired): array
    {
        $values = [];

        foreach (is_array($retired) ? $retired : [$retired] as $entry) {
            foreach ($this->retiredWordings((string) $entry) as $wording) {
                $values[] = $wording;
            }
        }

        return $values;
    }

    /**
     * One configured value's wordings, separated by a blank line and returned
     * folded. A line break is a real one or the two literal characters `\n` —
     * phpdotenv does not expand the escape — and both are folded to a real
     * break before splitting, so a wording reads the same whichever notation
     * it was written in and a literal `\n\n` separates two wordings exactly
     * like a blank line (#736).
     *
     * @return list<string>
     */
    private function retiredWordings(string $text): array
    {
        $values = [];
        $wording = '';

        // memoLines() splits on the ASCII newline forms only; `\R` is unsafe
        // on UTF-8 memo text — see its docblock.
        foreach ($this->memoLines($this->foldEscapedNewlines($text)) as $line) {
            if (trim($line) === '') {
                if ($wording !== '') {
                    $values[] = trim($wording);
                    $wording = '';
                }

                continue;
            }

            $wording = $wording === '' ? $line : $wording."\n".$line;
        }

        if ($wording !== '') {
            $values[] = trim($wording);
        }

        return $values;
    }

    /**
     * The operator-owned part of a QBO CustomerMemo — every run of lines except
     * the ones we stamped. Our own stamp is not text to preserve: it is
     * re-derived from the invoice on every push, so it cannot outlive the
     * reason it was written (a one-off invoice later attached to a recurring
     * profile, or the stamping being switched off).
     *
     * A configured wording is free-form customer-facing prose and nothing
     * restricts it to one line, so a known wording is matched as a whole BLOCK
     * of consecutive lines rather than line by line: a multi-line stamp we
     * failed to recognise would be re-appended by applyCustomerMemoForUpdate on
     * every push, growing a customer-visible field without bound and never
     * removable (#736). The longest matching block wins, so a wording that
     * begins with another known wording is still consumed whole. Individual
     * lines of a multi-line wording are never strip targets on their own —
     * fragments must not delete operator-typed text.
     */
    private function stripSkipMemos(string $memo): string
    {
        $known = [];
        foreach ($this->knownSkipMemos() as $value) {
            $block = array_map('trim', $this->memoLines($value));
            if ($block !== []) {
                $known[] = $block;
            }
        }

        $lines = $this->memoLines($memo);
        $trimmed = array_map('trim', $lines);
        $kept = [];

        for ($i = 0, $count = count($lines); $i < $count;) {
            $matched = 0;
            foreach ($known as $block) {
                $length = count($block);
                if ($length > $matched && array_slice($trimmed, $i, $length) === $block) {
                    $matched = $length;
                }
            }

            if ($matched > 0) {
                $i += $matched;

                continue;
            }

            $kept[] = $lines[$i];
            $i++;
        }

        return trim(implode("\n", $kept));
    }

    /**
     * Memo text split into lines on the ASCII newline forms only. PCRE's `\R`
     * without the `u` modifier also matches the lone byte 0x85 (NEL), which is
     * a legal UTF-8 continuation byte (`Å` is 0xC3 0x85), so it splits ordinary
     * operator text mid-character — and the pieces are rejoined and posted back
     * as the whole customer-visible memo. Adding `u` would fix the split but
     * makes preg_split return false on any non-UTF-8 memo, i.e. wipe it; CR and
     * LF bytes never occur inside a UTF-8 multi-byte sequence, so this class is
     * correct either way.
     *
     * @return list<string>
     */
    private function memoLines(string $text): array
    {
        return preg_split('/\r\n|\n|\r/', $text) ?: [];
    }

    /**
     * UPDATE-path memo decision. Operator text already on the QBO invoice is
     * echoed back — a QBO full update clears omitted writable fields, so
     * leaving it out would clobber it — and the skip memo is re-derived
     * alongside it, appended on its own line rather than replacing it or being
     * displaced by it: a memo someone typed in QBO must not cost a one-off
     * invoice the autopay exemption the stamp exists to give it (#736).
     * Conversely a stamp we wrote earlier is stripped before that decision, so
     * it survives only while the invoice is still non-recurring and the memo
     * still configured. Called again after a 409-retry refetch so the decision
     * always reflects the invoice state the write is based on.
     */
    private function applyCustomerMemoForUpdate(array &$qboData, Invoice $invoice, array $currentInvoice): void
    {
        $memo = $this->stripSkipMemos((string) ($currentInvoice['CustomerMemo']['value'] ?? ''));

        if ($skip = $this->nonRecurringSkipMemo($invoice)) {
            $memo = $memo === '' ? $skip : $memo."\n".$skip;
        }

        unset($qboData['CustomerMemo']);

        if ($memo !== '') {
            $qboData['CustomerMemo'] = ['value' => $memo];
        }
    }

    private function normalizeName(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $name)));
    }
}
