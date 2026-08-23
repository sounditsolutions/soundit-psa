<?php

namespace Tests\Feature;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reseller-report half of the vendor-held surface ruling (2026-08-19): held
 * rows stay visible with their quantity OUT of every total, and this surface — ours,
 * reseller-facing — may name the vendor's own status word. The customer report may
 * not; the two surfaces deliberately do not share a string.
 */
class ResellerReportVendorHeldTest extends TestCase
{
    use RefreshDatabase;

    public function test_held_seats_are_out_of_totals_and_annotated_with_vendor_wording(): void
    {
        $reseller = Client::factory()->create(['stage' => ClientStage::Active, 'is_active' => true]);
        $child = Client::factory()->create(['stage' => ClientStage::Active, 'is_active' => true, 'reseller_id' => $reseller->id]);

        $type = LicenseType::create([
            'vendor' => 'appriver',
            'vendor_sku_id' => 'business-premium',
            'name' => 'Business Premium',
            'is_active' => true,
        ]);

        License::create([
            'license_type_id' => $type->id, 'client_id' => $child->id, 'vendor_ref' => 'sub-a',
            'quantity' => 7, 'status' => 'active', 'vendor_status' => 'Active', 'synced_at' => now(),
        ]);
        License::create([
            'license_type_id' => $type->id, 'client_id' => $child->id, 'vendor_ref' => 'sub-b',
            'quantity' => 3, 'status' => 'active', 'vendor_status' => 'Suspended', 'synced_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get('/reseller-report?reseller_id='.$reseller->id);

        $response->assertOk();

        $data = $response->viewData('data');

        $this->assertSame(7, (int) $data['grandTotal'], 'Held seats must be out of the grand total.');

        $typeTotal = $data['typeTotals']->firstWhere('license_type_name', 'Business Premium');
        $this->assertSame(7, (int) $typeTotal->total_quantity, 'Held seats must be out of the per-type total.');
        $this->assertSame(3, (int) $typeTotal->held_quantity, 'Held seats must stay visible — annotated, not dropped.');

        $breakdownRow = $data['clientBreakdown']->get($typeTotal->license_type_id)->first();
        $this->assertSame(7, (int) $breakdownRow->quantity);
        $this->assertSame(3, (int) $breakdownRow->held_quantity);

        // This surface names the vendor's status word; the customer report must not.
        $response->assertSee('vendor reports', false);
    }
}
