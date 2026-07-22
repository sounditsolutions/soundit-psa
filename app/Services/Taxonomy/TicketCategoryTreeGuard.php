<?php

namespace App\Services\Taxonomy;

use App\Models\TicketCategory;

/**
 * Write-layer guardian of the so-0ftg taxonomy tree shape. The schema
 * deliberately does not enforce the depth <= 3 invariant (Category ->
 * Subcategory -> Item/Symptom) or acyclicity — TicketCategory's docblock
 * delegates both to the write layers, and this class is that check, shared
 * by every surface that creates or re-parents nodes (staff UI now, MCP
 * CRUD tools later).
 */
class TicketCategoryTreeGuard
{
    public const MAX_DEPTH = 3;

    /**
     * Why attaching $node under $parent would break the tree, or null when
     * the attachment is legal. $node null = a brand-new node (height 1);
     * $parent null = making the node a root, which is always legal.
     */
    public function attachmentError(?TicketCategory $node, ?TicketCategory $parent): ?string
    {
        if ($parent === null) {
            return null;
        }

        if ($node !== null && $parent->id === $node->id) {
            return 'A category cannot be its own parent.';
        }

        if ($node !== null && $node->descendants()->contains('id', $parent->id)) {
            return 'A category cannot be moved under one of its own descendants.';
        }

        $height = $node === null ? 1 : $this->subtreeHeight($node);
        if ($parent->depth() + $height > self::MAX_DEPTH) {
            return sprintf(
                'This move would exceed the maximum tree depth of %d (Category / Subcategory / Item).',
                self::MAX_DEPTH
            );
        }

        return null;
    }

    /** Height of the subtree rooted at $node: 1 for a leaf, up to MAX_DEPTH. */
    public function subtreeHeight(TicketCategory $node): int
    {
        $children = $node->children;
        if ($children->isEmpty()) {
            return 1;
        }

        return 1 + $children->max(fn (TicketCategory $child) => $this->subtreeHeight($child));
    }
}
