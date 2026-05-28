<?php

namespace App\Services\Stripe;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\Sku;
use App\Services\SyncResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StripeSyncService
{
    public function __construct(
        private readonly StripeClient $stripeClient,
    ) {}

    // ── Customer Matching ──

    public function fetchStripeCustomers(): array
    {
        $customers = $this->stripeClient->getAllCustomers();

        return collect($customers)
            ->map(fn ($c) => [
                'id' => $c['id'],
                'name' => $c['name'] ?? '',
                'email' => $c['email'] ?? '',
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    public function autoMatchClients(): array
    {
        $stripeCustomers = $this->fetchStripeCustomers();
        $clients = Client::active()->whereNull('stripe_customer_id')->get();

        $alreadyMappedIds = Client::whereNotNull('stripe_customer_id')
            ->pluck('stripe_customer_id')
            ->flip();

        $matched = [];
        $unmatched = [];
        $ambiguous = [];

        foreach ($clients as $client) {
            $normalizedName = $this->normalizeName($client->name);

            $matches = collect($stripeCustomers)->filter(function ($sc) use ($normalizedName, $client, $alreadyMappedIds) {
                if ($alreadyMappedIds->has($sc['id'])) {
                    return false;
                }

                // Match by name or email
                return $this->normalizeName($sc['name']) === $normalizedName
                    || (! empty($client->email) && ! empty($sc['email'])
                        && strtolower($client->email) === strtolower($sc['email']));
            });

            if ($matches->count() === 1) {
                $stripeCustomer = $matches->first();
                try {
                    $client->update(['stripe_customer_id' => $stripeCustomer['id']]);
                    $alreadyMappedIds[$stripeCustomer['id']] = true;
                    $matched[] = [
                        'client' => $client->name,
                        'stripe_name' => $stripeCustomer['name'],
                        'stripe_id' => $stripeCustomer['id'],
                    ];
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    Log::warning('[Stripe AutoMatch] Skipped duplicate', ['client' => $client->name]);
                    $unmatched[] = ['client' => $client->name];
                }
            } elseif ($matches->count() > 1) {
                $ambiguous[] = [
                    'client' => $client->name,
                    'stripe_matches' => $matches->pluck('name')->all(),
                ];
            } else {
                $unmatched[] = ['client' => $client->name];
            }
        }

        return compact('matched', 'unmatched', 'ambiguous');
    }

    // ── Invoice Push ──

    public function pushInvoiceToStripe(Invoice $invoice, bool $sendEmail = false): void
    {
        $invoice->loadMissing(['client', 'lines.sku']);

        if (! $invoice->client->stripe_customer_id) {
            $error = "Client \"{$invoice->client->name}\" has no Stripe customer linked. Go to Settings → Stripe Customer Matching.";
            $invoice->update(['stripe_sync_error' => $error]);
            throw new StripeClientException($error);
        }

        try {
            // 1. Create draft invoice
            $invoiceData = [
                'customer' => $invoice->client->stripe_customer_id,
                'auto_advance' => 'false',
                'collection_method' => 'send_invoice',
                'days_until_due' => ($invoice->due_date && $invoice->invoice_date)
                    ? max(0, (int) $invoice->invoice_date->diffInDays($invoice->due_date))
                    : 30,
                'metadata[psa_invoice_id]' => (string) $invoice->id,
                'metadata[psa_invoice_number]' => $invoice->invoice_number,
                'automatic_tax[enabled]' => 'true',
            ];

            $stripeInvoice = $this->stripeClient->createInvoice($invoiceData);
            $stripeInvoiceId = $stripeInvoice['id'];

            // 2. Add line items
            foreach ($invoice->lines as $line) {
                $itemData = [
                    'invoice' => $stripeInvoiceId,
                    'customer' => $invoice->client->stripe_customer_id,
                    'description' => $line->description,
                ];

                if ($line->is_taxable) {
                    // Taxable: price_data with quantity and explicit tax behavior
                    $productId = $this->resolveStripeProductId($line);
                    $isWholeQuantity = floor($line->quantity) == $line->quantity;
                    if ($isWholeQuantity) {
                        $itemData['quantity'] = (int) $line->quantity;
                    } else {
                        // Stripe accepts decimal quantities as a string via quantity_decimal
                        $itemData['quantity_decimal'] = (string) $line->quantity;
                    }
                    $itemData['price_data[currency]'] = 'usd';
                    $itemData['price_data[product]'] = $productId;
                    $itemData['price_data[unit_amount]'] = $this->dollarsToCents($line->unit_price);
                    $itemData['price_data[tax_behavior]'] = 'exclusive';
                } else {
                    // Non-taxable: amount (no quantity, no price_data) + nontaxable tax code
                    $itemData['amount'] = $this->dollarsToCents($line->amount);
                    $itemData['currency'] = 'usd';
                    $itemData['tax_code'] = 'txcd_00000000';
                }

                $this->stripeClient->createInvoiceItem($itemData);
            }

            // 3. Finalize
            $finalized = $this->stripeClient->finalizeInvoice($stripeInvoiceId);

            // 4. Send email to client if requested
            if ($sendEmail) {
                $this->stripeClient->sendInvoice($stripeInvoiceId);
            }

            // 5. Read back tax and totals
            $tax = $this->centsToDollars($finalized['tax'] ?? 0);
            $total = $this->centsToDollars($finalized['total'] ?? 0);

            $invoice->update([
                'stripe_invoice_id' => $stripeInvoiceId,
                'stripe_invoice_url' => $finalized['hosted_invoice_url'] ?? null,
                'tax' => $tax,
                'total' => $total,
                'status' => InvoiceStatus::Synced,
                'stripe_synced_at' => now(),
                'stripe_sync_error' => null,
            ]);
        } catch (StripeClientException $e) {
            $invoice->update(['stripe_sync_error' => $e->getMessage()]);
            throw $e;
        }
    }

    // ── Payment Status Pull ──

    public function syncInvoiceStatusFromStripe(Invoice $invoice): void
    {
        if (! $invoice->stripe_invoice_id) {
            return;
        }

        if ($invoice->status === InvoiceStatus::Void) {
            return; // PSA wins for void
        }

        try {
            $stripeInvoice = $this->stripeClient->getInvoice($invoice->stripe_invoice_id);
        } catch (StripeClientException $e) {
            $invoice->update(['stripe_sync_error' => $e->getMessage()]);
            throw $e;
        }

        $updates = [
            'tax' => $this->centsToDollars($stripeInvoice['tax'] ?? $this->dollarsToCents($invoice->tax)),
            'total' => $this->centsToDollars($stripeInvoice['total'] ?? $this->dollarsToCents($invoice->total)),
            'stripe_synced_at' => now(),
            'stripe_sync_error' => null,
        ];

        // Map Stripe status → PSA status
        $stripeStatus = $stripeInvoice['status'] ?? '';
        if ($stripeStatus === 'paid' && $invoice->status !== InvoiceStatus::Paid) {
            $updates['status'] = InvoiceStatus::Paid;
        }

        $invoice->update($updates);
    }

    public function syncAllUnpaidInvoices(): int
    {
        $invoices = Invoice::whereIn('status', [InvoiceStatus::Synced])
            ->whereNotNull('stripe_invoice_id')
            ->get();

        $updated = 0;

        foreach ($invoices as $invoice) {
            try {
                $this->syncInvoiceStatusFromStripe($invoice);
                $updated++;
            } catch (\Throwable $e) {
                Log::error("[StripeSync] Failed to sync invoice {$invoice->invoice_number}", [
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
            if (! $invoice->client->stripe_customer_id) {
                $results['skipped']++;
                continue;
            }

            try {
                $this->pushInvoiceToStripe($invoice);
                $results['pushed']++;
            } catch (\Throwable $e) {
                Log::error("[StripeSync] Failed to push invoice {$invoice->invoice_number}", [
                    'error' => $e->getMessage(),
                ]);
                $results['errors']++;
            }
        }

        return $results;
    }

    // ── Product/SKU Sync ──

    public function importStripeProducts(): array
    {
        // Expand default_price so we get pricing data in the same call
        $response = $this->stripeClient->get('/v1/products', [
            'limit' => 100,
            'active' => 'true',
            'expand[]' => 'data.default_price',
        ]);
        $products = $response['data'] ?? [];

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($products as $product) {
            // Extract price from the expanded default_price object
            $defaultPrice = $product['default_price'] ?? null;
            $unitPrice = null;
            $stripePriceId = null;

            if (is_array($defaultPrice)) {
                $stripePriceId = $defaultPrice['id'] ?? null;
                $unitAmount = $defaultPrice['unit_amount'] ?? null;
                if ($unitAmount !== null) {
                    $unitPrice = $this->centsToDollars($unitAmount);
                }
            } elseif (is_string($defaultPrice)) {
                // Not expanded — fetch it separately
                try {
                    $priceData = $this->stripeClient->get("/v1/prices/{$defaultPrice}");
                    $stripePriceId = $priceData['id'] ?? null;
                    $unitAmount = $priceData['unit_amount'] ?? null;
                    if ($unitAmount !== null) {
                        $unitPrice = $this->centsToDollars($unitAmount);
                    }
                } catch (\Throwable) {
                    // Price fetch failed — continue without price
                }
            }

            // No default_price — look up the product's active prices
            if (! $stripePriceId) {
                try {
                    $prices = $this->stripeClient->get('/v1/prices', [
                        'product' => $product['id'],
                        'active' => 'true',
                        'limit' => 1,
                    ]);
                    $firstPrice = $prices['data'][0] ?? null;
                    if ($firstPrice) {
                        $stripePriceId = $firstPrice['id'] ?? null;
                        $unitAmount = $firstPrice['unit_amount'] ?? null;
                        if ($unitAmount !== null) {
                            $unitPrice = $this->centsToDollars($unitAmount);
                        }
                    }
                } catch (\Throwable) {
                    // Price lookup failed — continue without price
                }
            }

            $existing = Sku::withTrashed()->where('stripe_product_id', $product['id'])->first();

            if ($existing) {
                $updates = [
                    'name' => $product['name'] ?? $existing->name,
                    'description' => $product['description'] ?? $existing->description,
                    'stripe_synced_at' => now(),
                    'deleted_at' => null,
                ];
                if ($unitPrice !== null) {
                    $updates['unit_price'] = $unitPrice;
                }
                if ($stripePriceId) {
                    $updates['stripe_price_id'] = $stripePriceId;
                }
                $existing->update($updates);
                $updated++;
            } else {
                $baseCode = Str::upper(Str::slug($product['name'] ?? 'STRIPE-PRODUCT', '-'));
                $skuCode = Str::limit($baseCode, 47, '');
                $suffix = 0;
                while (Sku::withTrashed()->where('sku_code', $skuCode)->exists()) {
                    $suffix++;
                    $skuCode = Str::limit($baseCode, 44, '') . '-' . $suffix;
                }

                Sku::create([
                    'name' => $product['name'] ?? 'Unnamed Stripe Product',
                    'description' => $product['description'] ?? null,
                    'sku_code' => $skuCode,
                    'unit_price' => $unitPrice ?? 0,
                    'stripe_product_id' => $product['id'],
                    'stripe_price_id' => $stripePriceId,
                    'stripe_synced_at' => now(),
                ]);
                $created++;
            }
        }

        return compact('created', 'updated', 'skipped');
    }

    public function pushProductToStripe(Sku $sku): void
    {
        try {
            if ($sku->stripe_product_id) {
                // Update existing product
                $this->stripeClient->updateProduct($sku->stripe_product_id, [
                    'name' => $sku->name,
                    'description' => $sku->description ?? '',
                ]);
            } else {
                // Create new product
                $product = $this->stripeClient->createProduct([
                    'name' => $sku->name,
                    'description' => $sku->description ?? '',
                    'metadata[psa_sku_id]' => (string) $sku->id,
                    'metadata[sku_code]' => $sku->sku_code,
                ]);
                $sku->stripe_product_id = $product['id'];
            }

            // Create/update price (Stripe prices are immutable — create new one each time)
            $price = $this->stripeClient->createPrice([
                'product' => $sku->stripe_product_id,
                'unit_amount' => $this->dollarsToCents($sku->unit_price),
                'currency' => 'usd',
            ]);

            $sku->update([
                'stripe_price_id' => $price['id'],
                'stripe_synced_at' => now(),
            ]);
        } catch (StripeClientException $e) {
            Log::error("[StripeSync] Failed to push SKU {$sku->sku_code}: {$e->getMessage()}");
            throw $e;
        }
    }

    // ── Invoice Import (Stripe → PSA) ──

    public function importInvoicesFromStripe(?callable $onProgress = null, ?string $createdAfter = null): SyncResult
    {
        $result = new SyncResult();
        $skipped = 0;
        $maxCreated = 0;

        $extraParams = [];
        if ($createdAfter) {
            $extraParams['created[gte]'] = strtotime($createdAfter);
        }

        $startingAfter = null;
        $processed = 0;

        for ($page = 0; $page < 500; $page++) {
            try {
                $response = $this->stripeClient->listInvoices(100, $startingAfter, $extraParams);
            } catch (StripeClientException $e) {
                $result->recordError("Failed to fetch invoices: {$e->getMessage()}");
                break;
            }

            $invoices = $response['data'] ?? [];
            if (empty($invoices)) {
                break;
            }

            foreach ($invoices as $data) {
                $stripeId = $data['id'] ?? null;
                if (! $stripeId) {
                    continue;
                }

                // Skip drafts
                if (($data['status'] ?? '') === 'draft') {
                    continue;
                }

                // Skip invoices pushed FROM PSA (round-trip prevention)
                if (! empty($data['metadata']['psa_invoice_id'])) {
                    continue;
                }

                // Track max created timestamp for incremental sync
                $created = (int) ($data['created'] ?? 0);
                if ($created > $maxCreated) {
                    $maxCreated = $created;
                }

                try {
                    $existing = Invoice::withTrashed()
                        ->where('stripe_invoice_id', $stripeId)
                        ->exists();

                    $invoice = $this->upsertInvoiceFromStripeData($data);

                    if ($invoice) {
                        $existing ? $result->updated++ : $result->created++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $result->recordError("Invoice {$stripeId}: {$e->getMessage()}");
                    Log::error("[StripeImport] Failed to import {$stripeId}", [
                        'error' => $e->getMessage(),
                    ]);
                }

                $processed++;
            }

            if ($onProgress) {
                $onProgress($processed);
            }

            if (! ($response['has_more'] ?? false)) {
                break;
            }

            $startingAfter = end($invoices)['id'] ?? null;
            if (! $startingAfter) {
                break;
            }

            if ($page >= 490) {
                Log::warning('[StripeImport] Approaching page safety cap (500 pages)');
            }
        }

        // Store watermark for incremental sync
        if ($maxCreated > 0) {
            Setting::setValue(
                'stripe_invoice_import_last_sync',
                Carbon::createFromTimestamp($maxCreated)->toIso8601String()
            );
        }

        // Attach skipped count to summary via deactivated field
        $result->deactivated = $skipped;

        return $result;
    }

    private function upsertInvoiceFromStripeData(array $data): ?Invoice
    {
        $stripeId = $data['id'];

        // Resolve client
        $stripeCustomerId = $data['customer'] ?? null;
        if (! $stripeCustomerId) {
            Log::info('[StripeImport] Skipping invoice — no customer', ['stripe_id' => $stripeId]);
            return null;
        }

        $client = Client::where('stripe_customer_id', $stripeCustomerId)->first();
        if (! $client) {
            Log::info('[StripeImport] Skipping invoice — no matching client', [
                'stripe_id' => $stripeId,
                'stripe_customer_id' => $stripeCustomerId,
            ]);
            return null;
        }

        // Invoice number: Stripe's number, fallback to bare Stripe ID
        $invoiceNumber = $data['number'] ?? $stripeId;
        $invoiceNumber = Str::limit($invoiceNumber, 50, '');

        // Check for collision with a different invoice
        $collision = Invoice::withTrashed()
            ->where('invoice_number', $invoiceNumber)
            ->where(function ($q) use ($stripeId) {
                $q->where('stripe_invoice_id', '!=', $stripeId)
                    ->orWhereNull('stripe_invoice_id');
            })
            ->exists();

        if ($collision) {
            $invoiceNumber = Str::limit($stripeId, 50, '');
        }

        // Dates
        $invoiceDate = isset($data['created'])
            ? Carbon::createFromTimestamp($data['created'])->toDateString()
            : today()->toDateString();

        $dueDate = ! empty($data['due_date'])
            ? Carbon::createFromTimestamp($data['due_date'])->toDateString()
            : $invoiceDate;

        // Amounts (cents → dollars)
        $subtotal = $this->centsToDollars($data['subtotal'] ?? 0);
        $tax = $this->centsToDollars($data['tax'] ?? 0);
        $total = $this->centsToDollars($data['total'] ?? 0);

        // Status
        $status = $this->mapStripeInvoiceStatus($data['status'] ?? '');

        // Check for status regression — don't downgrade Void or Paid
        $existing = Invoice::withTrashed()->where('stripe_invoice_id', $stripeId)->first();
        if ($existing) {
            $existingStatus = $existing->status;
            if ($existingStatus === InvoiceStatus::Void || $existingStatus === InvoiceStatus::Paid) {
                // Only allow Void→Void or Paid→Paid (no downgrade)
                if ($status !== InvoiceStatus::Void && $existingStatus === InvoiceStatus::Void) {
                    $status = InvoiceStatus::Void;
                }
                if ($status !== InvoiceStatus::Paid && $status !== InvoiceStatus::Void && $existingStatus === InvoiceStatus::Paid) {
                    $status = InvoiceStatus::Paid;
                }
            }

            // Restore if soft-deleted
            if ($existing->trashed()) {
                $existing->restore();
            }
        }

        $invoice = Invoice::withTrashed()->updateOrCreate(
            ['stripe_invoice_id' => $stripeId],
            [
                'client_id' => $client->id,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'status' => $status,
                'stripe_invoice_url' => $data['hosted_invoice_url'] ?? null,
                'stripe_synced_at' => now(),
                'stripe_sync_error' => null,
            ],
        );

        // Sync line items
        $lines = $data['lines']['data'] ?? [];
        $hasMore = $data['lines']['has_more'] ?? false;

        if ($hasMore) {
            $lines = $this->stripeClient->getAllInvoiceLines($stripeId);
        }

        if (! empty($lines)) {
            $this->syncStripeInvoiceLines($invoice, $lines);
        }

        return $invoice;
    }

    private function syncStripeInvoiceLines(Invoice $invoice, array $lines): void
    {
        // Delete-and-recreate for imported records (Stripe is source of truth)
        $invoice->lines()->delete();

        foreach ($lines as $i => $line) {
            $quantity = (float) ($line['quantity'] ?? 1);
            $amount = $this->centsToDollars($line['amount'] ?? 0);

            // Derive unit price: price.unit_amount → unit_amount_excluding_tax → calculated
            $unitPrice = null;
            if (isset($line['price']['unit_amount'])) {
                $unitPrice = $this->centsToDollars($line['price']['unit_amount']);
            } elseif (isset($line['unit_amount_excluding_tax'])) {
                $unitPrice = $this->centsToDollars((int) $line['unit_amount_excluding_tax']);
            } elseif ($quantity > 0) {
                $unitPrice = round($amount / $quantity, 2);
            } else {
                $unitPrice = $amount;
            }

            // Best-effort SKU match via Stripe product ID
            $skuId = null;
            $stripeProductId = $line['price']['product'] ?? null;
            if ($stripeProductId) {
                $skuId = Sku::where('stripe_product_id', $stripeProductId)->value('id');
            }

            // Taxability from line's tax_amounts
            $isTaxable = ! empty($line['tax_amounts']);

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'sku_id' => $skuId,
                'description' => $line['description'] ?? 'Stripe line item',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'is_taxable' => $isTaxable,
                'sort_order' => $i,
            ]);
        }
    }

    private function mapStripeInvoiceStatus(string $stripeStatus): InvoiceStatus
    {
        return match ($stripeStatus) {
            'paid' => InvoiceStatus::Paid,
            'open' => InvoiceStatus::Synced,
            'void' => InvoiceStatus::Void,
            'uncollectible' => InvoiceStatus::Void,
            default => InvoiceStatus::Draft,
        };
    }

    // ── Helpers ──

    /**
     * Get the Stripe product ID for a line item's SKU.
     * Throws if the SKU is missing or hasn't been imported to Stripe.
     */
    private function resolveStripeProductId(InvoiceLine $line): string
    {
        if ($line->sku?->stripe_product_id) {
            return $line->sku->stripe_product_id;
        }

        $skuLabel = $line->sku
            ? "SKU \"{$line->sku->sku_code}\" is missing a Stripe product ID. Re-import products from Settings → Stripe."
            : "Invoice line \"{$line->description}\" has no SKU linked.";

        throw new StripeClientException($skuLabel);
    }

    private function dollarsToCents(float $dollars): int
    {
        return (int) round($dollars * 100);
    }

    private function centsToDollars(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function normalizeName(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $name)));
    }
}
