<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One entry in the global tag vocabulary. Links to people, clients and assets
 * live in per-entity pivots, reached through each entity's tags() relation and
 * written only through App\Services\TagService. There is deliberately no inverse
 * relation here, so no tag-side attach path exists for the writer guard to miss.
 */
class Tag extends Model
{
    /**
     * The closed colour list: the semantic and brand-navy entries of DESIGN.md's
     * palette. Signal gold is excluded on purpose (the Signal Gold Rule).
     */
    public const COLORS = ['navy', 'info', 'success', 'warning', 'danger', 'neutral'];

    public const DEFAULT_COLOR = 'neutral';

    public const NAME_MAX = 64;

    protected $fillable = [
        'name',
        'color',
        'is_active',
        'created_by',
    ];

    protected $attributes = [
        'color' => self::DEFAULT_COLOR,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $tag): void {
            $name = self::normalizeName((string) $tag->name);
            if ($name === '') {
                throw new InvalidArgumentException('A tag name cannot be blank.');
            }
            if (mb_strlen($name) > self::NAME_MAX) {
                throw new InvalidArgumentException('A tag name can be at most '.self::NAME_MAX.' characters.');
            }
            if (! in_array($tag->color, self::COLORS, true)) {
                throw new InvalidArgumentException('Unknown tag colour.');
            }

            $tag->name = $name;
            $tag->name_key = self::nameKey($name);
        });
    }

    /** Trim and collapse internal whitespace; the display form keeps its case. */
    public static function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** The case-insensitive uniqueness key stored in tags.name_key. */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(self::normalizeName($name));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
