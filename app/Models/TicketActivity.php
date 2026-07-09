<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single field-level change on a ticket ("field X changed from Y to Z").
 *
 * Unlike ContractActivity — which is event-based (one row per semantic action,
 * with a `changes` JSON blob) — ticket activity is field-based: one immutable
 * row per changed column, captured automatically by TicketActivityLogger from
 * the TicketObserver. Old/new values are stored as human-readable labels
 * rendered at write time so the record stays meaningful even if the referenced
 * entity (assignee, contract, …) is later renamed or deleted.
 */
class TicketActivity extends Model
{
    public $timestamps = false;

    /**
     * Ticket columns whose changes are recorded to the audit trail.
     *
     * Deliberately excludes derived columns (priority_order), long-text bodies
     * (description/resolution — noisy, tracked elsewhere), and side-effect
     * timestamps (resolved_at, closed_at, responded_at, pending_since,
     * sla_breach_recorded_at, …) that are consequences of a status change
     * rather than audit-worthy edits in their own right.
     *
     * @var list<string>
     */
    public const TRACKED_FIELDS = [
        'status',
        'priority',
        'type',
        'assignee_id',
        'client_id',
        'contact_id',
        'contract_id',
        'subject',
        'category',
        'subcategory',
        'due_at',
        'response_due_at',
    ];

    protected $fillable = [
        'ticket_id',
        'user_id',
        'field',
        'old_value',
        'new_value',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Human-friendly label for the changed field (e.g. "assignee_id" → "Assignee").
     */
    public function fieldLabel(): string
    {
        return match ($this->field) {
            'status' => 'Status',
            'priority' => 'Priority',
            'type' => 'Type',
            'assignee_id' => 'Assignee',
            'client_id' => 'Client',
            'contact_id' => 'Contact',
            'contract_id' => 'Contract',
            'subject' => 'Subject',
            'category' => 'Category',
            'subcategory' => 'Subcategory',
            'due_at' => 'Due date',
            'response_due_at' => 'Response due',
            default => Str::headline((string) $this->field),
        };
    }
}
