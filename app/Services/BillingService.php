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
use App\Services\NotificationService;
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
            ->where('is_active', true)
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
        $profile->loadMissing(['contract.client', 'lines.sku']);
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

                $amount = round($quantity * (float) $line->unit_price, 2);
                $subtotal += $amount;

                $unitCost = (float) ($line->unit_cost_override ?? $line->sku?->unit_cost ?? 0);
                $costAmount = round($quantity * $unitCost, 2);
                $totalCost += $costAmount;

                $quantitySource = $this->buildQuantitySource(
                    $line->quantity_type, $quantity, $invoiceDate, $contract,
                    $line->license_type_id, $line,
                );

                $prepaidMinutesPerUnit = $line->prepaid_time_override ?? $line->sku?->prepaid_time_minutes;
                $prepaidTimeMinutes = $prepaidMinutesPerUnit ? (int) ($quantity * $prepaidMinutesPerUnit) : null;

                $lineData[] = [
                    'sku_id' => $line->sku_id,
                    'description' => $line->description,
                    'quantity' => $quantity,
                    'unit_price' => $line->unit_price,
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                    'cost_amount' => $costAmount,
                    'prepaid_time_minutes' => $prepaidTimeMinutes,
                    'quantity_source' => $quantitySource,
                    'is_taxable' => $line->is_taxable,
                    'qbo_item_ref' => $line->sku?->qbo_item_id,
                    'sort_order' => $line->sort_order,
                ];
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
        $months = $profile->billing_period->months();
        $nextDate = $invoiceDate->copy()->addMonths($months);

        $profile->update([
            'next_run_date' => $nextDate,
            'last_run_date' => today(),
        ]);
    }

    public function previewInvoice(RecurringInvoiceProfile $profile): array
    {
        $profile->loadMissing(['contract.client', 'lines.sku']);
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

            $amount = round($quantity * (float) $line->unit_price, 2);
            $subtotal += $amount;

            $prepaidMinutesPerUnit = $line->prepaid_time_override ?? $line->sku?->prepaid_time_minutes;
            $prepaidTimeMinutes = $prepaidMinutesPerUnit ? (int) ($quantity * $prepaidMinutesPerUnit) : null;
            if ($prepaidTimeMinutes) {
                $totalPrepaidMinutes += $prepaidTimeMinutes;
            }

            $lines[] = [
                'description' => $line->description,
                'quantity' => $quantity,
                'unit_price' => (float) $line->unit_price,
                'amount' => $amount,
                'prepaid_time_minutes' => $prepaidTimeMinutes,
                'quantity_type' => $line->quantity_type->label(),
                'quantity_source' => $this->buildQuantitySource(
                    $line->quantity_type, $quantity, $invoiceDate, $contract,
                    $line->license_type_id, $line,
                ),
            ];
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
            ->orderByRaw("CAST(SUBSTRING(invoice_number, ?) AS UNSIGNED) DESC", [strlen($prefix) + 2])
            ->value('invoice_number');

        $next = 1;
        if ($last && preg_match('/(\d+)$/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        // Use whichever is higher: the DB sequence or the configured floor
        $next = max($next, $floorNumber);

        return sprintf('%s-%05d', $prefix, $next);
    }

    private function buildQuantitySource(
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
                ? Client::where('reseller_id', $client->id)->where('is_active', true)->count()
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

        return (int) now()->diffInHours(\Illuminate\Support\Carbon::parse($oldestSync));
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
            ->where('is_active', true)
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

        return (int) now()->diffInHours(\Illuminate\Support\Carbon::parse($oldestSync));
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
