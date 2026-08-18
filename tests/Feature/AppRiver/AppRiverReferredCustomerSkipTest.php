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
 * Referred customers are skipped by the sync, not attempted and errored.
 *
 * A Referred customer buys from AppRiver directly — the partner API refuses its
 * subscriptions by design (measured in prod 2026-08-18: the six access-denied
 * customers were exactly the six CustomerType=Referred records, one-to-one).
 * The sync's worklist comes from the DB and holds only the GUID, so the type is
 * read from the customer list once per run.
 *
 * The filter must degrade, never dominate: a failed or unreadable customer list
 * falls back to syncing every mapped client — the pre-filter behaviour, whose
 * per-client catch is loud and withholds stale cleanup — and only the type the
 * vendor is measured to refuse is skipped. 'Partner' and anything the vendor
 * adds later sync normally.
 */
class AppRiverReferredCustomerSkipTest extends TestCase
{
    use RefreshDatabase;

    private function mappedClient(string $customerId, string $name): Client
    {
        return Client::factory()->create([
            'name' => $name,
            'appriver_customer_id' => $customerId,
            'stage' => ClientStage::Active,
            'is_active' => true,
        ]);
    }

    private function licenceFor(Client $client, string $vendorRef = 'sub-1', int $quantity = 4): License
    {
        $type = LicenseType::firstOrCreate(
            ['vendor' => 'appriver', 'vendor_sku_id' => 'business-premium'],
            ['name' => 'Business Premium', 'is_active' => true],
        );

        return License::create([
            'license_type_id' => $type->id,
            'client_id' => $client->id,
            'vendor_ref' => $vendorRef,
            'quantity' => $quantity,
            'assigned_quantity' => $quantity,
            'status' => 'active',
            'synced_at' => now()->subDay(),
        ]);
    }

    /** @return array<string, mixed> */
    private function customer(string $id, string $type): array
    {
        return ['CustomerId' => $id, 'Name' => "Customer {$id}", 'CustomerType' => $type];
    }

    /** @return array<string, mixed> */
    private function activeSubscription(string $key = 'sub-1'): array
    {
        return [
            'SubscriptionStatus' => 'Active',
            'SubscriptionKey' => $key,
            'ProductName' => 'Business Premium',
        ];
    }

    /** @return array<string, mixed> */
    private function detailWithCounts(int $total, int $assigned): array
    {
        return [
            'ReadonlySubscriptionDetails' => [
                ['Name' => 'TotalLicenses', 'Value' => (string) $total],
                ['Name' => 'AssignedLicenses', 'Value' => (string) $assigned],
            ],
        ];
    }

