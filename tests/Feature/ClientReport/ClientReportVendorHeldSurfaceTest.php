<?php

namespace Tests\Feature\ClientReport;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\ClientReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer-report half of the vendor-held surface ruling (2026-08-19):
 * held rows stay VISIBLE — a licence that vanishes from a client's report between
 * months reads as a billing error and generates the ticket the guard exists to
 * prevent — but they are annotated, and the wording on this client-readable surface
 * says ONLY that nothing is being charged and nothing has been cancelled. The
 * vendor's own status word ("Suspended") belongs to the reseller surface; the two
 * surfaces must not share a string.
 */
class ClientReportVendorHeldSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithLicences(): Client
    {
        $client = Client::create(['name' => 'Acme Corp']);

        $type = LicenseType::create([
            'vendor' => 'appriver',
            'vendor_sku_id' => 'business-premium',
            'name' => 'Business Premium',
            'is_active' => true,
        ]);

        License::create([
            'license_type_id' => $type->id,
            'client_id' => $client->id,
            'vendor_ref' => 'sub-held',
            'quantity' => 3,
            'status' => 'active',
            'vendor_status' => 'Suspended',
            'synced_at' => now(),
        ]);

        $normalType = LicenseType::create([
            'vendor' => 'cipp',
            'vendor_sku_id' => 'm365-business-standard',
            'name' => 'M365 Business Standard',
            'is_active' => true,
        ]);

        License::create([
            'license_type_id' => $normalType->id,
            'client_id' => $client->id,
            'vendor_ref' => 'ms-sub',
            'quantity' => 10,
            'status' => 'active',
            'vendor_status' => null,
            'synced_at' => now(),
        ]);

        return $client;
    }

    public function test_held_row_is_visible_and_annotated_with_customer_wording_only(): void
    {
        $client = $this->clientWithLicences();

        $report = app(ClientReportService::class)->weeklyReport($client);
        $markdown = $report['markdown'];

        $this->assertStringContainsString('Business Premium', $markdown, 'A vendor-held licence must stay visible on the customer report.');
        $this->assertStringContainsString('not being charged this period; nothing has been cancelled', $markdown);

        // The vendor's status word is the reseller surface's string, not this one.
        $this->assertStringNotContainsString('vendor reports', $markdown);
        $this->assertStringNotContainsString('Suspended', $markdown);
    }

    public function test_normal_rows_carry_no_charging_annotation(): void
    {
        $client = $this->clientWithLicences();

        $data = app(ClientReportService::class)->gatherData($client, now()->startOfWeek(), now()->endOfWeek());

        $rows = collect($data['licenses']);
        $this->assertTrue($rows->firstWhere('name', 'Business Premium')['vendor_held']);
        $this->assertFalse($rows->firstWhere('name', 'M365 Business Standard')['vendor_held'], 'A NULL vendor_status row is not held.');
    }
}
