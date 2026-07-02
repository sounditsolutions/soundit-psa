<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignalConfigLog extends Model
{
    protected $table = 'signal_config_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'changes',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public static function record(?int $userId, string $action, Model $subject, array $changes): void
    {
        static::create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'changes' => $changes,
        ]);
    }
}
