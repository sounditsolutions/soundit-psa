<?php

namespace Tests\Feature\Tactical;

use App\Http\Controllers\Web\AssetController;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Services\AssetHealthService;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalFieldMap;
use App\Services\Tactical\TacticalReadOnlyToolset;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UptimeProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public static function statuses(): array
    {
        return [
            'overdue' => ['overdue', 'unverified'],
            'online' => ['online', 'vendor_reported'],
            'missing' => [null, 'unverified'],
            'offline' => ['offline', 'unverified'],
            'unknown' => ['Online', 'unverified'],
        ];
    }

    private function asset(): Asset
    {
        $this->travelTo(now()->setDate(2026, 9, 24)->startOfDay());

        return Asset::factory()->create([
            'client_id' => Client::factory()->create()->id,
            'hostname' => 'synthetic-uptime',
            'last_boot_at' => now()->subDays(42),
            'needs_reboot' => false,
            'ninja_id' => null,
            'level_id' => null,
        ]);
    }

    #[DataProvider('statuses')]
    public function test_live_read_surfaces_carry_provenance(?string $status, string $state): void
    {
        $asset = $this->asset();
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'synthetic');
        TacticalAsset::create(['asset_id' => $asset->id, 'agent_id' => 'synthetic-agent', 'hostname' => $asset->hostname]);
        // Field projection from AgentSerializer / AgentTableSerializer at
        // tacticalrmm e56ebd3e48e99de59f34d4bd7600c127a6f02cf2,
        // api/tacticalrmm/agents/serializers.py: status, last_seen, boot_time.
        // Values are synthetic; boot_time is epoch seconds, not a report clock.
        $agent = ['boot_time' => now()->subDays(42)->timestamp, 'last_seen' => now()->subHours(2)->toIso8601String()];
        if ($status !== null) {
            $agent['status'] = $status;
        }
        $api = Mockery::mock(TacticalClient::class);
        $api->shouldReceive('getAgent')->twice()->with('synthetic-agent')->andReturn($agent);
        $this->app->instance(TacticalClient::class, $api);
        $ticket = Ticket::factory()->create(['client_id' => $asset->client_id]);
        $results = [
            app(TacticalReadOnlyToolset::class)->execute('tactical_get_device', ['hostname' => $asset->hostname], $asset->client_id),
            (new TriageToolExecutor($ticket))->execute('tactical_get_device', ['hostname' => $asset->hostname]),
        ];
        foreach ($results as $result) {
            $this->assertArrayNotHasKey('error', $result);
            $this->assertSame('42d', $result['uptime']);
            $this->assertSame($state, $result['uptime_state'], 'Overdue/missing status must not claim vendor_reported uptime');
            $this->assertSame($agent['boot_time'], $result['boot_time']);
            $this->assertSame($status, $result['agent_status']);
            $this->assertSame($agent['last_seen'], $result['agent_last_seen']);
            $this->assertNotSame('verified', $result['uptime_state']);
            $this->assertStringContainsString($status === 'online' ? 'periodic info report' : $agent['last_seen'], $result['freshness_note']);
            $this->assertArrayNotHasKey('boot_time_as_of', $result);
        }
    }

    public static function storedStatuses(): array
    {
        return [
            ...self::statuses(),
            'stale online' => ['online', 'unverified', 120],
            'undated online' => ['online', 'unverified', null],
        ];
    }

    #[DataProvider('storedStatuses')]
    public function test_stored_read_surfaces_use_stored_tactical_status(?string $status, string $state, ?int $ageMinutes = 0): void
    {
        $asset = $this->asset();
        if ($status !== null) {
            TacticalAsset::create([
                'asset_id' => $asset->id, 'agent_id' => 'synthetic-agent',
                'hostname' => $asset->hostname, 'status' => $status,
                'last_seen_at' => now()->subHours(2),
                'synced_at' => $ageMinutes !== null ? now()->subMinutes($ageMinutes) : null,
            ]);
        }
        $expected = TacticalFieldMap::storedUptime($asset);
        $this->assertSame($state, $expected['uptime_state'], 'Stored online needs a recent sync; stale or undated status must be unverified');
        $results = [
            (new AssistantToolExecutor(clientId: $asset->client_id))->execute('get_asset', ['asset_id' => $asset->id]),
            app(AssetController::class)->quickLook($asset)->getData(true),
        ];
        foreach ($results as $result) {
            foreach ($expected as $key => $value) {
                $this->assertSame($value, $result[$key], $key);
            }
        }
        $this->actingAs(\App\Models\User::factory()->create())
            ->get(route('assets.show', $asset))
            ->assertOk()
            ->assertSee($state)
            ->assertSee($expected['freshness_note']);
        $factor = collect(app(AssetHealthService::class)->compute($asset)->factors)->firstWhere('key', 'patch');
        $this->assertNotNull($factor);
        $this->assertStringContainsString($state, $factor['detail']);
        $this->assertStringContainsString($expected['freshness_note'], $factor['detail']);
        $this->assertStringNotContainsString('up 42d', $factor['detail']);
    }
}
