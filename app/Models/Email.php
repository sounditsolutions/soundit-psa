<?php

namespace App\Models;

use App\Enums\EmailDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Email extends Model
{
    protected $fillable = [
        'graph_id',
        'internet_message_id',
        'conversation_id',
        'in_reply_to',
        'direction',
        'from_address',
        'from_name',
        'to_recipients',
        'cc_recipients',
        'subject',
        'body_preview',
        'body_text',
        'body_html',
        'has_attachments',
        'importance',
        'received_at',
        'is_read',
        'dismissed_at',
        'dismissed_by',
        'client_id',
        'person_id',
        'user_id',
        'ticket_id',
    ];

    protected $hidden = ['body_html'];

    protected function casts(): array
    {
        return [
            'direction' => EmailDirection::class,
            'to_recipients' => 'array',
            'cc_recipients' => 'array',
            'has_attachments' => 'boolean',
            'is_read' => 'boolean',
            'received_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(TicketNote::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ── Scopes ──

    public function scopeRecent(Builder $query, int $limit = 50): Builder
    {
        return $query->orderByDesc('received_at')->limit($limit);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('direction', EmailDirection::Inbound)
            ->where('is_read', false);
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', EmailDirection::Inbound);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeNoClient(Builder $query): Builder
    {
        return $query->whereNull('client_id');
    }

    public function scopeNoTicket(Builder $query): Builder
    {
        return $query->whereNull('ticket_id');
    }

    public function scopeNotDismissed(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->inbound()->noTicket()->notDismissed();
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('subject', 'like', "%{$term}%")
                ->orWhere('from_address', 'like', "%{$term}%")
                ->orWhere('from_name', 'like', "%{$term}%")
                ->orWhere('body_text', 'like', "%{$term}%")
                ->orWhere('body_preview', 'like', "%{$term}%");
        });
    }

    // ── Helpers ──

    public function getSentAtAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->direction === EmailDirection::Outbound ? $this->received_at : null;
    }

    public function isLinkedToTicket(): bool
    {
        return $this->ticket_id !== null;
    }

    public function senderDisplay(): string
    {
        if ($this->from_name) {
            return $this->from_name;
        }

        return $this->from_address;
    }

    public function primaryRecipientDisplay(): string
    {
        $first = $this->to_recipients[0] ?? null;

        // Recipients may be stored either as ['name' => ..., 'address' => ...]
        // maps (Graph ingestion) or as plain address strings (seeded/legacy
        // data). Tolerate both — mirrors the guard in emails/show.blade.php.
        if (is_string($first)) {
            return $first !== '' ? $first : '—';
        }

        if (is_array($first)) {
            return ($first['name'] ?? null) ?: ($first['address'] ?? '—');
        }

        return '—';
    }

    public function primaryRecipientAddress(): ?string
    {
        $first = $this->to_recipients[0] ?? null;

        if (is_string($first)) {
            return $first !== '' ? $first : null;
        }

        if (is_array($first)) {
            return $first['address'] ?? null;
        }

        return null;
    }

    public function fromDomain(): ?string
    {
        $parts = explode('@', $this->from_address);

        return $parts[1] ?? null;
    }

    /**
     * Return body HTML with scripts and event handlers stripped for safe iframe rendering.
     */
    public function sanitizedBodyHtml(): string
    {
        $html = $this->getRawOriginal('body_html') ?? '';

        // Strip <script> tags and their content
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);

        // Strip event handler attributes (on*)
        $html = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/\s+on\w+\s*=\s*'[^']*'/i", '', $html);

        return $html;
    }
}
