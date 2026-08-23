<?php

namespace Tests\Feature\AppRiver;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverLicenseSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `licenses.vendor_status` is written on EVERY observation — the reported status on
 * the inconclusive hold-out path, the Active/Trial value on the create/update path.
 *
 * The column is the instrument that survives log level (ruling 2026-08-19): the
 * hold-out path's log lines are info-level and prod has dropped them before, but the
 * billing guard keys on this persisted value, so each write here is load-bearing for
 * VendorStatusBillingGuardTest, not bookkeeping.
 */
class AppRiverVendorStatusWriteTest extends TestCase
{
    use RefreshDatabase;

    private function mappedClientWithLicence(?string $vendorStatus = null): array
    {
        $client = Client::factory()->create([
            'appriver_customer_id' => 'cust-1',
            'stage' => ClientStage::Active,
            'is_active' => true,
        ]);

        $type = LicenseType::create([
            'vendor' => 'appriver',
            'vendor_sku_id' => 'business-premium',
            'name' => 'Business Premium',
            'is_active' => true,
        ]);

        $licence = License::create([
            'license_type_id' => $type->id,
            'client_id' => $client->id,
            'vendor_ref' => 'sub-1',
            'quantity' => 4,
            'assigned_quantity' => 4,
            'status' => 'active',
            'vendor_status' => $vendorStatus,
            'synced_at' => now()->subDay(),
        ]);

        return [$client, $licence];
    }

    private function vendorReporting(string $status, ?array $detail = null): AppRiverClient
    {
        $mock = $this->createMock(AppRiverClient::class);

        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => $status,
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);

        if ($detail !== null) {
            $mock->method('getSubscriptionDetail')->willReturn($detail);
        }

        return $mock;
    }

    private function activeDetail(int $total, int $assigned): array
    {
        return [
            'ReadonlySubscriptionDetails' => [
                ['Name' => 'TotalLicenses', 'Value' => (string) $total],
                ['Name' => 'AssignedLicenses', 'Value' => (string) $assigned],
            ],
        ];
    }

    public function test_hold_out_path_records_the_reported_suspended_status(): void
    {
        [, $licence] = $this->mappedClientWithLicence();

        (new AppRiverLicenseSyncService($this->vendorReporting('Suspended')))->syncLicenses();

        $licence->refresh();

        $this->assertSame('Suspended', $licence->vendor_status, 'The hold-out path must persist the vendor-reported status — it is what the billing guard keys on.');
        $this->assertSame(4, $licence->quantity, 'Recording the observation must not disturb the held row\'s data.');
        $this->assertSame('active', $licence->status);
    }

    public function test_hold_out_path_records_the_reported_pending_status(): void
    {
        [, $licence] = $this->mappedClientWithLicence();

        (new AppRiverLicenseSyncService($this->vendorReporting('Pending')))->syncLicenses();

        $this->assertSame('Pending', $licence->fresh()->vendor_status);
    }

    public function test_active_observation_writes_vendor_status_on_the_update_path(): void
    {
        [, $licence] = $this->mappedClientWithLicence();

        (new AppRiverLicenseSyncService(
            $this->vendorReporting('Active', $this->activeDetail(5, 3)),
        ))->syncLicenses();

        $licence->refresh();

        $this->assertSame('Active', $licence->vendor_status);
        $this->assertSame(5, $licence->quantity);
    }

    /**
     * The release path: a subscription the vendor suspended and later restored must
     * come back billable with no operator action — the same write that refreshes the
     * seat count overwrites the held value.
     */
    public function test_a_restored_subscription_overwrites_the_held_status(): void
    {
        [, $licence] = $this->mappedClientWithLicence('Suspended');

        (new AppRiverLicenseSyncService(
            $this->vendorReporting('Active', $this->activeDetail(4, 4)),
        ))->syncLicenses();

        $this->assertSame('Active', $licence->fresh()->vendor_status, 'A vendor-restored subscription must release the billing hold on the same sync that observes it.');
    }
}
