<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\PhoneCall;
use App\Models\SipEndpoint;
use App\Models\User;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Card 6aac6ee770e3c3433477d91f — a redelivered webhook regresses a COMPLETED
 * call back to Ringing and re-dates its start (which in turn can mis-date the
 * prepay charge — see the dating note below for the ordering that decides
 * whether it actually does).
 *
 * Same defect shape as the answered_by one that was fixed just before this:
 * PhoneCall::updateOrCreate()'s values array is applied on the UPDATE branch as
 * well as the create branch, and Plivo re-delivers webhooks (its own callbacks
 * doc says so: "Plivo may send duplicate callbacks due to retries. Handle
 * idempotently using CallUUID as the key"). 'status' => Ringing and
 * 'started_at' => now() were the two columns left behind when answered_by was
 * moved out — flagged at diff:1/context:1 and deliberately deferred to this
 * branch.
 *
 * Why started_at is not cosmetic, cited rather than asserted: PrepayService
 * dates the transaction it writes with `$call->started_at ?? $call->created_at`
 * (PrepayService.php, the two 'date' => assignments in debitFromPhoneCall's
 * write path). So re-dating started_at re-dates the prepay charge for the call.
 * Note the `?? $call->created_at` fallback bounds the blast radius - a row whose
 * started_at were NULL would fall back to creation time rather than to nothing -
 * but on these rows started_at is set, so the redelivery value is what is used.
 *
 * THREE of the five tests below were RED-CHECKED against 4474fa88 and fail
 * there: the two outbound regressions and the inbound one. The remaining two are
 * GREEN both before and after by construction and are guards on the FIX rather
 * than on the defect - that a first delivery still establishes Ringing and a
 * start time, and that the answered_by guard is undisturbed. An earlier version
 * of this docblock said "all four", which was wrong on both the count and the
 * red-check claim. Fixtures are varied across both affected paths — logOutboundCall AND
 * logIncomingCall carry the identical hazard, and a fix applied to only one of
 * them would pass half of this file.
 */
class CallRedeliveryRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function endpointFor(User $user, string $sipUri): SipEndpoint
    {
        return SipEndpoint::create([
            'sip_uri' => $sipUri,
            'sip_username' => 'redeliveryendpoint',
            'sip_password' => 'irrelevant',
            'user_id' => $user->id,
            'label' => 'Redelivery endpoint',
            'is_active' => true,
        ]);
    }

    /**
     * THE REGRESSION. A completed outbound call, then a redelivered webhook for
     * the same CallUUID.
     *
     * RED at 4474fa88: status came back as Ringing.
     */
    public function test_a_redelivered_outbound_webhook_does_not_regress_a_completed_call_to_ringing(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->endpointFor($user, 'sip:tester@phone.plivo.com');
        $service = app(PhoneCallService::class);

        $payload = [
            'CallUUID' => 'redelivery-status-regression',
            'From' => 'sip:tester@phone.plivo.com',
            'To' => '+15555550123',
        ];

        $service->logOutboundCall($payload);

        // The call runs to completion.
        $call = PhoneCall::where('call_uuid', $payload['CallUUID'])->firstOrFail();
        $call->status = CallStatus::Completed;
        $call->answered_at = now()->subMinutes(9);
        $call->ended_at = now();
        $call->duration = 540;
        $call->save();

        // Plivo redelivers the browser-answer webhook.
        $service->logOutboundCall($payload);

        $this->assertSame(CallStatus::Completed, $call->fresh()->status,
            'a redelivered webhook must never walk a finished call backwards to Ringing');
    }

    /**
     * THE RE-DATING, which is the one that moves money: started_at feeds the
     * prepay charge's dating - see the class docblock for the cited assignment
     * in PrepayService. This test pins the timestamp, not the charge: no prepay
     * transaction is exercised here, so it is evidence for the input to that
     * dating, not proof of a mis-dated transaction end to end.
     *
     * RED at 4474fa88: started_at was rewritten to the redelivery instant.
     */
    public function test_a_redelivered_outbound_webhook_does_not_re_date_started_at(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->endpointFor($user, 'sip:tester@phone.plivo.com');
        $service = app(PhoneCallService::class);

        $payload = [
            'CallUUID' => 'redelivery-started-at',
            'From' => 'sip:tester@phone.plivo.com',
            'To' => '+15555550124',
        ];

        $service->logOutboundCall($payload);
        $originalStart = PhoneCall::where('call_uuid', $payload['CallUUID'])->firstOrFail()->started_at;
        $this->assertNotNull($originalStart, 'the create branch must still establish started_at');

        $this->travel(37)->minutes();
        $service->logOutboundCall($payload);

        $this->assertTrue($originalStart->equalTo(PhoneCall::where('call_uuid', $payload['CallUUID'])->firstOrFail()->started_at),
            'the call started when it started; a redelivery 37 minutes later must not re-date it, because prepay dating reads this column');
    }

    /**
     * THE SAME HAZARD ON THE INBOUND PATH. logIncomingCall() carries an
     * identical updateOrCreate values array. A fix applied to only one call site
     * would pass the two tests above and fail this one — which is why the
     * fixture is deliberately the other direction.
     *
     * RED at 4474fa88: status regressed to Ringing and started_at was re-dated.
     */
    public function test_a_redelivered_inbound_webhook_regresses_neither_status_nor_started_at(): void
    {
        Queue::fake();
        $service = app(PhoneCallService::class);

        $payload = [
            'CallUUID' => 'redelivery-inbound',
            'From' => '+15555550101',
            'To' => '+15555550100',
        ];

        $service->logIncomingCall($payload);
        $originalStart = PhoneCall::where('call_uuid', 'redelivery-inbound')->firstOrFail()->started_at;

        $call = PhoneCall::where('call_uuid', 'redelivery-inbound')->firstOrFail();
        $call->status = CallStatus::Voicemail;
        $call->ended_at = now();
        $call->save();

        $this->travel(11)->minutes();
        $service->logIncomingCall($payload);

        $stored = PhoneCall::where('call_uuid', 'redelivery-inbound')->firstOrFail();
        $this->assertSame(CallStatus::Voicemail, $stored->status,
            'a redelivered inbound webhook must not regress a voicemail to Ringing');
        $this->assertTrue($originalStart->equalTo($stored->started_at));
    }

    /**
     * The create branch must still do its job. Removing two keys from an
     * updateOrCreate values array is only correct if the create path still
     * establishes them. Precisely: status HAS a DB default of 'ringing', so the
     * schema does cover that one and the assignment is belt-and-braces;
     * started_at is nullable with no default, so that write is the one actually
     * doing the work on create. (An earlier version of this docblock said the
     * schema covered neither, which was the same error the source comment
     * carried and which was corrected there first.)
     *
     * GREEN before and after by construction; it is the guard on the fix.
     */
    public function test_a_first_delivery_still_establishes_ringing_and_a_start_time(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->endpointFor($user, 'sip:tester@phone.plivo.com');

        app(PhoneCallService::class)->logOutboundCall([
            'CallUUID' => 'first-delivery-outbound',
            'From' => 'sip:tester@phone.plivo.com',
            'To' => '+15555550125',
        ]);
        $outbound = PhoneCall::where('call_uuid', 'first-delivery-outbound')->firstOrFail();
        $this->assertSame(CallStatus::Ringing, $outbound->status);
        $this->assertNotNull($outbound->started_at);
        $this->assertSame(CallDirection::Outbound, $outbound->direction);

        app(PhoneCallService::class)->logIncomingCall([
            'CallUUID' => 'first-delivery-inbound',
            'From' => '+15555550101',
            'To' => '+15555550100',
        ]);
        $inbound = PhoneCall::where('call_uuid', 'first-delivery-inbound')->firstOrFail();
        $this->assertSame(CallStatus::Ringing, $inbound->status);
        $this->assertNotNull($inbound->started_at);
    }

    /**
     * The fix must not falsify the long comment it sits beside: answered_by is
     * still resolved and still never cleared by a redelivery. Re-pinned here
     * because this change edits that exact values array.
     */
    public function test_the_answered_by_guard_is_undisturbed_by_this_change(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $endpoint = $this->endpointFor($user, 'sip:tester@phone.plivo.com');
        $service = app(PhoneCallService::class);

        $payload = [
            'CallUUID' => 'redelivery-answered-by-intact',
            'From' => 'sip:tester@phone.plivo.com',
            'To' => '+15555550126',
        ];

        $service->logOutboundCall($payload);
        $this->assertSame($user->id, PhoneCall::where('call_uuid', $payload['CallUUID'])->firstOrFail()->answered_by);

        $endpoint->update(['is_active' => false]);
        $service->logOutboundCall($payload);

        $this->assertSame($user->id, PhoneCall::where('call_uuid', $payload['CallUUID'])->firstOrFail()->answered_by,
            'a redelivery that resolves no user must still leave the established attribution alone');
    }
}
