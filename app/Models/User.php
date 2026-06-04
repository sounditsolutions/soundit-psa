<?php

namespace App\Models;

use App\Enums\NotificationEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'microsoft_id',
        'notification_preferences',
        'is_active',
        'is_contractor',
        'email_signature',
        'avatar_path',
        'entra_avatar_fetched_at',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'notification_preferences' => 'array',
            'is_active' => 'boolean',
            'is_contractor' => 'boolean',
            'entra_avatar_fetched_at' => 'datetime',
        ];
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function () {
            if ($this->avatar_path) {
                return Storage::disk('public')->url($this->avatar_path);
            }

            if ($this->entra_avatar_fetched_at) {
                return Storage::disk('public')->url("avatars/entra_{$this->id}.jpg");
            }

            return null;
        });
    }

    public function wantsNotification(NotificationEventType $type): bool
    {
        $prefs = $this->notification_preferences;

        if ($prefs === null) {
            return $type->defaultEnabled();
        }

        return $prefs[$type->value] ?? $type->defaultEnabled();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeContractor(Builder $query): Builder
    {
        return $query->where('is_contractor', true);
    }

    public function sipEndpoints(): HasMany
    {
        return $this->hasMany(SipEndpoint::class);
    }

    public function contractorTimeTransactions(): HasMany
    {
        return $this->hasMany(ContractorTimeTransaction::class);
    }
}
