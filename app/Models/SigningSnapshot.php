<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SigningSnapshot extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'signed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('signing_snapshot_immutable'));
        static::deleting(fn () => throw new LogicException('signing_snapshot_immutable'));
    }
}
