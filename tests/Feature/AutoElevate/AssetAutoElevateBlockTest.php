<?php

namespace Tests\Feature\AutoElevate;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\AutoElevate\AutoElevateAssetSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Stage 3a asset-page states (since S2 in the Sources AutoElevate panel, design-r3 §4)
 * and the stage 2 client panel's "Linked asset" column.
 * The asset page makes NO vendor call (Http::fake + assertNothingSent); every empty state
 * names its reason (C-56); times render in Pacific with the zone shown (C-14).
 */
class AssetAutoElevateBlockTest extends TestCase
{
    use AutoElevateFixtures;
    use RefreshDatabase;

    private const BASE = 'https://partner-api.autoelevate.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-only-key');
        Setting::setValue('app_timezone', 'America/Los_Angeles');
        $this->actingAs(User::factory()->create(['role' => UserRole::Tech]));
    }

    private function block(Asset $asset)
    {
        Http::fake(); // records requests; the page must send none
        $response = $this->get(route('assets.show', $asset))->assertOk();
        Http::assertNothingSent();

        return $response;
    }

    public function test_linked_audit_asset_calls_out_audit_and_shows_pacific_check_in(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'autoelevate_computer_id' => self::uuid(1),
            'autoelevate_elevation_mode' => 'audit',
            'autoelevate_last_checked_in_at' => CarbonImmutable::createFromTimestampMsUTC(self::EXAMPLE_MS),
            'autoelevate_synced_at' => now(),
        ]);

        $this->block($asset)
            ->assertSee('data-state="linked"', false)
            ->assertSee('data-audit-callout', false)
            ->assertSee('Audit mode:')
            ->assertSee('AUDIT')
            ->assertSee('May 28, 2024 5:40 AM PDT')   // 12:40Z → Pacific, zone shown
            ->assertSee('Not reported by AutoElevate'); // no agent version in the vendor schema
    }

    public function test_linked_live_asset_has_no_audit_callout(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $asset = Asset::factory()->create(['client_id' => $client->id,
            'autoelevate_computer_id' => self::uuid(2), 'autoelevate_elevation_mode' => 'live']);

        $this->block($asset)->assertSee('data-state="linked"', false)
            ->assertDontSee('data-audit-callout', false)->assertSee('>live<', false);
    }

    public function test_null_mode_renders_as_unknown_never_audit(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $asset = Asset::factory()->create(['client_id' => $client->id,
            'autoelevate_computer_id' => self::uuid(3), 'autoelevate_elevation_mode' => null]);

        $this->block($asset)->assertSee('No mode reported')
            ->assertDontSee('data-audit-callout', false)->assertDontSee('AUDIT');
    }

    public function test_unrecognised_mode_is_shown_verbatim_and_flagged(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $asset = Asset::factory()->create(['client_id' => $client->id,
            'autoelevate_computer_id' => self::uuid(4), 'autoelevate_elevation_mode' => 'quarantine']);

        $this->block($asset)->assertSee('Unrecognised: quarantine');
    }

    public function test_empty_state_client_unmapped(): void
    {
        $asset = Asset::factory()->create();

        $this->block($asset)->assertSee('data-state="not_mapped"', false)->assertSee('Client not mapped.');
    }

    public function test_empty_state_no_vendor_match(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $asset = Asset::factory()->create(['client_id' => $client->id]);
        AutoElevateAssetSyncService::recordClientOutcome($client->id, self::COMPANY_A, true, null,
            [$asset->id => AutoElevateAssetSyncService::normalizeHostname($asset->hostname)]);

        $this->block($asset)->assertSee('data-state="no_match"', false)->assertSee('No AutoElevate match.');
    }

    public function test_empty_state_read_failed_names_the_reason(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $asset = Asset::factory()->create(['client_id' => $client->id]);
        AutoElevateAssetSyncService::recordClientOutcome($client->id, self::COMPANY_A, false, 'http_503');

        $this->block($asset)->assertSee('data-state="read_failed"', false)
            ->assertSee('data-reason="http_503"', false)
            ->assertSee('AutoElevate read failed')
            ->assertSee('not evidence that the machine is absent');
    }

    public function test_empty_state_mapped_but_never_synced(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $asset = Asset::factory()->create(['client_id' => $client->id]);

        $this->block($asset)->assertSee('data-state="not_synced"', false)->assertSee('Not synced yet.');
    }

    public function test_outcome_read_under_a_previous_mapping_is_not_claimed_as_no_match(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $asset = Asset::factory()->create(['client_id' => $client->id]);
        // Recorded while the client was mapped to company B, with this very asset considered.
        AutoElevateAssetSyncService::recordClientOutcome($client->id, self::COMPANY_B, true, null,
            [$asset->id => AutoElevateAssetSyncService::normalizeHostname($asset->hostname)]);

        $this->block($asset)->assertSee('data-state="not_synced"', false)->assertDontSee('No AutoElevate match.');
    }

    public function test_block_hidden_when_unconfigured_and_unlinked(): void
    {
        Setting::where('key', 'autoelevate_api_key')->delete();
        $asset = Asset::factory()->create();

        $this->block($asset)->assertDontSee('data-source-panel="autoelevate"', false)
            ->assertDontSee('autoelevate-asset-state', false);
    }

    // --- client panel: linked asset column ------------------------------------------------

    public function test_client_panel_links_each_computer_to_its_own_clients_asset_only(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $other = Client::factory()->create();
        $mine = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'WS-FINANCE-04',
            'autoelevate_computer_id' => self::uuid(1)]);
        // A stale link on ANOTHER client's asset for the second computer must not be shown.
        $foreign = Asset::factory()->create(['client_id' => $other->id, 'hostname' => 'LT-NEW-01',
            'autoelevate_computer_id' => self::uuid(2)]);

        Http::fake([self::BASE.'/api/v1/computers*' => Http::response(self::envelope([
            self::computer(['id' => self::uuid(1), 'machineName' => 'WS-FINANCE-04']),
            self::computer(['id' => self::uuid(2), 'machineName' => 'LT-NEW-01']),
        ]), 200)]);

        $this->get(route('clients.autoelevate.computers', $client))->assertOk()
            ->assertSee('<th>Linked asset</th>', false)
            ->assertSee('data-linked-asset="'.$mine->id.'"', false)
            ->assertSee(route('assets.show', $mine), false)
            ->assertDontSee('data-linked-asset="'.$foreign->id.'"', false)
            ->assertSee('data-linked-asset="none"', false);
    }

    /** linkedAssets() keys by lowercase id; the panel must look up the same way, whatever the id's case. */
    public function test_client_panel_finds_the_linked_asset_for_a_non_lowercase_computer_id(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $upper = strtoupper(self::uuid(5));
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'WS-UPPER',
            'autoelevate_computer_id' => $upper]);
        // Seed the panel's read cache so the rendered id is exactly the non-lowercase one.
        Cache::put('autoelevate_computers_'.strtolower(self::COMPANY_A), [[
            'id' => $upper, 'machine_name' => 'WS-UPPER', 'os_name' => null, 'os_version' => null,
            'elevation_mode' => null, 'elevation_mode_known' => false, 'last_checked_in_at' => null,
        ]], 60);

        Http::fake();
        $this->get(route('clients.autoelevate.computers', $client))->assertOk()
            ->assertSee('data-linked-asset="'.$asset->id.'"', false)
            ->assertDontSee('data-linked-asset="none"', false);
        Http::assertNothingSent();
    }
}
