<?php

namespace App\Services\Qbo;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Sku;
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
        $result = $this->qboClient->query("SELECT * FROM Customer MAXRESULTS 1000");

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
        $clients = Client::active()->whereNull('qbo_customer_id')->get();

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
                    && !$alreadyMappedQboIds->has($qc['Id']);
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
                    $skuCode = Str::limit($baseCode, 44, '') . '-' . $suffix;
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
                "SELECT Id, Name, AccountType, AccountSubType FROM Account WHERE Active = true ORDERBY Name MAXRESULTS 500"
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
        if (!$invoice->client->qbo_customer_id) {
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
                $response = $this->qboClient->post('invoice', $qboData);
            } else {
                $invoice->update(['qbo_sync_error' => $e->getMessage()]);
                throw $e;
            }
        }

        $qboInvoice = $response['Invoice'] ?? $response;

        if ($isUpdate) {
            // UPDATE: refresh tax/total and sync timestamp, keep current status
            $invoice->update([
                'tax' => $qboInvoice['TxnTaxDetail']['TotalTax'] ?? $invoice->tax,
                'total' => $qboInvoice['TotalAmt'] ?? $invoice->subtotal,
                'qbo_synced_at' => now(),
                'qbo_sync_error' => null,
            ]);
        } else {
            // CREATE: store QBO IDs and transition to Synced
            $invoice->update([
                'qbo_invoice_id' => $qboInvoice['Id'] ?? null,
                'qbo_doc_number' => $qboInvoice['DocNumber'] ?? null,
                'tax' => $qboInvoice['TxnTaxDetail']['TotalTax'] ?? 0,
                'total' => $qboInvoice['TotalAmt'] ?? $invoice->subtotal,
                'status' => InvoiceStatus::Synced,
                'qbo_synced_at' => now(),
                'qbo_sync_error' => null,
            ]);
        }
    }

    public function syncInvoiceStatusFromQbo(Invoice $invoice): void
    {
        if (!$invoice->qbo_invoice_id) {
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

        // QBO sets PrivateNote to "Voided" when an invoice is voided.
        // Detect this before updating totals — QBO zeroes out amounts on void,
        // and we must preserve the original totals for reporting/audit.
        if (($qboInvoice['PrivateNote'] ?? '') === 'Voided') {
            Log::info('[QboSync] Void detected for invoice #' . $invoice->invoice_number, [
                'invoice_id' => $invoice->id,
            ]);
            $invoice->update([
                'status' => InvoiceStatus::Void,
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

        $invoice->update($updates);

        // Sync line item details (description, quantity, unit_price, amount)
        $this->syncLineItemsFromQbo($invoice, $qboInvoice);
    }

    private function syncLineItemsFromQbo(Invoice $invoice, array $qboInvoice): void
    {
        $qboLines = collect($qboInvoice['Line'] ?? [])
            ->filter(fn ($line) => ($line['DetailType'] ?? '') === 'SalesItemLineDetail');

        $psaLines = $invoice->lines()->orderBy('sort_order')->get();

        if ($qboLines->count() !== $psaLines->count()) {
            Log::warning('[QboSync] Line count mismatch for invoice #' . $invoice->invoice_number, [
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
        if (!$invoice->qbo_invoice_id) {
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
        if (($qboInvoice['PrivateNote'] ?? '') === 'Voided') {
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
            if (!$invoice->client->qbo_customer_id) {
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

        return [
            'CustomerRef' => ['value' => $invoice->client->qbo_customer_id],
            'DocNumber' => $invoice->invoice_number,
            'TxnDate' => $invoice->invoice_date->format('Y-m-d'),
            'DueDate' => $invoice->due_date->format('Y-m-d'),
            'Line' => $lines,
        ];
    }

    private function normalizeName(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $name)));
    }
}
