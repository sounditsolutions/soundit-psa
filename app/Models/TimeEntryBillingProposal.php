<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeEntryBillingProposal extends Model
{
    protected $fillable = [
        'entry_type', 'entry_id', 'billable_minutes', 'reason', 'actor_user_id',
        'actor_label', 'state', 'handled_by', 'handled_at', 'expires_at',
    ];

    protected $hidden = ['reason'];

    protected function casts(): array
    {
        return [
            'entry_id' => 'integer',
            'billable_minutes' => 'integer',
            'actor_user_id' => 'integer',
            'handled_by' => 'integer',
            'handled_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
