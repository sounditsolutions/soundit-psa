<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TacticalAsset extends Model
{
    protected $fillable = [
        'asset_id',
        'agent_id',
        'hostname',
        'os',
        'os_version',
        'public_ip',
        'local_ips',
        'last_user',
        'cpu',
        'make_model',
        'disk_summary',
        'ram_gb',
        'serial_number',
        'status',
        'agent_version',
        'last_seen_at',
        'client_name',
        'site_name',
        'needs_reboot',
        'has_patches_pending',
        'graphics',
        'monitoring_type',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'local_ips' => 'array',
            'ram_gb' => 'decimal:1',
            'needs_reboot' => 'boolean',
            'has_patches_pending' => 'boolean',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'online' => 'bg-success',
            'offline' => 'bg-danger',
            'overdue' => 'bg-warning text-dark',
            default => 'bg-secondary',
        };
    }
}
