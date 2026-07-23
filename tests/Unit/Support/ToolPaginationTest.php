<?php

namespace Tests\Unit\Support;

use App\Support\ToolPagination;
use Tests\TestCase;

/**
 * Uniform limit/offset contract for the ticket-list MCP tools (psa-ti6n9):
 * a caller-settable limit with a sane hard ceiling (never unbounded) + offset
 * paging + a has_more signal. Pure logic — no DB.
 */
class ToolPaginationTest extends TestCase
{
    public function test_limit_defaults_when_absent(): void
    {
        $this->assertSame(20, ToolPagination::limit([]));
        $this->assertSame(15, ToolPagination::limit([], 15));   // per-tool default override
    }

    public function test_limit_is_hard_capped_at_100(): void
    {
        $this->assertSame(100, ToolPagination::limit(['limit' => 100]));
        $this->assertSame(100, ToolPagination::limit(['limit' => 5000])); // never unbounded
    }

    public function test_limit_honours_a_raised_request_below_the_cap(): void
    {
        // The point of the feature: a caller can now ask for more than the old 20-50.
        $this->assertSame(75, ToolPagination::limit(['limit' => 75]));
    }

    public function test_limit_floors_at_one_and_ignores_garbage(): void
    {
        $this->assertSame(1, ToolPagination::limit(['limit' => 0]));
        $this->assertSame(1, ToolPagination::limit(['limit' => -10]));
        $this->assertSame(20, ToolPagination::limit(['limit' => 'abc']));
        $this->assertSame(20, ToolPagination::limit(['limit' => null]));
    }

    public function test_offset_defaults_to_zero_and_floors_at_zero(): void
    {
        $this->assertSame(0, ToolPagination::offset([]));
        $this->assertSame(0, ToolPagination::offset(['offset' => -5]));
        $this->assertSame(40, ToolPagination::offset(['offset' => 40]));
        $this->assertSame(0, ToolPagination::offset(['offset' => 'nope']));
    }

    public function test_meta_reports_has_more_from_actual_rows_returned(): void
    {
        // 50 total, first page of 20 → more remain.
        $m = ToolPagination::meta(total: 50, limit: 20, offset: 0, returned: 20);
        $this->assertSame(['total' => 50, 'limit' => 20, 'offset' => 0, 'returned' => 20, 'has_more' => true], $m);

        // last partial page → no more.
        $m = ToolPagination::meta(total: 50, limit: 20, offset: 40, returned: 10);
        $this->assertFalse($m['has_more']);

        // exact boundary (offset+returned == total) → no more.
        $m = ToolPagination::meta(total: 40, limit: 20, offset: 20, returned: 20);
        $this->assertFalse($m['has_more']);

        // empty set → no more.
        $this->assertFalse(ToolPagination::meta(total: 0, limit: 20, offset: 0, returned: 0)['has_more']);
    }
}
