<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type_key',
        'entity_type',
        'entity_id',
        'summary',
        'context',
        'origin_event_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function originEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_event_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SignalDelivery::class, 'event_id');
    }
}
