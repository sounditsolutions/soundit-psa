<?php

namespace App\Models;

use App\Enums\TicketCategoryChangeSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tickets.category_id change: who moved the ticket to which taxonomy
 * node, from which node, and what the legacy free-text classification said
 * at that moment (so-0ftg Part 4 — "log agent overrides"). Written solely by
 * TicketObserver on a category_id change, so every writer — the triage
 * mapping, the web UI, future MCP tools — is captured without opting in.
 *
 * Node ids are unconstrained and paths are string snapshots on purpose: the
 * log must survive node renames/deletes, and Phase-1 mapping refinement
 * reads the paths, not the live tree.
 */
class TicketCategoryChangeLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ticket_id',
        'previous_category_id',
        'new_category_id',
        'previous_path',
        'new_path',
        'legacy_category',
        'legacy_subcategory',
        'source',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => TicketCategoryChangeSource::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * True while the triage mapping is applying its own category_id write, so
     * the observer attributes that row to Triage instead of Staff/System.
     * Process-local; always reset in runAsTriage()'s finally.
     */
    private static bool $applyingTriageMapping = false;

    // ── relations ────────────────────────────────────────────────────────────

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** May return null after a node hard-delete — read the path snapshot then. */
    public function previousNode(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'previous_category_id');
    }

    /** May return null after a node hard-delete — read the path snapshot then. */
    public function newNode(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'new_category_id');
    }

    // ── recording ────────────────────────────────────────────────────────────

    /**
     * Run $fn with category_id writes attributed to the triage mapping.
     */
    public static function runAsTriage(callable $fn): mixed
    {
        self::$applyingTriageMapping = true;
        try {
            return $fn();
        } finally {
            self::$applyingTriageMapping = false;
        }
    }

    /**
     * Record a just-saved category_id change. Called by TicketObserver when
     * wasChanged('category_id') — the single seam every writer passes through.
     */
    public static function recordFor(Ticket $ticket): self
    {
        $previousId = $ticket->getOriginal('category_id');
        $newId = $ticket->category_id;

        $source = TicketCategoryChangeSource::System;
        $changedBy = null;
        if (self::$applyingTriageMapping) {
            $source = TicketCategoryChangeSource::Triage;
        } elseif (auth()->check()) {
            $source = TicketCategoryChangeSource::Staff;
            $changedBy = auth()->id();
        }

        return self::create([
            'ticket_id' => $ticket->id,
            'previous_category_id' => $previousId,
            'new_category_id' => $newId,
            'previous_path' => self::pathSnapshot($previousId),
            'new_path' => self::pathSnapshot($newId),
            'legacy_category' => $ticket->category,
            'legacy_subcategory' => $ticket->subcategory,
            'source' => $source,
            'changed_by' => $changedBy,
        ]);
    }

    /**
     * The source of the latest change for a ticket, or null when the log has
     * never seen it. The triage mapping uses this to tell "triage set this
     * node" from "a human owns it" — unknown history reads as human-owned.
     */
    public static function latestSourceFor(Ticket $ticket): ?TicketCategoryChangeSource
    {
        $latest = self::where('ticket_id', $ticket->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $latest?->source;
    }

    private static function pathSnapshot(?int $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        return TicketCategory::find($categoryId)?->pathString();
    }
}
