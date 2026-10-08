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
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * #5743 residuals, burner b5d.
 *
 *  - #5748 / #5749: ONE live-record rule at approval. A record is live until
 *    a removal or a reap proves its rule gone, whatever its expiry says, so
 *    an ACTIVE record past its expiry and not yet reaped (a fault's own
 *    record included) refuses a second create.
 *  - #5751: the since-staging fault arm and its own-record exemption, with
 *    executed_with_fault rows the executor itself writes.
 *  - #5752: the same-second boundary and the no-staging-row fallback.
 *  - #5756: the close compare-and-set on a release with no re-claim, and
 *    its whereNull(claimed_at) branch.
 *  - #5746 / #5747: the refusal says what happened to the card and who
 *    wrote the create.
 *
 * G-5: Http is faked with stray requests prevented; MeshClient and
 * MeshWriteClient (which bypass the Http facade) are container doubles. A
 * call a double was not set up for throws at the call, and a path that
 * catches Throwable could swallow that, so creates are COUNTED (write()).
 * Synthetic data only (G-13).
 */
class MeshAllowRuleBurnerB5dTest extends TestCase
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

    private const SAME_SECOND = 'In the same second this card was staged (the PSA records these times only to the second, so it cannot say which came first), ';

    private const NOT_CLOSED = 'This card was NOT closed: while this refusal was being written it was released or claimed again elsewhere, so it keeps that state and is not changed by this approval.';

    private ?string $token = null;

    private int $creates = 0;

    /** What the next createAllowRule() answers: 'clean', 'unresolved' (no id on re-read) or 'scope' (wrong added_for). */
    private string $nextCreate = 'clean';

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
     * The MeshWriteClient double. Every createAllowRule() is COUNTED, so a
     * second create shows up as a count, never as a mock error a refusal
     * path could swallow. $nextCreate picks the answer: a clean scope-proved
     * create, one whose id cannot be re-read (rule_id_unresolved), or one
     * whose added_for names another tenant (scope_unconfirmed).
     */
    private function write(): Mockery\MockInterface
    {
        $write = Mockery::mock(MeshWriteClient::class);
        $write->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $write->shouldReceive('createAllowRule')->andReturnUsing(function (): array {
            $this->creates++;

            return ['added_for' => [$this->nextCreate === 'scope' ? self::OTHER_TENANT : self::TENANT]];
        });
        $write->shouldReceive('findRuleByComment')->andReturnUsing(
            fn (): ?array => $this->nextCreate === 'unresolved' ? null : ['id' => 'rule-b5d-'.$this->creates]
        );
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

    private function faults(): int
    {
        return TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed_with_fault')->count();
    }

    /** Approve $card and assert the since-staging refusal: whole text, one audited blocked row, card closed, no create. */
    private function assertRefusedSinceStaging(User $actor, TechnicianRun $card, string $change, string $opener = 'Since this card was staged, '): void
    {
        $creates = $this->creates;
        $blocked = $this->blocked();
        $this->approve($actor, $card)->assertSessionHas('error');
        $expected = $opener.$change.self::SINCE_TAIL;
        $this->assertSame($expected, (string) session('error'));
        $this->assertSame($blocked + 1, $this->blocked());
        $this->assertSame($expected, TechnicianActionLog::where('result_status', 'blocked')->latest('id')->value('summary'));
        $this->assertSame(TechnicianRunState::Done, $card->fresh()->state, 'the refused card is closed');
        $this->assertSame($creates, $this->creates, 'no second create');
    }

    /**
     * Approve $card and assert the unsettled brake refused it for $record as an
     * ACTIVE record past its expiry: whole text, audited, card RELEASED (a
     * live-brake refusal, b5c2 ruling), no create.
     */
    private function assertRefusedAsLive(User $actor, TechnicianRun $card, MeshAllowRule $record): void
    {
        $creates = $this->creates;
        $blocked = $this->blocked();
        $this->approve($actor, $card)->assertSessionHas('error');
        $expected = "An earlier allow rule for '".self::SENDER."' on this client (PSA record #{$record->id}) may still be live upstream and has not been proved absent, so a second rule was not created. "
            .'That record is active and its expiry ('.$record->expires_at->toIso8601String().') has passed, but Mesh does not expire rules on its own and the hourly expiry job has not removed it yet, '
            .'so the rule is treated as live: this block stays until the PSA proves it removed (the expiry job tries that on its next run). Until then, '
            .'allow this sender directly in the Mesh portal if it is needed now. No upstream call was made.';
        $this->assertSame($expected, (string) session('error'));
        $this->assertSame($blocked + 1, $this->blocked());
        $this->assertSame(TechnicianRunState::AwaitingApproval, $card->fresh()->state, 'a live-brake refusal releases the card');
        $this->assertSame($creates, $this->creates, 'no second create');
    }

    // ---- #5748 / #5749: one live-record rule ------------------------------

    /**
     * #5748, the issue's scenario. Card C is staged. Sibling A's create ends
     * executed_with_fault (rule_id_unresolved: scope proved, id not re-read)
     * and writes its own record with an expiry. The expiry job identifies
     * the rule and settles the record ACTIVE with its id; then the expiry
     * passes and the job has not run again. At base C passed every brake
     * (the create fact skipped the fault for its record, the live brake the
     * expired row, the unsettled brake the ACTIVE one, the ended fact the
     * unreaped one) and created a SECOND rule. Here the record counts as
     * live until it is reaped: C is refused and released. Once the reap
     * proves the rule gone, C (staged before the reap) is refused and
     * closed, and a proposal staged after the reap creates.
     */
    public function test_a_faults_own_active_record_past_its_expiry_refuses_a_sibling_until_it_is_reaped(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $write = $this->write();

        $cardC = $this->stageAdd($client);
        $this->travel(1)->seconds();
        $this->nextCreate = 'unresolved';
        $this->approve($actor, $this->stageAdd($client, ['expires_at' => now()->addHours(2)->toIso8601String()]));
        $this->nextCreate = 'clean';
        $this->assertSame(1, $this->faults(), 'A ended executed_with_fault');
        $record = MeshAllowRule::sole();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        $this->assertTrue((bool) $record->scope_proved);

        $write->shouldReceive('findRulesByComment')->once()->andReturn([['id' => 'rule-b5d-settled']]);
        app(MeshAllowRuleReaper::class)->reap();
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->fresh()->state, 'the expiry job settled the fault record');

        $this->travel(3)->hours();
        $this->assertFalse($record->fresh()->expires_at->isFuture(), 'past its expiry, not yet reaped');
        $this->assertRefusedAsLive($actor, $cardC, $record->fresh());
        $this->assertSame(1, $this->creates);

        $this->travel(1)->seconds();
        $write->shouldReceive('deleteRule')->once()->with('rule-b5d-settled');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-b5d-settled')->andReturn(true);
        $this->assertSame(1, app(MeshAllowRuleReaper::class)->reap()['reaped']);
        $this->assertRefusedSinceStaging($actor, $cardC,
            "PSA record #{$record->id} for '".self::SENDER."' on this client was closed by the expiry job, which proved its rule absent");

        $this->travel(1)->seconds();
        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        $this->assertSame(2, $this->creates, 'a proposal staged after the reap creates');
    }

    /**
     * The same rule with a clean create (one rule for all paths): the dedup
     * window has passed and the record is ACTIVE with its id, past its
     * expiry and not reaped. A card staged now (staging does not answer
     * "already allowed" from an expired record) was approved at base and
     * created a second rule; here it is refused as live.
     */
    public function test_a_clean_records_active_expired_unreaped_state_refuses_a_card_staged_after_it(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $this->approve($actor, $this->stageAdd($client, ['expires_at' => now()->addHours(2)->toIso8601String()]))->assertSessionHas('success');
        $record = MeshAllowRule::sole();
        $this->travel(25)->hours();

        $card = $this->stageAdd($client);
        $this->assertRefusedAsLive($actor, $card, $record->fresh());
        $this->assertSame(1, $this->creates);
    }

    /**
     * #5749: the unlinked note promises that while the id-less record stays,
     * a new allow rule for this sender is refused at approval. For a DATED
     * ACTIVE id-less record past its expiry that was false at base (the
     * live brake skips it as expired, the unsettled brake as ACTIVE). Here
     * the card says it, and approval does it.
     */
    public function test_the_unlinked_notes_refusal_promise_holds_for_an_expired_active_id_less_record(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $record = MeshAllowRule::create([
            'client_id' => $client->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => self::SENDER,
            'comment' => 'PSA allow b5dtoken',
            'mesh_rule_id' => null,
            'expires_at' => now()->subDay()->startOfSecond(),
            'state' => MeshAllowRule::STATE_ACTIVE,
            'scope_proved' => true,
        ]);

        $write = Mockery::mock(MeshWriteClient::class);
        $write->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $write->shouldReceive('findRuleById')->once()->andReturn([
            'id' => 'rule-b5d-rekeyed', 'sender' => self::SENDER, 'comment' => 'PSA allow b5dtoken',
            'ab' => MeshWriteClient::ALLOW_RULE, 'created_by' => 'owner@soundit.example.test', 'date_expiry' => null,
        ]);
        $this->app->instance(MeshWriteClient::class, $write);
        $staged = $this->decoded($this->callTool('mesh_remove_allow_rule', [
            'client_id' => $client->id,
            'ticket_id' => $this->ticket($client)->id,
            'rule_id' => 'rule-b5d-rekeyed',
            'confirm_sender' => self::SENDER,
            'reason' => 'Remove it.',
        ]));
        $this->assertStringContainsString('It stays as it is, and while it does, a new allow rule for this sender is refused (when it is approved', (string) TechnicianRun::findOrFail($staged['run_id'])->proposed_content);

        $this->write();
        $card = $this->stageAdd($client);
        $this->assertRefusedAsLive($actor, $card, $record->fresh());
        $this->assertSame(0, $this->creates);
    }

    // ---- #5751: the fault arm, with faults the executor writes -------------

    /**
     * record_unwritable after staging: sibling A's create lands and its PSA
     * record cannot be written (the insert throws), so the executor audits
     * executed_with_fault with no record. 25 hours on, faultedExecution()'s
     * window has passed, and only the since-staging fault arm stands between
     * card C and a second rule beside an untracked one. Deleting that arm
     * lets C create.
     */
    public function test_a_record_unwritable_fault_after_staging_refuses_the_card_after_the_window(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $cardC = $this->stageAdd($client);
        $this->travel(1)->seconds();
        $unwritable = true;
        MeshAllowRule::creating(function () use (&$unwritable): void {
            if ($unwritable) {
                throw new \RuntimeException('synthetic: the record insert fails');
            }
        });
        $this->approve($actor, $this->stageAdd($client));
        $unwritable = false;
        $this->assertSame(1, $this->creates);
        $this->assertSame(1, $this->faults());
        $this->assertSame(0, MeshAllowRule::count(), 'no record was written');
        $this->assertStringContainsString('could not be written', (string) TechnicianActionLog::where('result_status', 'executed_with_fault')->sole()->summary);

        $this->travel(25)->hours();
        $this->assertRefusedSinceStaging($actor, $cardC,
            "another proposal for '".self::SENDER."' on this client was approved and its create FAULTED after reaching Mesh, and no PSA record of it exists now");
        $this->assertSame(1, $this->creates);
    }

    /**
     * The own-record exemption: sibling A's create ends scope_unconfirmed,
     * so its record stores the tenant Mesh attested, not this one. The
     * expiry job later reaps that record (proved absent). Card C, staged
     * before A, is the corrected retry both fault texts promise: the
     * record is closed, the ended fact does not see it (another tenant),
     * and the fault row is exempt because A wrote a record. C creates.
     * Dropping the exemption refuses it.
     */
    public function test_a_fault_that_wrote_its_own_record_is_exempt_once_that_record_is_closed(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $write = $this->write();

        $cardC = $this->stageAdd($client);
        $this->travel(1)->seconds();
        $this->nextCreate = 'scope';
        $this->approve($actor, $this->stageAdd($client, ['expires_at' => now()->addHours(2)->toIso8601String()]));
        $this->nextCreate = 'clean';
        $this->assertSame(1, $this->faults());
        $record = MeshAllowRule::sole();
        $this->assertSame(self::OTHER_TENANT, $record->mesh_customer_id);

        $this->travel(3)->hours();
        $write->shouldReceive('deleteRule')->once()->with('rule-b5d-1');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-b5d-1')->andReturn(true);
        $this->assertSame(1, app(MeshAllowRuleReaper::class)->reap()['reaped']);
        $this->assertSame(MeshAllowRule::STATE_REAPED, $record->fresh()->state);

        $this->travel(25)->hours();
        $this->approve($actor, $cardC)->assertSessionHas('success');
        $this->assertSame(2, $this->creates, 'the corrected retry creates');
        $this->assertSame(TechnicianRunState::Done, $cardC->fresh()->state);
    }

    // ---- #5752: boundary and fallback pins --------------------------------

    /** Delete $card's staging audit row (its write can fail without throwing), so the fallback decides. */
    private function dropStagingRow(TechnicianRun $card): void
    {
        $this->assertSame(1, DB::table('technician_action_logs')->where('run_id', $card->id)
            ->where('action_type', 'mesh_stage_add_allow_rule')->delete());
    }

    /**
     * No staging row, create in a later second: the fallback (the run's own
     * creation time) still places the create after the staging, and the
     * card is refused. A fallback that answers null lets it create.
     */
    public function test_with_no_staging_row_a_later_create_still_refuses_the_card(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $cardC = $this->stageAdd($client);
        $this->dropStagingRow($cardC);
        $this->travel(1)->seconds();
        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        MeshAllowRule::query()->delete();

        $this->travel(25)->hours();
        $this->assertRefusedSinceStaging($actor, $cardC,
            "another proposal for '".self::SENDER."' on this client was approved, and no PSA record of its create exists now");
    }

    /**
     * No staging row, create in the staging second: the fallback is by clock
     * and inclusive, so the tie is refused, and the text says it was the
     * same second instead of claiming "since". A strict fallback lets it
     * create.
     */
    public function test_with_no_staging_row_a_create_in_the_staging_second_refuses_and_says_so(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $this->freezeTime();
        $cardC = $this->stageAdd($client);
        $this->dropStagingRow($cardC);
        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        MeshAllowRule::query()->delete();
        $this->assertTrue($cardC->fresh()->created_at->equalTo(TechnicianActionLog::where('result_status', 'executed')->sole()->created_at));

        $this->travel(25)->hours();
        $this->assertRefusedSinceStaging($actor, $cardC,
            "another proposal for '".self::SENDER."' on this client was approved, and no PSA record of its create exists now", self::SAME_SECOND);
    }

    /**
     * The lower edge: a removal one second BEFORE the staging is a state the
     * card was drafted against, so it does not refuse. (The same-second ties
     * on removed_at and reaped_at are pinned in MeshAllowRuleBurnerB5c3Test.)
     */
    public function test_a_removal_one_second_before_staging_does_not_refuse_the_card(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        $record = MeshAllowRule::sole();
        $this->travel(2)->days();
        $record->forceFill(['state' => MeshAllowRule::STATE_REMOVED, 'removed_at' => now()->startOfSecond()])->save();
        $this->travel(1)->seconds();

        $cardC = $this->stageAdd($client);
        $this->approve($actor, $cardC)->assertSessionHas('success');
        $this->assertSame(2, $this->creates);
    }

    // ---- #5756 / #5746: the close compare-and-set --------------------------

    /**
     * Arm interference on the dedup refusal: the dedup arm's record lookup is
     * the last query before the close, and $change is applied to the card's
     * row right then.
     *
     * @param  array<string, mixed>  $change
     */
    private function interfereBeforeClose(TechnicianRun $card, array $change): \Closure
    {
        $armed = true;
        DB::listen(function ($query) use (&$armed, $card, $change): void {
            if ($armed && str_contains($query->sql, 'from "mesh_allow_rules"') && str_contains($query->sql, '"technician_run_id" = ?')) {
                $armed = false;
                DB::table('technician_runs')->where('id', $card->id)->update($change);
            }
        });

        return function () use (&$armed): bool {
            return ! $armed;
        };
    }

    /**
     * The stale-claim path RELEASES the run while the refusal is being
     * written and nobody re-claims it. releaseClaimTo() keeps claimed_at, so
     * only the state predicate tells this apart from the live claim; without
     * it the released run was overwritten with Done. And (#5746) the
     * approver and the blocked row are told the card was NOT closed.
     */
    public function test_a_release_without_reclaim_is_not_closed_and_the_refusal_says_so(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $cardB = $this->stageAdd($client);
        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        MeshAllowRule::query()->delete();

        $fired = $this->interfereBeforeClose($cardB, ['state' => TechnicianRunState::AwaitingApproval->value]);
        $this->approve($actor, $cardB)->assertSessionHas('error');
        $this->assertTrue($fired(), 'the release landed inside the refusal');

        $fresh = $cardB->fresh();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $fresh->state, 'a released run is not overwritten with Done');
        $this->assertNotNull($fresh->claimed_at, 'the release kept claimed_at, as releaseClaimTo() does');
        $error = (string) session('error');
        $this->assertStringEndsWith(' '.self::NOT_CLOSED, $error);
        $this->assertStringNotContainsString('This card is closed', $error);
        $this->assertSame($error, TechnicianActionLog::where('result_status', 'blocked')->latest('id')->value('summary'));
        $this->assertSame(1, $this->creates);
    }

    /**
     * The since-staging arm takes the same close: a lost compare there is
     * not called closed either (the CAS-lost text replaces the closed text
     * in the middle of the refusal).
     */
    public function test_the_since_staging_refusal_does_not_say_closed_when_the_close_loses(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $this->write();

        $cardC = $this->stageAdd($client);
        $this->travel(1)->seconds();
        $this->approve($actor, $this->stageAdd($client))->assertSessionHas('success');
        MeshAllowRule::query()->delete();
        $this->travel(25)->hours();

        $fired = $this->interfereBeforeClose($cardC, ['state' => TechnicianRunState::AwaitingApproval->value]);
        $this->approve($actor, $cardC)->assertSessionHas('error');
        $this->assertTrue($fired());
        $error = (string) session('error');
        $this->assertStringContainsString('NOT applied. '.self::NOT_CLOSED.' If the sender still needs allowing', $error);
        $this->assertStringNotContainsString('This card is closed', $error);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $cardC->fresh()->state);
        $this->assertSame(1, $this->creates);
    }

    /**
     * The whereNull(claimed_at) branch. claimForExecution() always stamps
     * claimed_at, so approveStagedRun() never hands the close a run without
     * one; the branch is pinned by calling the close directly. A run held
     * with no claimed_at is closed; the same in-memory run is NOT closed
     * once the row carries a claimed_at (a later claim took it).
     */
    public function test_the_close_of_a_claim_without_claimed_at_matches_only_a_null_claimed_at(): void
    {
        $this->configure();
        $client = $this->client();
        $card = $this->stageAdd($client);
        DB::table('technician_runs')->where('id', $card->id)->update(['state' => TechnicianRunState::Executing->value, 'claimed_at' => null]);
        $run = $card->fresh();
        $this->assertNull($run->claimed_at);

        $close = new \ReflectionMethod(\App\Services\Mcp\StaffMeshAdminToolExecutor::class, 'closeSpentCard');
        $executor = app(\App\Services\Mcp\StaffMeshAdminToolExecutor::class);

        $later = now()->startOfSecond();
        DB::table('technician_runs')->where('id', $card->id)->update(['claimed_at' => $later]);
        $this->assertSame(self::NOT_CLOSED, $close->invoke($executor, $run));
        $this->assertSame(TechnicianRunState::Executing, $card->fresh()->state, 'a later claim is not closed');

        DB::table('technician_runs')->where('id', $card->id)->update(['claimed_at' => null]);
        $this->assertStringStartsWith('This card is closed', $close->invoke($executor, $run));
        $this->assertSame(TechnicianRunState::Done, $card->fresh()->state);
    }
}
