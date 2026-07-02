<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalRoute extends Model
{
    protected $fillable = [
        'label',
        'event_filter',
        'enabled',
        'cooldown_seconds',
    ];

    protected function casts(): array
    {
        return [
            'event_filter' => 'array',
            'enabled' => 'boolean',
            'cooldown_seconds' => 'integer',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SignalRouteStep::class, 'route_id')->orderBy('step_order');
    }
}
