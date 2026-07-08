<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalInboxEntry extends Model
{
    protected $table = 'signal_inbox';

    public const UPDATED_AT = null;

    protected $fillable = [
        'destination_id',
        'event_id',
        'delivery_id',
        'payload',
        'acked_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'acked_at' => 'datetime',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(SignalDestination::class, 'destination_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(SignalEvent::class, 'event_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(SignalDelivery::class, 'delivery_id');
    }
}
