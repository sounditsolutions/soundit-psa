<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalContextProvider;
use App\Services\Tactical\TacticalInsightService;
use App\Services\Wiki\Mining\WikiRedactor;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TacticalContextProviderTest extends TestCase
{
    use RefreshDatabase;

    private function provider(array $responses): TacticalContextProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $client = new TacticalClient(new Client(['handler' => $stack]));

        return new TacticalContextProvider(new TacticalInsightService($client), new WikiRedactor);
    }

    public function test_returns_null_for_a_non_tactical_asset(): void
    {
        $asset = Asset::factory()->create(); // no tactical_asset link
        $this->assertNull($this->provider([])->forAsset($asset));
    }

    public function test_redacts_a_secret_planted_in_failing_check_stdout(): void
    {
        [$asset] = $this->seedTacticalAssetWithFailingCheck(
            stdout: 'connecting with password=SuperSecret123 to db'
        );
        // status + checks live reads (TacticalInsightService::forAsset live: true)
        $block = $this->provider([
            new Response(200, [], json_encode($this->agentStatusPayload())),
            new Response(200, [], json_encode($this->failingChecksPayload('password=SuperSecret123'))),
        ])->forAsset($asset);

        $this->assertNotNull($block);
        $this->assertStringNotContainsString('SuperSecret123', $block->text);
        $this->assertStringContainsString('[REDACTED:credential]', $block->text);
    }

    // ── Fixture helpers (same tactical_assets row + agent/checks JSON shapes as TacticalInsightServiceTest) ──

    /**
     * Seed a TacticalAsset with one failing check whose stdout contains the given
     * string. Returns [$asset] so callers can spread-assign.
     *
     * @return array{0: Asset}
     */
    private function seedTacticalAssetWithFailingCheck(string $stdout): array
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-CTX']);
        TacticalAsset::create([
            'asset_id'          => $asset->id,
            'agent_id'          => 'AGENT-CTX',
            'hostname'          => 'BOX-CTX',
            'os'                => 'Windows 11 Pro',
            'cpu'               => 'Intel i7',
            'ram_gb'            => 16.0,
            'disk_summary'      => 'C: 256GB',
            'status'            => 'online',
            'needs_reboot'      => false,
            'has_patches_pending' => false,
            'checks_failing'    => 1,
            'checks_total'      => 3,
            'last_seen_at'      => now()->subMinutes(5),
            'synced_at'         => now()->subMinutes(10),
        ]);

        return [$asset->refresh()];
    }

    /**
     * Minimal agent-status response body for the getAgent endpoint.
     *
     * @return array<string, mixed>
     */
    private function agentStatusPayload(): array
    {
        return [
            'status'              => 'online',
            'maintenance_mode'    => false,
            'logged_in_username'  => 'None',
            'needs_reboot'        => false,
        ];
    }

    /**
     * Checks response body with a single failing check whose stdout contains the
     * given credential string. Matches the getAgentChecks shape used throughout
     * the Tactical feature test suite.
     *
     * @return array<int, array<string, mixed>>
     */
    private function failingChecksPayload(string $stdout): array
    {
        return [
            [
                'name'         => 'DB Connection Check',
                'check_result' => [
                    'status'  => 'failing',
                    'retcode' => 1,
                    'stdout'  => $stdout,
                ],
            ],
        ];
    }
}
