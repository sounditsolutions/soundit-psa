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
 * consumed count resolved to 0 for every row and the license fell back to syncing
 * the TOTAL seat count instead of the used one — the tool's own comment says
 * "quantity is consumed units", but it was quietly recording the total. A mock
 * authored from the code's wished-for shape would have hidden this; this fixture
 * is copied from what CIPP actually emits, so it fails loudly until the real key
 * is read (CLAUDE.md, the psa-7lgo lesson).
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

    public function test_consumed_units_are_read_from_the_real_count_used_key(): void
    {
        $client = $this->syncWith([$this->realLicenseOverviewRow()]);

        $license = License::where('client_id', $client->id)->firstOrFail();

        // The consumed count is CountUsed (18), not 0 and not the total (20).
        $this->assertSame(18, $license->quantity, 'consumed seat count read from the real CIPP key');
    }

    public function test_license_type_takes_its_name_from_the_real_license_key(): void
    {
        $client = $this->syncWith([$this->realLicenseOverviewRow()]);

        $license = License::where('client_id', $client->id)->firstOrFail();
        $type = LicenseType::findOrFail($license->license_type_id);

        $this->assertSame('cipp_m365', $type->vendor);
        $this->assertSame('Microsoft 365 Business Premium', $type->name);
    }

    public function test_falls_back_to_total_when_no_seats_are_consumed(): void
    {
        // A license type owned but entirely unused reports CountUsed "0"; showing
        // the total it is entitled to is more useful to the agent than a bare 0.
        $client = $this->syncWith([$this->realLicenseOverviewRow(['CountUsed' => '0'])]);

        $license = License::where('client_id', $client->id)->firstOrFail();
        $this->assertSame(20, $license->quantity);
    }
}
