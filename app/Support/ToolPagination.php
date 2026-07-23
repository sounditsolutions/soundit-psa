<?php

namespace App\Support;

/**
 * Uniform limit/offset pagination contract for the ticket-list MCP tools
 * (psa-ti6n9, pairs with psa-717bn). Charlie asked that the "various ticket
 * list tools" allow a settable max-results + pagination. This is the single
 * DRY seam every ticket-list executor consults so the contract stays identical
 * across the staff and portal surfaces:
 *   - limit: caller-settable, floored at 1, HARD-capped at MAX_LIMIT so an
 *     agent can never pull an unbounded, context-blowing page — page instead.
 *   - offset: caller-settable, floored at 0.
 *   - meta(): the {total, limit, offset, returned, has_more} envelope so a
 *     caller knows whether to page again.
 */
class ToolPagination
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 100;

    /** Resolve the requested page size, clamped to [1, MAX_LIMIT]. */
    public static function limit(array $input, int $default = self::DEFAULT_LIMIT): int
    {
        $raw = $input['limit'] ?? null;
        $n = is_numeric($raw) ? (int) $raw : $default;

        return max(1, min($n, self::MAX_LIMIT));
    }

    /** Resolve the requested offset, floored at 0. */
    public static function offset(array $input): int
    {
        $raw = $input['offset'] ?? null;
        $n = is_numeric($raw) ? (int) $raw : 0;

        return max(0, $n);
    }

    /**
     * Pagination envelope meta. has_more is derived from the rows ACTUALLY
     * returned, so a short final page reads has_more=false even if it happened
     * to equal the limit.
     */
    public static function meta(int $total, int $limit, int $offset, int $returned): array
    {
        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'returned' => $returned,
            'has_more' => ($offset + $returned) < $total,
        ];
    }
}
