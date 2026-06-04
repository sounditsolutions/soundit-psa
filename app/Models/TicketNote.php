<?php

namespace App\Models;

use App\Enums\NoteType;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property-read \App\Models\Email|null $email
 */
class TicketNote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ticket_id',
        'contract_id',
        'author_id',
        'email_id',
        'author_name',
        'body',
        'body_html',
        'note_type',
        'is_private',
        'who_type',
        'is_billable',
        'status_from',
        'status_to',
        'time_minutes',
        'noted_at',
        'edited_at',
        'edited_by',
    ];

    protected function casts(): array
    {
        return [
            'note_type' => NoteType::class,
            'status_from' => TicketStatus::class,
            'status_to' => TicketStatus::class,
            'who_type' => WhoType::class,
            'is_private' => 'boolean',
            'is_billable' => 'boolean',
            'time_minutes' => 'integer',
            'noted_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    // ── Relations ──

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ── Scopes ──

    /**
     * Filter to notes visible in the client portal.
     * Excludes private notes and system-generated note types.
     */
    public function scopePortalVisible(Builder $query): Builder
    {
        return $query->where('is_private', false)
            ->whereNotIn('note_type', array_map(
                fn (NoteType $t) => $t->value,
                NoteType::systemGenerated(),
            ));
    }

    // ── Accessors ──

    public function getDisplayAuthorAttribute(): string
    {
        if ($this->relationLoaded('author') && $this->author) {
            return $this->author->name;
        }

        return $this->author_name ?? 'System';
    }

    public function getRenderedBodyAttribute(): string
    {
        if ($this->body_html) {
            return $this->body_html; // Already sanitized at write time
        }

        return nl2br(e($this->body ?? ''));
    }

    public function getFormattedTimeAttribute(): ?string
    {
        if (! $this->time_minutes) {
            return null;
        }

        $hours = intdiv($this->time_minutes, 60);
        $mins = $this->time_minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours}h {$mins}m";
        } elseif ($hours > 0) {
            return "{$hours}h";
        }

        return "{$mins}m";
    }
}
