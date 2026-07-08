<?php

namespace Tests\Feature\Sku;

use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The staff SKU form gained portal-catalog fields (portal_orderable +
 * portal_description) so operators can publish products to the client portal
 * shop. Locks in that they validate, coerce, and persist through create/update.
 */
class SkuPortalFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Portal Laptop',
            'sku_code' => 'PL-1',
            'unit_price' => '999.00',
            'unit_cost' => '750.00',
            'is_taxable' => '1',
            'is_active' => '1',
        ], $overrides);
    }

    public function test_store_persists_portal_catalog_fields(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->post(route('skus.store'), $this->payload([
            'portal_orderable' => '1',
            'portal_description' => 'A fast laptop for your team.',
        ]))->assertRedirect();

        $sku = Sku::where('sku_code', 'PL-1')->firstOrFail();
        $this->assertTrue($sku->portal_orderable);
        $this->assertSame('A fast laptop for your team.', $sku->portal_description);
    }

    public function test_store_defaults_portal_orderable_off(): void
    {
        $staff = User::factory()->create();

        // No portal_orderable field submitted → coerces to false.
        $this->actingAs($staff)->post(route('skus.store'), $this->payload([
            'sku_code' => 'PL-2',
        ]))->assertRedirect();

        $sku = Sku::where('sku_code', 'PL-2')->firstOrFail();
        $this->assertFalse($sku->portal_orderable);
    }

    public function test_update_toggles_portal_orderable(): void
    {
        $staff = User::factory()->create();
        $sku = Sku::create([
            'name' => 'Widget',
            'sku_code' => 'W-1',
            'unit_price' => 10,
            'unit_cost' => 5,
            'is_taxable' => true,
            'is_active' => true,
            'portal_orderable' => true,
        ]);

        // Update without portal_orderable → hidden input posts 0 → turns off.
        $this->actingAs($staff)->patch(route('skus.update', $sku), $this->payload([
            'name' => 'Widget',
            'sku_code' => 'W-1',
            'unit_price' => '10.00',
            'unit_cost' => '5.00',
            'portal_orderable' => '0',
        ]))->assertRedirect();

        $this->assertFalse($sku->fresh()->portal_orderable);
    }
}
