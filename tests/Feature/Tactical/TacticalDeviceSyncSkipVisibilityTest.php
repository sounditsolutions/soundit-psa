<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #333 taught the sync to create assets for discovered agents, and gave it three
 * reasons to refuse: no hostname, a hostname another agent already owns, and a
 * hostname belonging to a soft-deleted asset. Each refusal is correct. Each was
 * also silent — the device ends up with no Asset, every Tactical UI surface hangs
 * off Asset, and the operator was told only how many assets were created.
 *
 * That is the original #333 complaint one layer up: a real machine, no record,
 * no message. These guards hold the skip counted and named.
 *
 * Two adjacent hazards are covered here for the same reason:
 *  - the link query ORs hostname against name and took ->first() with no
 *    ordering, so which asset a device adopted varied between runs;
 *  - OEM placeholder serials were written verbatim onto new assets, seeding a
 *    column where an entire fleet shares one value.
 */
class TacticalDeviceSyncSkipVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, array<string, mixed>>  $agents */
    private function syncService(array $agents): TacticalDeviceSyncService
    {
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], json_encode($agents))])),
            'timeout' => 30,
        ]);

        return new TacticalDeviceSyncService(new TacticalClient($http));
    }

    private function mappedClient(): Client
    {
        return Client::factory()->create(['tactical_site_id' => 'Acme|Main', 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agent(array $overrides = []): array
    {
        return array_merge([
            'agent_id' => 'AGENT-NEW',
            'hostname' => 'NEWBOX',
            'client_name' => 'Acme',
            'site_name' => 'Main',
            'status' => 'online',
            'operating_system' => 'Windows 11 Pro, 64 bit',
            'plat' => 'windows',
            'monitoring_type' => 'workstation',
            'serial_number' => 'SN-123',
            'cpu_model' => ['Intel Core i7-9700'],
            'physical_disks' => ['SAMSUNG SSD 500GB'],
            'local_ips' => '192.168.0.42',
            'logged_username' => 'zachary',
            'last_seen' => '2026-07-29 12:00:00',
            'needs_reboot' => true,
        ], $overrides);
    }

    public function test_reinstall_conflict_is_counted_and_named(): void
    {
        $client = $this->mappedClient();

        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'NEWBOX']);
        $old = TacticalAsset::create([
            'agent_id' => 'AGENT-OLD', 'hostname' => 'NEWBOX', 'asset_id' => $asset->id,
            'status' => 'offline', 'synced_at' => now()->subDay(),
        ]);
        $asset->update(['tactical_asset_id' => $old->id]);

        $result = $this->syncService([$this->agent()])->syncDevices();

        $this->assertSame(1, $result->details['assets_skipped'] ?? 0);
        $this->assertSame(1, $result->details['assets_skipped_reasons']['hostname_conflict'] ?? 0);
    }

    public function test_soft_deleted_conflict_is_counted_and_named(): void
    {
        $client = $this->mappedClient();

        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'NEWBOX']);
        $asset->delete();

        $result = $this->syncService([$this->agent()])->syncDevices();

        $this->assertSame(1, $result->details['assets_skipped'] ?? 0);
        $this->assertSame(1, $result->details['assets_skipped_reasons']['soft_deleted_conflict'] ?? 0);
    }

    /**
     * Both rows match the conflict lookup's OR, and which one is reported picks
     * the human remedy ('another agent owns this name' vs 'someone deleted this
     * deliberately'). It must not depend on storage order: the live, blocking
     * record wins. The trashed asset is created first here, so an id-only tie
     * break would report the wrong reason.
     */
    public function test_a_live_conflict_outranks_a_soft_deleted_one(): void
    {
        $client = $this->mappedClient();

        $trashed = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'NEWBOX']);
        $trashed->delete();

        $live = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'NEWBOX']);
        $old = TacticalAsset::create([
            'agent_id' => 'AGENT-OLD', 'hostname' => 'NEWBOX', 'asset_id' => $live->id,
            'status' => 'offline', 'synced_at' => now()->subDay(),
        ]);
        $live->update(['tactical_asset_id' => $old->id]);

        $result = $this->syncService([$this->agent()])->syncDevices();

        $this->assertSame(1, $result->details['assets_skipped_reasons']['hostname_conflict'] ?? 0);
        $this->assertArrayNotHasKey('soft_deleted_conflict', $result->details['assets_skipped_reasons'] ?? []);
    }

    public function test_agent_without_a_hostname_is_counted_and_named(): void
    {
        $this->mappedClient();

        $result = $this->syncService([$this->agent(['hostname' => null])])->syncDevices();

        $this->assertSame(1, $result->details['assets_skipped'] ?? 0);
        $this->assertSame(1, $result->details['assets_skipped_reasons']['no_hostname'] ?? 0);
    }

    public function test_a_clean_create_reports_no_skips(): void
    {
        $this->mappedClient();

        $result = $this->syncService([$this->agent()])->syncDevices();

        $this->assertSame(1, $result->details['assets_created'] ?? 0);
        $this->assertArrayNotHasKey('assets_skipped', $result->details);
    }

    public function test_two_agents_sharing_a_hostname_yield_one_asset_and_one_named_skip(): void
    {
        // The red-check case: two discovered machines answering to the same
        // hostname must not silently collapse into one tracked device. One is
        // created, the other is refused — and the refusal is visible.
        $this->mappedClient();

        $result = $this->syncService([
            $this->agent(['agent_id' => 'AGENT-A', 'serial_number' => 'To be filled by O.E.M.']),
            $this->agent(['agent_id' => 'AGENT-B', 'serial_number' => 'To be filled by O.E.M.']),
        ])->syncDevices();

        $this->assertSame(1, Asset::count(), 'two same-hostname agents must not fork or merge into an untracked state');
        $this->assertSame(1, $result->details['assets_created'] ?? 0);
        $this->assertSame(1, $result->details['assets_skipped'] ?? 0);
        $this->assertSame(2, TacticalAsset::count(), 'both agents stay tracked as agent rows');
    }

    public function test_exact_hostname_match_outranks_a_name_only_match(): void
    {
        $client = $this->mappedClient();

        // Both are live and unlinked, so both satisfy the OR in the link query.
        // Without ordering, which one adopted the agent was arbitrary.
        $nameOnly = Asset::factory()->create([
            'client_id' => $client->id, 'name' => 'NEWBOX', 'hostname' => 'SOMETHING-ELSE',
        ]);
        $exact = Asset::factory()->create([
            'client_id' => $client->id, 'name' => 'Reception PC', 'hostname' => 'NEWBOX',
        ]);

        $this->syncService([$this->agent()])->syncDevices();

        $this->assertSame(
            $exact->id,
            TacticalAsset::where('agent_id', 'AGENT-NEW')->value('asset_id'),
            'the exact hostname match must win regardless of insertion order'
        );
        $this->assertNull($nameOnly->fresh()->tactical_asset_id);
    }

    /**
     * @dataProvider placeholderSerials
     */
    public function test_placeholder_serials_are_stored_as_null(string $reported): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent(['serial_number' => $reported])])->syncDevices();

        $this->assertNull(
            Asset::where('hostname', 'NEWBOX')->value('serial_number'),
            "placeholder serial '{$reported}' must not reach the asset record"
        );
    }

    /** @return array<string, array<int, string>> */
    public static function placeholderSerials(): array
    {
        return [
            'oem punctuated' => ['To be filled by O.E.M.'],
            'oem unpunctuated' => ['To Be Filled By OEM'],
            'default string' => ['Default string'],
            'system serial' => ['System Serial Number'],
            'not specified' => ['Not Specified'],
            'none' => ['None'],
            'zeros' => ['00000000'],
            'whitespace' => ['   '],
        ];
    }

    public function test_a_real_serial_is_preserved(): void
    {
        $this->mappedClient();

        $this->syncService([$this->agent(['serial_number' => '5CD1234ABC'])])->syncDevices();

        $this->assertSame('5CD1234ABC', Asset::where('hostname', 'NEWBOX')->value('serial_number'));
    }

    /**
     * The skip count is flashed as 'warning', and layouts/app.blade.php renders
     * only success and error. Without a block on this page the whole message is
     * dropped — a fix nobody can see. Exactly once, for the same reason MF6
     * pins the invoice warning: layout and page-local blocks must not both fire.
     */
    public function test_the_integrations_page_renders_a_flashed_warning_exactly_once(): void
    {
        $needle = 'could not be linked to an asset';

        $html = $this->actingAs(User::factory()->create())
            ->withSession(['warning' => "1 device {$needle}, so its Tactical data is not shown anywhere — see the sync log."])
            ->get(route('settings.integrations'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, $needle));
    }
}
