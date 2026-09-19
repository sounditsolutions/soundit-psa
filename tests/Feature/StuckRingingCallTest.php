<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\PhoneCall;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A call that RINGS, records, and never finalises.
 *
 * Measured in production on 2026-09-18: 36 rows at status=ringing with
 * ended_at NULL, oldest 2026-05-05, newest 2026-09-15. Every one of the 36
 * carries BOTH recording_url and recording_duration, and 25 also carry a
 * duration. That combination is the whole diagnosis: the recording webhook
 * arrived, so the call demonstrably ended, while the hangup webhook that
 * would have written ended_at and the final status never did.
 *
 * This is NOT the answered-as-missed redelivery defect (card 6aac6037). That
 * one leaves ringing WITH ended_at set; its count is 0. ringing + answered_at
 * is also 0. This is the opposite shape - nothing finalised the row at all.
 *
 * Why nothing sweeps them: handleRecordingReady() delegates the correcting
 * work to reconcileAnsweredStateWithDuration(), which returns early when
 * ended_at === null. So the one webhook that DID arrive declines to act
 * precisely on the rows that need it. The standing safety net
 * `calls:resolve-recordings` is scoped to status=Completed rows MISSING a
 * recording, which is the complement of this population and can never reach
 * it.
 *
 * What the fix must NOT do, each pinned below:
 *  - it must not invent an answer. A recording proves audio existed, not that
 *    a human picked up - the same inference handleCallEnded() deliberately
 *    refuses to make. A stuck row with no answer fingerprint finalises as
 *    Missed, not Completed.
 *  - it must not overwrite a real ended_at, or re-date a call that already
 *    finalised correctly.
 *  - it must not resurrect or re-stamp a voicemail.
 */
class StuckRingingCallTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A call stuck exactly as the production rows are: ringing, no ended_at,
     * and (for the 25-row majority) a duration already backfilled.
     */
    private function stuckRingingCall(
        string $uuid,
        ?int $duration = null,
        ?string $answeredAt = null,
        string $direction = 'inbound',
    ): PhoneCall {
        // ended_at, answered_at and duration are NOT in PhoneCall::$fillable,
        // so they must be assigned directly. Found the hard way: a create()
        // array carrying them silently DROPPED all three, and several tests in
        // this file first went red for that reason instead of for the defect
        // they name. A fixture that fails for the wrong reason is worse than
        // one that fails loudly, because its red reads as proof.
        $call = PhoneCall::create([
            'call_uuid' => $uuid,
            'direction' => $direction,
            'from_number' => '+15555550111',
            'to_number' => '+15555550222',
            'status' => CallStatus::Ringing,
            'started_at' => now()->subMinutes(10),
        ]);

        $call->ended_at = null;
        $call->duration = $duration;
        $call->answered_at = $answeredAt;
        $call->save();

        return $call;
    }

    /**
     * THE DEFECT. The recording lands on a row that never got a hangup
     * webhook. Before the fix: duration was backfilled, the prepay debit
     * re-ran, and the row stayed at Ringing with ended_at NULL forever.
     *
     * RED at db9e5d12: status stayed Ringing, ended_at stayed null.
     */
    public function test_a_recording_finalises_a_call_the_hangup_webhook_never_closed(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-basic');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-basic',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-stuck.mp3',
            90,
        );

        $stored = $call->fresh();

        $this->assertNotNull($stored->ended_at,
            'the recording proves the call ended; the row must no longer claim it is still ringing');
        $this->assertNotSame(CallStatus::Ringing, $stored->status,
            'a row with a recording is not a ringing call');
    }

    /**
     * The end time must be DERIVED, not stamped at sweep time. These rows can
     * be months old (oldest measured: 2026-05-05), so writing now() would
     * re-date a call to whenever the fix happened to run and silently corrupt
     * every duration-based report that reads the column.
     */
    public function test_the_derived_end_time_is_anchored_to_the_call_not_to_now(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-anchored');
        $startedAt = $call->started_at->copy();

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-anchored',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-anchored.mp3',
            120,
        );

        $stored = $call->fresh();

        $this->assertTrue(
            $stored->ended_at->equalTo($startedAt->copy()->addSeconds(120)),
            'ended_at must be started_at + the recorded length, not the moment the correction ran'
        );
    }

    /**
     * IT MUST NOT INVENT AN ANSWER. This is the same boundary
     * reconcileAnsweredStateWithDuration() already holds: a duration proves
     * audio existed, never that a human picked up. A stuck row carrying no
     * answer fingerprint finalises as Missed.
     */
    public function test_a_stuck_call_with_no_answer_fingerprint_finalises_as_missed(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-no-answer');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-no-answer',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-no-answer.mp3',
            45,
        );

        $stored = $call->fresh();

        $this->assertSame(CallStatus::Missed, $stored->status,
            'a recording is not an answer - status must follow the same rule handleCallEnded uses');
        $this->assertNull($stored->answered_at,
            'no answer fingerprint existed, so none may be manufactured');
    }

    /**
     * ...and the converse: a stuck row that DOES carry an answer fingerprint
     * (handleCallAnswered ran, the hangup never did) finalises as Completed.
     */
    public function test_a_stuck_call_that_was_answered_finalises_as_completed(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall(
            'stuck-ringing-answered',
            null,
            now()->subMinutes(9)->toDateTimeString(),
        );

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-answered',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-answered.mp3',
            300,
        );

        $stored = $call->fresh();

        $this->assertSame(CallStatus::Completed, $stored->status,
            'the row carries a real answer moment, so the finalised call is a conversation');
        $this->assertNotNull($stored->ended_at);
    }

    /**
     * IT MUST NOT TOUCH A ROW THAT ALREADY ENDED. The finalisation is for
     * rows the hangup webhook never closed; a call with a real ended_at is
     * none of its business, and re-deriving one would move a correct
     * timestamp.
     */
    public function test_a_call_that_already_ended_keeps_its_own_end_time(): void
    {
        Queue::fake();
        $call = PhoneCall::create([
            'call_uuid' => 'already-ended',
            'direction' => 'inbound',
            'from_number' => '+15555550111',
            'to_number' => '+15555550222',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(10),
        ]);
        $call->ended_at = now()->subMinutes(5);
        $call->answered_at = now()->subMinutes(9);
        $call->duration = 240;
        $call->save();

        $originalEnd = $call->ended_at->copy();

        app(PhoneCallService::class)->handleRecordingReady(
            'already-ended',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-already.mp3',
            240,
        );

        $stored = $call->fresh();

        $this->assertTrue($stored->ended_at->equalTo($originalEnd),
            'a row that already finalised must not be re-dated by the recording');
        $this->assertSame(CallStatus::Completed, $stored->status);
    }

    /**
     * A voicemail that never got its hangup webhook still ends - the row must
     * stop claiming to be ringing - but it stays a VOICEMAIL. The recording
     * that triggered this IS the voicemail.
     */
    public function test_a_stuck_voicemail_finalises_without_being_resurrected(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-voicemail');
        $call->status = CallStatus::Voicemail;
        $call->save();

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-voicemail',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-vm-stuck.mp3',
            30,
        );

        $stored = $call->fresh();

        $this->assertSame(CallStatus::Voicemail, $stored->status,
            'the recording IS the voicemail; it is not evidence of a conversation');
        $this->assertNotNull($stored->ended_at,
            'but the call did end, and the row must say so');
        $this->assertNull($stored->answered_at,
            'a voicemail has no answer moment');
    }

    /**
     * Running it twice changes nothing. The second delivery finds a row that
     * already has ended_at and leaves it entirely alone.
     */
    public function test_finalisation_is_idempotent_across_redeliveries(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-idempotent');
        $service = app(PhoneCallService::class);
        $url = 'https://media.plivo.com/v1/Account/MA/Recording/rec-idem.mp3';

        $service->handleRecordingReady('stuck-ringing-idempotent', $url, 75);
        $afterFirst = $call->fresh();

        $service->handleRecordingReady('stuck-ringing-idempotent', $url, 75);
        $afterSecond = $call->fresh();

        $this->assertTrue($afterFirst->ended_at->equalTo($afterSecond->ended_at),
            'a redelivered recording must not move an end time this method itself derived');
        $this->assertSame($afterFirst->status, $afterSecond->status);
    }

    /**
     * THE REGRESSION THIS CHANGE ITSELF INTRODUCED, caught by three review
     * seats and fixed at source.
     *
     * The recording callback does not only fire at hangup: the <Record>
     * element carries maxLength="14400", and a Record element posts its
     * callback when the RECORDING stops - at that ceiling as well as at
     * hangup. On a call still connected past four hours the callback is
     * mid-conversation. Before this change that was harmless, because
     * reconcileAnsweredStateWithDuration() returns early on a null ended_at;
     * after it, the finalisation would have stamped ended_at and flipped the
     * status on a LIVE call.
     *
     * THE FIXTURE IS INBOUND, matching the production population: 220 of the
     * 221 never-finalised rows are inbound. An earlier version of this test
     * was re-aimed OUTBOUND on the theory that only outbound calls could hold
     * a recording at the ceiling. That theory was wrong and is withdrawn: the
     * predicate this guard protects carries no direction term at all, and
     * inbound calls do carry recordings - 537 of 666 in production, the
     * longest 9712s - because inbound recording is configured in the Plivo
     * application rather than emitted by this code. Flipping the fixture
     * outbound deleted the only executable inbound coverage of a guard whose
     * real population is inbound.
     */
    public function test_a_maxlength_recording_does_not_finalise_a_still_connected_call(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-maxlength');

        $this->assertSame(CallDirection::Inbound, $call->fresh()->direction,
            'this guard protects the inbound population the sweep actually acts on; '
            .'an outbound fixture would leave that population uncovered');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-maxlength',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-maxlen.mp3',
            14400,
        );

        $stored = $call->fresh();

        $this->assertNull($stored->ended_at,
            'a recording at the maxLength ceiling may have stopped while the call continued, '
            .'so it is not treated as evidence the call ended');
        $this->assertSame(CallStatus::Ringing, $stored->status,
            'a call that may still be connected must not be finalised by its own recording rolling over');
        $this->assertSame(14400, $stored->recording_duration,
            'the recording columns are still written - only the finalisation is withheld');
    }

    /**
     * One second under the ceiling is an ordinary completed recording and
     * still finalises. Without this, the guard above could be satisfied by a
     * method that never finalises anything.
     */
    public function test_a_recording_just_under_the_ceiling_still_finalises(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-under-ceiling');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-under-ceiling',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-under.mp3',
            14399,
        );

        $this->assertNotNull($call->fresh()->ended_at,
            'a recording that stopped short of the ceiling is treated as evidence the call ended; '
            .'without this the guard above could be satisfied by a method that never finalises anything');
    }

    /**
     * The guard reasons about a ceiling the CONTROLLER sets. If the two ever
     * disagree the guard goes silently inert - it would compare against a
     * threshold no recording can reach - so pin the emitted value itself.
     * This is a source assertion on purpose: the number is a contract between
     * two files, and no behavioural test can observe a mismatch.
     *
     * SCOPED TO browserAnswer(). An earlier version of this control read the
     * WHOLE controller file, so it could not tell which method emitted the
     * element and would have been satisfied by a maxLength anywhere in the
     * file. Scoping it to the emitting method is what makes it a contract
     * between the constant and a specific line of XML.
     *
     * This control pins ONE fact and claims nothing beyond it: browserAnswer()
     * emits the only <Record maxLength> element in this application. It does
     * NOT establish which calls can hold a recording at that length. Inbound
     * recording is configured in the Plivo application rather than emitted
     * here - see resolveRecordingAfterEnd() - so the absence of a <Record>
     * element on the inbound path says nothing about inbound recordings.
     */
    public function test_the_recording_ceiling_matches_the_value_browser_answer_emits(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/Api/PlivoWebhookController.php'));

        // Take browserAnswer()'s body only: from its signature to the start of
        // the next method declaration at the same indentation.
        $this->assertSame(
            1,
            preg_match(
                '/\n    public function browserAnswer\(.*?\n(?=    (?:public|private|protected) function )/s',
                $controller,
                $m
            ),
            'browserAnswer() must be locatable in the controller for this contract to be checkable; '
            .'if the method was renamed or removed, re-aim this control rather than deleting it'
        );

        $this->assertMatchesRegularExpression(
            '/<Record[^>]*maxLength="14400"/',
            $m[0],
            'the <Record> maxLength browserAnswer() emits must match RECORDING_MAX_LENGTH_SECONDS; '
            .'if this fails, update both together or the rollover guard stops firing. This control '
            .'pins where the element is emitted; it says nothing about which calls can reach that '
            .'length, because inbound recording is configured outside this codebase'
        );
    }

    /**
     * A recording with no usable duration proves the call ended but gives no
     * length to anchor from. The row must still stop claiming to ring, and
     * the end time falls back to the last moment we can defend - the start.
     */
    public function test_a_recording_without_a_duration_still_finalises_the_row(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-no-duration');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-no-duration',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-nodur.mp3',
            null,
        );

        $stored = $call->fresh();

        $this->assertNotNull($stored->ended_at,
            'the recording still proves the call ended, even with no length');
        $this->assertNotSame(CallStatus::Ringing, $stored->status);
    }

    /**
     * The ceiling guard must be impossible to skip by omission.
     *
     * finaliseCallTheHangupNeverClosed() once defaulted $recordingIsComplete
     * to true. The sole caller passes it, so no behavioural test could fail if
     * the default came back - a future second call site would silently inherit
     * the pre-guard behaviour and finalise a still-connected call. There is no
     * safe default (true skips the guard; false declines everything the method
     * exists to do), so the contract is that the caller MUST state it.
     *
     * This asserts the contract itself rather than a behaviour, because the
     * defect it guards is precisely the absence of a caller to observe.
     *
     * WHAT IT CANNOT SEE, stated rather than fixed: it constrains the
     * SIGNATURE, so it cannot catch a future caller that supplies the flag as a
     * literal true and skips the guard that way. Only a behavioural test of
     * such a caller could, and that caller does not exist yet - which is the
     * same reason this asserts a contract at all.
     *
     * It deliberately does NOT assert the required-parameter COUNT. That would
     * go red on any legitimately added required parameter, under a message
     * claiming the ceiling contract was broken when it was intact. Assert what
     * is meant: this flag exists, is named, and is required.
     */
    public function test_the_ceiling_flag_cannot_be_omitted_by_a_future_caller(): void
    {
        $method = new \ReflectionMethod(PhoneCallService::class, 'finaliseCallTheHangupNeverClosed');
        $parameters = $method->getParameters();

        // Assert the parameter EXISTS before dereferencing it, so the loudest
        // failure - someone removing the flag outright - surfaces as this
        // message rather than an undefined-key warning and a call on null.
        $this->assertGreaterThanOrEqual(2, count($parameters),
            'the ceiling flag is gone from finaliseCallTheHangupNeverClosed() entirely');

        $parameter = $parameters[1];

        $this->assertSame('recordingIsComplete', $parameter->getName(),
            'guarding the wrong parameter - the ceiling flag moved position');

        $this->assertFalse($parameter->isOptional(),
            'finaliseCallTheHangupNeverClosed() must REQUIRE $recordingIsComplete: '
            .'a default lets a future caller skip the ceiling guard and finalise a live call, '
            .'and no behavioural test would catch it because the only caller today passes it');
    }
}
