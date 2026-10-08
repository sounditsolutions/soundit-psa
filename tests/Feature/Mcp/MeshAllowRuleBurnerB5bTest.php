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
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * #5389 residuals, burner b5b: Mesh allow-rule wording.
 *
 *  - #5410: the post-execution dedup answer names what is true of the record
 *           in the state it is in. A REMOVED record is not "PERMANENT ... it
 *           stays until someone removes it", and a permanent record with no
 *           upstream id is not reachable by the remove or edit verb.
 *  - #5412: every reworded #5156 runtime string is pinned on its own
 *           emitting arm, so reverting any one of them fails here.
 *
 * Every state below is reached through the verbs themselves (stage, approve,
 * remove, edit), not hand-written, except where a test says so. No test in
 * this file runs the reaper (b5c #5497 item 8); MeshAllowRuleBurnerB5cTest
 * reaches the reaped and unresolved states through it.
 * Synthetic data only (G-13): example.test senders, no client names.
 */
class MeshAllowRuleBurnerB5bTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const SENDER = 'billing@vendor.example.test';

    private const DOMAIN = 'vendor.example.test';

    private const PERMANENT_UNTIL = 'it stays until someone removes it (mesh_remove_allow_rule) or gives it a date (mesh_edit_allow_rule)';

    private const PERMANENT_UNTIL_NO_ID = 'the PSA holds no upstream rule id for it, so mesh_edit_allow_rule cannot give it a date, mesh_remove_allow_rule would not close this record, and the expiry job never removes a rule with no expiry; this PSA record keeps blocking new allow rules for this sender until someone checks the rule in the Mesh portal and clears the record by hand';

    /**
     * G-5 (#5651, #5656, #5753): Http is faked and stray requests are
     * prevented for anything that goes through the Http facade. MeshClient
     * and MeshWriteClient build their own Guzzle clients, so neither the
     * Http fake nor preventStrayRequests() sees their traffic; their guard
     * is at the container instead. Both are bound to Mockery doubles with
     * no expectations. A call a double was not set up for throws AT THE
     * CALL; a path that catches Throwable can turn that throw into a
     * refusal the test never sees, so the doubles prove no real Mesh
     * traffic left the box, not that every stray call fails a test (#5757).
     * A test that needs MeshWriteClient replaces its double with one
     * carrying expectations (mockWrite()).
     */
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([]);
        Http::preventStrayRequests();
        $this->app->instance(MeshClient::class, Mockery::mock(MeshClient::class));
        $this->app->instance(MeshWriteClient::class, Mockery::mock(MeshWriteClient::class));
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private const ONE_ID_ONLY = 'That proves only that the rule this record tracked is gone; the PSA has not checked whether any other rule for this sender is in force on the tenant.';

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

    private ?string $token = null;

    private function token(): string
    {
        return $this->token ??= McpConfig::rotateStaffToken(
            allowedTools: ['mesh_add_allow_rule:staged', 'mesh_remove_allow_rule:staged', 'mesh_edit_allow_rule:staged'],
            label: 'opsbot',
        );
    }

    private function callTool(string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
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

    private function anotherTicket(array $fixture): Ticket
    {
        return Ticket::factory()->for($fixture['client'])->create(['subject' => 'Same vendor, another ticket']);
    }

    /** @return array<string, mixed> */
    private function addArgs(array $fixture, array $overrides = []): array
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

    /** Stage an add through the MCP verb and return its awaiting run. */
    private function stageAdd(array $fixture, array $overrides = []): TechnicianRun
    {
        $result = $this->decodedResult($this->callTool('mesh_add_allow_rule', $this->addArgs($fixture, $overrides)));
        $this->assertArrayHasKey('run_id', $result, 'staging failed: '.json_encode($result));
        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return $run;
    }

    /** @return array<string, mixed> */
    private function restage(array $fixture): array
    {
        return $this->decodedResult($this->callTool('mesh_add_allow_rule', $this->addArgs($fixture, [
            'ticket_id' => $this->anotherTicket($fixture)->id,
        ])));
    }

    /** A row as MeshWriteClient::findRuleById() hands it back. */
    private function upstreamRow(string $ruleId, string $comment): array
    {
        return [
            'id' => $ruleId,
            'sender' => self::SENDER,
            'comment' => $comment,
            'ab' => MeshWriteClient::ALLOW_RULE,
            'created_by' => 'owner@soundit.example.test',
            'date_expiry' => null,
        ];
    }

    /** Approve a staged add whose create proves scope and recovers $ruleId. */
    private function approveCleanCreate(User $actor, Mockery\MockInterface $write, TechnicianRun $run, string $ruleId): MeshAllowRule
    {
        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => $ruleId, 'created_by' => 'owner@soundit.example.test']);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $record = MeshAllowRule::where('technician_run_id', $run->id)->sole();
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state);
        $this->assertSame($ruleId, $record->mesh_rule_id);

        return $record;
    }

    /**
     * A permanent ACTIVE record with no upstream id, reached through the
     * verbs: a dated rule is created cleanly; an approved edit makes it
     * permanent; after the PATCH Mesh no longer returns the rule under that
     * id, so executeEditAllowRule()'s display_id_lost path clears the id.
     */
    private function idLessPermanentRecord(User $actor, Mockery\MockInterface $write, array $fixture): MeshAllowRule
    {
        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['expires_at' => now()->addDays(30)->toIso8601String()]), 'rule-b5b-lost');

        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow('rule-b5b-lost', $record->comment));
        $edit = $this->decodedResult($this->callTool('mesh_edit_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'rule_id' => 'rule-b5b-lost',
            'confirm_sender' => self::SENDER,
            'expires_at' => 'never',
            'reason' => 'The vendor relationship is permanent.',
        ]));
        $this->assertArrayHasKey('run_id', $edit, json_encode($edit));
        $write->shouldReceive('patchRule')->once()->andReturn([]);
        $write->shouldReceive('findRuleById')->once()->andReturn(null);
        $this->actingAs($actor)->post(route('cockpit.approve', TechnicianRun::findOrFail($edit['run_id'])))->assertSessionHas('error');
        $this->assertStringContainsString('its recorded rule id was cleared', (string) session('error'));

        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state);
        $this->assertTrue($record->isPermanent());
        $this->assertNull($record->mesh_rule_id);

        return $record;
    }

    /**
     * The claim PERMANENT_UNTIL_NO_ID makes, executed: the rule is still in
     * Mesh (re-keyed), the edit verb refuses it as FOREIGN, and the remove
     * verb finds no record under the new id, so it could not close the record.
     * Since #5570 the remove card does not call the rule foreign: it names the
     * id-less record that matches by sender and comment and says it stays.
     */
    private function assertTheVerbsCannotReachTheRecord(array $fixture, Mockery\MockInterface $write, MeshAllowRule $record): void
    {
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow('rule-b5b-rekeyed', $record->comment));

        $edit = $this->decodedResult($this->callTool('mesh_edit_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'rule_id' => 'rule-b5b-rekeyed',
            'confirm_sender' => self::SENDER,
            'expires_at' => now()->addDays(10)->toIso8601String(),
            'reason' => 'Give it a date.',
        ]));
        $this->assertStringContainsString('is FOREIGN', $edit['error'] ?? json_encode($edit));

        $remove = $this->decodedResult($this->callTool('mesh_remove_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'rule_id' => 'rule-b5b-rekeyed',
            'confirm_sender' => self::SENDER,
            'reason' => 'Remove it.',
        ]));
        $this->assertArrayHasKey('run_id', $remove, json_encode($remove));
        $card = TechnicianRun::findOrFail($remove['run_id'])->proposed_content;
        $this->assertStringContainsString("The PSA holds no record under this rule id, but PSA record #{$record->id} (state 'active', no recorded rule id) carries this rule's sender and comment. ", $card);
        $this->assertStringContainsString('That record is NOT closed by this removal.', $card);
        $this->assertStringContainsString('removing it may break mail delivery that is working today', $card);
        $this->assertStringNotContainsString('FOREIGN', $card);
    }

    // ---- #5410: the dedup answer is true of the record's state ---------------

    /**
     * (a) of #5410, reached through the verbs: a permanent rule is created,
     * then removed by an approved mesh_remove_allow_rule (proved 404, record
     * STATE_REMOVED), and the same sender is re-staged inside the 24-hour
     * post-execution window. The answer must not tell the caller the rule
     * "stays until someone removes it": it was removed. Proved absent is not an idempotent success, so it is refused as
     * a reaped record is: no success, no idempotent, no expires_at.
     */
    public function test_the_dedup_answer_for_a_removed_record_says_removed_not_permanent(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5b-removed');
        $this->assertTrue($record->isPermanent());

        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow('rule-b5b-removed', $record->comment));
        $removeTicket = $this->anotherTicket($fixture);
        $this->callTool('mesh_remove_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $removeTicket->id,
            'rule_id' => 'rule-b5b-removed',
            'confirm_sender' => self::SENDER,
            'reason' => 'The vendor now authenticates its mail.',
        ])->assertOk();
        $removal = TechnicianRun::where('action_type', 'mesh_stage_remove_allow_rule')->sole();
        $write->shouldReceive('deleteRule')->once()->with('rule-b5b-removed');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-b5b-removed')->andReturn(true);
        $this->actingAs($actor)->post(route('cockpit.approve', $removal))->assertSessionHas('success');
        $this->assertSame(MeshAllowRule::STATE_REMOVED, $record->fresh()->state);

        $runsBefore = TechnicianRun::count();
        $blocked = static fn (): int => TechnicianActionLog::query()
            ->where('action_type', 'mesh_stage_add_allow_rule')
            ->where('result_status', 'blocked')
            ->count();
        $blockedBefore = $blocked();
        $answer = $this->restage($fixture);

        // The machine-readable channel: a proved-absent rule is not a success.
        $this->assertArrayNotHasKey('success', $answer);
        $this->assertArrayNotHasKey('idempotent', $answer);
        $this->assertArrayNotHasKey('expires_at', $answer);
        // #5485: the refusal is on the error key itself, never found only
        // inside a JSON encoding of some other key.
        $this->assertArrayHasKey('error', $answer, json_encode($answer));
        $error = $answer['error'];
        // #5486: the removal proved ONE recorded id absent, so the text says
        // that and never that no allow is in force for the sender.
        $this->assertStringContainsString(
            'as PSA record #'.$record->id.", but an approved mesh_remove_allow_rule has since proved it absent upstream (state 'removed'). ".self::ONE_ID_ONLY.' Nothing was staged now.',
            $error,
        );
        $this->assertStringNotContainsString('NO allow is in force', $error);
        $this->assertStringNotContainsString('create the rule by hand', $error);
        $this->assertStringNotContainsString('PERMANENT', $error);
        $this->assertStringNotContainsString('stays until', $error);
        $this->assertSame($runsBefore, TechnicianRun::count(), 'nothing was staged');
        $this->assertSame($blockedBefore + 1, $blocked(), 'the refusal is audited as a refusal');
    }

    /**
     * A permanent record with NO upstream id, reached through the verbs: a
     * dated rule is created cleanly, then an approved mesh_edit_allow_rule
     * makes it permanent and Mesh stops returning its id after the PATCH
     * (display_id_lost clears mesh_rule_id). Re-staged inside 24 hours, the
     * dedup answer is a refusal (b5c #5490: the PSA no longer knows the rule
     * exists) and must not offer the edit or remove verb as what ends it,
     * and the two verbs are then called to show that claim is true: the edit
     * is refused as FOREIGN and the removal card labels it FOREIGN.
     */
    public function test_the_dedup_answer_for_a_permanent_record_without_an_id_does_not_offer_the_verbs(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->idLessPermanentRecord($actor, $write, $fixture);

        $answer = $this->restage($fixture);

        // b5c #5490: the PSA no longer knows the rule exists, so this is a
        // refusal, never success/idempotent/'already created'.
        $this->assertArrayNotHasKey('success', $answer);
        $this->assertArrayNotHasKey('idempotent', $answer);
        $this->assertArrayHasKey('error', $answer, json_encode($answer));
        $this->assertStringNotContainsString('already created', $answer['error']);
        $this->assertStringContainsString("PSA record #{$record->id}, but Mesh stopped returning the rule under its recorded id after an approved mesh_edit_allow_rule, and that id was cleared (state 'active')", $answer['error']);
        $this->assertStringContainsString('It is PERMANENT, and '.self::PERMANENT_UNTIL_NO_ID.'.', $answer['error']);
        $this->assertStringNotContainsString(self::PERMANENT_UNTIL, $answer['error']);

        $this->assertTheVerbsCannotReachTheRecord($fixture, $write, $record);
    }

    /**
     * The same id-less permanent record after the 24-hour window: the live
     * brakes answer it now, at staging and at approval: both refuse (b5c
     * #5490) and neither may offer the verbs. Card B was staged before the record existed, as two
     * technicians raising one sender on two tickets would.
     */
    public function test_the_live_answers_for_a_permanent_record_without_an_id_do_not_offer_the_verbs(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $cardB = $this->stageAdd(['client' => $fixture['client'], 'ticket' => $this->anotherTicket($fixture)], ['expires_at' => now()->addDays(30)->toIso8601String()]);
        $record = $this->idLessPermanentRecord($actor, $write, $fixture);
        $this->travel(25)->hours();

        // b5c #5490: neither live brake says 'already allowed' over a record
        // whose rule the PSA no longer measures; both refuse.
        $staged = $this->restage($fixture);
        $this->assertArrayNotHasKey('idempotent', $staged);
        $this->assertArrayHasKey('error', $staged, json_encode($staged));
        $this->assertStringNotContainsString('already allowed', $staged['error']);
        $this->assertStringContainsString("is recorded for this client as PSA record #{$record->id}, but Mesh stopped returning the rule under its recorded id", $staged['error']);
        $this->assertStringContainsString('It is PERMANENT, and '.self::PERMANENT_UNTIL_NO_ID.'. Nothing was staged now.', $staged['error']);

        $write->shouldNotReceive('createAllowRule');
        $this->actingAs($actor)->post(route('cockpit.approve', $cardB))->assertSessionHas('error');
        $executed = (string) session('error');
        $this->assertStringNotContainsString('already allowed', $executed);
        $this->assertStringContainsString("is recorded for this client as PSA record #{$record->id}, but Mesh stopped returning the rule under its recorded id", $executed);
        $this->assertStringEndsWith('It is PERMANENT, and '.self::PERMANENT_UNTIL_NO_ID.'. No upstream call was made and the lifetime on this proposal was NOT applied.', $executed);
        $this->assertStringNotContainsString(self::PERMANENT_UNTIL, $executed);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $cardB->fresh()->state, 'a refusal releases the card; nothing was written');
    }

    /**
     * (b) of #5410 as the issue states it: STATE_UNRESOLVED with a null
     * mesh_rule_id. The panel found it unreachable through the verbs (the
     * id-unrecoverable path audits executed_with_fault, which the dedup brake
     * ignores), so this ONE row is hand-written: a clean create's record is
     * set back to unresolved with no id. It pins the arm for the day a path
     * does reach it: a refusal, never an idempotent success (b5c #5487).
     */
    public function test_the_dedup_answer_for_an_unresolved_record_without_an_id_does_not_offer_the_verbs(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5b-unresolved');
        $record->forceFill(['state' => MeshAllowRule::STATE_UNRESOLVED, 'mesh_rule_id' => null])->save();

        $answer = $this->restage($fixture);

        // b5c #5487: whether the rule exists was never proved, so this is a
        // refusal, never an idempotent 'already created'. #5495: a
        // scope-proved record names the identify pass, as the brake does.
        $this->assertArrayNotHasKey('success', $answer);
        $this->assertArrayNotHasKey('idempotent', $answer);
        $this->assertArrayHasKey('error', $answer, json_encode($answer));
        $this->assertStringNotContainsString('already created', $answer['error']);
        $this->assertStringContainsString("PSA record #{$record->id}, but the PSA cannot currently say whether that rule is live upstream (state 'unresolved')", $answer['error']);
        $this->assertStringContainsString('the expiry job only has to identify it', $answer['error']);
        $this->assertStringNotContainsString(self::PERMANENT_UNTIL, $answer['error']);
    }

    /**
     * Positive control for the two tests above: a permanent ACTIVE record
     * that HAS its id still gets PERMANENT_UNTIL, and the verbs it names do
     * reach it. Without this, deleting PERMANENT_UNTIL everywhere would pass.
     */
    public function test_the_dedup_answer_for_a_permanent_record_with_its_id_names_the_verbs(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5b-tracked');

        $answer = $this->restage($fixture);
        $this->assertStringContainsString('PSA record #'.$record->id.' is PERMANENT (it has no automatic expiry; '.self::PERMANENT_UNTIL.')', $answer['message']);
        $this->assertStringNotContainsString(self::PERMANENT_UNTIL_NO_ID, $answer['message']);

        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow('rule-b5b-tracked', $record->comment));
        $remove = $this->decodedResult($this->callTool('mesh_remove_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'rule_id' => 'rule-b5b-tracked',
            'confirm_sender' => self::SENDER,
            'reason' => 'The vendor now authenticates its mail.',
        ]));
        $this->assertArrayHasKey('run_id', $remove, json_encode($remove));
        $this->assertStringContainsString('PSA-TRACKED (record #'.$record->id, TechnicianRun::findOrFail($remove['run_id'])->proposed_content);
    }

    // ---- #5412: every reworded #5156 runtime string is pinned on its arm -----

    /** The live-record answers at staging and at approval, for a permanent record WITH its id. */
    public function test_the_live_answers_for_a_tracked_permanent_record_name_the_verbs(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $cardB = $this->stageAdd(['client' => $fixture['client'], 'ticket' => $this->anotherTicket($fixture)], ['expires_at' => now()->addDays(30)->toIso8601String()]);
        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5b-live');
        $this->travel(25)->hours();

        $staged = $this->restage($fixture);
        // McpStaffController prepends the staged-downgrade notice, so the
        // executor's whole sentence is the END of the message.
        $this->assertStringEndsWith(" '".self::SENDER."' is already allowed for this client PERMANENTLY (PSA record #{$record->id}; it has no automatic expiry; ".self::PERMANENT_UNTIL.'); no proposal was staged.', $staged['message']);

        $write->shouldNotReceive('createAllowRule');
        $this->actingAs($actor)->post(route('cockpit.approve', $cardB))->assertSessionHas('error');
        $this->assertSame(
            "'".self::SENDER."' is already allowed for this client by PSA record #{$record->id} PERMANENTLY (that record has no automatic expiry; ".self::PERMANENT_UNTIL.'); no upstream call was made and the lifetime on this proposal was NOT applied.',
            (string) session('error'),
        );
    }

    /**
     * The 'executed' audit row's absence-of-record arm. An 'executed' row is
     * written only after its MeshAllowRule row was saved, so this state is
     * HAND-WRITTEN (the row is deleted): no verb deletes a mesh_allow_rules row.
     */
    public function test_the_dedup_answer_with_no_psa_record_says_no_psa_expiry_applies(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5b-gone');
        MeshAllowRule::query()->delete();

        $answer = $this->restage($fixture);
        $this->assertStringEndsWith(
            " An allow rule for '".self::SENDER."' was created for this client recently, but the PSA holds no record of it, so its lifetime cannot be stated and no PSA expiry applies to it. Resolve it in the Mesh portal by hand; no proposal was staged.",
            $answer['error'] ?? json_encode($answer),
        );
    }

    /**
     * Both create faults on a PERMANENT proposal, then the unsettled brake each
     * leaves for a second card: scope unproved (added_for names another
     * tenant) and id unrecoverable (scope proved, re-read finds nothing).
     */
    public function test_the_permanent_create_faults_and_the_brakes_they_leave_say_what_ends_the_rule(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        // Scope unproved.
        $cardB = $this->stageAdd(['client' => $fixture['client'], 'ticket' => $this->anotherTicket($fixture)]);
        $cardA = $this->stageAdd($fixture);
        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => ['99999999-8888-7777-6666-555555555555']]);
        $this->actingAs($actor)->post(route('cockpit.approve', $cardA))->assertSessionHas('error');
        $record = MeshAllowRule::sole();
        $this->assertSame(
            'Mesh did not confirm the rule was scoped to this client only. The rule exists upstream and has been recorded (PSA record #'.$record->id.'); '
                .'it is PERMANENT, so nothing removes it automatically — check it in the Mesh portal and remove it by hand if the scope is wrong.',
            (string) session('error'),
        );

        $this->actingAs($actor)->post(route('cockpit.approve', $cardB))->assertSessionHas('error');
        $this->assertStringContainsString(
            'That record is PERMANENT (no expiry) and Mesh never confirmed its scope, so the expiry job never removes it while it has no expiry, and identifying it does not clear this block — recovering its id is not scope evidence. '
                .'This block clears when the PSA proves that rule removed: an approved mesh_remove_allow_rule does that directly, and an approved mesh_edit_allow_rule that gives the rule a date leaves it to the hourly expiry job once that date passes. '
                ."Both need the PSA to have recorded the rule's upstream id first; otherwise someone has to check that rule in the Mesh portal AND clear the PSA record by hand. Checking the portal alone changes nothing here. In the meantime, ",
            (string) session('error'),
        );

        // Id unrecoverable, on a second sender so the first record is not in the way.
        $other = 'invoices@vendor.example.test';
        $cardD = $this->stageAdd(['client' => $fixture['client'], 'ticket' => $this->anotherTicket($fixture)], ['sender' => $other]);
        $cardC = $this->stageAdd(['client' => $fixture['client'], 'ticket' => $this->anotherTicket($fixture)], ['sender' => $other]);
        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(null);
        $this->actingAs($actor)->post(route('cockpit.approve', $cardC))->assertSessionHas('error');
        $record = MeshAllowRule::where('sender', $other)->sole();
        $this->assertTrue((bool) $record->scope_proved);
        $this->assertSame(
            "Allow rule created for '{$other}', but its Mesh rule id could not be recovered by re-read. It is recorded (PSA record #{$record->id}) and the expiry job will retry resolving it; "
                .'it is PERMANENT, so even once resolved nothing removes it automatically — remove it when it is no longer needed.',
            (string) session('error'),
        );

        $this->actingAs($actor)->post(route('cockpit.approve', $cardD))->assertSessionHas('error');
        $this->assertStringContainsString(
            'That record is PERMANENT (no expiry), so the expiry job never removes it; its scope WAS confirmed when it was created, so the expiry job only has to IDENTIFY it, and this block clears when it does. Until then, ',
            (string) session('error'),
        );
    }

    /** The clean permanent create: its audit summary, the text an operator reads. */
    public function test_the_executed_answer_for_a_permanent_rule_states_its_whole_message(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stageAdd($fixture);

        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'rule-b5b-exec']);
        $result = app(StaffMeshAdminToolExecutor::class)->approveStagedRun($run, $actor->id);

        $this->assertSame('executed', $result->status);
        // approveStagedRun() drops the message of a clean execution (the
        // cockpit shows its generic text), so the audit summary is the
        // operator-visible text and is the one pinned. b5c #5497 item 6: the
        // returned message tail was once pinned here by calling the private
        // executeAllowRule() through reflection; no operator path emits it,
        // so that pin is gone rather than kept as false coverage.
        $summary = "Created Mesh allow rule for '".self::SENDER."' scoped to this client; PERMANENT — no automatic expiry; ".self::PERMANENT_UNTIL.'.';
        $this->assertSame($summary.' Mesh rule id rule-b5b-exec.', TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed')->sole()->summary);
        $this->assertNull($result->message, 'a clean execution carries no message to the cockpit');
    }

    /** Both PERMANENT arms of reconcileUnacknowledgedCreate(): found on re-read, and not found. */
    public function test_both_permanent_reconcile_arms_say_what_ends_the_rule(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $found = $this->stageAdd($fixture);
        $write->shouldReceive('createAllowRule')->once()->andThrow(new MeshClientException('Mesh API error: timeout', 0));
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'rule-b5b-landed']);
        $this->actingAs($actor)->post(route('cockpit.approve', $found))->assertSessionHas('error');
        $foundText = (string) session('error');
        $record = MeshAllowRule::where('technician_run_id', $found->id)->sole();
        $this->assertStringContainsString(
            "a re-read of this client's tenant found the rule live for '".self::SENDER."'. It is recorded (PSA record #{$record->id}) and it is PERMANENT — nothing removes it automatically. There was no successful create response",
            $foundText,
        );

        $other = 'invoices@vendor.example.test';
        $lost = $this->stageAdd(['client' => $fixture['client'], 'ticket' => $this->anotherTicket($fixture)], ['sender' => $other]);
        $comment = (string) $lost->proposed_meta['redacted_params']['comment'];
        $write->shouldReceive('createAllowRule')->once()->andThrow(new MeshClientException('Mesh API error: timeout', 0));
        $write->shouldReceive('findRuleByComment')->once()->andReturn(null);
        $this->actingAs($actor)->post(route('cockpit.approve', $lost))->assertSessionHas('error');
        $record = MeshAllowRule::where('technician_run_id', $lost->id)->sole();
        $this->assertStringContainsString(
            'Whether the rule was created is UNMEASURED, so it is recorded unresolved (PSA record #'.$record->id.') and '
                .'it is PERMANENT — the expiry job keeps trying to identify it, but it never removes it. Look for a rule with the comment '.$comment." on this client's Mesh tenant and remove it by hand."
                .' Check the Mesh portal before allowing this sender again.',
            (string) session('error'),
        );
    }

    /** The edit verb's advertised description and its expires_at schema text. */
    public function test_the_edit_verb_advertises_never_truthfully(): void
    {
        $this->configureMesh();
        $definitions = collect(StaffMeshAdminToolExecutor::definitions())->keyBy('name');
        $edit = $definitions['mesh_edit_allow_rule'];

        $this->assertStringContainsString(
            'ONE FIELD: `expires_at` (an ISO-8601 date or datetime, or "never" for a rule with no automatic expiry) is the only thing this verb changes, and it is required. ',
            $edit['description'],
        );
        $this->assertStringContainsString(
            'or the word "never" for a rule that NEVER expires: it stays until someone removes it (`mesh_remove_allow_rule`) or gives it another date with this verb. ',
            $edit['input_schema']['properties']['expires_at']['description'],
        );
        $this->assertSame(
            $edit['input_schema']['properties']['expires_at']['description'],
            $definitions['mesh_stage_edit_allow_rule']['input_schema']['properties']['expires_at']['description'],
        );
    }
}
