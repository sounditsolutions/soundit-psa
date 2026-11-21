<?php

namespace Tests\Feature\ControlD;

use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDOnboardingStaged;
use App\Services\ControlD\ControlDProvisioning;
use App\Services\Mcp\StaffControlDOnboardingToolExecutor;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Support\ControlDConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * P7A74iGD (b): the one-approval Control D onboarding plan and its code-gated Tactical
 * deploy. Control D answers from a Guzzle MockHandler on the injected ControlDOnboardingStaged;
 * Tactical from a routed Guzzle handler on an injected TacticalClient (it builds its own Guzzle
 * client, so Http::fake() cannot see it). Http::preventStrayRequests() guards everything else.
 * All ids, names and codes are synthetic (G-13); the field and script ids are arbitrary test values.
 */
class ControlDOnboardPlanTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '0123456789abcdef0123456789abcdef';

    private const OTHER = 'fedcba9876543210fedcba9876543210';

    private const FIELD = 31;

    private const SCRIPT = 77;

    private const TC = 501;

    private const TC_NAME = 'Synthetic Tactical Client';

    private array $cd = [];

    /** @var array<int, array<string, mixed>> */
    private array $tac = [];

    /** Tactical client custom field value served by GET clients/{TC}/ (null = absent). */
    private mixed $fieldValue = null;

    /** Value the read-back serves after a PUT; null = what was PUT. */
    private mixed $readBackOverride = null;

    private bool $putFails = false;

    /** @var array<int, array<string, mixed>> agent rows for GET agents/?client= */
    private array $agents = [];

    /** #8466: the deploy script's own `args` served by GET scripts/{SCRIPT}/ (synthetic). */
    private mixed $scriptArgs = ['-OrgCode', '{{client.ControlDOrgCode}}', '-Quiet'];

    /** #8466 C-56: when set, answers GET scripts/{id}/ instead of the script record (a failed or degraded read). */
    private ?\Closure $scriptAnswer = null;

    /** The deploy script's stored argument list, as the run body must carry it. */
    private const ARGS = ['-OrgCode', '{{client.ControlDOrgCode}}', '-Quiet'];

    /** #8466 diff:1: a synthetic 32-character run of letters and digits that is not the bound code. */
    private const RUN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

    /** #8466: the one Tactical read staging (and approval) makes for a plan with a deploy step. */
    private const SCRIPT_READ = 'GET scripts/'.self::SCRIPT.'/';

    public function beginDatabaseTransaction(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setValue('controld_enabled', '1');
        Setting::setEncrypted('controld_api_key', 'synthetic-key');
        Setting::setValue('controld_stats_endpoint', 'synthetic-region');
        foreach (['tactical_client_field_id' => (string) self::FIELD, 'default_profile_id' => 'testprofile01', 'code_profile_id' => '444444synthDD', 'code_expiry_days' => '7',
            'code_device_limit_headroom' => '2', 'code_analytics_level' => '0', 'code_intercept_mode' => 'standard'] as $key => $value) {
            Setting::setValue('controld_'.$key, $value);
        }
        Setting::setValue(ControlDConfig::TACTICAL_DEPLOY_SCRIPT_SETTING, (string) self::SCRIPT);
        Setting::setValue(ControlDConfig::ONBOARDING_ENABLED_SETTING, '1');
        Setting::setValue('tactical_api_url', 'https://tactical.invalid');
        Setting::setEncrypted('tactical_api_key', 'synthetic-tactical-key');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->agents = [
            ['agent_id' => 'agent-a', 'hostname' => 'WS-A', 'client_name' => self::TC_NAME, 'site_name' => 'Main'],
            ['agent_id' => 'agent-b', 'hostname' => 'WS-B', 'client_name' => self::TC_NAME, 'site_name' => 'Main'],
        ];
        $this->bindTactical();
    }

    // ── fixtures ────────────────────────────────────────────────────────────────

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(array $client = []): array
    {
        $client = Client::factory()->create(array_merge(['name' => 'Synthetic Organization', 'email' => 'synthetic@example.invalid', 'tactical_site_id' => self::TC_NAME.'|Main'], $client));
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Onboard to Control D', 'status' => \App\Enums\TicketStatus::New->value]);

        return compact('client', 'ticket');
    }

    /** A PSA asset of $client linked to Tactical agent $agentId. */
    private function linkedAsset(Client $client, string $agentId, string $name): Asset
    {
        $asset = Asset::factory()->create(['client_id' => $client->id, 'name' => $name]);
        TacticalAsset::create(['asset_id' => $asset->id, 'agent_id' => $agentId, 'hostname' => $name, 'client_name' => self::TC_NAME, 'site_name' => 'Main']);

        return $asset;
    }

    private function ok(array $body): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'body' => $body]));
    }

    private function listed(string $org = 'syntheticOrg01', ?string $global = 'testprofile01'): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/sub-organization.json')), true)['body']['sub_organizations'][0];
        $row['PK'] = $org;
        if ($global === null) {
            unset($row['parent_profile']);
        } else {
            $row['parent_profile']['PK'] = $global;
        }

        return $row;
    }

    private function orgRow(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/organization.json')), true)['body']['organization'];
    }

    /** The code step's exchange: device types, profiles, POST provision, provision read-back. */
    private function codeResponses(string $org = 'testorg001', int $max = 2): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true);
        $row['max'] = $max;
        $row['org'] = $org;
        $row['profile_id'] = '444444synthDD';
        $row['ts_exp'] = now()->getTimestamp() + 7 * 86400;

        return [
            $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->ok(['profiles' => [['PK' => '444444synthDD']]]),
            $this->ok(['provision' => $row]),
            $this->ok(['provisions' => [$row]]),
        ];
    }

    /** Bind Control D answering from $responses (then 503s), recording history in $this->cd. */
    private function controlD(array $responses): void
    {
        $this->cd = [];
        $stack = HandlerStack::create(new MockHandler([...$responses, ...array_fill(0, 8, new Response(503))]));
        $stack->push(Middleware::history($this->cd));
        $transport = new ControlDClient(['api_key' => 'synthetic-key', 'handler' => $stack]);
        $this->app->instance(ControlDOnboardingStaged::class, new ControlDOnboardingStaged($transport, new ControlDProvisioning($transport)));
    }

    /** Bind a routed Tactical upstream, recording every request in $this->tac. */
    private function bindTactical(): void
    {
        $this->tac = [];
        $handler = function (RequestInterface $request) {
            $method = $request->getMethod();
            $path = ltrim($request->getUri()->getPath(), '/');
            $query = $request->getUri()->getQuery();
            $json = static fn ($body, int $status = 200) => Create::promiseFor(new Response($status, [], json_encode($body)));
            if ($method === 'GET' && $path === 'clients/') {
                return $json([['id' => self::TC, 'name' => self::TC_NAME], ['id' => self::TC + 1, 'name' => 'Another Synthetic Client']]);
            }
            if ($method === 'GET' && $path === 'clients/'.self::TC.'/') {
                $fields = [['id' => 9, 'field' => self::FIELD + 1, 'client' => self::TC, 'value' => 'unrelated']];
                if ($this->fieldValue !== null) {
                    $fields[] = ['id' => 10, 'field' => self::FIELD, 'client' => self::TC, 'value' => $this->fieldValue];
                }

                return $json(['id' => self::TC, 'name' => self::TC_NAME, 'custom_fields' => $fields]);
            }
            if ($method === 'PUT' && $path === 'clients/'.self::TC.'/') {
                if ($this->putFails) {
                    return $json(['detail' => 'synthetic failure'], 500);
                }
                $body = json_decode((string) $request->getBody(), true);
                $this->fieldValue = $this->readBackOverride ?? ($body['custom_fields'][0]['string_value'] ?? null);

                return $json('ok');
            }
            if ($method === 'GET' && $path === 'agents/' && $query === 'client='.self::TC) {
                return $json($this->agents);
            }
            if ($method === 'GET' && preg_match('#^scripts/(\d+)/$#', $path, $m)) {
                if ($this->scriptAnswer !== null) {
                    return ($this->scriptAnswer)($request, (int) $m[1]);
                }

                // The real detail carries the script body too; the deploy must take only `args`.
                return $json(['id' => (int) $m[1], 'name' => 'Synthetic Control D deploy', 'shell' => 'powershell',
                    'script_body' => 'Write-Host synthetic-script-body', 'args' => $this->scriptArgs, 'env_vars' => []]);
            }
            // #8466: Tactical's runscript view is POST-only; anything else falls through to the 599 below.
            if ($method === 'POST' && preg_match('#^agents/[^/]+/runscript/$#', $path)) {
                return $json('ok');
            }

            return $json(['unrouted' => "{$method} {$path}"], 599);
        };
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->tac));
        $guzzle = new GuzzleClient(['handler' => $stack, 'base_uri' => 'https://tactical.invalid/']);
        $this->app->instance(TacticalClient::class, new TacticalClient($guzzle));
    }

    /** @return list<string> "METHOD path[?query]" of every Tactical request */
    private function tacticalRequests(): array
    {
        return array_map(static function (array $t): string {
            $uri = $t['request']->getUri();

            return $t['request']->getMethod().' '.ltrim($uri->getPath(), '/').($uri->getQuery() !== '' ? '?'.$uri->getQuery() : '');
        }, $this->tac);
    }

    /** The JSON body of the one client custom field write (PUT clients/{TC}/). */
    private function fieldWriteBody(): string
    {
        $writes = array_values(array_filter($this->tac, static fn (array $t): bool => $t['request']->getMethod() === 'PUT' && str_starts_with(ltrim($t['request']->getUri()->getPath(), '/'), 'clients/')));
        $this->assertCount(1, $writes);

        return (string) $writes[0]['request']->getBody();
    }

    /** @return list<array<string, mixed>> the JSON bodies of every runscript request */
    private function scriptRuns(): array
    {
        return array_values(array_map(static fn (array $t): array => ['agent' => explode('/', ltrim($t['request']->getUri()->getPath(), '/'))[1]] + json_decode((string) $t['request']->getBody(), true),
            array_filter($this->tac, static fn (array $t): bool => str_ends_with($t['request']->getUri()->getPath(), '/runscript/'))));
    }

    private function stage(Client $client, Ticket $ticket, mixed $deploy = null): array
    {
        $stager = User::factory()->admin()->create(['is_active' => true]);

        return app(StaffControlDOnboardingToolExecutor::class)->stageForClient($client, $ticket, 'onboard', $stager, $deploy);
    }

    private function approve(int $runId): \App\Services\Technician\TechnicianApprovalResult
    {
        $approver = User::factory()->admin()->create(['is_active' => true]);

        return app(StaffControlDOnboardingToolExecutor::class)->approveStagedRun(TechnicianRun::findOrFail($runId), $approver->id);
    }

    private function payload(int $runId): array
    {
        return json_decode(Crypt::decryptString((string) TechnicianRun::findOrFail($runId)->proposed_meta['encrypted_payload']), true);
    }

    /** @return list<string> audit summaries of $runId in order */
    private function audit(int $runId): array
    {
        return TechnicianActionLog::where('run_id', $runId)->orderBy('id')->pluck('summary')->all();
    }

    /** Capture every application log record (Log::listen sees each record written through the logger). */
    private function captureLogs(): \ArrayObject
    {
        $records = new \ArrayObject;
        Log::listen(function ($event) use ($records): void {
            $records[] = json_encode([$event->level, $event->message, $event->context]);
        });
        Log::info('capture-sentinel');

        return $records;
    }

    // ── tests ───────────────────────────────────────────────────────────────────

    /** The full exchange of an unmapped client: org (POST, GET), code (4). */
    private function orgAndCodeResponses(): array
    {
        return [$this->ok(['organization' => $this->orgRow()]), $this->ok(['sub_organizations' => [$this->listed()]]), ...$this->codeResponses('syntheticOrg01')];
    }

    /** One plan, one approval: org, code and deploy (all) all run; the run closes Done. */
    public function test_a_full_plan_binds_org_and_code_then_deploys_to_all_agents_and_closes_done(): void
    {
        $this->travelTo(now()->startOfSecond());
        $f = $this->fixture();
        $staged = $this->stage($f['client'], $f['ticket'], 'all');
        $this->assertSame(['organization', 'code', 'deploy'], $staged['steps'] ?? null, json_encode($staged));
        $this->assertSame([self::SCRIPT_READ], $this->tacticalRequests(), "staging makes one Tactical request: the read of the deploy script's own arguments");
        $this->assertSame(self::ARGS, $this->payload($staged['run_id'])['deploy']['script_args'], 'the script\'s own args are pinned');
        $this->assertSame(0, ControlDOnboardingIntent::count());

        $this->controlD($this->orgAndCodeResponses());
        $result = $this->approve($staged['run_id']);

        $this->assertSame('executed', $result->status, (string) $result->message);
        $this->assertSame(TechnicianRunState::Done, TechnicianRun::find($staged['run_id'])->state);
        $client = $f['client']->fresh();
        $this->assertSame('syntheticOrg01', $client->controld_org_id);
        $this->assertSame(self::CODE, $client->controld_provisioning_code);
        $intents = ControlDOnboardingIntent::pluck('state', 'operation')->all();
        ksort($intents);
        $this->assertSame(['code' => 'bound', 'organization' => 'bound'], $intents);
        // Tactical: script args (staging, approval), resolve, read field, write once, read back, list agents, one POST script run per agent.
        $this->assertSame([self::SCRIPT_READ, self::SCRIPT_READ, 'GET clients/', 'GET clients/'.self::TC.'/', 'PUT clients/'.self::TC.'/', 'GET clients/'.self::TC.'/', 'GET agents/?client='.self::TC,
            'POST agents/agent-a/runscript/', 'POST agents/agent-b/runscript/'], $this->tacticalRequests());
        $put = json_decode($this->fieldWriteBody(), true);
        $this->assertSame([['field' => self::FIELD, 'string_value' => self::CODE]], $put['custom_fields']);
        $this->assertCount(2, $this->scriptRuns());
        foreach ($this->scriptRuns() as $run) {
            $this->assertSame(self::SCRIPT, $run['script']);
            $this->assertSame(self::ARGS, $run['args'], "#8466: exactly the script's own args pinned at staging");
            $this->assertContains('{{client.ControlDOrgCode}}', $run['args'], 'the placeholder Tactical fills from the field');
            $this->assertSame([], $run['env_vars']);
        }
        $this->assertStringContainsString('Deploy: started', (string) $result->message);
        $this->assertStringContainsString(StaffControlDOnboardingToolExecutor::DEPLOY_STARTED_MEANS, (string) $result->message);
    }

    /** Audit (addition 4): one row per step outcome plus one summary row; ids and status only, never the code. */
    public function test_each_step_and_the_run_summary_are_audited_with_ids_only(): void
    {
        $this->travelTo(now()->startOfSecond());
        $f = $this->fixture();
        $staged = $this->stage($f['client'], $f['ticket'], 'all');
        $this->controlD($this->orgAndCodeResponses());
        $this->approve($staged['run_id']);
        $rows = TechnicianActionLog::where('run_id', $staged['run_id'])->where('result_status', '!=', 'awaiting_approval')->orderBy('id')->get(['summary', 'result_status']);
        $prefix = "onboard:{$f['client']->id}:plan";
        $this->assertCount(4, $rows, json_encode($rows));
        $this->assertStringStartsWith("{$prefix}:organization: bound (intent ", $rows[0]->summary);
        $this->assertStringStartsWith("{$prefix}:code: bound (intent ", $rows[1]->summary);
        $this->assertStringStartsWith("{$prefix}:deploy: started; Tactical client #".self::TC.', field written; script run requested on 2 of 2 agents', $rows[2]->summary);
        $this->assertSame("{$prefix}: plan summary: organization=bound, code=bound, deploy=started; run completed; run closed (state done).", $rows[3]->summary);
        $this->assertSame(['executed', 'executed', 'executed', 'executed'], $rows->pluck('result_status')->all());
        foreach (TechnicianActionLog::all() as $row) {
            $this->assertStringNotContainsString(self::CODE, (string) $row->summary);
        }
    }

    /**
     * A stop at each Control D step, rejected (a 4xx vendor envelope) and uncertain (a 503):
     * later steps do not run, the deploy makes no Tactical request at all, and the result, the
     * summary row and the client page name the failed step and say the run did not complete.
     *
     * @return array<string, array{string, string}>
     */
    public static function stops(): array
    {
        return [
            'organization rejected' => ['organization', 'rejected'],
            'organization uncertain' => ['organization', 'uncertain'],
            'global-profile rejected' => ['global-profile', 'rejected'],
            'global-profile uncertain' => ['global-profile', 'uncertain'],
            'code rejected' => ['code', 'rejected'],
            'code uncertain' => ['code', 'uncertain'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stops')]
    public function test_a_stop_at_a_step_runs_nothing_after_it_and_makes_no_tactical_request(string $at, string $how): void
    {
        $fail = $how === 'rejected' ? new Response(403, [], file_get_contents(base_path('tests/Fixtures/ControlD/read-only-rejection.json'))) : new Response(503, [], 'upstream');
        if ($at === 'organization') {
            $f = $this->fixture();
            $this->controlD([]);
            $staged = $this->stage($f['client'], $f['ticket'], 'all');
            $this->assertSame(['organization', 'code', 'deploy'], $staged['steps']);
            $this->controlD([$fail]);
        } elseif ($at === 'global-profile') {
            $f = $this->fixture(['controld_org_id' => 'testorg001']);
            $this->controlD([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
            $staged = $this->stage($f['client'], $f['ticket'], 'all');
            $this->assertSame(['global-profile', 'code', 'deploy'], $staged['steps']);
            $this->controlD([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]), $this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]), $fail]);
        } else {
            $f = $this->fixture(['controld_org_id' => 'testorg001']);
            $this->controlD([$this->ok(['sub_organizations' => [$this->listed('testorg001')]])]);
            $staged = $this->stage($f['client'], $f['ticket'], 'all');
            $this->assertSame(['code', 'deploy'], $staged['steps']);
            $code = $this->codeResponses();
            $this->controlD([$this->ok(['sub_organizations' => [$this->listed('testorg001')]]), $code[0], $code[1], $fail]);
        }
        $this->tac = [];
        $result = $this->approve($staged['run_id']);

        $this->assertSame([self::SCRIPT_READ], $this->tacticalRequests(), "THE DEPLOY GATE: no Tactical write and no script run when the code is not bound; only approval's read of the script's arguments");
        $this->assertSame('executed_with_fault', $result->status);
        // Jeeves 10:51Z: no new run state; the run closes Done and every surface says it did not complete.
        $this->assertSame(TechnicianRunState::Done, TechnicianRun::find($staged['run_id'])->state);
        $this->assertStringStartsWith("The onboarding run did not complete: the '{$at}' step ended {$how}.", (string) $result->message);
        $this->assertStringContainsString('its state reads done, which here does not mean every step succeeded', (string) $result->message);
        $this->assertSame(['completed' => false, 'failed_step' => $at, 'failed_state' => $how], array_slice(TechnicianRun::find($staged['run_id'])->proposed_meta['plan_outcome'], 0, 3));
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($admin)->get(route('clients.show', $f['client']))->assertOk()
            ->assertSee('data-testid="controld-plan-incomplete"', false)
            ->assertSee("did not complete: the '{$at}' step ended {$how}.", false);
        $intent = ControlDOnboardingIntent::where('operation', $at)->sole();
        $this->assertSame($how, $intent->state);
        $this->assertSame(1, ControlDOnboardingIntent::count(), 'nothing after the stop was staged');
        $this->assertNull($f['client']->fresh()->getRawOriginal('controld_provisioning_code'));
        $summary = TechnicianActionLog::where('run_id', $staged['run_id'])->where('summary', 'like', '%plan summary%')->sole();
        $this->assertSame('error', $summary->result_status);
        $after = $at === 'code' ? '' : ', code=not-run';
        $this->assertStringContainsString("{$at}={$how}{$after}, deploy=not-run; run did not complete; failed step: {$at} ({$how}); run closed (state done).", $summary->summary);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $staged['run_id'])->where('summary', 'like', '%:deploy: not run; the provisioning code is not bound%')->count());
        $this->assertStringContainsString('Deploy: not run, because the provisioning code is not bound; nothing was written in Tactical and no script was run.', (string) $result->message);
        if ($how === 'uncertain') {
            $this->assertSame((int) $f['client']->id, (int) $intent->active_client_id, 'uncertain keeps the lock');
            $this->assertStringContainsString('never retried', (string) $result->message);
            // #6575: the plan has a deploy step, but the failed step is a Control D one, so the Control D tail is given.
            $this->assertStringEndsWith('Steps already bound are not redone when the plan is staged again; a step that ended uncertain must be reconciled first.', (string) $result->message);
            $this->assertStringNotContainsString('holds no lock', (string) $result->message);
            $this->assertSame(1, collect($this->cd)->filter(fn ($h) => in_array($h['request']->getMethod(), ['POST', 'PUT'], true))->count(), 'the uncertain write was sent once and never retried');
        } else {
            $this->assertNull($intent->active_client_id);
        }
    }

    /**
     * Approval drift: the plan derived at approval differs from the card, so approval refuses with
     * nothing written: no intent, no Control D write, no Tactical request; the run waits for a deny.
     *
     * @return array<string, array{\Closure, string}>
     */
    public static function drifts(): array
    {
        return [
            'a changed setting (headroom)' => [function (array $f): void {
                Setting::setValue('controld_code_device_limit_headroom', '3');
            }, 'The code values pinned on this card', [self::SCRIPT_READ]],
            'a changed pin (expiry)' => [function (array $f): void {
                Setting::setValue('controld_code_expiry_days', '30');
            }, 'The code values pinned on this card', [self::SCRIPT_READ]],
            'a step already bound by someone else' => [function (array $f): void {
                $f['client']->forceFill(['controld_org_id' => 'racedOrg01'])->save();
            }, "the client now needs the steps 'code, deploy', not 'organization, code, deploy'", [self::SCRIPT_READ]],
            'a changed asset to agent pin' => [function (array $f): void {
                TacticalAsset::where('asset_id', $f['asset']->id)->update(['agent_id' => 'agent-moved']);
            }, "a device's Tactical agent link", [self::SCRIPT_READ]],
            'the asset unlinked' => [function (array $f): void {
                TacticalAsset::where('asset_id', $f['asset']->id)->delete();
            }, 'is not a current asset of this client linked to exactly one Tactical agent', []],
            'the deploy script setting cleared' => [function (array $f): void {
                Setting::setValue(ControlDConfig::TACTICAL_DEPLOY_SCRIPT_SETTING, '');
            }, 'Tactical deploy script ID', []],
            'the deploy script setting changed' => [function (array $f): void {
                Setting::setValue(ControlDConfig::TACTICAL_DEPLOY_SCRIPT_SETTING, (string) (self::SCRIPT + 1));
            }, 'Tactical client custom field ID or deploy script ID differs', ['GET scripts/'.(self::SCRIPT + 1).'/']],
            'the client custom field setting changed' => [function (array $f): void {
                Setting::setValue('controld_tactical_client_field_id', (string) (self::FIELD + 1));
            }, 'Tactical client custom field ID or deploy script ID differs', [self::SCRIPT_READ]],
            'the client contact email changed' => [function (array $f): void {
                $f['client']->forceFill(['email' => 'changed@example.invalid'])->save();
            }, "the client's name or contact email, or the configured analytics region or global profile, differs", [self::SCRIPT_READ]],
            'the analytics region changed' => [function (array $f): void {
                Setting::setValue('controld_stats_endpoint', 'other-region');
            }, "the client's name or contact email, or the configured analytics region or global profile, differs", [self::SCRIPT_READ]],
            'the configured global profile changed' => [function (array $f): void {
                Setting::setValue('controld_default_profile_id', 'testprofile02');
            }, "the client's name or contact email, or the configured analytics region or global profile, differs", [self::SCRIPT_READ]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('drifts')]
    public function test_approval_drift_refuses_with_nothing_written(\Closure $drift, string $why, array $reads): void
    {
        $f = $this->fixture();
        $f['asset'] = $this->linkedAsset($f['client'], 'agent-a', 'WS-A');
        $staged = $this->stage($f['client'], $f['ticket'], [$f['asset']->id]);
        $this->assertSame(['organization', 'code', 'deploy'], $staged['steps'], json_encode($staged));
        $this->assertSame([self::SCRIPT_READ], $this->tacticalRequests(), 'staging read the pinned script once');
        $this->tac = [];
        $this->assertSame([[$f['asset']->id, 'agent-a']], $this->payload($staged['run_id'])['deploy']['assets']);
        $drift($f);
        $this->controlD([$this->ok(['sub_organizations' => [$this->listed('racedOrg01')]])]);
        $result = $this->approve($staged['run_id']);

        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString($why, (string) $result->message);
        $this->assertStringContainsString('stage again', (string) $result->message);
        $this->assertStringContainsString('Nothing was created', (string) $result->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, TechnicianRun::find($staged['run_id'])->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertSame([], array_values(array_filter($this->cd, fn ($h) => $h['request']->getMethod() !== 'GET')), 'no Control D write');
        $this->assertSame($reads, $this->tacticalRequests(), "#8466 diff:7: approval's Tactical requests are exactly these reads; no write, no script run");
        $this->assertSame(1, TechnicianActionLog::where('run_id', $staged['run_id'])->where('result_status', 'blocked')->where('summary', 'like', '%approval refused%nothing was changed at Control D or in Tactical.')->count());
    }

    /** A single-step card staged before the plan existed refuses at approval with the re-stage text and no vendor call. */
    public function test_a_single_step_card_staged_before_the_plan_refuses_and_must_be_restaged(): void
    {
        $f = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->controlD([$this->ok(['sub_organizations' => [$this->listed('testorg001')]])]);
        $staged = $this->stage($f['client'], $f['ticket']);
        $run = TechnicianRun::findOrFail($staged['run_id']);
        $payload = $this->payload($run->id);
        foreach (['plan', 'deploy', 'deploy_request'] as $key) {
            unset($payload[$key]);
        }
        $payload['step'] = 'code';
        $meta = $run->proposed_meta;
        $meta['encrypted_payload'] = Crypt::encryptString(json_encode($payload));
        $run->forceFill(['proposed_meta' => $meta])->save();
        $this->controlD([]);
        $result = $this->approve($run->id);
        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(StaffControlDOnboardingToolExecutor::LEGACY_STEP_REFUSAL, $result->message);
        $this->assertSame([], $this->cd);
        $this->assertSame([], $this->tacticalRequests());
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    // ── deploy step (code already bound: a deploy-only plan) ───────────────────────────────

    /** @return array{client: Client, ticket: Ticket} an onboarded client: mapped, code bound */
    private function onboarded(): array
    {
        static $n = 0;
        $f = $this->fixture(['controld_org_id' => 'testorg'.str_pad((string) ++$n, 3, '0', STR_PAD_LEFT)]);
        $f['client']->forceFill(['controld_provisioning_code' => self::CODE])->save();

        return $f;
    }

    private function deployOnly(array $f, mixed $scope): array
    {
        $this->controlD([]);
        $staged = $this->stage($f['client'], $f['ticket'], $scope);
        $this->assertSame(['deploy'], $staged['steps'] ?? null, json_encode($staged));
        $this->assertSame([], $this->cd, 'a deploy-only plan reads nothing at Control D');
        $this->tac = [];
        $result = $this->approve($staged['run_id']);
        $staged['result'] = $result;

        return $staged;
    }

    /** Selected: only the pinned agents get the script, even though Tactical lists more. */
    public function test_selected_deploy_runs_the_script_only_on_the_pinned_agents(): void
    {
        $f = $this->onboarded();
        $asset = $this->linkedAsset($f['client'], 'agent-b', 'WS-B');
        $this->linkedAsset($f['client'], 'agent-a', 'WS-A');
        $staged = $this->deployOnly($f, [$asset->id]);
        $this->assertSame('executed', $staged['result']->status, (string) $staged['result']->message);
        $this->assertSame(['agent-b'], array_column($this->scriptRuns(), 'agent'));
        $this->assertSame(self::ARGS, $this->scriptRuns()[0]['args']);
        $this->assertSame(TechnicianRunState::Done, TechnicianRun::find($staged['run_id'])->state);
        $this->assertStringContainsString('1 named device: WS-B (asset #'.$asset->id.', agent agent-b)', TechnicianRun::find($staged['run_id'])->proposed_content);
    }

    /** Client 46's case: a non-empty field holding a different value refuses: no PUT, no script. */
    public function test_a_differing_non_empty_field_refuses_with_no_write_and_no_script(): void
    {
        $this->fieldValue = self::OTHER;
        $staged = $this->deployOnly($this->onboarded(), 'all');
        $this->assertSame([self::SCRIPT_READ, 'GET clients/', 'GET clients/'.self::TC.'/'], $this->tacticalRequests());
        $this->assertSame(self::OTHER, $this->fieldValue, 'the field is left as it is');
        // Nothing was written anywhere, so the run is not closed: it waits for the approver to deny it.
        $this->assertSame('gate_declined', $staged['result']->status);
        $this->assertSame(TechnicianRunState::AwaitingApproval, TechnicianRun::find($staged['run_id'])->state);
        $this->assertSame('The onboarding plan was refused before anything was written. Deploy: refused: the Tactical client custom field already holds a different value; it was left as it is.', $staged['result']->message);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $staged['run_id'])->where('result_status', 'error')->where('summary', 'like', '%plan summary: deploy=refused; run did not complete; failed step: deploy (refused); refused before any write; run returned to awaiting approval.')->count());
    }

    /** A field present but holding '' is empty: it is written with the code, read back, and the script runs. */
    public function test_a_present_empty_field_is_written_like_an_absent_one(): void
    {
        $this->fieldValue = '';
        $staged = $this->deployOnly($this->onboarded(), 'all');
        $this->assertSame([self::SCRIPT_READ, 'GET clients/', 'GET clients/'.self::TC.'/', 'PUT clients/'.self::TC.'/', 'GET clients/'.self::TC.'/', 'GET agents/?client='.self::TC,
            'POST agents/agent-a/runscript/', 'POST agents/agent-b/runscript/'], $this->tacticalRequests());
        $this->assertSame('executed', $staged['result']->status, (string) $staged['result']->message);
        $this->assertSame(self::CODE, $this->fieldValue);
    }

    /** A value equal to the bound code is not rewritten; the script runs. */
    public function test_an_equal_field_is_not_written_and_the_script_runs(): void
    {
        $this->fieldValue = self::CODE;
        $staged = $this->deployOnly($this->onboarded(), 'all');
        $this->assertSame([self::SCRIPT_READ, 'GET clients/', 'GET clients/'.self::TC.'/', 'GET agents/?client='.self::TC, 'POST agents/agent-a/runscript/', 'POST agents/agent-b/runscript/'], $this->tacticalRequests());
        $this->assertSame('executed', $staged['result']->status);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $staged['run_id'])->where('summary', 'like', '%:deploy: started; Tactical client #'.self::TC.', field equal;%')->count());
    }

    /**
     * A read-back that does not equal the bound code is uncertain: no script. Covers a different
     * value, and the same code truncated by one character (an equality check on a prefix would pass it).
     *
     * @return array<string, array{string}>
     */
    public static function badReadBacks(): array
    {
        return ['different' => [self::OTHER], 'truncated' => [substr(self::CODE, 0, 31)], 'with a trailing space' => [self::CODE.' ']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badReadBacks')]
    public function test_a_read_back_that_is_not_the_bound_code_is_uncertain_and_runs_no_script(string $readBack): void
    {
        $this->readBackOverride = $readBack;
        $staged = $this->deployOnly($this->onboarded(), 'all');
        $this->assertSame([self::SCRIPT_READ, 'GET clients/', 'GET clients/'.self::TC.'/', 'PUT clients/'.self::TC.'/', 'GET clients/'.self::TC.'/'], $this->tacticalRequests());
        $this->assertSame([], $this->scriptRuns());
        $this->assertSame(TechnicianRunState::Done, TechnicianRun::find($staged['run_id'])->state);
        $this->assertStringContainsString('Deploy: uncertain: the read-back did not show the bound code in the custom field; no script was run.', (string) $staged['result']->message);
    }

    /** A failed field write is uncertain (never retried) and runs no script. */
    public function test_a_failed_field_write_is_uncertain_once_and_runs_no_script(): void
    {
        $this->putFails = true;
        $staged = $this->deployOnly($this->onboarded(), 'all');
        $this->assertSame([self::SCRIPT_READ, 'GET clients/', 'GET clients/'.self::TC.'/', 'PUT clients/'.self::TC.'/'], $this->tacticalRequests());
        $this->assertStringContainsString('Deploy: uncertain: the custom field write or its read-back failed', (string) $staged['result']->message);
        $this->assertSame(TechnicianRunState::Done, TechnicianRun::find($staged['run_id'])->state);
    }

    /**
     * G-14 (Jeeves 13:3xZ, diff:4; #6574, #6576, #6578): an uncertain deploy writes no intent, so the card and the
     * result must not say it keeps the client locked or needs a reconcile; they say what is true, and a new plan
     * for the same client can be staged at once.
     */
    public function test_an_uncertain_deploy_says_it_holds_no_lock_and_needs_no_reconcile(): void
    {
        $this->putFails = true;
        $f = $this->onboarded();
        $staged = $this->deployOnly($f, 'all');
        $card = (string) TechnicianRun::findOrFail($staged['run_id'])->proposed_content;
        $this->assertStringContainsString('A Control D step (organization, global profile or code) that ends uncertain is never retried and keeps this client locked until it is reconciled. The deploy step holds no lock and needs no reconcile, whatever its outcome: if it ends uncertain, check the devices and the custom field in Tactical; approving a newly staged plan re-reads the field and never overwrites a value that differs from the code.', $card);
        $this->assertStringNotContainsString('An uncertain step is never retried and keeps this client locked', $card);
        $this->assertStringNotContainsString('staging again re-reads', $card);
        $message = (string) $staged['result']->message;
        $this->assertStringStartsWith("The onboarding run did not complete: the 'deploy' step ended uncertain.", $message);
        $this->assertStringEndsWith('Steps already bound are not redone when the plan is staged again; the deploy step holds no lock and needs no reconcile: check the devices and the custom field in Tactical; approving a newly staged plan re-reads the field and never overwrites a value that differs from the code.', $message);
        // #6574: outside the one sentence that denies them, the result speaks of no lock and no reconcile anywhere.
        $rest = substr($message, 0, -strlen('Steps already bound are not redone when the plan is staged again; the deploy step holds no lock and needs no reconcile: check the devices and the custom field in Tactical; approving a newly staged plan re-reads the field and never overwrites a value that differs from the code.'));
        $this->assertDoesNotMatchRegularExpression('/lock|reconcil/i', $rest);
        // #6578: the lock that refuses a new proposal is an open intent of this client; there is none, and a new
        // plan for the same client stages at once (a new ticket, so it is a new run, not the closed one).
        $this->assertFalse(ControlDOnboardingIntent::where('client_id', $f['client']->id)->whereIn('state', ['staged', 'posted', 'uncertain'])->exists());
        $ticket = Ticket::factory()->for($f['client'])->create(['subject' => 'Onboard to Control D again', 'status' => \App\Enums\TicketStatus::New->value]);
        $this->controlD([]);
        $again = $this->stage($f['client'], $ticket, 'all');
        $this->assertSame(['deploy'], $again['steps'] ?? null, json_encode($again));
        $this->assertNotSame($staged['run_id'], $again['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, TechnicianRun::findOrFail($again['run_id'])->state);
    }

    /** #6573: a plan with no deploy step says nothing about a deploy step or Tactical. */
    public function test_a_plan_without_a_deploy_step_does_not_describe_one(): void
    {
        $f = $this->fixture();
        $staged = $this->stage($f['client'], $f['ticket']);
        $this->assertSame(['organization', 'code'], $staged['steps'] ?? null, json_encode($staged));
        $card = (string) TechnicianRun::findOrFail($staged['run_id'])->proposed_content;
        $this->assertStringContainsString('that ends uncertain is never retried and keeps this client locked until it is reconciled. Approval derives', $card);
        // The rules paragraph and the steps (the Undo line names what undo leaves untouched, and is out of scope here).
        $body = strstr($card, "\nUndo: ", true);
        $this->assertNotFalse($body);
        $this->assertStringNotContainsString('deploy', strtolower($body));
        $this->assertStringNotContainsString('Tactical', $body);
    }

    /** #6580: a hard fault inside the deploy gives the hard-fault text, never the no-lock promise. */
    public function test_a_hard_fault_in_the_deploy_does_not_promise_no_lock(): void
    {
        $f = $this->onboarded();
        $this->controlD([]);
        $staged = $this->stage($f['client'], $f['ticket'], 'all');
        // The first resolution is approval's re-read of the script's arguments; the deploy's own resolution then faults.
        $real = app(TacticalClient::class);
        $calls = 0;
        $this->app->bind(TacticalClient::class, static function () use ($real, &$calls) {
            return ++$calls === 1 ? $real : throw new \RuntimeException('synthetic fault');
        });
        $result = $this->approve($staged['run_id']);
        $message = (string) $result->message;
        $this->assertSame('executed_with_fault', $result->status);
        $this->assertStringStartsWith("The onboarding run did not complete: the 'deploy' step ended error.", $message);
        $this->assertStringContainsString('HARD FAULT: the plan failed while running', $message);
        $this->assertStringEndsWith('Steps already bound are not redone when the plan is staged again; for the deploy step, check the devices and the custom field in Tactical.', $message);
        $this->assertStringNotContainsString('holds no lock', $message);
        $this->assertStringNotContainsString('re-reads the field', $message);
    }

    /** A pinned agent Tactical no longer lists under the client is refused; the others still run. */
    public function test_a_pinned_agent_outside_the_client_is_refused(): void
    {
        $f = $this->onboarded();
        $inside = $this->linkedAsset($f['client'], 'agent-a', 'WS-A');
        $outside = $this->linkedAsset($f['client'], 'agent-z', 'WS-Z');
        $this->agents[] = ['agent_id' => 'agent-z', 'hostname' => 'WS-Z', 'client_name' => 'Another Synthetic Client', 'site_name' => 'Main'];
        $staged = $this->deployOnly($f, [$inside->id, $outside->id]);
        $this->assertSame(['agent-a'], array_column($this->scriptRuns(), 'agent'));
        $this->assertSame('executed_with_fault', $staged['result']->status);
        $this->assertStringContainsString('Agents: agent-a=started, agent-z=refused.', (string) $staged['result']->message);
        $this->assertSame(TechnicianRunState::Done, TechnicianRun::find($staged['run_id'])->state);
        $this->assertStringStartsWith("The onboarding run did not complete: the 'deploy' step ended partial.", (string) $staged['result']->message, 'a partial deploy is not a completed run');
    }

    /** Unset field id or script id: the deploy step refuses at staging (fail closed); nothing reaches Tactical. */
    public function test_an_unset_field_or_script_setting_refuses_the_deploy(): void
    {
        // The field id is also one of the six onboarding defaults, so unsetting it turns onboarding off as a whole.
        foreach ([ControlDConfig::TACTICAL_DEPLOY_SCRIPT_SETTING => 'Tactical deploy script ID', 'controld_tactical_client_field_id' => 'not enabled'] as $setting => $text) {
            $f = $this->onboarded();
            $before = Setting::getValue($setting);
            Setting::setValue($setting, '');
            $this->controlD([]);
            $result = $this->stage($f['client'], $f['ticket'], 'all');
            $this->assertStringContainsString($text, (string) ($result['error'] ?? ''), json_encode($result));
            $this->assertSame([], $this->tacticalRequests());
            Setting::setValue($setting, (string) $before);
        }
        $this->assertSame(0, TechnicianRun::count());
        // The deploy service itself refuses either unset setting before any Tactical request.
        $f = $this->onboarded();
        $pins = ['scope' => 'all', 'tactical_client' => self::TC_NAME, 'assets' => []];
        foreach ([ControlDConfig::TACTICAL_DEPLOY_SCRIPT_SETTING, 'controld_tactical_client_field_id'] as $setting) {
            $before = Setting::getValue($setting);
            Setting::setValue($setting, '');
            $out = (new \App\Services\ControlD\ControlDTacticalDeploy(app(TacticalClient::class)))->execute($f['client'], $pins);
            $this->assertSame(['refused', 'the Tactical client custom field ID or the deploy script ID is not set'], [$out['outcome'], $out['reason']]);
            $this->assertSame([], $this->tacticalRequests());
            Setting::setValue($setting, (string) $before);
        }
        // Approval re-checks too: a staged card whose script setting is cleared refuses with no Tactical request.
        $f = $this->onboarded();
        $this->controlD([]);
        $staged = $this->stage($f['client'], $f['ticket'], 'all');
        $this->assertSame([self::SCRIPT_READ], $this->tacticalRequests(), 'staging read the pinned script once');
        $this->tac = [];
        Setting::setValue(ControlDConfig::TACTICAL_DEPLOY_SCRIPT_SETTING, '0');
        $result = $this->approve($staged['run_id']);
        $this->assertSame('gate_declined', $result->status);
        $this->assertSame([], $this->tacticalRequests(), '#8466 diff:7: no Tactical request at all, and no GET scripts/0/');
    }

    /** The code never appears in script arguments, audit rows, logs, the proposal, the result or the run. */
    public function test_the_code_is_absent_from_args_audit_logs_proposal_and_result(): void
    {
        $this->travelTo(now()->startOfSecond());
        $logs = $this->captureLogs();
        $f = $this->fixture();
        $staged = $this->stage($f['client'], $f['ticket'], 'all');
        $this->controlD($this->orgAndCodeResponses());
        // #6605 pin: cross a real second boundary between building the mocked read-back
        // (ts_exp from now()) and approval. Without the frozen clock above this goes red
        // deterministically ('executed_with_fault'); with it, now() cannot move.
        // usleep of a duration from one clock read is always positive (0.05–1.05 s), so a scheduler stall cannot make it error.
        usleep((int) ((1.05 - fmod(microtime(true), 1.0)) * 1_000_000));
        $result = $this->approve($staged['run_id']);
        $this->assertSame('executed', $result->status, (string) $result->message);
        // Positive controls: the code IS what was written to the field, and the logger is captured.
        $this->assertStringContainsString(self::CODE, $this->fieldWriteBody());
        $this->assertCount(2, $this->scriptRuns(), 'positive control: the script runs were sent');
        $this->assertContains(json_encode(['info', 'capture-sentinel', []]), $logs->getArrayCopy());
        foreach ($this->scriptRuns() as $run) {
            $this->assertStringNotContainsString(self::CODE, json_encode($run));
        }
        $this->assertStringNotContainsString(self::CODE, (string) $result->message);
        $this->assertStringNotContainsString(self::CODE, json_encode(TechnicianRun::findOrFail($staged['run_id'])->toArray()));
        $this->assertStringNotContainsString(self::CODE, json_encode(TechnicianActionLog::all()->toArray()));
        $this->assertStringNotContainsString(self::CODE, implode("\n", $logs->getArrayCopy()));
        $this->assertStringNotContainsString(self::CODE, json_encode(session()->all()));
    }

    /** A Tactical failure in the deploy is logged status-only and the code still never appears. */
    public function test_a_failed_field_write_logs_no_code(): void
    {
        $logs = $this->captureLogs();
        $this->putFails = true;
        $this->deployOnly($this->onboarded(), 'all');
        $this->assertGreaterThan(1, count($logs), 'the failed PUT was logged (positive control)');
        $this->assertStringNotContainsString(self::CODE, implode("\n", $logs->getArrayCopy()));
    }

    /** The client-page button carries the deploy scope; the card lists every step and what each writes (G-14). */
    public function test_the_button_stages_a_plan_with_the_chosen_devices_and_the_card_lists_every_write(): void
    {
        $f = $this->fixture();
        $asset = $this->linkedAsset($f['client'], 'agent-a', 'WS-A');
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $page = $this->actingAs($admin)->get(route('clients.show', $f['client']))->assertOk();
        $page->assertSee('data-testid="controld-deploy-scope"', false)->assertSee('WS-A (#'.$asset->id.')');
        $this->actingAs($admin)->post(route('clients.controld.onboard', $f['client']), ['ticket_id' => $f['ticket']->id, 'reason' => 'new client', 'deploy_scope' => 'selected', 'deploy_assets' => [$asset->id]])
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Control D onboarding plan (organization, code, deploy) staged for one cockpit approval'));
        $run = TechnicianRun::sole();
        $this->assertSame([[$asset->id, 'agent-a']], $this->payload($run->id)['deploy']['assets']);
        $c = $run->proposed_content;
        $this->assertStringContainsString('3 steps, approved once, run in this order.', $c);
        $this->assertStringContainsString('1. Organization (writes at Control D): creates a sub-organization', $c);
        $this->assertStringContainsString('2. Provisioning code (writes at Control D): cuts one provisioning code', $c);
        $this->assertStringContainsString('3. Deploy (writes in Tactical RMM): runs only if the provisioning code is bound', $c);
        $this->assertStringContainsString('if it holds a different value, the deploy step refuses and writes nothing', $c);
        // #8466 contract:1 A: the count and the placeholder's presence, never a value; diff:3: the one true mechanism, labelled inferred.
        $this->assertStringContainsString("with the script's own arguments as Tactical listed them when this was staged: 3 arguments; the Control D org-code placeholder is among them. Their values are kept only in this plan's sealed payload and are not shown here; approval refuses if they change. Tactical fills {{client.ControlDOrgCode}} from the client custom field named ControlDOrgCode when it runs the script (inferred from Tactical's source; verified at the production done-check), so this works only when the configured field #".self::FIELD.' is that field. The PSA sends the placeholder, never the code. It runs on 1 named device: WS-A (asset #'.$asset->id.', agent agent-a)', $c);
        foreach (['-OrgCode', '-Quiet', '["'] as $value) {
            $this->assertStringNotContainsString($value, $c, 'no argument value is shown on the card');
        }
        $this->assertStringContainsString('Undo: '.StaffControlDOnboardingToolExecutor::PLAN_UNDO, $c);
        $this->assertStringContainsString('Devices already enrolled are not removed', StaffControlDOnboardingToolExecutor::PLAN_UNDO);
        $this->assertStringContainsString('only the provisioning code can be undone', StaffControlDOnboardingToolExecutor::PLAN_UNDO);
        $this->assertStringContainsString('it does not mean Control D was installed', $c);
        $this->assertStringNotContainsString(self::CODE, $c);
        // Without a deploy choice the plan has no deploy step and the card says nothing about Tactical writes.
        $g = $this->fixture(['name' => 'Second Synthetic']);
        $this->actingAs($admin)->post(route('clients.controld.onboard', $g['client']), ['ticket_id' => $g['ticket']->id, 'reason' => 'x', 'deploy_scope' => 'none'])->assertSessionHas('success');
        $plain = TechnicianRun::where('client_id', $g['client']->id)->sole();
        $this->assertSame(['organization', 'code'], $plain->proposed_meta['redacted_params']['steps']);
        $this->assertStringNotContainsString('Deploy (writes in Tactical RMM)', $plain->proposed_content);
    }

    /**
     * Jeeves 10:51Z (G-14): approved through the cockpit, a plan that stopped flashes on the error
     * channel that the run did not complete and names the step; a completed one does not.
     */
    public function test_the_cockpit_flash_names_the_failed_step_and_says_the_run_did_not_complete(): void
    {
        $f = $this->fixture(['controld_org_id' => 'flashorg01']);
        $this->controlD([$this->ok(['sub_organizations' => [$this->listed('flashorg01')]])]);
        $staged = $this->stage($f['client'], $f['ticket'], 'all');
        $code = $this->codeResponses('flashorg01');
        $this->controlD([$this->ok(['sub_organizations' => [$this->listed('flashorg01')]]), $code[0], $code[1], new Response(503, [], 'upstream')]);
        $this->tac = [];
        $approver = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($approver)->post(route('cockpit.approve', TechnicianRun::findOrFail($staged['run_id'])))->assertRedirect();
        $error = (string) session('error');
        $this->assertStringStartsWith("The onboarding run did not complete: the 'code' step ended uncertain.", $error);
        $this->assertNull(session('success'), 'never on the success channel');
        $this->assertSame([self::SCRIPT_READ], $this->tacticalRequests(), '#8466 diff:7: one read of the pinned script and nothing else');

        // Positive control: a completed deploy-only plan is on the success channel and says it completed.
        $g = $this->onboarded();
        $this->controlD([]);
        $ok = $this->stage($g['client'], $g['ticket'], 'all');
        $this->actingAs($approver)->post(route('cockpit.approve', TechnicianRun::findOrFail($ok['run_id'])))->assertRedirect();
        $this->assertStringStartsWith('Control D onboarding plan completed.', (string) session('success'));
        $this->assertStringNotContainsString('did not complete', (string) session('success'));
        $this->actingAs($approver)->get(route('clients.show', $g['client']))->assertOk()->assertDontSee('data-testid="controld-plan-incomplete"', false);
    }

    /** An onboarded client offers a deploy-only plan; one already locked by an intent refuses it. */
    public function test_a_deploy_only_plan_refuses_while_an_intent_holds_the_client(): void
    {
        $f = $this->onboarded();
        $intent = new ControlDOnboardingIntent;
        $intent->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'client_id' => $f['client']->id, 'actor_id' => 1,
            'active_client_id' => $f['client']->id, 'operation' => 'invalidate', 'state' => 'uncertain', 'phase' => 'post', 'payload' => []])->save();
        $result = $this->stage($f['client'], $f['ticket'], 'all');
        $this->assertStringContainsString('already owns this client', (string) ($result['error'] ?? ''));
        $this->assertSame(0, TechnicianRun::count());
    }

    /** A rejected stop spends the run; staging again resumes from that step (bound steps are not redone). */
    public function test_resume_after_a_rejected_code_skips_the_bound_organization(): void
    {
        $this->travelTo(now()->startOfSecond());
        $f = $this->fixture();
        $staged = $this->stage($f['client'], $f['ticket']);
        $this->assertSame(['organization', 'code'], $staged['steps']);
        $code = $this->codeResponses('syntheticOrg01');
        $this->controlD([$this->ok(['organization' => $this->orgRow()]), $this->ok(['sub_organizations' => [$this->listed()]]), $code[0], $code[1],
            new Response(403, [], file_get_contents(base_path('tests/Fixtures/ControlD/read-only-rejection.json')))]);
        $this->approve($staged['run_id']);
        $this->assertSame(TechnicianRunState::Done, TechnicianRun::find($staged['run_id'])->state);
        $this->assertSame('syntheticOrg01', $f['client']->fresh()->controld_org_id);

        $this->controlD([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $again = $this->stage($f['client']->fresh(), $f['ticket'], 'all');
        $this->assertSame(['code', 'deploy'], $again['steps'], 'the bound organization step is not proposed again');
        $this->assertNotSame($staged['run_id'], $again['run_id']);
        $this->controlD([$this->ok(['sub_organizations' => [$this->listed()]]), ...$this->codeResponses('syntheticOrg01')]);
        $result = $this->approve($again['run_id']);
        $this->assertSame('executed', $result->status, (string) $result->message);
        $this->assertSame(1, ControlDOnboardingIntent::where('operation', 'organization')->count(), 'the organization was created once');
        $this->assertSame(0, collect($this->cd)->filter(fn ($h) => str_contains($h['request']->getUri()->getPath(), 'suborg'))->count());
        $this->assertSame(self::CODE, $f['client']->fresh()->controld_provisioning_code);
    }

    /** The organization inputs and the Tactical field and script ids are pinned and shown on the card; the deploy refuses ids that differ from its pins. */
    public function test_the_organization_inputs_and_the_tactical_ids_are_pinned_on_the_card(): void
    {
        $f = $this->fixture();
        $staged = $this->stage($f['client'], $f['ticket'], 'all');
        $payload = $this->payload($staged['run_id']);
        $this->assertSame(['name' => 'Synthetic Organization', 'contact_email' => 'synthetic@example.invalid', 'stats_endpoint' => 'synthetic-region', 'profile_id' => 'testprofile01'], $payload['org_inputs']);
        $this->assertSame([self::FIELD, self::SCRIPT], [$payload['deploy']['field_id'], $payload['deploy']['script_id']]);
        $c = TechnicianRun::findOrFail($staged['run_id'])->proposed_content;
        $this->assertStringContainsString('analytics region synthetic-region, with the configured global profile testprofile01, and binds', $c);
        $this->assertStringContainsString('Reads custom field #'.self::FIELD.' of Tactical client', $c);
        $this->assertStringContainsString('run the configured deploy script (#'.self::SCRIPT.") with the script's own arguments", $c);

        // The deploy service runs only the pinned ids: a setting that differs from its pin refuses before any Tactical request.
        $g = $this->onboarded();
        $pins = ['scope' => 'all', 'tactical_client' => self::TC_NAME, 'assets' => [], 'field_id' => self::FIELD, 'script_id' => self::SCRIPT, 'script_args' => self::ARGS];
        foreach ([['script_id' => self::SCRIPT + 1], ['field_id' => self::FIELD + 1], ['field_id' => null]] as $other) {
            $this->tac = [];
            $out = (new \App\Services\ControlD\ControlDTacticalDeploy(app(TacticalClient::class)))->execute($g['client'], array_merge($pins, $other));
            $this->assertSame(['refused', 'the Tactical client custom field ID or the deploy script ID differs from the one pinned on the card'], [$out['outcome'], $out['reason']]);
            $this->assertSame([], $this->tacticalRequests());
        }
        $this->tac = [];
        $out = (new \App\Services\ControlD\ControlDTacticalDeploy(app(TacticalClient::class)))->execute($g['client'], $pins);
        $this->assertSame(\App\Services\ControlD\ControlDTacticalDeploy::STARTED, $out['outcome'], 'positive control: the pinned ids run');
    }

    // ── #8466: the deploy script's own arguments ─────────────────────────────────────────

    /**
     * Staging refuses (no run, no Tactical write) unless the script's own args are a non-empty
     * list of strings carrying the placeholder, and never with the code itself in them.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function badScriptArgs(): array
    {
        return [
            'empty' => [[], 'are empty or not a list'],
            'absent' => [null, 'are empty or not a list'],
            'not a list' => [['org' => '{{client.ControlDOrgCode}}'], 'are empty or not a list'],
            'a non-string' => [['{{client.ControlDOrgCode}}', 7], 'are not all text'],
            'no placeholder' => [['-OrgCode', '{{client.SomethingElse}}'], 'do not include {{client.ControlDOrgCode}}'],
            'the code itself' => [['-OrgCode', self::CODE], 'do not include {{client.ControlDOrgCode}}'],
            'the code beside the placeholder' => [['{{client.ControlDOrgCode}}', '-Fallback='.self::CODE], "Argument 2 of the deploy script in Tactical contains this client's provisioning code itself"],
            // Jeeves diff:1 A: another code-shaped run refuses too, by position, never by value.
            'another code-shaped run in a flag' => [['{{client.ControlDOrgCode}}', '-Fallback='.self::RUN], 'Argument 2 of the deploy script in Tactical carries a code-shaped run'],
            'a run joined to the placeholder' => [['-Quiet', '{{client.ControlDOrgCode}}'.self::RUN], 'Argument 2 of the deploy script in Tactical carries a code-shaped run'],
            'a run split by the placeholder' => [['-Quiet', '-Quiet', substr(self::RUN, 0, 16).'{{client.ControlDOrgCode}}'.substr(self::RUN, 16)], 'Argument 3 of the deploy script in Tactical carries a code-shaped run'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badScriptArgs')]
    public function test_staging_refuses_when_the_script_args_are_empty_or_lack_the_placeholder(mixed $args, string $why): void
    {
        $logs = $this->captureLogs();
        $this->scriptArgs = $args;
        $f = $this->onboarded();
        $this->controlD([]);
        $result = $this->stage($f['client'], $f['ticket'], 'all');
        $this->assertStringContainsString($why, (string) ($result['error'] ?? ''), json_encode($result));
        $this->assertStringContainsString('Nothing was staged.', (string) $result['error']);
        $this->assertSame(0, TechnicianRun::count(), 'no plan');
        $this->assertSame([self::SCRIPT_READ], $this->tacticalRequests(), 'one read of the script, nothing else');
        $this->assertStringNotContainsString(self::CODE, json_encode($result));
        $this->assertStringNotContainsString(self::CODE, json_encode(TechnicianActionLog::all()->toArray()));
        $this->assertStringNotContainsString(self::CODE, implode("\n", $logs->getArrayCopy()));
        $this->assertStringNotContainsString('synthetic-script-body', json_encode([$result, TechnicianActionLog::all()->toArray(), $logs->getArrayCopy()]), 'the script body is never returned or audited');
        // Positive control: the same client stages once the script carries the placeholder.
        $this->scriptArgs = self::ARGS;
        $ok = $this->stage($f['client'], $f['ticket'], 'all');
        $this->assertSame(['deploy'], $ok['steps'] ?? null, json_encode($ok));
    }

    /**
     * #8466 diff:4 + context:3 (C-56): a failed or degraded read of the deploy script refuses staging
     * as a READ failure, with the HTTP status (when there is one) and the exception class, and writes
     * one warning line carrying the script id, the status and the class only (never a body or args).
     *
     * @return array<string, array{\Closure, string, ?int, ?string}>
     */
    public static function failedScriptReads(): array
    {
        $json = static fn ($body, int $status = 200) => Create::promiseFor(new Response($status, [], json_encode($body)));

        return [
            'a 404' => [fn () => $json(['detail' => 'SYNTH-BODY-MARKER'], 404), '(HTTP 404, TacticalClientException)', 404, TacticalClientException::class],
            'a 401' => [fn () => $json(['detail' => 'SYNTH-BODY-MARKER'], 401), '(HTTP 401, TacticalClientException)', 401, TacticalClientException::class],
            'a connection failure' => [fn (RequestInterface $r) => Create::rejectionFor(new ConnectException('SYNTH-BODY-MARKER', $r)), '(TacticalClientException)', null, TacticalClientException::class],
            'a 200 with no args key' => [fn ($r, int $id) => $json(['id' => $id, 'name' => 'SYNTH-BODY-MARKER']), "Tactical answered, but not with the script's record", null, null],
            'a 200 for another script' => [fn ($r, int $id) => $json(['id' => $id + 1, 'args' => ['SYNTH-BODY-MARKER', '{{client.ControlDOrgCode}}']]), "Tactical answered, but not with the script's record", null, null],
            // TacticalClient::get() is typed array, so a JSON string body throws a TypeError inside the read.
            'a 200 that is not an object' => [fn () => $json('SYNTH-BODY-MARKER'), '(TypeError)', null, \TypeError::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failedScriptReads')]
    public function test_a_failed_or_degraded_script_read_refuses_as_a_read_failure_with_status_and_class(\Closure $answer, string $detail, ?int $status, ?string $class): void
    {
        $logs = $this->captureLogs();
        $f = $this->onboarded();
        $this->controlD([]);
        $this->scriptAnswer = $answer;
        $result = $this->stage($f['client'], $f['ticket'], 'all');
        $error = (string) ($result['error'] ?? '');
        $this->assertStringContainsString("The deploy step needs the deploy script's own arguments, and ", $error, json_encode($result));
        $this->assertStringContainsString($detail, $error);
        $this->assertStringContainsString("This is a failed read, not a problem with the script's arguments. Nothing was staged.", $error);
        $this->assertStringNotContainsString('are empty or not a list', $error, 'a degraded read is not reported as an args problem');
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame([self::SCRIPT_READ], $this->tacticalRequests(), 'one read; no fallback');
        $warning = json_encode(['warning', '[ControlDTacticalDeploy] The deploy script could not be read for its arguments', ['script_id' => self::SCRIPT, 'status' => $status, 'exception' => $class]]);
        $this->assertSame(1, count(array_keys($logs->getArrayCopy(), $warning, true)), 'exactly one warning line with the script id, status and class only: '.implode("\n", $logs->getArrayCopy()));
        $this->assertStringNotContainsString('SYNTH-BODY-MARKER', implode("\n", $logs->getArrayCopy()), 'no response body in any log line');
        $this->assertStringNotContainsString('SYNTH-BODY-MARKER', json_encode([$result, TechnicianActionLog::all()->toArray()]));
        $this->assertSame(1, TechnicianActionLog::where('result_status', 'rejected')->where('summary', 'like', '%'.$detail.'%')->count(), 'the refusal is audited with the same detail');

        // The same at approval: a card staged on a good read is refused when the re-read fails.
        $this->scriptAnswer = null;
        $staged = $this->stage($f['client'], $f['ticket'], 'all');
        $this->scriptAnswer = $answer;
        $this->tac = [];
        $approval = $this->approve($staged['run_id']);
        $this->assertSame('gate_declined', $approval->status);
        $this->assertStringContainsString("The deploy script's arguments could not be confirmed at approval", (string) $approval->message);
        $this->assertStringContainsString($detail, (string) $approval->message);
        $this->assertSame([self::SCRIPT_READ], $this->tacticalRequests());
    }

    /**
     * Approval re-reads the script's args: a changed list is drift (deny and re-stage), with no
     * Tactical write and no script run; an emptied list or a lost placeholder refuses the same way.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function changedScriptArgs(): array
    {
        return [
            'an argument added' => [[...self::ARGS, '-Verbose'], "the deploy script's own arguments in Tactical differ from the ones pinned on it"],
            'reordered' => [array_reverse(self::ARGS), "the deploy script's own arguments in Tactical differ from the ones pinned on it"],
            'emptied' => [[], "The deploy script's arguments could not be confirmed at approval"],
            'placeholder removed' => [['-OrgCode', 'literal', '-Quiet'], "The deploy script's arguments could not be confirmed at approval"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedScriptArgs')]
    public function test_approval_denies_when_the_script_args_changed_after_staging(mixed $changed, string $why): void
    {
        $f = $this->onboarded();
        $this->controlD([]);
        $staged = $this->stage($f['client'], $f['ticket'], 'all');
        $this->assertSame(self::ARGS, $this->payload($staged['run_id'])['deploy']['script_args']);
        $this->scriptArgs = $changed;
        $this->tac = [];
        $result = $this->approve($staged['run_id']);
        $this->assertSame('gate_declined', $result->status, (string) $result->message);
        $this->assertStringContainsString($why, (string) $result->message);
        $this->assertStringContainsString('stage again', (string) $result->message);
        $this->assertSame([self::SCRIPT_READ], $this->tacticalRequests(), 'no field write and no script run');
        $this->assertSame(TechnicianRunState::AwaitingApproval, TechnicianRun::find($staged['run_id'])->state);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $staged['run_id'])->where('result_status', 'blocked')->where('summary', 'like', '%approval refused%')->count());
        // Positive control: restored, the same card approves and sends the pinned list.
        $this->scriptArgs = self::ARGS;
        $this->assertSame('executed', $this->approve($staged['run_id'])->status);
        $this->assertSame([self::ARGS, self::ARGS], array_column($this->scriptRuns(), 'args'));
    }

    /**
     * Every outbound runscript body carries the pinned placeholder and never the provisioning
     * code; a pin that holds the code refuses before any Tactical request.
     */
    public function test_no_provisioning_code_value_appears_in_any_outbound_runscript_body(): void
    {
        $f = $this->onboarded();
        $asset = $this->linkedAsset($f['client'], 'agent-a', 'WS-A');
        $staged = $this->deployOnly($f, [$asset->id]);
        $this->assertSame('executed', $staged['result']->status, (string) $staged['result']->message);
        $runs = array_values(array_filter($this->tac, static fn (array $t): bool => str_ends_with($t['request']->getUri()->getPath(), '/runscript/')));
        $this->assertCount(1, $runs, 'positive control: a run body was sent');
        foreach ($runs as $t) {
            $this->assertSame('POST', $t['request']->getMethod());
            $body = (string) $t['request']->getBody();
            $this->assertStringContainsString('{{client.ControlDOrgCode}}', json_decode($body, true)['args'][1]);
            $this->assertStringNotContainsString(self::CODE, $body);
        }
        $this->assertStringContainsString(self::CODE, $this->fieldWriteBody(), 'positive control: the code went to the field write');

        // A pinned list that holds the code itself (a tampered card) is refused before any Tactical request.
        $pins = ['scope' => 'all', 'tactical_client' => self::TC_NAME, 'assets' => [], 'field_id' => self::FIELD, 'script_id' => self::SCRIPT];
        foreach ([['{{client.ControlDOrgCode}}', self::CODE], ['{{client.ControlDOrgCode}}', 'x'.self::CODE.'y']] as $tampered) {
            $this->tac = [];
            $out = (new \App\Services\ControlD\ControlDTacticalDeploy(app(TacticalClient::class)))->execute($f['client'], $pins + ['script_args' => $tampered]);
            $this->assertSame('refused', $out['outcome'], json_encode($out));
            $this->assertStringNotContainsString(self::CODE, json_encode($out));
            $this->assertSame([], $this->tacticalRequests());
        }
        // A pin without a usable list (absent, empty, no placeholder) is malformed: refused before any Tactical request.
        foreach ([[], ['script_args' => []], ['script_args' => ['-OrgCode', 'x']]] as $bad) {
            $this->tac = [];
            $out = (new \App\Services\ControlD\ControlDTacticalDeploy(app(TacticalClient::class)))->execute($f['client'], $pins + $bad);
            $this->assertSame(['refused', 'the deploy pins are malformed'], [$out['outcome'], $out['reason']]);
            $this->assertSame([], $this->tacticalRequests());
        }
    }

    /**
     * #8466 Jeeves contract:1 A: a unique marker in one stored argument is found in none of the plaintext
     * places (the card, the run's other meta, audit rows, approval and drift messages, the MCP tool
     * result, log lines, the session), through staging over MCP, a drift refusal at approval, and an
     * approval that executes. It appears only in the encrypted payload and the outbound runscript bodies.
     */
    public function test_an_argument_value_reaches_only_the_sealed_payload_and_the_runscript_body(): void
    {
        $marker = 'SYNTH-ARG-MARKER-q7z';
        $args = ['-OrgCode', '{{client.ControlDOrgCode}}', '-Tag='.$marker];
        $this->scriptArgs = $args;
        $logs = $this->captureLogs();
        $f = $this->onboarded();
        $this->controlD([]);
        $token = \App\Support\McpConfig::rotateStaffToken(allowedTools: ['controld_onboard_client:staged'], label: 'opsbot', aiActor: true);
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'controld_onboard_client', 'arguments' => ['client_id' => $f['client']->id, 'ticket_id' => $f['ticket']->id, 'reason' => 'onboard', 'staged' => true, 'deploy' => 'all']],
        ]);
        $mcp = (string) $response->getContent();
        $staged = json_decode((string) $response->json('result.content.0.text'), true) ?? [];
        $this->assertSame(['deploy'], $staged['steps'] ?? null, $mcp);
        $this->assertSame($args, $this->payload($staged['run_id'])['deploy']['script_args'], 'positive control: the sealed payload carries the marker');

        // Drift: the stored list changes (the marker stays in it); approval refuses.
        $this->scriptArgs = [...$args, '-Verbose'];
        $drift = $this->approve($staged['run_id']);
        $this->assertSame('gate_declined', $drift->status);
        $this->assertStringContainsString("the deploy script's own arguments in Tactical differ from the ones pinned on it", (string) $drift->message);

        // Restored: approval executes and sends the pinned list.
        $this->scriptArgs = $args;
        $this->tac = [];
        $done = $this->approve($staged['run_id']);
        $this->assertSame('executed', $done->status, (string) $done->message);
        $this->assertCount(2, $this->scriptRuns());
        foreach ($this->scriptRuns() as $run) {
            $this->assertSame($args, $run['args'], 'positive control: the runscript body carries the marker');
        }

        $run = TechnicianRun::findOrFail($staged['run_id']);
        $meta = $run->proposed_meta;
        unset($meta['encrypted_payload']);
        $this->assertStringContainsString('3 arguments; the Control D org-code placeholder is among them.', (string) $run->proposed_content);
        $this->assertContains(json_encode(['info', 'capture-sentinel', []]), $logs->getArrayCopy(), 'positive control: the logger is captured');
        $places = [
            'the card' => (string) $run->proposed_content,
            'the run meta' => json_encode($meta),
            'the run row' => json_encode(collect($run->getAttributes())->except('proposed_meta')->all()),
            'the audit rows' => json_encode(TechnicianActionLog::all()->toArray()),
            'the drift message' => (string) $drift->message,
            'the result message' => (string) $done->message,
            'the MCP tool result' => $mcp,
            'the log lines' => implode("\n", $logs->getArrayCopy()),
            'the session' => json_encode(session()->all()),
        ];
        foreach ($places as $where => $text) {
            $this->assertStringNotContainsString($marker, $text, "the argument value reached {$where}");
        }
    }

    /**
     * #8466 Jeeves diff:1 A: execute() refuses a pinned argument that, with every placeholder taken out,
     * still carries a code-shaped run; the reason names the position only. Positive control: the
     * placeholder alone, a placeholder next to ordinary flags, and a 31-character run all run.
     */
    public function test_execute_refuses_a_code_shaped_run_in_a_pinned_argument_by_position_only(): void
    {
        $f = $this->onboarded();
        $pins = ['scope' => 'all', 'tactical_client' => self::TC_NAME, 'assets' => [], 'field_id' => self::FIELD, 'script_id' => self::SCRIPT];
        $cases = [
            [['{{client.ControlDOrgCode}}', '-Fallback='.self::RUN], 2],
            [['-Quiet', '{{client.ControlDOrgCode}}'.self::RUN], 2],
            [['-Quiet', '-Quiet', substr(self::RUN, 0, 16).'{{client.ControlDOrgCode}}'.substr(self::RUN, 16)], 3],
            [['{{client.ControlDOrgCode}}{{client.ControlDOrgCode}}', self::OTHER], 2],
        ];
        foreach ($cases as [$args, $position]) {
            $this->tac = [];
            $out = (new \App\Services\ControlD\ControlDTacticalDeploy(app(TacticalClient::class)))->execute($f['client'], $pins + ['script_args' => $args]);
            $this->assertSame(['refused', "pinned script argument {$position} carries a code-shaped run (32 or more letters and digits) besides the placeholder; no script was run"], [$out['outcome'], $out['reason']], json_encode($args));
            $this->assertSame([], $this->tacticalRequests(), 'refused before any Tactical request');
            $this->assertStringNotContainsString(self::RUN, json_encode($out));
            $this->assertStringNotContainsString(self::OTHER, json_encode($out));
        }
        foreach ([['{{client.ControlDOrgCode}}'], ['-OrgCode', '{{client.ControlDOrgCode}}', '-Quiet'], ['{{client.ControlDOrgCode}}', substr(self::RUN, 0, 31)]] as $fine) {
            $this->tac = [];
            $out = (new \App\Services\ControlD\ControlDTacticalDeploy(app(TacticalClient::class)))->execute($f['client'], $pins + ['script_args' => $fine]);
            $this->assertSame(\App\Services\ControlD\ControlDTacticalDeploy::STARTED, $out['outcome'], 'positive control: '.json_encode([$fine, $out]));
        }
    }

    /**
     * #8466 context:4: an undecryptable stored code refuses at staging and at approval with an audited
     * message; neither the message, the audit rows nor any log line carry exception text or a value.
     * execute() refuses the same way before any Tactical request.
     */
    public function test_an_undecryptable_stored_code_refuses_with_an_audited_message(): void
    {
        $logs = $this->captureLogs();
        $f = $this->onboarded();
        $this->controlD([]);
        $staged = $this->stage($f['client'], $f['ticket'], 'all');
        $this->assertSame(['deploy'], $staged['steps'] ?? null, json_encode($staged));
        // The stored ciphertext no longer decrypts (a rotated key or a legacy row).
        DB::table('clients')->where('id', $f['client']->id)->update(['controld_provisioning_code' => 'not-a-ciphertext']);
        $this->tac = [];
        $approval = $this->approve($staged['run_id']);
        $this->assertSame('gate_declined', $approval->status, (string) $approval->message);
        $why = "The client's stored provisioning code cannot be decrypted, so the deploy script's arguments cannot be checked against it. Fix or clear the stored code first.";
        $this->assertSame("The deploy step could not be re-checked at approval ({$why}). Deny this proposal. Nothing was created and nothing was changed at Control D or in Tactical.", $approval->message);
        $this->assertSame([self::SCRIPT_READ], $this->tacticalRequests());
        $this->assertSame(1, TechnicianActionLog::where('run_id', $staged['run_id'])->where('result_status', 'blocked')->where('summary', 'like', '%approval refused — '.$why.'%')->count());

        $ticket = Ticket::factory()->for($f['client'])->create(['subject' => 'again', 'status' => \App\Enums\TicketStatus::New->value]);
        $result = $this->stage($f['client'], $ticket, 'all');
        $this->assertSame($why.' Nothing was staged.', $result['error'] ?? null, json_encode($result));
        $this->assertSame(1, TechnicianActionLog::where('ticket_id', $ticket->id)->where('result_status', 'rejected')->where('summary', $why.' Nothing was staged.')->count(), 'the staging refusal is audited');

        $pins = ['scope' => 'all', 'tactical_client' => self::TC_NAME, 'assets' => [], 'field_id' => self::FIELD, 'script_id' => self::SCRIPT, 'script_args' => self::ARGS];
        $this->tac = [];
        $out = (new \App\Services\ControlD\ControlDTacticalDeploy(app(TacticalClient::class)))->execute($f['client'], $pins);
        $this->assertSame(['refused', "the client's stored provisioning code cannot be decrypted; no script was run"], [$out['outcome'], $out['reason']]);
        $this->assertSame([], $this->tacticalRequests());

        $all = implode("\n", $logs->getArrayCopy()).json_encode([$approval->message, $result, $out, TechnicianActionLog::all()->toArray()]);
        $this->assertStringContainsString('The stored provisioning code could not be decrypted at plan derivation', $all, 'positive control: the refusal was logged');
        foreach (['not-a-ciphertext', 'The payload is invalid', 'DecryptException', 'Exception'] as $leak) {
            $this->assertStringNotContainsString($leak, $all, "no exception text or value: {$leak}");
        }
    }

    /**
     * A plan close that throws, or that loses its fence, never says the run is closed or reads
     * done; the outcome is recorded before the close, so the client card still shows the run that
     * did not complete, with its actual state. SQLite triggers stand in for the two failed closes.
     *
     * @return array<string, array{string, string}>
     */
    public static function failedCloses(): array
    {
        return [
            'the close throws' => ["RAISE(ABORT, 'synthetic close failure')", 'Closing the run failed afterwards, so it stays claimed'],
            'the close loses its fence' => ['RAISE(IGNORE)', StaffControlDOnboardingToolExecutor::NOT_CLOSED],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failedCloses')]
    public function test_a_plan_close_that_fails_or_loses_its_fence_says_so_and_the_card_still_shows_the_outcome(string $raise, string $says): void
    {
        $f = $this->onboarded();
        $this->putFails = true;
        DB::statement("CREATE TRIGGER synthetic_plan_close BEFORE UPDATE OF state ON technician_runs WHEN NEW.state = 'done' BEGIN SELECT {$raise}; END");
        try {
            $staged = $this->deployOnly($f, 'all');
        } finally {
            DB::statement('DROP TRIGGER synthetic_plan_close');
        }
        $message = (string) $staged['result']->message;
        $this->assertSame('executed_with_fault', $staged['result']->status);
        $this->assertStringStartsWith("The onboarding run did not complete: the 'deploy' step ended uncertain.", $message);
        $this->assertStringContainsString($says, $message);
        $this->assertStringNotContainsString('The run is closed', $message);
        $this->assertStringNotContainsString('reads done', $message);
        // #6579: the run stays claimed, so a re-stage on this ticket stages nothing; no no-lock promise is made.
        $this->assertStringContainsString('Steps already bound are not redone when the plan is staged again; for the deploy step, check the devices and the custom field in Tactical.', $message);
        $this->assertStringNotContainsString('holds no lock', $message);
        $run = TechnicianRun::findOrFail($staged['run_id']);
        $this->assertSame(TechnicianRunState::Executing, $run->state, 'not closed, and never reopened');
        $this->assertSame(['completed' => false, 'failed_step' => 'deploy', 'failed_state' => 'uncertain'], array_slice($run->proposed_meta['plan_outcome'], 0, 3));
        $summary = TechnicianActionLog::where('run_id', $staged['run_id'])->where('summary', 'like', '%plan summary%')->sole()->summary;
        $this->assertStringNotContainsString('closed (state done)', $summary);
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($admin)->get(route('clients.show', $f['client']))->assertOk()
            ->assertSee('data-testid="controld-plan-incomplete"', false)
            ->assertSee("did not complete: the 'deploy' step ended uncertain.", false)
            ->assertSee('Its run state reads executing, not done', false)
            ->assertDontSee('Its run state reads done because', false);
    }
}
