<?php

namespace Tests\Feature\AppRiver;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\AppRiver\AppRiverClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auto-match creates mappings on a name coincidence alone, so it is gated on
 * CustomerType === 'Resold' — the only type the partner API can actually serve.
 *
 * This is how the five bad mappings happened in the first place: autoMatch had no
 * type filter, five Referred customers shared a name with a PSA client, and the
 * sync then errored on them every night. Manual mapping is deliberately left
 * ungated: an operator choosing from the dropdown sees the type badge and decides.
 */
class AppRiverAutoMatchTypeGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Setting::setEncrypted('appriver_access_token', 'live-token');
    }

    private function unmappedClient(string $name): Client
    {
        return Client::factory()->create([
            'name' => $name,
            'appriver_customer_id' => null,
            'stage' => ClientStage::Active,
            'is_active' => true,
        ]);
    }

    /** @param array<int, array<string, mixed>> $customers */
    private function bindClientListing(array $customers): void
    {
        $mock = $this->createMock(AppRiverClient::class);
        $mock->method('getCustomers')->willReturn($customers);

        $this->app->instance(AppRiverClient::class, $mock);
    }

    public function test_auto_match_maps_resold_customers_but_never_referred_ones(): void
    {
        $resold = $this->unmappedClient('Acme Co');
        $referred = $this->unmappedClient('Globex Inc');

        $this->bindClientListing([
            ['CustomerId' => 'cust-resold', 'Name' => 'Acme Co', 'CustomerType' => 'Resold'],
            ['CustomerId' => 'cust-referred', 'Name' => 'Globex Inc', 'CustomerType' => 'Referred'],
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.appriver-customers.auto-match'))
            ->assertRedirect(route('settings.appriver-customers.index'));

        $this->assertSame(
            'cust-resold',
            $resold->refresh()->appriver_customer_id,
            'A name-matching Resold customer must still be auto-mapped.'
        );
        $this->assertNull(
            $referred->refresh()->appriver_customer_id,
            'A name-matching Referred customer must not be auto-mapped — the sync can never serve it.'
        );
    }

    /**
     * Fail closed on the CREATE path: a customer with no readable type is not
     * auto-mapped. It stays visible on the mapping page for an operator to map by
     * hand; guessing here is how unserviceable mappings get minted.
     */
    public function test_auto_match_skips_customers_with_no_customer_type(): void
    {
        $typeless = $this->unmappedClient('Initech LLC');

        $this->bindClientListing([
            ['CustomerId' => 'cust-typeless', 'Name' => 'Initech LLC'],
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.appriver-customers.auto-match'));

        $this->assertNull(
            $typeless->refresh()->appriver_customer_id,
            'A customer with no CustomerType must not be auto-mapped.'
        );
    }
}
