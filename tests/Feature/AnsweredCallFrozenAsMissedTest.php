<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Http\Controllers\Api\PlivoWebhookController;
use App\Models\PhoneCall;
use App\Models\SipEndpoint;
use App\Models\User;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Card 6aac6037 — answered calls frozen as "missed", and card 6aac6ee7 — a
 * redelivered webhook regressing a completed call to Ringing.
 *
 * MEASURED DEFECT (8 production rows at the time of writing, 6 of them long real
 * conversations, the longest 3319s): status='missed' while carrying an
 * answered_by user and a NULL answered_at. The mechanism is three call sites
 * that between them drop a late-arriving fact:
 *
 *  1. handleCallAnswered()'s late branch was `elseif ($call->duration > 0)`.
 *     Plivo's DialAction=answer fires at END of dial, so it routinely arrives
 *     after hangup; when the hangup webhook carried no usable Duration, duration
 *     is still null at that instant, BOTH branches were skipped and answered_at
 *     was never stamped — while the answered_by resolution below still ran and
 *     stored the answerer. That contradiction IS the fingerprint.
 *  2. handleCallEnded() decides status from answered_at ALONE, deliberately
 *     (Plivo's Duration includes voicemail recording time). null => Missed.
 *  3. handleRecordingReady() backfilled duration and re-ran only the prepay
 *     debit. The correcting fact arrived and was dropped.
 *
 * RED-CHECK STATUS, and do not read a blanket claim into it. Some tests here
 * were red-checked against the unfixed code at 4474fa88 and fail there; their
 * individual notes name the failure each produced. The rest pass both before
 * and after by construction and are guards on the FIX rather than on the defect
 * - the unanswered-B-leg negative, the unknown-vendor-word negative, the
 * unanswered-outbound negative, the CallStatus negatives and the two voicemail
 * guards. NOT ALL OF THOSE CARRY AN INDIVIDUAL GREEN NOTE, so the per-test notes
 * are not a complete index of which is which; this paragraph is the honest
 * summary and neither it nor they should be read as covering every method.
 * Earlier versions of this line claimed every test was red-checked, and then
 * that the others each said so individually. Both were false.
 *
 * FIXTURES ARE VARIED DELIBERATELY. The masking pattern this repo has been
 * bitten by three times is fixtures that all sit at the benign value, so a
 * broken reader passes the whole provider. So the answer-evidence tests below do
 * NOT all carry the same key: one drives DialBLegUUID, one DialBLegDuration, one
 * DialBLegStatus, one DialAction=connected, and the negatives carry the keys that
 * are present on a call NOBODY answered (DialBLegTo alone, empty DialBLegUUID,
 * and the top-level CallStatus=in-progress that describes the A leg Plivo itself
 * answered) so a reader that accepts any Dial* key — or the A leg's status — at
 * all fails here.
 */
class AnsweredCallFrozenAsMissedTest extends TestCase
{
    use RefreshDatabase;

    private function endpointFor(User $user, string $sipUri, bool $active = true): SipEndpoint
    {
        return SipEndpoint::create([
            'sip_uri' => $sipUri,
            'sip_username' => 'guardendpoint',
            'sip_password' => 'irrelevant',
            'user_id' => $user->id,
            'label' => 'Guard endpoint',
            'is_active' => $active,
        ]);
    }

    /**
     * An inbound call that has already ended, exactly as the hangup webhook
     * leaves it when Plivo omitted Duration: ended, status Missed, duration
     * null, answered_at null.
     */
    private function endedCallWithNoDuration(string $uuid, CallStatus $status = CallStatus::Missed): PhoneCall
    {
        $call = PhoneCall::create([
            'call_uuid' => $uuid,
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550101',
            'to_number' => '+15555550100',
            'status' => $status,
            'started_at' => now()->subMinutes(30),
        ]);

        $call->ended_at = now();
        $call->duration = null;
        $call->answered_at = null;
        $call->save();

        return $call->fresh();
    }

    // ── (1) stamp answered_at when an answer is genuinely observed ──────────

    /**
     * THE REPRODUCTION. The exact production fingerprint, driven through the
     * real call site with a real Plivo-shaped late-answer payload.
     *
     * RED at 4474fa88: status stayed Missed and answered_at stayed null while
     * answered_by was written — the contradiction seen on 8 production rows.
     */
    public function test_late_answer_with_no_duration_stamps_answered_at_and_unfreezes_the_status(): void
    {
        $user = User::factory()->create();
        $this->endpointFor($user, 'sip:tech@phone.plivo.com');
        $call = $this->endedCallWithNoDuration('late-answer-no-duration');

        app(PhoneCallService::class)->handleCallAnswered('late-answer-no-duration', [
            'CallUUID' => 'late-answer-no-duration',
            'DialAction' => 'answer',
            'DialBLegTo' => 'sip:tech@phone.plivo.com',
            'DialBLegUUID' => 'b-leg-9e1f4a20',
        ]);

        $stored = $call->fresh();

        $this->assertNotNull($stored->answered_at,
            'a genuinely observed answer must stamp answered_at even when duration is unknown — leaving it null is what freezes the call as missed');
        $this->assertSame(CallStatus::Completed, $stored->status);
        $this->assertSame($user->id, $stored->answered_by,
            'the answered_by resolution must be unchanged by the reordering');
    }

    /**
     * The row must never again be able to hold the contradiction itself. This is
     * the invariant the card is about, asserted directly.
     *
     * RED at 4474fa88: the row held answered_by with a null answered_at.
     */
    public function test_a_call_with_an_answerer_is_never_left_missed_with_a_null_answered_at(): void
    {
        $user = User::factory()->create();
        $this->endpointFor($user, 'sip:tech@phone.plivo.com');
        $this->endedCallWithNoDuration('contradiction-invariant');

        app(PhoneCallService::class)->handleCallAnswered('contradiction-invariant', [
            'CallUUID' => 'contradiction-invariant',
            'DialAction' => 'answer',
            'DialBLegTo' => 'sip:tech@phone.plivo.com',
            'DialBLegUUID' => 'b-leg-contradiction',
        ]);

        $this->assertSame(0, PhoneCall::query()
            ->where('status', CallStatus::Missed->value)
            ->whereNotNull('answered_by')
            ->whereNull('answered_at')
            ->count(),
            'no row may carry an answerer, a missed status and a null answered_at at once — that combination is the production defect');
    }

    /**
     * DIFFERENT EVIDENCE KEY: DialBLegDuration, with DialBLegUUID absent
     * entirely. A reader that only understood the UUID would pass the test above
     * and fail here — which is the point of varying the fixture.
     *
     * RED at 4474fa88: answered_at null. (Note DialBLegDuration is NOT the same
     * as the call's duration column: the controller maps it into Duration only
     * on the HANGUP branch, and this is the answer branch.)
     */
    public function test_a_b_leg_duration_is_accepted_as_answer_evidence_on_its_own(): void
    {
        $this->endedCallWithNoDuration('late-answer-bleg-duration');

        app(PhoneCallService::class)->handleCallAnswered('late-answer-bleg-duration', [
            'CallUUID' => 'late-answer-bleg-duration',
            'DialAction' => 'answer',
            'DialBLegDuration' => '742',
        ]);

        $stored = PhoneCall::where('call_uuid', 'late-answer-bleg-duration')->firstOrFail();

        $this->assertNotNull($stored->answered_at);
        $this->assertSame(CallStatus::Completed, $stored->status);
    }

    /**
     * DIFFERENT EVIDENCE KEY AGAIN: DialBLegStatus, and separately
     * DialAction=connected. Plivo's docs enumerate no value list for
     * DialBLegStatus, so the reader matches affirmative words only;
     * DialAction is documented as answer|connected|hangup|digits and
     * 'connected' is the explicit bridge event. Both are B-leg scoped, which
     * the top-level CallStatus is not — that value is pinned as a NEGATIVE
     * below, because the A leg is up on a call nobody picked up.
     *
     * RED at 4474fa88 on both rows: answered_at null.
     */
    public function test_affirmative_b_leg_evidence_is_accepted_as_answer_evidence(): void
    {
        $service = app(PhoneCallService::class);

        $this->endedCallWithNoDuration('late-answer-blegstatus');
        $service->handleCallAnswered('late-answer-blegstatus', [
            'CallUUID' => 'late-answer-blegstatus',
            'DialAction' => 'answer',
            'DialBLegStatus' => 'answer',
        ]);
        $this->assertNotNull(PhoneCall::where('call_uuid', 'late-answer-blegstatus')->firstOrFail()->answered_at);

        $this->endedCallWithNoDuration('late-answer-dialaction-connected');
        $service->handleCallAnswered('late-answer-dialaction-connected', [
            'CallUUID' => 'late-answer-dialaction-connected',
            'DialAction' => 'connected',
        ]);
        $this->assertNotNull(PhoneCall::where('call_uuid', 'late-answer-dialaction-connected')->firstOrFail()->answered_at);
    }

    /**
     * THE NEGATIVE THAT MATTERS MOST. A missed inbound call still RINGS a
     * tech's SIP endpoint, so DialBLegTo is present on a call nobody picked up,
     * and Plivo states DialBLegUUID is "empty if nobody answers". A reader that
     * treated any Dial* key — or DialBLegTo, or an empty B-leg UUID, or the A
     * leg's own CallStatus — as an answer would convert every genuinely missed
     * call into a completed one.
     *
     * This test is GREEN both before and after the fix by construction, and it
     * is the guard on the fix rather than on the defect: it is what stops the
     * repair being worse than the disease.
     */
    public function test_a_dialled_but_unanswered_b_leg_is_not_treated_as_an_answer(): void
    {
        $user = User::factory()->create();
        $this->endpointFor($user, 'sip:tech@phone.plivo.com');
        $this->endedCallWithNoDuration('never-answered');

        app(PhoneCallService::class)->handleCallAnswered('never-answered', [
            'CallUUID' => 'never-answered',
            'DialAction' => 'answer',
            'DialBLegTo' => 'sip:tech@phone.plivo.com',
            'DialBLegUUID' => '',
            'DialBLegDuration' => '0',
            'DialBLegStatus' => 'no-answer',
            'DialBLegHangupCauseName' => 'NO_ANSWER',
            // The A leg is STILL UP at end-of-dial - Plivo answered it to run the
            // Dial at all - so a real no-answer payload carries this affirmative
            // value alongside every B-leg key that says nobody picked up.
            'CallStatus' => 'in-progress',
        ]);

        $stored = PhoneCall::where('call_uuid', 'never-answered')->firstOrFail();

        $this->assertNull($stored->answered_at,
            'DialBLegTo names the destination ATTEMPTED and is present on a call nobody answered; an empty DialBLegUUID is Plivo saying nobody answered, and CallStatus=in-progress is only the A leg');
        $this->assertSame(CallStatus::Missed, $stored->status);
    }

    /**
     * THE A-LEG TRAP, pinned on its own with NO B-leg evidence in the payload at
     * all. The top-level CallStatus is the A leg's state, and on an inbound call
     * Plivo has already answered the A leg in order to execute the Dial/Record
     * XML — so it reads 'in-progress' for the whole time a tech's endpoint is
     * merely ringing, and on a call that rings out to voicemail. Plivo retries
     * callbacks and PlivoWebhookController routes CallStatus=in-progress straight
     * to handleCallAnswered, so such a delivery lands on an already-ended,
     * duration-less row. Accepting it there would flip a genuinely missed call to
     * Completed and suppress the voicemail auto-detect: the severe direction.
     *
     * GREEN both before and after the fix by construction; it is the guard on the
     * fix, and it is what stops the repair being worse than the disease.
     */
    public function test_a_top_level_call_status_is_not_treated_as_answer_evidence(): void
    {
        $service = app(PhoneCallService::class);

        $this->endedCallWithNoDuration('a-leg-in-progress-only');
        $service->handleCallAnswered('a-leg-in-progress-only', [
            'CallUUID' => 'a-leg-in-progress-only',
            'CallStatus' => 'in-progress',
        ]);

        $stored = PhoneCall::where('call_uuid', 'a-leg-in-progress-only')->firstOrFail();
        $this->assertNull($stored->answered_at,
            'CallStatus describes the A leg Plivo itself answered and is affirmative on a call nobody picked up');
        $this->assertSame(CallStatus::Missed, $stored->status);

        // The other affirmative A-leg word, on a row already detected as a
        // voicemail: it must neither stamp an answer moment nor resurrect it.
        $this->endedCallWithNoDuration('a-leg-answered-voicemail', CallStatus::Voicemail);
        $service->handleCallAnswered('a-leg-answered-voicemail', [
            'CallUUID' => 'a-leg-answered-voicemail',
            'CallStatus' => 'answered',
        ]);

        $voicemail = PhoneCall::where('call_uuid', 'a-leg-answered-voicemail')->firstOrFail();
        $this->assertNull($voicemail->answered_at);
        $this->assertSame(CallStatus::Voicemail, $voicemail->status);
    }

    /**
     * An unrecognised DialBLegStatus value must fail CLOSED. A vendor word we
     * have never read is not evidence of an answer.
     */
    public function test_an_unknown_b_leg_status_is_not_treated_as_an_answer(): void
    {
        $this->endedCallWithNoDuration('unknown-bleg-status');

        app(PhoneCallService::class)->handleCallAnswered('unknown-bleg-status', [
            'CallUUID' => 'unknown-bleg-status',
            'DialAction' => 'answer',
            'DialBLegStatus' => 'some-word-plivo-has-not-documented',
        ]);

        $this->assertNull(PhoneCall::where('call_uuid', 'unknown-bleg-status')->firstOrFail()->answered_at);
        $this->assertSame(CallStatus::Missed, PhoneCall::where('call_uuid', 'unknown-bleg-status')->firstOrFail()->status);
    }

    /**
     * An OUTBOUND call already carries answered_by from logOutboundCall() — the
     * PLACING user, written before any answer event exists. So answered_by must
     * never be read as answer evidence, or a dead outbound dial would be stamped
     * answered.
     */
    public function test_an_unanswered_outbound_dial_is_not_stamped_answered_from_its_placing_user(): void
    {
        $user = User::factory()->create();

        $call = PhoneCall::create([
            'call_uuid' => 'outbound-never-picked-up',
            'direction' => CallDirection::Outbound,
            'from_number' => '+15555550188',
            'to_number' => '+15555550100',
            'answered_by' => $user->id,
            'status' => CallStatus::Missed,
            'started_at' => now()->subMinutes(2),
        ]);
        $call->ended_at = now();
        $call->save();

        app(PhoneCallService::class)->handleCallAnswered('outbound-never-picked-up', [
            'CallUUID' => 'outbound-never-picked-up',
            'DialAction' => 'answer',
            'DialBLegUUID' => '',
            'DialBLegStatus' => 'busy',
        ]);

        $this->assertNull($call->fresh()->answered_at,
            'answered_by is the PLACING user on an outbound call and proves nothing about an answer');
    }

    /**
     * The pre-existing arms must be byte-for-byte unchanged in behaviour.
     * Live answer: status InProgress, answered_at ~now. Late answer WITH a
     * duration: answered_at derived from it, not the ceiling.
     */
    public function test_the_existing_live_and_duration_derived_arms_are_unchanged(): void
    {
        $service = app(PhoneCallService::class);

        PhoneCall::create([
            'call_uuid' => 'live-answer',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550101',
            'status' => CallStatus::Ringing,
            'started_at' => now(),
        ]);
        $service->handleCallAnswered('live-answer', ['CallUUID' => 'live-answer', 'CallStatus' => 'in-progress']);
        $live = PhoneCall::where('call_uuid', 'live-answer')->firstOrFail();
        $this->assertSame(CallStatus::InProgress, $live->status);
        $this->assertNotNull($live->answered_at);

        $call = $this->endedCallWithNoDuration('late-answer-with-duration');
        $call->duration = 600;
        $call->save();
        $service->handleCallAnswered('late-answer-with-duration', [
            'CallUUID' => 'late-answer-with-duration',
            'DialAction' => 'answer',
            'DialBLegUUID' => 'b-leg-with-duration',
        ]);
        $stored = $call->fresh();
        $this->assertSame(CallStatus::Completed, $stored->status);
        $this->assertSame(600, (int) $stored->ended_at->diffInSeconds($stored->answered_at, true),
            'with a duration present the answer moment is derived from it, never the ended_at ceiling');
    }

    // ── the voicemail ordering hazard (owed guard) ────────────────────────

    /**
     * THE ORDERING HAZARD, and the reason this guard is owed. The voicemail
     * auto-detect at PlivoWebhookController keys off answered_at === null on a
     * >=3s recording. Stamping answered_at EARLIER could make a genuine
     * voicemail no longer detectable as one.
     *
     * This drives the real controller with a real recording payload on a call
     * NOBODY answered, and pins that it is still detected as voicemail — which
     * holds because the negative above is what keeps answered_at null on such a
     * call. If a future change loosened the answer evidence, this test is what
     * would catch a voicemail being silently reclassified.
     */
    public function test_a_genuine_voicemail_is_still_detected_as_voicemail(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->endpointFor($user, 'sip:tech@phone.plivo.com');

        // The call rang the tech and was not picked up, then went to voicemail.
        $this->endedCallWithNoDuration('voicemail-ordering');
        app(PhoneCallService::class)->handleCallAnswered('voicemail-ordering', [
            'CallUUID' => 'voicemail-ordering',
            'DialAction' => 'answer',
            'DialBLegTo' => 'sip:tech@phone.plivo.com',
            'DialBLegUUID' => '',
            'DialBLegStatus' => 'no-answer',
        ]);

        $request = Request::create('/api/plivo/secret/webhook', 'POST', [
            'CallUUID' => 'voicemail-ordering',
            'RecordUrl' => 'https://media.plivo.com/v1/Account/MA/Recording/rec-voicemail.mp3',
            'RecordingDuration' => 42,
        ]);
        app(PlivoWebhookController::class)->handle($request);

        $this->assertSame(CallStatus::Voicemail,
            PhoneCall::where('call_uuid', 'voicemail-ordering')->firstOrFail()->status,
            'a genuine voicemail must still be detected as voicemail — the auto-detect keys off answered_at === null');
    }

    /**
     * And once a row IS a voicemail, the second look must not resurrect it, no
     * matter how long the recording is. A 200-second voicemail is still a
     * voicemail.
     */
    public function test_the_second_look_never_resurrects_a_voicemail(): void
    {
        Queue::fake();
        $call = $this->endedCallWithNoDuration('voicemail-not-resurrected', CallStatus::Voicemail);

        app(PhoneCallService::class)->handleRecordingReady(
            'voicemail-not-resurrected',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-long-voicemail.mp3',
            200,
        );

        $stored = $call->fresh();
        $this->assertSame(CallStatus::Voicemail, $stored->status);
        $this->assertNull($stored->answered_at,
            'a voicemail has no answer moment; a long recording is not an answer');
    }

    /**
     * The test above is VACUOUS for the guard it names, and this one exists
     * because of that. Found by mutation: deleting reconcile's Voicemail early
     * return leaves the test above still passing, because on a row with a null
     * answered_at and a non-Missed status the rest of the method is a no-op
     * anyway. It was pinning the outcome through the wrong mechanism.
     *
     * The case that actually needs the guard: handleCallAnswered's ceiling arm
     * guards only the STATUS write against voicemail and stamps answered_at
     * regardless, so a Voicemail row CAN carry a ceiling answered_at. On exactly
     * that row the early return is the only thing stopping the second look
     * rewriting a genuine voicemail's timestamp. Removing the guard fails this.
     */
    public function test_the_second_look_does_not_rewrite_a_voicemails_answered_at(): void
    {
        Queue::fake();
        $call = $this->endedCallWithNoDuration('voicemail-ceiling', CallStatus::Voicemail);
        $call->answered_at = $call->ended_at->copy();
        $call->save();

        app(PhoneCallService::class)->handleRecordingReady(
            'voicemail-ceiling',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-vm-ceiling.mp3',
            120,
        );

        $stored = $call->fresh();
        $this->assertSame(CallStatus::Voicemail, $stored->status);
        $this->assertTrue($stored->answered_at->equalTo($stored->ended_at),
            'a voicemail row is not the second look\'s business at all');
    }

    // ── (2) handleRecordingReady() takes the second look ───────────────────

    /**
     * THE SECOND LOOK. The correcting fact — a real duration — arrives on the
     * recording webhook. Before the fix it backfilled duration, re-ran only the
     * prepay debit, and left the call frozen as Missed.
     *
     * RED at 4474fa88: status stayed Missed.
     */
    public function test_a_late_duration_unfreezes_a_call_that_carries_an_answer_fingerprint(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->endpointFor($user, 'sip:tech@phone.plivo.com');
        $call = $this->endedCallWithNoDuration('second-look-unfreeze');

        // The late answer webhook stamps the ceiling and corrects the status…
        app(PhoneCallService::class)->handleCallAnswered('second-look-unfreeze', [
            'CallUUID' => 'second-look-unfreeze',
            'DialAction' => 'answer',
            'DialBLegTo' => 'sip:tech@phone.plivo.com',
            'DialBLegUUID' => 'b-leg-second-look',
        ]);

        // …and the recording then supplies the honest duration.
        app(PhoneCallService::class)->handleRecordingReady(
            'second-look-unfreeze',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-second-look.mp3',
            1462,
        );

        $stored = $call->fresh();

        $this->assertSame(CallStatus::Completed, $stored->status);
        $this->assertSame(1462, (int) $stored->ended_at->diffInSeconds($stored->answered_at, true),
            'the recording duration is the primary path to a TRUE answered_at — it must replace the ended_at ceiling, not sit on top of it');
    }

    /**
     * The ceiling is transient by design, and this is the assertion that says
     * so: after the second look answered_at is strictly EARLIER than ended_at.
     *
     * RED at 4474fa88: answered_at was null, so there was nothing to correct.
     */
    public function test_the_ceiling_answered_at_is_replaced_by_the_true_answer_moment(): void
    {
        Queue::fake();
        $call = $this->endedCallWithNoDuration('ceiling-replaced');

        app(PhoneCallService::class)->handleCallAnswered('ceiling-replaced', [
            'CallUUID' => 'ceiling-replaced',
            'DialAction' => 'answer',
            'DialBLegUUID' => 'b-leg-ceiling',
        ]);

        $ceiling = $call->fresh();
        $this->assertTrue($ceiling->answered_at->equalTo($ceiling->ended_at),
            'the interim value IS the ceiling: answered at hangup, wrong by the length of the conversation');

        app(PhoneCallService::class)->handleRecordingReady(
            'ceiling-replaced',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-ceiling.mp3',
            497,
        );

        $stored = $call->fresh();
        $this->assertTrue($stored->answered_at->lessThan($stored->ended_at),
            'once a real duration lands the answer moment must be honest, not a ceiling');
        $this->assertSame(497, (int) $stored->ended_at->diffInSeconds($stored->answered_at, true));
    }

    /**
     * IDEMPOTENT, and it must not disturb a row already correct. Re-delivery of
     * the recording webhook is ordinary (Plivo retries), so the second look runs
     * more than once on the same row.
     */
    public function test_the_second_look_is_idempotent_and_leaves_a_correct_row_alone(): void
    {
        Queue::fake();
        $call = $this->endedCallWithNoDuration('second-look-idempotent');
        // The stored answer moment is deliberately derived from a DIFFERENT
        // number (900s) than the one the recording will deliver (300s). An
        // earlier version of this test seeded both from 300, so an unconditional
        // rewrite produced a byte-identical value and the ceiling guard was not
        // pinned at all - the assertion below could not fail. With the two
        // numbers separated, dropping the guard moves answered_at by 600
        // seconds and this test fails, which is the whole point of it.
        $call->duration = 900;
        $call->answered_at = $call->ended_at->copy()->subSeconds(900);
        $call->status = CallStatus::Completed;
        $call->save();

        $before = $call->fresh()->answered_at;

        $service = app(PhoneCallService::class);
        $url = 'https://media.plivo.com/v1/Account/MA/Recording/rec-idem.mp3';
        $service->handleRecordingReady('second-look-idempotent', $url, 300);
        $service->handleRecordingReady('second-look-idempotent', $url, 300);

        $stored = $call->fresh();
        $this->assertTrue($before->equalTo($stored->answered_at),
            'a row that already holds a true answer moment must not be rewritten');
        $this->assertSame(900, (int) $stored->ended_at->diffInSeconds($stored->answered_at, true),
            'the honest 900s answer moment must survive a 300s recording landing on top of it');
        $this->assertSame(CallStatus::Completed, $stored->status);
    }

    /**
     * The second look must NOT invent an answer. A recording proves audio
     * existed, not that a human picked up — which is exactly the inference
     * handleCallEnded refuses to make, because Plivo's Duration includes
     * voicemail recording time. A missed call with no answer fingerprint at all
     * stays missed.
     */
    public function test_the_second_look_does_not_invent_an_answer_on_a_row_with_no_fingerprint(): void
    {
        Queue::fake();
        $call = $this->endedCallWithNoDuration('no-fingerprint');

        app(PhoneCallService::class)->handleRecordingReady(
            'no-fingerprint',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-no-fingerprint.mp3',
            180,
        );

        $stored = $call->fresh();
        $this->assertNull($stored->answered_at,
            'a duration is not an answer — inventing one here would relabel unanswered calls with audio as conversations');
        $this->assertSame(CallStatus::Missed, $stored->status);
    }

    /**
     * END TO END through the real webhook entry point, in the production order:
     * hangup with no Duration, then the late DialAction=answer, then the
     * recording. This is the sequence that produced the 8 rows.
     *
     * RED at 4474fa88: the stored call ended as Missed with a null answered_at.
     */
    public function test_the_production_webhook_sequence_no_longer_freezes_the_call(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->endpointFor($user, 'sip:tech@phone.plivo.com');

        PhoneCall::create([
            'call_uuid' => 'end-to-end-sequence',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550101',
            'to_number' => '+15555550100',
            'status' => CallStatus::Ringing,
            'started_at' => now()->subMinutes(10),
        ]);

        $controller = app(PlivoWebhookController::class);

        // 1. Hangup, carrying NO Duration and no DialBLegDuration — the trigger.
        $controller->handle(Request::create('/api/plivo/secret/webhook', 'POST', [
            'CallUUID' => 'end-to-end-sequence',
            'DialAction' => 'hangup',
        ]));

        $this->assertSame(CallStatus::Missed,
            PhoneCall::where('call_uuid', 'end-to-end-sequence')->firstOrFail()->status,
            'handleCallEnded is unchanged: with no answered_at it still says Missed at this instant');

        // 2. The late answer webhook (DialAction=answer fires at end-of-dial).
        $controller->handle(Request::create('/api/plivo/secret/webhook', 'POST', [
            'CallUUID' => 'end-to-end-sequence',
            'DialAction' => 'answer',
            'DialBLegTo' => 'sip:tech@phone.plivo.com',
            'DialBLegUUID' => 'b-leg-end-to-end',
        ]));

        // 3. The recording lands with the real duration.
        $controller->handle(Request::create('/api/plivo/secret/webhook', 'POST', [
            'CallUUID' => 'end-to-end-sequence',
            'RecordUrl' => 'https://media.plivo.com/v1/Account/MA/Recording/rec-end-to-end.mp3',
            'RecordingDuration' => 3319,
        ]));

        $stored = PhoneCall::where('call_uuid', 'end-to-end-sequence')->firstOrFail();

        $this->assertSame(CallStatus::Completed, $stored->status,
            'a 3319-second conversation must not be stored as a missed call');
        $this->assertSame($user->id, $stored->answered_by);
        $this->assertSame(3319, (int) $stored->ended_at->diffInSeconds($stored->answered_at, true));
    }
}
