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
 * Two write-path guards on the same principle the envelope and status filters already
 * carry: unreadable is not empty, and not-active is not gone.
 *
 * The existing status filter is binary — a status is either in ACTIVE_SUBSCRIPTION_STATUSES
 * or it is treated as positive evidence the subscription ended, which makes its licence
 * cleanup-eligible. 'Suspended' and 'Pending' are neither: one may be restored by the
 * vendor, the other is mid-provisioning. Both were being read as absence.
 *
 * The second guard is one layer further in. extractLicenseCounts() yields nulls when
 * ReadonlySubscriptionDetails is absent or renamed, and the licence write defaults total
 * to 0 — so a drifted detail payload zeroed a live seat count on the write path itself,
 * with nothing counted unobserved and the run exiting SUCCESS.
 */
class AppRiverInconclusiveStatusGuardTest extends TestCase
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

    private function clientReportingStatus(string $status): AppRiverClient
    {
        $mock = $this->createMock(AppRiverClient::class);

        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => $status,
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);

        return $mock;
    }

    /**
     * A suspended subscription is one the vendor may restore. Zeroing its seat count
     * makes the restore silent — the licence comes back at whatever the next run reads,
     * and everything between is billed against a row that says zero.
     */
    public function test_a_suspended_subscription_is_unobserved_not_cleaned_up(): void
    {
        [, $licence] = $this->mappedClientWithLicence(4);

        $service = new AppRiverLicenseSyncService($this->clientReportingStatus('Suspended'));
        $result = $service->syncLicenses();

        $licence->refresh();

        $this->assertSame(4, $licence->quantity, 'A suspended subscription must not zero a live seat count.');
        $this->assertSame('active', $licence->status, 'A suspended subscription is not an observation of absence.');
        $this->assertSame(0, $result->errors, 'Suspended is an ordinary vendor state, not an error — routing it through recordError() would fail the nightly run for as long as the vendor leaves it suspended.');
    }

    /**
     * Pending is a subscription mid-provisioning — the seats are being stood up, not
     * torn down. Reading it as absence cleans up a licence that is about to be active.
     */
    public function test_a_pending_subscription_is_unobserved_not_cleaned_up(): void
    {
        [, $licence] = $this->mappedClientWithLicence(4);

        $service = new AppRiverLicenseSyncService($this->clientReportingStatus('Pending'));
        $result = $service->syncLicenses();

        $licence->refresh();

        $this->assertSame(4, $licence->quantity, 'A pending subscription must not zero a live seat count.');
        $this->assertSame('active', $licence->status);
        $this->assertSame(0, $result->errors, 'A subscription mid-provisioning is not an error.');
    }

    /**
     * The withheld cleanup is per-subscription, not per-client. A suspended subscription
     * holds back its OWN licence; a cancelled sibling on the same client must still be
     * zeroed, or that cancelled subscription bills for as long as the vendor leaves the
     * other one suspended.
     */
    public function test_an_inconclusive_subscription_does_not_block_cleanup_of_a_cancelled_sibling(): void
    {
        [$client, $suspendedLicence] = $this->mappedClientWithLicence(4);

        $cancelledType = LicenseType::create([
            'vendor' => 'appriver',
            'vendor_sku_id' => 'exchange-plan-1',
            'name' => 'Exchange Plan 1',
            'is_active' => true,
        ]);

        $cancelledLicence = License::create([
            'license_type_id' => $cancelledType->id,
            'client_id' => $client->id,
            'vendor_ref' => 'sub-2',
            'quantity' => 4,
            'assigned_quantity' => 4,
            'status' => 'active',
            'synced_at' => now()->subDay(),
        ]);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => 'Suspended',
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
            [
                'SubscriptionStatus' => 'Cancelled',
                'SubscriptionKey' => 'sub-2',
                'ProductName' => 'Exchange Plan 1',
            ],
        ]);

        $service = new AppRiverLicenseSyncService($mock);
        $result = $service->syncLicenses();

        $suspendedLicence->refresh();
        $cancelledLicence->refresh();

        $this->assertSame(4, $suspendedLicence->quantity, 'The suspended subscription must keep its seat count.');
        $this->assertSame('active', $suspendedLicence->status);
        $this->assertSame(0, $cancelledLicence->quantity, 'A cancelled sibling must still be cleaned up — one inconclusive subscription does not shield the whole client.');
        $this->assertSame('suspended', $cancelledLicence->status);
        $this->assertSame(0, $result->errors);
    }

    /**
     * Licence rows are only ever created from an Active/Trial report, so a subscription
     * first reported Pending has no row of its own to hold out. That is routine
     * provisioning state, not a malformed entry: it must not fail the nightly run, and it
     * must not withhold cleanup from the client's other subscriptions.
     */
    public function test_an_inconclusive_subscription_with_no_licence_row_is_not_an_error_and_shields_nothing(): void
    {
        [, $cancelledLicence] = $this->mappedClientWithLicence(4);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => 'Pending',
                'SubscriptionKey' => 'sub-new',
                'ProductName' => 'Business Premium',
            ],
            [
                'SubscriptionStatus' => 'Cancelled',
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);

        $service = new AppRiverLicenseSyncService($mock);
        $result = $service->syncLicenses();

        $cancelledLicence->refresh();

        $this->assertSame(0, $result->errors, 'A subscription mid-provisioning that has no licence row yet is not an error — recording one fails every nightly run until provisioning completes.');
        $this->assertSame(0, $cancelledLicence->quantity, 'A cancelled sibling must still be cleaned up — a pending subscription with no row of its own shields nothing.');
        $this->assertSame('suspended', $cancelledLicence->status);
    }

    public static function conclusiveInactiveStatuses(): array
    {
        return [
            'cancelled' => ['Cancelled'],
            'expired' => ['Expired'],
            'deleted' => ['Deleted'],
        ];
    }

    /**
     * The other side of the same line, and the reason the inconclusive list is short and
     * explicit rather than "everything not active": a genuinely ended subscription must
     * still be cleaned up, or a cancelled licence bills forever.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('conclusiveInactiveStatuses')]
    public function test_a_conclusive_inactive_status_still_deactivates(string $status): void
    {
        [, $licence] = $this->mappedClientWithLicence(4);

        $service = new AppRiverLicenseSyncService($this->clientReportingStatus($status));
        $result = $service->syncLicenses();

        $licence->refresh();

        $this->assertSame(0, $licence->quantity, "A {$status} subscription must still be cleaned up.");
        $this->assertSame('suspended', $licence->status);
        $this->assertSame(0, $result->errors, "A {$status} subscription is not an error.");
    }

    /**
     * The write-path half. A detail body that carries no readable licence counts is a
     * drifted or truncated payload, not a subscription with zero seats — and the write
     * below it defaults total to 0.
     */
    public function test_an_unreadable_detail_payload_does_not_zero_the_seat_count(): void
    {
        [, $licence] = $this->mappedClientWithLicence(4);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => 'Active',
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);
        // ReadonlySubscriptionDetails absent entirely — extractLicenseCounts() yields nulls.
        $mock->method('getSubscriptionDetail')->willReturn(['SubscriptionKey' => 'sub-1']);

        $service = new AppRiverLicenseSyncService($mock);
        $result = $service->syncLicenses();

        $licence->refresh();

        $this->assertSame(4, $licence->quantity, 'An unreadable detail payload must not write quantity 0 over a live licence.');
        $this->assertSame('active', $licence->status);
        $this->assertGreaterThan(0, $result->errors, 'An unreadable payload must be recorded as unobserved.');
    }

    /**
     * The section can be present and populated and still not carry the one entry the
     * seat count is read from. That is the same corruption with a different cause, and
     * the guard is on the value, not on the section existing.
     */
    public function test_a_populated_detail_section_missing_the_count_entry_does_not_zero_the_seat_count(): void
    {
        [, $licence] = $this->mappedClientWithLicence(4);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => 'Active',
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);
        $mock->method('getSubscriptionDetail')->willReturn([
            'ReadonlySubscriptionDetails' => [
                ['Name' => 'BillingCycle', 'Value' => 'Monthly'],
                ['Name' => 'RenewalDate', 'Value' => '2026-09-01'],
            ],
        ]);

        $service = new AppRiverLicenseSyncService($mock);
        $result = $service->syncLicenses();

        $licence->refresh();

        $this->assertSame(4, $licence->quantity, 'A populated section naming every field but the count must not zero a licence.');
        $this->assertSame('active', $licence->status);
        $this->assertGreaterThan(0, $result->errors);
    }

    /**
     * A real zero must still be written. The guard keys on unreadable (both counts null),
     * not on the number being small — otherwise a subscription genuinely reduced to zero
     * seats never gets recorded.
     */
    public function test_a_readable_zero_seat_count_is_still_written(): void
    {
        [, $licence] = $this->mappedClientWithLicence(4);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => 'Active',
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);
        $mock->method('getSubscriptionDetail')->willReturn([
            'ReadonlySubscriptionDetails' => [
                ['Name' => 'TotalLicenses', 'Value' => '0'],
                ['Name' => 'AssignedLicenses', 'Value' => '0'],
            ],
        ]);

        $service = new AppRiverLicenseSyncService($mock);
        $result = $service->syncLicenses();

        $licence->refresh();

        $this->assertSame(0, $licence->quantity, 'An observed zero is an observation and must be written.');
        $this->assertSame('active', $licence->status, 'An observed zero is not a stale-cleanup suspension.');
        $this->assertSame(0, $result->errors, 'An observed zero is not an error.');
    }
}
