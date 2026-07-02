<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only activity/audit row for Alerts Hub destination and route changes.
 */
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

    protected static function booted(): void
    {
        static::updating(function (self $log): void {
            throw new LogicException('signal_config_log is append-only');
        });

        static::deleting(function (self $log): void {
            throw new LogicException('signal_config_log is append-only');
        });
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
