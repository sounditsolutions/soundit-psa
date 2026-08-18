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
use Illuminate\Support\Facades\Log;
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
     *
     * The vendor is asked before anything is refused, and here it returns nothing
     * readable — which is the RECOVERABLE case: a later attempt can genuinely clear it,
     * so the message has to name a remedy that works rather than one that cannot.
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
            'Try again',
            $caught->getMessage(),
            'the refusal has to name a remedy that can actually clear it, or it reads as a bug'
        );

        $licence->refresh();
        $this->assertSame(10, $licence->quantity, 'a refused reduction changes nothing locally either');
    }

    /**
     * The state the refusal must never become permanent for: a product whose detail
     * payload carries TotalLicenses and no AssignedLicenses at all. Every sync rewrites
     * that null, so a refusal here could not be cleared by any action available to the
     * operator — seat reductions for the whole product line would be impossible through
     * the PSA while the client went on being billed for the seats.
     *
     * The vendor's own answer is what separates this from the unreadable case above:
     * counts came back, they simply carry no assignment, so there is nothing to check
     * and never will be. The reduction goes.
     */
    public function test_a_product_that_never_reports_an_assignment_can_still_be_reduced(): void
    {
        [$licence, $mock] = $this->licenceWithAssigned(null);

        $sent = [];
        $mock->method('updateSubscriptionQuantity')
            ->willReturnCallback(function (string $customerId, string $key, int $quantity) use (&$sent): array {
                $sent[] = $quantity;

                return [];
            });
        $mock->method('getSubscriptionDetail')->willReturnOnConsecutiveCalls(
            ['ReadonlySubscriptionDetails' => [['Name' => 'TotalLicenses', 'Value' => '10']]],
            ['ReadonlySubscriptionDetails' => [['Name' => 'TotalLicenses', 'Value' => '2']]],
        );

        Log::spy();

        (new AppRiverLicenseSyncService($mock))->updateQuantity($licence, 2);

        $licence->refresh();

        $this->assertSame([2], $sent, 'a check that can never be satisfied must not block the reduction forever');
        $this->assertSame(2, $licence->quantity);
        $this->assertNull($licence->assigned_quantity, 'the vendor still reports no assignment, and nothing may invent one');

        // Unchecked is not unremarked. The one thing owed to the operator on this path is
        // a record that no assignment was verified before the seats were given back.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'without an assigned-seat check'))
            ->once();
    }

    /**
     * The ordinary null: a row the sync left blank for a subscription the vendor DOES
     * report an assignment for. Asking at the point of the write repopulates it and the
     * reduction is checked against the real number — the operator is never sent off to
     * run a sync by hand to unblock themselves, and the check still bites.
     */
    public function test_a_stale_unknown_is_read_from_the_vendor_and_the_reduction_is_checked_against_it(): void
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
                ['Name' => 'TotalLicenses', 'Value' => '10'],
                ['Name' => 'AssignedLicenses', 'Value' => '7'],
            ],
        ]);

        $caught = null;

        try {
            (new AppRiverLicenseSyncService($mock))->updateQuantity($licence, 5);
        } catch (AppRiverClientException $e) {
            $caught = $e;
        }

        $this->assertSame([], $sent, 'a reduction below the real assigned count must still not reach the vendor');
        $this->assertNotNull($caught, 'reading the count is what makes the refusal possible, not a way around it');
        $this->assertStringContainsString('7 seats are currently assigned', $caught->getMessage());

        $licence->refresh();
        $this->assertSame(7, $licence->assigned_quantity, 'the count the decision was made on is kept, not thrown away');
        $this->assertSame(10, $licence->quantity, 'a refused reduction changes nothing else locally');
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
