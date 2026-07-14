<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * D4 global per-type master toggle overlay for the Alerts-Hub relay matrix (psa-0j6i).
 *
 * One row per signal event type_key that an operator has explicitly toggled. ABSENCE means
 * enabled — so the feature ships dormant: with no rows, every type is globally enabled and
 * nothing about routing changes. A disabled row is the true master gate for that type: the
 * matrix write path refuses to relay it, and SignalRouter short-circuits its delivery.
 *
 * Deliberately an overlay, NOT a column on SignalEventTypes.php — that catalog array is
 * asserted byte-for-byte by a test and must stay static.
 */
class SignalEventTypeSetting extends Model
{
    protected $fillable = [
        'type_key',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * Is this type globally enabled for relay? Default TRUE when no overlay row exists, so
     * absence is enabled and the master toggle is opt-in-to-disable.
     */
    public static function isTypeGloballyEnabled(string $typeKey): bool
    {
        $row = static::query()->where('type_key', $typeKey)->first();

        return $row === null ? true : (bool) $row->enabled;
    }

    /** Upsert the master toggle for a type — one row per type_key, latest wins. */
    public static function setGlobalEnabled(string $typeKey, bool $enabled): self
    {
        return static::query()->updateOrCreate(
            ['type_key' => $typeKey],
            ['enabled' => $enabled],
        );
    }
}
