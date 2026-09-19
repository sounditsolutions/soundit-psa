<?php

namespace App\Services;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketType;
use App\Jobs\ResolveCallerFromPeople;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\SipEndpoint;
use App\Models\Ticket;
use App\Services\Triage\AssetMatcher;
use App\Support\PhoneNumber;
use App\Support\PlivoConfig;
use App\Support\TriageConfig;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PhoneCallService
{
    /**
     * The maxLength ceiling on the <Record> element emitted by
     * PlivoWebhookController. A recording callback reporting a duration AT or
     * ABOVE this stopped because the RECORDING hit its limit, not because the
     * call ended - the call may still be connected. Kept beside the consumer
     * that reasons about it; the emitter is the controller, and the two must
     * agree (a mismatch would make the guard below silently inert, which is
     * why a control pins the value rather than only the behaviour).
     *
     * PUBLIC because there are two consumers, not one: the live guard below,
     * and the FinaliseStuckCalls sweep. The ceiling is a single external fact
     * about the XML the controller emits, so a second copy of the number could
     * drift from this one and re-open the hole the guard closes. The sweep
     * duplicates the RULE deliberately; it does not duplicate the NUMBER.
     */
    public const RECORDING_MAX_LENGTH_SECONDS = 14400;

    /**
     * Log an incoming call from a Plivo webhook.
     * Returns immediately — caller lookup dispatched async.
     */
    public function logIncomingCall(array $data): PhoneCall
    {
        $fromNumber = $data['From'] ?? '';

        $call = PhoneCall::updateOrCreate(
            ['call_uuid' => $data['CallUUID']],
            [
                'direction' => CallDirection::Inbound,
                'from_number' => $fromNumber,
                'to_number' => $data['To'] ?? null,
                'sip_endpoint' => $data['SipEndpoint'] ?? $data['To'] ?? null,
                // 'status' and 'started_at' are held out of this array for the
                // same reason as in logOutboundCall(): updateOrCreate applies
                // these values on the UPDATE branch too, so a second delivery
                // for an existing CallUUID would regress a finished call to
                // Ringing and re-date its start (and with it the prepay debit).
                // Card 6aac6ee770e3c3433477d91f.
                //
                // LATENT rather than live on this path, and the mechanism
                // matters, so state it exactly. The sole caller
                // (PlivoWebhookController) guards this with `if (! $existing)`,
                // so the update branch is normally unreachable from a webhook.
                // It is not unreachable in general, and the reachable
                // interleaving is NOT "both deliveries create" - call_uuid is
                // unique, so a genuine simultaneous INSERT pair ends in a
                // QueryException, not a silent regression. The path that does
                // reach the update branch is this one: two deliveries both pass
                // the caller's existence check, the first completes its INSERT
                // and COMMITS, and the second then runs updateOrCreate, whose
                // OWN lookup now finds the row and takes the update branch.
                // The method is also public and nothing binds future callers to
                // that existence check. Fixed here because the hazard is
                // identical to the outbound one and a guard that lives in a
                // caller's check-then-act is not a guard.
            ]
        );

        // Create branch only — see logOutboundCall() for the full reasoning.
        if ($call->wasRecentlyCreated) {
            $call->status = CallStatus::Ringing;
            $call->started_at = now();
            $call->save();
        }

        // Async: resolve caller from people table without blocking the webhook response
        if ($call->wasRecentlyCreated && $fromNumber) {
            ResolveCallerFromPeople::dispatch($call->id);
        }

        Log::debug('PhoneCallService: call logged', [
            'call_uuid' => $data['CallUUID'],
            'from' => $fromNumber,
            'created' => $call->wasRecentlyCreated,
        ]);

        return $call;
    }

    /**
     * Log an outbound call initiated from a browser endpoint.
     */
    public function logOutboundCall(array $data): PhoneCall
    {
        $toNumber = $data['To'] ?? '';
        $fromSip = $data['From'] ?? '';

        // Resolve which user placed the call via SIP endpoint
        // Plivo may send the SIP URI in various formats:
        //   sip:user@phone.plivo.com, user@phone.plivo.com, or just the username
        $endpoint = SipEndpoint::where('sip_uri', $fromSip)
            ->where('is_active', true)
            ->first();

        if (! $endpoint && $fromSip) {
            $endpoint = SipEndpoint::where('sip_uri', 'like', "%{$fromSip}%")
                ->where('is_active', true)
                ->first();
        }

        $call = PhoneCall::updateOrCreate(
            ['call_uuid' => $data['CallUUID']],
            [
                'direction' => CallDirection::Outbound,
                'from_number' => $toNumber,
                'to_number' => \App\Support\PlivoConfig::get('did_number'),
                'sip_endpoint' => $fromSip,
                // 'status' and 'started_at' are deliberately NOT in this array
                // either, and for exactly the reason the paragraph below gives
                // for 'answered_by': updateOrCreate applies these values on the
                // UPDATE branch too. Plivo re-delivers webhooks, so leaving them
                // here regressed a COMPLETED call back to Ringing and re-dated
                // started_at to the redelivery instant - which also re-dates the
                // prepay debit derived from it. They are applied on the create
                // branch only, below. Card 6aac6ee770e3c3433477d91f.
                //
                // 'answered_by' is deliberately NOT in this array. It is stored
                // below instead, because updateOrCreate applies these values on
                // the UPDATE branch as well as the create branch: Plivo
                // re-delivers webhooks, and an unresolved endpoint on a later
                // delivery would write null over an attribution that the first
                // delivery (or handleCallAnswered, from DialBLegTo) had already
                // resolved. Mass assignment was the original bug - 'answered_by'
                // was missing from PhoneCall::$fillable and Laravel discarded it
                // without a word. The key IS fillable now, so re-adding it to
                // this array would NOT be inert: it would immediately restore
                // that destructive write. Its absence from this array is the
                // only thing preventing it.
            ]
        );

        // Create branch only. A first delivery establishes the ringing state and
        // the call's start; every later delivery for the same CallUUID leaves
        // both alone, so a redelivered webhook can no longer regress THESE TWO
        // columns. Stated that narrowly on purpose: direction, from_number,
        // to_number and sip_endpoint are still in the values array above and are
        // still rewritten on every redelivery, so this is not a claim that a
        // redelivery cannot touch a finished call at all. sip_endpoint in
        // particular is also written by handleCallAnswered() from a different
        // payload field. Those four are pre-existing and out of scope here;
        // tracked as their own issue.
        //
        // On the column defaults: status has a DB default of 'ringing', so that
        // assignment is belt-and-braces; started_at is nullable with no default,
        // so THAT write is the one actually doing the work on create.
        if ($call->wasRecentlyCreated) {
            $call->status = CallStatus::Ringing;
            $call->started_at = now();
            $call->save();
        }

        // Write the attribution when THIS delivery resolved an endpoint THAT
        // CARRIES A user_id, and otherwise leave what is already there. Note the
        // condition is on user_id, not on the endpoint: sip_endpoints.user_id is
        // nullable, so a resolved-but-unassigned endpoint is an expected state
        // and is treated the same as no endpoint at all. A delivery that cannot
        // produce a user therefore never clears an established attribution -
        // that is the defect this guard exists for.
        //
        // It is NOT monotonic: a delivery that produces a DIFFERENT non-null
        // user replaces the stored one, so among outbound deliveries this is
        // last-non-null-writer-wins.
        //
        // Against handleCallAnswered(): that writer guards 'if (!
        // $call->answered_by)' and declines when a value exists, while this one
        // overwrites a differing one. So WHEN THE TWO RUN SERIALLY the outcome
        // is a fixed precedence rather than an ordering effect - whenever this
        // delivery produces a user, ITS user is what remains, whichever ran
        // first - and the column therefore prefers the PLACING user over the
        // ANSWERING one, the opposite of what the column's name says.
        //
        // Concurrently it is ALSO a race, and the two problems are separate.
        // handleCallAnswered() runs inside updateCallSafely()'s transaction with
        // lockForUpdate(); this write does not, and it is an unlocked
        // read-compare-save over a model hydrated before the comparison. So a
        // lost update is possible here regardless of the precedence question.
        // The locking gap is issue #2168. WHICH WRITER SHOULD WIN is the
        // separate open product question, issue #2166 - do not close that one by
        // adding a lock, and do not read this comment as a ruling on either.
        if ($endpoint?->user_id !== null && $call->answered_by !== $endpoint->user_id) {
            $call->answered_by = $endpoint->user_id;
            $call->save();
        }

        if ($call->wasRecentlyCreated && $toNumber) {
            ResolveCallerFromPeople::dispatch($call->id);
        }

        Log::debug('PhoneCallService: outbound call logged', [
            'call_uuid' => $data['CallUUID'],
            'from_sip' => $fromSip,
            'to' => $toNumber,
            'resolved_user' => $endpoint?->user_id,
        ]);

        return $call;
    }

    /**
     * Handle a call being answered — update status and resolve who answered.
     */
    public function handleCallAnswered(string $callUuid, array $data): ?PhoneCall
    {
        return $this->updateCallSafely($callUuid, function (PhoneCall $call) use ($data) {
            $callEnded = $call->ended_at !== null;

            // Resolve which user answered via SIP endpoint (inbound calls only).
            // Outbound calls already have answered_by set from logOutboundCall().
            // Plivo sends the answering endpoint as DialBLegTo (e.g. sip:user@phone.plivo.com)
            //
            // This block runs before the status/answered_at decision below, and
            // the ordering is BEHAVIOURALLY INERT - say that first, because two
            // earlier versions of this comment led with a load-bearing-sounding
            // justification and then walked it back. answerIsObserved() reads
            // the payload only, so nothing below consumes what this produces,
            // and either order writes the same columns in the same single
            // save(). It sits here because attribution reads as logically prior
            // to the answer question, which is a readability preference and
            // nothing more.
            //
            // Note it WRITES $call->sip_endpoint as well as reading $data and
            // $call->answered_by. Its guard (decline when a value already
            // exists) is untouched. The precedence question it belongs to is
            // issue #2166 and the locking gap is #2168; neither is answered here.
            if (! $call->answered_by) {
                $sipUri = $data['DialBLegTo'] ?? $data['SipEndpoint'] ?? $data['To'] ?? null;
                if ($sipUri) {
                    $call->sip_endpoint = $sipUri;
                    $endpoint = SipEndpoint::where('sip_uri', $sipUri)->where('is_active', true)->first();
                    if ($endpoint?->user_id) {
                        $call->answered_by = $endpoint->user_id;
                    }
                }
            }

            // For active calls, mark as in-progress and stamp answered_at.
            // For already-ended calls, this is a late "answer" webhook (Plivo's
            // DialAction=answer fires at end-of-dial, not at answer, so it
            // routinely arrives AFTER the hangup webhook). Derive answered_at
            // from duration so it reflects the real moment the call was picked
            // up, not when this late webhook arrived.
            if (! $callEnded) {
                $call->status = CallStatus::InProgress;
                $call->answered_at = now();
            } elseif ($call->duration && $call->duration > 0) {
                $call->answered_at = $call->ended_at->copy()->subSeconds($call->duration);
                if ($call->status !== CallStatus::Voicemail) {
                    $call->status = CallStatus::Completed;
                }
            } elseif ($this->answerIsObserved($data)) {
                // Late answer with NO usable duration. Before this branch existed
                // both arms above were skipped, answered_at was never stamped, and
                // handleCallEnded's answered_at-only status decision had already
                // frozen a real conversation as Missed - with answered_by sitting
                // right there on the row contradicting it. Card 6aac6037.
                //
                // ended_at is a CEILING, not the true answer moment: it says the
                // call was picked up at hangup, which is wrong by the length of the
                // conversation. It is used because nothing in this repo computes
                // talk time or billing from answered_at (measured at 4474fa88 -
                // duration/billing all run through effectiveDurationSeconds(),
                // which reads duration and recording_duration, never this column),
                // so the ceiling cannot skew a report; and handleRecordingReady()
                // replaces it with the honest value the moment a real duration
                // lands. A visibly-wrong status was the defect; an approximate
                // answer moment is the smaller wrong. It is USUALLY transient -
                // see answeredAtIsCeiling() for the measured cases where it is
                // not.
                //
                // Be precise about what answered_at IS, because an earlier
                // version of this comment called it display-only and that is
                // false: besides the detail view, handleCallEnded() decides
                // status from it and the controller's voicemail auto-detect keys
                // on it being null. What is true is narrower - no reader
                // computes talk time or billing from it (that is
                // effectiveDurationSeconds(), which never reads this column), so
                // its VALUE cannot skew a report. Its NULLNESS, by contrast, is
                // load-bearing for both of those readers, which is exactly why
                // stamping it at all has to be right.
                $call->answered_at = $call->ended_at->copy();
                if ($call->status !== CallStatus::Voicemail) {
                    $call->status = CallStatus::Completed;
                }
            }

            $call->save();

            return $call;
        });
    }

    /**
     * Did THIS payload observe a genuine answer — a B leg that actually
     * connected — independently of whether a duration is known yet?
     *
     * Vendor shape read at source, not guessed (STANDARDS C-56). Plivo's Dial
     * element posts these to its callbackUrl (plivo.com/docs/voice/xml/dial):
     * DialAction (answer|connected|hangup|digits), DialBLegStatus, DialALegUUID,
     * DialBLegUUID, DialBLegDuration and DialBLegBillDuration ("on hangup"),
     * DialBLegFrom, DialBLegTo, DialBLegHangupCauseName/Code/Source. The
     * action URL carries a different set - DialStatus, DialRingStatus,
     * DialHangupCause, DialALegUUID, DialBLegUUID - and of DialBLegUUID the
     * dial-status-reporting page says exactly: "CallUUID of the B leg. Empty if
     * nobody answers." That sentence is why a non-empty B-leg UUID is the
     * primary signal here.
     *
     * Note what is deliberately NOT accepted as evidence:
     *  - The CALL ROW itself, and answered_by in particular. On OUTBOUND calls
     *    logOutboundCall() sets answered_by from the PLACING user's SIP endpoint
     *    before any answer event exists, so it is present on outbound calls that
     *    were never picked up; reading it as an answer would stamp answered_at
     *    on a dead outbound dial. This method therefore takes ONLY the payload
     *    and holds no PhoneCall at all - the row is deliberately out of reach
     *    rather than merely unread, so the mistake cannot be made here later.
     *  - DialBLegTo on its own. It names the destination that was ATTEMPTED and
     *    is present on a dial nobody picked up, which is precisely a missed
     *    inbound call ringing a tech's SIP endpoint. Accepting it would convert
     *    every genuinely missed call into a completed one.
     *  - The top-level CallStatus. It describes the A LEG, and every arm here is
     *    deliberately B-leg scoped. On an inbound call Plivo has ALREADY answered
     *    the A leg - that is how this Dial/Record XML is executing at all - so
     *    CallStatus is 'in-progress' for the entire time a tech's endpoint is
     *    merely ringing, and stays so on a call that then rings out to voicemail.
     *    Plivo retries callbacks and PlivoWebhookController routes
     *    CallStatus=in-progress straight to handleCallAnswered, so such a
     *    delivery lands on an already-ended, duration-less row; reading it as
     *    answer evidence would flip a genuinely missed call to Completed and
     *    suppress the voicemail auto-detect. A live answer does not need it: that
     *    path is the (! $callEnded) arm above, which never consults this
     *    predicate.
     *
     * Casing is taken from the vendor verbatim (Plivo is PascalCase here); no
     * case-insensitive lookup is done, because a key we cannot name exactly is a
     * key we have not read.
     */
    private function answerIsObserved(array $data): bool
    {
        // "Empty if nobody answers" — the vendor's own words.
        if (! empty($data['DialBLegUUID'])) {
            return true;
        }

        // A B leg that ran for a positive number of seconds was connected.
        if (isset($data['DialBLegDuration']) && (int) $data['DialBLegDuration'] > 0) {
            return true;
        }

        // B-leg status, when Plivo states it. The docs enumerate no value list
        // for DialBLegStatus, so this matches only the affirmative words and
        // treats every other value - including one we have never seen - as NOT
        // an answer. Failing closed here costs a status correction; failing open
        // would mislabel a missed call as answered.
        $blegStatus = is_string($data['DialBLegStatus'] ?? null) ? strtolower($data['DialBLegStatus']) : null;
        if (in_array($blegStatus, ['answer', 'answered', 'in-progress', 'connected'], true)) {
            return true;
        }

        // DialAction=connected is an explicit bridge event.
        return ($data['DialAction'] ?? null) === 'connected';
    }

    /**
     * Is this row's answered_at the CEILING value written by the late-answer
     * branch of handleCallAnswered() - answered_at === ended_at, "answered at
     * hangup" - rather than a real answer moment?
     *
     * Equality is the fingerprint: the duration-derived value is strictly
     * earlier than ended_at whenever duration > 0, and the live-answer value is
     * stamped while ended_at is still null and so is normally earlier too.
     *
     * WAYS THAT ARGUMENT IS WEAKER THAN IT LOOKS, all measured, recorded as
     * bounded limits rather than papered over. What they cost is the ACCURACY of
     * answered_at once it is non-null, not a status: every status path keys on
     * whether the column is null, and none of these cases returns it to null.
     * (Do not read that as "display-only" - handleCallEnded() and the voicemail
     * auto-detect both read this column. The value is what is approximate; the
     * nullness is what is load-bearing.) That is why these are documented and
     * filed rather than fixed by a redesign on this branch.
     *
     *  0. THE SIMPLEST CASE, and the most common one: NO RECORDING EVER
     *     ARRIVES. The second look runs from handleRecordingReady(), so on a
     *     call with no recording callback nothing ever replaces the ceiling -
     *     no re-stamp or truncation required. The repo already documents that
     *     inbound recording callbacks "often don't reach our webhook", and the
     *     compensating API path (resolveRecordingFromPlivo) writes
     *     recording_duration WITHOUT invoking the second look, and is itself
     *     gated behind duration >= 1 - which is exactly the duration-null
     *     population this branch is about. So for a meaningful share of the very
     *     rows this fix targets, the ceiling is the permanent value. The status
     *     is still corrected, which is the defect being fixed; the answer moment
     *     stays approximate.
     *  1. THE CEILING IS NOT RELIABLY TRANSIENT. handleCallEnded() re-stamps
     *     ended_at on EVERY delivery, and the controller routes two distinct
     *     callbacks to it (DialAction=hangup and CallStatus=completed). On a
     *     call that receives both, ended_at moves after the ceiling was written,
     *     equality no longer holds, and the second look never replaces it. The
     *     row is still Completed and still correct in status; its answered_at
     *     just stays at the ceiling. A durable marker column is the right
     *     long-term shape.
     *  2. SECOND-RESOLUTION STORAGE. answered_at and ended_at are timestamp
     *     columns with second precision, so a call answered and hung up inside
     *     the SAME second stores a live answered_at exactly equal to ended_at
     *     and is indistinguishable from the ceiling. The second look then
     *     rewrites a row that was already correct, moving answered_at earlier by
     *     the recording length. Bounded to sub-second calls.
     */
    private function answeredAtIsCeiling(PhoneCall $call): bool
    {
        return $call->answered_at !== null
            && $call->ended_at !== null
            && $call->answered_at->equalTo($call->ended_at);
    }

    /**
     * Handle a call ending — set duration and final status.
     */
    public function handleCallEnded(string $callUuid, array $data): ?PhoneCall
    {
        return $this->updateCallSafely($callUuid, function (PhoneCall $call) use ($data) {
            $call->ended_at = now();
            $call->duration = isset($data['Duration']) ? (int) $data['Duration'] : null;

            // Determine final status — preserve voicemail if already detected.
            // Don't infer "answered" from non-zero duration: Plivo's Duration
            // includes voicemail recording time, so a missed-call-with-voicemail
            // also has duration > 0. Use answered_at as the only signal —
            // handleCallAnswered sets it only for genuine answer events.
            if ($call->status === CallStatus::Voicemail) {
                // Already recorded as voicemail — preserve
            } elseif ($call->answered_at === null) {
                $call->status = CallStatus::Missed;
            } else {
                $call->status = CallStatus::Completed;
            }

            $call->save();

            // Re-run the debit with whatever duration is available. The
            // service uses effectiveDurationSeconds() which falls back to
            // recording_duration when Plivo omits Duration from the hangup
            // payload. Idempotent — updates existing txn if one exists.
            if ($call->ticket_id && $call->is_billable) {
                app(PrepayService::class)->debitFromPhoneCall($call);
            }

            return $call;
        });
    }

    /**
     * Mark a call as voicemail (auto-detected from recording on unanswered call).
     */
    public function markAsVoicemail(string $callUuid): ?PhoneCall
    {
        return $this->updateCallSafely($callUuid, function (PhoneCall $call) {
            $call->status = CallStatus::Voicemail;
            $call->save();

            return $call;
        });
    }

    /**
     * Handle recording becoming available.
     */
    public function handleRecordingReady(string $callUuid, string $url, ?int $duration): ?PhoneCall
    {
        $call = $this->updateCallSafely($callUuid, function (PhoneCall $call) use ($url, $duration) {
            $call->recording_url = $url;
            $call->recording_duration = $duration;

            // Plivo occasionally omits Duration from the hangup webhook. When
            // the recording webhook arrives afterward, use its duration as the
            // call duration so UI, reports, and exports all render correctly.
            if (! $call->duration && $duration && $duration > 0) {
                $call->duration = $duration;
            }

            // A recording that reached its maxLength ceiling is evidence the
            // RECORDING stopped, not that the CALL did - see the guard in
            // finaliseCallTheHangupNeverClosed(). Anything shorter stopped
            // because the call did.
            $recordingIsComplete = $duration === null || $duration < self::RECORDING_MAX_LENGTH_SECONDS;

            $this->finaliseCallTheHangupNeverClosed($call, $recordingIsComplete);
            $this->reconcileAnsweredStateWithDuration($call);

            $call->save();

            return $call;
        });

        // Download to local storage (non-blocking — failure doesn't affect the webhook response)
        if ($call) {
            $this->downloadRecording($call);

            // When Plivo's hangup webhook arrives without Duration, the debit
            // can't be calculated at call-end time. Re-run once the recording
            // duration lands — effectiveDurationSeconds() will use it as the
            // fallback. Idempotent.
            if ($call->ticket_id && $call->is_billable) {
                app(PrepayService::class)->debitFromPhoneCall($call);
            }
        }

        return $call;
    }

    /**
     * THE CALL THAT NEVER ENDED. A recording has arrived for a row that still
     * claims to be ringing, because the hangup webhook that would have written
     * ended_at and the final status never reached us.
     *
     * Measured in production 2026-09-18: 36 such rows, oldest 2026-05-05,
     * newest 2026-09-15, all inbound. Every one carries BOTH recording_url and
     * recording_duration - which is the evidence this method rests on. The
     * recording callback is the only evidence these rows carry that the call
     * is over, and on its own it is not conclusive: a recording also stops
     * when it hits the maxLength ceiling, which is why $recordingIsComplete is
     * threaded through below.
     *
     * WHAT THAT FLAG DOES NOT COVER. It discriminates on a REPORTED length, so
     * it only declines when the vendor tells us the recording is long. It is
     * computed as ($duration === null || $duration < CEILING), so a callback
     * carrying NO usable length reads as complete and finalises. That is a
     * deliberate choice - the recording callback is itself evidence the call
     * ended - but it means the ceiling guard protects against a long recording,
     * NOT against an unmeasured one. The controller widens that case:
     * $request->integer() turns an ABSENT RecordingDuration into 0, so a
     * rollover callback that omits the field is indistinguishable here from a
     * zero-length recording. Do not read this guard as covering it.
     *
     * ON WHAT IS ACTUALLY PINNED, stated narrowly because an earlier version of
     * this docblock overclaimed it: test_a_recording_without_a_duration_still_
     * finalises_the_row covers the NULL arm by calling this service directly
     * with null. That is a real contract and it is why the null branch must not
     * be flipped. It is NOT coverage of production, because the sole production
     * caller passes $request->integer(), which cannot return null - so the
     * branch that test pins is one no delivery reaches. The value production
     * actually emits for an absent field is 0, and no test passes 0 here.
     *
     * Why the second look below could not do this job: it returns early when
     * ended_at === null, so the one webhook that DID arrive declined to act on
     * exactly the rows that needed it. This runs FIRST and supplies the
     * ended_at the second look then reads, which is why the ordering in
     * handleRecordingReady() is load-bearing rather than cosmetic.
     *
     * What it deliberately does NOT do:
     *  1. It does not invent an answer. A recording proves audio existed, not
     *     that a human picked up - the inference handleCallEnded() refuses to
     *     make, refused identically here. A row with no answered_at finalises
     *     as Missed; only a row that already carries an answer fingerprint
     *     finalises as Completed. status is decided by the same rule
     *     handleCallEnded() uses, deliberately duplicated rather than shared,
     *     because the two paths hold the rule for different reasons and a
     *     future change to one should not silently move the other.
     *  2. It does not touch a row that already ended. The guard is on
     *     ended_at, not on status, so a Completed row whose recording arrives
     *     late is left entirely alone and a redelivery is a no-op.
     *  3. It does not resurrect a voicemail. Status is only ever written for a
     *     row that is not already Voicemail; a stuck voicemail gets its
     *     ended_at and keeps its status.
     *  4. It does not stamp now(). These rows can be months old, so now()
     *     would re-date a spring call to whenever this shipped and corrupt
     *     every report reading the column. ended_at is DERIVED as started_at
     *     plus the recorded length.
     *
     * The derivation is approximate and that is stated rather than hidden: it
     * assumes the recording covers the call, so on a call that rang for a
     * while before recording began the end time is early by the ring time. It
     * is bounded by two real values (never before started_at, never later than
     * now) and is a far smaller wrong than a row that claims to still be
     * ringing four months on. With no usable duration at all the end time
     * falls back to started_at - the last moment the row itself can defend.
     *
     * ONE CASE THIS MUST NOT TOUCH, and the reason the $recordingIsComplete
     * argument exists. The recording callback does not only fire at hangup:
     * PlivoWebhookController emits <Record ... maxLength="14400" />, and a
     * Record element posts its callback when the RECORDING stops - at
     * maxLength as well as at hangup. On a call still connected past four
     * hours that callback is mid-conversation, and without this guard the
     * finalisation below would stamp ended_at and flip the status on a live
     * call. That is a regression THIS METHOD INTRODUCED: before it existed
     * the mid-call callback wrote the recording columns and nothing else,
     * because reconcileAnsweredStateWithDuration() returns early on a null
     * ended_at.
     *
     * DECLINING IS NOT THE WHOLE FIX, because the caller has already written
     * recording_url, recording_duration and (on a duration-less row) duration
     * by the time this returns. The row left behind - null ended_at, full end
     * evidence, hours old - is exactly the shape FinaliseStuckCalls sweeps, so
     * that command applies this same ceiling test to its population and counts
     * what it declines. Both halves read RECORDING_MAX_LENGTH_SECONDS above;
     * neither restates the number.
     *
     * Raised by three independent review seats against the first version of
     * this change, whose docblock asserted the opposite - that the recording
     * columns are 'never written while a call is connected'. Verified at
     * source before acting: the maxLength attribute is real, the controller
     * routes any RecordingDuration >= 0 here, and nothing downstream
     * distinguished the two callbacks.
     *
     * Not currently reachable in the measured data - the longest call on
     * record is 9720s against a 14400s ceiling - which is why it is a guard
     * and a control rather than an incident.
     *
     * Mutates the model only; the caller saves.
     *
     * $recordingIsComplete is REQUIRED, deliberately. It used to default to
     * true, which meant a caller that simply forgot the argument got the
     * pre-guard behaviour - the ceiling test skipped, a still-connected call
     * finalised - and no test would have failed, because the one existing
     * caller passes it. Protection that is opt-in is protection the next
     * call site silently loses. There is no safe default here: true skips the
     * guard, and false would decline every finalisation this method exists to
     * perform. The caller must say which it knows.
     */
    private function finaliseCallTheHangupNeverClosed(PhoneCall $call, bool $recordingIsComplete): void
    {
        if ($call->ended_at !== null) {
            return;
        }

        if (! $recordingIsComplete) {
            return;
        }

        $seconds = $call->effectiveDurationSeconds();
        $startedAt = $call->started_at ?? $call->created_at;

        if ($startedAt === null) {
            return;
        }

        $endedAt = $seconds && $seconds > 0
            ? $startedAt->copy()->addSeconds($seconds)
            : $startedAt->copy();

        // Never claim a call ended in the future: a recording_duration longer
        // than the time since the call started would otherwise date the hangup
        // ahead of now.
        $call->ended_at = $endedAt->isFuture() ? now() : $endedAt;

        if ($call->status === CallStatus::Voicemail) {
            return;
        }

        $call->status = $call->answered_at === null
            ? CallStatus::Missed
            : CallStatus::Completed;

        Log::info('[PhoneCall] Finalised a call the hangup webhook never closed', [
            'call_id' => $call->id,
            'derived_ended_at' => $call->ended_at->toDateTimeString(),
            'duration_seconds' => $seconds,
        ]);
    }

    /**
     * The SECOND LOOK. A duration has just landed from the recording; re-derive
     * answered_at (and, where it follows, status) from it instead of leaving the
     * row frozen at whatever the webhooks could conclude without it.
     *
     * The defect class this closes is a late-arriving fact that nothing
     * re-evaluates: handleCallEnded() decides status from answered_at ALONE
     * (deliberately - Plivo's Duration includes voicemail recording time), so a
     * call whose duration was unknown at hangup was stamped Missed and never
     * looked at again, even when the recording later proved a 55-minute
     * conversation. Mutates the model only; the caller saves.
     *
     * Three things it must not do, each guarded:
     *  1. It must not resurrect a voicemail. A genuine voicemail carries status
     *     Voicemail, and that status is never touched here - the recording that
     *     triggers this call is, for a voicemail, the voicemail itself.
     *  2. It must not invent an answer. A row with no answered_at at all is left
     *     alone: a duration proves audio existed, not that a human picked up,
     *     which is the exact inference handleCallEnded refuses to make.
     *  3. It must not disturb a row already correct. A real answered_at (live or
     *     duration-derived) is strictly earlier than ended_at and is left
     *     untouched; only the ceiling value is replaced. Running it twice is a
     *     no-op, because after the first run answered_at is no longer equal to
     *     ended_at.
     */
    private function reconcileAnsweredStateWithDuration(PhoneCall $call): void
    {
        if ($call->status === CallStatus::Voicemail) {
            return;
        }

        $seconds = $call->effectiveDurationSeconds();
        if (! $seconds || $seconds <= 0 || $call->ended_at === null) {
            return;
        }

        // Replace the ceiling with the honest answer moment. This is the primary
        // path for an accurate answered_at: handleCallAnswered's ceiling exists
        // only to carry the row until this runs.
        if ($this->answeredAtIsCeiling($call)) {
            $call->answered_at = $call->ended_at->copy()->subSeconds($seconds);
        }

        // Correct a status frozen as Missed on a row that DOES carry an answer
        // fingerprint. answered_at is the fingerprint, exactly as handleCallEnded
        // defines it - a row with none stays Missed, because a recording alone
        // does not distinguish a conversation from an unanswered call with audio.
        if ($call->answered_at !== null && $call->status === CallStatus::Missed) {
            $call->status = CallStatus::Completed;

            // Logged inside the caller's transaction and before its save(), so
            // this line records an INTENT, not a committed fact: a rollback
            // afterwards would leave the log claiming a correction that never
            // persisted. Accepted deliberately rather than moved - the caller
            // (handleRecordingReady) is where the save and the commit live, and
            // threading a post-commit hook through it for one info line would
            // cost more than the ambiguity is worth. Read it as "decided to
            // correct", and trust the row over the log.
            Log::info('[PhoneCall] Status correction decided on late duration', [
                'call_id' => $call->id,
                'duration_seconds' => $seconds,
            ]);
        }
    }

    /**
     * Resolve the actual recording URL from the Plivo API by call UUID.
     *
     * Plivo's initial recording callback (duration=-1) provides a temporary
     * recording ID. For unanswered calls (voicemails), the final recording
     * gets a different ID. This method queries the Plivo API to find the
     * correct, permanent recording URL.
     */
    public function resolveRecordingFromPlivo(PhoneCall $call): void
    {
        $authId = \App\Support\PlivoConfig::get('auth_id');
        $authToken = \App\Support\PlivoConfig::get('auth_token');
        if (! $authId || ! $authToken) {
            return;
        }

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 15]);
            $response = $client->get(
                "https://api.plivo.com/v1/Account/{$authId}/Recording/",
                [
                    'auth' => [$authId, $authToken],
                    'query' => ['call_uuid' => $call->call_uuid, 'limit' => 5],
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);
            $recordings = $data['objects'] ?? [];

            // Pick the longest recording for this call (the actual voicemail, not the stub)
            $best = null;
            foreach ($recordings as $rec) {
                if (! $best || ($rec['recording_duration_ms'] ?? 0) > ($best['recording_duration_ms'] ?? 0)) {
                    $best = $rec;
                }
            }

            if ($best && ! empty($best['recording_url'])) {
                $durationSec = (int) round(($best['recording_duration_ms'] ?? 0) / 1000);
                $call->recording_url = $best['recording_url'];
                $call->recording_duration = $durationSec > 0 ? $durationSec : null;
                $call->save();

                Log::info('[PhoneCall] Resolved recording from Plivo API', [
                    'call_id' => $call->id,
                    'recording_id' => $best['recording_id'],
                    'duration_ms' => $best['recording_duration_ms'],
                ]);

                $this->downloadRecording($call);
            }
        } catch (\Throwable $e) {
            Log::warning('[PhoneCall] Failed to resolve recording from Plivo API', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Download a call recording from Plivo CDN and store it locally.
     */
    public function downloadRecording(PhoneCall $call): bool
    {
        if (! $call->recording_url || $call->recording_disk_path) {
            return false;
        }

        $authId = PlivoConfig::get('auth_id');
        $authToken = PlivoConfig::get('auth_token');

        $options = ['timeout' => 300];
        if ($authId && $authToken && str_contains($call->recording_url, 'media.plivo.com')) {
            $options['auth'] = [$authId, $authToken];
        }

        try {
            $client = new GuzzleClient($options);
            $response = $client->get($call->recording_url);
            $content = $response->getBody()->getContents();

            if (strlen($content) === 0) {
                Log::warning('[PhoneCall] Downloaded recording is empty', ['call_id' => $call->id]);

                return false;
            }

            $path = "call-recordings/{$call->id}.mp3";
            Storage::disk('local')->put($path, $content);

            $call->recording_disk_path = $path;
            $call->save();

            Log::info('[PhoneCall] Recording stored locally', [
                'call_id' => $call->id,
                'path' => $path,
                'size' => strlen($content),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[PhoneCall] Failed to download recording', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Look up a Person by caller phone number. Used by both the async
     * ResolveCallerFromPeople job (post-call) and the synchronous
     * Plivo IVR resolve-caller endpoint (during the call, for routing).
     *
     * Returns null if the number can't be normalized or no match exists.
     * When multiple people share a number, picks the highest-scoring
     * candidate (primary > active > recent ticket activity).
     */
    public function findPersonByPhoneNumber(?string $rawNumber): ?Person
    {
        $normalized = PhoneNumber::normalize($rawNumber);
        if (! $normalized) {
            return null;
        }

        $candidates = Person::query()
            ->where(function ($q) use ($normalized) {
                $q->where('phone', $normalized)->orWhere('mobile', $normalized);
            })
            ->with('client')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        return $this->pickBestPersonCandidate($candidates);
    }

    /**
     * Score: primary contact (+100), active (+50), recent ticket (+0–30).
     */
    private function pickBestPersonCandidate(Collection $candidates): Person
    {
        return $candidates->sortByDesc(function (Person $person) {
            $score = 0;
            if ($person->is_primary) {
                $score += 100;
            }
            if ($person->is_active) {
                $score += 50;
            }
            $latest = $person->tickets()->orderByDesc('opened_at')->value('opened_at');
            if ($latest) {
                // Sign-safe (psa-lqlu): recency should DECREASE with age. now()->diffInDays($past)
                // is NEGATIVE in Carbon 3, which made the score grow with age (backwards).
                $score += max(0, 30 - (int) $latest->diffInDays(now()));
            }

            return $score;
        })->first();
    }

    /**
     * Link an existing call to a PSA ticket.
     * Sets billability from triage if classification exists, otherwise leaves
     * it null — the triage pipeline will set it after classification runs.
     */
    public function linkCallToTicket(PhoneCall $call, int $ticketId): PhoneCall
    {
        $call->ticket_id = $ticketId;

        // Only set billability if triage has already classified this ticket
        if ($call->is_billable === null) {
            $ticket = Ticket::with('latestTriageRun')->find($ticketId);
            if ($ticket?->latestTriageRun?->stageResult('classification')) {
                $call->is_billable = app(TicketService::class)->defaultBillable($ticket);
            }
        }

        $call->save();

        // Trigger prepay debit only if billability is determined
        if ($call->is_billable && $call->duration) {
            app(PrepayService::class)->debitFromPhoneCall($call);
        }

        return $call;
    }

    /**
     * Link a call to an existing ticket AND drop an internal phone-call note.
     *
     * Wraps linkCallToTicket (keeps the billability + prepay behaviour) and then
     * records a best-effort private note authored by the AI/system user. The note
     * step is fail-soft: a missing system user, a missing ticket, or any note
     * failure must NEVER undo the link — the link is the durable outcome.
     */
    public function linkCallToTicketWithNote(PhoneCall $call, int $ticketId, string $noteBody): PhoneCall
    {
        $this->linkCallToTicket($call, $ticketId);

        // Best-effort note — wrapped so a note failure can't unwind the (already
        // committed) link. We only author when a system user resolves.
        try {
            $authorId = TriageConfig::systemUserId();
            $ticket = $authorId !== null ? Ticket::find($ticketId) : null;

            if ($authorId !== null && $ticket !== null) {
                app(TicketService::class)->addNote(
                    $ticket,
                    $noteBody,
                    NoteType::PhoneCall,
                    isPrivate: true,
                    authorUserId: $authorId,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[PhoneCall] Failed to add call note after link', [
                'call_id' => $call->id,
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }

        return $call;
    }

    /**
     * Create a new ticket from a resolved call and link the call to it.
     *
     * PRECONDITION: the call is already resolved ($call->client_id !== null) — the
     * pipeline applies caller resolution before calling this. Mirrors
     * EmailService::autoCreateTicketFromEmail: builds the ticket data and delegates
     * to the canonical TicketService::createTicket with a null createdBy so the
     * TicketObserver runs triage exactly as it does for an auto-created email.
     */
    public function createTicketFromCall(PhoneCall $call): Ticket
    {
        $caller = $call->caller_identified_name
            ?: $call->person?->fullName
            ?: $call->client?->name
            ?: $call->from_number;

        $subject = Str::limit('Phone call from '.$caller, 250);

        $ticketData = [
            'subject' => $subject,
            'client_id' => $call->client_id,
            'contact_id' => $call->person_id,
            'priority' => TicketPriority::P3->value,
            'source' => TicketSource::Phone->value,
            'type' => TicketType::ServiceRequest->value,
            'description' => $call->call_summary
                ?: $call->cleaned_transcript
                ?: 'Inbound phone call — see linked call for transcript.',
        ];

        $ticket = app(TicketService::class)->createTicket($ticketData, null);

        $this->linkCallToTicketWithNote($call, $ticket->id, "Ticket created from phone call #{$call->id}.");

        // psa-vggw: link the caller's device(s) onto the ticket at creation so it
        // carries real asset context from the start (held-first, fail-soft). Reuses
        // the triage Stage-2c matcher against the resolved person/client's fleet plus
        // any hostname named in the summary; the later async triage run then simply
        // skips an already-linked ticket.
        AssetMatcher::matchAtIntake($ticket);

        return $ticket;
    }

    /**
     * Unlink a call from its ticket, reversing any prepay debit.
     */
    public function unlinkCallFromTicket(PhoneCall $call): PhoneCall
    {
        app(PrepayService::class)->reverseDebitForPhoneCall($call);

        $call->ticket_id = null;
        $call->is_billable = null;
        $call->save();

        return $call;
    }

    /**
     * Toggle billability on a phone call and update prepay accordingly.
     */
    public function setBillable(PhoneCall $call, bool $billable): PhoneCall
    {
        $call->is_billable = $billable;
        $call->save();

        // Re-evaluate prepay: debitFromPhoneCall handles both create and reverse
        app(PrepayService::class)->debitFromPhoneCall($call);

        return $call;
    }

    /**
     * Mark a missed/voicemail call as followed up.
     */
    public function markFollowedUp(PhoneCall $call, int $userId): PhoneCall
    {
        $call->followed_up_at = now();
        $call->followed_up_by = $userId;
        $call->save();

        return $call;
    }

    /**
     * Get recent calls with optional filters.
     */
    public function getRecentCalls(int $limit = 50, array $filters = []): Collection
    {
        $query = PhoneCall::with(['answeredBy', 'client', 'person', 'ticket', 'ticket.categoryNode.parent.parent'])->orderByDesc('started_at');

        if (! empty($filters['status'])) {
            if ($filters['status'] === 'needs-follow-up') {
                $query->unfollowedUp();
            } elseif ($filters['status'] === 'unknown-caller') {
                $query->unknownCaller();
            } else {
                $query->where('status', $filters['status']);
            }
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('started_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('started_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('from_number', 'like', "%{$search}%")
                    ->orWhere('halo_client_name', 'like', "%{$search}%");
            });
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get all People matching this call's phone number for disambiguation.
     */
    public function getCandidateCallers(PhoneCall $call): Collection
    {
        $normalized = PhoneNumber::normalize($call->from_number);
        if (! $normalized) {
            return collect();
        }

        return Person::where(function ($q) use ($normalized) {
            $q->where('phone', $normalized)->orWhere('mobile', $normalized);
        })
            ->with('client')
            ->withCount(['tickets as open_ticket_count' => function ($q) {
                $q->open();
            }])
            ->orderByDesc('is_primary')
            ->orderByDesc('is_active')
            ->get();
    }

    /**
     * Safely update a call record with pessimistic locking.
     * Prevents race conditions when multiple webhooks arrive for the same call.
     */
    private function updateCallSafely(string $callUuid, callable $callback): ?PhoneCall
    {
        try {
            return DB::transaction(function () use ($callUuid, $callback) {
                $call = PhoneCall::where('call_uuid', $callUuid)->lockForUpdate()->first();

                if (! $call) {
                    Log::warning('PhoneCallService: call not found', ['call_uuid' => $callUuid]);

                    return null;
                }

                return $callback($call);
            });
        } catch (\Throwable $e) {
            Log::error('PhoneCallService: failed to update call', [
                'call_uuid' => $callUuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
