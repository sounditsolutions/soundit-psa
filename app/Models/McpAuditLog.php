<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpAuditLog extends Model
{
    protected $fillable = [
        'server_name',
        'method',
        'tool_name',
        'arguments',
        'status',
        'error_message',
        'duration_ms',
        'actor_label',
        'source_ip',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'duration_ms' => 'integer',
        ];
    }
}
