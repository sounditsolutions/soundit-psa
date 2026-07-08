<?php

namespace Tests\Feature\Skus;

use App\Enums\QuantityType;
use App\Models\BackupStorageTier;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkuStorageTierTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_renders_storage_tier_editor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('skus.create'))
            ->assertOk()
            ->assertSee('Backup Storage Pricing Tiers')
            ->assertSee('Backup Storage (GB)'); // new quantity-type option
    }

    public function test_edit_page_prefills_existing_tiers(): void
    {
        $user = User::factory()->create();

        $sku = Sku::create([
            'name' => 'Cloud Backup Storage',
            'sku_code' => 'BKP-GB',
            'unit_price' => '0',
            'unit_cost' => '0',
            'default_quantity_type' => QuantityType::PerBackupStorageGb,
            'is_taxable' => true,
            'is_active' => true,
        ]);
        BackupStorageTier::create(['sku_id' => $sku->id, 'up_to_gb' => 100, 'unit_price' => '1.00', 'sort_order' => 0]);

        $this->actingAs($user)->get(route('skus.edit', $sku))
            ->assertOk()
            ->assertSee('Backup Storage Pricing Tiers')
            ->assertSee('name="tiers[0][up_to_gb]"', false)
            ->assertSee('value="100"', false); // prefilled from the stored tier
    }

    public function test_store_persists_backup_storage_tiers_and_skips_empty_rows(): void
    {
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->post(route('skus.store'), [
            'name' => 'Cloud Backup Storage',
            'sku_code' => 'BKP-GB',
            'unit_price' => '0',
            'unit_cost' => '0',
            'default_quantity_type' => QuantityType::PerBackupStorageGb->value,
            'is_taxable' => '1',
            'is_active' => '1',
            'tiers' => [
                ['up_to_gb' => '100', 'unit_price' => '1.00'],
                ['up_to_gb' => '500', 'unit_price' => '0.80'],
                ['up_to_gb' => '', 'unit_price' => '0.60'], // unbounded catch-all
                ['up_to_gb' => '', 'unit_price' => ''],     // empty template row — ignored
            ],
        ]);

        $sku = Sku::where('sku_code', 'BKP-GB')->firstOrFail();
        $resp->assertRedirect(route('skus.edit', $sku));

        $tiers = $sku->backupStorageTiers()->get();
        $this->assertCount(3, $tiers);
        // Rate card order: bounded ascending, unbounded last.
        $this->assertSame(100, $tiers[0]->up_to_gb);
        $this->assertSame(500, $tiers[1]->up_to_gb);
        $this->assertNull($tiers[2]->up_to_gb);
        $this->assertSame('0.60', (string) $tiers[2]->unit_price);
    }

    public function test_update_replaces_existing_tiers(): void
    {
        $user = User::factory()->create();

        $sku = Sku::create([
            'name' => 'Cloud Backup Storage',
            'sku_code' => 'BKP-GB',
            'unit_price' => '0',
            'unit_cost' => '0',
            'default_quantity_type' => QuantityType::PerBackupStorageGb,
            'is_taxable' => true,
            'is_active' => true,
        ]);
        BackupStorageTier::create(['sku_id' => $sku->id, 'up_to_gb' => 100, 'unit_price' => '1.00', 'sort_order' => 0]);
        BackupStorageTier::create(['sku_id' => $sku->id, 'up_to_gb' => null, 'unit_price' => '0.60', 'sort_order' => 1]);

        $this->actingAs($user)->patch(route('skus.update', $sku), [
            'name' => 'Cloud Backup Storage',
            'sku_code' => 'BKP-GB',
            'unit_price' => '0',
            'unit_cost' => '0',
            'default_quantity_type' => QuantityType::PerBackupStorageGb->value,
            'is_taxable' => '1',
            'is_active' => '1',
            'tiers' => [
                ['up_to_gb' => '250', 'unit_price' => '0.90'],
            ],
        ])->assertRedirect();

        $tiers = $sku->fresh()->backupStorageTiers()->get();
        $this->assertCount(1, $tiers);
        $this->assertSame(250, $tiers[0]->up_to_gb);
        $this->assertSame('0.90', (string) $tiers[0]->unit_price);
    }

    public function test_update_with_no_tiers_clears_them(): void
    {
        $user = User::factory()->create();

        $sku = Sku::create([
            'name' => 'Cloud Backup Storage',
            'sku_code' => 'BKP-GB',
            'unit_price' => '0',
            'unit_cost' => '0',
            'is_taxable' => true,
            'is_active' => true,
        ]);
        BackupStorageTier::create(['sku_id' => $sku->id, 'up_to_gb' => 100, 'unit_price' => '1.00', 'sort_order' => 0]);

        $this->actingAs($user)->patch(route('skus.update', $sku), [
            'name' => 'Cloud Backup Storage',
            'sku_code' => 'BKP-GB',
            'unit_price' => '0',
            'unit_cost' => '0',
            'is_taxable' => '1',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame(0, $sku->fresh()->backupStorageTiers()->count());
    }
}
