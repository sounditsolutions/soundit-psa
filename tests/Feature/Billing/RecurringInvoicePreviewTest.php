<?php

namespace Tests\Feature\Billing;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\Sku;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for psa-sg3l: a managed-services recurring profile must
 * preview a nonzero invoice with meaningful (non-blank) line descriptions when
 * its quantities resolve above zero. The demo seeder previously created profile
 * lines with blank descriptions and $0.00 unit prices, so "Preview Next Invoice"
 * rendered $0.00 across seven blank rows even though the same contract's
 * historical invoices billed in full.
 *
 * BillingService faithfully uses each line's own description/unit_price (there
 * is intentionally no SKU price fallback — a $0-priced line is a valid "record
 * of coverage"), so the invariant under test is that a properly-configured
 * profile computes real amounts.
 */
class RecurringInvoicePreviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeSku(string $code, string $name, float $price, float $cost, string $qtyType): Sku
    {
        return Sku::create([
            'sku_code' => $code,
            'name' => $name,
            'description' => '',
            'category' => 'managed_services',
            'unit_price' => $price,
            'unit_cost' => $cost,
            'default_quantity_type' => $qtyType,
            'is_taxable' => true,
            'is_active' => true,
        ]);
    }

    private function makeLicenseType(string $name, ?Sku $sku = null): LicenseType
    {
        return LicenseType::create([
            'name' => $name,
            'vendor' => 'test',
            'vendor_sku_id' => strtolower(str_replace(' ', '-', $name)),
            'sku_id' => $sku?->id,
            'default_unit_cost' => 0,
            'is_active' => true,
        ]);
    }

    private function makeLicense(Client $client, LicenseType $type, int $qty): License
    {
        return License::create([
            'license_type_id' => $type->id,
            'client_id' => $client->id,
            'quantity' => $qty,
            'status' => 'active',
            'synced_at' => now()->subHours(6),
        ]);
    }

    /**
     * Build a Vandelay-style managed profile in miniature, exercising every
     * quantity type the demo profile uses: per_workstation, per_server,
     * per_license_type, and overage. Returns the profile.
     */
    private function seedManagedProfile(Client $client, Contract $contract): RecurringInvoiceProfile
    {
        $msUser = $this->makeSku('MS-USER', 'Managed Workstation', 95.00, 32.00, 'per_workstation');
        $msServer = $this->makeSku('MS-SERVER', 'Managed Server', 250.00, 75.00, 'per_server');
        $m365 = $this->makeSku('M365-BP', 'Microsoft 365 Business Premium', 26.50, 22.00, 'per_license_type');
        $bkpOverage = $this->makeSku('BKP-GB-OVERAGE', 'Cloud Backup Overage (per GB)', 0.18, 0.07, 'overage');
        $bkpWsSku = $this->makeSku('BKP-WS', 'Cloud Backup - Workstation', 12.00, 4.50, 'per_license_type');

        $m365Type = $this->makeLicenseType('M365 Business Premium', $m365);
        $bkpWsType = $this->makeLicenseType('Backup Workstation', $bkpWsSku);
        $bkpUsageType = $this->makeLicenseType('Cloud Storage (GB)', $bkpOverage);

        // Client-wide license counts (contract has no license assignments, so
        // BillingService falls back to client-wide — mirrors the demo).
        $this->makeLicense($client, $m365Type, 22);
        $this->makeLicense($client, $bkpWsType, 18);
        $this->makeLicense($client, $bkpUsageType, 1800);

        // 3 active workstations, 2 active servers.
        Asset::factory()->count(3)->create(['client_id' => $client->id, 'asset_type' => 'Workstation', 'is_active' => true]);
        Asset::factory()->count(2)->create(['client_id' => $client->id, 'asset_type' => 'Server', 'is_active' => true]);

        $profile = RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Monthly Managed',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 15,
            'next_run_date' => now()->addDays(2)->toDateString(),
        ]);

        // Lines mirror the fixed seeder: description + price come from the SKU.
        $lines = [
            ['sku' => $msUser, 'qty_type' => 'per_workstation'],
            ['sku' => $msServer, 'qty_type' => 'per_server'],
            ['sku' => $m365, 'qty_type' => 'per_license_type', 'license_type_id' => $m365Type->id],
            ['sku' => $bkpOverage, 'qty_type' => 'overage', 'usage' => $bkpUsageType->id, 'base' => $bkpWsType->id],
        ];

        foreach ($lines as $i => $l) {
            /** @var Sku $sku */
            $sku = $l['sku'];
            RecurringInvoiceProfileLine::create([
                'profile_id' => $profile->id,
                'sku_id' => $sku->id,
                'description' => $sku->name,
                'unit_price' => $sku->unit_price,
                'unit_cost_override' => $sku->unit_cost,
                'license_type_id' => $l['license_type_id'] ?? null,
                'usage_license_type_id' => $l['usage'] ?? null,
                'base_license_type_id' => $l['base'] ?? null,
                'included_per_base_unit' => isset($l['base']) ? 100 : null,
                'overage_divisor' => isset($l['base']) ? 1 : null,
                'quantity_type' => $l['qty_type'],
                'fixed_quantity' => 0,
                'is_taxable' => true,
                'sort_order' => $i,
            ]);
        }

        return $profile->fresh(['lines']);
    }

    private function makeContract(Client $client): Contract
    {
        return Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services Agreement',
            'type' => 'managed',
            'status' => 'active',
            'billing_source' => 'psa',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 15,
            'start_date' => now()->subYear()->toDateString(),
        ]);
    }

    public function test_managed_profile_previews_nonzero_invoice_with_meaningful_descriptions(): void
    {
        $client = Client::factory()->create();
        $contract = $this->makeContract($client);
        $profile = $this->seedManagedProfile($client, $contract);

        $preview = app(BillingService::class)->previewInvoice($profile);

        // Core regression: the invoice is NOT $0.00.
        // 3×95 (workstations) + 2×250 (servers) + 22×26.50 (M365) + 0×0.18 (overage)
        $this->assertEqualsWithDelta(1368.00, $preview['subtotal'], 0.001);
        $this->assertGreaterThan(0, $preview['subtotal']);
        $this->assertFalse($preview['would_skip']);

        // Every line carries a meaningful, non-blank description (the exact
        // field the bug left empty).
        $this->assertCount(4, $preview['lines']);
        foreach ($preview['lines'] as $line) {
            $this->assertNotSame('', $line['description']);
            $this->assertNotNull($line['description']);
        }

        // Spot-check each resolved line.
        [$ws, $srv, $m365, $overage] = $preview['lines'];

        $this->assertSame('Managed Workstation', $ws['description']);
        $this->assertSame(95.00, $ws['unit_price']);
        $this->assertSame(3, $ws['quantity']);
        $this->assertEqualsWithDelta(285.00, $ws['amount'], 0.001);

        $this->assertSame('Managed Server', $srv['description']);
        $this->assertSame(250.00, $srv['unit_price']);
        $this->assertSame(2, $srv['quantity']);
        $this->assertEqualsWithDelta(500.00, $srv['amount'], 0.001);

        $this->assertSame('Microsoft 365 Business Premium', $m365['description']);
        $this->assertSame(26.50, $m365['unit_price']);
        $this->assertSame(22, $m365['quantity']);
        $this->assertEqualsWithDelta(583.00, $m365['amount'], 0.001);

        // Overage resolves to 0 (1800 usage − 18×100 included), but remains a
        // valid, meaningfully-described line rather than blank noise.
        $this->assertSame('Cloud Backup Overage (per GB)', $overage['description']);
        $this->assertSame(0, $overage['quantity']);
        $this->assertEqualsWithDelta(0.00, $overage['amount'], 0.001);
    }

    public function test_preview_endpoint_returns_nonzero_managed_invoice(): void
    {
        $client = Client::factory()->create();
        $contract = $this->makeContract($client);
        $profile = $this->seedManagedProfile($client, $contract);
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->getJson(route('profiles.preview', $profile));

        $resp->assertOk();
        $resp->assertJsonPath('would_skip', false);
        $this->assertEqualsWithDelta(1368.00, $resp->json('subtotal'), 0.001);
        $this->assertNotSame('', $resp->json('lines.0.description'));
    }
}
