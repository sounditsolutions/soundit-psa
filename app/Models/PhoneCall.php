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
            'sentiment_score' => 'integer',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'followed_up_at' => 'datetime',
            'transcribed_at' => 'datetime',
        ];
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
            && !$this->isFollowedUp();
    }

    public function isTranscribed(): bool
    {
        return $this->transcription_status === TranscriptionStatus::Completed;
    }

    public function isTranscribing(): bool
    {
        return in_array($this->transcription_status, [
            TranscriptionStatus::Pending,
            TranscriptionStatus::Processing,
        ]);
    }
}
