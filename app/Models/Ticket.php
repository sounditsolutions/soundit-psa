<?php

namespace App\Models;

use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'halo_id',
        'client_id',
        'contact_id',
        'parent_ticket_id',
        'assignee_id',
        'created_by',
        'subject',
        'description',
        'description_html',
        'resolution',
        'resolution_ai_drafted',
        'source',
        'source_ref',
        'type',
        'status',
        'priority',
        'priority_order',
        'category',
        'subcategory',
        'category_id',
        'search_keywords',
        'opened_at',
        'responded_at',
        'resolved_at',
        'closed_at',
        'sla_breach_recorded_at',
        'total_pending_minutes',
        'pending_since',
        'due_at',
        'response_due_at',
        'contract_id',
        'total_time_minutes',
        'sla_name',
        'reported_by',
    ];

    protected function casts(): array
    {
        return [
            'resolution_ai_drafted' => 'boolean',
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'type' => TicketType::class,
            'source' => TicketSource::class,
            'total_pending_minutes' => 'integer',
            'total_time_minutes' => 'integer',
            'opened_at' => 'datetime',
            'responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'sla_breach_recorded_at' => 'datetime',
            'pending_since' => 'datetime',
            'due_at' => 'datetime',
            'response_due_at' => 'datetime',
        ];
    }

    // ── Mutators ──

    public function setPriorityAttribute($value): void
    {
        $this->attributes['priority'] = $value instanceof TicketPriority ? $value->value : $value;

        $enum = $value instanceof TicketPriority ? $value : TicketPriority::tryFrom($value);
        $this->attributes['priority_order'] = $enum?->sortOrder() ?? 3;
    }

    // ── Relations ──

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'contact_id');
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'ticket_asset')
            ->withPivot('is_primary', 'halo_asset_id')
            ->withTimestamps();
    }

    public function primaryAsset(): ?Asset
    {
        return $this->assets->firstWhere('pivot.is_primary', true)
            ?? $this->assets->first();
    }

    /**
     * The structured taxonomy leaf this ticket maps to (so-0ftg). Named
     * categoryNode(), NOT category(), because the legacy free-text `category`
     * string column would otherwise shadow the relation on $ticket->category.
     */
    public function categoryNode(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parentTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'parent_ticket_id');
    }

    public function childTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'parent_ticket_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(TicketNote::class)->withTrashed()->orderByDesc('noted_at');
    }

    public function phoneCalls(): HasMany
    {
        return $this->hasMany(PhoneCall::class);
    }

    public function triageRuns(): HasMany
    {
        return $this->hasMany(TriageRun::class)->orderByDesc('created_at');
    }

    public function technicianRuns(): HasMany
    {
        return $this->hasMany(TechnicianRun::class);
    }

    public function latestTriageRun(): HasOne
    {
        return $this->hasOne(TriageRun::class)->latestOfMany();
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ── Scopes ──

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TicketStatus::New,
            TicketStatus::InProgress,
            TicketStatus::PendingClient,
            TicketStatus::PendingThirdParty,
        ]);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TicketStatus::Resolved,
            TicketStatus::Closed,
        ]);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assignee_id', $userId);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('due_at')->where('due_at', '<', now());
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('response_due_at')
                        ->whereNull('responded_at')
                        ->where('response_due_at', '<', now());
                });
            });
    }

    public function scopeBreaching(Builder $query): Builder
    {
        return $query->open()->overdue();
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $outer) use ($term) {
            // ID-shortcut lookups
            if (preg_match('/^T-?(\d+)$/i', $term, $matches)) {
                $outer->orWhere('id', $matches[1]);
            }
            if (preg_match('/^#(\d+)$/', $term, $matches)) {
                $outer->orWhere('halo_id', $matches[1]);
            }

            // Tokenize for keyword search across subject/description/resolution.
            // Each token must match at least one of those columns (AND across
            // tokens, OR across columns) so multi-word queries narrow results
            // instead of failing to match a single long substring.
            $tokens = self::extractSearchTokens($term);

            if (empty($tokens)) {
                $outer->orWhere('subject', 'like', "%{$term}%");

                return;
            }

            $outer->orWhere(function (Builder $tokensGroup) use ($tokens) {
                foreach ($tokens as $token) {
                    $tokensGroup->where(function (Builder $cols) use ($token) {
                        $cols->where('subject', 'like', "%{$token}%")
                            ->orWhere('description', 'like', "%{$token}%")
                            ->orWhere('resolution', 'like', "%{$token}%")
                            ->orWhere('search_keywords', 'like', "%{$token}%")
                            ->orWhere('category', 'like', "%{$token}%")
                            ->orWhere('subcategory', 'like', "%{$token}%")
                            ->orWhereExists(function ($sub) use ($token) {
                                $sub->from('ticket_asset')
                                    ->join('assets', 'assets.id', '=', 'ticket_asset.asset_id')
                                    ->whereColumn('ticket_asset.ticket_id', 'tickets.id')
                                    ->where(function ($a) use ($token) {
                                        $a->where('assets.hostname', 'like', "%{$token}%")
                                            ->orWhere('assets.name', 'like', "%{$token}%");
                                    });
                            });
                    });
                }
            });
        });
    }

    /**
     * Pull keyword tokens out of a free-form search query: lowercase,
     * drop punctuation, drop short/stop words, dedupe.
     *
     * @return list<string>
     */
    private static function extractSearchTokens(string $term): array
    {
        static $stopwords = [
            'the', 'and', 'for', 'are', 'but', 'not', 'with', 'from',
            'this', 'that', 'will', 'when', 'what', 'where', 'have',
            'has', 'had', 'was', 'were', 'can', 'any', 'all', 'its',
            'our', 'you', 'how', 'why', 'who', 'use', 'using',
        ];

        $cleaned = preg_replace('/[^\p{L}\p{N}\s_-]+/u', ' ', mb_strtolower($term, 'UTF-8'));
        $parts = preg_split('/\s+/', trim((string) $cleaned)) ?: [];

        $tokens = array_filter($parts, fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, $stopwords, true));

        return array_values(array_unique($tokens));
    }

    // ── Helpers ──

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * True iff this ticket has had zero human interaction since creation.
     *
     * All of the following must hold:
     *   1. No TicketNote with a non-system note_type (excludes System/StatusChange/AiTriage/Escalation).
     *   2. No portal reply — a note with who_type == EndUser (null author_id, but IS human).
     *   3. responded_at is null.
     *   4. status is still New.
     *
     * Designed to be called AFTER AlertService::resolve() has added its resolve note;
     * that note is NoteType::System (system-generated) and is therefore excluded by gate 1.
     */
    public function isUntouchedByHuman(): bool
    {
        // Gate 3 & 4: quick scalar checks first (no DB query)
        if ($this->responded_at !== null || $this->status !== TicketStatus::New) {
            return false;
        }

        // Gate 1 & 2: query via a fresh hasMany(TicketNote::class) which applies the
        // default global scope and therefore EXCLUDES soft-deleted notes by default.
        // (The notes() relation uses withTrashed() so that already-loaded relation is
        // bypassed here — we want active notes only; trashed notes don't count.)
        $systemTypes = array_map(
            fn (NoteType $t) => $t->value,
            NoteType::systemGenerated(),
        );

        return ! $this->hasMany(TicketNote::class)
            ->where(function ($q) use ($systemTypes) {
                // Gate 1: human note_type
                $q->whereNotIn('note_type', $systemTypes)
                    // Gate 2: portal reply (EndUser who_type)
                    ->orWhere('who_type', WhoType::EndUser->value);
            })
            ->exists();
    }

    public function isOverdue(): bool
    {
        return $this->due_at && now()->gt($this->due_at) && ! $this->resolved_at;
    }

    public function isResponseOverdue(): bool
    {
        return $this->response_due_at && ! $this->responded_at && now()->gt($this->response_due_at);
    }

    public function isSlaBreach(): bool
    {
        return $this->isOverdue() || $this->isResponseOverdue();
    }

    // ── Accessors ──

    /**
     * Rendered description: prefer pre-rendered HTML (from email), fall back to markdown.
     */
    public function getRenderedDescriptionAttribute(): ?string
    {
        if ($this->description_html) {
            return $this->description_html;
        }

        if ($this->description) {
            return \App\Helpers\MarkdownRenderer::render($this->description);
        }

        return null;
    }

    public function getDisplayIdAttribute(): string
    {
        if ($this->halo_id) {
            return "#{$this->halo_id}";
        }

        return "T-{$this->id}";
    }

    /**
     * Resolve a ticket from an AI-tool or user-supplied reference — the inverse
     * of display_id. Accepts the internal id (8351, "8351", "T-8351") and the
     * display id shown for externally-synced tickets ("#8351" → halo_id). A bare
     * number prefers the internal id and falls back to halo_id, so internal-id
     * callers keep their existing matches while display-number callers stop
     * getting "not found" on synced tickets whose internal id diverges.
     * When $clientId is given, every lookup is scoped to that client.
     */
    public static function resolveReference(int|string $reference, ?int $clientId = null): ?self
    {
        $reference = trim((string) $reference);

        $query = fn (): Builder => static::query()
            ->when($clientId !== null, fn (Builder $q) => $q->where('client_id', $clientId));

        if (preg_match('/^T-?(\d+)$/i', $reference, $matches)) {
            return $query()->where('id', (int) $matches[1])->first();
        }

        if (preg_match('/^#(\d+)$/', $reference, $matches)) {
            return $query()->where('halo_id', (int) $matches[1])->first();
        }

        if (! preg_match('/^\d+$/', $reference)) {
            return null;
        }

        return $query()->where('id', (int) $reference)->first()
            ?? $query()->where('halo_id', (int) $reference)->first();
    }

    public function getFormattedTotalTimeAttribute(): ?string
    {
        // Prefer Halo's ticket-level total, fall back to notes sum
        $minutes = $this->total_time_minutes ?? $this->notes_sum_time_minutes ?? 0;
        if ($minutes <= 0) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours}h {$mins}m";
        } elseif ($hours > 0) {
            return "{$hours}h";
        }

        return "{$mins}m";
    }

    public function getNetElapsedMinutesAttribute(): int
    {
        $end = $this->resolved_at ?? now();
        $totalMinutes = $this->opened_at ? $this->opened_at->diffInMinutes($end) : 0;

        return max(0, $totalMinutes - ($this->total_pending_minutes ?? 0));
    }
}
