<?php

namespace App\Support;

use App\Models\RecentItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RecentItems
{
    const MAX_ITEMS = 15;

    /** Per-request cache to avoid re-querying in sidebar + command palette */
    private static ?Collection $cache = null;

    private static ?int $cacheUserId = null;

    /**
     * Track a recently visited entity.
     * Upserts on [user_id, item_type, item_id], then prunes beyond MAX_ITEMS.
     */
    public static function track(int $userId, string $type, int $id, string $label, string $url): void
    {
        try {
            RecentItem::upsert(
                [[
                    'user_id' => $userId,
                    'item_type' => $type,
                    'item_id' => $id,
                    'label' => mb_substr($label, 0, 100),
                    'url' => $url,
                    'visited_at' => now(),
                ]],
                ['user_id', 'item_type', 'item_id'],
                ['label', 'url', 'visited_at']
            );

            // Prune old entries beyond MAX_ITEMS
            $keepIds = RecentItem::where('user_id', $userId)
                ->orderByDesc('visited_at')
                ->limit(self::MAX_ITEMS)
                ->pluck('id');

            if ($keepIds->isNotEmpty()) {
                RecentItem::where('user_id', $userId)
                    ->whereNotIn('id', $keepIds)
                    ->delete();
            }

            // Invalidate per-request cache
            static::$cache = null;
            static::$cacheUserId = null;
        } catch (\Throwable $e) {
            Log::warning('[RecentItems] Failed to track item', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get recent items for a user, ordered by most recent first.
     */
    public static function get(int $userId): Collection
    {
        if (static::$cache !== null && static::$cacheUserId === $userId) {
            return static::$cache;
        }

        static::$cacheUserId = $userId;
        static::$cache = RecentItem::where('user_id', $userId)
            ->orderByDesc('visited_at')
            ->limit(self::MAX_ITEMS)
            ->get();

        return static::$cache;
    }

    /** Icon mapping for sidebar display */
    public static function iconFor(string $type): string
    {
        return match ($type) {
            'ticket' => 'bi-ticket-perforated',
            'client' => 'bi-building',
            'person' => 'bi-person',
            'asset' => 'bi-pc-display',
            'contract' => 'bi-file-earmark-text',
            default => 'bi-clock-history',
        };
    }
}
