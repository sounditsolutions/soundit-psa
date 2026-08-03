<?php

namespace Tests\Feature\AppRiver;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverClientException;
use App\Services\AppRiver\AppRiverLicenseSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guard for the data-loss path the #389 review surfaced.
 *
 * syncClientSubscriptions() swallows a per-subscription \Throwable and returns
 * normally, so a client whose subscriptions ALL failed was still recorded in
 * $successfulClientIds — and deactivateStale() then zeroed its seat counts and
 * suspended its licences.
 *
 * That was unreachable while disconnect() never fired: the run died at the first
 * token refresh, getSubscriptions() threw, and the client was correctly excluded.
 * The #389 parse fix makes disconnect() fire for real, which opens the window —
 * credentials dying partway through a client's detail calls is exactly this shape.
 */
class AppRiverStaleCleanupGuardTest extends TestCase
{
    use RefreshDatabase;

    private function mappedClientWithLicence(int $quantity = 4): array
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
            'quantity' => $quantity,
            'assigned_quantity' => $quantity,
            'status' => 'active',
            'synced_at' => now()->subDay(),
        ]);

        return [$client, $licence];
    }

    private function clientReturningSubscriptionsThenFailingDetail(): AppRiverClient
    {
        $mock = $this->createMock(AppRiverClient::class);

        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => 'Active',
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);

        $mock->method('getSubscriptionDetail')->willThrowException(
            new AppRiverClientException('AppRiver access token not found. Please connect via Settings > Integrations > AppRiver.')
        );

        return $mock;
    }

    public function test_a_client_whose_subscriptions_all_failed_is_not_stale_cleaned(): void
    {
        [, $licence] = $this->mappedClientWithLicence(4);

        $service = new AppRiverLicenseSyncService($this->clientReturningSubscriptionsThenFailingDetail());
        $service->syncLicenses();

        $licence->refresh();

        $this->assertSame(4, $licence->quantity, 'Seat count must survive a mid-run credential failure.');
        $this->assertSame(4, $licence->assigned_quantity);
        $this->assertSame('active', $licence->status, 'A failed detail call must not suspend a licence.');
    }

    /**
     * A malformed entry is skipped without throwing, so it is not a *failure* by any
     * test the loop applies — but its licence is still absent from $seenLicenseIds.
     * Eligibility for stale cleanup has to be positive observation, not absence of
     * exceptions, or a vendor payload shape change silently suspends licences.
     */
    public function test_a_silently_skipped_malformed_subscription_also_blocks_stale_cleanup(): void
    {
        [, $licence] = $this->mappedClientWithLicence(4);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => 'Active',
                'SubscriptionKey' => 'sub-1',
                // ProductName missing — skipped by the loop with no exception
            ],
        ]);

        $service = new AppRiverLicenseSyncService($mock);
        $service->syncLicenses();

        $licence->refresh();

        $this->assertSame(4, $licence->quantity, 'A silently skipped subscription must not zero a licence.');
        $this->assertSame('active', $licence->status);
    }

    /**
     * The drift the guard is advertised to defend against: a vendor rename of
     * SubscriptionStatus makes every entry fall through the active filter. Counting
     * that as "inactive" would leave the client looking fully observed with zero
     * licences seen, and stale cleanup would zero the lot.
     */
    public function test_an_unrecognised_status_is_unobserved_not_inactive(): void
    {
        [, $licence] = $this->mappedClientWithLicence(4);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([
            [
                'subscriptionState' => 'Active', // renamed field
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);

        $service = new AppRiverLicenseSyncService($mock);
        $result = $service->syncLicenses();

        $licence->refresh();

        $this->assertSame(4, $licence->quantity, 'A renamed status field must not zero a licence.');
        $this->assertSame('active', $licence->status);
        $this->assertGreaterThan(0, $result->errors, 'Withheld cleanup must be recorded, not just logged.');
    }

    /**
     * The other side of it: a known inactive status IS an observation, so the
     * licence should still be cleaned up. Otherwise a cancelled subscription bills
     * forever.
     */
    public function test_a_known_inactive_status_is_observed_and_still_deactivates(): void
    {
        [, $licence] = $this->mappedClientWithLicence(4);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => 'Cancelled',
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);

        $service = new AppRiverLicenseSyncService($mock);
        $result = $service->syncLicenses();

        $licence->refresh();

        $this->assertSame(0, $licence->quantity, 'A cancelled subscription must still be cleaned up.');
        $this->assertSame('suspended', $licence->status);
        $this->assertSame(0, $result->errors, 'A cancelled subscription is not an error.');
    }

    public function test_a_genuinely_absent_subscription_is_still_deactivated(): void
    {
        [, $licence] = $this->mappedClientWithLicence(4);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([]);

        $service = new AppRiverLicenseSyncService($mock);
        $service->syncLicenses();

        $licence->refresh();

        $this->assertSame(0, $licence->quantity, 'A clean run that no longer sees a subscription must still deactivate it.');
        $this->assertSame('suspended', $licence->status);
    }
}
