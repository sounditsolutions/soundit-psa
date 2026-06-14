<?php

namespace App\Models;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiScope;
use App\Support\WikiConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikiFact extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope', 'client_id', 'page_id', 'section_anchor', 'subject_key', 'statement',
        'status', 'pinned', 'volatility', 'source_type', 'source_refs', 'confidence',
        'last_affirmed_at', 'confirmed_by', 'retired_by', 'disputed_with_fact_id',
        'superseded_by_fact_id', 'dismissed_evidence',
    ];

    protected function casts(): array
    {
        return [
            'scope' => WikiScope::class,
            'status' => WikiFactStatus::class,
            'volatility' => WikiFactVolatility::class,
            'source_type' => WikiFactSource::class,
            'source_refs' => 'array',
            'dismissed_evidence' => 'array',
            'pinned' => 'boolean',
            'confidence' => 'decimal:2',
            'last_affirmed_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'page_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function disputedWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'disputed_with_fact_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_fact_id');
    }

    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by');
    }

    /** Spec §4.2/§7: only volatile, non-sync, non-retired facts go stale. */
    public function isStale(): bool
    {
        if ($this->volatility !== WikiFactVolatility::Volatile
            || $this->status === WikiFactStatus::Retired
            || $this->source_type === WikiFactSource::Sync) {
            return false;
        }
        $last = $this->last_affirmed_at ?? $this->created_at;

        return $last !== null && $last->lt(now()->subDays(WikiConfig::stalenessDaysVolatile()));
    }

    /** COALESCE keeps SQLite (test) and MariaDB (prod) parity. */
    public function scopeStale(Builder $query): Builder
    {
        return $query
            ->where('volatility', WikiFactVolatility::Volatile->value)
            ->where('source_type', '!=', WikiFactSource::Sync->value)
            ->where('status', '!=', WikiFactStatus::Retired->value)
            ->whereRaw('COALESCE(last_affirmed_at, created_at) < ?', [now()->subDays(WikiConfig::stalenessDaysVolatile())]);
    }
}
