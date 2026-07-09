<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalRouteStep extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'route_id',
        'step_order',
        'destination_id',
        'derived_from',
        'wait_for_ack_seconds',
        'resolve_within_seconds',
        'non_suppressible',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'wait_for_ack_seconds' => 'integer',
            'resolve_within_seconds' => 'integer',
            'non_suppressible' => 'boolean',
        ];
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
