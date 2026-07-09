<?php

namespace Tests\Feature\Billing;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\QuantityType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\CustomQuantityType;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomQuantityTypeBillingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BillingService
    {
        return app(BillingService::class);
    }

    private function makeContract(int $clientId): Contract
    {
        return Contract::create([
            'client_id' => $clientId,
            'name' => 'Managed Services Agreement',
            'type' => ContractType::Managed->value,
            'status' => ContractStatus::Active->value,
            'start_date' => now()->subYear(),
        ]);
    }

    // ── resolveQuantity() ──

    public function test_custom_type_counts_active_assets_of_matching_type_client_wide(): void
    {
        $client = Client::factory()->create();
        Asset::factory()->count(3)->create(['client_id' => $client->id, 'asset_type' => 'Firewall']);
        Asset::factory()->count(2)->create(['client_id' => $client->id, 'asset_type' => 'Workstation']);

        $qty = $this->service()->resolveQuantity(
            QuantityType::Custom, $client, customAssetTypes: ['Firewall'],
        );

        $this->assertSame(3, $qty);
    }

    public function test_custom_type_counts_across_multiple_asset_types(): void
    {
        $client = Client::factory()->create();
        Asset::factory()->count(2)->create(['client_id' => $client->id, 'asset_type' => 'Firewall']);
        Asset::factory()->count(4)->create(['client_id' => $client->id, 'asset_type' => 'Switch']);
        Asset::factory()->count(1)->create(['client_id' => $client->id, 'asset_type' => 'Printer']);

        $qty = $this->service()->resolveQuantity(
            QuantityType::Custom, $client, customAssetTypes: ['Firewall', 'Switch'],
        );

        $this->assertSame(6, $qty);
    }

    public function test_custom_type_excludes_inactive_assets(): void
    {
        $client = Client::factory()->create();
        Asset::factory()->count(2)->create(['client_id' => $client->id, 'asset_type' => 'Firewall', 'is_active' => true]);
        Asset::factory()->count(3)->create(['client_id' => $client->id, 'asset_type' => 'Firewall', 'is_active' => false]);

        $qty = $this->service()->resolveQuantity(
            QuantityType::Custom, $client, customAssetTypes: ['Firewall'],
        );

        $this->assertSame(2, $qty);
    }

    public function test_custom_type_with_no_asset_types_resolves_to_zero(): void
    {
        $client = Client::factory()->create();
        Asset::factory()->count(3)->create(['client_id' => $client->id, 'asset_type' => 'Firewall']);

        $this->assertSame(0, $this->service()->resolveQuantity(
            QuantityType::Custom, $client, customAssetTypes: [],
        ));
        $this->assertSame(0, $this->service()->resolveQuantity(
            QuantityType::Custom, $client, customAssetTypes: null,
        ));
    }

    public function test_custom_type_is_contract_scoped_when_contract_has_asset_assignments(): void
    {
        $client = Client::factory()->create();
        $contract = $this->makeContract($client->id);

        $fw1 = Asset::factory()->create(['client_id' => $client->id, 'asset_type' => 'Firewall']);
        $fw2 = Asset::factory()->create(['client_id' => $client->id, 'asset_type' => 'Firewall']);
        // A third firewall on the client but NOT assigned to the contract.
        Asset::factory()->create(['client_id' => $client->id, 'asset_type' => 'Firewall']);

        $contract->assets()->attach($fw1->id, ['assigned_at' => now(), 'assignment_source' => 'manual']);
        $contract->assets()->attach($fw2->id, ['assigned_at' => now(), 'assignment_source' => 'manual']);

        $qty = $this->service()->resolveQuantity(
            QuantityType::Custom, $client, contract: $contract, customAssetTypes: ['Firewall'],
        );

        // Contract has assignments → count is scoped to the 2 assigned firewalls.
        $this->assertSame(2, $qty);
    }

    // ── generateInvoice() end-to-end ──

    public function test_generate_invoice_uses_custom_type_quantity_and_records_source(): void
    {
        $client = Client::factory()->create();
        $contract = $this->makeContract($client->id);
        Asset::factory()->count(3)->create(['client_id' => $client->id, 'asset_type' => 'Firewall']);

        $custom = CustomQuantityType::create([
            'name' => 'Per Firewall',
            'asset_types' => ['Firewall'],
            'is_active' => true,
        ]);

        $profile = RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Monthly Managed Services',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => today(),
        ]);

        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Firewall management',
            'unit_price' => 25,
            'quantity_type' => QuantityType::Custom->value,
            'custom_quantity_type_id' => $custom->id,
            'fixed_quantity' => 1,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $result = $this->service()->generateInvoice($profile);

        $this->assertSame('created', $result['status']);
        $line = $result['invoice']->lines->first();

        // quantity/amount are decimal-cast columns → compare loosely.
        $this->assertEquals(3, $line->quantity);
        $this->assertEquals(75.00, $line->amount);
        $this->assertStringContainsString('Per Firewall', $line->quantity_source);
        $this->assertStringContainsString('Firewall', $line->quantity_source);
    }
}
