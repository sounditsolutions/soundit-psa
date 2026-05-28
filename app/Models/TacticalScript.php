<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TacticalScript extends Model
{
    protected $fillable = [
        'tactical_script_id',
        'name',
        'description',
        'shell',
        'category',
        'default_timeout',
        'supported_platforms',
        'hidden',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'supported_platforms' => 'array',
            'hidden' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function shellIcon(): string
    {
        return match ($this->shell) {
            'powershell' => 'bi-terminal-fill',
            'cmd' => 'bi-terminal',
            'python' => 'bi-filetype-py',
            'shell' => 'bi-terminal-dash',
            default => 'bi-code',
        };
    }
}
