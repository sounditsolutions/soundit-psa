<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TechnicianActionLog;
use App\Models\User;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #3929 remainder: the duplicate arm of StaffTacticalActionToolExecutor::executeBusTool().
 *
 * That arm is shared by every bus tool. For tactical_set_patch_action,
 * prepareArgumentsForTool() reads the agent's Windows updates before the
 * duplicate check, and for the four service tools it reads the agent's
 * services; so "no upstream call was made" was false on those paths. The arm
 * now names the tool that was not sent. Each case runs one call twice through
 * execute() and asserts on the SECOND call: the exact message, the exact
 * request log and the audit summary. The first call must record the tool's
 * own write, so the recorder is shown to see traffic before the second call
 * is asserted to send none.
 *
 * TacticalClient takes an injected Guzzle client; a routing handler answers by
 * method and path and Guzzle's history middleware records every request.
 */
class TacticalActionAlreadyWordingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://tactical.invalid/';

    /** @var array<int,mixed> */
    private array $history = [];

    /** @var list<string> */
    private array $unrouted = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('tactical_api_url', 'https://tactical.invalid');
        Setting::setEncrypted('tactical_api_key', 'test-key');
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $handler = function (RequestInterface $request) {
            $method = $request->getMethod();
            $path = ltrim($request->getUri()->getPath(), '/');
            if ($method !== 'GET') {
                return Create::promiseFor(new Response(200, [], json_encode('ok')));
            }
            $routes = [
                'winupdate/agent-1/' => [['id' => 55, 'kb' => 'KB5030211', 'guid' => 'g-55', 'title' => 'Cumulative update']],
                'services/agent-1/' => [['name' => 'Spooler', 'display_name' => 'Print Spooler']],
            ];
            if (array_key_exists($path, $routes)) {
                return Create::promiseFor(new Response(200, [], json_encode($routes[$path])));
            }
            $this->unrouted[] = "{$method} {$path}";

            return Create::promiseFor(new Response(599, [], '{}'));
        };
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));
        $guzzle = new GuzzleClient(['handler' => $stack, 'base_uri' => self::BASE]);
        $this->app->bind(TacticalClient::class, fn () => new TacticalClient($guzzle));
    }

    /** @return list<string> */
    private function requests(): array
    {
        return array_map(
            fn (array $t) => $t['request']->getMethod().' '.(string) $t['request']->getUri(),
            $this->history,
        );
    }

    private int $assetId = 0;

    private function clientId(): int
    {
        $client = Client::factory()->create(['name' => 'Acme']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'PC-01', 'name' => 'PC-01']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-1',
            'hostname' => 'PC-01',
            'plat' => 'windows',
            'status' => 'online',
            'synced_at' => now(),
        ]);
        $this->assetId = $asset->id;

        return $client->id;
    }

    private function assertDuplicate(string $tool, array $args, string $firstWrite, array $secondRequests, string $message, string $audit): void
    {
        $clientId = $this->clientId();
        $args = ['hostname' => 'PC-01', 'reason' => 'wording test'] + $args;
        $executor = app(StaffTacticalActionToolExecutor::class);

        $first = $executor->execute($tool, $args, $clientId, 'wording-test');
        $this->assertTrue($first['success'] ?? false, "{$tool}: first call must execute: ".json_encode($first));
        $this->assertContains($firstWrite, $this->requests(), "{$tool}: the recorder must see the first call's write");

        $this->history = [];
        $second = $executor->execute($tool, $args, $clientId, 'wording-test');
        $this->assertTrue($second['idempotent'] ?? false, "{$tool}: second call must reach the duplicate arm: ".json_encode($second));
        $this->assertSame($secondRequests, $this->requests(), "{$tool}: the second call sends exactly these requests");
        $this->assertSame([], $this->unrouted, "{$tool}: every request must be routed");
        $this->assertSame($message, $second['message'] ?? null, "{$tool}: duplicate message");

        $row = TechnicianActionLog::query()->where('action_type', $tool)->where('result_status', 'blocked')->latest('id')->first();
        $this->assertNotNull($row, "{$tool}: the duplicate must be audited as blocked");
        $this->assertSame("asset #{$this->assetId} (PC-01): {$audit}", (string) $row->summary, "{$tool}: audit summary");
    }

    public function test_patch_action_duplicate_names_the_action_not_sent_after_its_patch_read(): void
    {
        $this->assertDuplicate(
            'tactical_set_patch_action', ['kb' => 'KB5030211', 'action' => 'approve'],
            'PUT '.self::BASE.'winupdate/55/',
            ['GET '.self::BASE.'winupdate/agent-1/'],
            'Already executed identical Tactical action recently; no tactical_set_patch_action was sent.',
            'Duplicate tactical_set_patch_action suppressed; no tactical_set_patch_action was sent.',
        );
    }

    public function test_service_duplicate_names_the_action_not_sent_after_its_service_read(): void
    {
        $this->assertDuplicate(
            'tactical_start_service', ['service_name' => 'Print Spooler'],
            'POST '.self::BASE.'services/agent-1/Spooler/',
            ['GET '.self::BASE.'services/agent-1/'],
            'Already executed identical Tactical action recently; no tactical_start_service was sent.',
            'Duplicate tactical_start_service suppressed; no tactical_start_service was sent.',
        );
    }

    /**
     * Positive control for the recorder's zeros: a bus tool with no preparation
     * read sends nothing on the duplicate, and the same arm's text holds there.
     */
    public function test_scan_duplicate_sends_nothing_and_keeps_the_same_arm_text(): void
    {
        $this->assertDuplicate(
            'tactical_scan_patches', [],
            'POST '.self::BASE.'winupdate/agent-1/scan/',
            [],
            'Already executed identical Tactical action recently; no tactical_scan_patches was sent.',
            'Duplicate tactical_scan_patches suppressed; no tactical_scan_patches was sent.',
        );
    }
}
