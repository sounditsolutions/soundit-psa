<?php

namespace Tests\Feature\Invoices;

use App\Enums\QuantityType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the generalised formula-based quantity resolution (GitHub #126):
 * the included-allowance + divisor formula that was previously Overage-only
 * now applies to every dynamic quantity type.
 *
 *     qty = max(0, ceil((usage - base × includedPerBaseUnit) / divisor))
 */
class QuantityFormulaResolutionTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = new BillingService;
    }

    private function clientWithAssets(int $count, string $assetType = 'Workstation'): Client
    {
        $client = Client::factory()->create();

        if ($count > 0) {
            Asset::factory()->count($count)->create([
                'client_id' => $client->id,
                'asset_type' => $assetType,
            ]);
        }

        return $client;
    }

    private function licenseType(): LicenseType
    {
        return LicenseType::create([
            'name' => 'Type '.uniqid(),
            'vendor' => 'test',
        ]);
    }

    private function giveLicenses(Client $client, LicenseType $type, int $quantity): void
    {
        License::create([
            'client_id' => $client->id,
            'license_type_id' => $type->id,
            'quantity' => $quantity,
            'status' => 'active',
        ]);
    }

    // ── Backward compatibility: neutral formula is a no-op ──

    public function test_dynamic_type_with_no_formula_returns_the_raw_count(): void
    {
        $client = $this->clientWithAssets(5);

        // Default args (no allowance, divisor 1) — how every existing line resolves.
        $this->assertSame(5, $this->billing->resolveQuantity(QuantityType::PerWorkstation, $client));

        // Explicit neutral formula resolves identically.
        $this->assertSame(5, $this->billing->resolveQuantity(
            QuantityType::PerWorkstation, $client,
            includedPerBaseUnit: 0, overageDivisor: 1,
        ));
    }

    // ── Included allowance ──

    public function test_included_allowance_is_subtracted_from_the_count(): void
    {
        $client = $this->clientWithAssets(5);

        // max(0, ceil((5 - 2) / 1)) = 3
        $this->assertSame(3, $this->billing->resolveQuantity(
            QuantityType::PerWorkstation, $client, includedPerBaseUnit: 2,
        ));
    }

    public function test_included_allowance_floors_the_result_at_zero(): void
    {
        $client = $this->clientWithAssets(3);

        // max(0, ceil((3 - 10) / 1)) = 0 — never bills a negative quantity.
        $this->assertSame(0, $this->billing->resolveQuantity(
            QuantityType::PerWorkstation, $client, includedPerBaseUnit: 10,
        ));
    }

    // ── Divisor (billing in blocks) ──

    public function test_divisor_bills_in_blocks_and_rounds_up(): void
    {
        // 10 in blocks of 5 → exactly 2 blocks.
        $client = $this->clientWithAssets(10);
        $this->assertSame(2, $this->billing->resolveQuantity(
            QuantityType::PerWorkstation, $client, overageDivisor: 5,
        ));

        // 11 in blocks of 5 → ceil(11/5) = 3 (a partial block still bills).
        $client = $this->clientWithAssets(11);
        $this->assertSame(3, $this->billing->resolveQuantity(
            QuantityType::PerWorkstation, $client, overageDivisor: 5,
        ));
    }

    public function test_included_allowance_and_divisor_combine(): void
    {
        $client = $this->clientWithAssets(12);

        // max(0, ceil((12 - 2) / 5)) = ceil(10 / 5) = 2
        $this->assertSame(2, $this->billing->resolveQuantity(
            QuantityType::PerWorkstation, $client,
            includedPerBaseUnit: 2, overageDivisor: 5,
        ));
    }

    public function test_divisor_of_zero_is_treated_as_one(): void
    {
        $client = $this->clientWithAssets(7);

        // A zero/garbage divisor must not divide-by-zero; it falls back to 1.
        $this->assertSame(7, $this->billing->resolveQuantity(
            QuantityType::PerWorkstation, $client, overageDivisor: 0,
        ));
    }

    // ── The formula is not workstation-specific ──

    public function test_formula_applies_to_other_dynamic_types(): void
    {
        $client = $this->clientWithAssets(4, 'Server');

        // 4 servers in blocks of 2 → 2.
        $this->assertSame(2, $this->billing->resolveQuantity(
            QuantityType::PerServer, $client, overageDivisor: 2,
        ));
    }

    // ── Fixed is never touched by the formula ──

    public function test_fixed_quantity_ignores_the_formula(): void
    {
        $client = Client::factory()->create();

        $this->assertSame(7, $this->billing->resolveQuantity(
            QuantityType::Fixed, $client,
            fixedQuantity: 7, includedPerBaseUnit: 5, overageDivisor: 2,
        ));
    }

    // ── Overage semantics are preserved by the refactor ──

    public function test_overage_still_measures_usage_against_a_base_license_type(): void
    {
        $client = Client::factory()->create();
        $usageType = $this->licenseType();
        $baseType = $this->licenseType();
        $this->giveLicenses($client, $usageType, 100);
        $this->giveLicenses($client, $baseType, 3);

        // max(0, ceil((100 - 3 × 20) / 10)) = ceil(40 / 10) = 4
        $this->assertSame(4, $this->billing->resolveQuantity(
            QuantityType::Overage, $client,
            usageLicenseTypeId: $usageType->id,
            baseLicenseTypeId: $baseType->id,
            includedPerBaseUnit: 20,
            overageDivisor: 10,
        ));
    }

    public function test_overage_uses_a_base_of_one_when_no_base_license_type(): void
    {
        $client = Client::factory()->create();
        $usageType = $this->licenseType();
        $this->giveLicenses($client, $usageType, 50);

        // base defaults to 1: max(0, 50 - 1 × 10) = 40
        $this->assertSame(40, $this->billing->resolveQuantity(
            QuantityType::Overage, $client,
            usageLicenseTypeId: $usageType->id,
            includedPerBaseUnit: 10,
        ));
    }

    public function test_overage_without_a_usage_license_type_resolves_to_zero(): void
    {
        $client = Client::factory()->create();

        $this->assertSame(0, $this->billing->resolveQuantity(
            QuantityType::Overage, $client,
            usageLicenseTypeId: null,
            includedPerBaseUnit: 5,
        ));
    }

    // ── Audit trail records the formula arithmetic ──

    public function test_quantity_source_records_the_formula_breakdown(): void
    {
        $client = $this->clientWithAssets(12);
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'start_date' => now()->toDateString(),
        ]);
        $profile = RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Monthly',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => now()->addMonth()->toDateString(),
        ]);
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Workstations beyond the first two, in blocks of five',
            'unit_price' => 10,
            'quantity_type' => QuantityType::PerWorkstation->value,
            'included_per_base_unit' => 2,
            'overage_divisor' => 5,
        ]);

        $preview = $this->billing->previewInvoice($profile);
        $line = $preview['lines'][0];

        // ceil((12 - 2) / 5) = 2
        $this->assertSame(2, $line['quantity']);
        $this->assertStringContainsString('12 usage', $line['quantity_source']);
        $this->assertStringContainsString('2 included', $line['quantity_source']);
        $this->assertStringContainsString('10 raw', $line['quantity_source']);
        $this->assertStringContainsString('5 divisor', $line['quantity_source']);
    }

    public function test_quantity_source_omits_the_formula_when_neutral(): void
    {
        $client = $this->clientWithAssets(4);
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'start_date' => now()->toDateString(),
        ]);
        $profile = RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Monthly',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => now()->addMonth()->toDateString(),
        ]);
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Per workstation',
            'unit_price' => 10,
            'quantity_type' => QuantityType::PerWorkstation->value,
        ]);

        $preview = $this->billing->previewInvoice($profile);
        $line = $preview['lines'][0];

        $this->assertSame(4, $line['quantity']);
        $this->assertStringNotContainsString('usage', $line['quantity_source']);
        $this->assertStringContainsString('4 per workstation', $line['quantity_source']);
    }
}
