<?php

namespace App\Models;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\ChargeClassification;
use App\Enums\TranscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneCall extends Model
{
    protected $fillable = [
        'call_uuid',
        'direction',
        'from_number',
        'to_number',
        'sip_endpoint',
        'status',
        'started_at',
        // No writer of THIS column in app/ uses mass assignment. The two that
        // write it assign the property directly: handleCallAnswered() always
        // did, and logOutboundCall() was moved out of its updateOrCreate()
        // values array because that array is applied on the UPDATE branch too,
        // so a redelivered webhook with an unresolved endpoint would null an
        // attribution already established. (Other columns of this model ARE
        // mass-assigned in app/ — CallController and TranscriptionService both
        // call ->update([...]) — so this is a claim about answered_by, not
        // about the model's fill() surface.)
        //
        // The entry stays because DevDataSeeder and the test fixtures
        // mass-assign it. Measured at this tip, not assumed: no controller,
        // form request, job, command or importer writes this column from
        // request input — every PhoneCall write array in app/ is a literal key
        // list, never a splatted validated()/all(). That is a measurement with
        // no executable guard behind it; issue #2165 owes the negative test.
        'answered_by',
        'ticket_id',
        'notes',
        'transcription',
        'transcription_summary',
        'call_summary',
        'summary_is_public',
        'next_steps',
        'cleaned_transcript',
        'transcription_status',
        'transcription_error',
        'transcribed_at',
        'sentiment_score',
        'charge_classification',
        'coaching_notes',
        'person_confirmed',
        'recording_disk_path',
        'is_billable',
        'billable_minutes',
        'caller_identified_name',
        'caller_identified_company',
        'caller_identity_confidence',
        'intake_spam_score',
    ];

    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'status' => CallStatus::class,
            'transcription_status' => TranscriptionStatus::class,
            'charge_classification' => ChargeClassification::class,
            'person_confirmed' => 'boolean',
            'summary_is_public' => 'boolean',
            'is_billable' => 'boolean',
            'billable_minutes' => 'integer',
            'sentiment_score' => 'integer',
            'caller_identity_confidence' => 'float',
            'intake_spam_score' => 'float',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'followed_up_at' => 'datetime',
            'transcribed_at' => 'datetime',
            'voicemail_notify_deferred_at' => 'datetime',
            'voicemail_notified_at' => 'datetime',
        ];
    }

    public function billedSeconds(): ?int
    {
        $actual = $this->effectiveDurationSeconds();
        if ($actual === null || $actual <= 0) {
            return $actual;
        }

        return $this->billable_minutes === null
            ? $actual
            : min($this->billable_minutes * 60, $actual);
    }

    /**
     * Best-available duration in seconds for billing purposes.
     * Prefers Plivo's Duration (authoritative), falls back to
     * recording_duration (actual audio length) when Plivo's hangup
     * webhook arrives without a Duration field.
     */
    public function effectiveDurationSeconds(): ?int
    {
        if ($this->duration && $this->duration > 0) {
            return (int) $this->duration;
        }

        if ($this->recording_duration && $this->recording_duration > 0) {
            return (int) $this->recording_duration;
        }

        return null;
    }

    /**
     * The number at the OTHER end of the call: the caller on an inbound call,
     * the number we dialled on an outbound call.
     *
     * On a row logOutboundCall() writes, and on a row logIncomingCall() writes
     * for a call that really was inbound, that is from_number.
     * PhoneCallService::logIncomingCall() stores the caller there, and
     * logOutboundCall() stores the DIALLED number there and writes our own DID
     * (PlivoConfig did_number) into to_number. So to_number
     * on an outbound row is not a reading of anything, and a consumer that
     * picks "the far end" by direction (inbound ? from : to) gets our main line
     * back on every outbound call. CallController::getCallHistory() did exactly
     * that until card mfmgS3XO.
     *
     * This is a METHOD over from_number, not a stored column. It adds no
     * migration and no backfill, and there is no second copy to drift from the
     * one every caller-resolution path already reads. The columns are
     * deliberately NOT renamed or swapped. ResolveCallerFromPeople,
     * CallerResolver and PhoneCallActionService all read from_number as the far
     * end on both directions. Making the columns literally direction-accurate
     * would silently break client attribution on outbound calls.
     *
     * The value alone does not say what kind of evidence it is. Read it
     * together with farEndProvenance().
     */
    public function farEndNumber(): ?string
    {
        return $this->from_number !== null && $this->from_number !== ''
            ? $this->from_number
            : null;
    }

    /**
     * What farEndNumber() is evidence OF, which differs by direction:
     *   'caller_id' - inbound. The number the network presented for the party
     *                 who called us. That is evidence about the caller, and a
     *                 spoofable claim, not a verified contact number.
     *   'dialled'   - outbound. The number one of our staff dialled. That is
     *                 evidence about what WE did, not about what number the
     *                 other party owns.
     * null when there is no far-end number (e.g. an anonymous inbound call).
     *
     * It exists because one column carries both provenances with no marker. On
     * card mfmgS3XO a consumer reading from_number on an outbound call was one
     * step from proposing it as a client's own number.
     */
    public function farEndProvenance(): ?string
    {
        if ($this->farEndNumber() === null) {
            return null;
        }

        return match ($this->direction) {
            CallDirection::Inbound => 'caller_id',
            CallDirection::Outbound => 'dialled',
            default => null,
        };
    }

    // ── Relations ──

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function followedUpBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_up_by');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * The contract this call's time was stamped with when it was linked
     * (card I3EvQKUV). Not mass-assignable: PrepayService stamps it and
     * PhoneCallService's link/unlink set or clear it.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function sipEndpointRecord(): BelongsTo
    {
        return $this->belongsTo(SipEndpoint::class, 'sip_endpoint', 'sip_uri');
    }

    // ── Scopes ──

    public function scopeRecent(Builder $query, int $limit = 50): Builder
    {
        return $query->orderByDesc('started_at')->limit($limit);
    }

    public function scopeMissed(Builder $query): Builder
    {
        return $query->where('status', CallStatus::Missed);
    }

    public function scopeUnfollowedUp(Builder $query): Builder
    {
        return $query->whereIn('status', [CallStatus::Missed, CallStatus::Voicemail])
            ->whereNull('followed_up_at');
    }

    public function scopeUnknownCaller(Builder $query): Builder
    {
        return $query->whereNull('client_id')->whereNull('followed_up_at');
    }

    /**
     * Calls whose caller is resolved to a client and a contact but which are
     * attached to no ticket (card 0JJon0z4). Read-only listing filter.
     */
    public function scopeResolvedWithoutTicket(Builder $query): Builder
    {
        return $query->whereNotNull('client_id')
            ->whereNotNull('person_id')
            ->whereNull('ticket_id');
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('started_at', today());
    }

    // ── Helpers ──

    public function isLinkedToTicket(): bool
    {
        return $this->ticket_id !== null;
    }

    public function isFollowedUp(): bool
    {
        return $this->followed_up_at !== null;
    }

    public function needsFollowUp(): bool
    {
        return in_array($this->status, [CallStatus::Missed, CallStatus::Voicemail])
            && ! $this->isFollowedUp();
    }

    public function isTranscribed(): bool
    {
        return $this->transcription_status === TranscriptionStatus::Completed;
    }

    /** Automatic delivery must not retry content explicitly held for listening. */
    public function hasTerminalTranscription(): bool
    {
        return in_array($this->transcription_status, [
            TranscriptionStatus::Completed,
            TranscriptionStatus::Unusable,
        ], true);
    }

    public function isTranscribing(): bool
    {
        return in_array($this->transcription_status, [
            TranscriptionStatus::Pending,
            TranscriptionStatus::Processing,
        ]);
    }
}
