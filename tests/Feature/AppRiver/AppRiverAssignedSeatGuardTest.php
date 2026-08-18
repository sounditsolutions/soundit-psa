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
 * The operator-facing seat write, and the one state its guard used to wave through.
 *
 * updateQuantity() refuses to reduce a licence below its assigned seat count, because
 * that strands users. The guard read `assigned_quantity !== null && $new < $assigned`,
 * so a NULL assigned count skipped it entirely — the reduction went to the vendor
 * unchecked on precisely the rows the PSA can say least about.
 *
 * Null is not "nobody is assigned". extractLicenseCounts() returns assigned => null for
 * any detail payload carrying TotalLicenses without AssignedLicenses, and the sync
 * writes that null onto the row, so this is a state the ordinary sync path produces —
 * not a hypothetical. The same rule the envelope and status guards already hold to:
 * unreadable is not empty.
 */
class AppRiverAssignedSeatGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: License, 1: AppRiverClient}
     */
    private function licenceWithAssigned(?int $assigned, int $quantity = 10): array
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
            'assigned_quantity' => $assigned,
            'status' => 'active',
            'synced_at' => now()->subDay(),
        ]);

        return [$licence, $this->createMock(AppRiverClient::class)];
    }

    /**
     * The reported defect. Ten seats, an unknown assignment, and a reduction to two:
     * the PSA cannot tell whether that strands eight users or none, and the write is
     * outbound and billable either way.
     */
    public function test_a_reduction_is_refused_when_the_assigned_seat_count_is_unknown(): void
    {
        [$licence, $mock] = $this->licenceWithAssigned(null);

        $sent = [];
        $mock->method('updateSubscriptionQuantity')
            ->willReturnCallback(function (string $customerId, string $key, int $quantity) use (&$sent): array {
                $sent[] = $quantity;

                return [];
            });

        $caught = null;

        try {
            (new AppRiverLicenseSyncService($mock))->updateQuantity($licence, 2);
        } catch (AppRiverClientException $e) {
            $caught = $e;
        }

        // Asserted before the exception, because "nothing was sent" is the claim: a
        // guard that throws AFTER the vendor call would satisfy expectException and
        // still have billed the client.
        $this->assertSame([], $sent, 'a reduction that cannot be checked must not reach the vendor');

        $this->assertNotNull($caught, 'the reduction must be refused, not silently skipped');
        $this->assertStringContainsString('assigned seat count', $caught->getMessage());
        $this->assertStringContainsString(
            'Re-sync',
            $caught->getMessage(),
            'the refusal has to tell the operator how to clear it, or it reads as a bug'
        );

        $licence->refresh();
        $this->assertSame(10, $licence->quantity, 'a refused reduction changes nothing locally either');
    }

    /**
     * The guard must not swallow the feature it guards. An INCREASE is not checked
     * against the assigned count in either direction — you cannot strand a user by
     * buying seats — so an unknown assignment must not block it.
     */
    public function test_an_increase_is_unaffected_by_an_unknown_assigned_count(): void
    {
        [$licence, $mock] = $this->licenceWithAssigned(null);

        $sent = [];
        $mock->method('updateSubscriptionQuantity')
            ->willReturnCallback(function (string $customerId, string $key, int $quantity) use (&$sent): array {
                $sent[] = $quantity;

                return [];
            });
        $mock->method('getSubscriptionDetail')->willReturn([
            'ReadonlySubscriptionDetails' => [
                ['Name' => 'TotalLicenses', 'Value' => '12'],
                ['Name' => 'AssignedLicenses', 'Value' => '9'],
            ],
        ]);

        (new AppRiverLicenseSyncService($mock))->updateQuantity($licence, 12);

        $licence->refresh();

        $this->assertSame([12], $sent, 'an increase is safe with an unknown assignment and must still go');
        $this->assertSame(12, $licence->quantity);
        $this->assertSame(9, $licence->assigned_quantity, 're-fetching the detail is what repopulates the count');
    }

    /**
     * And the existing refusal is untouched: a KNOWN assignment still blocks a
     * reduction below it, with the count named.
     */
    public function test_a_reduction_below_a_known_assigned_count_is_still_refused(): void
    {
        [$licence, $mock] = $this->licenceWithAssigned(7);

        $sent = [];
        $mock->method('updateSubscriptionQuantity')
            ->willReturnCallback(function (string $customerId, string $key, int $quantity) use (&$sent): array {
                $sent[] = $quantity;

                return [];
            });

        $caught = null;

        try {
            (new AppRiverLicenseSyncService($mock))->updateQuantity($licence, 5);
        } catch (AppRiverClientException $e) {
            $caught = $e;
        }

        $this->assertSame([], $sent);
        $this->assertNotNull($caught);
        $this->assertStringContainsString('7 seats are currently assigned', $caught->getMessage());
    }
}
