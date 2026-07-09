<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record of a per-technician daily briefing email.
 *
 * One row per (technician, local date). The unique index on
 * (user_id, briefing_date) is the feature's idempotency guard — the daily
 * command records a row after emailing a technician, and skips anyone who
 * already has a row for today. See {@see \App\Services\Briefing\DailyBriefingService}.
 */
class DailyBriefing extends Model
{
    /** @use HasFactory<\Database\Factories\DailyBriefingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'briefing_date',
        'sent_at',
        'open_ticket_count',
        'alert_count',
        'voicemail_count',
        'sla_risk_count',
        'ai_suggestions_included',
    ];

    protected function casts(): array
    {
        return [
            'briefing_date' => 'date',
            'sent_at' => 'datetime',
            'open_ticket_count' => 'integer',
            'alert_count' => 'integer',
            'voicemail_count' => 'integer',
            'sla_risk_count' => 'integer',
            'ai_suggestions_included' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
