<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreenConnectEvent extends Model
{
    protected $table = 'screenconnect_events';

    protected $fillable = [
        'asset_id',
        'session_id',
        'event_type',
        'event_time',
        'host',
        'data',
        'participant',
        'network_address',
    ];

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
