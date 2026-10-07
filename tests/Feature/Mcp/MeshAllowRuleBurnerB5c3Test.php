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
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * #5638 residuals, burner b5c batch 3.
 *
 *  - #5639 / #5654 / #5653: a card staged before a create, a removal or a
 *    reap of its sender's rule is refused and CLOSED at approval, however
 *    long after the 24-hour dedup window; a card staged after the change
 *    (the "new proposal" the refusal names) still creates.
 *  - #5652: the close is compare-and-set on the claim.
 *  - #5650: the dedup reads its executed row once, with one cutoff.
 *  - #5655: the dedup names its run's record, not the sender's newest on
 *    the same tenant.
 *  - #5648 / #5640: the remove card's unlinked match is this tenant, an
 *    open state, exactly one record, case-exact; the delivery caveat stays.
 *
 * G-5: MeshClient and MeshWriteClient are container doubles; any call a
 * test did not set up throws. Synthetic data only (G-13).
 */
class MeshAllowRuleBurnerB5c3Test extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const OTHER_TENANT = '66666666-7777-8888-9999-000000000000';

    private const SENDER = 'billing@vendor.example.test';

    private const DOMAIN = 'vendor.example.test';

    private const SINCE_TAIL = ', so the card was not approved against what the PSA now records for this sender and no rule was created.'
        .' No upstream call was made and the lifetime on this proposal was NOT applied.'
        .' This card is closed, not returned to the approval queue, so approving it again cannot create a rule. Only this card is closed; any other proposal already staged for this sender stays as it is.'
        .' If the sender still needs allowing, check its rules in the Mesh portal and stage a new proposal; it is checked against what the PSA records when it is staged and again when it is approved.';

    private const CAVEAT = 'The PSA cannot prove it tracks this rule, and the comment can be edited in the Mesh portal, so the rule may have been set up outside this system: removing it may break mail delivery that is working today. ';

    private const FOREIGN = 'This rule is FOREIGN: the PSA did not create it and holds no record of it.';

    private ?string $token = null;

    private int $creates = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MeshClient::class, Mockery::mock(MeshClient::class));
        $this->app->instance(MeshWriteClient::class, Mockery::mock(MeshWriteClient::class));
    }

    private function configure(): User
    {
        Setting::setEncrypted('mesh_api_key', 'test-placeholder-not-a-key');
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
    private function decoded(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    private function client(): Client
    {
        return Client::factory()->create(['name' => 'Example Client', 'mesh_customer_id' => self::TENANT]);
    }

    private function ticket(Client $client): Ticket
    {
        return Ticket::factory()->for($client)->create(['subject' => 'Vendor mail quarantined']);
    }

    /**
     * The MeshWriteClient double. Every createAllowRule() is COUNTED and
     * answered as a clean, scope-proved create, so a second create shows up
     * as a count, never as a mock error a refusal path could swallow.
     */
    private function write(string $tenant = self::TENANT): Mockery\MockInterface
    {
        $write = Mockery::mock(MeshWriteClient::class);
        $write->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $write->shouldReceive('createAllowRule')->andReturnUsing(function () use ($tenant): array {
            $this->creates++;

            return ['added_for' => [$tenant]];
        });
        $write->shouldReceive('findRuleByComment')->andReturnUsing(fn (): array => ['id' => 'rule-b5c3-'.$this->creates]);
        $this->app->instance(MeshWriteClient::class, $write);

        return $write;
    }

    private function stageAdd(Client $client, array $overrides = []): TechnicianRun
    {
        $result = $this->decoded($this->callTool('mesh_add_allow_rule', array_merge([
            'client_id' => $client->id,
            'ticket_id' => $this->ticket($client)->id,
            'sender' => self::SENDER,
            'confirm_domain' => self::DOMAIN,
            'reason' => 'Vendor invoices are being quarantined; approved by the client contact.',
        ], $overrides)));
        $this->assertArrayHasKey('run_id', $result, 'staging failed: '.json_encode($result));

        return TechnicianRun::findOrFail($result['run_id']);
    }

    private function approve(User $actor, TechnicianRun $run): TestResponse
    {
        return $this->actingAs($actor)->post(route('cockpit.approve', $run));
    }

    private function blocked(): int
    {
        return TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'blocked')->count();
    }

    /** Approve $card and assert the since-staging refusal: whole text, one audited blocked row, card closed, no create. */
    private function assertRefusedSinceStaging(User $actor, TechnicianRun $card, string $change): void
    {
        $creates = $this->creates;
        $blocked = $this->blocked();
        $this->approve($actor, $card)->assertSessionHas('error');
        $expected = 'Since this card was staged, '.$change.self::SINCE_TAIL;
        $this->assertSame($expected, (string) session('error'));
        $this->assertSame($blocked + 1, $this->blocked());
        $this->assertSame($expected, TechnicianActionLog::where('result_status', 'blocked')->latest('id')->value('summary'));
        $this->assertSame(TechnicianRunState::Done, $card->fresh()->state, 'the refused card is closed');
        $this->assertSame($creates, $this->creates, 'no second create');

        // Closed means closed: a later click finds it handled.
        $this->travel(2)->days();
        $this->approve($actor, $card);
        $this->assertSame($creates, $this->creates);
    }

    /** Stage and approve a removal of $record's rule, proved absent: REMOVED. */
    private function removeRecord(User $actor, Mockery\MockInterface $write, Client $client, MeshAllowRule $record): void
    {
        $write->shouldReceive('findRuleById')->twice()->andReturn([
            'id' => $record->mesh_rule_id, 'sender' => self::SENDER, 'comment' => $record->comment,
            'ab' => MeshWriteClient::ALLOW_RULE, 'created_by' => 'owner@soundit.example.test', 'date_expiry' => null,
        ]);
        $staged = $this->decoded($this->callTool('mesh_remove_allow_rule', [
            'client_id' => $client->id,
            'ticket_id' => $this->ticket($client)->id,
            'rule_id' => $record->mesh_rule_id,
            'confirm_sender' => self::SENDER,
            'reason' => 'The vendor now authenticates its mail.',
        ]));
        $this->assertArrayHasKey('run_id', $staged, json_encode($staged));
        $write->shouldReceive('deleteRule')->once()->with($record->mesh_rule_id);
        $write->shouldReceive('ruleAbsent')->once()->with($record->mesh_rule_id)->andReturn(true);
        $this->approve($actor, TechnicianRun::findOrFail($staged['run_id']))->assertSessionHas('success');
        $this->assertSame(MeshAllowRule::STATE_REMOVED, $record->fresh()->state);
    }

    // ---- #5639 / #5654 / #5653: the sibling-card re-create cluster ------

    /**
     * #5639, the issue's own scenario: cards B and C staged, A approved (rule
     * R, record #1 ACTIVE), a named approver removes R. 25 hours later C is
     * approved. At base every brake was silent (dedup window passed, record
     * REMOVED) and createAllowRule ran a second time, re-creating the rule a
     * human removed. Here C is refused and closed, naming the removal.
     */
    public function test_a_sibling_card_approved_after_the_window_cannot_recreate_a_removed_rule(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $write = $this->write();

        $cardC = $this->stageAdd($client);
        $this->travel(1)->seconds();
        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        $record = MeshAllowRule::sole();
        $this->travel(1)->seconds();
        $this->removeRecord($actor, $write, $client, $record);

        $this->travel(25)->hours();
        $this->assertRefusedSinceStaging($actor, $cardC,
            "another proposal for '".self::SENDER."' on this client was approved and wrote PSA record #{$record->id} (now in state 'removed')");
        $this->assertSame(1, $this->creates);
        $this->assertSame(1, MeshAllowRule::count());
    }

    /**
     * #5639 second half: the first record was REAPED (expiry job, through
     * the reaper) and, separately, never written at all (hand-deleted: no
     * verb deletes a mesh_allow_rules row). Either way the sibling card
     * staged before the create cannot create a second rule after the window.
     */
    public function test_a_sibling_card_approved_after_the_window_cannot_create_over_a_reaped_or_missing_record(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $write = $this->write();

        $cardB = $this->stageAdd($client);
        $cardC = $this->stageAdd($client, ['expires_at' => now()->addDays(30)->toIso8601String()]);
        $this->travel(1)->seconds();
        $this->approve($actor, $this->stageAdd($client, ['expires_at' => now()->addHours(2)->toIso8601String()]))->assertSessionHas('success');
        $record = MeshAllowRule::sole();
        $write->shouldReceive('deleteRule')->once()->with($record->mesh_rule_id);
        $write->shouldReceive('ruleAbsent')->once()->with($record->mesh_rule_id)->andReturn(true);
        $this->travel(3)->hours();
        $this->assertSame(1, app(MeshAllowRuleReaper::class)->reap()['reaped']);

        $this->travel(25)->hours();
        $this->assertRefusedSinceStaging($actor, $cardB,
            "another proposal for '".self::SENDER."' on this client was approved and wrote PSA record #{$record->id} (now in state 'reaped')");

        MeshAllowRule::query()->delete();
        $this->assertRefusedSinceStaging($actor, $cardC,
            "another proposal for '".self::SENDER."' on this client was approved and its create left no PSA record");
        $this->assertSame(1, $this->creates);
    }

    /**
     * #5653 (contract:7): a run-less 'executed' create (HAND-WRITTEN audit
     * row, run_id null, as a direct-mode write would leave it) after the
     * card was staged. Inside the window the dedup refuses it as "no
     * record"; this card waited past the window, and the since-staging fact
     * still refuses and closes it rather than letting a second create run.
     */
    public function test_a_run_less_executed_create_after_staging_refuses_the_card_after_the_window(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $card = $this->stageAdd($client);
        // The write's base key (StaffMeshAdminToolExecutor::contentHash(),
        // expiry-free), which the executed row of any create carries.
        $direct = hash('sha256', json_encode([
            'tool' => 'mesh_stage_add_allow_rule',
            'client_id' => $client->id,
            'target' => 'allow-rule-'.self::SENDER,
            'params' => ['mesh_customer_id' => self::TENANT],
        ]));
        TechnicianActionLog::create([
            'actor_id' => $actor->id,
            'actor_label' => 'direct',
            'action_type' => 'mesh_add_allow_rule',
            'tier' => \App\Enums\TechnicianTier::Approve->value,
            'result_status' => 'executed',
            'client_id' => $client->id,
            'run_id' => null,
            'content_hash' => $direct,
            'summary' => 'Created Mesh allow rule (synthetic, run-less).',
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->travel(25)->hours();
        $this->assertRefusedSinceStaging($actor, $card,
            "another proposal for '".self::SENDER."' on this client was approved and its create left no PSA record");
        $this->assertSame(0, $this->creates);
    }

    /**
     * #5654: a card the UNSETTLED brake refused is released (b5c2 ruling:
     * live-brake refusals release). The record is later reaped. At base the
     * released card's next click passed every brake and created a new rule;
     * the refusal had told nobody it would. Here the reap after staging
     * refuses and closes it.
     */
    public function test_a_card_released_by_the_unsettled_brake_cannot_create_after_the_record_is_reaped(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $write = $this->write();

        $this->approve($actor, $this->stageAdd($client, ['expires_at' => now()->addHours(2)->toIso8601String()]))->assertSessionHas('success');
        $record = MeshAllowRule::sole();
        $record->forceFill(['state' => MeshAllowRule::STATE_UNRESOLVED])->save();
        $this->travel(25)->hours();

        $cardC = $this->stageAdd($client);
        $this->approve($actor, $cardC)->assertSessionHas('error');
        $this->assertStringStartsWith("An earlier allow rule for '".self::SENDER."' on this client (PSA record #{$record->id}) may still be live upstream", (string) session('error'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $cardC->fresh()->state, 'a live-brake refusal still releases the card');

        $this->travel(1)->seconds();
        $write->shouldReceive('deleteRule')->once()->with($record->mesh_rule_id);
        $write->shouldReceive('ruleAbsent')->once()->with($record->mesh_rule_id)->andReturn(true);
        $this->assertSame(1, app(MeshAllowRuleReaper::class)->reap()['reaped']);

        $this->assertRefusedSinceStaging($actor, $cardC,
            "PSA record #{$record->id} for '".self::SENDER."' on this client was closed by the expiry job, which proved its rule absent");
        $this->assertSame(1, $this->creates);
    }

    /**
     * #5654 on the removal arm, with a record from an OLDER create (outside
     * the window, so the create fact does not apply): the card staged before
     * a named approver's removal is refused, naming the removal.
     */
    public function test_a_card_staged_before_a_removal_is_refused_naming_the_removal(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $write = $this->write();

        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        $record = MeshAllowRule::sole();
        $record->forceFill(['state' => MeshAllowRule::STATE_REAP_FAILED])->save();
        $this->travel(2)->days();
        $cardC = $this->stageAdd($client);
        $this->travel(1)->seconds();
        $record->forceFill(['state' => MeshAllowRule::STATE_ACTIVE])->save();
        $this->removeRecord($actor, $write, $client, $record);

        $this->assertRefusedSinceStaging($actor, $cardC,
            "PSA record #{$record->id} for '".self::SENDER."' on this client was closed by an approved mesh_remove_allow_rule that proved its rule absent");
    }

    /**
     * #5653: the "new proposal" the refusal promises, executed. After the
     * refusal, a proposal staged AFTER the removal is approved and creates;
     * and a card staged before nothing changed (no create, no removal, no
     * reap) is not touched by the new brake at all.
     */
    public function test_a_new_proposal_staged_after_the_change_creates_and_an_untouched_card_still_creates(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $write = $this->write();

        $cardC = $this->stageAdd($client);
        $this->travel(1)->seconds();
        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        $record = MeshAllowRule::sole();
        $this->travel(1)->seconds();
        $this->removeRecord($actor, $write, $client, $record);
        $this->travel(25)->hours();
        $this->assertRefusedSinceStaging($actor, $cardC,
            "another proposal for '".self::SENDER."' on this client was approved and wrote PSA record #{$record->id} (now in state 'removed')");

        $fresh = $this->stageAdd($client);
        $this->approve($actor, $fresh)->assertSessionHas('success');
        $this->assertSame(2, $this->creates, 'the new proposal creates');
        $this->assertSame(TechnicianRunState::Done, $fresh->fresh()->state);

        // Control on a sender nothing happened to: staged, waited out, created.
        $other = $this->stageAdd($client, ['sender' => 'orders@vendor.example.test']);
        $this->travel(25)->hours();
        $this->approve($actor, $other)->assertSessionHas('success');
        $this->assertSame(3, $this->creates);
    }

    /**
     * Boundaries the brake must not over-reach: a create on ANOTHER tenant
     * mapping (another write key) and a removal on another tenant do not
     * refuse this tenant's card; a fault that wrote its own record (the
     * corrected retry its text promises) is that record's business.
     */
    public function test_the_since_staging_brake_is_scoped_to_this_write(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $card = $this->stageAdd($client);
        $client->forceFill(['mesh_customer_id' => self::OTHER_TENANT])->save();
        $write = $this->write(self::OTHER_TENANT);
        $this->travel(1)->seconds();
        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        $other = MeshAllowRule::sole();
        $this->travel(1)->seconds();
        $this->removeRecord($actor, $write, $client, $other);
        $client->forceFill(['mesh_customer_id' => self::TENANT])->save();
        $this->write();

        $this->travel(1)->hours();
        $this->approve($actor, $card)->assertSessionHas('success');
        $this->assertSame(2, $this->creates);
    }

    // ---- #5652: the close is compare-and-set on the claim ----------------

    /**
     * While the dedup refusal is being written (the blocked audit row), the
     * stale-claim path releases the run and a second approver re-claims it.
     * At base advanceTo(Done) was a plain save and overwrote that second
     * claim with Done. Here the close only lands on this request's claim.
     */
    public function test_the_dedup_close_does_not_overwrite_a_claim_taken_meanwhile(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $cardB = $this->stageAdd($client);
        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        MeshAllowRule::query()->delete();

        $reclaimedAt = now()->addMinutes(20)->startOfSecond();
        TechnicianActionLog::creating(function (TechnicianActionLog $log) use ($cardB, $reclaimedAt): void {
            if ($log->result_status === 'blocked' && (int) $log->run_id === $cardB->id) {
                DB::table('technician_runs')->where('id', $cardB->id)->update([
                    'state' => TechnicianRunState::Executing->value,
                    'claimed_at' => $reclaimedAt,
                ]);
            }
        });

        $this->approve($actor, $cardB)->assertSessionHas('error');
        $this->assertStringContainsString('This card is closed', (string) session('error'));
        $fresh = $cardB->fresh();
        $this->assertSame(TechnicianRunState::Executing, $fresh->state, 'the second claim is not overwritten with Done');
        $this->assertTrue($fresh->claimed_at->equalTo($reclaimedAt));
        $this->assertSame(1, $this->creates);
    }

    /** Control: with no interference the same refusal closes the card. */
    public function test_the_dedup_close_lands_on_its_own_claim(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $cardB = $this->stageAdd($client);
        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        MeshAllowRule::query()->delete();

        $this->approve($actor, $cardB)->assertSessionHas('error');
        $this->assertSame(TechnicianRunState::Done, $cardB->fresh()->state);
    }

    // ---- #5650: one cutoff ----------------------------------------------

    /**
     * The dedup reads its executed audit row ONCE per answer, at staging
     * and at approval, so the 24-hour cutoff is computed once. At base
     * alreadyExecuted() and executedRunId() each queried with their own
     * now()->subHours(24), and a row could age out between the two.
     */
    public function test_the_dedup_reads_its_executed_row_once_with_one_cutoff(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $cardB = $this->stageAdd($client);
        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'technician_action_logs') && str_contains($query->sql, '"created_at" >=')
                && in_array('executed', $query->bindings, true)) {
                $reads++;
            }
        });

        $answer = $this->decoded($this->callTool('mesh_add_allow_rule', [
            'client_id' => $client->id,
            'ticket_id' => $this->ticket($client)->id,
            'sender' => self::SENDER,
            'confirm_domain' => self::DOMAIN,
            'reason' => 'Again.',
        ]));
        $this->assertTrue($answer['idempotent'] ?? false, json_encode($answer));
        $this->assertSame(1, $reads, 'staging: one read of the executed row');

        $reads = 0;
        $this->approve($actor, $cardB);
        $this->assertSame(1, $reads, 'approval: one read of the executed row');
    }

    // ---- #5655: the dedup names its run's record ------------------------

    /**
     * Same tenant, same sender: the executed run's record is ACTIVE with its
     * id, and a NEWER record (HAND-WRITTEN, as a later faulted create would
     * leave it) is UNRESOLVED. The dedup must answer from the run's record.
     * A latest-by-tenant-and-sender lookup would pick the unresolved one and
     * refuse; the tenant switch in the b5c2 test could not tell them apart.
     */
    public function test_the_dedup_names_the_run_record_not_a_newer_same_tenant_record(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        $ran = MeshAllowRule::sole();
        $later = MeshAllowRule::create([
            'client_id' => $client->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => self::SENDER,
            'comment' => 'PSA allow b5c3later',
            'mesh_rule_id' => null,
            'expires_at' => null,
            'state' => MeshAllowRule::STATE_UNRESOLVED,
            'scope_proved' => false,
        ]);
        $this->assertGreaterThan($ran->id, $later->id);

        $answer = $this->decoded($this->callTool('mesh_add_allow_rule', [
            'client_id' => $client->id,
            'ticket_id' => $this->ticket($client)->id,
            'sender' => self::SENDER,
            'confirm_domain' => self::DOMAIN,
            'reason' => 'Again.',
        ]));
        $this->assertTrue($answer['idempotent'] ?? false, json_encode($answer));
        $this->assertStringContainsString("This allow rule was already created recently: PSA record #{$ran->id} is PERMANENT", $answer['message']);
        $this->assertStringNotContainsString('#'.$later->id, $answer['message']);
    }

    // ---- #5648 / #5640: the unlinked match on the remove card -----------

    /** An id-less record, HAND-WRITTEN (display_id_lost leaves one through the verbs; b5b/b5c pin that path). */
    private function idLess(Client $client, array $overrides = []): MeshAllowRule
    {
        return MeshAllowRule::create(array_merge([
            'client_id' => $client->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => self::SENDER,
            'comment' => 'PSA allow b5c3token',
            'mesh_rule_id' => null,
            'expires_at' => null,
            'state' => MeshAllowRule::STATE_ACTIVE,
            'scope_proved' => true,
        ], $overrides));
    }

    /** Stage a removal of a rule Mesh returns with $comment and return the card text. */
    private function removalCard(Client $client, string $comment = 'PSA allow b5c3token'): string
    {
        $write = Mockery::mock(MeshWriteClient::class);
        $write->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $write->shouldReceive('findRuleById')->once()->andReturn([
            'id' => 'rule-b5c3-rekeyed', 'sender' => self::SENDER, 'comment' => $comment,
            'ab' => MeshWriteClient::ALLOW_RULE, 'created_by' => 'owner@soundit.example.test', 'date_expiry' => null,
        ]);
        $this->app->instance(MeshWriteClient::class, $write);
        $staged = $this->decoded($this->callTool('mesh_remove_allow_rule', [
            'client_id' => $client->id,
            'ticket_id' => $this->ticket($client)->id,
            'rule_id' => 'rule-b5c3-rekeyed',
            'confirm_sender' => self::SENDER,
            'reason' => 'Remove it.',
        ]));
        $this->assertArrayHasKey('run_id', $staged, json_encode($staged));

        return (string) TechnicianRun::findOrFail($staged['run_id'])->proposed_content;
    }

    /** Positive control: exactly one open id-less record on this tenant is named, with the delivery caveat kept. */
    public function test_one_open_id_less_record_on_this_tenant_is_named_with_the_delivery_caveat(): void
    {
        $this->configure();
        $client = $this->client();
        $record = $this->idLess($client);

        $card = $this->removalCard($client);
        $this->assertStringContainsString("The PSA holds no record under this rule id, but PSA record #{$record->id} (state 'active', no recorded rule id) carries this rule's sender and comment. ".self::CAVEAT, $card);
        $this->assertStringContainsString(' Approval re-checks this: if the expiry job records this rule\'s id on that record before the card is approved, approving closes the record as removed.', $card);
        $this->assertStringNotContainsString('FOREIGN', $card);
    }

    /**
     * Each narrowing, one at a time, falls back to FOREIGN: another tenant,
     * a closed state (removed, reaped), two matches, a case difference in
     * the comment or in the stored sender. At base every one of these was
     * named as the matching record and the FOREIGN warning was dropped.
     */
    public function test_the_unlinked_match_is_this_tenant_an_open_state_exactly_one_and_case_exact(): void
    {
        $this->configure();
        $client = $this->client();

        $cases = [
            'another tenant' => [['mesh_customer_id' => self::OTHER_TENANT]],
            'removed' => [['state' => MeshAllowRule::STATE_REMOVED]],
            'reaped' => [['state' => MeshAllowRule::STATE_REAPED]],
            'two matches' => [[], ['state' => MeshAllowRule::STATE_UNRESOLVED]],
            'sender case' => [['sender' => 'Billing@vendor.example.test']],
        ];
        foreach ($cases as $label => $rows) {
            MeshAllowRule::query()->delete();
            foreach ($rows as $row) {
                $this->idLess($client, $row);
            }
            $card = $this->removalCard($client);
            $this->assertStringContainsString(self::FOREIGN, $card, $label);
            $this->assertStringNotContainsString('carries this rule\'s sender and comment', $card, $label);
        }

        MeshAllowRule::query()->delete();
        $this->idLess($client);
        $card = $this->removalCard($client, 'PSA ALLOW B5C3TOKEN');
        $this->assertStringContainsString(self::FOREIGN, $card, 'comment case');
    }

    /** The unlinked match is still found on each open state, unresolved and reap_failed included. */
    public function test_the_unlinked_match_covers_the_unsettled_states(): void
    {
        $this->configure();
        $client = $this->client();

        foreach ([MeshAllowRule::STATE_UNRESOLVED, MeshAllowRule::STATE_REAP_FAILED] as $state) {
            MeshAllowRule::query()->delete();
            $record = $this->idLess($client, ['state' => $state]);
            $card = $this->removalCard($client);
            $this->assertStringContainsString("PSA record #{$record->id} (state '{$state}', no recorded rule id) carries this rule's sender and comment. ".self::CAVEAT, $card, $state);
        }
    }

    /**
     * #5647 / #5653: the expiry job settles the id-less record between
     * staging and approval (exactly one match, scope proved), so approval
     * re-derives the target, finds the record by id and closes it. The card
     * said so in advance; the executed summary says what happened.
     */
    public function test_a_settle_between_stage_and_approve_closes_the_record_as_the_card_said_it_could(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $record = $this->idLess($client, ['state' => MeshAllowRule::STATE_UNRESOLVED]);

        $card = $this->removalCard($client);
        $this->assertStringContainsString('approving closes the record as removed', $card);
        $run = TechnicianRun::latest('id')->firstOrFail();

        $write = Mockery::mock(MeshWriteClient::class);
        $write->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $write->shouldReceive('findRulesByComment')->once()->andReturn([['id' => 'rule-b5c3-rekeyed']]);
        $this->app->instance(MeshWriteClient::class, $write);
        app(MeshAllowRuleReaper::class)->reap();
        $this->assertSame('rule-b5c3-rekeyed', $record->fresh()->mesh_rule_id);

        $write->shouldReceive('findRuleById')->once()->andReturn([
            'id' => 'rule-b5c3-rekeyed', 'sender' => self::SENDER, 'comment' => 'PSA allow b5c3token',
            'ab' => MeshWriteClient::ALLOW_RULE, 'created_by' => 'owner@soundit.example.test', 'date_expiry' => null,
        ]);
        $write->shouldReceive('deleteRule')->once()->with('rule-b5c3-rekeyed');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-b5c3-rekeyed')->andReturn(true);
        $this->approve($actor, $run)->assertSessionHas('success');

        $this->assertSame(MeshAllowRule::STATE_REMOVED, $record->fresh()->state);
        $summary = TechnicianActionLog::where('action_type', 'mesh_remove_allow_rule')->where('result_status', 'executed')->sole()->summary;
        $this->assertStringEndsWith(" PSA record #{$record->id} closed.", $summary);
    }

    // ---- #5649: the tool descriptions say what the remove card does -----

    public function test_the_tool_descriptions_name_the_unlinked_match_and_no_longer_call_it_foreign(): void
    {
        $definitions = collect(\App\Services\Mcp\StaffMeshAdminToolExecutor::definitions())->keyBy('name');

        $list = $definitions['mesh_list_allow_rules']['description'];
        $this->assertStringNotContainsString('they treat the rule as foreign', $list);
        $this->assertStringContainsString('mesh_edit_allow_rule refuses the rule as foreign even where psa_created is true', $list);
        $this->assertStringContainsString('in which case the card names that record and says it stays open', $list);

        $remove = $definitions['mesh_remove_allow_rule']['description'];
        $this->assertStringNotContainsString('a rule the PSA never wrote is labelled FOREIGN', $remove);
        $this->assertStringContainsString('If exactly one PSA record with no recorded rule id (not removed or reaped) matches the rule by sender and comment, the card names that record instead, keeps that warning, and says the record is NOT closed by the removal.', $remove);

        $stage = $definitions['mesh_stage_remove_allow_rule']['description'];
        $this->assertStringNotContainsString('whether the rule is PSA-TRACKED or FOREIGN,', $stage);
        $this->assertStringContainsString('whether the rule is PSA-TRACKED, FOREIGN, or matched by sender and comment to a PSA record with no recorded rule id (which the removal does not close)', $stage);
    }
}
