<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'halo_id',
        'ninja_id',
        'level_id',
        'controld_device_id',
        'zorus_endpoint_id',
        'm365_device_id',
        'client_id',
        'name',
        'notes',
        'asset_type',
        'serial_number',
        'hostname',
        'os',
        'cpu',
        'ram_gb',
        'disk_summary',
        'ip_address',
        'last_user',
        'ninja_url',
        'level_url',
        'controld_profile_name',
        'controld_status',
        'controld_agent_status',
        'controld_agent_version',
        'is_active',
        'last_seen_at',
        'last_boot_at',
        'needs_reboot',
        'warranty_start',
        'warranty_end',
        'rmm_online',
        'ninja_synced_at',
        'level_synced_at',
        'controld_last_seen_at',
        'controld_synced_at',
        'zorus_group_name',
        'zorus_filtering_enabled',
        'zorus_cybersight_enabled',
        'zorus_agent_version',
        'zorus_agent_state',
        'zorus_last_seen_at',
        'zorus_synced_at',
        'backup_cloud_bytes',
        'backup_local_bytes',
        'backup_revisions_bytes',
        'backup_synced_at',
        'comet_username',
        'comet_device_id',
        'comet_backup_enabled',
        'servosity_backup_enabled',
        'servosity_dr_backup_id',
        'servosity_backup_password',
        'm365_compliance_state',
        'm365_is_compliant',
        'm365_enrollment_type',
        'm365_os_version',
        'm365_last_sync_at',
        'm365_device_owner_type',
        'm365_defender_status',
        'm365_defender_version',
        'm365_last_scan_at',
        'm365_synced_at',
        'screenconnect_session_id',
        'screenconnect_online',
        'screenconnect_client_version',
        'screenconnect_last_seen_at',
        'screenconnect_synced_at',
        'tactical_asset_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rmm_online' => 'boolean',
            'ram_gb' => 'decimal:2',
            'last_seen_at' => 'datetime',
            'last_boot_at' => 'datetime',
            'needs_reboot' => 'boolean',
            'warranty_start' => 'date',
            'warranty_end' => 'date',
            'ninja_synced_at' => 'datetime',
            'level_synced_at' => 'datetime',
            'controld_last_seen_at' => 'datetime',
            'controld_synced_at' => 'datetime',
            'zorus_filtering_enabled' => 'boolean',
            'zorus_cybersight_enabled' => 'boolean',
            'zorus_last_seen_at' => 'datetime',
            'zorus_synced_at' => 'datetime',
            'backup_synced_at' => 'datetime',
            'comet_backup_enabled' => 'boolean',
            'servosity_backup_enabled' => 'boolean',
            'servosity_backup_password' => 'encrypted',
            'm365_is_compliant' => 'boolean',
            'm365_last_sync_at' => 'datetime',
            'm365_last_scan_at' => 'datetime',
            'm365_synced_at' => 'datetime',
            'screenconnect_online' => 'boolean',
            'screenconnect_last_seen_at' => 'datetime',
            'screenconnect_synced_at' => 'datetime',
        ];
    }

    // ── Relations ──

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_asset');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function activeAlerts(): HasMany
    {
        return $this->hasMany(Alert::class)->whereIn('status', [
            \App\Enums\AlertStatus::Active,
            \App\Enums\AlertStatus::Acknowledged,
            \App\Enums\AlertStatus::Ticketed,
        ]);
    }

    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'contract_asset')
            ->using(ContractAsset::class)
            ->withPivot('assigned_at', 'assignment_source');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'asset_person')
            ->withPivot('is_primary', 'assignment_source', 'last_seen_at')
            ->withTimestamps();
    }

    public function primaryUser(): ?Person
    {
        return $this->users()->wherePivot('is_primary', true)->first();
    }

    public function tacticalAsset(): HasOne
    {
        return $this->hasOne(TacticalAsset::class, 'asset_id');
    }

    // ── Scopes ──

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('hostname', 'like', "%{$term}%")
                ->orWhere('serial_number', 'like', "%{$term}%")
                ->orWhere('ip_address', 'like', "%{$term}%");
        });
    }

    // ── Accessors ──

    /**
     * Status badge: Online, Offline, or Unknown.
     *
     * When rmm_online is set (by Level or Ninja sync), it is the authoritative source.
     * Level: direct boolean from API/webhooks (real-time).
     * Ninja: derived from lastContact heartbeat freshness (updated every 5 min).
     * Falls back to last_seen_at timestamp for non-RMM assets.
     */
    public function getStatusBadgeAttribute(): string
    {
        if ($this->rmm_online !== null) {
            return $this->rmm_online ? 'Online' : 'Offline';
        }

        if (! $this->last_seen_at) {
            return 'Unknown';
        }

        return $this->last_seen_at->diffInMinutes(now()) <= 15 ? 'Online' : 'Offline';
    }

    public function getControldStatusLabelAttribute(): ?string
    {
        if ($this->controld_status === null) {
            return null;
        }

        return match ($this->controld_status) {
            0 => 'Pending',
            1 => 'Active',
            2 => 'Soft Disabled',
            3 => 'Hard Disabled',
            default => 'Unknown',
        };
    }

    public function getControldAgentStatusLabelAttribute(): ?string
    {
        if ($this->controld_agent_status === null) {
            return null;
        }

        return $this->controld_agent_status === 1 ? 'Connected' : 'Disconnected';
    }

    public function getM365ComplianceLabelAttribute(): ?string
    {
        if ($this->m365_compliance_state === null) {
            return null;
        }

        return match (strtolower($this->m365_compliance_state)) {
            'compliant' => 'Compliant',
            'noncompliant' => 'Non-Compliant',
            'configmanager' => 'Config Manager',
            'ingraceperiod' => 'In Grace Period',
            'unknown' => 'Unknown',
            default => $this->m365_compliance_state,
        };
    }

    // ── Helpers ──

    /**
     * Try to match last_user to a Person record in the same client.
     */
    public function resolveLastUserPerson(): ?Person
    {
        if (! $this->last_user || ! $this->client_id) {
            return null;
        }

        $raw = $this->last_user;

        // Extract username from DOMAIN\username or username@domain
        $username = $raw;
        if (str_contains($raw, '\\')) {
            $username = substr($raw, strrpos($raw, '\\') + 1);
        } elseif (str_contains($raw, '@')) {
            $username = substr($raw, 0, strpos($raw, '@'));
        }

        $scope = Person::where('client_id', $this->client_id);

        // Match by CIPP UPN (e.g., username@domain.com)
        $match = (clone $scope)->where('cipp_upn', 'like', $username.'@%')->first();
        if ($match) {
            return $match;
        }

        // Match by email
        $match = (clone $scope)->where('email', 'like', $username.'@%')->first();
        if ($match) {
            return $match;
        }

        // Match by full name (case-insensitive)
        $match = (clone $scope)->whereRaw(
            "LOWER(CONCAT(first_name, ' ', last_name)) = ?",
            [strtolower($raw)]
        )->first();
        if ($match) {
            return $match;
        }

        return null;
    }
}
