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
 * The other half of the AppRiver release condition: an outbound seat INCREASE the
 * operator never asked for.
 *
 * syncLicenses() runs deactivateStale() and then, three lines later,
 * retryScheduledReductions(). deactivateStale() zeroed quantity and left
 * scheduled_quantity standing, so the same row immediately matched the retry's
 * pending query — scheduled_quantity != quantity — and the "queued reduction"
 * pushed to the vendor was a rise from 0 to whatever was queued, against a
 * subscription AppRiver had just stopped reporting. A billing write, unattended,
 * on every sync run.
 *
 * The guard is in retryScheduledReductions(): it refuses to send a queued value
 * ABOVE the current quantity, whatever put it there — a row zeroed by the stale
 * cleanup in this same run, a row an earlier build already zeroed and which the stale
 * query can no longer see (quantity 0, already suspended), or a vendor-side reduction
 * observed by an ordinary sync.
 *
 * deactivateStale() deliberately does NOT clear the queue. Staleness is only "absent
 * from this run's response", so clearing it would let a paging glitch or a partial
 * response silently destroy an operator's billing instruction that nothing can
 * re-derive; quantity self-heals when the subscription reappears, and the reduction
 * goes then.
 */
class AppRiverScheduledReductionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function mappedClient(): Client
    {
        return Client::factory()->create([
            'appriver_customer_id' => 'cust-1',
            'stage' => ClientStage::Active,
            'is_active' => true,
        ]);
    }

    private function licenceFor(Client $client, array $attributes): License
    {
        $type = LicenseType::create([
            'vendor' => 'appriver',
            'vendor_sku_id' => 'business-premium',
            'name' => 'Business Premium',
            'is_active' => true,
        ]);

        return License::create(array_merge([
            'license_type_id' => $type->id,
            'client_id' => $client->id,
            'vendor_ref' => 'sub-1',
            'status' => 'active',
            'synced_at' => now()->subDay(),
        ], $attributes));
    }

    /**
     * Records what was pushed to the vendor rather than asserting never() on the
     * mock. A mock expectation is verified at teardown, so any assertion that fails
     * first masks it — and "no seat change was sent" is the claim these tests exist
     * to make, so it has to be the assertion that fires first and the one a
     * red-check breaks on.
     *
     * @param  array<int, int>  $sent
     */
    private function recordSeatWrites(AppRiverClient $mock, array &$sent): void
    {
        $mock->method('updateSubscriptionQuantity')
            ->willReturnCallback(function (string $customerId, string $key, int $quantity) use (&$sent) {
                $sent[] = $quantity;
            });
    }

    private function detail(int $total, int $assigned): array
    {
        return [
            'ReadonlySubscriptionDetails' => [
                ['Name' => 'TotalLicenses', 'Value' => (string) $total],
                ['Name' => 'AssignedLicenses', 'Value' => (string) $assigned],
            ],
        ];
    }

    /**
     * The reported path, end to end. A queued reduction of 4 → 2 is outstanding when
     * AppRiver reports the subscription cancelled. Cleanup is correct and must
     * happen; what must NOT happen is the retry pass then telling the vendor to put
     * the subscription back up to 2 seats — and the operator's queued instruction must
     * still be standing afterwards, because "cancelled" here is only "AppRiver did not
     * list it in this response".
     */
    public function test_stale_cleanup_sends_no_seat_change_and_keeps_the_queued_reduction(): void
    {
        $client = $this->mappedClient();
        $licence = $this->licenceFor($client, [
            'quantity' => 4,
            'assigned_quantity' => 4,
            'scheduled_quantity' => 2,
        ]);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => 'Cancelled',
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);
        $sent = [];
        $this->recordSeatWrites($mock, $sent);

        (new AppRiverLicenseSyncService($mock))->syncLicenses();

        $licence->refresh();

        $this->assertSame(
            [],
            $sent,
            'no seat count may be pushed to AppRiver for a subscription it has just reported cancelled'
        );
        $this->assertSame(0, $licence->quantity, 'a cancelled subscription must still be cleaned up');
        $this->assertSame('suspended', $licence->status);
        $this->assertSame(
            2,
            $licence->scheduled_quantity,
            'the operator instruction survives cleanup — a vendor omission may be transient, and the retry guard already refuses to send a queued value above quantity'
        );
    }

    /**
     * Guard 2, on the state guard 1 cannot repair: a row zeroed by an EARLIER run of
     * the unfixed code. Nothing in this run creates it and the stale query cannot
     * see it (quantity 0, already suspended), so it reaches the retry pass exactly as
     * it sits in the database today.
     */
    public function test_a_queued_value_above_the_current_quantity_is_refused_not_sent(): void
    {
        $client = $this->mappedClient();
        $licence = $this->licenceFor($client, [
            'quantity' => 0,
            'assigned_quantity' => 0,
            'scheduled_quantity' => 3,
            'status' => 'suspended',
        ]);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([]);

        $sent = [];
        $this->recordSeatWrites($mock, $sent);

        (new AppRiverLicenseSyncService($mock))->syncLicenses();

        $licence->refresh();

        $this->assertSame(
            [],
            $sent,
            'a queued value above the current quantity is an increase; it must never reach the vendor'
        );
        $this->assertSame(0, $licence->quantity, 'the guard must not resurrect the seat count either');
        $this->assertSame(
            3,
            $licence->scheduled_quantity,
            'the queued intent is refused, not discarded — silently dropping an operator instruction is a separate decision'
        );
    }

    /**
     * The guard must not swallow the feature it guards. A genuine outstanding
     * reduction — queued at 6 against a subscription the vendor still reports at 10 —
     * is exactly what retryScheduledReductions() exists to send, and it still goes.
     */
    public function test_a_genuine_queued_reduction_is_still_applied(): void
    {
        $client = $this->mappedClient();
        $licence = $this->licenceFor($client, [
            'quantity' => 10,
            'assigned_quantity' => 5,
            'scheduled_quantity' => 6,
        ]);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => 'Active',
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);
        $mock->method('getSubscriptionDetail')->willReturn($this->detail(10, 5));
        $mock->expects($this->once())
            ->method('updateSubscriptionQuantity')
            ->with('cust-1', 'sub-1', 6);

        (new AppRiverLicenseSyncService($mock))->syncLicenses();

        $licence->refresh();

        $this->assertNull($licence->scheduled_quantity, 'an applied reduction clears the queue');
    }

    /**
     * Removing a client's AppRiver mapping zeroes its licences through a different
     * method on the model, and the same invariant has to hold there: nothing may be
     * left queued against a suspended row.
     */
    public function test_unmapping_a_client_also_clears_the_queued_reduction(): void
    {
        $client = $this->mappedClient();
        $licence = $this->licenceFor($client, [
            'quantity' => 4,
            'assigned_quantity' => 4,
            'scheduled_quantity' => 2,
        ]);

        License::deactivateForClients([$client->id], 'appriver');

        $licence->refresh();

        $this->assertSame(0, $licence->quantity);
        $this->assertSame('suspended', $licence->status);
        $this->assertNull($licence->scheduled_quantity);
    }
}
