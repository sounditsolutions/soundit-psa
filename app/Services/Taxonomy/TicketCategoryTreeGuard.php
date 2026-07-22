<?php

namespace App\Services\Taxonomy;

use App\Models\TicketCategory;

/**
 * Write-layer guardian of the so-0ftg taxonomy tree shape. The schema
 * deliberately does not enforce the depth <= 3 invariant (Category ->
 * Subcategory -> Item/Symptom) or acyclicity — TicketCategory's docblock
 * delegates both to the write layers, and this class is that check, shared
 * by every surface that creates, re-parents, or reactivates nodes (staff
 * UI and MCP CRUD tools).
 *
 * Retired (is_active = false) nodes are refused as attachment targets on
 * both write doors (tree-policy ruling, psa-m4bki): retirement stays soft —
 * retiring a node never detaches its existing children — but no NEW node
 * may be created under or moved under a retired parent. Reactivation is
 * guarded the same way (psa-9mirx ruling): a retired node may not come
 * back to life while its own parent is retired — reactivate the parent
 * first, or reparent the node.
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

        if (! $parent->is_active) {
            return 'A category cannot be placed under a retired parent.';
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

    /**
     * Why flipping $node back to active would break tree policy, or null when
     * the reactivation is legal. Refused only while the node's own parent is
     * retired (psa-9mirx); roots and nodes under active parents reactivate
     * freely. This governs the node being REVIVED — a node that is already
     * active under a retired parent is soft-retirement's legal state and is
     * not this method's concern.
     */
    public function reactivationError(TicketCategory $node): ?string
    {
        $parent = $node->parent;
        if ($parent !== null && ! $parent->is_active) {
            return 'The parent category "'.$parent->name.'" is retired. Reactivate it first, or reparent this node.';
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
