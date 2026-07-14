<?php

namespace Tests\Feature\Cipp;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippLicenseSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * CippLicenseSyncService against CIPP's REAL ListLicenses shape (psa-d6mf).
 *
 * The fixture is CIPP's actual Get-CIPPLicenseOverview row, verified against
 * CIPP-API source (the same audit that produced the relay's cipp_list_licenses
 * projection, psa-zw1j): hand-built rows — NOT raw Graph subscribedSkus — whose
 * seat counts are the STRINGS License / CountUsed / CountAvailable / TotalLicenses.
 * consumedUnits is the raw Graph key CIPP consumes internally; it is NEVER emitted
 * at this layer.
 *
 * The service read $licenseData['consumedUnits'] ?? ['ConsumedUnits'] ?? 0, so the
 * used-seat count resolved to 0 for every row and assigned_quantity was never
 * populated — hiding license waste — while the license fell back to recording the
 * TOTAL in quantity. The fix reads the REAL key, CountUsed, and records the
 * vendor-agnostic split the other license syncs use (e.g. AppRiver:
 * quantity=TotalLicenses, assigned_quantity=AssignedLicenses): quantity = the
 * purchased/entitled seats BillingService bills PerLicense, assigned_quantity = the
 * used seats that drive the utilization/waste UI (psa-d6mf product review: recording
 * used in quantity under-bills a reseller and hides waste). A mock authored from the
 * code's wished-for shape would have hidden the dead key; this fixture is copied from
 * what CIPP actually emits, so it fails loudly until the real key is read (CLAUDE.md,
 * the psa-7lgo lesson).
 */
class CippLicenseSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One license row in CIPP's REAL Get-CIPPLicenseOverview shape — counts as
     * STRINGS, display name in `License`, skuPartNumber duplicating the pretty name.
     */
    private function realLicenseOverviewRow(array $overrides = []): array
    {
        return array_merge([
            'Tenant' => 'acme.example',
            'License' => 'Microsoft 365 Business Premium',
            'CountUsed' => '18',
            'CountAvailable' => '2',
            'TotalLicenses' => '20',
            'skuId' => 'cbdc14ab-d96c-4c30-b9f4-6ada7cdc1d46',
            'skuPartNumber' => 'Microsoft 365 Business Premium',
        ], $overrides);
    }

    private function syncWith(array $rows): Client
    {
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);

        $cipp = Mockery::mock(CippClient::class);
        $cipp->shouldReceive('listLicenses')
            ->with('acme.example')
            ->andReturn($rows);
        $this->app->instance(CippClient::class, $cipp);

        app(CippLicenseSyncService::class)->syncLicenses();

        return $client;
    }

    public function test_quantity_is_the_purchased_total_licenses_count(): void
    {
        $client = $this->syncWith([$this->realLicenseOverviewRow()]);

        $license = License::where('client_id', $client->id)->firstOrFail();

        // quantity is the purchased/entitled seat count (TotalLicenses = 20), the
        // billable denominator — NOT the used count. Recording used here would
        // under-bill a reseller and hide waste (psa-d6mf product review).
        $this->assertSame(20, $license->quantity, 'purchased seat count read from TotalLicenses');
    }

    public function test_assigned_quantity_is_read_from_the_real_count_used_key(): void
    {
        $client = $this->syncWith([$this->realLicenseOverviewRow()]);

        $license = License::where('client_id', $client->id)->firstOrFail();

        // The used count lands in assigned_quantity, read from the REAL CIPP key
        // (CountUsed = 18) — not 0 (the dead consumedUnits read) and not the total.
        // This drives utilization_percent / waste. It would be 0 if the service
        // still read the non-emitted consumedUnits key.
        $this->assertSame(18, $license->assigned_quantity, 'used seat count read from the real CountUsed key');
    }

    public function test_license_type_takes_its_name_from_the_real_license_key(): void
    {
        $client = $this->syncWith([$this->realLicenseOverviewRow()]);

        $license = License::where('client_id', $client->id)->firstOrFail();
        $type = LicenseType::findOrFail($license->license_type_id);

        $this->assertSame('cipp_m365', $type->vendor);
        $this->assertSame('Microsoft 365 Business Premium', $type->name);
    }

    public function test_zero_used_records_full_quantity_and_zero_assigned(): void
    {
        // A license type owned but entirely unused reports CountUsed "0": the full
        // purchased count still bills (quantity = 20) and the waste signal is
        // explicit (assigned_quantity = 0), not hidden.
        $client = $this->syncWith([$this->realLicenseOverviewRow(['CountUsed' => '0'])]);

        $license = License::where('client_id', $client->id)->firstOrFail();
        $this->assertSame(20, $license->quantity);
        $this->assertSame(0, $license->assigned_quantity);
    }
}
