<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingPeriod;
use App\Enums\BillingSource;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\QuantityType;
use App\Models\Asset;
use App\Models\BackupStorageTier;
use App\Models\Client;
use App\Models\Contract;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\Sku;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupStorageBillingTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824; // 1024^3 bytes

    private function billing(): BillingService
    {
        return app(BillingService::class);
    }

    private function contractFor(Client $client): Contract
    {
        return Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => ContractType::Managed,
            'status' => ContractStatus::Active,
            'billing_source' => BillingSource::Psa,
            'billing_period' => BillingPeriod::Monthly,
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'start_date' => now()->subMonth(),
        ]);
    }

    // ── Quantity resolution (bytes → GB) ──

    public function test_resolves_client_wide_backup_storage_in_gb(): void
    {
        $client = Client::factory()->create();
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 200 * self::GB]);
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 100 * self::GB]);
        // An asset with no backup usage must not affect the total.
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => null]);

        $qty = $this->billing()->resolveQuantity(QuantityType::PerBackupStorageGb, $client);

        $this->assertSame(300, $qty);
    }

    public function test_inactive_and_soft_deleted_assets_are_excluded(): void
    {
        $client = Client::factory()->create();
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 50 * self::GB]);
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 999 * self::GB, 'is_active' => false]);
        $deleted = Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 999 * self::GB]);
        $deleted->delete();

        $qty = $this->billing()->resolveQuantity(QuantityType::PerBackupStorageGb, $client);

        $this->assertSame(50, $qty);
    }

    public function test_resolves_contract_scoped_backup_storage_when_assets_assigned(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contractFor($client);

        $assigned = Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 200 * self::GB]);
        // Unassigned client asset — excluded once the contract has assignments.
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 100 * self::GB]);

        $contract->assets()->attach($assigned->id);

        $qty = $this->billing()->resolveQuantity(
            QuantityType::PerBackupStorageGb, $client, null, $contract,
        );

        $this->assertSame(200, $qty);
    }

    // ── Tiered pricing through previewInvoice ──

    private function tieredProfile(Client $client, Sku $sku, string $fallbackPrice = '9.99'): RecurringInvoiceProfile
    {
        $contract = $this->contractFor($client);

        $profile = RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Backup billing',
            'is_active' => true,
            'billing_period' => BillingPeriod::Monthly,
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => today(),
        ]);

        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'sku_id' => $sku->id,
            'description' => 'Cloud backup storage',
            'unit_price' => $fallbackPrice,
            'quantity_type' => QuantityType::PerBackupStorageGb,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        return $profile;
    }

    private function backupSku(array $tiers = []): Sku
    {
        $sku = Sku::create([
            'name' => 'Cloud Backup Storage',
            'sku_code' => 'BKP-GB-'.fake()->unique()->numerify('###'),
            'unit_price' => '0.00',
            'unit_cost' => '0.00',
            'default_quantity_type' => QuantityType::PerBackupStorageGb,
            'is_taxable' => true,
            'is_active' => true,
        ]);

        foreach ($tiers as $i => $tier) {
            BackupStorageTier::create([
                'sku_id' => $sku->id,
                'up_to_gb' => $tier['up_to_gb'],
                'unit_price' => $tier['unit_price'],
                'sort_order' => $i,
            ]);
        }

        return $sku;
    }

    public function test_preview_prices_backup_line_at_the_matching_tier_rate(): void
    {
        $client = Client::factory()->create();
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 200 * self::GB]);
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 100 * self::GB]);

        $sku = $this->backupSku([
            ['up_to_gb' => 100, 'unit_price' => '1.00'],
            ['up_to_gb' => 500, 'unit_price' => '0.80'],
            ['up_to_gb' => null, 'unit_price' => '0.60'],
        ]);

        $preview = $this->billing()->previewInvoice($this->tieredProfile($client, $sku));

        $line = $preview['lines'][0];
        $this->assertSame(300, $line['quantity']);           // 300 GB total
        $this->assertSame(0.80, (float) $line['unit_price']); // middle tier rate
        $this->assertSame(240.00, (float) $line['amount']);   // 300 × 0.80
        $this->assertStringContainsString('300 GB backup storage', $line['quantity_source']);
        $this->assertStringContainsString('tier rate $0.80/GB', $line['quantity_source']);
    }

    public function test_preview_falls_back_to_flat_price_when_sku_has_no_tiers(): void
    {
        $client = Client::factory()->create();
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 300 * self::GB]);

        $sku = $this->backupSku(); // no tiers

        $preview = $this->billing()->previewInvoice($this->tieredProfile($client, $sku, '2.50'));

        $line = $preview['lines'][0];
        $this->assertSame(300, $line['quantity']);
        $this->assertSame(2.50, (float) $line['unit_price']); // flat line price
        $this->assertSame(750.00, (float) $line['amount']);   // 300 × 2.50
    }
}
