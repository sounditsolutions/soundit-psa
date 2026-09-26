<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\StaffTacticalAdminToolExecutor;
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
 * #3929: the "Already …" idempotent arms of StaffTacticalAdminToolExecutor.
 *
 * On twenty arms the method can read Tactical BEFORE alreadyExecuted()
 * answers (on eighteen always; on create_automation_policy only with copy_id,
 * on create_agent_task only with a script action), so "no upstream call was
 * made" was false there: a read is an upstream call. Those arms now name the
 * write that was not sent, which is true on every path. The script-sync and
 * script-create arms send nothing before the predicate; their old sentence is
 * true, and the two controls below pin it unchanged.
 *
 * Each case enters through execute() (or approveStagedRun() for the one arm
 * only approval reaches), runs the same call twice, and asserts on the SECOND
 * call: the full message, the exact request log, and the audit summary. The
 * first call must record the tool's own upstream request (the write itself on
 * every tool except script sync, whose sync is a read), so the recorder is
 * proved to see the traffic the second call is then asserted not to send.
 *
 * TacticalClient builds its own Guzzle client, so Http::fake() cannot see its
 * traffic. A routing handler answers by method and path, and Guzzle's history
 * middleware records every request that reaches it.
 */
class TacticalAlreadyIdempotentWordingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://tactical.invalid/';

    private const SCRIPTS_READ = 'GET '.self::BASE.'scripts/?showCommunityScripts=true&showHiddenScripts=true';

    private const POLICIES_READ = 'GET '.self::BASE.'automation/policies/';

    private const CLIENTS_READ = 'GET '.self::BASE.'clients/';

    /** @var array<int,mixed> */
    private array $history = [];

    /** @var list<string> requests no route answered; each one fails the test */
    private array $unrouted = [];

    private User $approver;

    private bool $agentGone = false;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('tactical_api_url', 'https://tactical.invalid');
        Setting::setEncrypted('tactical_api_key', 'test-key');
        Setting::setValue('controld_enabled', '1');
        Setting::setValue('controld_tactical_client_field_id', '7');
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->approver = User::factory()->create();
        $this->bindRouter();
    }

    /** A fixed upstream: every GET answers the same rows, every write answers 200. */
    private function routes(): array
    {
        return [
            'GET scripts/' => [[
                'id' => 102, 'name' => 'Deploy App', 'script_type' => 'userdefined',
                'shell' => 'powershell', 'args' => [], 'env_vars' => [], 'supported_platforms' => ['windows'],
            ]],
            'GET automation/policies/' => [
                ['id' => 7, 'name' => 'Workstations', 'winupdatepolicy' => ['id' => 41]],
                ['id' => 8, 'name' => 'Servers', 'winupdatepolicy' => null],
            ],
            'GET automation/policies/7/tasks/' => [['id' => 31, 'name' => 'Policy job']],
            'GET clients/' => [['id' => 11, 'name' => 'Acme', 'sites' => [['id' => 21, 'name' => 'Main']]]],
            'GET tasks/' => [['id' => 21, 'name' => 'Daily cleanup']],
            'GET agents/agent-1/tasks/' => [['id' => 21, 'name' => 'Daily cleanup']],
            'GET agents/agent-1/checks/' => [['id' => 310, 'check_type' => 'script', 'script' => 102]],
        ];
    }

    private function bindRouter(): void
    {
        $this->history = [];
        $this->unrouted = [];
        $routes = $this->routes();
        $handler = function (RequestInterface $request) use ($routes) {
            $method = $request->getMethod();
            $path = ltrim($request->getUri()->getPath(), '/');
            if ($method === 'DELETE' && $path === 'agents/agent-abc/') {
                $this->agentGone = true;
            }
            if ($method !== 'GET') {
                return Create::promiseFor(new Response(200, [], json_encode('ok')));
            }
            if ($path === 'agents/agent-abc/') {
                // Quiet and removable until a DELETE lands; then 404, which is
                // what the removal's own post-condition read must see.
                return Create::promiseFor($this->agentGone
                    ? new Response(404, [], '{}')
                    : new Response(200, [], json_encode(['agent_id' => 'agent-abc', 'hostname' => 'RETIRED-01', 'status' => 'offline', 'last_seen' => now()->subDays(30)->toIso8601String()])));
            }
            if (array_key_exists("GET {$path}", $routes)) {
                return Create::promiseFor(new Response(200, [], json_encode($routes["GET {$path}"])));
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
        $out = [];
        foreach ($this->history as $t) {
            $out[] = $t['request']->getMethod().' '.(string) $t['request']->getUri();
        }

        return $out;
    }

    private function executor(): StaffTacticalAdminToolExecutor
    {
        return app(StaffTacticalAdminToolExecutor::class);
    }

    private function latestBlockedSummary(string $tool): string
    {
        $row = TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('result_status', 'blocked')
            ->latest('id')
            ->first();
        $this->assertNotNull($row, "{$tool}: the duplicate must be audited as blocked");

        return (string) $row->summary;
    }

    /**
     * Run one tool twice through execute(). The first call must record
     * $expectedRequest (proving the recorder sees it); the second must hit
     * the idempotent arm.
     * Returns the second call's result, with the history holding only its
     * requests.
     */
    private function twice(string $tool, array $args, ?int $clientId, string $expectedRequest): array
    {
        $first = $this->executor()->execute($tool, $args, $clientId, 'wording-test');
        $this->assertTrue($first['success'] ?? false, "{$tool}: the first call must execute: ".json_encode($first));
        $this->assertFalse($first['idempotent'] ?? false, "{$tool}: the first call must not already be a duplicate");
        $this->assertContains($expectedRequest, $this->requests(), "{$tool}: the first call must record its own upstream request, so the recorder is proved to see it");

        $this->history = [];
        $second = $this->executor()->execute($tool, $args, $clientId, 'wording-test');
        $this->assertTrue($second['idempotent'] ?? false, "{$tool}: the second call must reach the idempotent arm: ".json_encode($second));
        $this->assertSame([], $this->unrouted, "{$tool}: every request must be answered by a route");

        return $second;
    }

    /**
     * @param  list<string>  $reads  the exact request log of the SECOND call
     */
    private function assertArm(string $tool, array $args, ?int $clientId, string $expectedRequest, array $reads, string $message, string $audit): void
    {
        $second = $this->twice($tool, $args, $clientId, $expectedRequest);

        $this->assertSame($message, $second['message'] ?? null, "{$tool}: idempotent message");
        $this->assertSame($reads, $this->requests(), "{$tool}: the second call sends exactly these reads and no write");
        $this->assertSame($audit, $this->latestBlockedSummary($tool), "{$tool}: audit summary of the suppressed duplicate");
    }

    /** @return array{client: Client, asset: Asset} */
    private function endpoint(): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'PC-01', 'name' => 'PC-01']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-1',
            'hostname' => 'PC-01',
            'plat' => 'windows',
            'status' => 'online',
            'synced_at' => now(),
        ]);

        return compact('client', 'asset');
    }

    // ---- positive controls: TRUE arms keep their sentence and send nothing ----

    public function test_true_arm_script_sync_sends_nothing_on_the_duplicate_and_keeps_its_wording(): void
    {
        // Proves the recorder can report absence on the SAME harness: a
        // duplicate that sends nothing records zero requests, so the recorder
        // does not invent the reads asserted on the false arms below.
        $this->assertArm(
            'tactical_sync_scripts_now', ['reason' => 'wording control'], null,
            'GET '.self::BASE.'scripts/',
            [],
            'Already synced Tactical scripts recently; no upstream call was made.',
            'Duplicate Tactical script sync suppressed before upstream call.',
        );
    }

    public function test_true_arm_script_create_sends_nothing_on_the_duplicate_and_keeps_its_wording(): void
    {
        $this->assertArm(
            'tactical_create_script',
            ['reason' => 'wording control', 'name' => 'New Script', 'shell' => 'powershell', 'script_body' => 'Write-Host hi'],
            null,
            'POST '.self::BASE.'scripts/',
            [],
            'Already created an identical Tactical script recently; no upstream call was made.',
            'Duplicate Tactical script create suppressed before upstream call.',
        );
    }

    // ---- the false arms: a read went out, so the sentence names the write ----

    public function test_update_script(): void
    {
        $this->assertArm(
            'tactical_update_script', ['reason' => 'r', 'script_name' => 'Deploy App', 'description' => 'new'], null,
            'PUT '.self::BASE.'scripts/102/',
            [self::SCRIPTS_READ],
            'Already updated this Tactical script recently; no script update was sent.',
            'Duplicate Tactical script update suppressed; no script update was sent.',
        );
    }

    public function test_delete_script(): void
    {
        $this->assertArm(
            'tactical_delete_script', ['reason' => 'r', 'script_name' => 'Deploy App', 'confirm_script_name' => 'Deploy App'], null,
            'DELETE '.self::BASE.'scripts/102/',
            [self::SCRIPTS_READ],
            'Already deleted this Tactical script recently; no script delete was sent.',
            'Duplicate Tactical script delete suppressed; no script delete was sent.',
        );
    }

    public function test_create_automation_policy_with_copy_id_reads_the_policy_list_first(): void
    {
        $this->assertArm(
            'tactical_create_automation_policy', ['reason' => 'r', 'name' => 'Copy of Workstations', 'copy_id' => 7], null,
            'POST '.self::BASE.'automation/policies/',
            [self::POLICIES_READ],
            'Already created an identical Tactical automation policy recently; no automation-policy create was sent.',
            'Duplicate automation-policy create suppressed; no automation-policy create was sent.',
        );
    }

    public function test_create_automation_policy_without_copy_id_sends_nothing_and_the_same_sentence_is_true(): void
    {
        // The conditional arm: one message on both paths, true on both.
        $this->assertArm(
            'tactical_create_automation_policy', ['reason' => 'r', 'name' => 'Fresh policy'], null,
            'POST '.self::BASE.'automation/policies/',
            [],
            'Already created an identical Tactical automation policy recently; no automation-policy create was sent.',
            'Duplicate automation-policy create suppressed; no automation-policy create was sent.',
        );
    }

    public function test_update_automation_policy(): void
    {
        $this->assertArm(
            'tactical_update_automation_policy', ['reason' => 'r', 'policy_id' => 7, 'desc' => 'new'], null,
            'PUT '.self::BASE.'automation/policies/7/',
            [self::POLICIES_READ],
            'Already updated this Tactical automation policy recently; no automation-policy update was sent.',
            'Duplicate automation-policy update suppressed; no automation-policy update was sent.',
        );
    }

    public function test_delete_automation_policy(): void
    {
        $this->assertArm(
            'tactical_delete_automation_policy', ['reason' => 'r', 'policy_id' => 8, 'confirm_policy_name' => 'Servers'], null,
            'DELETE '.self::BASE.'automation/policies/8/',
            [self::POLICIES_READ],
            'Already deleted this Tactical automation policy recently; no automation-policy delete was sent.',
            'Duplicate automation-policy delete suppressed; no automation-policy delete was sent.',
        );
    }

    public function test_assign_automation_policy(): void
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        $this->assertArm(
            'tactical_assign_automation_policy',
            ['reason' => 'r', 'policy_id' => 7, 'target_type' => 'client', 'policy_kind' => 'workstation'],
            (int) $client->id,
            'PUT '.self::BASE.'clients/11/',
            [self::POLICIES_READ, self::CLIENTS_READ],
            'Already assigned this Tactical automation policy recently; no assignment was sent.',
            'Duplicate automation-policy assignment suppressed; no assignment was sent.',
        );
    }

    public function test_create_check(): void
    {
        $fx = $this->endpoint();
        $this->assertArm(
            'tactical_create_check',
            ['reason' => 'r', 'hostname' => 'PC-01', 'confirm_hostname' => 'PC-01', 'script_name' => 'Deploy App'],
            (int) $fx['client']->id,
            'POST '.self::BASE.'checks/',
            [self::SCRIPTS_READ],
            'Already created an identical Tactical check recently; no check create was sent.',
            'Duplicate Tactical check create suppressed; no check create was sent.',
        );
    }

    public function test_create_agent_task_with_a_script_action_reads_the_script_list_first(): void
    {
        $fx = $this->endpoint();
        $this->assertArm(
            'tactical_create_agent_task',
            ['reason' => 'r', 'hostname' => 'PC-01', 'name' => 'Deploy', 'task_type' => 'manual',
                'actions' => [['type' => 'script', 'script_name' => 'Deploy App', 'script_args' => [], 'timeout' => 90]]],
            (int) $fx['client']->id,
            'POST '.self::BASE.'tasks/',
            [self::SCRIPTS_READ],
            'Already created an identical Tactical agent task recently; no task create was sent.',
            'Duplicate agent task create suppressed; no task create was sent.',
        );
    }

    public function test_create_agent_task_with_only_cmd_actions_sends_nothing_and_the_same_sentence_is_true(): void
    {
        // The conditional arm: one message on both paths, true on both.
        $fx = $this->endpoint();
        $this->assertArm(
            'tactical_create_agent_task',
            ['reason' => 'r', 'hostname' => 'PC-01', 'name' => 'Whoami', 'task_type' => 'manual',
                'actions' => [['type' => 'cmd', 'shell' => 'powershell', 'command' => 'whoami', 'timeout' => 30]]],
            (int) $fx['client']->id,
            'POST '.self::BASE.'tasks/',
            [],
            'Already created an identical Tactical agent task recently; no task create was sent.',
            'Duplicate agent task create suppressed; no task create was sent.',
        );
    }

    public function test_create_policy_task(): void
    {
        $this->assertArm(
            'tactical_create_policy_task',
            ['reason' => 'r', 'policy_id' => 7, 'name' => 'Policy whoami', 'task_type' => 'manual',
                'actions' => [['type' => 'cmd', 'shell' => 'powershell', 'command' => 'whoami', 'timeout' => 30]]],
            null,
            'POST '.self::BASE.'tasks/',
            [self::POLICIES_READ],
            'Already created an identical Tactical policy task recently; no task create was sent.',
            'Duplicate policy task create suppressed; no task create was sent.',
        );
    }

    public function test_update_task(): void
    {
        $this->assertArm(
            'tactical_update_task', ['reason' => 'r', 'task_id' => 21, 'name' => 'Renamed cleanup'], null,
            'PUT '.self::BASE.'tasks/21/',
            ['GET '.self::BASE.'tasks/'],
            'Already updated this Tactical task recently; no task update was sent.',
            'Duplicate task update suppressed; no task update was sent.',
        );
    }

    public function test_delete_task(): void
    {
        $this->assertArm(
            'tactical_delete_task', ['reason' => 'r', 'task_id' => 21, 'confirm_task_name' => 'Daily cleanup'], null,
            'DELETE '.self::BASE.'tasks/21/',
            ['GET '.self::BASE.'tasks/'],
            'Already deleted this Tactical task recently; no task delete was sent.',
            'Duplicate task delete suppressed; no task delete was sent.',
        );
    }

    public function test_run_agent_task(): void
    {
        $fx = $this->endpoint();
        $this->assertArm(
            'tactical_run_agent_task',
            ['reason' => 'r', 'hostname' => 'PC-01', 'confirm_hostname' => 'PC-01', 'task_id' => 21, 'confirm_task_name' => 'Daily cleanup'],
            (int) $fx['client']->id,
            'POST '.self::BASE.'tasks/21/run/',
            ['GET '.self::BASE.'agents/agent-1/tasks/'],
            'Already ran this Tactical agent task recently; no task run was sent.',
            'Duplicate agent task run suppressed; no task run was sent.',
        );
    }

    public function test_run_policy_task_on_agent(): void
    {
        $fx = $this->endpoint();
        $this->assertArm(
            'tactical_run_policy_task_on_agent',
            ['reason' => 'r', 'hostname' => 'PC-01', 'confirm_hostname' => 'PC-01', 'policy_id' => 7, 'task_id' => 31, 'confirm_task_name' => 'Policy job'],
            (int) $fx['client']->id,
            'POST '.self::BASE.'tasks/31/run/',
            [self::POLICIES_READ, 'GET '.self::BASE.'automation/policies/7/tasks/'],
            'Already ran this Tactical policy task on that agent recently; no task run was sent.',
            'Duplicate policy task single-agent run suppressed; no task run was sent.',
        );
    }

    public function test_run_policy_task_all(): void
    {
        $this->assertArm(
            'tactical_run_policy_task_all',
            ['reason' => 'r', 'policy_id' => 7, 'task_id' => 31, 'confirm_policy_name' => 'Workstations',
                'confirm_task_name' => 'Policy job', 'confirm_run_all' => 'run policy task for all affected agents'],
            null,
            'POST '.self::BASE.'automation/tasks/31/run/',
            [self::POLICIES_READ, 'GET '.self::BASE.'automation/policies/7/tasks/'],
            'Already ran this Tactical policy task for all affected agents recently; no task run was sent.',
            'Duplicate policy task all-agents run suppressed; no task run was sent.',
        );
    }

    public function test_create_patch_policy(): void
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        $this->assertArm(
            'tactical_create_patch_policy', ['reason' => 'r', 'policy_id' => 8, 'critical' => 'approve'], (int) $client->id,
            'POST '.self::BASE.'automation/patchpolicy/',
            [self::POLICIES_READ],
            'Already created an identical patch policy recently; no patch-policy create was sent.',
            'Duplicate patch-policy create suppressed; no patch-policy create was sent.',
        );
    }

    public function test_update_patch_policy(): void
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        $this->assertArm(
            'tactical_update_patch_policy', ['reason' => 'r', 'policy_id' => 7, 'critical' => 'approve'], (int) $client->id,
            'PUT '.self::BASE.'automation/patchpolicy/41/',
            [self::POLICIES_READ],
            'Already updated this patch policy recently; no patch-policy update was sent.',
            'Duplicate patch-policy update suppressed; no patch-policy update was sent.',
        );
    }

    public function test_delete_patch_policy(): void
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        $this->assertArm(
            'tactical_delete_patch_policy', ['reason' => 'r', 'policy_id' => 7, 'confirm_policy_name' => 'Workstations'], (int) $client->id,
            'DELETE '.self::BASE.'automation/patchpolicy/41/',
            [self::POLICIES_READ],
            'Already deleted this patch policy recently; no patch-policy delete was sent.',
            'Duplicate patch-policy delete suppressed; no patch-policy delete was sent.',
        );
    }

    public function test_reset_patch_policies(): void
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        $this->assertArm(
            'tactical_reset_patch_policies', ['reason' => 'r', 'scope' => 'site', 'confirm_client_name' => 'Acme'], (int) $client->id,
            'POST '.self::BASE.'automation/patchpolicy/reset/',
            [self::CLIENTS_READ],
            'Already reset this Tactical patch-policy scope recently; no reset was sent.',
            'Duplicate patch-policy reset suppressed; no reset was sent.',
        );
    }

    public function test_set_client_custom_field(): void
    {
        // yf7IXuhQ context:1: the message AND the audit summary.
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        $this->assertArm(
            'tactical_set_client_custom_field', ['reason' => 'r', 'field_key' => 'controld_org_id', 'value' => 'org-1'], (int) $client->id,
            'PUT '.self::BASE.'clients/11/',
            [self::CLIENTS_READ],
            'Already wrote this Tactical client custom field recently; no custom-field write was sent.',
            'Duplicate Tactical client custom-field write suppressed; no custom-field write was sent.',
        );
    }

    public function test_remove_agent_on_approval(): void
    {
        // Staged-only: the arm is reached through approveStagedRun(). Two
        // proposals for the same device (two tickets) carry one content hash.
        // A verified removal deletes the TacticalAsset row and the agent then
        // reads 404, so the second approval reaches alreadyExecuted() only
        // once a TacticalAsset row links the agent again AND the agent reads
        // quiet again. That world is built here explicitly.
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        $asset = Asset::factory()->for($client)->create(['name' => 'RETIRED-01', 'hostname' => 'RETIRED-01', 'is_active' => false]);
        $row = ['asset_id' => $asset->id, 'agent_id' => 'agent-abc', 'hostname' => 'RETIRED-01', 'status' => 'offline', 'last_seen_at' => now()->subDays(30)];
        TacticalAsset::create($row);
        $stage = function () use ($client, $asset): TechnicianRun {
            $ticket = Ticket::factory()->for($client)->create();
            $staged = $this->executor()->execute('tactical_stage_remove_agent', [
                'ticket_id' => $ticket->id, 'asset_id' => $asset->id, 'confirm_hostname' => 'RETIRED-01', 'reason' => 'r',
            ], (int) $client->id, 'wording-test');
            $this->assertTrue($staged['success'] ?? false, 'staging must succeed: '.json_encode($staged));

            return TechnicianRun::findOrFail($staged['run_id']);
        };
        $first = $stage();
        $second = $stage();
        $this->assertSame($first->content_hash, $second->content_hash);

        $this->assertSame('executed', $this->executor()->approveStagedRun($first->fresh(), (int) $this->approver->id)->status);
        $this->assertContains('DELETE '.self::BASE.'agents/agent-abc/', $this->requests(), 'the first approval must send the removal');

        TacticalAsset::create($row);
        $this->agentGone = false;
        $this->history = [];
        $result = $this->executor()->approveStagedRun($second->fresh(), (int) $this->approver->id);

        $this->assertSame('executed', $result->status);
        $this->assertSame([], $this->unrouted);
        $this->assertSame(['GET '.self::BASE.'agents/agent-abc/'], $this->requests(), 'the second approval sends exactly the live read and no removal');
        $this->assertSame('Duplicate Tactical agent removal suppressed; no agent removal was sent.', $this->latestBlockedSummary('tactical_remove_agent'), 'tactical_remove_agent: audit summary of the suppressed duplicate');
        // approveStagedRun() returns only a status here and discards the
        // executor's message, so no public entry can observe that message; the
        // audit summary above is what this arm leaves behind.
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'tactical_remove_agent')->where('result_status', 'blocked')->count());
    }
}
