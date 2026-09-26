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
 * kill switch in the same method). The list was closed with no wording change.
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
        // The property, stated independently of the exact sentence: a refusal on
        // this path may say the write was refused, and may not make a claim about
        // upstream state or tell the approver to repeat the action. The kill switch
        // has to be cleared first, so a retry instruction would be false here.
        foreach (['try again', 'retry', 'nothing was sent', 'no changes were made', 'no upstream call was made'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $expected,
                "{$method}'s kill-switch decline must not claim '{$forbidden}'."
            );
        }

        // And it must still name the refusal, so deleting the message does not pass.
        $this->assertStringContainsStringIgnoringCase('refused', $expected);
        $this->assertStringContainsStringIgnoringCase('kill-switch', $expected);
    }

    public function test_the_arm_count_is_pinned_so_a_sixth_cannot_arrive_unread(): void
    {
        // The adjudication covered five CIPP kill-switch decline arms. If a sixth
        // approval path is added, this fails and its arm gets read rather than
        // inheriting an unexamined message.
        $reflection = new \ReflectionClass(StaffCippWriteToolExecutor::class);
        $file = $reflection->getFileName();
        $source = file_get_contents((string) $file);
        $this->assertNotFalse($source);

        $matches = preg_match_all(
            "/\\\$this->declined\('Technician kill-switch engaged;/",
            $source
        );

        $this->assertSame(5, $matches, 'Five CIPP kill-switch decline arms were adjudicated; read any new one before it ships.');
    }
}
