<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Setting;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Tests for TacticalClient::getMeshCentralLinks (P6 Task 1, unit)
 * and AssetController::openTacticalMeshCentral (P6 Task 2, feature).
 *
 * Unit tests use injected-MockHandler directly on TacticalClient so SSRF-pin
 * middleware is NOT active (no real DNS lookup).
 * Controller tests bind TacticalClient via the service container and exercise
 * the full HTTP layer: audit logging, Cache-Control, status codes.
 */
class TacticalMeshCentralTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'svc-user-api-key-abc123';

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('tactical_api_url', 'https://tactical.example.com');
        Setting::setEncrypted('tactical_api_key', 'svc-key-abc123');
    }

    // ── unit helpers ──────────────────────────────────────────────────────────

    /**
     * Build a TacticalClient over an injected mock transport.
     *
     * @param  Response[]  $queue
     */
    private function clientReturning(array $queue): TacticalClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $mockHttp = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => $stack,
            'allow_redirects' => false,
            'headers' => [
                'X-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        return new TacticalClient($mockHttp);
    }

    // ── controller helpers ────────────────────────────────────────────────────

    /**
     * Create an authenticated user + an asset with a linked TacticalAsset.
     *
     * @return array{0: User, 1: Asset}
     */
    private function authedUserWithTacticalAsset(string $agentId = 'AGENT-1'): array
    {
        $user  = User::factory()->create();
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => $agentId,
            'hostname'  => 'BOX-1',
            'status'    => 'online',
            'synced_at' => now(),
        ]);

        return [$user, $asset->refresh()];
    }

    /**
     * Bind TacticalClient to a mock that returns the given links payload.
     *
     * @param  array<string,string>  $links  e.g. ['control'=>'https://...']
     */
    private function bindTacticalClientReturning(array $links): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(array_merge(['hostname' => 'BOX-1'], $links))),
        ]));
        $stack->push(Middleware::history($this->history));
        $http = new GuzzleClient(['base_uri' => 'https://tactical.example.com/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    /**
     * Bind TacticalClient to a mock that throws TacticalClientException.
     */
    private function bindTacticalClientThrowing(): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([
            new ConnectException('connection refused', new GuzzleRequest('GET', 'agents/AGENT-1/meshcentral/')),
        ]));
        $stack->push(Middleware::history($this->history));
        $http = new GuzzleClient(['base_uri' => 'https://tactical.example.com/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    // ── unit tests: TacticalClient::getMeshCentralLinks ─────────────────────

    public function test_getMeshCentralLinks_hits_the_agent_meshcentral_endpoint(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode([
                'hostname' => 'BOX',
                'control'  => 'https://mesh.example.com/?login=ctrl',
                'terminal' => 'https://mesh.example.com/?login=term',
                'file'     => 'https://mesh.example.com/?login=file',
            ])),
        ]);

        $links = $client->getMeshCentralLinks('AGENT-1');

        $this->assertSame('https://mesh.example.com/?login=ctrl', $links['control']);
        $this->assertStringContainsString(
            'agents/AGENT-1/meshcentral',
            (string) $this->history[0]['request']->getUri()
        );
    }

    public function test_getMeshCentralLinks_returns_all_fields(): void
    {
        $payload = [
            'hostname' => 'WORKSTATION-42',
            'control'  => 'https://mesh.example.com/?login=ctrl',
            'terminal' => 'https://mesh.example.com/?login=term',
            'file'     => 'https://mesh.example.com/?login=file',
            'status'   => 'online',
            'client'   => 'Acme Corp',
            'site'     => 'Main',
        ];

        $client = $this->clientReturning([
            new Response(200, [], json_encode($payload)),
        ]);

        $links = $client->getMeshCentralLinks('AGENT-42');

        $this->assertSame($payload, $links);
    }

    public function test_getMeshCentralLinks_uses_get_verb(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['hostname' => 'BOX'])),
        ]);

        $client->getMeshCentralLinks('AGENT-1');

        $this->assertSame('GET', $this->history[0]['request']->getMethod());
    }

    // ── controller tests: AssetController::openTacticalMeshCentral ───────────

    public function test_open_meshcentral_returns_url_audits_and_sets_no_store(): void
    {
        [$user, $asset] = $this->authedUserWithTacticalAsset(agentId: 'AGENT-1');
        $this->bindTacticalClientReturning(['control' => 'https://mesh.example.com/?login=tok']);

        $res = $this->actingAs($user)
            ->postJson("/assets/{$asset->id}/tactical/meshcentral", ['type' => 'control']);

        $res->assertOk()
            ->assertJsonPath('url', 'https://mesh.example.com/?login=tok')
            ->assertHeader('Cache-Control', 'no-store, private');   // Laravel appends 'private'

        $log = TacticalActionLog::sole();
        $this->assertSame('tactical.remote_control', $log->action_key);
        $this->assertSame('control', $log->params['link_type']);
        $this->assertSame('ok', $log->result_status);
        // G2: URL/token must NEVER appear in any audit column
        $this->assertStringNotContainsString('login=', json_encode($log->getAttributes()));
        $this->assertStringNotContainsString('mesh.example.com', json_encode($log->getAttributes()));
    }

    public function test_open_meshcentral_audits_failures_without_logging_a_url(): void
    {
        [$user, $asset] = $this->authedUserWithTacticalAsset(agentId: 'AGENT-1');
        $this->bindTacticalClientThrowing();

        $res = $this->actingAs($user)
            ->postJson("/assets/{$asset->id}/tactical/meshcentral", ['type' => 'terminal']);

        $res->assertStatus(502);
        $this->assertSame('error', TacticalActionLog::sole()->result_status);
    }

    public function test_open_meshcentral_422_when_url_is_not_https(): void
    {
        [$user, $asset] = $this->authedUserWithTacticalAsset(agentId: 'AGENT-1');
        // Tactical returns a non-https URL (http:// or empty)
        $this->bindTacticalClientReturning(['control' => 'http://mesh.example.com/?login=tok']);

        $res = $this->actingAs($user)
            ->postJson("/assets/{$asset->id}/tactical/meshcentral", ['type' => 'control']);

        $res->assertStatus(422);
        $log = TacticalActionLog::sole();
        $this->assertSame('error', $log->result_status);
        // The non-https URL must not appear in audit
        $this->assertStringNotContainsString('login=', json_encode($log->getAttributes()));
        $this->assertStringNotContainsString('mesh.example.com', json_encode($log->getAttributes()));
    }

    public function test_open_meshcentral_422_when_asset_has_no_tactical_link(): void
    {
        $user  = User::factory()->create();
        $asset = Asset::factory()->create(['hostname' => 'NOLINK']);

        $res = $this->actingAs($user)
            ->postJson("/assets/{$asset->id}/tactical/meshcentral", ['type' => 'control']);

        $res->assertStatus(422);
        // No audit row — no agent_id to anchor it
        $this->assertDatabaseCount('tactical_action_logs', 0);
    }

    public function test_open_meshcentral_422_on_invalid_type(): void
    {
        [$user, $asset] = $this->authedUserWithTacticalAsset();

        $res = $this->actingAs($user)
            ->postJson("/assets/{$asset->id}/tactical/meshcentral", ['type' => 'vnc']);

        $res->assertStatus(422);
    }

    public function test_open_meshcentral_records_ticket_id_on_audit_row(): void
    {
        [$user, $asset] = $this->authedUserWithTacticalAsset(agentId: 'AGENT-1');
        $this->bindTacticalClientReturning(['control' => 'https://mesh.example.com/?login=tok']);
        $ticket = Ticket::factory()->create();

        $res = $this->actingAs($user)
            ->postJson("/assets/{$asset->id}/tactical/meshcentral", [
                'type'      => 'control',
                'ticket_id' => $ticket->id,
            ]);

        $res->assertOk();
        $log = TacticalActionLog::sole();
        $this->assertSame($ticket->id, $log->ticket_id);
    }

    public function test_open_meshcentral_requires_auth(): void
    {
        $asset = Asset::factory()->create();

        $this->postJson("/assets/{$asset->id}/tactical/meshcentral", ['type' => 'control'])
            ->assertStatus(401);
    }

    public function test_open_meshcentral_route_has_throttle_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('assets.tactical-meshcentral');
        $this->assertNotNull($route, 'Route assets.tactical-meshcentral must be registered');
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
        $this->assertContains('POST', $route->methods());
    }
}
