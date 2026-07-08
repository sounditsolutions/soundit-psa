<?php

namespace App\Models;

use App\Enums\EmergencyState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TechnicianEmergency extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reasons' => 'array',
        'ticket_ids' => 'array',
        'state' => EmergencyState::class,
        'severity' => 'integer',
        'escalation_step' => 'integer',
        'current_target_user_id' => 'integer',
        'alerted_at' => 'datetime',
        'last_pinged_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'max_hold_sent_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('state', '!=', EmergencyState::Resolved->value);
    }

    public static function hasOpenEmergency(Ticket $ticket): bool
    {
        return static::query()->open()
            ->where(function (Builder $q) use ($ticket) {
                $q->where('ticket_id', $ticket->id)
                    ->orWhereJsonContains('ticket_ids', $ticket->id);
            })->exists();
    }
}
