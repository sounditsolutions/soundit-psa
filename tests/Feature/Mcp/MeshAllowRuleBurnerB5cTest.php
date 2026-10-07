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
use App\Services\Mesh\MeshAllowRuleReaper;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * #5444 residuals, burner b5c batch 1: Mesh allow-rule operator messages.
 *
 * An "already created" / "already allowed" answer is success:true and
 * idempotent:true on the machine-readable channel, so it is given only over
 * an ACTIVE record that carries its upstream id. Every other record state is
 * refused, with text saying exactly what that state proves
 * (StaffMeshAdminToolExecutor::recordRefusal()), at staging and at approval:
 *
 *  - #5487 unresolved, #5490 id cleared by display_id_lost, #5497 item 1
 *    approval-time dedup, item 2 reap_failed, item 3 a date already past;
 *  - #5486 removed/reaped prove ONE id absent, not that no allow is in force;
 *  - #5491 display_id_lost on a permanent edit never promises the expiry job;
 *  - #5495 a scope-proved unresolved record names the identify pass;
 *  - #5497 item 5: the two reaper settle notes #5156 reworded.
 *
 * States are reached through the verbs (stage, approve, remove, edit) and
 * the reaper, except where a test says it hand-writes one.
 * Synthetic data only (G-13): example.test senders, no client names.
 */
