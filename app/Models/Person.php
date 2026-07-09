<?php

namespace App\Models;

use App\Enums\PersonType;
use App\Support\AvatarHelper;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

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
        return Attribute::get(fn () => $this->email ? AvatarHelper::gravatarUrl($this->email) : null);
    }

    /**
     * Display-formatted phone. `phone_display` is a denormalized column that
     * PersonService writes alongside `phone`, but rows created outside that
     * path (seeders, imports, pre-normalization syncs) store the raw number
     * with a null display. Since every view renders the display column, fall
     * back to formatting the raw `phone` so the contact's number is never
     * blank when a number is actually stored (psa-klwu).
     */
    protected function phoneDisplay(): Attribute
    {
        return Attribute::get(
            fn (?string $value) => $value ?: ($this->phone ? PhoneNumber::format($this->phone) : null),
        );
    }

    /**
     * Display-formatted mobile — same denormalization fallback as
     * {@see phoneDisplay()} for the `mobile`/`mobile_display` pair.
     */
    protected function mobileDisplay(): Attribute
    {
        return Attribute::get(
            fn (?string $value) => $value ?: ($this->mobile ? PhoneNumber::format($this->mobile) : null),
        );
    }
}
