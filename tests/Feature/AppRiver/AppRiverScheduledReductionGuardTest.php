<?php

namespace Tests\Feature\AppRiver;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverLicenseSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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
 * Two guards, and they answer different states:
 *   1. deactivateStale() clears scheduled_quantity with the rest of the row, so the
 *      state is not created — and NAMES each cleared instruction in the log and on
 *      SyncResult, because dropping an operator's billing intent in silence is its own
 *      defect. Keeping it instead was tried and is worse: a queue left on a zeroed row
 *      has no expiry, and reactivation of the same SubscriptionKey revives the row at
 *      the new seat count, at which point the old instruction passes guard 2 and bills
 *      the new subscription down.
 *   2. retryScheduledReductions() refuses to SEND a queued value above the current
 *      quantity, whatever put it there. That is what protects rows an earlier build
 *      already zeroed and left queued, which guard 1 cannot reach: the stale query only
 *      matches quantity > 0 or status active, and such a row is neither. It refuses
 *      without clearing, because there it does not know the row's history.
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
            ->willReturnCallback(function (string $customerId, string $key, int $quantity) use (&$sent): array {
                $sent[] = $quantity;

                // updateSubscriptionQuantity() is declared `: array`. Returning null
                // here raises a TypeError INSIDE retryScheduledReductions()' catch
                // (\Throwable), which logs 'still pending' at info level and moves on —
                // so a send would look like a vendor refusal and the positive case
                // below would be proving nothing. test_a_genuine_queued_reduction_is_
                // still_applied asserts that log line is absent for exactly this reason.
                return [];
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
     * the subscription back up to 2 seats. The queue is cleared rather than left
     * standing — but the discarded instruction is named, not dropped in silence, and
     * this pins both halves of that.
     */
    public function test_stale_cleanup_sends_no_seat_change_and_records_the_queue_it_discards(): void
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

        Log::spy();

        $result = (new AppRiverLicenseSyncService($mock))->syncLicenses();

        $licence->refresh();

        $this->assertSame(
            [],
            $sent,
            'no seat count may be pushed to AppRiver for a subscription it has just reported cancelled'
        );
        $this->assertSame(0, $licence->quantity, 'a cancelled subscription must still be cleaned up');
        $this->assertSame('suspended', $licence->status);
        $this->assertNull(
            $licence->scheduled_quantity,
            'the queued reduction must not survive the licence being zeroed — a queue standing on a zeroed row is an increase waiting for the next reactivation to make it sendable'
        );

        // Cleared is not the same as dropped. The instruction was an operator's, so
        // both the log and the run summary have to carry what was discarded.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'Discarding queued seat reduction to 2')
                && str_contains($message, 'Business Premium'))
            ->once();

        $this->assertSame(
            1,
            $result->withdrawn,
            'a discarded operator instruction must reach the run summary, not only the log'
        );
        $this->assertStringContainsString('withdrawn', $result->summary());

        // ...and it is NOT an error. AppRiverSyncLicenses returns FAILURE on
        // errors > 0, so routing a correct, expected outcome through recordError()
        // fails the nightly run and trains operators to ignore the exit code. The
        // sibling suite already fixes this convention: 'A cancelled subscription is
        // not an error.'
        $this->assertSame(
            0,
            $result->errors,
            'a cleanly handled cancellation is not a sync failure, queued reduction or not'
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

        $sent = [];
        $this->recordSeatWrites($mock, $sent);

        Log::spy();

        (new AppRiverLicenseSyncService($mock))->syncLicenses();

        $licence->refresh();

        $this->assertSame([6], $sent, 'the queued reduction is still sent — the guard must not swallow the feature it guards');
        $this->assertNull($licence->scheduled_quantity, 'an applied reduction clears the queue');

        // The send must SUCCEED, not merely be attempted. retryScheduledReductions()
        // wraps the call in catch (\Throwable) and logs 'still pending' on failure, so
        // without this a stub that throws would look identical to a passing guard.
        Log::shouldNotHaveReceived('info', [\Mockery::pattern('/still pending/')]);
    }

    /**
     * The hazard guard 2 cannot see, on the population guard 1 cannot repair: a row an
     * EARLIER build zeroed and left queued, whose subscription then comes back at a
     * different seat count. Reviving it restores quantity above the queued value, so
     * `scheduled > quantity` is false and the refusal never fires — the stale
     * instruction is simply sendable again. Nothing in this build creates that state;
     * it is what is sitting in the database from before the fix.
     */
    public function test_a_returning_subscription_does_not_make_an_old_queued_reduction_sendable(): void
    {
        $client = $this->mappedClient();
        $licence = $this->licenceFor($client, [
            'quantity' => 0,
            'assigned_quantity' => 0,
            'scheduled_quantity' => 3,
            'status' => 'suspended',
        ]);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getSubscriptions')->willReturn([
            [
                'SubscriptionStatus' => 'Active',
                'SubscriptionKey' => 'sub-1',
                'ProductName' => 'Business Premium',
            ],
        ]);
        $mock->method('getSubscriptionDetail')->willReturn($this->detail(10, 8));

        $sent = [];
        $this->recordSeatWrites($mock, $sent);

        Log::spy();

        $result = (new AppRiverLicenseSyncService($mock))->syncLicenses();

        $licence->refresh();

        $this->assertSame(
            [],
            $sent,
            'a queued reduction written against a subscription that has since ended must not be applied to the one that replaced it'
        );
        $this->assertSame(10, $licence->quantity, 'the returning subscription is still synced at its real seat count');
        $this->assertNull($licence->scheduled_quantity, 'the superseded instruction is retired, not left to fire later');

        // This path destroys an operator instruction too, so it owes the same record
        // as the stale-cleanup discard — and on the same non-error channel.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'Discarding queued seat reduction to 3')
                && str_contains($message, 'reported again at 10 seats'))
            ->once();

        $this->assertSame(1, $result->withdrawn, 'the retired instruction must reach the run summary');
        $this->assertSame(0, $result->errors, 'a subscription coming back is not a sync failure');
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
