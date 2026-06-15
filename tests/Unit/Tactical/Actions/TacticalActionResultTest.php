<?php

namespace Tests\Unit\Tactical\Actions;

use App\Services\Tactical\Actions\TacticalActionResult;
use PHPUnit\Framework\TestCase;

/**
 * Task 3 (P2): the normalized action result value object.
 *
 * Status set (amendment m2): ok|offline|error|denied|rejected|blocked, with
 * `rejected` (invalid params) and `blocked` (missing confirm) kept DISTINCT
 * from generic `error`.
 */
class TacticalActionResultTest extends TestCase
{
    public function test_ok_factory(): void
    {
        $r = TacticalActionResult::ok('hello world', 0);

        $this->assertSame('ok', $r->status);
        $this->assertSame('hello world', $r->stdout);
        $this->assertSame(0, $r->retcode);
        $this->assertTrue($r->isOk());
        $this->assertFalse($r->isOffline());
    }

    public function test_ok_retcode_defaults_to_zero(): void
    {
        $r = TacticalActionResult::ok('done');

        $this->assertSame(0, $r->retcode);
        $this->assertTrue($r->isOk());
    }

    public function test_offline_factory(): void
    {
        $r = TacticalActionResult::offline('agent offline');

        $this->assertSame('offline', $r->status);
        $this->assertSame('agent offline', $r->message);
        $this->assertNull($r->stdout);
        $this->assertNull($r->retcode);
        $this->assertFalse($r->isOk());
        $this->assertTrue($r->isOffline());
    }

    public function test_error_factory(): void
    {
        $r = TacticalActionResult::error('upstream 500');

        $this->assertSame('error', $r->status);
        $this->assertSame('upstream 500', $r->message);
        $this->assertFalse($r->isOk());
        $this->assertFalse($r->isOffline());
    }

    public function test_denied_factory(): void
    {
        $r = TacticalActionResult::denied('not authorized');

        $this->assertSame('denied', $r->status);
        $this->assertSame('not authorized', $r->message);
        $this->assertFalse($r->isOk());
    }

    public function test_rejected_factory_is_distinct_from_error(): void
    {
        $r = TacticalActionResult::rejected('invalid params: missing script');

        $this->assertSame('rejected', $r->status);
        $this->assertSame('invalid params: missing script', $r->message);
        $this->assertFalse($r->isOk());
    }

    public function test_blocked_factory_is_distinct_from_error(): void
    {
        $r = TacticalActionResult::blocked('confirmation required');

        $this->assertSame('blocked', $r->status);
        $this->assertSame('confirmation required', $r->message);
        $this->assertFalse($r->isOk());
    }

    public function test_audit_shape_for_the_log_row(): void
    {
        $r = TacticalActionResult::ok('the output', 0);

        $audit = $r->audit();

        $this->assertSame('ok', $audit['result_status']);
        $this->assertSame('the output', $audit['output']);
        $this->assertSame(0, $audit['retcode']);
        $this->assertArrayHasKey('message', $audit);
        $this->assertNull($audit['message']);
    }

    public function test_audit_shape_for_a_failure(): void
    {
        $r = TacticalActionResult::offline('agent offline');

        $audit = $r->audit();

        $this->assertSame('offline', $audit['result_status']);
        $this->assertNull($audit['output']);
        $this->assertNull($audit['retcode']);
        $this->assertSame('agent offline', $audit['message']);
    }
}
