<?php

namespace Tests\Feature\Integrations;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressWriteClient;
use App\Services\Mcp\StaffHuntressActionToolExecutor;
use App\Support\HuntressConfig;
use App\Support\McpConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Huntress one key pair: which stored pair reads and resolve sign with, in
 * each starting state an install can be in, and the per-action MCP token
 * grant as the one control on who may use resolve.
 *
 * Rule (derived from what is stored, nothing else): reads always use the
 * main pair; resolve uses the earlier user pair while BOTH of its halves are
 * stored, otherwise the main pair. Resolve is published and dispatched on
 * isEnabled() && isConfigured().
 *
 * No Huntress call leaves the process: every HTTP client here is a Guzzle
 * MockHandler or a Mockery double, and Http::preventStrayRequests() is on.
 * All values are synthetic.
 */
class HuntressOnePairTest extends TestCase
{
    use RefreshDatabase;

    private const ORG_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function storeMain(string $key = 'synthetic-main-key', string $secret = 'synthetic-main-secret'): void
    {
        Setting::setEncrypted('huntress_api_key', $key);
        Setting::setEncrypted('huntress_api_secret', $secret);
    }

    private function storeEarlier(?string $key = 'synthetic-earlier-key', ?string $secret = 'synthetic-earlier-secret'): void
    {
        if ($key !== null) {
            Setting::setEncrypted('huntress_user_api_key', $key);
        }
        if ($secret !== null) {
            Setting::setEncrypted('huntress_user_api_secret', $secret);
        }
    }