    public function test_a_referred_client_is_skipped_and_its_subscriptions_never_requested(): void
    {
        $this->mappedClient('cust-referred', 'Referred Co');
        $this->mappedClient('cust-resold', 'Resold Co');

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getCustomers')->willReturn([
            $this->customer('cust-referred', 'Referred'),
            $this->customer('cust-resold', 'Resold'),
        ]);

        // The load-bearing assertion: the vendor is never asked about the Referred
        // customer. Exactly one subscriptions call, and it names the Resold one.
        $mock->expects($this->once())
            ->method('getSubscriptions')
            ->with('cust-resold')
            ->willReturn([$this->activeSubscription()]);
        $mock->method('getSubscriptionDetail')->willReturn($this->detailWithCounts(5, 3));

        $result = (new AppRiverLicenseSyncService($mock))->syncLicenses();

        $this->assertSame(0, $result->errors, 'A skipped Referred client is by-design, not an error.');
        $this->assertSame(1, $result->created, 'The Resold sibling must still sync normally.');

        // By-design is not invisible: the skipped client's seat counts are frozen and
        // billed from, so the run summary has to name the omission even though the
        // exit status stays green.
        $this->assertSame(1, $result->skipped, 'A skipped client must be recorded on SyncResult, not only logged.');
        $this->assertStringContainsString(
            'Referred Co',
            $result->skippedMessages[0] ?? '',
            'The skip message must name the client whose licences were left unsynced.'
        );
        $this->assertStringContainsString('1 skipped', $result->summary());
    }

    /**
     * The destructive edge. A skipped client must stay out of the stale-cleanup
     * set: it was not observed, so any licence rows it holds are not evidence of
     * anything and must survive the run untouched.
     */
    public function test_a_skipped_referred_client_is_not_stale_cleaned(): void
    {
        $referred = $this->mappedClient('cust-referred', 'Referred Co');
        $licence = $this->licenceFor($referred);

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getCustomers')->willReturn([$this->customer('cust-referred', 'Referred')]);
        $mock->expects($this->never())->method('getSubscriptions');

        (new AppRiverLicenseSyncService($mock))->syncLicenses();

        $licence->refresh();

        $this->assertSame(4, $licence->quantity, 'A skipped client must not have its licences zeroed.');
        $this->assertSame('active', $licence->status);
    }

    /**
     * The filter degrades rather than dominates: if the customer list cannot be
     * read, the run proceeds for every mapped client exactly as before the filter
     * existed. Failing the run here would let a listing hiccup block the licence
     * sync the filter only exists to tidy.
     */
    public function test_a_failed_customer_list_read_does_not_fail_or_narrow_the_run(): void
    {
        $client = $this->mappedClient('cust-resold', 'Resold Co');

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getCustomers')->willThrowException(
            new AppRiverClientException('AppRiver customer list response has no Customers array; refusing to treat it as an empty list.')
        );
        $mock->expects($this->once())
            ->method('getSubscriptions')
            ->with('cust-resold')
            ->willReturn([$this->activeSubscription()]);
        $mock->method('getSubscriptionDetail')->willReturn($this->detailWithCounts(5, 3));

        $result = (new AppRiverLicenseSyncService($mock))->syncLicenses();

        $this->assertSame(0, $result->errors, 'An unreadable customer list must not fail the sync run.');
        $this->assertSame(1, $result->created);
        $this->assertNotNull(
            License::where('client_id', $client->id)->first(),
            'The mapped client must still have been synced without the type filter.'
        );
    }

    /**
     * Unknown is not Referred. A mapped id absent from the customer list — drift,
     * a conversion mid-run, a vendor paging quirk — syncs normally and fails
     * loudly on its own merits if access is really gone.
     */
    public function test_a_client_absent_from_the_customer_list_syncs_normally(): void
    {
        $this->mappedClient('cust-unlisted', 'Unlisted Co');

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getCustomers')->willReturn([$this->customer('cust-other', 'Resold')]);
        $mock->expects($this->once())
            ->method('getSubscriptions')
            ->with('cust-unlisted')
            ->willReturn([$this->activeSubscription()]);
        $mock->method('getSubscriptionDetail')->willReturn($this->detailWithCounts(5, 3));

        $result = (new AppRiverLicenseSyncService($mock))->syncLicenses();

        $this->assertSame(0, $result->errors);
        $this->assertSame(1, $result->created, 'An unlisted client must sync as before, not be silently skipped.');
    }

    /**
     * Only 'Referred' is skipped. 'Partner' is our own record and syncs normally —
     * a non-Resold blanket skip would silently freeze Sound IT's own licences.
     */
    public function test_a_partner_client_still_syncs(): void
    {
        $this->mappedClient('cust-partner', 'Sound IT Solutions');

        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getCustomers')->willReturn([$this->customer('cust-partner', 'Partner')]);
        $mock->expects($this->once())
            ->method('getSubscriptions')
            ->with('cust-partner')
            ->willReturn([$this->activeSubscription()]);
        $mock->method('getSubscriptionDetail')->willReturn($this->detailWithCounts(5, 3));

        $result = (new AppRiverLicenseSyncService($mock))->syncLicenses();

        $this->assertSame(0, $result->errors);
        $this->assertSame(1, $result->created);
    }
}
