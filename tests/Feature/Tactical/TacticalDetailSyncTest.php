<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Amendment B (P4): syncDeviceDetail() reads the agent DETAIL (getAgent) and
 * writes the columns the daily list-sync leaves unfilled — ram_gb / os_version —
 * plus refreshes status/last_seen/synced_at and the checks_failing/checks_total
 * summary. A fetch failure leaves the prior snapshot intact and returns a clear
 * result (no exception to the caller).
 */
class TacticalDetailSyncTest extends TestCase
{
    use RefreshDatabase;

    private function syncService(array $queue): TacticalDeviceSyncService
    {
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create(new MockHandler($queue)),
            'timeout' => 30,
            'allow_redirects' => false,
        ]);

        return new TacticalDeviceSyncService(new TacticalClient($http));
    }

    private function linkedAsset(array $taOverrides = []): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        TacticalAsset::create(array_merge([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'status' => 'offline',
            'ram_gb' => null,
            'os_version' => null,
            'checks_failing' => null,
            'checks_total' => null,
            'synced_at' => now()->subDay(),
        ], $taOverrides));

        return $asset->refresh();
    }

    public function test_detail_sync_writes_ram_gb_and_os_version(): void
    {
        $asset = $this->linkedAsset();

        $service = $this->syncService([
            new Response(200, [], json_encode([
                'total_ram' => 16, // GB integer (Tactical reports total_ram in GB)
                'operating_system' => 'Windows 11 Pro, 23H2 (build 22631)',
                'status' => 'online',
                'last_seen' => '2026-06-16 01:00:00',
                // getAgent `checks` is the SUMMARY DICT, not a list of checks.
                'checks' => ['total' => 3, 'passing' => 2, 'failing' => 1, 'warning' => 0, 'info' => 0, 'has_failing_checks' => true],
            ])),
        ]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertTrue($result->ok);

        $ta = $asset->tacticalAsset->refresh();
        $this->assertSame('16.0', (string) $ta->ram_gb);
        $this->assertSame('Windows 11 Pro, 23H2 (build 22631)', $ta->os_version);
        $this->assertSame('online', $ta->status);
        $this->assertSame(1, $ta->checks_failing);
        $this->assertSame(3, $ta->checks_total);
        $this->assertNotNull($ta->synced_at);
        $this->assertTrue($ta->synced_at->gt(now()->subMinute()));
    }

    public function test_detail_sync_failure_leaves_prior_snapshot_intact(): void
    {
        $asset = $this->linkedAsset([
            'ram_gb' => 8.0,
            'os_version' => 'Windows 10 Pro',
            'status' => 'online',
            'checks_failing' => 2,
            'checks_total' => 5,
        ]);
        $priorSyncedAt = $asset->tacticalAsset->synced_at;

        // A 500 => the detail read throws inside the service; it must be caught.
        $service = $this->syncService([
            new Response(500, [], 'upstream boom'),
        ]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertFalse($result->ok);
        $this->assertNotEmpty($result->message);

        // Prior snapshot values untouched.
        $ta = $asset->tacticalAsset->refresh();
        $this->assertSame('8.0', (string) $ta->ram_gb);
        $this->assertSame('Windows 10 Pro', $ta->os_version);
        $this->assertSame(2, $ta->checks_failing);
        $this->assertSame($priorSyncedAt->toDateTimeString(), $ta->synced_at->toDateTimeString());
    }

    public function test_detail_sync_on_unlinked_asset_returns_clear_result(): void
    {
        $asset = Asset::factory()->create(['hostname' => 'NOLINK']);

        // No live call should be made; no exception.
        $service = $this->syncService([]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertFalse($result->ok);
        $this->assertNotEmpty($result->message);
    }

    public function test_detail_sync_uses_a_short_timeout(): void
    {
        $asset = $this->linkedAsset();

        $captured = [];
        $handler = function (\Psr\Http\Message\RequestInterface $request, array $options) use (&$captured) {
            $captured[] = $options['timeout'] ?? null;

            return \GuzzleHttp\Promise\Create::promiseFor(new Response(200, [], json_encode([
                'total_ram' => 8, // GB integer
                'operating_system' => 'Windows 11',
                'status' => 'online',
            ])));
        };
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create($handler),
            'timeout' => 30,
            'allow_redirects' => false,
        ]);
        $service = new TacticalDeviceSyncService(new TacticalClient($http));

        $service->syncDeviceDetail($asset);

        $this->assertNotEmpty($captured);
        $this->assertNotNull($captured[0]);
        $this->assertLessThanOrEqual(5, $captured[0]);
    }

    // ── Daily list-sync defensive checks-summary persistence (amendment B) ──

    public function test_list_sync_persists_checks_summary_when_payload_carries_it(): void
    {
        // Per amendment B: the agent-list payload carries a per-agent checks
        // SUMMARY DICT (source v1.5.0 + live VM 105), so the daily sync persists
        // failing/total straight off it — the card health line is snapshot-fresh
        // from the daily run, no per-agent detail fan-out.
        $client = \App\Models\Client::factory()->create([
            'tactical_site_id' => 'Acme|Main',
            'is_active' => true,
        ]);

        $service = $this->syncService([
            new Response(200, [], json_encode([[
                'agent_id' => 'AGENT-LIST',
                'hostname' => 'LISTBOX',
                'client_name' => 'Acme',
                'site_name' => 'Main',
                'status' => 'online',
                'checks' => ['total' => 6, 'passing' => 4, 'failing' => 2, 'warning' => 0, 'info' => 0],
            ]])),
        ]);

        $service->syncDevices();

        $ta = TacticalAsset::where('agent_id', 'AGENT-LIST')->first();
        $this->assertNotNull($ta);
        $this->assertSame(2, $ta->checks_failing);
        $this->assertSame(6, $ta->checks_total);
    }

    public function test_list_sync_leaves_checks_null_when_payload_lacks_it(): void
    {
        // Defensive guard: if a payload ever omits the checks dict, the columns
        // stay null (Unavailable) rather than being written as "0 clean".
        $client = \App\Models\Client::factory()->create([
            'tactical_site_id' => 'Acme|Main',
            'is_active' => true,
        ]);

        $service = $this->syncService([
            new Response(200, [], json_encode([[
                'agent_id' => 'AGENT-NOCHK',
                'hostname' => 'NOCHKBOX',
                'client_name' => 'Acme',
                'site_name' => 'Main',
                'status' => 'online',
            ]])),
        ]);

        $service->syncDevices();

        $ta = TacticalAsset::where('agent_id', 'AGENT-NOCHK')->first();
        $this->assertNotNull($ta);
        $this->assertNull($ta->checks_failing);
        $this->assertNull($ta->checks_total);
    }
}
