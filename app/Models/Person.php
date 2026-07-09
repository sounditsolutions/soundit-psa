<?php

namespace App\Models;

use App\Enums\PersonType;
use App\Support\AvatarHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;

class Person extends Authenticatable
{
    use SoftDeletes;

    protected $fillable = [
        'halo_id',
        'cipp_user_id',
        'cipp_upn',
        'client_id',
        'person_type',
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_display',
        'mobile',
        'mobile_display',
        'job_title',
        'notes',
        'department',
        'office_location',
        'is_hybrid',
        'm365_user_type',
        'mailbox_size_bytes',
        'mailbox_item_count',
        'mailbox_forwarding_smtp',
        'mailbox_forwarding_internal',
        'mailbox_deliver_and_forward',
        'last_sign_in_at',
        'cipp_inactive',
        'mfa_enabled',
        'is_primary',
        'is_active',
        'portal_enabled',
        'company_wide_access',
        'portal_last_login_at',
        'cipp_synced_at',
        'cipp_enriched_at',
        'avatar_path',
        'avatar_synced_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'person_type' => PersonType::class,
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'portal_enabled' => 'boolean',
            'company_wide_access' => 'boolean',
            'password' => 'hashed',
            'portal_last_login_at' => 'datetime',
            'cipp_synced_at' => 'datetime',
            'cipp_enriched_at' => 'datetime',
            'is_hybrid' => 'boolean',
            'mfa_enabled' => 'boolean',
            'mailbox_deliver_and_forward' => 'boolean',
            'last_sign_in_at' => 'datetime',
            'cipp_inactive' => 'boolean',
            'avatar_synced_at' => 'datetime',
        ];
    }

    /**
     * True when the mailbox has an external SMTP forward configured — a top
     * indicator of account compromise.
     */
    public function hasExternalForward(): bool
    {
        if (! $this->mailbox_forwarding_smtp) {
            return false;
        }

        // If the SMTP target's domain matches the user's own domain (or the cipp UPN's domain),
        // treat as an internal forward, not external.
        $ownDomain = $this->cipp_upn ? mb_strtolower(substr(strrchr($this->cipp_upn, '@') ?: '', 1)) : null;
        $targetDomain = mb_strtolower(substr(strrchr($this->mailbox_forwarding_smtp, '@') ?: '', 1));

        return $targetDomain !== '' && $targetDomain !== $ownDomain;
    }

    // ── Relations ──

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'contact_id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'contract_person')
            ->using(ContractPerson::class)
            ->withPivot('assigned_at', 'assignment_source');
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_person')
            ->withPivot('is_primary', 'assignment_source', 'last_seen_at')
            ->withTimestamps();
    }

    public function emailAddresses(): HasMany
    {
        return $this->hasMany(PersonEmail::class);
    }

    public function additionalEmailAddresses(): HasMany
    {
        return $this->hasMany(PersonEmail::class)->where('is_primary', false);
    }

    // ── Scopes ──

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeBillable(Builder $query): Builder
    {
        return $query->where('person_type', PersonType::User->value);
    }

    public function scopePortalEnabled(Builder $query): Builder
    {
        return $query->where('portal_enabled', true)
            ->where('is_active', true)
            ->whereNotNull('email');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('phone_display', 'like', "%{$term}%")
                ->orWhere('mobile', 'like', "%{$term}%")
                ->orWhere('mobile_display', 'like', "%{$term}%")
                ->orWhereHas('emailAddresses', fn (Builder $eq) => $eq->where('email', 'like', "%{$term}%"));
        });
    }

    public function scopeWhereEmailMatch(Builder $query, string $email): Builder
    {
        $normalized = mb_strtolower(trim($email));

        return $query->where(function (Builder $q) use ($normalized) {
            $q->whereRaw('LOWER(email) = ?', [$normalized])
                ->orWhereHas('emailAddresses', fn (Builder $eq) => $eq->where('email', $normalized));
        });
    }

    public function scopeWhereEmailDomain(Builder $query, string $domain): Builder
    {
        $normalized = mb_strtolower(trim($domain));
        $pattern = "%@{$normalized}";

        return $query->where(function (Builder $q) use ($pattern) {
            $q->whereRaw('LOWER(email) LIKE ?', [$pattern])
                ->orWhereHas('emailAddresses', fn (Builder $eq) => $eq->where('email', 'like', $pattern));
        });
    }

    // ── Portal Auth ──

    /**
     * The single source of truth for "may this person use the client portal?".
     *
     * Security invariant: a prospect's contact must NEVER reach the portal, even
     * if `portal_enabled`/`password` were somehow set. Every grant path (login,
     * access-link, verify, password reset, staff invite/toggle/impersonate, and
     * the PortalAuthenticate middleware) routes through this predicate or its
     * `client.stage === Active` condition.
     */
    public function canAccessPortal(): bool
    {
        return $this->portal_enabled
            && $this->is_active
            && $this->client?->stage === \App\Enums\ClientStage::Active;
    }

    /**
     * Send the password reset notification via Graph API email.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = url(route('portal.password.reset', ['token' => $token, 'email' => $this->email], false));

        $companyName = \App\Support\PortalConfig::companyName();
        $body = "You are receiving this email because we received a password reset request for your {$companyName} portal account.\n\n"
            ."Click the link below to reset your password:\n{$url}\n\n"
            ."This link will expire in 60 minutes.\n\n"
            .'If you did not request a password reset, no further action is required.';

        app(\App\Services\EmailService::class)->sendNew(
            $this->email,
            "Reset Your {$companyName} Portal Password",
            $body,
            $this->full_name,
        );
    }

    // ── Accessors ──

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getMailboxSizeFormattedAttribute(): ?string
    {
        return $this->mailbox_size_bytes !== null
            ? \App\Support\Format::bytes($this->mailbox_size_bytes)
            : null;
    }

    /**
     * All email addresses for this person (primary + additional), deduped and lowercased.
     */
    public function allEmailAddresses(): array
    {
        $emails = [];

        if ($this->email) {
            $emails[] = mb_strtolower(trim($this->email));
        }

        foreach ($this->emailAddresses as $pe) {
            $emails[] = $pe->email;
        }

        return array_values(array_unique($emails));
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function () {
            // A synced M365 profile photo (CIPP) is the real person's face — prefer it
            // over the Gravatar fallback.
            if ($this->avatar_path) {
                return Storage::disk('public')->url($this->avatar_path);
            }

            return $this->email ? AvatarHelper::gravatarUrl($this->email) : null;
        });
    }
}
