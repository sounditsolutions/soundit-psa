<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $fillable = [
        'asset_id',
        'client_id',
        'source',
        'source_alert_id',
        'severity',
        'status',
        'title',
        'message',
        'hostname',
        'ticket_id',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_at',
        'refired_count',
        'metadata',
        'fired_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => AlertSource::class,
            'severity' => AlertSeverity::class,
            'status' => AlertStatus::class,
            'metadata' => 'array',
            'fired_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            AlertStatus::Active,
            AlertStatus::Acknowledged,
            AlertStatus::Ticketed,
        ]);
    }

    public function scopeActive($query)
    {
        return $query->where('status', AlertStatus::Active);
    }

    /**
     * Get a URL to view this alert/device in the source RMM.
     */
    public function sourceUrl(): ?string
    {
        return match ($this->source) {
            AlertSource::Tactical => $this->tacticalUrl(),
            AlertSource::Ninja => $this->ninjaUrl(),
            AlertSource::Huntress => $this->huntressUrl(),
            AlertSource::Comet => $this->cometUrl(),
            default => null,
        };
    }

    private function tacticalUrl(): ?string
    {
        $agentId = $this->metadata['agent_id'] ?? null;
        if (! $agentId) {
            return null;
        }

        // Tactical's web console is hosted separately from its API. We store
        // the console URL in settings so operators can point to their own
        // deployment without code changes.
        $consoleUrl = rtrim(\App\Models\Setting::getValue('tactical_console_url') ?? '', '/');
        if (! $consoleUrl) {
            return null;
        }

        return "{$consoleUrl}/#/agents/{$agentId}";
    }

    private function ninjaUrl(): ?string
    {
        $deviceId = $this->metadata['ninja_device_id'] ?? null;
        if (! $deviceId) {
            return null;
        }
        $ninjaUrl = \App\Models\Setting::getValue('ninja_instance_url') ?? 'https://app.ninjarmm.com';

        return rtrim($ninjaUrl, '/').'/#/deviceDashboard/'.$deviceId.'/overview';
    }

    private function huntressUrl(): ?string
    {
        // source_alert_id is the incident report URL for Huntress
        if (str_starts_with($this->source_alert_id, 'http')) {
            return $this->source_alert_id;
        }

        return null;
    }

    private function cometUrl(): ?string
    {
        $serverUrl = \App\Support\CometConfig::serverUrl();

        return $serverUrl ? rtrim($serverUrl, '/') : null;
    }
}
