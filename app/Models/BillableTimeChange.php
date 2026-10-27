<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class BillableTimeChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'entry_type', 'entry_id', 'old_billable_minutes', 'new_billable_minutes',
        'reason', 'source', 'actor_user_id', 'actor_label',
    ];

    protected function casts(): array
    {
        return [
            'entry_id' => 'integer',
            'old_billable_minutes' => 'integer',
            'new_billable_minutes' => 'integer',
            'actor_user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Billable time audit models cannot be updated.');
        });
        static::deleting(function () {
            throw new LogicException('Billable time audit models cannot be deleted.');
        });
    }
}
