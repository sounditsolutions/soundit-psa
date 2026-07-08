<?php

namespace App\Models;

use App\Enums\PhoneDirectoryListType;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneDirectoryEntry extends Model
{
    protected $table = 'phone_directory';

    protected $fillable = [
        'phone_number',
        'list_type',
        'label',
        'reason',
        'added_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'list_type' => PhoneDirectoryListType::class,
        ];
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /**
     * Look up the directory entry for a raw phone number, regardless of list type.
     * Normalizes to E.164 before checking. Returns null on unparseable input.
     */
    public static function lookup(?string $rawNumber): ?self
    {
        $normalized = PhoneNumber::normalize($rawNumber);
        if (! $normalized) {
            return null;
        }

        return self::where('phone_number', $normalized)->first();
    }

    public function isBlocked(): bool
    {
        return $this->list_type === PhoneDirectoryListType::Blocked;
    }

    public function isAllowed(): bool
    {
        return $this->list_type === PhoneDirectoryListType::Allowed;
    }
}
