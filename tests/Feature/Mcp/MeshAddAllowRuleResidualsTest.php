<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\StaffMeshAdminToolExecutor;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * #5131 residuals, batch 1: mesh_add_allow_rule after the permanent-by-default
 * ruling (Charlie, 2026-10-05).
 *
 *  - #5155: the staging audit row states the lifetime in words.
 *  - #5156: no text claims the PSA can never remove a permanent rule; its own
 *           removal and edit verbs can (G-14).
 *  - #5158: a proposal staged before the deploy (hashed with 'default', a
 *           staging-time+90d payload) is not re-staged by an omitted-key call.
 *  - #5159: an approved payload with no expires_at key is refused, never
 *           silently released as a PERMANENT rule.
 *
 * Synthetic data only (G-13): example.test senders, no client names.
 */
class MeshAddAllowRuleResidualsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const SENDER = 'billing@vendor.example.test';

    private const DOMAIN = 'vendor.example.test';

    private function configureMesh(): void
    {
        Setting::setEncrypted('mesh_api_key', 'k');
    }

    private function configureAiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function token(): string
    {
        return McpConfig::rotateStaffToken(allowedTools: ['mesh_add_allow_rule:staged'], label: 'opsbot');
    }

    private function callTool(array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'mesh_add_allow_rule', 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(): array
    {
        $client = Client::factory()->create(['name' => 'Example Client', 'mesh_customer_id' => self::TENANT]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Vendor mail quarantined']);

        return compact('client', 'ticket');
    }

    /** @return array<string, mixed> */
    private function stageArgs(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'sender' => self::SENDER,
            'confirm_domain' => self::DOMAIN,
            'reason' => 'Vendor invoices are being quarantined; approved by the client contact.',
        ], $overrides);
    }

    private function mockWrite(): Mockery\MockInterface
    {
        $client = Mockery::mock(MeshWriteClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $this->app->instance(MeshWriteClient::class, $client);

        return $client;
    }

    private function stage(array $fixture, array $overrides = []): TechnicianRun
    {
        $result = $this->decodedResult($this->callTool($this->stageArgs($fixture, $overrides)));
        $this->assertArrayHasKey('run_id', $result, 'staging failed: '.json_encode($result));
        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return $run;
    }

    /** Rewrite the approved arguments in a run's encrypted payload. */
    private function rewritePayloadArguments(TechnicianRun $run, callable $mutate): void
    {
        $meta = $run->proposed_meta;
        $payload = json_decode(Crypt::decryptString($meta['encrypted_payload']), true);
        $payload['arguments'] = $mutate($payload['arguments']);
        $meta['encrypted_payload'] = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $run->forceFill(['proposed_meta' => $meta])->save();
    }

    private function stagingAuditSummary(TechnicianRun $run): string
    {
        return (string) TechnicianActionLog::query()
            ->where('action_type', 'mesh_stage_add_allow_rule')
            ->where('result_status', 'awaiting_approval')
            ->where('run_id', $run->id)
            ->sole()
            ->summary;
    }

    // ---- #5155: the staging audit row states the lifetime ---------------------

    /**
     * The awaiting_approval row is the only durable record of a proposal that
     * is later denied or never executed, so it names the lifetime in words, as
     * the edit verb's staging row does: PERMANENT for an omitted key and for
     * 'never', the date for a dated proposal.
     */
    public function test_the_staging_audit_row_states_the_lifetime_in_words(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $this->mockWrite();

        // One client, three tickets: the brakes are per ticket, so each case
        // stages its own proposal for the same sender.
        $omitted = $this->fixture();
        $ticketFor = fn (): array => ['client' => $omitted['client'], 'ticket' => Ticket::factory()->for($omitted['client'])->create(['subject' => 'Vendor mail quarantined'])];

        $args = $this->stageArgs($omitted);
        $this->assertArrayNotHasKey('expires_at', $args, 'the first case is the ABSENT key');
        $run = $this->stage($omitted);
        $this->assertStringContainsString("MCP staged Mesh allow rule for '".self::SENDER."': PERMANENT", $this->stagingAuditSummary($run));

        $never = $ticketFor();
        $run = $this->stage($never, ['expires_at' => 'never']);
        $this->assertStringContainsString("MCP staged Mesh allow rule for '".self::SENDER."': PERMANENT", $this->stagingAuditSummary($run));

        $dated = $ticketFor();
        $chosen = now()->addDays(7)->startOfSecond();
        $run = $this->stage($dated, ['expires_at' => $chosen->toIso8601String()]);
        $summary = $this->stagingAuditSummary($run);
        $this->assertStringContainsString('expires '.$chosen->toDayDateTimeString().' UTC', $summary);
        $this->assertStringNotContainsString('PERMANENT', $summary);
    }

    // ---- #5156: what ends a permanent rule is named truthfully (G-14) ---------

    /**
     * mesh_remove_allow_rule removes PSA-created rules, permanent ones too, and
     * mesh_edit_allow_rule can date one so the reaper removes it. So no text
     * may say the PSA can never remove a permanent rule, or that undoing it
     * needs the Mesh portal. The approval card, the advertised text and the
     * executed audit summary are each checked.
     */
    public function test_no_permanent_rule_text_claims_the_psa_can_never_remove_it(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $false = '/NEVER remove|never removes? it|will ever remove|NEVER removed by the PSA|ever delete it|by hand in the Mesh portal/i';

        $definitions = collect(StaffMeshAdminToolExecutor::definitions())->keyBy('name');
        $advertised = [
            'direct description' => $definitions['mesh_add_allow_rule']['description'],
            'staged description' => $definitions['mesh_stage_add_allow_rule']['description'],
            'expires_at' => $definitions['mesh_add_allow_rule']['input_schema']['properties']['expires_at']['description'],
        ];
        foreach ($advertised as $label => $text) {
            $this->assertDoesNotMatchRegularExpression($false, $text, $label);
            $this->assertStringContainsString('mesh_remove_allow_rule', $text, $label.' names the verb that does remove it');
        }

        $run = $this->stage($fixture);
        $this->assertDoesNotMatchRegularExpression($false, $run->proposed_content);
        $this->assertStringContainsString('PERMANENTLY', $run->proposed_content);
        $this->assertStringContainsString('nothing removes it automatically', $run->proposed_content);
        $this->assertStringContainsString('mesh_remove_allow_rule', $run->proposed_content);
        $this->assertStringContainsString('mesh_edit_allow_rule', $run->proposed_content);

        $comment = (string) $run->proposed_meta['redacted_params']['comment'];
        $write->shouldReceive('createAllowRule')->once()
            ->with(self::TENANT, self::SENDER, $comment, null)
            ->andReturn(['detail' => 'ok', 'added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'rule-perm', 'created_by' => 'owner@soundit.example.test']);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $summary = (string) TechnicianActionLog::query()
            ->where('action_type', 'mesh_add_allow_rule')
            ->where('result_status', 'executed')
            ->sole()
            ->summary;
        $this->assertStringContainsString('PERMANENT', $summary);
        $this->assertDoesNotMatchRegularExpression($false, $summary);
        $this->assertStringContainsString('mesh_remove_allow_rule', $summary);
    }

    // ---- #5158: a pre-deploy 'default'-hashed proposal ------------------------

    /**
     * The proposal content hash as the pre-#5131 code computed it
     * (StaffMeshAdminToolExecutor::contentHash at 07806ab0): an omitted key was
     * hashed as the literal 'default'. Replicated here because the current
     * code no longer produces it; the positive control below proves the
     * replica against the current code's own hash for the resolved lifetime.
     */
    private function proposalHash(int $clientId, string $expiresAtToken): string
    {
        $params = ['mesh_customer_id' => self::TENANT, 'expires_at' => $expiresAtToken];
        ksort($params);

        return hash('sha256', json_encode([
            'tool' => 'mesh_stage_add_allow_rule',
            'client_id' => $clientId,
            'target' => 'allow-rule-'.self::SENDER,
            'params' => $params,
        ]));
    }

    /**
     * A run staged BEFORE the deploy with an omitted key carries the 'default'
     * content hash and a payload ISO of staging-time + 90 days. After the
     * deploy the same omitted-key call hashes as 'never', so it does not match
     * that run as "already staged". The answer pinned here is the refusal: it
     * names the different (dated) lifetime and the run to settle, and stages
     * nothing. The two really are different lifetimes (90 days vs permanent).
     */
    public function test_a_pre_deploy_default_hashed_proposal_is_named_not_restaged(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $this->mockWrite();
        $fixture = $this->fixture();
        $clientId = $fixture['client']->id;

        // Positive control on the replica: for the omitted key the current
        // code hashes the resolved 'never', so a fresh omitted-key staging on
        // another ticket must land on exactly the replica's 'never' hash.
        $control = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Control ticket']);
        $controlRun = $this->stage(['client' => $fixture['client'], 'ticket' => $control]);
        $this->assertSame($this->proposalHash($clientId, 'never'), $controlRun->content_hash, 'the hash replica must match the code');
        $controlRun->forceFill(['state' => TechnicianRunState::Denied])->save();

        // The pre-deploy run: staged with a staging-time+90d ISO (what the old
        // omitted key resolved to), then given the old 'default' hash.
        $stagedAt = now()->subDays(2)->startOfSecond();
        $old = null;
        $this->travelTo($stagedAt, function () use ($fixture, $stagedAt, &$old) {
            $old = $this->stage($fixture, ['expires_at' => $stagedAt->copy()->addDays(90)->toIso8601String()]);
        });
        // #5414: the hash is what decides between the two answers, so it is
        // shown deciding. Collision control first: were the old run hashed
        // as 'never', liveAwaitingRun() would match it and the omitted-key
        // call would be told "Already staged" against a 90-day run, which is
        // the silent lifetime substitution. Only the 'default' rewrite below
        // turns that answer into the refusal this test pins, so deleting it
        // fails the test.
        $old->forceFill(['content_hash' => $this->proposalHash($clientId, 'never')])->save();
        $collided = $this->decodedResult($this->callTool($this->stageArgs($fixture)));
        $this->assertTrue($collided['idempotent'] ?? false, 'control: a never-hashed old run is matched as already staged, got: '.json_encode($collided));
        $this->assertSame($old->id, $collided['run_id']);

        // What 07806ab0 wrote for an omitted key. contentHash() itself is
        // byte-identical at 07806ab0 and here (tool, client_id, target,
        // ksorted params), and stageAllowRule() there passed the same two
        // params (mesh_customer_id, expires_at), so the replica proved for
        // 'never' above is the same function for 'default'.
        $old->forceFill(['content_hash' => $this->proposalHash($clientId, 'default')])->save();

        $response = $this->callTool($this->stageArgs($fixture));

        $this->assertTrue((bool) $response->json('result.isError'), 'expected a refusal, got: '.$response->json('result.content.0.text'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('already awaiting approval on this ticket with a different lifetime', $text);
        $this->assertStringContainsString('(expires '.$stagedAt->copy()->addDays(90)->toDayDateTimeString().' UTC)', $text);
        $this->assertStringContainsString('approve or deny run #'.$old->id, $text);
        $this->assertStringNotContainsString('Already staged', $text);

        // Nothing new staged, and the old run is untouched.
        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)->count());
        $old->refresh();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $old->state);
        $this->assertSame($this->proposalHash($clientId, 'default'), $old->content_hash);
        $this->assertSame(1, TechnicianActionLog::query()
            ->where('action_type', 'mesh_stage_add_allow_rule')
            ->where('result_status', 'blocked')
            ->where('run_id', $old->id)
            ->count());
    }

    // ---- #5159: an approved payload with no expires_at -----------------------

    /**
     * stageAllowRule() always writes expires_at into the payload, so a payload
     * without it was not written by current code, and its approver was never
     * shown PERMANENT. It is refused with status-only wording, exactly as the
     * edit verb refuses it, before any Mesh call and without a record.
     */
    public function test_an_approved_payload_with_no_expires_at_is_refused_not_made_permanent(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $run = $this->stage($fixture, ['expires_at' => now()->addDays(30)->toIso8601String()]);
        $this->rewritePayloadArguments($run, function (array $arguments): array {
            unset($arguments['expires_at']);

            return $arguments;
        });

        $write->shouldNotReceive('createAllowRule');
        $write->shouldNotReceive('findRuleByComment');

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertSame(
            'The approved proposal carries no expires_at; nothing was changed. Stage a new proposal.',
            (string) session('error'),
        );

        $this->assertSame(0, MeshAllowRule::count());
        $this->assertNotSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed')->count());
        $this->assertSame(1, TechnicianActionLog::query()
            ->where('action_type', 'mesh_add_allow_rule')
            ->where('result_status', 'rejected')
            ->where('summary', 'The approved proposal carries no expires_at; nothing was changed. Stage a new proposal.')
            ->count());
    }

    /**
     * A key that is PRESENT but null is not "no expires_at": the payload says
     * something was meant, and requestedExpiry() refuses it as empty in its own
     * words. Pinned so the guard stays array_key_exists (as in the edit verb)
     * and is not loosened to isset, which would mislabel this case as absent.
     * Fail closed either way: no Mesh call, no record.
     */
    public function test_an_approved_payload_with_a_null_expires_at_is_refused_as_empty(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $run = $this->stage($fixture);
        $this->rewritePayloadArguments($run, function (array $arguments): array {
            $arguments['expires_at'] = null;

            return $arguments;
        });

        $write->shouldNotReceive('createAllowRule');

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $error = (string) session('error');
        $this->assertStringContainsString('expires_at was empty', $error);
        $this->assertStringContainsString('No upstream call was made', $error);
        $this->assertStringNotContainsString('carries no expires_at', $error);
        $this->assertSame(0, MeshAllowRule::count());
    }
}
