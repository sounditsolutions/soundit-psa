<?php

namespace App\Services\Qbo;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\QboBankAccount;
use App\Models\QboExpense;
use App\Models\Setting;
use App\Models\Sku;
use App\Services\InvoiceVoidService;
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

        // QBO wins for payment status: Balance = 0 → paid
        $balance = (float) ($qboInvoice['Balance'] ?? $invoice->total);
        if ($balance == 0 && $invoice->status !== InvoiceStatus::Paid) {
            $updates['status'] = InvoiceStatus::Paid;
        }

        // Route the final write through the locked guard: a local void that
        // committed during the GET above must not be re-inflated by this stale
        // read-back tax/total or flipped to Paid (psa-qfhc5). Mirrors the
        // push-path guard (recordPushResult).
        $wasVoid = $invoice->recordStatusPullResult($updates);

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
     */
    private function nonRecurringSkipMemo(Invoice $invoice): ?string
    {
        if ($invoice->profile_id !== null) {
            return null;
        }

        $memo = trim((string) config('billing.qbo_nonrecurring_skip_memo', ''));

        return $memo !== '' ? $memo : null;
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
        foreach (array_merge([config('billing.qbo_nonrecurring_skip_memo', '')], $retired) as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ! in_array($value, $known, true)) {
                $known[] = $value;
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
     * A literal `\n` is folded into a line break first, because phpdotenv does
     * not turn it into one, so a value written that way following the
     * documentation arrives with the two literal characters — `\n\n` therefore
     * separates two wordings. A stamp we fail to recognise is one we can never
     * remove (#736).
     *
     * @return list<string>
     */
    private function retiredSkipMemos(mixed $retired): array
    {
        if (is_array($retired)) {
            return array_values($retired);
        }

        $text = str_replace('\n', "\n", (string) $retired);

        $values = [];
        $wording = [];

        // memoLines() splits on the ASCII newline forms only — see there for
        // why `\R` is unsafe on UTF-8 memo text.
        foreach ($this->memoLines($text) as $line) {
            if (trim($line) === '') {
                if ($wording !== []) {
                    $values[] = implode("\n", $wording);
                    $wording = [];
                }

                continue;
            }

            $wording[] = $line;
        }

        if ($wording !== []) {
            $values[] = implode("\n", $wording);
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
