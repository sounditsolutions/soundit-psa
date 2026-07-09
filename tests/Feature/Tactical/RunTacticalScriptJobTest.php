<?php

namespace Tests\Feature\Tactical;

use App\Jobs\RunTacticalScriptJob;
use App\Models\Asset;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\User;
use App\Services\Tactical\TacticalActionService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * bd psa-nfqd: the async run-script path. RunTacticalScriptJob dispatches a
 * fire-and-forget script run THROUGH the audited action bus, so the Servosity
 * deploy (formerly a raw TacticalClient::runScriptAsync bypass) is now
 * capability-gated and lands one immutable tactical_action_logs row like every
 * other endpoint-affecting action.
 */
class RunTacticalScriptJobTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<mixed> */
    private array $history = [];

    private function bindClient(array $queue): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $http = new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]);
        $this->app->instance(\App\Services\Tactical\TacticalClient::class, new \App\Services\Tactical\TacticalClient($http));
    }

    private function onlineAsset(string $agentId = 'AGENT-1'): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'WS-1']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => $agentId,
            'hostname' => 'WS-1',
            'status' => 'online',
        ]);

        return $asset->refresh();
    }

    public function test_job_runs_the_script_through_the_bus_and_audits_ok(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        // Fire-and-forget PUT: Tactical accepts and returns a scalar "ok".
        $this->bindClient([new Response(200, [], json_encode('ok'))]);

        (new RunTacticalScriptJob($asset->id, 218, '-ServosityOneUrl {{agent.ServosityOneUrl}}', 600, $user->id, 'servosity-deploy'))
            ->handle(app(TacticalActionService::class));

        // The run flowed through the bus and wrote exactly one audit row.
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_script',
            'asset_id' => $asset->id,
            'actor_id' => $user->id,
            'result_status' => 'ok',
        ]);
        $this->assertSame(1, TacticalActionLog::count());

        // ...via a single fire-and-forget PUT (output=forget), not a wait-run.
        $this->assertCount(1, $this->history);
        $req = $this->history[0]['request'];
        $this->assertSame('PUT', $req->getMethod());
        $this->assertSame('/agents/AGENT-1/runscript/', $req->getUri()->getPath());
        $this->assertSame('forget', json_decode((string) $req->getBody(), true)['output']);
    }

    public function test_job_audits_offline_when_the_agent_is_unreachable(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([new ConnectException('agent offline', new Request('PUT', 'agents/AGENT-1/runscript/'))]);

        (new RunTacticalScriptJob($asset->id, 218, '', 600, $user->id, 'servosity-deploy'))
            ->handle(app(TacticalActionService::class));

        // A transport failure is a surfaced `offline` audit, not a masked success.
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_script',
            'result_status' => 'offline',
        ]);
    }

    public function test_job_attributes_to_the_system_label_when_no_user_resolves(): void
    {
        $asset = $this->onlineAsset();
        $this->bindClient([new Response(200, [], json_encode('ok'))]);

        (new RunTacticalScriptJob($asset->id, 218, '', 600, null, 'servosity-deploy'))
            ->handle(app(TacticalActionService::class));

        // No initiating user → the bus authorizes the run as a system action and
        // attributes the audit row to the supplied label (never denied for lack
        // of an actor).
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_script',
            'actor_id' => null,
            'actor_label' => 'servosity-deploy',
            'result_status' => 'ok',
        ]);
    }

    public function test_job_no_ops_when_the_asset_is_gone(): void
    {
        $this->bindClient([]); // must never be called

        (new RunTacticalScriptJob(999999, 218, '', 600, null, 'servosity-deploy'))
            ->handle(app(TacticalActionService::class));

        $this->assertSame(0, TacticalActionLog::count());
        $this->assertCount(0, $this->history);
    }
}
