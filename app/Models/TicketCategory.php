<?php

namespace App\Models;

use App\Enums\RecordTypeHint;
use App\Enums\SopStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A node in the so-0ftg ITIL-informed ticket taxonomy: a self-referential tree
 * (Category -> Subcategory -> Item/Symptom, depth <= 3 — the max depth is
 * enforced by the write layers, not the DB). Each node carries the SOP served
 * inline on ticket detail. sop_status is a soft hint that never gates serving.
 */
class TicketCategory extends Model
{
    protected $fillable = [
        'name',
        'parent_id',
        'description',
        'sop_text',
        'sop_status',
        'record_type_hint',
        'sort_order',
        'is_active',
        'updated_by',
        'source_runbook_slug',
    ];

    protected function casts(): array
    {
        return [
            'sop_status' => SopStatus::class,
            'record_type_hint' => RecordTypeHint::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected $attributes = [
        'sop_status' => 'none',
        'is_active' => true,
        'sort_order' => 0,
    ];

    // ── relations ────────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ── tree helpers ─────────────────────────────────────────────────────────

    public function isLeaf(): bool
    {
        return $this->children()->doesntExist();
    }

    /** 1 for a root category, 2 for a subcategory, 3 for an item/symptom. */
    public function depth(): int
    {
        $depth = 1;
        $node = $this;
        while ($node->parent_id !== null) {
            $node = $node->parent;
            $depth++;
        }

        return $depth;
    }

    /** Ancestors ordered root-first, excluding self. */
    public function ancestors(): Collection
    {
        $chain = collect();
        $node = $this->parent;
        while ($node !== null) {
            $chain->prepend($node);
            $node = $node->parent;
        }

        return $chain;
    }

    /** Human-readable path, e.g. "Security & EDR / Scareware / Fake-AV popup". */
    public function pathString(string $separator = ' / '): string
    {
        return $this->ancestors()->push($this)->pluck('name')->implode($separator);
    }

    /** Every node below this one, all levels. */
    public function descendants(): Collection
    {
        $out = collect();
        foreach ($this->children as $child) {
            $out->push($child);
            $out = $out->merge($child->descendants());
        }

        return $out;
    }

    /** A node "has" an SOP when it carries text — independent of sop_status. */
    public function hasSop(): bool
    {
        return filled($this->sop_text);
    }

    // ── scopes (pure; callers compose, e.g. active()->coverageGap()) ─────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Nodes flagged as having no procedure yet — the coverage-gap filter. */
    public function scopeCoverageGap(Builder $query): Builder
    {
        return $query->where('sop_status', SopStatus::None->value);
    }

    /** Nodes not touched within the last $days — the staleness filter. */
    public function scopeStale(Builder $query, int $days): Builder
    {
        return $query->where('updated_at', '<', now()->subDays($days));
    }
}
