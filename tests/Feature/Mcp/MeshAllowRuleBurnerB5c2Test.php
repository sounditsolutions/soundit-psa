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
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * #5528 residuals, burner b5c batch 2: the approval-time dedup and the
 * recordRefusal() arms the b5c-1 review found unpinned.
 *
 *  - #5568: an approval-time dedup refusal CLOSES its card, so a click after
 *    the 24-hour window cannot reach createAllowRule; #5561 pins the
 *    no-record arm that refusal starts from.
 *  - #5566: the dedup names the record its matched create wrote, not the
 *    sender's newest record.
 *  - #5564: every recordRefusal() arm the b5c-1 tests never emitted, with
 *    the expiry job's promise executed where the arm makes one.
 *
 * Mesh is reached only through the MeshWriteClient double; raw HTTP is
 * faked with no stub and stray requests are prevented (G-5). Synthetic data
 * only (G-13): example.test senders and tenants, no client names.
 */
class MeshAllowRuleBurnerB5c2Test extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const OTHER_TENANT = '66666666-7777-8888-9999-000000000000';

    private const SENDER = 'billing@vendor.example.test';

    private const DOMAIN = 'vendor.example.test';

    private const AT_APPROVAL = ' No upstream call was made and the lifetime on this proposal was NOT applied.';

    private const CARD_CLOSED = ' This card is closed, not returned to the approval queue, so approving it again cannot create a rule; any further allow rule for this sender needs a new proposal.';

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function configureMesh(): void
    {
        Setting::setEncrypted('mesh_api_key', 'test-placeholder-not-a-key');
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

    /** @return array{client: Client} */
    private function fixture(): array
    {
        return ['client' => Client::factory()->create(['name' => 'Example Client', 'mesh_customer_id' => self::TENANT])];
    }

    private function ticket(array $fixture): Ticket
    {
        return Ticket::factory()->for($fixture['client'])->create(['subject' => 'Vendor mail quarantined']);
    }

    private function mockWrite(): Mockery\MockInterface
    {
        $client = Mockery::mock(MeshWriteClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $this->app->instance(MeshWriteClient::class, $client);

        return $client;
    }

    /** @return array<string, mixed> */
    private function stageArgs(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->ticket($fixture)->id,
            'sender' => self::SENDER,
            'confirm_domain' => self::DOMAIN,
            'reason' => 'Vendor invoices are being quarantined; approved by the client contact.',
        ], $overrides);
    }

    private function stageAdd(array $fixture, array $overrides = []): TechnicianRun
    {
        $result = $this->decodedResult($this->callTool('mesh_add_allow_rule', $this->stageArgs($fixture, $overrides)));
        $this->assertArrayHasKey('run_id', $result, 'staging failed: '.json_encode($result));

        return TechnicianRun::findOrFail($result['run_id']);
    }

    /** @return array<string, mixed> */
    private function restage(array $fixture, array $overrides = []): array
    {
        return $this->decodedResult($this->callTool('mesh_add_allow_rule', $this->stageArgs($fixture, $overrides)));
    }

    /** Approve a staged add whose create proves scope on $tenant and recovers $ruleId. */
    private function approveCleanCreate(User $actor, Mockery\MockInterface $write, TechnicianRun $run, string $ruleId, string $tenant = self::TENANT): MeshAllowRule
    {
        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [$tenant]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => $ruleId, 'created_by' => 'owner@soundit.example.test']);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $record = MeshAllowRule::where('technician_run_id', $run->id)->sole();
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state);

        return $record;
    }

    private function blocked(string $actionType): int
    {
        return TechnicianActionLog::query()->where('action_type', $actionType)->where('result_status', 'blocked')->count();
    }

    private function lastBlockedSummary(string $actionType): ?string
    {
        return TechnicianActionLog::query()->where('action_type', $actionType)->where('result_status', 'blocked')->latest('id')->value('summary');
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

    /** Stage and approve a removal of $record's rule; $absent decides REMOVED or REAP_FAILED. */
    private function approveRemoval(User $actor, Mockery\MockInterface $write, array $fixture, MeshAllowRule $record, bool $absent): void
    {
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow($record->mesh_rule_id, $record->comment));
        $staged = $this->decodedResult($this->callTool('mesh_remove_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->ticket($fixture)->id,
            'rule_id' => $record->mesh_rule_id,
            'confirm_sender' => self::SENDER,
            'reason' => 'The vendor now authenticates its mail.',
        ]));
        $this->assertArrayHasKey('run_id', $staged, json_encode($staged));
        $write->shouldReceive('deleteRule')->once()->with($record->mesh_rule_id);
        $write->shouldReceive('ruleAbsent')->once()->with($record->mesh_rule_id)->andReturn($absent);
        $this->actingAs($actor)->post(route('cockpit.approve', TechnicianRun::findOrFail($staged['run_id'])));
        $this->assertSame($absent ? MeshAllowRule::STATE_REMOVED : MeshAllowRule::STATE_REAP_FAILED, $record->fresh()->state);
    }

    /** An approved edit to $expiresAt after which Mesh no longer returns the rule under its id. */
    private function editWithIdLost(User $actor, Mockery\MockInterface $write, array $fixture, MeshAllowRule $record, string $expiresAt): void
    {
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow($record->mesh_rule_id, $record->comment));
        $edit = $this->decodedResult($this->callTool('mesh_edit_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $this->ticket($fixture)->id,
            'rule_id' => $record->mesh_rule_id,
            'confirm_sender' => self::SENDER,
            'expires_at' => $expiresAt,
            'reason' => 'Change how long the vendor stays allowed.',
        ]));
        $this->assertArrayHasKey('run_id', $edit, json_encode($edit));
        $write->shouldReceive('patchRule')->once()->andReturn([]);
        $write->shouldReceive('findRuleById')->once()->andReturn(null);
        $this->actingAs($actor)->post(route('cockpit.approve', TechnicianRun::findOrFail($edit['run_id'])))->assertSessionHas('error');
        $this->assertNull($record->fresh()->mesh_rule_id);
    }

    /** The staging refusal's executor text (the MCP controller prepends its downgrade notice). */
    private function stagingRefusal(array $answer): string
    {
        $this->assertArrayNotHasKey('success', $answer, json_encode($answer));
        $this->assertArrayHasKey('error', $answer, json_encode($answer));
        $summary = (string) $this->lastBlockedSummary('mesh_stage_add_allow_rule');
        $this->assertStringEndsWith($summary, $answer['error'], 'the audited summary is the refusal');

        return $summary;
    }

    /**
     * Approve $card and assert the approval-time dedup refusal shape: the
     * whole text, exactly one audited blocked row carrying it, the card
     * CLOSED (#5568), and nothing sent to Mesh.
     */
    private function assertApprovalDedupRefused(User $actor, TechnicianRun $card, string $expected): void
    {
        $before = $this->blocked('mesh_add_allow_rule');
        $this->actingAs($actor)->post(route('cockpit.approve', $card))->assertSessionHas('error');
        $this->assertSame($expected, (string) session('error'));
        $this->assertSame($before + 1, $this->blocked('mesh_add_allow_rule'));
        $this->assertSame($expected, $this->lastBlockedSummary('mesh_add_allow_rule'));
        $this->assertSame(TechnicianRunState::Done, $card->fresh()->state, 'the refused card is closed, not released');
    }

    // ---- #5568 / #5561: the approval-time dedup closes its card -----------

    /**
     * #5561 and #5568 on the no-record arm. A create is audited 'executed'
     * and its PSA record is gone (HAND-WRITTEN: no verb deletes a
     * mesh_allow_rules row, b5b). Card B, staged before it, is approved
     * inside the window: refused, audited once, and CLOSED. At base the
     * refusal released card B, and the same click 25 hours later reached
     * createAllowRule a second time; here that click finds the card handled
     * and Mesh is not called.
     */
    public function test_the_no_record_approval_dedup_refuses_and_closes_the_card_so_no_second_create_follows(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $cardB = $this->stageAdd($fixture, ['expires_at' => now()->addDays(30)->toIso8601String()]);
        $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c2-first');
        MeshAllowRule::query()->delete();

        // Every later create is counted, so a second one is visible here
        // rather than as a mock error.
        $creates = 0;
        $write->shouldReceive('createAllowRule')->andReturnUsing(function () use (&$creates): array {
            $creates++;

            return ['added_for' => [self::TENANT]];
        });
        $write->shouldReceive('findRuleByComment')->andReturn(['id' => 'rule-b5c2-second', 'created_by' => 'owner@soundit.example.test']);

        $this->assertApprovalDedupRefused($actor, $cardB,
            "An allow rule for '".self::SENDER."' was created for this client recently, but the PSA holds no record of it, so whether it is in force cannot be stated. Resolve it in the Mesh portal by hand.".self::AT_APPROVAL.self::CARD_CLOSED);
        $this->assertSame(0, TechnicianActionLog::where('summary', 'Duplicate Mesh allow rule suppressed before upstream call.')->count(), 'not the idempotent answer');

        $this->travel(25)->hours();
        $this->actingAs($actor)->post(route('cockpit.approve', $cardB));
        $this->assertSame(0, $creates, 'approving the refused card again must not create a second rule');
        $this->assertSame(0, MeshAllowRule::count());
        $this->assertSame(TechnicianRunState::Done, $cardB->fresh()->state);
    }

    /**
     * #5568 on a REAPED record, through the verbs and the reaper: a card
     * staged before a dated rule was reaped is refused inside the window and
     * closed, so it cannot re-create the reaped rule after the window.
     */
    public function test_a_card_staged_before_a_reap_is_closed_and_cannot_recreate_the_rule_later(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $cardB = $this->stageAdd($fixture);
        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['expires_at' => now()->addHours(2)->toIso8601String()]), 'rule-b5c2-reaped');
        $write->shouldReceive('deleteRule')->once()->with('rule-b5c2-reaped');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-b5c2-reaped')->andReturn(true);
        $this->travel(3)->hours();
        $this->assertSame(1, app(MeshAllowRuleReaper::class)->reap()['reaped']);

        $write->shouldNotReceive('createAllowRule');
        $this->assertApprovalDedupRefused($actor, $cardB,
            "An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$record->id}, but the expiry job has since proved it absent upstream after its expiry (state 'reaped'). "
                .'That proves only that the rule this record tracked is gone; the PSA has not checked whether any other rule for this sender is in force on the tenant.'.self::AT_APPROVAL.self::CARD_CLOSED);

        $this->travel(25)->hours();
        $this->actingAs($actor)->post(route('cockpit.approve', $cardB));
        $this->assertSame(1, MeshAllowRule::count());
        $this->assertSame(TechnicianRunState::Done, $cardB->fresh()->state);
    }

    /**
     * Control on the boundary #5568 draws: a refusal by a LIVE brake (here
     * the unsettled brake, outside the window) still releases the card,
     * because the record that refused it refuses every later click too.
     * Only the dedup refusal, which the window ends, closes the card.
     */
    public function test_an_unsettled_brake_refusal_outside_the_window_still_releases_the_card(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $cardB = $this->stageAdd($fixture);
        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c2-unsettled');
        $record->forceFill(['state' => MeshAllowRule::STATE_UNRESOLVED, 'mesh_rule_id' => null])->save();
        $this->travel(25)->hours();

        $write->shouldNotReceive('createAllowRule');
        $this->actingAs($actor)->post(route('cockpit.approve', $cardB))->assertSessionHas('error');
        $this->assertStringStartsWith("An earlier allow rule for '".self::SENDER."' on this client (PSA record #{$record->id}) may still be live upstream", (string) session('error'));
        $this->assertStringNotContainsString('This card is closed', (string) session('error'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $cardB->fresh()->state);
    }

    // ---- #5566: the dedup names the record its matched create wrote ------

    /**
     * Two records for one sender on one client, written by two creates on
     * two tenant mappings (the tenant is part of the dedup key). Record A
     * (this tenant) was removed; record B (another tenant mapping, newer)
     * is ACTIVE with its id. Back on this tenant, the dedup key matches A's
     * create. At base the answer was the idempotent "already created" over
     * B, the sender's newest record; it is A's removal that is reported.
     */
    public function test_the_dedup_reports_the_record_its_matched_create_wrote_not_the_senders_newest(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $recordA = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c2-a');
        $this->approveRemoval($actor, $write, $fixture, $recordA, true);

        $fixture['client']->forceFill(['mesh_customer_id' => self::OTHER_TENANT])->save();
        $recordB = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c2-b', self::OTHER_TENANT);
        $this->assertGreaterThan($recordA->id, $recordB->id);
        $fixture['client']->forceFill(['mesh_customer_id' => self::TENANT])->save();

        $answer = $this->restage($fixture);
        $this->assertArrayNotHasKey('idempotent', $answer, json_encode($answer));
        $this->assertStringEndsWith(
            "An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$recordA->id}, but an approved mesh_remove_allow_rule has since proved it absent upstream (state 'removed'). "
                .'That proves only that the rule this record tracked is gone; the PSA has not checked whether any other rule for this sender is in force on the tenant. Nothing was staged now. '
                .'If the sender still needs allowing, check its rules in the Mesh portal, then stage it again once the 24-hour post-execution dedup window has elapsed.',
            $this->stagingRefusal($answer),
        );
        $this->assertStringNotContainsString('#'.$recordB->id, $answer['error']);
    }

    // ---- #5564: recordRefusal() arms the b5c-1 tests never emitted -------

    /**
     * A DATED reap_failed record, through the verbs: the removal does not
     * prove absence. Before its expiry it is told the expiry job retries the
     * removal once the expiry passes; after it, that the job retries it.
     * Then the claim is executed: the reaper selects the row and the retry
     * proves absence (REAPED).
     */
    public function test_a_dated_reap_failed_record_is_told_the_expiry_job_retries_and_it_does(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['expires_at' => now()->addHours(2)->toIso8601String()]), 'rule-b5c2-rf');
        $this->approveRemoval($actor, $write, $fixture, $record, false);
        $record->refresh();
        $expiry = $record->expires_at->toDayDateTimeString().' UTC';
        $opener = "An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$record->id}, but an earlier removal of it did not prove it absent (state 'reap_failed'), so whether the rule is still live upstream is unknown. ";

        $this->assertSame($opener."The expiry job retries the removal once its expiry ({$expiry}) passes. Nothing was staged now.", $this->stagingRefusal($this->restage($fixture)));

        $this->travel(3)->hours();
        $this->assertSame($opener."Its expiry ({$expiry}) has passed, so the expiry job retries the removal. Nothing was staged now.", $this->stagingRefusal($this->restage($fixture)));

        $write->shouldReceive('deleteRule')->once()->with('rule-b5c2-rf');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-b5c2-rf')->andReturn(true);
        $this->assertSame(1, app(MeshAllowRuleReaper::class)->reap()['reaped']);
        $this->assertSame(MeshAllowRule::STATE_REAPED, $record->fresh()->state);
    }

    /**
     * An ACTIVE record whose id display_id_lost cleared, past its expiry and
     * not yet reaped: told the expiry job tries to re-identify and remove
     * it. Executed: the reaper finds it by sender and comment and reaps it.
     */
    public function test_an_id_less_active_record_past_its_expiry_is_told_the_expiry_job_tries_and_it_does(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['expires_at' => now()->addDays(30)->toIso8601String()]), 'rule-b5c2-lost');
        $this->editWithIdLost($actor, $write, $fixture, $record, now()->addHours(2)->toIso8601String());
        $record->refresh();
        $this->travel(3)->hours();

        $this->assertSame(
            "An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$record->id}, but Mesh stopped returning the rule under its recorded id after an approved mesh_edit_allow_rule, and that id was cleared (state 'active'), so whether the rule is still in force upstream is unknown: Mesh may have re-keyed it or it may be gone. "
                .'Its expiry ('.$record->expires_at->toDayDateTimeString().' UTC) has passed, so the expiry job tries to re-identify the rule by sender and comment and remove it. Nothing was staged now.',
            $this->stagingRefusal($this->restage($fixture)),
        );

        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'rule-b5c2-rekeyed']);
        $write->shouldReceive('deleteRule')->once()->with('rule-b5c2-rekeyed');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-b5c2-rekeyed')->andReturn(true);
        $this->assertSame(1, app(MeshAllowRuleReaper::class)->reap()['reaped']);
        $this->assertSame(MeshAllowRule::STATE_REAPED, $record->fresh()->state);
    }

    /**
     * Two UNRESOLVED arms, HAND-WRITTEN (no verb leaves either inside the
     * window): scope-proved WITH an id (no "no upstream rule id" suffix, and
     * no trailing space), and scope-unproved DATED (no PERMANENT note).
     */
    public function test_the_unresolved_arms_with_an_id_and_dated_without_scope_say_only_what_applies(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture), 'rule-b5c2-unres');
        $record->forceFill(['state' => MeshAllowRule::STATE_UNRESOLVED])->save();
        $opener = "An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$record->id}, but the PSA cannot currently say whether that rule is live upstream (state 'unresolved'), so it is not answered as already in place. ";

        $this->assertSame(
            $opener.'Its scope WAS confirmed when it was created, so the expiry job only has to identify it: once one rule in Mesh carries its sender and comment, the record goes active with that id and the remove and edit verbs can reach it. Nothing was staged now.',
            $this->stagingRefusal($this->restage($fixture)),
        );

        $record->forceFill(['scope_proved' => false, 'mesh_rule_id' => null, 'expires_at' => now()->addDays(5)])->save();
        $error = $this->stagingRefusal($this->restage($fixture));
        $this->assertSame(
            $opener.'Mesh never confirmed its scope, so identifying the rule does not settle this record. It closes when the PSA proves the rule removed: an approved mesh_remove_allow_rule can do that once the record carries its upstream id, '
                ."and the expiry job does it after the record's expiry passes; otherwise someone has to check the rule in the Mesh portal and clear the PSA record by hand. Nothing was staged now.",
            $error,
        );
        $this->assertStringNotContainsString('PERMANENT', $error);
    }

    /**
     * The at-approval tail on the unresolved and display_id_lost dedup arms
     * (only removed and reap_failed were emitted at approval before), each
     * closing its card (#5568).
     */
    public function test_the_approval_dedup_tail_on_unresolved_and_display_id_lost_records(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldNotReceive('createAllowRule')->byDefault();

        $cardB = $this->stageAdd($fixture);
        $cardC = $this->stageAdd($fixture);
        $record = $this->approveCleanCreate($actor, $write, $this->stageAdd($fixture, ['expires_at' => now()->addDays(30)->toIso8601String()]), 'rule-b5c2-tail');
        $this->editWithIdLost($actor, $write, $fixture, $record, now()->addDays(10)->toIso8601String());
        $record->refresh();

        $this->assertApprovalDedupRefused($actor, $cardB,
            "An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$record->id}, but Mesh stopped returning the rule under its recorded id after an approved mesh_edit_allow_rule, and that id was cleared (state 'active'), so whether the rule is still in force upstream is unknown: Mesh may have re-keyed it or it may be gone. "
                .'Once its expiry ('.$record->expires_at->toDayDateTimeString().' UTC) passes, the expiry job tries to re-identify the rule by sender and comment and remove it.'.self::AT_APPROVAL.self::CARD_CLOSED);

        $record->forceFill(['state' => MeshAllowRule::STATE_UNRESOLVED])->save();
        $this->assertApprovalDedupRefused($actor, $cardC,
            "An allow rule for '".self::SENDER."' was created for this client recently as PSA record #{$record->id}, but the PSA cannot currently say whether that rule is live upstream (state 'unresolved'), so it is not answered as already in place. "
                .'Its scope WAS confirmed when it was created, so the expiry job only has to identify it: once one rule in Mesh carries its sender and comment, the record goes active with that id and the remove and edit verbs can reach it. '
                .'Until then the PSA holds no upstream rule id for it, so neither verb can.'.self::AT_APPROVAL.self::CARD_CLOSED);
    }
}