class MeshAllowRuleBurnerB5cTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const SENDER = 'billing@vendor.example.test';

    private const DOMAIN = 'vendor.example.test';

    private const PERMANENT_UNTIL = 'it stays until someone removes it (mesh_remove_allow_rule) or gives it a date (mesh_edit_allow_rule)';

    private const PERMANENT_UNTIL_NO_ID = 'the PSA holds no upstream rule id for it, so mesh_edit_allow_rule cannot give it a date, mesh_remove_allow_rule would not close this record, and the expiry job never removes a rule with no expiry; this PSA record keeps blocking new allow rules for this sender until someone checks the rule in the Mesh portal and clears the record by hand';

    private const AT_APPROVAL = ' No upstream call was made and the lifetime on this proposal was NOT applied.';

    private ?string $token = null;

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

    private function mockWrite(): Mockery\MockInterface
    {
        $client = Mockery::mock(MeshWriteClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $this->app->instance(MeshWriteClient::class, $client);

        return $client;
    }

    /** Stage an add through the MCP verb (on a ticket of its own) and return its awaiting run. */
    private function stageAdd(array $fixture, array $overrides = []): TechnicianRun
    {
        $result = $this->decodedResult($this->callTool('mesh_add_allow_rule', array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'sender' => self::SENDER,
            'confirm_domain' => self::DOMAIN,
            'reason' => 'Vendor invoices are being quarantined; approved by the client contact.',
        ], $overrides)));
        $this->assertArrayHasKey('run_id', $result, 'staging failed: '.json_encode($result));
        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return $run;
    }

    /** @return array<string, mixed> */
    private function restage(array $fixture, array $overrides = []): array
    {
        return $this->decodedResult($this->callTool('mesh_add_allow_rule', array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'sender' => self::SENDER,
            'confirm_domain' => self::DOMAIN,
            'reason' => 'Vendor invoices are being quarantined again.',
        ], $overrides)));
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
     * Stage and approve a mesh_remove_allow_rule for $record's rule. A true
     * $absent closes it STATE_REMOVED; false leaves it STATE_REAP_FAILED.
     */
    private function approveRemoval(User $actor, Mockery\MockInterface $write, array $fixture, MeshAllowRule $record, bool $absent): void
    {
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow($record->mesh_rule_id, $record->comment));
        $staged = $this->decodedResult($this->callTool('mesh_remove_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'rule_id' => $record->mesh_rule_id,
            'confirm_sender' => self::SENDER,
            'reason' => 'The vendor now authenticates its mail.',
        ]));
        $this->assertArrayHasKey('run_id', $staged, json_encode($staged));
        $write->shouldReceive('deleteRule')->once()->with($record->mesh_rule_id);
        $write->shouldReceive('ruleAbsent')->once()->with($record->mesh_rule_id)->andReturn($absent);
        $this->actingAs($actor)->post(route('cockpit.approve', TechnicianRun::findOrFail($staged['run_id'])))
            ->assertSessionHas($absent ? 'success' : 'error');
        $this->assertSame($absent ? MeshAllowRule::STATE_REMOVED : MeshAllowRule::STATE_REAP_FAILED, $record->fresh()->state);
    }

    /**
     * An approved mesh_edit_allow_rule to $expiresAt after which Mesh no
     * longer returns the rule under its id (display_id_lost). Returns the
     * cockpit's message.
     */
    private function editWithIdLost(User $actor, Mockery\MockInterface $write, array $fixture, MeshAllowRule $record, string $expiresAt): string
    {
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow($record->mesh_rule_id, $record->comment));
        $edit = $this->decodedResult($this->callTool('mesh_edit_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'rule_id' => $record->mesh_rule_id,
            'confirm_sender' => self::SENDER,
            'expires_at' => $expiresAt,
            'reason' => 'Change how long the vendor stays allowed.',
        ]));
        $this->assertArrayHasKey('run_id', $edit, json_encode($edit));
        $write->shouldReceive('patchRule')->once()->andReturn([]);
        $write->shouldReceive('findRuleById')->once()->andReturn(null);
        $this->actingAs($actor)->post(route('cockpit.approve', TechnicianRun::findOrFail($edit['run_id'])))->assertSessionHas('error');

        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state);
        $this->assertNull($record->mesh_rule_id);

        return (string) session('error');
    }

    /** The refusal shape every non-success answer must have, plus its audit row. */
    private function assertRefused(callable $call): string
    {
        $runsBefore = TechnicianRun::count();
        $blockedBefore = $this->blocked('mesh_stage_add_allow_rule');
        $answer = $call();
        $this->assertArrayNotHasKey('success', $answer, json_encode($answer));
        $this->assertArrayNotHasKey('idempotent', $answer);
        $this->assertArrayNotHasKey('expires_at', $answer);
        $this->assertArrayHasKey('error', $answer, json_encode($answer));
        $this->assertStringNotContainsString('already created', $answer['error']);
        $this->assertStringNotContainsString('already allowed', $answer['error']);
        $this->assertSame($runsBefore, TechnicianRun::count(), 'nothing was staged');
        $this->assertSame($blockedBefore + 1, $this->blocked('mesh_stage_add_allow_rule'), 'the refusal is audited as a refusal');

        return $answer['error'];
    }

    private function blocked(string $actionType): int
    {
        return TechnicianActionLog::query()->where('action_type', $actionType)->where('result_status', 'blocked')->count();
    }

    // ---- #5487 / #5495: unresolved is refused, and says what recovers it ---

    /**
     * #5487 through the verbs and the reaper, nothing hand-written: a dated
     * rule is created cleanly; an edit moves its expiry two hours out and
     * display_id_lost clears its id; the expiry passes and the reaper cannot
     * find the rule by sender and comment, so it records the row UNRESOLVED.
     * Re-staged inside the 24-hour window, the PSA does not know whether the
     * rule exists, so the answer is a refusal, never 'already created'.
     */
    public function test_an_unresolved_record_inside_the_dedup_window_is_refused_not_already_created(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['expires_at' => now()->addDays(30)->toIso8601String()]), 'rule-b5c-unres');
        $this->editWithIdLost($actor, $write, $fixture, $record, now()->addHours(2)->toIso8601String());

        $this->travel(3)->hours();
        $write->shouldReceive('findRuleByComment')->once()->andReturn(null);
        $write->shouldNotReceive('deleteRule');
        $this->assertSame(1, app(MeshAllowRuleReaper::class)->reap()['unresolved']);
        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);

        $error = $this->assertRefused(fn () => $this->restage($fixture));
        $this->assertStringEndsWith(
            "An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$record->id}, but whether that rule is live upstream was never proved (state 'unresolved'), so it is not answered as already in place. "
                .'Its expiry ('.$record->expires_at->toDayDateTimeString().' UTC) has passed, so the expiry job tries to identify the rule and remove it. Nothing was staged now.',
            $error,
        );
    }

    /**
     * #5495, hand-written (the panel found no verb reaches a PERMANENT
     * unresolved row inside the dedup window): a scope-proved row is told
     * the expiry job only has to identify it and that the verbs then reach
     * it, as the unsettled brake says; the row without scope proof is not.
     * The identify pass is then run to show the first claim true.
     */
    public function test_a_permanent_unresolved_record_names_the_identify_pass_only_when_scope_was_proved(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c-ident');
        $record->forceFill(['state' => MeshAllowRule::STATE_UNRESOLVED, 'mesh_rule_id' => null])->save();

        $opener = "An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$record->id}, but whether that rule is live upstream was never proved (state 'unresolved'), so it is not answered as already in place. ";
        $error = $this->assertRefused(fn () => $this->restage($fixture));
        $this->assertStringEndsWith(
            $opener.'Its scope WAS confirmed when it was created, so the expiry job only has to identify it: once one rule in Mesh carries its sender and comment, the record goes active with that id and the remove and edit verbs can reach it. '
                .'Until then the PSA holds no upstream rule id for it, so neither verb can. Nothing was staged now.',
            $error,
        );

        $record->forceFill(['scope_proved' => false])->save();
        $error = $this->assertRefused(fn () => $this->restage($fixture));
        $this->assertStringEndsWith(
            $opener.'Mesh never confirmed its scope, so identifying the rule does not settle this record. It closes when the PSA proves the rule removed: an approved mesh_remove_allow_rule can do that once the record carries its upstream id, '
                ."and the expiry job does it after the record's expiry passes (this record is PERMANENT, so it first needs a date from mesh_edit_allow_rule, which also needs the id); otherwise someone has to check the rule in the Mesh portal and clear the PSA record by hand. Nothing was staged now.",
            $error,
        );

        // The claim the scope-proved text makes, executed: one match and the
        // row goes active with that id.
        $record->forceFill(['scope_proved' => true])->save();
        $write->shouldReceive('findRulesByComment')->once()->andReturn([['id' => 'rule-b5c-found']]);
        app(MeshAllowRuleReaper::class)->reap();
        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state);
        $this->assertSame('rule-b5c-found', $record->mesh_rule_id);
    }

    // ---- #5490 / #5491: display_id_lost is not a measured allow -------------

    /**
     * #5491: the permanent display_id_lost message never promises the expiry
     * job (a permanent record is never due), and #5490: every answer for the
     * id-less record it leaves is a refusal that says the rule may be gone,
     * at the dedup arm, the staging live brake and the approval live brake.
     */
    public function test_a_permanent_display_id_lost_record_is_refused_everywhere_and_never_promised_the_expiry_job(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $cardB = $this->stageAdd($fixture, ['expires_at' => now()->addDays(30)->toIso8601String()]);
        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['expires_at' => now()->addDays(30)->toIso8601String()]), 'rule-b5c-lost');
        $message = $this->editWithIdLost($actor, $write, $fixture, $record, 'never');

        $this->assertStringContainsString(
            "PSA record #{$record->id} keeps the new expiry; its recorded rule id was cleared. The record is now PERMANENT, so the expiry job never visits it, and with no recorded id neither mesh_remove_allow_rule nor mesh_edit_allow_rule can reach it. "
                .'Check the rule in the Mesh portal; until someone clears this PSA record by hand, it blocks new allow rules for this sender.',
            $message,
        );
        $this->assertStringNotContainsString('re-identifies', $message);
        $this->assertStringNotContainsString('when it is due', $message);

        $why = "but Mesh stopped returning the rule under its recorded id after an approved mesh_edit_allow_rule, and that id was cleared (state 'active'), so whether the rule is still in force upstream is unknown: Mesh may have re-keyed it or it may be gone. "
            .'It is PERMANENT, and '.self::PERMANENT_UNTIL_NO_ID.'.';

        $error = $this->assertRefused(fn () => $this->restage($fixture));
        $this->assertStringEndsWith("An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$record->id}, {$why} Nothing was staged now.", $error);

        $this->travel(25)->hours();
        $live = "An allow rule for '".self::SENDER."' is recorded for this client as PSA record #{$record->id}, {$why}";
        $error = $this->assertRefused(fn () => $this->restage($fixture));
        $this->assertStringEndsWith($live.' Nothing was staged now.', $error);

        $write->shouldNotReceive('createAllowRule');
        $this->actingAs($actor)->post(route('cockpit.approve', $cardB))->assertSessionHas('error');
        $this->assertSame($live.self::AT_APPROVAL, (string) session('error'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $cardB->fresh()->state);
    }

    /**
     * The DATED display_id_lost arm keeps its re-identification promise,
     * which is true there (reapOne() resolves a missing id by sender and
     * comment), and its id-less record is refused, naming that promise.
     * Positive control for the permanent arm above: a swap of the two arms
     * fails one test or the other.
     */
    public function test_a_dated_display_id_lost_record_is_refused_and_named_the_expiry_job(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c-dated');
        $message = $this->editWithIdLost($actor, $write, $fixture, $record, now()->addDays(10)->toIso8601String());
        $this->assertStringContainsString("PSA record #{$record->id} keeps the new expiry; its recorded rule id was cleared so the expiry job re-identifies the rule by sender and comment when it is due. Check the rule in the Mesh portal.", $message);
        $this->assertStringNotContainsString('now PERMANENT', $message);

        $error = $this->assertRefused(fn () => $this->restage($fixture));
        $this->assertStringEndsWith(
            'so whether the rule is still in force upstream is unknown: Mesh may have re-keyed it or it may be gone. Once its expiry ('.$record->fresh()->expires_at->toDayDateTimeString().' UTC) passes, the expiry job tries to re-identify the rule by sender and comment and remove it. Nothing was staged now.',
            $error,
        );
    }

    // ---- #5486 / #5488 / #5485: removed vs reaped, exactly -------------------

    /**
     * One record per arm, each reached through its own verb: REMOVED by an
     * approved mesh_remove_allow_rule, REAPED by the reaper after a two-hour
     * expiry. Each arm's whole sentence is pinned and each must NOT carry the
     * other's proof, so a swap or a merge of the two arms fails (#5488). Both
     * say only that ONE recorded id was proved absent, never "NO allow is in
     * force", and neither offers a hand-made rule (#5486). Both refuse on the
     * error key itself (#5485).
     */
    public function test_removed_and_reaped_refusals_say_only_what_each_proof_proved(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $removed = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c-removed');
        $this->approveRemoval($actor, $write, $fixture, $removed, true);

        $other = 'orders@vendor.example.test';
        $reaped = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['sender' => $other, 'expires_at' => now()->addHours(2)->toIso8601String()]), 'rule-b5c-reaped');
        $write->shouldReceive('deleteRule')->once()->with('rule-b5c-reaped');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-b5c-reaped')->andReturn(true);
        $this->travel(3)->hours();
        $this->assertSame(1, app(MeshAllowRuleReaper::class)->reap()['reaped']);
        $this->assertSame(MeshAllowRule::STATE_REAPED, $reaped->fresh()->state);

        $oneIdOnly = 'That proves only that the rule this record tracked is gone; the PSA has not checked whether any other rule for this sender is in force on the tenant.';
        $tail = ' Nothing was staged now. If the sender still needs allowing, check its rules in the Mesh portal, then stage it again once the 24-hour post-execution dedup window has elapsed.';

        $removedError = $this->assertRefused(fn () => $this->restage($fixture));
        $this->assertStringEndsWith(
            "An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$removed->id}, but an approved mesh_remove_allow_rule has since proved it absent upstream (state 'removed'). {$oneIdOnly}{$tail}",
            $removedError,
        );
        $this->assertStringNotContainsString('expiry job', $removedError);

        $reapedError = $this->assertRefused(fn () => $this->restage($fixture, ['sender' => $other]));
        $this->assertStringEndsWith(
            "An allow rule for '{$other}' was created for this client recently as PSA record #{$reaped->id}, but the expiry job has since proved it absent upstream after its expiry (state 'reaped'). {$oneIdOnly}{$tail}",
            $reapedError,
        );
        $this->assertStringNotContainsString('mesh_remove_allow_rule', $reapedError);

        foreach ([$removedError, $reapedError] as $error) {
            $this->assertStringNotContainsString('NO allow is in force', $error);
            $this->assertStringNotContainsString('create the rule by hand', $error);
        }
    }

    // ---- #5497 items 1-3: approval-time dedup, reap_failed, a past date ----

    /**
     * #5497 item 1: the approval-time dedup arm reads the record's state. A
     * card staged before the first approval is approved after that rule was
     * removed (inside the window): it is refused, the card goes back to
     * awaiting approval, and nothing reaches Mesh. Before b5c it answered
     * idempotent 'already created recently' over a removed rule.
     */
    public function test_the_approval_time_dedup_refuses_a_removed_record(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $cardB = $this->stageAdd($fixture);
        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c-dedup');
        $this->approveRemoval($actor, $write, $fixture, $record, true);

        $write->shouldNotReceive('createAllowRule');
        $before = $this->blocked('mesh_add_allow_rule');
        $this->actingAs($actor)->post(route('cockpit.approve', $cardB))->assertSessionHas('error');
        $this->assertSame(
            "An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$record->id}, but an approved mesh_remove_allow_rule has since proved it absent upstream (state 'removed'). "
                .'That proves only that the rule this record tracked is gone; the PSA has not checked whether any other rule for this sender is in force on the tenant.'.self::AT_APPROVAL,
            (string) session('error'),
        );
        $this->assertSame(TechnicianRunState::AwaitingApproval, $cardB->fresh()->state, 'a refusal releases the card');
        $this->assertSame($before + 1, $this->blocked('mesh_add_allow_rule'));
        $this->assertSame(0, TechnicianActionLog::where('summary', 'Duplicate Mesh allow rule suppressed before upstream call.')->count());
    }

    /**
     * Positive control for the test above: over an ACTIVE record with its id,
     * the approval-time dedup still answers its idempotent sentence, so a
     * mutant that refuses every state fails here.
     */
    public function test_the_approval_time_dedup_over_an_active_record_is_still_idempotent(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $cardB = $this->stageAdd($fixture);
        $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c-active');

        $write->shouldNotReceive('createAllowRule');
        $this->actingAs($actor)->post(route('cockpit.approve', $cardB))->assertSessionHas('error');
        $this->assertSame('This allow rule was already created recently; no upstream call was made.', (string) session('error'));
        $this->assertSame(TechnicianRunState::Done, $cardB->fresh()->state);
    }

    /**
     * #5497 item 2: a PERMANENT record whose removal did not prove absence
     * (reap_failed, through the remove verb) is not told the verbs end it
     * unconditionally. It is refused at staging and at approval, naming the
     * condition under which the remove verb can still reach it.
     */
    public function test_a_permanent_reap_failed_record_is_refused_and_not_promised_the_verbs(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $cardB = $this->stageAdd($fixture);
        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c-failed');
        $this->approveRemoval($actor, $write, $fixture, $record, false);

        $why = "PSA record #{$record->id}, but an earlier removal of it did not prove it absent (state 'reap_failed'), so whether the rule is still live upstream is unknown. "
            .'It is PERMANENT, so the expiry job never retries the removal. While Mesh still returns the rule under its recorded id, an approved mesh_remove_allow_rule can retry it; if the rule is already gone, neither verb can reach it and the PSA record has to be cleared by hand.';

        $error = $this->assertRefused(fn () => $this->restage($fixture));
        $this->assertStringEndsWith($why.' Nothing was staged now.', $error);
        $this->assertStringNotContainsString(self::PERMANENT_UNTIL, $error);

        $write->shouldNotReceive('createAllowRule');
        $this->actingAs($actor)->post(route('cockpit.approve', $cardB))->assertSessionHas('error');
        $this->assertStringEndsWith($why.self::AT_APPROVAL, (string) session('error'));
    }

    /**
     * b5c review contract:1 and contract:2, through the verbs and the
     * reaper: a dated rule's removal does not prove it absent (reap_failed),
     * then an approved edit to 'never' loses its id (display_id_lost). That
     * record is not one the expiry job never visits: its settle pass looks it
     * up by sender and comment and records the id again, and the remove verb
     * then reaches it. Until then neither message may promise a retry under a
     * recorded id; after it, the refusal names that retry.
     */
    public function test_a_permanent_reap_failed_record_that_loses_its_id_is_told_the_settle_pass_can_restore_it(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['expires_at' => now()->addDays(30)->toIso8601String()]), 'rule-b5c-refail');
        $row = $this->upstreamRow('rule-b5c-refail', $record->comment);

        // Counted, not open-ended, so the post-edit read below is reached:
        // each verb reads once at staging and once at approval.
        $write->shouldReceive('findRuleById')->twice()->andReturn($row);
        $removal = $this->decodedResult($this->callTool('mesh_remove_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'rule_id' => 'rule-b5c-refail',
            'confirm_sender' => self::SENDER,
            'reason' => 'The vendor now authenticates its mail.',
        ]));
        $this->assertArrayHasKey('run_id', $removal, json_encode($removal));
        $write->shouldReceive('deleteRule')->once()->with('rule-b5c-refail');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-b5c-refail')->andReturn(false);
        $this->actingAs($actor)->post(route('cockpit.approve', TechnicianRun::findOrFail($removal['run_id'])))->assertSessionHas('error');
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $record->fresh()->state);

        $write->shouldReceive('findRuleById')->twice()->andReturn($row);
        $edit = $this->decodedResult($this->callTool('mesh_edit_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'rule_id' => 'rule-b5c-refail',
            'confirm_sender' => self::SENDER,
            'expires_at' => 'never',
            'reason' => 'Change how long the vendor stays allowed.',
        ]));
        $this->assertArrayHasKey('run_id', $edit, json_encode($edit));
        $write->shouldReceive('patchRule')->once()->andReturn([]);
        $write->shouldReceive('findRuleById')->once()->andReturn(null);
        $this->actingAs($actor)->post(route('cockpit.approve', TechnicianRun::findOrFail($edit['run_id'])))->assertSessionHas('error');
        $message = (string) session('error');
        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $record->state);
        $this->assertTrue($record->isPermanent());
        $this->assertNull($record->mesh_rule_id);

        $this->assertStringContainsString(
            "PSA record #{$record->id} keeps the new expiry; its recorded rule id was cleared. The record is now PERMANENT (state 'reap_failed'), so the expiry job never removes it, and while it has no recorded id neither mesh_remove_allow_rule nor mesh_edit_allow_rule can reach it. "
                ."The expiry job does look a record in this state up by sender and comment: if exactly one rule in Mesh matches, it records that rule's id here again and both verbs can reach the record. Until then it blocks new allow rules for this sender. Check the rule in the Mesh portal.",
            $message,
        );
        $this->assertStringNotContainsString('never visits it', $message);
        $this->assertStringNotContainsString('by hand', $message);

        $why = "PSA record #{$record->id}, but an earlier removal of it did not prove it absent (state 'reap_failed'), so whether the rule is still live upstream is unknown. ";
        $error = $this->assertRefused(fn () => $this->restage($fixture));
        $this->assertStringEndsWith(
            $why.'It is PERMANENT, so the expiry job never retries the removal, and the PSA holds no upstream rule id for it, so neither verb can reach it now. '
                ."The expiry job does look this record up by sender and comment: once exactly one rule in Mesh matches, it records that rule's id here and an approved mesh_remove_allow_rule can retry the removal; if the rule is already gone, the PSA record has to be cleared by hand. Nothing was staged now.",
            $error,
        );
        $this->assertStringNotContainsString('under its recorded id', $error);

        // The claim both texts make, executed: one match and the id is back,
        // the row still reap_failed, and the remove verb reaches it.
        $write->shouldReceive('findRulesByComment')->once()->andReturn([['id' => 'rule-b5c-refound']]);
        app(MeshAllowRuleReaper::class)->reap();
        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $record->state);
        $this->assertSame('rule-b5c-refound', $record->mesh_rule_id);

        $error = $this->assertRefused(fn () => $this->restage($fixture));
        $this->assertStringEndsWith(
            $why.'It is PERMANENT, so the expiry job never retries the removal. While Mesh still returns the rule under its recorded id, an approved mesh_remove_allow_rule can retry it; if the rule is already gone, neither verb can reach it and the PSA record has to be cleared by hand. Nothing was staged now.',
            $error,
        );

        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow('rule-b5c-refound', $record->comment));
        $remove = $this->decodedResult($this->callTool('mesh_remove_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'rule_id' => 'rule-b5c-refound',
            'confirm_sender' => self::SENDER,
            'reason' => 'Retry the removal.',
        ]));
        $this->assertArrayHasKey('run_id', $remove, json_encode($remove));
        $this->assertStringContainsString('PSA-TRACKED (record #'.$record->id, TechnicianRun::findOrFail($remove['run_id'])->proposed_content);
    }

    /**
     * #5497 item 3: an ACTIVE dated record whose date has passed but which
     * the reaper has not yet removed is not "set to expire" a past date.
     * Hand-timed only by travelling past the expiry before the reaper runs.
     */
    public function test_the_dedup_answer_for_an_active_record_past_its_date_does_not_say_set_to_expire(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['expires_at' => now()->addHours(2)->toIso8601String()]), 'rule-b5c-past');
        $this->travel(3)->hours();

        $answer = $this->restage($fixture);
        $this->assertTrue($answer['idempotent'], json_encode($answer));
        $this->assertStringContainsString(
            'PSA record #'.$record->id.' is past its expiry ('.$record->expires_at->toDayDateTimeString()." UTC) but not yet removed by the expiry job, so it stays in force until that job removes it, state 'active'.",
            $answer['message'],
        );
        $this->assertStringNotContainsString('set to expire', $answer['message']);
    }

    // ---- #5496: the remove verb does not close an id-less record, executed --

    /**
     * PERMANENT_UNTIL_NO_ID says mesh_remove_allow_rule would not close this
     * record. Executed, not inferred from a card label: the rule is still in
     * Mesh under another id, the removal is approved and proved, and the
     * id-less PSA record stays ACTIVE (and keeps being refused).
     */
    public function test_an_approved_removal_of_the_rekeyed_rule_does_not_close_the_id_less_record(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['expires_at' => now()->addDays(30)->toIso8601String()]), 'rule-b5c-orphan');
        $this->editWithIdLost($actor, $write, $fixture, $record, 'never');

        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow('rule-b5c-rekeyed', $record->comment));
        $staged = $this->decodedResult($this->callTool('mesh_remove_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->anotherTicket($fixture)->id,
            'rule_id' => 'rule-b5c-rekeyed',
            'confirm_sender' => self::SENDER,
            'reason' => 'Remove it.',
        ]));
        $this->assertArrayHasKey('run_id', $staged, json_encode($staged));
        $write->shouldReceive('deleteRule')->once()->with('rule-b5c-rekeyed');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-b5c-rekeyed')->andReturn(true);
        $this->actingAs($actor)->post(route('cockpit.approve', TechnicianRun::findOrFail($staged['run_id'])))->assertSessionHas('success');

        $summary = TechnicianActionLog::where('action_type', 'mesh_remove_allow_rule')->where('result_status', 'executed')->sole()->summary;
        $this->assertStringContainsString('The rule was foreign — the PSA held no record of it.', $summary);
        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state, 'the removal did not close this record');
        $this->assertNull($record->removed_at);
        $this->assertNull($record->mesh_rule_id);
    }

    // ---- #5497 item 5: the two reaper settle notes #5156 reworded -----------

    /**
     * The settle pass's two PERMANENT notes that #5156 reworded: settled
     * active (names the verbs), and unsettled without scope proof (names the
     * edit-then-reap route). Each is pinned whole on its own arm.
     */
    public function test_the_reaper_settle_notes_reworded_by_5156_are_pinned(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        Log::spy();

        $settled = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c-s1');
        $settled->forceFill(['state' => MeshAllowRule::STATE_UNRESOLVED, 'mesh_rule_id' => null])->save();
        $unproved = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['sender' => 'orders@vendor.example.test']), 'rule-b5c-s2');
        $unproved->forceFill(['state' => MeshAllowRule::STATE_UNRESOLVED, 'mesh_rule_id' => null, 'scope_proved' => false])->save();

        $write->shouldReceive('findRulesByComment')->twice()->andReturn([['id' => 'rule-b5c-s1']], [['id' => 'rule-b5c-s2']]);
        app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $settled->fresh()->state);
        Log::shouldHaveReceived('info')->withArgs(fn (string $m): bool => str_ends_with($m,
            "Upstream rule id is 'rule-b5c-s1', and this rule's scope was confirmed by its create response, so the PERMANENT rule is now recorded active. It has no expiry, so the expiry job never removes it; ".self::PERMANENT_UNTIL.'.'))->once();

        $note = "Upstream rule id is 'rule-b5c-s2'. An id is not scope evidence, so this PERMANENT rule stays unresolved: the expiry job never removes it while it has no expiry, and it keeps refusing new allow rules for this sender. "
            .'Checking the rule in the Mesh portal does not change that — the record closes when the PSA proves the rule removed: an approved mesh_remove_allow_rule does that directly, and once an approved mesh_edit_allow_rule gives the rule a date, the expiry job does it after that date passes; otherwise the record has to be cleared by hand.';
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $unproved->fresh()->state);
        $this->assertSame($note, $unproved->fresh()->last_error);
    }
}
