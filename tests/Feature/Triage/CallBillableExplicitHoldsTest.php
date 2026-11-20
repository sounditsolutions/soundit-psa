<?php

namespace Tests\Feature\Triage;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TriageRun;
use App\Models\User;
use App\Services\PhoneCallService;
use App\Services\Triage\TriagePipeline;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * #8459, option (b): when a person or agent sets a call's billability (set_call_billable,
 * link_call_to_ticket, create_ticket_from_call, the call screen's toggle, edit form and bulk
 * action), the call is marked is_billable_explicit and triage reconciliation leaves its
 * value and its prepay debit alone. A default (the link default, the Voicemail false) is not
 * a decision: triage still reconciles such a call as before. Unlink clears the mark.
 * Reconcile is driven directly, as an auto-triage run on a classified ticket would.
 * Synthetic data only (G-13).
 */
class CallBillableExplicitHoldsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Contract $contract;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        Setting::setValue('triage_auto_new_tickets', '0');

        $actor = User::factory()->create(['name' => 'Synthetic AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $this->client = Client::create(['name' => 'Synthetic Explicit Client']);
        $this->contract = Contract::create([
            'client_id' => $this->client->id, 'name' => 'Synthetic Explicit Prepay',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $this->ticket = Ticket::factory()->create([
            'client_id' => $this->client->id, 'contract_id' => $this->contract->id,
            'subject' => 'Synthetic explicit ticket', 'status' => 'new',
        ]);
    }

    /** An unlinked, completed 30-minute call resolved to the client (duration is force-filled). */
    private function makeCall(array $attrs = []): PhoneCall
    {
        return PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate(array_merge([
            'call_uuid' => 'synthetic-explicit-'.uniqid(), 'direction' => CallDirection::Inbound->value,
            'from_number' => '+15555550177', 'status' => CallStatus::Completed->value,
            'client_id' => $this->client->id, 'is_billable' => null, 'duration' => 1800,
            'started_at' => now()->subMinutes(40),
        ], $attrs)))->fresh();
    }

    /** The native link: its billability default is not an explicit decision. */
    private function linkWithDefault(PhoneCall $call, ?Ticket $ticket = null): PhoneCall
    {
        app(PhoneCallService::class)->linkCallToTicket($call, ($ticket ?? $this->ticket)->id);

        return $call->fresh();
    }

    /** Classify the ticket before a link, so the link default follows the classification. */
    private function classify(bool $managedCovered, ?Ticket $ticket = null): void
    {
        TriageRun::create([
            'ticket_id' => ($ticket ?? $this->ticket)->id, 'status' => 'completed', 'stages_completed' => ['classification'],
            'stage_results' => ['classification' => ['work_covered_by_managed' => $managedCovered]],
        ]);
    }

    /** What an auto-triage (or staff re-run) classification does after classification. */
    private function reconcile(bool $managedCovered, ?Ticket $ticket = null): void
    {
        $pipeline = app(TriagePipeline::class);
        $ref = new ReflectionClass($pipeline);
        $ref->getProperty('stageResults')->setValue($pipeline, ['classification' => ['work_covered_by_managed' => $managedCovered]]);
        $ref->getMethod('reconcileBillabilityAfterClassification')->invoke($pipeline, ($ticket ?? $this->ticket)->fresh());
    }

    /** Net prepay hours drawn for the call (debits negative), and the contract balance. */
    private function assertNetDebit(PhoneCall $call, float $hours): void
    {
        $this->assertEquals(-$hours, (float) PrepayTransaction::where('phone_call_id', $call->id)->sum('hours'));
        $this->assertEquals(10 - $hours, (float) $this->contract->fresh()->prepay_balance);
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'synthetic-explicit');
    }

    private function callTool(string $tool, array $arguments, ?string $grant = null): array
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token([$grant ?? $tool])])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]);

        return $this->ok($response);
    }

    private function ok(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    private function actAsStaff(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    private function setCallBillable(PhoneCall $call, bool $billable): array
    {
        return $this->callTool('set_call_billable', [
            'phone_call_id' => $call->id, 'billable' => $billable, 'reason' => 'Synthetic decision',
        ], 'set_call_billable:immediate');
    }

    public function test_explicit_false_holds_against_a_not_managed_classification_and_takes_no_debit(): void
    {
        // Unclassified ticket: the link default is billable and debits 0.5 h.
        $call = $this->linkWithDefault($this->makeCall());
        $this->assertTrue($call->is_billable);
        $this->assertNetDebit($call, 0.5);

        $this->setCallBillable($call, false);
        $this->assertFalse($call->fresh()->is_billable);
        $this->assertNetDebit($call, 0.0);

        $this->reconcile(false);

        $this->assertFalse($call->fresh()->is_billable, 'triage must not overturn an explicit false');
        $this->assertTrue($call->fresh()->is_billable_explicit);
        $this->assertNetDebit($call, 0.0);
    }

    public function test_explicit_true_holds_against_a_managed_covered_classification_and_keeps_its_debit(): void
    {
        // Classified managed-covered before the link: the link default is not billable.
        $this->classify(true);
        $call = $this->linkWithDefault($this->makeCall());
        $this->assertFalse($call->is_billable);
        $this->assertNetDebit($call, 0.0);

        $this->setCallBillable($call, true);
        $this->assertNetDebit($call, 0.5);

        $this->reconcile(true);

        $this->assertTrue($call->fresh()->is_billable, 'triage must not overturn an explicit true');
        $this->assertNetDebit($call, 0.5);
    }

    public function test_set_call_billable_with_an_unchanged_value_still_marks_the_call_and_holds(): void
    {
        $call = $this->linkWithDefault($this->makeCall());
        $this->assertTrue($call->is_billable);
        $this->assertFalse($call->is_billable_explicit);

        $result = $this->setCallBillable($call, true);
        $this->assertTrue($result['unchanged']);
        $this->assertTrue($call->fresh()->is_billable_explicit, 'an unchanged set_call_billable is still a decision');
        $this->assertNetDebit($call, 0.5);

        $this->reconcile(true);

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertNetDebit($call, 0.5);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function verbsAndDecisions(): array
    {
        $cases = [];
        foreach (['link_call_to_ticket', 'create_ticket_from_call'] as $verb) {
            foreach ([true, false] as $billable) {
                $cases[$verb.' billable='.var_export($billable, true)] = [$verb, $billable];
            }
        }

        return $cases;
    }

    #[DataProvider('verbsAndDecisions')]
    public function test_the_link_verbs_mark_the_decision_and_triage_leaves_it(string $verb, bool $billable): void
    {
        $call = $this->makeCall();
        $arguments = ['phone_call_id' => $call->id, 'reason' => 'Synthetic decision', 'billable' => $billable];
        if ($verb === 'link_call_to_ticket') {
            $arguments['ticket_id'] = $this->ticket->id;
        }

        $result = $this->callTool($verb, $arguments);
        $ticket = Ticket::findOrFail((int) $result['ticket_id']);
        $this->assertSame($billable, (bool) $call->fresh()->is_billable);
        $this->assertTrue($call->fresh()->is_billable_explicit, $verb.' must mark the decision explicit');
        $this->assertNetDebit($call, $billable ? 0.5 : 0.0);

        // The classification that would flip it: managed-covered for true, not covered for false.
        $this->reconcile($billable, $ticket);

        $this->assertSame($billable, (bool) $call->fresh()->is_billable);
        $this->assertNetDebit($call, $billable ? 0.5 : 0.0);
    }

    public function test_the_web_toggle_marks_the_decision_and_triage_leaves_it(): void
    {
        $this->actAsStaff();
        $call = $this->linkWithDefault($this->makeCall());
        $this->assertTrue($call->is_billable);

        $this->post(route('calls.toggle-billable', $call))->assertRedirect();
        $this->assertFalse($call->fresh()->is_billable);
        $this->assertTrue($call->fresh()->is_billable_explicit);

        $this->reconcile(false);

        $this->assertFalse($call->fresh()->is_billable);
        $this->assertNetDebit($call, 0.0);
    }

    public function test_the_edit_form_marks_the_decision_and_triage_leaves_it(): void
    {
        $this->actAsStaff();
        $call = $this->linkWithDefault($this->makeCall());

        $this->patch(route('calls.update-notes', $call), ['notes' => 'Synthetic note', 'is_billable' => '0'])->assertRedirect();
        $this->assertFalse($call->fresh()->is_billable);
        $this->assertTrue($call->fresh()->is_billable_explicit);

        $this->reconcile(false);

        $this->assertFalse($call->fresh()->is_billable);
        $this->assertNetDebit($call, 0.0);
    }

    public function test_the_bulk_action_marks_the_decision_and_triage_leaves_it(): void
    {
        $this->actAsStaff();
        $call = $this->linkWithDefault($this->makeCall());

        $this->postJson(route('calls.bulk-action'), [
            'action' => 'set_billable', 'call_ids' => [$call->id], 'is_billable' => false,
        ])->assertOk()->assertJsonPath('summary.applied', 1);
        $this->assertTrue($call->fresh()->is_billable_explicit);

        $this->reconcile(false);

        $this->assertFalse($call->fresh()->is_billable);
        $this->assertNetDebit($call, 0.0);
    }

    /** A second ticket on the same client and contract. */
    private function otherTicket(): Ticket
    {
        return Ticket::factory()->create([
            'client_id' => $this->client->id, 'contract_id' => $this->contract->id,
            'subject' => 'Synthetic explicit ticket '.uniqid(), 'status' => 'new',
        ]);
    }

    public function test_a_notes_only_edit_on_an_undecided_call_writes_nothing_and_triage_still_sets_it(): void
    {
        // A recorded call on an unclassified ticket stays unset at link. The modal posts the
        // hidden 0 with its box unticked: that is no decision (#8459 r2, option A).
        $this->actAsStaff();
        foreach ([false => true, true => false] as $managedCovered => $expected) {
            $ticket = $this->otherTicket();
            $call = $this->linkWithDefault($this->makeCall(['recording_url' => 'https://example.com/synthetic.mp3']), $ticket);
            $this->assertNull($call->is_billable);

            $this->patch(route('calls.update-notes', $call), ['notes' => 'Synthetic note', 'is_billable' => '0'])->assertRedirect();

            $this->assertNull($call->fresh()->is_billable, 'an unticked box on an unset call writes nothing');
            $this->assertFalse($call->fresh()->is_billable_explicit, 'an unticked box on an unset call marks nothing');
            $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count());

            $this->reconcile((bool) $managedCovered, $ticket);

            $this->assertSame($expected, $call->fresh()->is_billable);
            $this->assertEquals($expected ? -0.5 : 0.0, (float) PrepayTransaction::where('phone_call_id', $call->id)->sum('hours'));
        }
    }

    public function test_the_edit_form_ticked_on_an_undecided_call_still_sets_true_and_marks(): void
    {
        $this->actAsStaff();
        $call = $this->linkWithDefault($this->makeCall(['recording_url' => 'https://example.com/synthetic.mp3']));
        $this->assertNull($call->is_billable);

        $this->patch(route('calls.update-notes', $call), ['notes' => 'Synthetic note', 'is_billable' => '1'])->assertRedirect();
        $this->assertTrue($call->fresh()->is_billable);
        $this->assertTrue($call->fresh()->is_billable_explicit);

        $this->reconcile(true);

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertNetDebit($call, 0.5);
    }

    public function test_the_bulk_action_on_an_unchanged_value_marks_the_decision_and_triage_leaves_it(): void
    {
        $this->actAsStaff();
        // Link default true on an unclassified ticket; staff confirm billable; triage says covered.
        $true = $this->linkWithDefault($this->makeCall());
        $this->assertTrue($true->is_billable);
        $this->assertFalse($true->is_billable_explicit);
        // Link default false on a ticket classified covered; staff confirm non-billable; triage says not covered.
        $ticket = $this->otherTicket();
        $this->classify(true, $ticket);
        $false = $this->linkWithDefault($this->makeCall(), $ticket);
        $this->assertFalse($false->is_billable);

        foreach ([[$true, true], [$false, false]] as [$call, $desired]) {
            $this->postJson(route('calls.bulk-action'), [
                'action' => 'set_billable', 'call_ids' => [$call->id], 'is_billable' => $desired,
            ])->assertOk()->assertJsonPath('summary.skipped', 1)
                ->assertJsonPath('results.0.reason', 'Already in the desired state.');
            $this->assertTrue($call->fresh()->is_billable_explicit);
        }

        $this->reconcile(true);
        $this->reconcile(false, $ticket);

        $this->assertTrue($true->fresh()->is_billable);
        $this->assertEquals(-0.5, (float) PrepayTransaction::where('phone_call_id', $true->id)->sum('hours'));
        $this->assertFalse($false->fresh()->is_billable);
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $false->id)->count());
    }

    /** Make the mark's save throw for one call; optionally make the re-read throw after it. */
    private function failMarkFor(PhoneCall $target, bool $reReadThrows = false): void
    {
        $thrown = false;
        PhoneCall::saving(function (PhoneCall $call) use ($target, &$thrown) {
            if ($call->id === $target->id && $call->isDirty('is_billable_explicit')) {
                $thrown = true;
                throw new \RuntimeException('Synthetic save failure');
            }
        });
        if ($reReadThrows) {
            PhoneCall::retrieved(function (PhoneCall $call) use ($target, &$thrown) {
                if ($thrown && $call->id === $target->id) {
                    throw new \RuntimeException('Synthetic read failure');
                }
            });
        }
    }

    public function test_a_failed_bulk_mark_is_reported_per_call_and_the_batch_still_reports_every_call(): void
    {
        $this->actAsStaff();
        $unchanged = $this->linkWithDefault($this->makeCall());
        $this->assertTrue($unchanged->is_billable);
        $other = $this->linkWithDefault($this->makeCall());
        $this->assertTrue($other->is_billable);
        $this->failMarkFor($unchanged);

        $this->postJson(route('calls.bulk-action'), [
            'action' => 'set_billable', 'call_ids' => [$unchanged->id, $other->id], 'is_billable' => true,
        ])->assertOk()
            ->assertJsonPath('summary.failed', 1)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('results.0.call_id', $unchanged->id)
            ->assertJsonPath('results.0.status', 'failed')
            ->assertJsonPath('results.0.reason', 'The decision could not be recorded; verify this call before retrying.')
            ->assertJsonPath('results.1.call_id', $other->id)
            ->assertJsonPath('results.1.status', 'skipped');
        $this->assertFalse($unchanged->fresh()->is_billable_explicit);
        $this->assertTrue($other->fresh()->is_billable_explicit);
    }

    public function test_a_failed_bulk_mark_whose_re_read_also_fails_is_reconciliation(): void
    {
        $this->actAsStaff();
        $call = $this->linkWithDefault($this->makeCall());
        $this->assertTrue($call->is_billable);
        $this->failMarkFor($call, reReadThrows: true);

        $this->postJson(route('calls.bulk-action'), [
            'action' => 'set_billable', 'call_ids' => [$call->id], 'is_billable' => true,
        ])->assertOk()
            ->assertJsonPath('summary.reconciliation', 1)
            ->assertJsonPath('results.0.status', 'reconciliation')
            ->assertJsonPath('results.0.reason', 'The outcome could not be verified; verify this call before retrying.');
    }

    public function test_the_bulk_action_still_refuses_an_unlinked_call_before_any_mark(): void
    {
        $this->actAsStaff();
        $call = $this->makeCall(['is_billable' => false]);

        $this->postJson(route('calls.bulk-action'), [
            'action' => 'set_billable', 'call_ids' => [$call->id], 'is_billable' => false,
        ])->assertOk()->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('results.0.reason', 'Call must be linked to a ticket to change billability.');
        $this->assertFalse($call->fresh()->is_billable_explicit);
    }

    public function test_an_unmarked_link_default_is_still_flipped_by_triage_with_its_debit(): void
    {
        // Default billable on an unclassified ticket, then classified managed-covered.
        $toFalse = $this->linkWithDefault($this->makeCall());
        $this->assertTrue($toFalse->is_billable);
        $this->assertFalse($toFalse->is_billable_explicit, 'the link default is not a decision');
        $this->assertNetDebit($toFalse, 0.5);

        $this->reconcile(true);

        $this->assertFalse($toFalse->fresh()->is_billable);
        $this->assertFalse($toFalse->fresh()->is_billable_explicit);
        $this->assertNetDebit($toFalse, 0.0);

        // Default not billable on a ticket classified managed-covered, then re-classified not covered.
        $other = Ticket::factory()->create([
            'client_id' => $this->client->id, 'contract_id' => $this->contract->id,
            'subject' => 'Synthetic explicit ticket 2', 'status' => 'new',
        ]);
        $this->classify(true, $other);
        $toTrue = $this->linkWithDefault($this->makeCall(), $other);
        $this->assertFalse($toTrue->is_billable);
        $this->assertFalse($toTrue->is_billable_explicit, 'the link default is not a decision');

        $this->reconcile(false, $other);

        $this->assertTrue($toTrue->fresh()->is_billable);
        $this->assertEquals(-0.5, (float) PrepayTransaction::where('phone_call_id', $toTrue->id)->sum('hours'));
    }

    public function test_a_default_set_once_the_call_completes_is_not_a_decision(): void
    {
        // Linked in progress on an unclassified ticket: unset at link, defaulted at hangup.
        \Illuminate\Support\Facades\Queue::fake();
        $call = $this->linkWithDefault($this->makeCall([
            'status' => CallStatus::InProgress->value, 'duration' => null, 'answered_at' => now()->subMinutes(30),
        ]));
        $this->assertNull($call->is_billable);
        app(PhoneCallService::class)->handleCallEnded($call->call_uuid, ['Duration' => '1800']);
        $this->assertTrue($call->fresh()->is_billable);
        $this->assertFalse($call->fresh()->is_billable_explicit);
    }

    public function test_unlink_clears_the_mark_so_a_relinked_default_is_reconciled_again(): void
    {
        $call = $this->linkWithDefault($this->makeCall());
        $this->setCallBillable($call, false);
        $this->assertTrue($call->fresh()->is_billable_explicit);

        app(PhoneCallService::class)->unlinkCallFromTicket($call->fresh());
        $this->assertNull($call->fresh()->is_billable);
        $this->assertFalse($call->fresh()->is_billable_explicit, 'unlink clears the mark with is_billable');

        $call = $this->linkWithDefault($call->fresh());
        $this->assertTrue($call->is_billable);
        $this->reconcile(true);

        $this->assertFalse($call->fresh()->is_billable);
        $this->assertNetDebit($call, 0.0);
    }

    public function test_a_voicemail_call_is_never_selected_by_reconcile(): void
    {
        $voicemail = $this->linkWithDefault($this->makeCall([
            'status' => CallStatus::Voicemail->value, 'duration' => null, 'recording_duration' => 45,
        ]));
        $this->assertFalse($voicemail->is_billable);
        $this->assertFalse($voicemail->is_billable_explicit, 'the Voicemail false is not a decision');

        $this->reconcile(false);

        $this->assertFalse($voicemail->fresh()->is_billable);
        $this->assertFalse(PrepayTransaction::where('phone_call_id', $voicemail->id)->exists());
    }
}
