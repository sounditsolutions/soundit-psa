<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignalDestination extends Model
{
    protected $fillable = [
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
}
