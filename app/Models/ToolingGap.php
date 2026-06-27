<?php

namespace App\Models;

use App\Enums\ToolingGapClassification;
use App\Enums\ToolingGapSource;
use App\Enums\ToolingGapStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A durable record of a "tooling gap" — a case where the AI agent lacked a tool
 * or data it needed, or failed to use an available tool.
 *
 * Design: each row separates two concerns:
 *   - `capability_gap`  — ABSTRACT, sanitized, forwardable description of the gap
 *     (e.g. "the agent needs to check recent ticket history for prior context").
 *     Privacy-safe; could one day be shared upstream across MSPs.
 *   - `evidence`        — instance-specific, private/local detail (the actual ticket,
 *     the correction text). Never forwarded.
 *
 * v1 stores both fields but forwards NOTHING. The multi-MSP upstream forwarding
 * seam is schema-only in this increment — opt-in routing is a future snap-in.
 * All gaps are born with status=Open.
 */
class ToolingGap extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'client_id',
        'capability_gap',
        'evidence',
        'classification',
        'source',
        'status',
        'agent_note',
    ];

    protected function casts(): array
    {
        return [
            'classification' => ToolingGapClassification::class,
            'source' => ToolingGapSource::class,
            'status' => ToolingGapStatus::class,
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Create a new ToolingGap row. Born Open. No dedup in v1 — callers
     * are responsible for dedup if required (Tasks 2/3 will add call-sites).
     */
    public static function record(
        ?int $ticketId,
        ?int $clientId,
        string $capabilityGap,
        ?string $evidence,
        ToolingGapClassification $class,
        ToolingGapSource $source,
        ?string $agentNote = null,
    ): self {
        return self::create([
            'ticket_id' => $ticketId,
            'client_id' => $clientId,
            'capability_gap' => $capabilityGap,
            'evidence' => $evidence,
            'classification' => $class,
            'source' => $source,
            'status' => ToolingGapStatus::Open,
            'agent_note' => $agentNote,
        ]);
    }
}
