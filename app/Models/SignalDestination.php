<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalDestination extends Model
{
    protected $fillable = [
        'label',
        'type',
        'address',
        'mcp_token_id',
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

    public function mcpToken(): BelongsTo
    {
        return $this->belongsTo(McpToken::class);
    }
}
