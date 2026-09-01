<?php

namespace App\Models;

use App\Enums\TicketDescriptionChangeSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tickets.description rewrite: who changed it, what it said before, what
 * it says now, and whether the rendering it replaced was pre-rendered email
 * HTML. Written solely by TicketObserver on a description change, so every
 * writer — the staff web UI, the MCP update_ticket verb, the email pipeline,
 * queued jobs — is captured without opting in (#992 audit-seam ruling,
 * 2026-09-01).
 *
 * SCOPED TO UPDATES, not creates. A description present at creation is not a
 * change: the ticket row itself already carries that text alongside
 * created_by and created_at, and nothing has been destroyed. This log exists
 * because an EDIT overwrites the prior value irrecoverably. (This is where it
 * deliberately diverges from TicketCategoryChangeLog, which also records at
 * create because Phase-1 mapping refinement needs the initially-chosen node.)
 *
 * AUDIT-ONLY — never an ownership or precedence source. The INSERT runs in
 * TicketObserver::updated(), AFTER the UPDATE it describes, and for callers
 * outside a transaction the two statements commit separately; a concurrent
 * reader can see the new description while this row is still pending. Read
 * the ticket for current state, this table only for history.
 */
class TicketDescriptionChangeLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ticket_id',
        'previous_description',
        'new_description',
        'previous_had_rendered_html',
        'source',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => TicketDescriptionChangeSource::class,
            'previous_had_rendered_html' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    // ── relations ────────────────────────────────────────────────────────────

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    // ── recording ────────────────────────────────────────────────────────────

    /**
     * Who a description write happening RIGHT NOW should be attributed to,
     * from execution context alone — never from caller-supplied attributes, so
     * it cannot be forged. An unauthenticated writer is System, and that is a
     * recorded fact rather than a skipped row: Jeeves's ruling is explicit that
     * "that context touching descriptions is precisely a thing the record
     * should show, not skip".
     */
    public static function attributionSource(): TicketDescriptionChangeSource
    {
        return auth()->check()
            ? TicketDescriptionChangeSource::Staff
            : TicketDescriptionChangeSource::System;
    }

    /**
     * Record a just-saved description change. Called by TicketObserver when
     * wasChanged('description') — the single seam every writer passes through.
     */
    public static function recordFor(Ticket $ticket): self
    {
        $source = self::attributionSource();

        return self::create([
            'ticket_id' => $ticket->id,
            'previous_description' => $ticket->getOriginal('description'),
            'new_description' => $ticket->description,
            // getOriginal, not the current value: updating() has already nulled
            // description_html by the time this runs, so the live column no
            // longer says whether the replaced rendering was email HTML.
            'previous_had_rendered_html' => filled($ticket->getOriginal('description_html')),
            'source' => $source,
            'changed_by' => $source === TicketDescriptionChangeSource::Staff ? auth()->id() : null,
        ]);
    }
}
