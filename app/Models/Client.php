<?php

namespace App\Models;

use App\Helpers\MarkdownRenderer;
use App\Support\AvatarHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $hidden = ['credentials'];

    protected $fillable = [
        'halo_id',
        'ninja_org_id',
        'level_group_id',
        'mesh_customer_id',
        'cipp_tenant_domain',
        'cipp_sync_group_id',
        'cipp_transport_rules',
        'cipp_safe_links_policy',
        'cipp_safe_attachments_filters',
        'cipp_conditional_access_policies',
        'cipp_compliance_policies',
        'cipp_mail_security_synced_at',
        'huntress_organization_id',
        'unifi_site_id',
        'unifi_host_id',
        'servosity_company_id',
        'controld_org_id',
        'zorus_customer_id',
        'appriver_customer_id',
        'printix_tenant_id',
        'tactical_site_id',
        'portal_install_token',
        'portal_primary_rmm',
        'comet_group_id',
        'comet_backup_user',
        'comet_backup_password',
        'stripe_customer_id',
        'name',
        'notes',
        'phone',
        'phone_display',
        'email',
        'website',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postcode',
        'is_active',
        'primary_tech_id',
        'reseller_id',
        'qbo_customer_id',
        'qbo_display_name',
        'site_notes',
        'site_notes_html',
        'site_notes_updated_at',
        'site_notes_updated_by',
        'credentials',
        'credentials_updated_at',
        'credentials_updated_by',
    ];

    protected function casts(): array
    {
        return [
            'stage' => \App\Enums\ClientStage::class,
            'ninja_org_id' => 'integer',
            'huntress_organization_id' => 'integer',
            'servosity_company_id' => 'integer',
            'reseller_id' => 'integer',
            'is_active' => 'boolean',
            'site_notes_updated_at' => 'datetime',
            'credentials' => 'encrypted',
            'credentials_updated_at' => 'datetime',
            'comet_backup_password' => 'encrypted',
            'cipp_transport_rules' => 'array',
            'cipp_safe_links_policy' => 'array',
            'cipp_safe_attachments_filters' => 'array',
            'cipp_conditional_access_policies' => 'array',
            'cipp_compliance_policies' => 'array',
            'cipp_mail_security_synced_at' => 'datetime',
        ];
    }

    /**
     * Compact summary of the tenant's M365 security posture (mail protections
     * + identity/conditional access). Returns null when no snapshot has run.
     */
    public function securitySnapshot(): ?array
    {
        if (! $this->cipp_mail_security_synced_at) {
            return null;
        }

        $caPolicies = is_array($this->cipp_conditional_access_policies) ? $this->cipp_conditional_access_policies : [];
        $caEnabled = array_filter($caPolicies, fn ($p) => ($p['state'] ?? $p['State'] ?? '') === 'enabled');
        $compliancePolicies = is_array($this->cipp_compliance_policies) ? $this->cipp_compliance_policies : [];

        return [
            'transport_rule_count' => is_array($this->cipp_transport_rules) ? count($this->cipp_transport_rules) : 0,
            'safe_links_active' => is_array($this->cipp_safe_links_policy) && ! empty($this->cipp_safe_links_policy),
            'safe_attachments_active' => is_array($this->cipp_safe_attachments_filters) && ! empty($this->cipp_safe_attachments_filters),
            'ca_policy_total' => count($caPolicies),
            'ca_policy_enabled' => count($caEnabled),
            'compliance_policy_count' => count($compliancePolicies),
            'synced_at' => $this->cipp_mail_security_synced_at,
        ];
    }

    // ── Relations ──

    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function phoneCalls(): HasMany
    {
        return $this->hasMany(PhoneCall::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function siteNotesUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'site_notes_updated_by');
    }

    public function credentialsUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credentials_updated_by');
    }

    public function primaryTech(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_tech_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'reseller_id');
    }

    public function resellerChildren(): HasMany
    {
        return $this->hasMany(Client::class, 'reseller_id');
    }

    // ── Scopes ──

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('stage', \App\Enums\ClientStage::Active)->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('phone_display', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    // ── Accessors ──

    public function getHasSiteNotesAttribute(): bool
    {
        return $this->site_notes !== null && trim($this->site_notes) !== '';
    }

    public function getCredentialsRenderedAttribute(): ?string
    {
        if (! $this->credentials) {
            return null;
        }

        return MarkdownRenderer::render($this->credentials);
    }

    public function getFullAddressAttribute(): ?string
    {
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->state ? "{$this->state} {$this->postcode}" : $this->postcode,
        ]);

        return $parts ? implode(', ', $parts) : null;
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => AvatarHelper::logoUrl(
            AvatarHelper::extractDomain($this->website, $this->email)
        ));
    }

    /**
     * Return a list of RMM slugs this client has mapped.
     * Used to populate the primary-RMM dropdown and to pick a default.
     *
     * @return array<int, string> e.g. ['ninja', 'level'] or ['tactical']
     */
    public function availableRmms(): array
    {
        $rmms = [];
        if (! empty($this->ninja_org_id)) {
            $rmms[] = 'ninja';
        }
        if (! empty($this->level_group_id)) {
            $rmms[] = 'level';
        }
        if (! empty($this->tactical_site_id)) {
            $rmms[] = 'tactical';
        }

        return $rmms;
    }

    /**
     * Returns the RMM slug to use for portal self-service install.
     * Uses `portal_primary_rmm` if set, otherwise the only mapped RMM
     * (returns null if multiple are mapped and none is chosen).
     */
    public function effectiveInstallRmm(): ?string
    {
        $available = $this->availableRmms();

        if (empty($available)) {
            return null;
        }

        if ($this->portal_primary_rmm && in_array($this->portal_primary_rmm, $available, true)) {
            return $this->portal_primary_rmm;
        }

        return count($available) === 1 ? $available[0] : null;
    }
}