    /** The Basic-auth pair a container-built client would send. */
    private function authOf(string $class): array
    {
        $this->app->forgetInstance($class);
        $client = $this->app->make($class);
        $http = (new \ReflectionProperty($client, 'http'))->getValue($client);

        return $http->getConfig('auth');
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    /** @return array<int, string> */
    private function listedNames(string $token): array
    {
        $tools = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->json('result.tools') ?? [];

        return array_column($tools, 'name');
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    private function resolveIsPublishedToAGrantedToken(): bool
    {
        return in_array('huntress_resolve_escalation', $this->listedNames($this->token(['huntress_resolve_escalation:staged'])), true);
    }

    // ── the four starting states ───────────────────────────────────────────────

    public function test_both_pairs_reads_use_main_and_resolve_keeps_the_earlier_pair_until_cleared(): void
    {
        $this->storeMain();
        $this->storeEarlier();

        $this->assertSame(['synthetic-main-key', 'synthetic-main-secret'], $this->authOf(HuntressClient::class));
        $this->assertSame(['synthetic-earlier-key', 'synthetic-earlier-secret'], $this->authOf(HuntressWriteClient::class));
        $this->assertTrue($this->resolveIsPublishedToAGrantedToken());

        // A new main pair changes reads only.
        $this->storeMain('synthetic-new-key', 'synthetic-new-secret');
        $this->assertSame(['synthetic-new-key', 'synthetic-new-secret'], $this->authOf(HuntressClient::class));
        $this->assertSame(['synthetic-earlier-key', 'synthetic-earlier-secret'], $this->authOf(HuntressWriteClient::class));

        // Only the admin clear moves resolve to the main pair.
        $admin = User::factory()->create(['role' => \App\Enums\UserRole::Admin, 'is_active' => true]);
        $this->actingAs($admin)->post(route('settings.integrations.huntress.update'), ['clear_user_api_key_pair' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(['synthetic-new-key', 'synthetic-new-secret'], $this->authOf(HuntressWriteClient::class));
    }

    public function test_user_pair_only_publishes_nothing_until_a_main_pair_is_saved_then_resolve_keeps_the_earlier_pair(): void
    {
        $this->storeEarlier();

        $this->assertFalse(HuntressConfig::isConfigured());
        $this->assertFalse($this->resolveIsPublishedToAGrantedToken(), 'no main pair: nothing is published');

        $this->storeMain();
        $this->assertTrue($this->resolveIsPublishedToAGrantedToken());
        $this->assertSame(['synthetic-main-key', 'synthetic-main-secret'], $this->authOf(HuntressClient::class));
        $this->assertSame(['synthetic-earlier-key', 'synthetic-earlier-secret'], $this->authOf(HuntressWriteClient::class));
    }

    public function test_account_pair_only_reads_unchanged_and_a_granted_token_sees_resolve_on_the_main_pair(): void
    {
        $this->storeMain();

        $this->assertSame(['synthetic-main-key', 'synthetic-main-secret'], $this->authOf(HuntressClient::class));
        $this->assertSame(['synthetic-main-key', 'synthetic-main-secret'], $this->authOf(HuntressWriteClient::class));
        $this->assertTrue($this->resolveIsPublishedToAGrantedToken());
    }

    public function test_a_lone_earlier_half_is_not_a_pair_and_resolve_uses_the_main_pair(): void
    {
        foreach ([['synthetic-earlier-key', null], [null, 'synthetic-earlier-secret']] as [$key, $secret]) {
            Setting::query()->delete();
            $this->storeMain();
            $this->storeEarlier($key, $secret);

            $this->assertFalse(HuntressConfig::hasEarlierUserPair());
            $this->assertTrue(HuntressConfig::hasEarlierUserPairResidue(), 'the notice must still show so the half can be cleared');
            $this->assertSame(['synthetic-main-key', 'synthetic-main-secret'], $this->authOf(HuntressWriteClient::class));
            $this->assertTrue($this->resolveIsPublishedToAGrantedToken());
        }
    }

    public function test_an_unconfigured_install_publishes_no_resolve_tool_and_reads_have_no_pair(): void
    {
        $this->assertFalse(HuntressConfig::isConfigured());
        $this->assertFalse($this->resolveIsPublishedToAGrantedToken());
        $this->assertSame(['api_key' => null, 'api_secret' => null], HuntressConfig::writeCredentials());
    }

    // ── rule 2: the per-action grant is the one control ────────────────────────

    public function test_a_token_without_the_resolve_grant_neither_sees_nor_calls_either_resolve_tool(): void
    {
        foreach (['main only' => fn () => $this->storeMain(), 'both pairs' => function () {
            $this->storeMain();
            $this->storeEarlier();
        }] as $state => $arrange) {
            Setting::query()->delete();
            $arrange();
            $this->mockWriteNeverCalled();
            $read = Mockery::mock(HuntressClient::class);
            $read->shouldNotReceive('getEscalation');
            $this->app->instance(HuntressClient::class, $read);

            // Precondition: the surface IS live, so an absence below is the grant.
            $this->assertTrue($this->resolveIsPublishedToAGrantedToken(), "{$state}: precondition, resolve is live for a granted token");

            foreach ([['huntress_list_escalations'], null] as $tools) {
                $token = McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
                $names = $this->listedNames($token);
                $this->assertNotEmpty($names, "{$state}: precondition, the token lists tools");
                $this->assertNotContains('huntress_resolve_escalation', $names, "{$state}: listed without the grant");
                $this->assertNotContains('huntress_stage_resolve_escalation', $names, "{$state}: staged alias listed without the grant");

                foreach (['huntress_resolve_escalation', 'huntress_stage_resolve_escalation'] as $tool) {
                    $response = $this->callTool($token, $tool, ['client_id' => 1, 'escalation_id' => 900, 'ticket_id' => 1, 'reason' => 'synthetic', 'staged' => true]);
                    $this->assertTrue((bool) $response->json('result.isError'), "{$state}: {$tool} was callable without the grant");
                    $this->assertStringStartsWith("Tool not allowed for this token: {$tool}", (string) $response->json('result.content.0.text'));
                }
            }
            $this->assertSame(0, TechnicianRun::count(), "{$state}: an ungranted call staged a run");
        }
    }

    public function test_resolve_stays_held_only_once_the_single_pair_is_saved(): void
    {
        $this->storeMain();
        $this->mockWriteNeverCalled();
        $fixture = $this->fixture();

        $token = $this->token(['huntress_resolve_escalation:immediate']);
        $response = $this->callTool($token, 'huntress_resolve_escalation', $this->stageArguments($fixture) + ['staged' => false]);

        $this->assertStringContainsString('held-only', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::count());
    }

    // ── a Huntress 403 on resolve ──────────────────────────────────────────────

    public function test_a_huntress_403_on_resolve_returns_the_plain_sentence_and_changes_nothing(): void
    {
        $this->storeMain();
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $fixture = $this->fixture();

        $this->mockRead();
        $this->mockWriteNeverCalled();
        $stage = $this->callTool($this->token(['huntress_stage_resolve_escalation']), 'huntress_stage_resolve_escalation', $this->stageArguments($fixture));
        $this->assertFalse((bool) $stage->json('result.isError'), (string) $stage->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail(json_decode((string) $stage->json('result.content.0.text'), true)['run_id']);

        // The real write client, on the main pair, against a Guzzle mock that answers 403.
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(403, [], '{"error":"forbidden"}')]));
        $stack->push(Middleware::history($history));
        $this->app->instance(HuntressWriteClient::class, new HuntressWriteClient(
            HuntressConfig::writeCredentials(),
            new GuzzleClient(['base_uri' => 'https://api.huntress.io/v1/', 'handler' => $stack]),
        ));
        $this->mockRead();
        $this->app->forgetInstance(StaffHuntressActionToolExecutor::class);

        $result = app(StaffHuntressActionToolExecutor::class)->approveStagedRun($run, $actor->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(
            "Huntress refused the resolve (HTTP 403): the Huntress user whose API key the PSA uses for resolve may not be allowed to resolve escalations. Check that user's role in Huntress. Nothing was changed.",
            $result->message,
        );
        $this->assertCount(1, $history, 'exactly one resolve POST was sent');
        $this->assertSame('{}', (string) $history[0]['request']->getBody());
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'a refused resolve must leave the run re-approvable');
        $this->assertSame(0, TechnicianRun::where('state', TechnicianRunState::Done)->count());
    }

    public function test_a_non_403_refusal_on_resolve_keeps_its_own_sentence(): void
    {
        $this->storeMain();
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $fixture = $this->fixture();

        $this->mockRead();
        $this->mockWriteNeverCalled();
        $stage = $this->callTool($this->token(['huntress_stage_resolve_escalation']), 'huntress_stage_resolve_escalation', $this->stageArguments($fixture));
        $run = TechnicianRun::findOrFail(json_decode((string) $stage->json('result.content.0.text'), true)['run_id']);

        $stack = HandlerStack::create(new MockHandler([new Response(401, [], '{}')]));
        $this->app->instance(HuntressWriteClient::class, new HuntressWriteClient(
            HuntressConfig::writeCredentials(),
            new GuzzleClient(['base_uri' => 'https://api.huntress.io/v1/', 'handler' => $stack]),
        ));
        $this->mockRead();
        $this->app->forgetInstance(StaffHuntressActionToolExecutor::class);

        $result = app(StaffHuntressActionToolExecutor::class)->approveStagedRun($run, $actor->id);

        $this->assertSame('Huntress rejected the resolve call — nothing was resolved. Try again.', $result->message);
    }

    // ── the approval gate re-checks the state at approve time ──────────────────

    public function test_an_approval_after_the_integration_is_switched_off_is_declined_and_sends_nothing(): void
    {
        $this->storeMain();
        [$run, $actor] = $this->stageARun();

        Setting::setValue('huntress_enabled', '0');

        $this->assertApprovalDeclinedAndNothingSent($run, $actor);
    }

    public function test_an_approval_after_the_main_pair_is_removed_is_declined_and_sends_nothing(): void
    {
        // The earlier pair stays stored, so writeCredentials() still has a
        // pair to sign with: only the approve-time isConfigured() check stops it.
        $this->storeMain();
        $this->storeEarlier();
        [$run, $actor] = $this->stageARun();

        Setting::whereIn('key', ['huntress_api_key', 'huntress_api_secret'])->delete();
        $this->assertSame(['api_key' => 'synthetic-earlier-key', 'api_secret' => 'synthetic-earlier-secret'], HuntressConfig::writeCredentials(), 'precondition: a signing pair is still stored');

        $this->assertApprovalDeclinedAndNothingSent($run, $actor);
    }

    // ── publish: a switched-off install ────────────────────────────────────────

    public function test_a_switched_off_install_does_not_list_resolve_to_a_granted_token(): void
    {
        $this->storeMain();
        $this->assertTrue($this->resolveIsPublishedToAGrantedToken(), 'precondition: resolve is listed while the integration is on');

        Setting::setValue('huntress_enabled', '0');

        $token = $this->token(['huntress_resolve_escalation:staged', 'huntress_stage_resolve_escalation']);
        $names = $this->listedNames($token);
        $this->assertNotEmpty($names, 'precondition: the token lists tools');
        $this->assertNotContains('huntress_resolve_escalation', $names);
        $this->assertNotContains('huntress_stage_resolve_escalation', $names);
    }

    // ── fixtures ───────────────────────────────────────────────────────────────

    /** Stage one resolve through the MCP surface; returns the run and the approver. */
    private function stageARun(): array
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $fixture = $this->fixture();

        $this->mockRead();
        $this->mockWriteNeverCalled();
        $stage = $this->callTool($this->token(['huntress_stage_resolve_escalation']), 'huntress_stage_resolve_escalation', $this->stageArguments($fixture));
        $this->assertFalse((bool) $stage->json('result.isError'), (string) $stage->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail(json_decode((string) $stage->json('result.content.0.text'), true)['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state, 'precondition: the run is staged');

        return [$run, $actor];
    }

    /** Approve against the real write client on a Guzzle mock that would answer 201; expect a decline and no request. */
    private function assertApprovalDeclinedAndNothingSent(TechnicianRun $run, User $actor): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201, [], json_encode(['id' => 900, 'status' => 'resolved', 'resolution_method' => 'direct']))]));
        $stack->push(Middleware::history($history));
        $this->app->instance(HuntressWriteClient::class, new HuntressWriteClient(
            ['api_key' => 'synthetic-any-key', 'api_secret' => 'synthetic-any-secret'],
            new GuzzleClient(['base_uri' => 'https://api.huntress.io/v1/', 'handler' => $stack]),
        ));
        $this->mockRead();
        $this->app->forgetInstance(StaffHuntressActionToolExecutor::class);

