<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshAllowRuleReaper;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * MeshAllowRuleReaper::reap() on the guarded arm — Mesh not configured.
 *
 * The reaper returns before any work when MeshWriteClient::isConfigured() is
 * false (no api_key). This drives that arm through the REAL reaper and the
 * REAL client (an empty config; the Guzzle mock handler is there only so no
 * network could be reached if the guard were ever removed) and captures the
 * warning it emits.
 *
 * The assertion is on the hedge clause only — "are not being removed and may
 * still be live upstream" — not the whole literal. The full sentence is not a
 * contract; what is graded (K5qwIx3B fix #15, Jeeves 2026-09-24) is that the
 * warning no longer asserts as fact that expired rules REMAIN live upstream:
 * a reapable STATE_UNRESOLVED row may have no upstream rule at all, a rule can
 * be removed by hand in the Mesh portal, and with zero expired rows there is
 * nothing to remain. "Not being removed" is true unconditionally on this arm.
 */
class MeshAllowRuleReaperNotConfiguredTest extends TestCase
{
    private function unconfiguredReaper(): MeshAllowRuleReaper
    {
        $handler = new MockHandler([]);
        $http = new GuzzleClient(['handler' => HandlerStack::create($handler)]);

        // No api_key => isConfigured() is false. Real client, real reaper.
        return new MeshAllowRuleReaper(new MeshWriteClient([], $http));
    }

    public function test_not_configured_warning_hedges_rather_than_asserting_rules_remain_live(): void
    {
        $captured = [];
        Log::shouldReceive('warning')->andReturnUsing(function (string $message) use (&$captured): void {
            $captured[] = $message;
        });

        $counts = $this->unconfiguredReaper()->reap();

        // The guard returns the untouched zero counts and does no work.
        $this->assertSame(['examined' => 0, 'reaped' => 0, 'unresolved' => 0, 'failed' => 0], $counts);

        $this->assertCount(1, $captured, 'exactly one warning on the guarded arm');
        // #6213: 'no usable API key' holds for a missing and for an unusable key.
        $this->assertStringStartsWith('[MeshAllowRuleReaper] Mesh has no usable API key;', $captured[0]);

        // Hedge clause only — deliberately not the whole literal (see class docblock).
        $this->assertStringContainsString('are not being removed and may still be live upstream', $captured[0]);

        // The overclaim this fix removes must not come back.
        $this->assertStringNotContainsString('rules remain live upstream', $captured[0]);
    }
}
