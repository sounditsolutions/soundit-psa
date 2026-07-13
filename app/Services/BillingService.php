<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PersonType;
use App\Enums\QuantityType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\Setting;
use App\Support\PricingModelConflict;
use App\Support\TieredPricing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingService
{
    /**
     * Resolve the quantity for a profile line.
     * When a contract has assignments, counts are contract-scoped.
     * Falls back to client-wide for contracts with no assignments.
     */
    public function resolveQuantity(
        QuantityType $type,
        Client $client,
        ?float $fixedQuantity = null,
        ?Contract $contract = null,
        ?int $licenseTypeId = null,
        ?int $usageLicenseTypeId = null,
        ?int $baseLicenseTypeId = null,
        int $includedPerBaseUnit = 0,
        int $overageDivisor = 1,
    ): int {
        if ($type === QuantityType::Fixed) {
            return max(1, (int) ($fixedQuantity ?? 1));
        }

        return match ($type) {
            QuantityType::PerWorkstation => $this->countAssets(
                $client, $contract, $this->getWorkstationTypes(),
            ),

            QuantityType::PerServer => $this->countAssets(
                $client, $contract, $this->getServerTypes(),
            ),

            QuantityType::PerWorkstationAndServer => $this->countAssets(
                $client, $contract, array_merge($this->getWorkstationTypes(), $this->getServerTypes()),
            ),

            QuantityType::PerUser => $this->countPeople($client, $contract),

            QuantityType::PerLicense => $this->countLicenses($client, $contract),

            QuantityType::PerLicenseType => $this->countLicensesByType(
                $client, $contract, $licenseTypeId,
            ),

            QuantityType::PerResellerLicenseType => $this->countResellerLicensesByType(
                $client, $licenseTypeId,
            ),

            QuantityType::Overage => $this->countOverage(
                $client, $contract, $usageLicenseTypeId, $baseLicenseTypeId,
                $includedPerBaseUnit, $overageDivisor,
            ),

            QuantityType::PerBackupStorageGb => $this->countBackupStorageGb($client, $contract),

            default => 1,
        };
    }

    /**
     * Count assets — contract-scoped if contract has assignments, client-wide otherwise.
     */
    private function countAssets(Client $client, ?Contract $contract, array $assetTypes): int
    {
        if ($contract && $contract->assets()->exists()) {
            return $contract->assets()
                ->whereNull('assets.deleted_at')
                ->where('assets.is_active', true)
                ->whereIn('assets.asset_type', $assetTypes)
                ->count();
        }

        return $client->assets()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('asset_type', $assetTypes)
            ->count();
    }

    /**
     * Count people — contract-scoped if contract has assignments, client-wide otherwise.
     */
    private function countPeople(Client $client, ?Contract $contract): int
    {
        if ($contract && $contract->people()->exists()) {
            return $contract->people()
                ->whereNull('people.deleted_at')
                ->where('people.is_active', true)
                ->where('people.person_type', PersonType::User->value)
                ->count();
        }

        return $client->people()
            ->where('is_active', true)
            ->billable()
            ->count();
    }

    /**
     * Count all active licenses — contract-scoped if contract has license assignments, client-wide otherwise.
     */
    private function countLicenses(Client $client, ?Contract $contract): int
    {
        if ($contract && $contract->licenses()->exists()) {
            return $contract->licenses()
                ->where('licenses.status', 'active')
                ->sum('licenses.quantity');
        }

        return $client->licenses()
            ->where('status', 'active')
            ->sum('quantity');
    }

    /**
     * Count active licenses of a specific type — contract-scoped if possible.
     */
    private function countLicensesByType(Client $client, ?Contract $contract, ?int $licenseTypeId): int
    {
        if (! $licenseTypeId) {
            return 0;
        }

        if ($contract && $contract->licenses()->exists()) {
            return $contract->licenses()
                ->where('licenses.status', 'active')
                ->where('licenses.license_type_id', $licenseTypeId)
                ->sum('licenses.quantity');
        }

        return $client->licenses()
            ->where('status', 'active')
            ->where('license_type_id', $licenseTypeId)
            ->sum('quantity');
    }

    /**
     * Count active licenses of a specific type across all reseller children (not contract-scoped).
     */
    private function countResellerLicensesByType(Client $client, ?int $licenseTypeId): int
    {
        if (! $licenseTypeId) {
            return 0;
        }

        $childIds = Client::where('reseller_id', $client->id)
            ->operational()
            ->pluck('id');

        if ($childIds->isEmpty()) {
            return 0;
        }

        return (int) DB::table('licenses')
            ->whereIn('client_id', $childIds)
            ->where('status', 'active')
            ->where('license_type_id', $licenseTypeId)
            ->sum('quantity');
    }

    /**
     * Count overage: usage above what's included per base unit.
     *
     * Formula: max(0, ceil((usage - base × includedPerBase) / divisor))
     */
    private function countOverage(
        Client $client,
        ?Contract $contract,
        ?int $usageLicenseTypeId,
        ?int $baseLicenseTypeId,
        int $includedPerBaseUnit,
        int $overageDivisor,
    ): int {
        if (! $usageLicenseTypeId) {
            return 0;
        }

        $usage = $this->countLicensesByType($client, $contract, $usageLicenseTypeId);

        $base = $baseLicenseTypeId
            ? $this->countLicensesByType($client, $contract, $baseLicenseTypeId)
            : 1;

        $included = $base * $includedPerBaseUnit;
        $overageRaw = max(0, $usage - $included);
        $divisor = max(1, $overageDivisor);

        return (int) ceil($overageRaw / $divisor);
    }

    /**
     * Total backup cloud storage in whole GB — contract-scoped if the contract
     * has asset assignments, client-wide otherwise. Summed from the
     * `backup_cloud_bytes` populated on assets by backup vendor syncs, then
     * converted bytes → GB with the same binary rounding the vendor sync uses
     * for the `cloud_usage_gb` license type, so the two stay consistent.
     */
    private function countBackupStorageGb(Client $client, ?Contract $contract): int
    {
        $bytes = ($contract && $contract->assets()->exists())
            ? (int) $contract->assets()
                ->whereNull('assets.deleted_at')
                ->where('assets.is_active', true)
                ->sum('assets.backup_cloud_bytes')
            : (int) $client->assets()
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->sum('backup_cloud_bytes');

        return (int) round($bytes / (1024 ** 3));
    }

    /**
     * Resolve the single unit price for a line that is NOT graduated.
     *
     * Backup-storage lines whose SKU carries a tier rate card use VOLUME
     * pricing — the whole quantity is billed at the one tier rate that covers
     * the measured GB ({@see \App\Models\Sku::priceForStorageGb()}). Everything
     * else (and backup lines without tiers) bills at the flat line unit price.
     *
     * Only ever reached from priceLineSegments(), and only when the line has no
     * graduated bands of its own — see the precedence rule there.
     */
    private function resolveUnitPrice(RecurringInvoiceProfileLine $line, int $quantity): float
    {
        if ($line->quantity_type === QuantityType::PerBackupStorageGb && $line->sku) {
            $tierPrice = $line->sku->priceForStorageGb($quantity);
            if ($tierPrice !== null) {
                return $tierPrice;
            }
        }

        return (float) $line->unit_price;
    }

    /**
     * Break a profile line into one or more priced segments — the single seam
     * where a (line, quantity) turns into money.
     *
     * Two *different* pricing models can apply to a line, and both are
     * legitimate. They are resolved here, in this order:
     *
     *   1. GRADUATED — `pricing_tiers` on the profile line, valid with any
     *      quantity type. Tax-bracket pricing: the quantity is split into bands
     *      and each band bills at its own rate. Yields one segment per consumed
     *      band. {@see \App\Support\TieredPricing}
     *   2. VOLUME — `backup_storage_tiers` on the line's SKU, backup-storage
     *      lines only. The whole quantity bills at the single rate whose bound
     *      covers it. Yields one segment. {@see \App\Models\Sku::priceForStorageGb()}
     *   3. FLAT — the line's `unit_price`. Yields one segment.
     *
     * Graduated wins over volume because it is the more specific configuration:
     * it is set on *this line*, while the volume card is inherited from the
     * product. That is already the precedence every other override on the
     * line-generation path follows (`unit_cost_override ?? sku->unit_cost`,
     * `prepaid_time_override ?? sku->prepaid_time_minutes`). The alternative
     * fails worse: a SKU rate card silently overriding bands the operator
     * explicitly put on the line would bill a pricing model nobody configured.
     *
     * That combination is now REFUSED at every door that can create it
     * ({@see \App\Support\PricingModelConflict}) — but this precedence stays, as
     * defence in depth. Validation stops new conflicts; it cannot un-write a row
     * that predates it. So if one still reaches billing, it must bill
     * deterministically and say so: loudly in the log, and by name in the invoice
     * line's `quantity_source`.
     *
     * Every segment carries `amount = round(quantity × unit_price, 2)`,
     * whichever model priced it, so that invariant holds for every invoice line
     * we emit. Both push paths depend on it: Stripe derives a taxable line's
     * charge from quantity × unit_amount and ignores our `amount` entirely,
     * and QBO reconciles `Amount` against `Qty × UnitPrice`. Splitting a line
     * into bands cannot lose a cent, because resolveQuantity() only ever
     * returns whole units and an integer times a 2-dp price is already exact to
     * the cent (TieredPricing::normalize() guarantees the 2 dp).
     *
     * A zero quantity yields a single zero-unit segment (record of coverage).
     *
     * @return array<int, array{quantity: int, unit_price: float, amount: float, label: ?string}>
     */
    private function priceLineSegments(RecurringInvoiceProfileLine $line, int $quantity): array
    {
        $tiers = $line->pricingTiers();

        // Not graduated: one segment, priced by the volume rate card or flat.
        if ($tiers === []) {
            $unitPrice = $this->resolveUnitPrice($line, $quantity);

            return [[
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => round($quantity * $unitPrice, 2),
                'label' => null,
            ]];
        }

        // A row that got in before the guard existed. Bill it deterministically,
        // and do not let it pass quietly.
        if (PricingModelConflict::onLine($line)) {
            Log::warning('[Billing] Profile line {line} carries graduated tiers and its SKU carries a volume rate card; the line\'s graduated tiers win.', [
                'line' => $line->id,
                'profile_id' => $line->profile_id,
                'sku_id' => $line->sku_id,
            ]);
        }

        // The client reads these band labels on the invoice, so name what is
        // being counted where the quantity type knows ("1–100 GB", "1–3
        // workstations"); "units" only where it genuinely does not.
        $noun = $line->quantity_type->unitNoun();

        $rows = [];

        foreach (TieredPricing::breakdown($tiers, $quantity) as $segment) {
            $rows[] = [
                'quantity' => $segment['quantity'],
                'unit_price' => $segment['unit_price'],
                'amount' => round($segment['quantity'] * $segment['unit_price'], 2),
                // Annotate the band range onto the invoice line description.
                // Skipped for the zero-quantity placeholder segment.
                'label' => $quantity > 0
                    ? "{$segment['from']}\u{2013}{$segment['to']} {$noun} @ \$".number_format($segment['unit_price'], 2)
                    : null,
            ];
        }

        return $rows;
    }

    public function generateInvoicesForDueProfiles(): array
    {
        $profiles = RecurringInvoiceProfile::due()->with(['contract.client', 'lines'])->get();
        $results = [];

        foreach ($profiles as $profile) {
            try {
                $result = $this->generateInvoice($profile);
                $results[] = array_merge($result, [
                    'client' => $profile->contract->client->name,
                    'profile' => $profile->name,
                ]);
            } catch (\Throwable $e) {
                Log::error("Failed to generate invoice for profile {$profile->id}: {$e->getMessage()}", [
                    'profile_id' => $profile->id,
                    'contract_id' => $profile->contract_id,
                    'exception' => $e,
                ]);
                try {
                    app(NotificationService::class)->notifyInvoiceGenerationFailed($profile, $e->getMessage());
                } catch (\Throwable $notifyError) {
                    Log::warning("[Billing] Failed to send generation failure notification: {$notifyError->getMessage()}");
                }
                $results[] = [
                    'status' => 'error',
                    'client' => $profile->contract->client->name ?? 'Unknown',
                    'profile' => $profile->name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Generate an invoice for a recurring profile.
     *
     * Returns: ['status' => 'created'|'skipped', 'invoice' => ?Invoice, 'reason' => ?string]
     */
    public function generateInvoice(RecurringInvoiceProfile $profile): array
    {
        $profile->loadMissing(['contract.client', 'lines.sku.backupStorageTiers']);
        $contract = $profile->contract;
        $client = $contract->client;
        $invoiceDate = $profile->next_run_date;

        return DB::transaction(function () use ($profile, $contract, $client, $invoiceDate) {
            // Idempotency check — includes soft-deleted (voided) invoices
            $exists = Invoice::withTrashed()
                ->where('profile_id', $profile->id)
                ->where('invoice_date', $invoiceDate)
                ->exists();

            if ($exists) {
                return ['status' => 'skipped', 'invoice' => null, 'reason' => 'exists'];
            }

            // Phase 1: Compute all line data in memory (no DB writes yet)
            $lineData = [];
            $subtotal = 0;
            $totalCost = 0;
            $hasNonZeroQty = false;
            $sortOrder = 0;

            foreach ($profile->lines as $line) {
                $quantity = $this->resolveQuantity(
                    $line->quantity_type, $client, $line->fixed_quantity, $contract,
                    $line->license_type_id,
                    $line->usage_license_type_id, $line->base_license_type_id,
                    $line->included_per_base_unit ?? 0, $line->overage_divisor ?? 1,
                );

                if ($quantity > 0) {
                    $hasNonZeroQty = true;
                }

                $unitCost = (float) ($line->unit_cost_override ?? $line->sku?->unit_cost ?? 0);
                $prepaidMinutesPerUnit = $line->prepaid_time_override ?? $line->sku?->prepaid_time_minutes;

                $quantitySource = $this->buildQuantitySource(
                    $line->quantity_type, $quantity, $invoiceDate, $contract,
                    $line->license_type_id, $line,
                );

                // Flat and volume-priced lines yield one segment; graduated
                // lines expand into one invoice line per consumed band.
                foreach ($this->priceLineSegments($line, $quantity) as $segment) {
                    $segQty = $segment['quantity'];
                    $amount = $segment['amount'];
                    $subtotal += $amount;

                    $costAmount = round($segQty * $unitCost, 2);
                    $totalCost += $costAmount;

                    $prepaidTimeMinutes = $prepaidMinutesPerUnit ? (int) ($segQty * $prepaidMinutesPerUnit) : null;

                    $description = $segment['label'] !== null
                        ? $line->description." ({$segment['label']})"
                        : $line->description;

                    $lineData[] = [
                        'sku_id' => $line->sku_id,
                        'description' => $description,
                        'quantity' => $segQty,
                        'unit_price' => $segment['unit_price'],
                        'unit_cost' => $unitCost,
                        'amount' => $amount,
                        'cost_amount' => $costAmount,
                        'prepaid_time_minutes' => $prepaidTimeMinutes,
                        'quantity_source' => $quantitySource,
                        'is_taxable' => $line->is_taxable,
                        'qbo_item_ref' => $line->sku?->qbo_item_id,
                        // A running counter, NOT $line->sort_order: expanding one
                        // profile line into several bands would otherwise emit
                        // invoice lines that tie on sort_order, and both QBO push
                        // (buildQboInvoice) and QBO readback (syncLineItemsFromQbo)
                        // pair lines up by their sort_order *position*. A tie makes
                        // that order DB-dependent, so a readback could write one
                        // band's amounts onto another band's row. Profile lines are
                        // already iterated in sort_order (see the lines() relation),
                        // so this preserves their order and breaks ties by band.
                        'sort_order' => $sortOrder++,
                    ];
                }
            }

            // Phase 2: Check if invoice should be skipped (nothing to bill)
            // Skip when: no lines OR all lines resolved to 0 qty
            // Do NOT skip: lines with qty >= 1 at $0 price (record of coverage)
            if ($profile->shouldSkipZeroInvoices() && (empty($lineData) || ! $hasNonZeroQty)) {
                Log::warning('[Billing] Skipped empty invoice for profile {id} ({name})', [
                    'id' => $profile->id,
                    'name' => $profile->name,
                ]);

                // Advance next_run_date even when skipping
                $this->advanceNextRunDate($profile, $invoiceDate);

                return ['status' => 'skipped', 'invoice' => null, 'reason' => 'nothing_to_bill'];
            }

            // Phase 3: Create the invoice and write lines
            $invoiceNumber = $this->nextInvoiceNumber();

            for ($attempt = 0; ; $attempt++) {
                try {
                    $invoice = Invoice::create([
                        'client_id' => $client->id,
                        'contract_id' => $contract->id,
                        'profile_id' => $profile->id,
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => $invoiceDate,
                        'due_date' => $invoiceDate->copy()->addDays($profile->payment_terms_days),
                        'status' => InvoiceStatus::Draft,
                    ]);
                    break;
                } catch (QueryException $e) {
                    if ($attempt < 1 && $e->errorInfo[1] == 1062) {
                        Log::warning("Invoice number {$invoiceNumber} collision, retrying", [
                            'profile_id' => $profile->id,
                        ]);
                        $invoiceNumber = $this->nextInvoiceNumber();

                        continue;
                    }
                    throw $e;
                }
            }

            foreach ($lineData as $data) {
                InvoiceLine::create(array_merge(['invoice_id' => $invoice->id], $data));
            }

            $invoice->update([
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'total_cost' => $totalCost,
                'margin' => round($subtotal - $totalCost, 2),
            ]);

            $this->advanceNextRunDate($profile, $invoiceDate);

            return ['status' => 'created', 'invoice' => $invoice, 'reason' => null];
        });
    }

    private function advanceNextRunDate(RecurringInvoiceProfile $profile, $invoiceDate): void
    {
        $profile->update([
            'next_run_date' => $profile->billing_period->advance($invoiceDate),
            'last_run_date' => today(),
        ]);
    }

    public function previewInvoice(RecurringInvoiceProfile $profile): array
    {
        $profile->loadMissing(['contract.client', 'lines.sku.backupStorageTiers']);
        $contract = $profile->contract;
        $client = $contract->client;
        $invoiceDate = $profile->next_run_date ?? today();

        $lines = [];
        $subtotal = 0;
        $totalPrepaidMinutes = 0;
        $hasNonZeroQty = false;

        foreach ($profile->lines as $line) {
            $quantity = $this->resolveQuantity(
                $line->quantity_type, $client, $line->fixed_quantity, $contract,
                $line->license_type_id,
                $line->usage_license_type_id, $line->base_license_type_id,
                $line->included_per_base_unit ?? 0, $line->overage_divisor ?? 1,
            );

            if ($quantity > 0) {
                $hasNonZeroQty = true;
            }

            $prepaidMinutesPerUnit = $line->prepaid_time_override ?? $line->sku?->prepaid_time_minutes;
            $quantityTypeLabel = $line->quantity_type->label();
            $quantitySource = $this->buildQuantitySource(
                $line->quantity_type, $quantity, $invoiceDate, $contract,
                $line->license_type_id, $line,
            );

            // Mirror generateInvoice(): graduated lines preview as one row per band.
            foreach ($this->priceLineSegments($line, $quantity) as $segment) {
                $segQty = $segment['quantity'];
                $amount = $segment['amount'];
                $subtotal += $amount;

                $prepaidTimeMinutes = $prepaidMinutesPerUnit ? (int) ($segQty * $prepaidMinutesPerUnit) : null;
                if ($prepaidTimeMinutes) {
                    $totalPrepaidMinutes += $prepaidTimeMinutes;
                }

                $description = $segment['label'] !== null
                    ? $line->description." ({$segment['label']})"
                    : $line->description;

                $lines[] = [
                    'description' => $description,
                    'quantity' => $segQty,
                    'unit_price' => $segment['unit_price'],
                    'amount' => $amount,
                    'prepaid_time_minutes' => $prepaidTimeMinutes,
                    'quantity_type' => $quantityTypeLabel,
                    'quantity_source' => $quantitySource,
                ];
            }
        }

        $wouldSkip = $profile->shouldSkipZeroInvoices() && (empty($lines) || ! $hasNonZeroQty);

        return [
            'client' => $client->name,
            'invoice_date' => $invoiceDate->format('Y-m-d'),
            'due_date' => $invoiceDate->copy()->addDays($profile->payment_terms_days)->format('Y-m-d'),
            'lines' => $lines,
            'subtotal' => $subtotal,
            'total_prepaid_minutes' => $totalPrepaidMinutes > 0 ? $totalPrepaidMinutes : null,
            'would_skip' => $wouldSkip,
        ];
    }

    public function nextInvoiceNumber(): string
    {
        $prefix = config('billing.invoice_prefix', 'INV');

        // Floor number from Settings — allows continuing from prior system's numbering
        $floorNumber = (int) Setting::getValue('billing_invoice_next_number', 0);

        $last = Invoice::withTrashed()
            ->where('invoice_number', 'like', "{$prefix}-%")
            ->orderByRaw('CAST(SUBSTRING(invoice_number, ?) AS UNSIGNED) DESC', [strlen($prefix) + 2])
            ->value('invoice_number');

        $next = 1;
        if ($last && preg_match('/(\d+)$/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        // Use whichever is higher: the DB sequence or the configured floor
        $next = max($next, $floorNumber);

        return sprintf('%s-%05d', $prefix, $next);
    }

    /**
     * The audit snapshot stored on each generated invoice line: how the quantity
     * was measured, and which rate card actually priced it.
     *
     * The two halves are independent, and either can be empty:
     *
     *  - A Fixed line records no *quantity* source. The operator typed the
     *    number; there is nothing to audit about how much.
     *  - A flat line records no *pricing* marker. There is no rate card to name.
     *
     * A Fixed + graduated line therefore still gets a quantity_source — just the
     * pricing half. It has to: Fixed + graduated is the commonest graduated
     * config there is ("first 10 @ $10, the rest @ $8" on a typed quantity), and
     * an audit record silent about the model that priced it is the exact failure
     * this feature is supposed to prevent. Only a plain flat Fixed line records
     * nothing at all — unchanged from before graduated pricing existed.
     */
    private function buildQuantitySource(
        QuantityType $type,
        int $quantity,
        $date,
        ?Contract $contract = null,
        ?int $licenseTypeId = null,
        ?RecurringInvoiceProfileLine $line = null,
    ): ?string {
        $source = $this->describeQuantity($type, $quantity, $date, $contract, $licenseTypeId, $line);
        $pricing = $this->describePricing($line, $quantity);

        if ($source === null) {
            return $pricing === '' ? null : ltrim($pricing);
        }

        return $source.$pricing;
    }

    /**
     * Name the rate card that actually priced the line, so the stored audit
     * record can never claim a rate that was not applied. Empty for plain flat
     * pricing. Mirrors the precedence in priceLineSegments() — if it did not,
     * a graduated backup-storage line would be annotated with the volume tier
     * rate its own bands overrode.
     */
    private function describePricing(?RecurringInvoiceProfileLine $line, int $quantity): string
    {
        if (! $line) {
            return '';
        }

        $tiers = $line->pricingTiers();
        if ($tiers !== []) {
            return ' [graduated: '.count($tiers).' bands]';
        }

        if ($line->quantity_type === QuantityType::PerBackupStorageGb) {
            $tierPrice = $line->sku?->priceForStorageGb($quantity);
            if ($tierPrice !== null) {
                return sprintf(' [volume tier rate $%.2f/GB]', $tierPrice);
            }
        }

        return '';
    }

    private function describeQuantity(
        QuantityType $type,
        int $quantity,
        $date,
        ?Contract $contract = null,
        ?int $licenseTypeId = null,
        ?RecurringInvoiceProfileLine $line = null,
    ): ?string {
        if ($type === QuantityType::Fixed) {
            return null;
        }

        $dateStr = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;
        $scope = $contract ? 'contract' : 'client';

        // Overage gets a detailed breakdown
        if ($type === QuantityType::Overage && $line) {
            $client = $contract?->client ?? $line->profile?->contract?->client;
            $usage = $client ? $this->countLicensesByType($client, $contract, $line->usage_license_type_id) : 0;
            $base = $line->base_license_type_id
                ? ($client ? $this->countLicensesByType($client, $contract, $line->base_license_type_id) : 1)
                : 1;
            $includedPer = $line->included_per_base_unit ?? 0;
            $divisor = max(1, $line->overage_divisor ?? 1);
            $included = $base * $includedPer;
            $raw = max(0, $usage - $included);

            $source = "{$quantity} overage ({$usage} usage - {$base} base × {$includedPer} included = {$raw} raw / {$divisor} divisor, {$scope}-scoped) as of {$dateStr}";

            // Staleness check on usage license type
            $staleHours = $this->getLicenseStalenessHours($contract, $line->usage_license_type_id);
            if ($staleHours !== null && $staleHours > 48) {
                $source .= " [STALE: license data is {$staleHours}h old]";
                Log::warning("[Billing] Stale license data ({$staleHours}h) used for overage resolution", [
                    'contract_id' => $contract?->id,
                    'usage_license_type_id' => $line->usage_license_type_id,
                    'quantity' => $quantity,
                ]);
            }

            return $source;
        }

        // Reseller license type gets its own format with child client count
        if ($type === QuantityType::PerResellerLicenseType) {
            $client = $contract?->client ?? $line?->profile?->contract?->client;
            $childCount = $client
                ? Client::where('reseller_id', $client->id)->operational()->count()
                : 0;

            $source = "{$quantity} per reseller license type (across {$childCount} child clients) as of {$dateStr}";

            $staleHours = $this->getResellerLicenseStalenessHours($client, $licenseTypeId);
            if ($staleHours !== null && $staleHours > 48) {
                $source .= " [STALE: license data is {$staleHours}h old]";
                Log::warning("[Billing] Stale license data ({$staleHours}h) used for reseller quantity resolution", [
                    'contract_id' => $contract?->id,
                    'reseller_client_id' => $client?->id,
                    'license_type_id' => $licenseTypeId,
                    'quantity' => $quantity,
                ]);
            }

            return $source;
        }

        // Backup storage records the measured GB. The rate that priced it is
        // appended by describePricing() — volume tiers only apply when the line
        // has no graduated bands of its own.
        if ($type === QuantityType::PerBackupStorageGb) {
            return "{$quantity} GB backup storage ({$scope}-scoped) as of {$dateStr}";
        }

        $label = strtolower($type->label());
        $source = "{$quantity} {$label} ({$scope}-scoped) as of {$dateStr}";

        // Staleness warning for license-based quantities
        if ($type === QuantityType::PerLicense || $type === QuantityType::PerLicenseType) {
            $staleHours = $this->getLicenseStalenessHours($contract, $licenseTypeId);
            if ($staleHours !== null && $staleHours > 48) {
                $source .= " [STALE: license data is {$staleHours}h old]";
                Log::warning("[Billing] Stale license data ({$staleHours}h) used for quantity resolution", [
                    'contract_id' => $contract?->id,
                    'license_type_id' => $licenseTypeId,
                    'quantity' => $quantity,
                ]);
            }
        }

        return $source;
    }

    /**
     * Check how old the newest license sync is for this contract/type.
     * Returns hours since last sync, or null if no licenses found.
     */
    private function getLicenseStalenessHours(?Contract $contract, ?int $licenseTypeId): ?int
    {
        $query = $contract && $contract->licenses()->exists()
            ? $contract->licenses()->where('licenses.status', 'active')
            : ($contract ? $contract->client->licenses()->where('status', 'active') : null);

        if (! $query) {
            return null;
        }

        if ($licenseTypeId) {
            $query->where('license_type_id', $licenseTypeId);
        }

        $oldestSync = $query->min('synced_at');

        if (! $oldestSync) {
            return null; // Manual licenses have no synced_at — not stale
        }

        // Sign-safe (psa-lqlu): $past->diffInHours(now()) is positive; the now()-first form is
        // NEGATIVE in Carbon 3, reporting negative "hours since sync".
        return (int) \Illuminate\Support\Carbon::parse($oldestSync)->diffInHours(now());
    }

    /**
     * Check how old the newest license sync is across all reseller children for a given type.
     * Returns hours since oldest sync, or null if no synced licenses found.
     */
    private function getResellerLicenseStalenessHours(?Client $client, ?int $licenseTypeId): ?int
    {
        if (! $client) {
            return null;
        }

        $childIds = Client::where('reseller_id', $client->id)
            ->operational()
            ->pluck('id');

        if ($childIds->isEmpty()) {
            return null;
        }

        $query = DB::table('licenses')
            ->whereIn('client_id', $childIds)
            ->where('status', 'active');

        if ($licenseTypeId) {
            $query->where('license_type_id', $licenseTypeId);
        }

        $oldestSync = $query->min('synced_at');

        if (! $oldestSync) {
            return null;
        }

        // Sign-safe (psa-lqlu): $past->diffInHours(now()) is positive; the now()-first form is
        // NEGATIVE in Carbon 3, reporting negative "hours since sync".
        return (int) \Illuminate\Support\Carbon::parse($oldestSync)->diffInHours(now());
    }

    /**
     * Get workstation asset types from Settings (falls back to config).
     */
    private function getWorkstationTypes(): array
    {
        $json = Setting::getValue('billing_workstation_types');
        if ($json) {
            $types = json_decode($json, true);
            if (is_array($types) && count($types) > 0) {
                return $types;
            }
        }

        return config('billing.quantity_sources.per_workstation.asset_types', []);
    }

    /**
     * Get server asset types from Settings (falls back to config).
     */
    private function getServerTypes(): array
    {
        $json = Setting::getValue('billing_server_types');
        if ($json) {
            $types = json_decode($json, true);
            if (is_array($types) && count($types) > 0) {
                return $types;
            }
        }

        return config('billing.quantity_sources.per_server.asset_types', []);
    }
}
