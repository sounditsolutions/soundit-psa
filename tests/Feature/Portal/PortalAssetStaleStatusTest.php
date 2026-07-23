<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Person;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Load-bearing data-safety fix (psa-wedk / security psa-aeu26): the client-facing
 * portal asset surfaces branched directly on raw rmm_online, bypassing
 * Asset::statusBadge, so a frozen rmm_online=true with a weeks-old last_seen_at
 * still rendered a reassuring "Online" to the client. Every portal surface must
 * route through the stale-aware accessor so stale RMM data never reads Online.
 */
class PortalAssetStaleStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Portal routes 404 when portal_enabled is off.
        Setting::setValue('portal_enabled', '1');
    }

    private function client(): Client
    {
        return Client::create(['name' => 'Acme Corp']);
    }

    private function portalPerson(Client $client): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => 'portal-'.uniqid().'@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => false,
        ]);
    }

    private function staleAsset(Client $client): Asset
    {
        return Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'VAN-APP01',
            'is_active' => true,
            'rmm_online' => true,
            'last_seen_at' => now()->subWeeks(4),
        ]);
    }

    public function test_device_list_does_not_show_stale_asset_as_online(): void
    {
        $client = $this->client();
        $person = $this->portalPerson($client);
        $this->staleAsset($client);

        $resp = $this->actingAs($person, 'portal')->get(route('portal.assets.index'));

        $resp->assertOk();
        $resp->assertDontSee('<span class="badge bg-success">Online</span>', false);
        $resp->assertSee('Stale');
    }

    public function test_device_detail_does_not_show_stale_asset_as_online(): void
    {
        $client = $this->client();
        $person = $this->portalPerson($client);
        $asset = $this->staleAsset($client);

        $resp = $this->actingAs($person, 'portal')->get(route('portal.assets.show', $asset));

        $resp->assertOk();
        $resp->assertDontSee('<span class="badge bg-success">Online</span>', false);
        $resp->assertSee('Stale');
    }

    public function test_agreement_detail_does_not_show_stale_asset_as_online(): void
    {
        $client = $this->client();
        $person = $this->portalPerson($client);
        $asset = $this->staleAsset($client);

        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'start_date' => '2026-01-01',
        ]);
        $contract->assets()->attach($asset->id);

        $resp = $this->actingAs($person, 'portal')->get(route('portal.contracts.show', $contract));

        $resp->assertOk();
        $resp->assertDontSee('<span class="badge bg-success">Online</span>', false);
        $resp->assertSee('Stale');
    }

    public function test_fresh_online_asset_still_shows_online(): void
    {
        $client = $this->client();
        $person = $this->portalPerson($client);
        Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'FRESH-01',
            'is_active' => true,
            'rmm_online' => true,
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $resp = $this->actingAs($person, 'portal')->get(route('portal.assets.index'));

        $resp->assertOk();
        $resp->assertSee('Online');
        $resp->assertDontSee('Stale');
    }
}
