<?php

namespace App\Support;

use App\Models\Ticket;

/**
 * Shared assembly for the ticket-list MCP tools (psa-717bn category +
 * psa-ti6n9 pagination). Every staff ticket-list tool consults the same seam so
 * the answer shape is identical across the Assistant queue tools and the Triage
 * client-scoped tools: the uniform ToolPagination limit/offset contract, the
 * per-row ITIL category fields, and the {tickets, pagination} envelope.
 *
 * ToolPagination stays a pure limit/offset helper; this trait owns the
 * ticket-list-specific assembly (category eager-load + per-row merge + envelope).
 */
trait PaginatesTicketLists
{
    /**
     * Page a fully-filtered, ordered ticket query and shape it for an MCP tool
     * result. The caller passes the query BEFORE any limit/offset/get — this
     * applies the uniform page window, eager-loads the category ancestor chain
     * (so pathString() never N+1s; taxonomy depth <= 3), merges each row's
     * category fields, and wraps the page in the standard envelope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  filtered + ordered; no limit/offset/get applied
     * @param  array  $input  raw tool input (limit/offset read via ToolPagination: default 20, hard max 100)
     * @param  array  $select  column allowlist to fetch (category_id is added automatically for categoryFields)
     * @param  callable(Ticket): array  $mapRow  shapes each row; its category_id/category_path are merged in
     * @return array{tickets: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    protected function paginatedTicketList($query, array $input, array $select, callable $mapRow): array
    {
        $limit = ToolPagination::limit($input);
        $offset = ToolPagination::offset($input);

        // total counts the whole result set before the page slice, so
        // has_more/total describe the query, not the page. Clone so counting
        // doesn't consume the builder we then page.
        $total = (clone $query)->count();

        // category_id is the categoryNode FK — it must be selected even though
        // callers don't emit it directly, or categoryFields() reads null.
        if (! in_array('category_id', $select, true)) {
            $select[] = 'category_id';
        }

        $rows = $query->with('categoryNode.parent.parent')
            ->offset($offset)
            ->limit($limit)
            ->get($select)
            ->map(fn (Ticket $t) => $mapRow($t) + $t->categoryFields())
            ->toArray();

        return [
            'tickets' => $rows,
            'pagination' => ToolPagination::meta($total, $limit, $offset, count($rows)),
        ];
    }
}
