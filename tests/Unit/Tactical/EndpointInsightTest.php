<?php

namespace Tests\Unit\Tactical;

use App\Services\Tactical\EndpointInsight;
use App\Services\Tactical\FailingCheck;
use App\Services\Tactical\SignalState;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Amendment A (P4): EndpointInsight is the P5-serialization CONTRACT — the
 * normalized shape TacticalContextProvider will serialize WITHOUT re-fetching.
 * These tests pin the readonly shape, the deterministic flags (computed here,
 * never invented by the model), the per-section availability enum, and the
 * plain-text-friendly accessors.
 */
class EndpointInsightTest extends TestCase
{
    private function insight(array $overrides = []): EndpointInsight
    {
        return new EndpointInsight(...array_merge([
            'linked' => true,
            'agentId' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'status' => 'online',
            'statusState' => SignalState::Snapshot,
            'lastSeen' => Carbon::now()->subMinutes(5),
            'uptime' => '3d 5h',
            'cpu' => 'Intel i7',
            'ramGb' => 16.0,
            'diskSummary' => 'C: 256GB',
            'diskVolumes' => [],
            'needsReboot' => false,
            'lowDisk' => false,
            'longOffline' => false,
            'stale' => false,
            'maintenance' => false,
            'userLoggedIn' => false,
            'failingChecks' => [],
            'checksState' => SignalState::Snapshot,
            'checksFailing' => 0,
            'checksTotal' => 3,
            'openAlerts' => 0,
            'openAlertList' => [],
            'pendingPatchCount' => 0,
            'recentActions' => [],
            'freshAsOf' => Carbon::now()->subMinutes(5),
        ], $overrides));
    }

    public function test_not_linked_insight_is_a_clear_no_throw_shape(): void
    {
        $insight = EndpointInsight::notLinked();

        $this->assertFalse($insight->linked);
        $this->assertNull($insight->status);
        // Every availability marker is Unavailable, never a clean-looking empty.
        $this->assertSame(SignalState::Unavailable, $insight->statusState);
        $this->assertSame(SignalState::Unavailable, $insight->checksState);
        $this->assertSame(0, $insight->openAlerts);
    }

    public function test_carries_the_p5_essential_fields(): void
    {
        $insight = $this->insight([
            'ramGb' => 16.0,
            'uptime' => '3d 5h',
            'pendingPatchCount' => 4,
            'userLoggedIn' => true,
        ]);

        $this->assertTrue($insight->linked);
        $this->assertSame('online', $insight->status);
        $this->assertSame(16.0, $insight->ramGb);
        $this->assertSame('3d 5h', $insight->uptime);
        $this->assertSame(4, $insight->pendingPatchCount);
        $this->assertTrue($insight->userLoggedIn);
    }

    public function test_failing_check_carries_raw_unclipped_stdout(): void
    {
        // P5 clips to its own ~200-char budget; the UI clips for display. The
        // value object must NOT pre-clip.
        $longStdout = str_repeat('disk error line; ', 100); // ~1700 chars
        $check = new FailingCheck(
            name: 'Disk C',
            status: 'failing',
            retcode: 1,
            stdout: $longStdout,
        );

        $insight = $this->insight(['failingChecks' => [$check]]);

        $this->assertCount(1, $insight->failingChecks);
        $this->assertSame($longStdout, $insight->failingChecks[0]->stdout);
        $this->assertGreaterThan(1000, mb_strlen($insight->failingChecks[0]->stdout));
    }

    public function test_unavailable_checks_is_not_zero_clean(): void
    {
        // Amendment A / §11.7: "couldn't fetch checks" (Unavailable) must NOT read
        // as "0 failing checks" (clean).
        $insight = $this->insight([
            'failingChecks' => [],
            'checksState' => SignalState::Unavailable,
            'checksFailing' => null,
            'checksTotal' => null,
        ]);

        $this->assertSame(SignalState::Unavailable, $insight->checksState);
        $this->assertNull($insight->checksFailing);
        $this->assertFalse($insight->checksKnownClean());
    }

    public function test_checks_known_clean_only_when_loaded_and_zero(): void
    {
        $clean = $this->insight([
            'checksState' => SignalState::Live,
            'checksFailing' => 0,
            'checksTotal' => 5,
        ]);
        $this->assertTrue($clean->checksKnownClean());

        $failing = $this->insight([
            'checksState' => SignalState::Live,
            'checksFailing' => 2,
            'checksTotal' => 5,
        ]);
        $this->assertFalse($failing->checksKnownClean());
    }

    public function test_deterministic_flags_are_plain_booleans(): void
    {
        $insight = $this->insight([
            'needsReboot' => true,
            'lowDisk' => true,
            'longOffline' => true,
            'stale' => true,
        ]);

        $this->assertTrue($insight->needsReboot);
        $this->assertTrue($insight->lowDisk);
        $this->assertTrue($insight->longOffline);
        $this->assertTrue($insight->stale);
    }

    public function test_flatten_to_text_is_plain_text_not_json(): void
    {
        // Amendment A / §11.1: P5 redacts FLATTENED PLAIN TEXT (never json_encode,
        // which slips PEM/connection-strings past the redactor). The value object
        // offers a plain-text rendering of its secret-bearing free-text members.
        $check = new FailingCheck('Disk C', 'failing', 1, 'output: all good');
        $insight = $this->insight([
            'hostname' => 'BOX-1',
            'failingChecks' => [$check],
        ]);

        $text = $insight->toPlainText();

        $this->assertIsString($text);
        $this->assertStringNotContainsString('{', $text);
        $this->assertStringContainsString('BOX-1', $text);
        $this->assertStringContainsString('Disk C', $text);
        $this->assertStringContainsString('output: all good', $text);
    }

    public function test_user_logged_in_is_a_bool_not_the_raw_username(): void
    {
        // §11.6 PII: the AI-facing member is a boolean. There is no public
        // username accessor the P5 serializer could read.
        $insight = $this->insight(['userLoggedIn' => true]);

        $this->assertIsBool($insight->userLoggedIn);
        $this->assertStringNotContainsString('username', strtolower(json_encode(array_keys(get_object_vars($insight)))));
    }
}
