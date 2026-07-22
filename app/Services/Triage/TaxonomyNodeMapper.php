<?php

namespace App\Services\Triage;

use App\Models\TicketCategory;
use Illuminate\Support\Facades\Log;

/**
 * so-0ftg Part 4 — resolves the legacy free-text classification the triage
 * loop already produces (config/tickets.php 'categories' pair) to a node in
 * the ticket_categories taxonomy, via the operator-editable
 * config('tickets.taxonomy_map') rate card of confident pairs.
 *
 * NOT a classifier: no AI call, no guessing. A pair either has a configured
 * path whose every segment resolves to an ACTIVE node by name, or the answer
 * is null and the ticket stays a visible gap (the locked design's "coarse map
 * to top-volume leaves; rest degrade to gap").
 *
 * Name matching is case-insensitive with one trailing "(...)" group ignored
 * on both sides, so "Email security & quarantine (Mesh)" in the DB matches
 * the shorter config entry. Nothing fuzzier than that — a categorization that
 * silently lands on the wrong SOP is worse than an honest gap.
 */
class TaxonomyNodeMapper
{
    /**
     * Resolve a legacy (category, subcategory) pair to its mapped taxonomy
     * node, or null when the pair is unmapped or the path doesn't resolve.
     */
    public static function resolve(?string $category, ?string $subcategory): ?TicketCategory
    {
        $path = self::configuredPathFor($category, $subcategory);
        if ($path === null) {
            return null;
        }

        $node = self::walk($path);
        if ($node === null) {
            Log::info('[Triage] Taxonomy map entry did not resolve — degrading to gap', [
                'category' => $category,
                'subcategory' => $subcategory,
                'path' => $path,
            ]);
        }

        return $node;
    }

    /**
     * The configured name path for a pair: the subcategory's own entry first,
     * then the category-level '' fallback. Null = deliberately unmapped.
     *
     * @return list<string>|null
     */
    private static function configuredPathFor(?string $category, ?string $subcategory): ?array
    {
        if ($category === null || $category === '') {
            return null;
        }

        $entries = config('tickets.taxonomy_map', []);
        $forCategory = $entries[$category] ?? null;
        if (! is_array($forCategory)) {
            return null;
        }

        $path = null;
        if ($subcategory !== null && $subcategory !== '' && array_key_exists($subcategory, $forCategory)) {
            $path = $forCategory[$subcategory];
        } elseif (array_key_exists('', $forCategory)) {
            $path = $forCategory[''];
        }

        if ($path === null) {
            return null;
        }

        // A malformed entry is an operator config error — scream, then gap.
        if (! is_array($path) || $path === [] || count($path) > 3) {
            Log::warning('[Triage] Malformed taxonomy_map entry (want 1-3 node names)', [
                'category' => $category,
                'subcategory' => $subcategory,
                'entry' => $path,
            ]);

            return null;
        }
        foreach ($path as $segment) {
            if (! is_string($segment) || trim($segment) === '') {
                Log::warning('[Triage] Malformed taxonomy_map path segment', [
                    'category' => $category,
                    'subcategory' => $subcategory,
                    'entry' => $path,
                ]);

                return null;
            }
        }

        return array_values($path);
    }

    /**
     * Walk the name path from the roots down. Every segment must match an
     * ACTIVE node under the previous one; any miss resolves the whole path
     * to null. Ties (duplicate normalized names at one level) break by the
     * tree's own display order, deterministically.
     *
     * @param  list<string>  $path
     */
    private static function walk(array $path): ?TicketCategory
    {
        $parentId = null;
        $node = null;

        foreach ($path as $segment) {
            $wanted = self::normalizeName($segment);

            $node = TicketCategory::query()
                ->active()
                ->where('parent_id', $parentId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->first(fn (TicketCategory $candidate) => self::normalizeName($candidate->name) === $wanted);

            if ($node === null) {
                return null;
            }

            $parentId = $node->id;
        }

        return $node;
    }

    /**
     * Case-insensitive, trimmed, with ONE trailing parenthetical group
     * dropped: "Email security & quarantine (Mesh)" and "email security &
     * quarantine" normalize identically. Applied to both config and DB names.
     */
    private static function normalizeName(string $name): string
    {
        $stripped = preg_replace('/\s*\([^()]*\)\s*$/u', '', $name) ?? $name;

        return mb_strtolower(trim($stripped), 'UTF-8');
    }
}