        $result = app(StaffHuntressActionToolExecutor::class)->approveStagedRun($run, $actor->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame('Huntress is not enabled or its API key and API secret are not saved — nothing was resolved.', $result->message);
        $this->assertCount(0, $history, 'a request was sent to Huntress');
        $this->assertSame(0, TechnicianRun::where('state', TechnicianRunState::Done)->count());
    }

    private function mockWriteNeverCalled(): void
    {
        $mock = Mockery::mock(HuntressWriteClient::class);
        $mock->shouldNotReceive('resolveEscalation');
        $this->app->instance(HuntressWriteClient::class, $mock);
    }

    private function mockRead(): void
    {
        $mock = Mockery::mock(HuntressClient::class);
        $mock->shouldReceive('withClampedBackoff')->andReturnSelf();
        $mock->shouldReceive('getEscalation')->andReturn([
            'id' => 900, 'status' => 'sent', 'resolved_at' => null, 'severity' => 'high',
            'type' => 'identity', 'subtype' => 'synthetic', 'subject' => 'Synthetic escalation',
            'organizations' => [['id' => self::ORG_ID, 'name' => 'Example Org']],
        ]);
        $this->app->instance(HuntressClient::class, $mock);
    }

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(): array
    {
        $client = Client::factory()->create(['name' => 'Example Dental Co.', 'huntress_organization_id' => self::ORG_ID]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Synthetic SOC follow-up']);

        return compact('client', 'ticket');
    }

    private function stageArguments(array $fixture): array
    {
        return [
            'client_id' => $fixture['client']->id,
            'escalation_id' => 900,
            'ticket_id' => $fixture['ticket']->id,
            'reason' => 'Synthetic reason for the test.',
        ];
    }
}
