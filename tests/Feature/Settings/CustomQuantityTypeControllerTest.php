<?php

namespace Tests\Feature\Settings;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\QuantityType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\CustomQuantityType;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomQuantityTypeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    // ── CRUD ──

    public function test_index_lists_existing_custom_types(): void
    {
        $type = CustomQuantityType::create([
            'name' => 'Per Firewall',
            'asset_types' => ['Firewall'],
            'is_active' => true,
        ]);

        $resp = $this->actingAs($this->user())->get(route('settings.quantity-types.index'));

        $resp->assertOk();
        $resp->assertSee('Per Firewall');
    }

    public function test_store_creates_custom_type(): void
    {
        $resp = $this->actingAs($this->user())->post(route('settings.quantity-types.store'), [
            'name' => 'Per Switch',
            'description' => 'Network switches',
            'asset_types' => ['Switch', 'Router'],
            'is_active' => '1',
        ]);

        $resp->assertRedirect(route('settings.quantity-types.index'));
        $this->assertDatabaseHas('custom_quantity_types', [
            'name' => 'Per Switch',
            'description' => 'Network switches',
            'is_active' => true,
        ]);
        $type = CustomQuantityType::where('name', 'Per Switch')->firstOrFail();
        $this->assertSame(['Switch', 'Router'], $type->asset_types);
    }

    public function test_store_requires_name_and_asset_types(): void
    {
        $resp = $this->actingAs($this->user())->post(route('settings.quantity-types.store'), [
            'name' => '',
            'asset_types' => [],
        ]);

        $resp->assertSessionHasErrors(['name', 'asset_types']);
        $this->assertDatabaseCount('custom_quantity_types', 0);
    }

    public function test_store_enforces_unique_name(): void
    {
        CustomQuantityType::create(['name' => 'Per Firewall', 'asset_types' => ['Firewall'], 'is_active' => true]);

        $resp = $this->actingAs($this->user())->post(route('settings.quantity-types.store'), [
            'name' => 'Per Firewall',
            'asset_types' => ['Switch'],
        ]);

        $resp->assertSessionHasErrors('name');
        $this->assertDatabaseCount('custom_quantity_types', 1);
    }

    public function test_update_modifies_custom_type(): void
    {
        $type = CustomQuantityType::create(['name' => 'Per Firewall', 'asset_types' => ['Firewall'], 'is_active' => true]);

        $resp = $this->actingAs($this->user())->patch(route('settings.quantity-types.update', $type), [
            'name' => 'Per Edge Device',
            'asset_types' => ['Firewall', 'Router'],
            'is_active' => '0',
        ]);

        $resp->assertRedirect(route('settings.quantity-types.index'));
        $type->refresh();
        $this->assertSame('Per Edge Device', $type->name);
        $this->assertSame(['Firewall', 'Router'], $type->asset_types);
        $this->assertFalse($type->is_active);
    }

    public function test_destroy_deletes_unused_type(): void
    {
        $type = CustomQuantityType::create(['name' => 'Per Printer', 'asset_types' => ['Printer'], 'is_active' => true]);

        $resp = $this->actingAs($this->user())->delete(route('settings.quantity-types.destroy', $type));

        $resp->assertRedirect(route('settings.quantity-types.index'));
        $this->assertDatabaseMissing('custom_quantity_types', ['id' => $type->id]);
    }

    public function test_destroy_is_blocked_when_type_is_referenced_by_a_profile_line(): void
    {
        $type = CustomQuantityType::create(['name' => 'Per Firewall', 'asset_types' => ['Firewall'], 'is_active' => true]);
        $this->makeProfileLineUsing($type);

        $resp = $this->actingAs($this->user())->delete(route('settings.quantity-types.destroy', $type));

        $resp->assertRedirect(route('settings.quantity-types.index'));
        $resp->assertSessionHas('error');
        // Still present — protected by the in-use guard.
        $this->assertDatabaseHas('custom_quantity_types', ['id' => $type->id]);
    }

    public function test_edit_page_renders(): void
    {
        $type = CustomQuantityType::create(['name' => 'Per Firewall', 'asset_types' => ['Firewall'], 'is_active' => true]);

        $resp = $this->actingAs($this->user())->get(route('settings.quantity-types.edit', $type));

        $resp->assertOk();
        $resp->assertSee('Per Firewall');
    }

    // ── Profile form rendering (custom-type selector wiring) ──

    public function test_profile_create_page_renders_custom_type_option(): void
    {
        $client = Client::factory()->create();
        $contract = $this->makeContract($client->id);
        CustomQuantityType::create(['name' => 'Per Firewall', 'asset_types' => ['Firewall'], 'is_active' => true]);

        $resp = $this->actingAs($this->user())->get(route('profiles.create', $contract));

        $resp->assertOk();
        $resp->assertSee('Custom (asset type)');
        $resp->assertSee('Per Firewall');
    }

    public function test_profile_show_page_renders_with_custom_line(): void
    {
        $type = CustomQuantityType::create(['name' => 'Per Firewall', 'asset_types' => ['Firewall'], 'is_active' => true]);
        $line = $this->makeProfileLineUsing($type);

        $resp = $this->actingAs($this->user())->get(route('profiles.show', $line->profile_id));

        $resp->assertOk();
        $resp->assertSee('Per Firewall');
    }

    // ── Profile line persistence / validation ──

    public function test_profile_store_persists_custom_quantity_type_id(): void
    {
        $client = Client::factory()->create();
        $contract = $this->makeContract($client->id);
        $type = CustomQuantityType::create(['name' => 'Per Firewall', 'asset_types' => ['Firewall'], 'is_active' => true]);

        $resp = $this->actingAs($this->user())->post(route('profiles.store', $contract), [
            'name' => 'Monthly',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => today()->toDateString(),
            'lines' => [[
                'description' => 'Firewall management',
                'unit_price' => 25,
                'quantity_type' => 'custom',
                'custom_quantity_type_id' => $type->id,
                'is_taxable' => 1,
            ]],
        ]);

        $resp->assertRedirect();
        $this->assertDatabaseHas('recurring_invoice_profile_lines', [
            'quantity_type' => 'custom',
            'custom_quantity_type_id' => $type->id,
        ]);
    }

    public function test_profile_store_requires_custom_type_id_when_quantity_type_is_custom(): void
    {
        $client = Client::factory()->create();
        $contract = $this->makeContract($client->id);

        $resp = $this->actingAs($this->user())->post(route('profiles.store', $contract), [
            'name' => 'Monthly',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => today()->toDateString(),
            'lines' => [[
                'description' => 'Firewall management',
                'unit_price' => 25,
                'quantity_type' => 'custom',
                // custom_quantity_type_id intentionally omitted
                'is_taxable' => 1,
            ]],
        ]);

        $resp->assertSessionHasErrors('lines.0.custom_quantity_type_id');
    }

    // ── Helpers ──

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

    private function makeProfileLineUsing(CustomQuantityType $type): RecurringInvoiceProfileLine
    {
        $client = Client::factory()->create();
        $contract = $this->makeContract($client->id);

        $profile = RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Monthly',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => today(),
        ]);

        return RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Firewall management',
            'unit_price' => 25,
            'quantity_type' => QuantityType::Custom->value,
            'custom_quantity_type_id' => $type->id,
            'fixed_quantity' => 1,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);
    }
}
