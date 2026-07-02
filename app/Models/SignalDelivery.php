<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalDelivery extends Model
{
    protected $fillable = [
        'event_id',
        'route_id',
        'step_order',
        'destination_id',
        'status',
        'delivered_at',
        'acked_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'delivered_at' => 'datetime',
            'acked_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(SignalEvent::class, 'event_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(SignalRoute::class, 'route_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(SignalDestination::class, 'destination_id');
    }
}
