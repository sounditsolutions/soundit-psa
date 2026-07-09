<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalDestination extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'type',
        'address',
        'mcp_token_label',
        'wake_url',
        'wake_secret',
        'secret',
        'enabled',
        'last_delivery_at',
        'last_delivery_status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'encrypted',
            'wake_url' => 'encrypted',
            'wake_secret' => 'encrypted',
            'secret' => 'encrypted',
            'enabled' => 'boolean',
            'last_delivery_at' => 'datetime',
        ];
    }

    /** The staff user this destination is auto-provisioned for (derived recipients only). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Only manually-created destinations — excludes the auto-provisioned
     * per-user rows backing derived recipients, which are managed automatically.
     */
    public function scopeManual(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }
}
