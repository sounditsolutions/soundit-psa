<?php

namespace Tests\Feature\Mcp;

use App\Services\Mcp\StaffCippWriteToolExecutor;
use Tests\TestCase;

/**
 * #3901 / yf7IXuhQ effect-denial sweep — the five CIPP kill-switch decline
 * messages.
 *
 * These five arms were adjudicated on 2026-09-26 as TRUE on every path: each
 * checks the kill switch, writes an audit row, and declines before any client
 * call (measured, with a positive control finding 7 `->client->` calls past the
 * kill switch in the same method). No wording change was needed on those five.
 *
 * "Five" is the count of kill-switch arms that decline WITH A SENTENCE, not the
 * count of kill-switch arms (round 2, contract:12). A SIXTH exists:
 * approveEmailSecurityStagedRun checks the kill switch and returns a message-less
 * gate_declined, so the operator gets the cockpit's generic no-reason fallback
 * instead of a CIPP sentence. That arm is NOT adjudicated here and its shape is
 * not endorsed — it is the #3901 defect, and the wider tripwire below exists to
 * make a seventh of either kind visible.
 *
 * The gap Jeeves named is that nothing pinned them, so a later edit could put an
 * effect claim back into a message that currently has none, and no control would
 * notice. These are source-level assertions for that reason: the messages are
 * operator-facing text on a refusal path, and what matters is the claim each
 * literal makes, not a rendered flash.
 *
 * Each assertion is scoped to the enclosing method rather than the whole file, so
 * it cannot be satisfied by a byte-identical literal somewhere else. The wording
 * these arms are allowed to use is narrow by construction: the kill switch is
 * engaged and this staged write was refused. That is a statement about THIS
 * action, not about upstream state.
 */
class CippKillSwitchDeclineWordingTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function killSwitchArms(): array
    {
        return [
            'approveStagedRun' => ['approveStagedRun', 'Technician kill-switch engaged; the staged CIPP write was refused.'],
            'approveResetPasswordStagedRun' => ['approveResetPasswordStagedRun', 'Technician kill-switch engaged; the staged password reset was refused.'],
            'approveCreateUserStagedRun' => ['approveCreateUserStagedRun', 'Technician kill-switch engaged; the staged CIPP write was refused.'],
            'approveGroupMembershipStagedRun' => ['approveGroupMembershipStagedRun', 'Technician kill-switch engaged; the staged CIPP write was refused.'],
            'approveLicenseTargetStagedRun' => ['approveLicenseTargetStagedRun', 'Technician kill-switch engaged; the staged CIPP write was refused.'],
        ];
    }

    /** Source of the named method only, so a sibling's literal cannot satisfy it. */
    private function methodSource(string $method): string
    {
        $reflection = new \ReflectionMethod(StaffCippWriteToolExecutor::class, $method);
        $file = $reflection->getFileName();
        $this->assertNotFalse($file, 'The executor must be loadable from disk.');

        $lines = file($file);
        $this->assertNotFalse($lines);

        $start = $reflection->getStartLine() - 1;
        $length = $reflection->getEndLine() - $start;

        $source = implode('', array_slice($lines, $start, $length));

        // Precondition: the slice really is the method under test and really
        // reaches its kill-switch arm. Without this the assertions below could
        // pass over an empty or mis-sliced string.
        $this->assertStringContainsString('killSwitchEngaged', $source, "{$method} must contain its kill-switch check.");
        $this->assertStringContainsString('$this->declined(', $source, "{$method} must decline through the declined() helper.");

        return $source;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('killSwitchArms')]
    public function test_the_kill_switch_arm_declines_with_its_adjudicated_wording(string $method, string $expected): void
    {
        $this->assertStringContainsString(
            "\$this->declined('{$expected}')",
            $this->methodSource($method),
            "{$method}'s kill-switch decline must keep the wording adjudicated true on every arm."
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('killSwitchArms')]
    public function test_the_kill_switch_arm_claims_no_effect_and_prescribes_no_retry(string $method, string $expected): void
    {
        // Round 1 (diff:3, context:9, contract:9) caught this test asserting
        // against $expected — the test's OWN data-provider literal — and never
        // reading the executor at all. All 35 of its assertions passed no matter
        // what production contained. Proved by mutation before rewriting: an
        // effect claim and a retry instruction injected into the real declined()
        // literal at :964 left this case green (only the exact-match case above
        // failed). It now extracts the argument from the production source.
        $source = $this->methodSource($method);

        // ALL kill-switch literals in the method, not the first (round 2,
        // contract:13: preg_match stops at one, so a second arm added below the
        // first would never be inspected). `(?:[^'\\\\]|\\\\.)*` also tolerates an
        // escaped apostrophe, which `[^']*` would truncate mid-sentence and then
        // silently pass the deny-list on the fragment.
        $matched = preg_match_all(
            "/\\\$this->declined\('(Technician kill-switch engaged;(?:[^'\\\\]|\\\\.)*)'\)/",
            $source,
            $m
        );
        $this->assertGreaterThanOrEqual(
            1,
            $matched,
            "{$method} must contain a kill-switch declined() literal to inspect."
        );

        foreach ($m[1] as $actual) {
            // The property, stated independently of the exact sentence: a refusal on
            // this path may say the write was refused, and may not make a claim about
            // upstream state or tell the approver to repeat the action. The kill switch
            // has to be cleared first, so a retry instruction would be false here.
            foreach (['try again', 'retry', 'nothing was sent', 'no changes were made', 'no upstream call was made'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $actual,
                    "{$method}'s kill-switch decline must not claim '{$forbidden}'."
                );
            }

            // And it must still name the refusal, so deleting the message does not pass.
            $this->assertStringContainsStringIgnoringCase('refused', $actual);
            $this->assertStringContainsStringIgnoringCase('kill-switch', $actual);
        }
    }

    public function test_a_new_kill_switch_arm_of_either_shape_cannot_arrive_unread(): void
    {
        // Round 1 (diff:4, contract:7, context:10): the first version counted
        // copies of ONE literal spelling of declined('Technician kill-switch
        // engaged;...'), and claimed in its own comment that a sixth approval
        // path could not arrive unread. That was false in two directions. A new
        // arm using double quotes, a constant, sprintf, or the message-less
        // `new TechnicianApprovalResult('gate_declined')` form adds no match; and
        // one such arm ALREADY existed at the reviewed tip — approveEmailSecurity-
        // StagedRun's kill-switch check audits and returns message-less, so it
        // renders the cockpit fallback rather than a CIPP sentence.
        //
        // So the tripwire is re-anchored on the thing that actually matters: how
        // many kill-switch checks exist in this executor, whatever each one goes
        // on to return. Adding a kill-switch branch in ANY shape fails this.
        $reflection = new \ReflectionClass(StaffCippWriteToolExecutor::class);
        $file = $reflection->getFileName();
        $source = file_get_contents((string) $file);
        $this->assertNotFalse($source);

        $declinedArms = preg_match_all(
            "/\\\$this->declined\('Technician kill-switch engaged;/",
            $source
        );
        $this->assertSame(5, $declinedArms, 'Five CIPP kill-switch arms decline with an adjudicated sentence; read any new one before it ships.');

        // The wider anchor. 11 = the 5 sentence arms above + approveEmailSecurity-
        // StagedRun's message-less arm + 5 non-staged MCP write guards that return
        // ['error' => ...] rather than a TechnicianApprovalResult.
        //
        // Round 2 (context:10, contract:8) argued this count sweeps in comments and
        // the method definition. MEASURED, and it does not: all 11 matches are
        // `if (TechnicianConfig::killSwitchEngaged()) {` call sites in this file,
        // and the definition lives in TechnicianConfig, not here. The regex is
        // anchored on that statement form anyway, so a mention in a comment cannot
        // move it.
        //
        // The fair half of that finding is kept: this DOES trip on a refactor that
        // changes no arm. That is the intended trade — the failure message tells the
        // next person what to check and how to re-baseline, which is cheap; missing
        // a new unread arm is not.
        $killSwitchChecks = preg_match_all(
            '/if \(TechnicianConfig::killSwitchEngaged\(\)\) \{/',
            $source
        );
        $this->assertSame(
            11,
            $killSwitchChecks,
            'A kill-switch check was added, removed or reshaped in this executor. Read its arm: if it declines with a sentence, adjudicate the wording and add it to the provider above; if it returns message-less, it renders the cockpit no-reason fallback (#3901) instead. If the change was a pure refactor, re-baseline this number.'
        );
    }
}
