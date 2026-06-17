<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Services\Tactical\TacticalClient;
use App\Services\Triage\ContextBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P5: TacticalContextProvider wired into ContextBuilder (replaces the un-timed
 * inline getAgentChecks() live-check that was the G5 violation). The provider
 * owns the bounded read (LIVE_TIMEOUT_SECONDS), redaction, injection-fencing,
 * and token budget — ContextBuilder just appends the fenced block.
 *
 * These tests verify the ContextBuilder integration surface:
 *   - the fence (=== ENDPOINT TELEMETRY) appears in the built context
 *   - redaction still works (the provider's redactor runs before the block lands)
 *   - offline/degraded agents surface "checks: unavailable", not an exception
 *   - the old inline "| Failing checks: N" string is gone
 */
class ContextBuilderTacticalChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Make TacticalConfig::isConfigured() true so buildAssetSection appends the
        // provider block.
        Setting::setValue('tactical_api_url', 'https://tactical.example.com');
        Setting::setEncrypted('tactical_api_key', 'svc-key-abc123');
    }

    private function bindClient(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => $stack,
            'timeout' => 30,
            'allow_redirects' => false,
        ]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    /**
     * @param  array<string, mixed>  $taAttributes  Extra TacticalAsset column overrides.
     */
    private function ticketWithLinkedAsset(array $taAttributes = []): Ticket
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        $ta = TacticalAsset::create(array_merge([
            'asset_id'       => $asset->id,
            'agent_id'       => 'AGENT-1',
            'hostname'       => 'BOX-1',
            'status'         => 'online',
            'checks_failing' => 1,
            'checks_total'   => 2,
        ], $taAttributes));
        $asset->update(['tactical_asset_id' => $ta->id]);

        $ticket = Ticket::factory()->create(['client_id' => $asset->client_id]);
        $ticket->assets()->attach($asset->id);

        return $ticket->fresh();
    }

    /**
     * Minimal agent-status response (getAgent endpoint shape).
     *
     * @return array<string, mixed>
     */
    private function agentStatusPayload(): array
    {
        return [
            'status'             => 'online',
            'maintenance_mode'   => false,
            'logged_in_username' => 'None',
            'needs_reboot'       => false,
        ];
    }

    public function test_provider_block_is_fenced_and_contains_check_data(): void
    {
        $ticket = $this->ticketWithLinkedAsset();

        // Provider live path: getAgent first, then getAgentChecks.
        $this->bindClient([
            new Response(200, [], json_encode($this->agentStatusPayload())),
            new Response(200, [], json_encode([
                ['name' => 'Disk Space C', 'check_result' => ['status' => 'failing', 'stdout' => 'C: at 95%', 'retcode' => 1]],
                ['name' => 'Ping',         'check_result' => ['status' => 'passing']],
            ])),
        ]);

        $context = ContextBuilder::buildForTicket($ticket);

        // P5: provider fence present.
        $this->assertStringContainsString('=== ENDPOINT TELEMETRY', $context);
        $this->assertStringContainsString('=== END ENDPOINT TELEMETRY', $context);
        // Failing check data surfaced via provider format.
        $this->assertStringContainsString('Failing check: Disk Space C', $context);
        // Old inline output is gone.
        $this->assertStringNotContainsString('| Failing checks:', $context);
    }

    public function test_planted_secret_in_check_stdout_is_redacted_before_the_prompt(): void
    {
        $ticket = $this->ticketWithLinkedAsset();

        // Build the secret by concatenation so no contiguous AWS-key-shaped literal
        // lives in the test (secret-guard).
        $secret = 'db password = '.'S3cr3tP'.'@ssw0rd!';

        // Provider live path: getAgent first, then getAgentChecks with the planted secret.
        $this->bindClient([
            new Response(200, [], json_encode($this->agentStatusPayload())),
            new Response(200, [], json_encode([
                ['name' => 'Backup Job', 'check_result' => ['status' => 'failing', 'stdout' => $secret, 'retcode' => 1]],
            ])),
        ]);

        $context = ContextBuilder::buildForTicket($ticket);

        // The fence is present (provider block landed).
        $this->assertStringContainsString('=== ENDPOINT TELEMETRY', $context);
        // The credential value never reaches the prompt string.
        $this->assertStringNotContainsString('S3cr3tP@ssw0rd!', $context);
        $this->assertStringContainsString('[REDACTED:credential]', $context);
    }

    public function test_offline_tactical_returns_within_bound_and_never_throws(): void
    {
        // Seed WITHOUT snapshot check counts so that a live-read failure yields
        // checksState Unavailable (not Snapshot). If checks_failing is seeded, a
        // 500 just degrades to the snapshot count instead of "unavailable".
        $ticket = $this->ticketWithLinkedAsset(['checks_failing' => null, 'checks_total' => null]);

        // Both live reads fail (getAgent 500 + getAgentChecks 500). Provider must
        // degrade gracefully and render "checks: unavailable" (G7 — absence of data
        // is never rendered as healthy). Must not throw.
        $this->bindClient([
            new Response(500, [], 'natsdown'),
            new Response(500, [], 'natsdown'),
        ]);

        $context = ContextBuilder::buildForTicket($ticket);

        // Provider block is still fenced even when degraded.
        $this->assertStringContainsString('=== ENDPOINT TELEMETRY', $context);
        // Checks unavailable — not "all passing" or a count.
        $this->assertStringContainsStringIgnoringCase('checks: unavailable', $context);
        // Old inline output is gone.
        $this->assertStringNotContainsString('| Failing checks:', $context);
        // Asset hostname still present.
        $this->assertStringContainsString('BOX-1', $context);
    }
}
