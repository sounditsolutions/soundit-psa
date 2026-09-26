<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Http\Controllers\Web\TechnicianCockpitController;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\TechnicianApprovalResult;
use App\Services\Technician\TechnicianApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #3901 — the cockpit's fallback text for a decline that carries no message.
 *
 * TechnicianCockpitController::approve() renders `$result->message ?? <fallback>`
 * on 'gate_declined', and the same fallback on the match's `default` arm.
 *
 * NO COUNTS ARE QUOTED HERE. Rounds 1 and 2 both carried figures ("46 reach this
 * line", "23 of 46 unaudited") that did not reproduce as written, and round 2's
 * attempt to fix them by citing an out-of-tree script (census2.py) only moved the
 * problem: production cannot cite an instrument that does not ship. The denominator
 * is not load-bearing for this test. What is load-bearing is that SOME arms reach
 * this line with no message, which the cases below demonstrate directly.
 *
 * Two claims that earlier versions of this docblock made are WITHDRAWN as false,
 * recorded here because the withdrawal is the useful part:
 *   - "no operator surface renders technician_action_logs" — false. TicketToolActivity
 *     reads that table and TicketTimeline renders it on the staff ticket page.
 *     The original probe grepped views for the table name, which cannot match,
 *     because Blade renders a presented DTO.
 *   - "approve() dispatches only approveAndSend, approveClose, approveMerge and
 *     approveAssetMerge" — false. It dispatches 13 service methods; the four named
 *     were the ones the census happened to look at.
 *
 * These controls pin what the fallback must NOT claim: no mechanism (no pause, no
 * refusal — some sites return after the upstream call was made and failed), no
 * prescribed action (no retry), no effect claim (not "nothing was sent"), and no
 * pointer at the audit row (those surfaces withhold the diagnostic payload, so it
 * would not carry a reason).
 *
 * The `default` arm is exercised through a bound service double returning an
 * unknown status. That is a statement about a fail-closed arm, not a claim that
 * production can produce one today.
 */
class CockpitDeclineFallbackTest extends TestCase
{
    /**
     * Read from production rather than restated, so the two cannot drift and so a
     * copy edit does not have to be made twice.
     */
    private const EXPECTED_FALLBACK = TechnicianCockpitController::NO_REASON_FALLBACK;

    use RefreshDatabase;

    private function heldReplyRun(User $actor): TechnicianRun
    {
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));

        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Fallback',
            'last_name' => 'Contact',
            'email' => 'fallback@example.com',
            'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $person->id,
            'subject' => 'Printer down',
        ]);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'send_reply',
            'content_hash' => str_repeat('b', 64),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'We will get the printer back online.',
            'proposed_meta' => ['drafted_by' => 'mcp-staff:chet', 'reasons' => ['Drafted a client update.']],
        ]);
    }

    /**
     * Bind a TechnicianApprovalService whose approveAndSend returns exactly the
     * given result. The controller takes the service by method injection, so the
     * container binding is what decides which object the real route resolves —
     * the request below goes through the real controller, the real match block
     * and the real flash channel.
     */
    private function bindApprovalResult(TechnicianApprovalResult $result): void
    {
        // The signature must match the real approveAndSend exactly or PHP refuses
        // the subclass outright; the container is then handed an object the route
        // resolves in place of the real service. Everything downstream of the call
        // — the match block, the $ok list, the flash channel — is production code.
        $double = new class($result, app(TechnicianApprovalService::class)) extends TechnicianApprovalService
        {
            public function __construct(
                private TechnicianApprovalResult $forced,
                TechnicianApprovalService $real,
            ) {
                // Reuse the real collaborators rather than inventing nulls, so the
                // double differs from the real service in exactly one behaviour.
                $reflection = new \ReflectionClass(TechnicianApprovalService::class);
                $args = [];
                foreach (['gate', 'disclosure', 'email', 'recipients'] as $name) {
                    $property = $reflection->getProperty($name);
                    $property->setAccessible(true);
                    $args[] = $property->getValue($real);
                }
                parent::__construct(...$args);
            }

            public function approveAndSend(TechnicianRun $run, string $body, int $approverId, array $to = [], array $cc = []): TechnicianApprovalResult
            {
                return $this->forced;
            }
        };

        $this->app->instance(TechnicianApprovalService::class, $double);
    }

    /** The things the fallback is forbidden to say, asserted as absences. */
    private function assertFallbackClaimsNothing(string $flash): void
    {
        // The mechanism claim. A decline is not evidence the Technician is paused;
        // only the kill-switch arms make that even arguable.
        $this->assertStringNotContainsStringIgnoringCase('paused', $flash);
        // The prescribed action. Unverifiable from this frame, and false on the arms
        // that were read: clearing a kill switch, configuring an integration or
        // re-staging is what is required. NOT "false on every arm" — rounds 1 and 2
        // both quoted a whole-population claim that no instrument here supports
        // (round 2, context:13: on the Tactical bus-dispatch arm a retry might even
        // succeed, which is its own hazard and not one this line can speak to).
        $this->assertStringNotContainsStringIgnoringCase('try again', $flash);
        $this->assertStringNotContainsStringIgnoringCase('retry', $flash);
        // The effect claim. Whether an upstream call had already left is a
        // property of each arm, and most are unread.
        $this->assertStringNotContainsStringIgnoringCase('nothing was sent', $flash);
        $this->assertStringNotContainsStringIgnoringCase('was not sent', $flash);
        // The audit pointer. Not because no row exists or nothing renders it —
        // TicketToolActivity reads technician_action_logs and TicketTimeline
        // renders it on the staff ticket page (round 1, contract:4 corrected the
        // first draft's claim that no surface does). Because those surfaces
        // withhold the diagnostic payload, so the pointer would not carry a reason.
        $this->assertStringNotContainsStringIgnoringCase('audit', $flash);
        $this->assertStringNotContainsStringIgnoringCase('action log', $flash);
        // The refusal claim. The Tactical bus dispatch and agent removal return
        // this status after the upstream call was made and failed, so "declined"
        // or "refused" is a mechanism claim that is false there; and some sites
        // had a reason and dropped or audited it, so "gave no reason" is false there.
        $this->assertStringNotContainsStringIgnoringCase('declined', $flash);
        $this->assertStringNotContainsStringIgnoringCase('refused', $flash);
        $this->assertStringNotContainsStringIgnoringCase('gave no reason', $flash);
        // The confirmation claim, dropped in round 3. "Not confirmed as carried
        // out" cannot be checked from this frame either: executed_with_fault means
        // the write LANDED, and the Tactical arms return after the call was made.
        $this->assertStringNotContainsStringIgnoringCase('not confirmed', $flash);
        $this->assertStringNotContainsStringIgnoringCase('carried out', $flash);

        // EXACT MATCH (round 1, diff:5 / context:11). Everything above is a
        // deny-list, and a deny-list only forbids the vocabulary it happens to
        // name: 'blocked by the kill switch', 'disabled', 're-approve', 'resend'
        // would all pass it. The fallback is a single fixed sentence, so pinning
        // it exactly is available and is strictly stronger — any new claim, in
        // any wording, fails here. The deny-list is kept because it says WHY each
        // phrase is forbidden, which a bare assertSame would lose.
        $this->assertSame(self::EXPECTED_FALLBACK, $flash);
    }

    public function test_a_message_less_gate_declined_states_no_mechanism_no_retry_and_no_effect(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);

        // Precondition: the result really carries no message, so the ?? fallback
        // is the branch under test rather than a forwarded reason.
        $result = new TechnicianApprovalResult('gate_declined');
        $this->assertNull($result->message);
        $this->bindApprovalResult($result);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run), ['body' => 'We will get the printer back online.']);

        $response->assertSessionHas('error');
        $flash = (string) session('error');

        $this->assertFallbackClaimsNothing($flash);
    }

    public function test_the_json_path_real_operators_use_carries_the_same_bounded_text(): void
    {
        // Round 2 (context:6, contract:15): every case above asserts the session
        // flash, and the real cockpit NEVER takes that branch. index.blade.php
        // posts with Accept: application/json, so actionResponse() returns JSON
        // and the flash path is the JS-less fallback only. The wording controls
        // were therefore pinned on a branch no operator sees. This drives the
        // branch they do see.
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);
        $this->bindApprovalResult(new TechnicianApprovalResult('gate_declined'));

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('cockpit.approve', $run), ['body' => 'We will get the printer back online.']);

        $response->assertOk()->assertJsonPath('ok', false)
            // The structured keys that carry the facts the deleted prose asserted.
            ->assertJsonPath('status', 'gate_declined');

        $this->assertFallbackClaimsNothing((string) $response->json('message'));
    }

    public function test_an_empty_string_message_gets_the_fallback_and_not_a_blank_banner(): void
    {
        // Round 2 (diff:6): `?? ` only catches null, so a site passing '' rendered
        // an EMPTY error banner — strictly worse than the fallback, and invisible
        // to every control here because they all assert on absences. No site passes
        // '' today (measured: 0 of 25 message-carrying constructions), so this pins
        // the guard rather than reporting a live defect. filled() is what fixes it.
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);
        $this->bindApprovalResult(new TechnicianApprovalResult('gate_declined', message: ''));

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run), ['body' => 'We will get the printer back online.'])
            ->assertSessionHas('error');

        $flash = (string) session('error');
        $this->assertNotSame('', trim($flash), 'an empty message must not render an empty banner');
        $this->assertFallbackClaimsNothing($flash);
    }

    public function test_a_gate_declined_that_does_carry_a_reason_still_forwards_it(): void
    {
        // The change must not swallow the specific reasons that DO get forwarded:
        // the Mesh executor goes to trouble to pass its refusal text through, and
        // that path must keep winning over the fallback.
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);

        $this->bindApprovalResult(new TechnicianApprovalResult(
            'gate_declined',
            message: 'The allow rule expiry is in the past — re-stage with a later date.',
        ));

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run), ['body' => 'We will get the printer back online.'])
            ->assertSessionHas('error');

        $flash = (string) session('error');
        $this->assertStringContainsString('expiry is in the past', $flash);
        // The fallback must not be appended to a forwarded reason.
        $this->assertStringNotContainsString(TechnicianCockpitController::NO_REASON_FALLBACK, $flash);
    }

    public function test_the_default_arm_carries_the_same_bounded_text(): void
    {
        // Unreachable from any status the codebase constructs today; exercised
        // through a bound double so the fail-closed arm is pinned rather than
        // assumed. If a later status is added and forgotten, this is the text it
        // gets.
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);

        $this->bindApprovalResult(new TechnicianApprovalResult('a_status_added_later'));

        $response = $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run), ['body' => 'We will get the printer back online.']);

        $response->assertSessionHas('error');
        $flash = (string) session('error');

        $this->assertFallbackClaimsNothing($flash);
    }

    public function test_the_default_arm_forwards_a_message_when_a_later_status_carries_one(): void
    {
        // The default arm reads $result->message first for the same reason the
        // gate_declined arm does: without it, a future status that does carry an
        // operator-facing reason would have the no-reason fallback rendered
        // over it.
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);

        $this->bindApprovalResult(new TechnicianApprovalResult(
            'a_status_added_later',
            message: 'A later status explaining itself.',
        ));

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run), ['body' => 'We will get the printer back online.'])
            ->assertSessionHas('error');

        $flash = (string) session('error');
        $this->assertStringContainsString('A later status explaining itself.', $flash);
        $this->assertStringNotContainsString(TechnicianCockpitController::NO_REASON_FALLBACK, $flash);
    }

    public function test_a_neighbouring_status_is_not_flattened_onto_the_decline_text(): void
    {
        // Over-reach control. The new fallback is deliberately vague, which makes it
        // an attractive thing to reuse: flattening a neighbour onto it would trade a
        // specific true account for a generic one. 'executed_with_fault' is the
        // sharpest neighbour — the write LANDED and violated its post-condition — so
        // rendering the no-reason fallback over it would replace a true specific
        // account with silence (round 2, diff:14: this said "rendering 'declined'",
        // wording that no longer exists anywhere in the change).
        //
        // Nothing else in the suite pinned this string: existing tests assert the
        // executed_with_fault AUDIT ROW, never the text the approver reads. That gap
        // predates this change; it is closed here because this change is what makes
        // the flattening tempting.
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);

        $this->bindApprovalResult(new TechnicianApprovalResult('executed_with_fault'));

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run), ['body' => 'We will get the printer back online.'])
            ->assertSessionHas('error');

        $flash = (string) session('error');
        $this->assertStringNotContainsString(TechnicianCockpitController::NO_REASON_FALLBACK, $flash);
        $this->assertStringNotContainsStringIgnoringCase('declined', $flash);
        $this->assertStringContainsString('post-condition', $flash);
    }

    public function test_a_decline_is_still_an_error_not_a_success(): void
    {
        // Positive control on the channel, not the words: the reworded fallback
        // must not have moved a decline onto the success channel. Without this,
        // a mutation that flashes the same text as 'status' would pass every
        // assertion above.
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);

        $this->bindApprovalResult(new TechnicianApprovalResult('gate_declined'));

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run), ['body' => 'We will get the printer back online.'])
            ->assertSessionHas('error')
            ->assertSessionMissing('success');
    }
}
