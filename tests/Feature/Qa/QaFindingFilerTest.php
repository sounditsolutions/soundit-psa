<?php

namespace Tests\Feature\Qa;

use App\Services\Qa\QaFinding;
use App\Services\Qa\QaFindingFiler;
use Tests\TestCase;

class QaFindingFilerTest extends TestCase
{
    private function finding(string $kind = 'bug'): QaFinding
    {
        return new QaFinding(
            scenarioId: 'tix-resolve',
            title: 'Resolve does not prompt for a resolution',
            kind: $kind,
            severity: 'medium',
            steps: ['Open ticket', 'Set status Resolved'],
            expected: 'Prompted to enter a resolution',
            actual: 'Status set to Resolved with empty resolution, no prompt',
            screenshotPath: '/tmp/qa/tix-resolve.png',
        );
    }

    public function test_files_a_finding_as_a_labeled_bead(): void
    {
        $calls = [];
        $runner = function (array $cmd) use (&$calls) {
            $calls[] = $cmd;

            return 'psa-NEW1';
        };
        // existing open qa beads (for dedup) — none yet:
        $existing = fn () => [];

        $filer = new QaFindingFiler($runner, $existing);
        $id = $filer->file($this->finding('ux'));

        $this->assertSame('psa-NEW1', $id);
        $create = collect($calls)->first(fn ($c) => in_array('create', $c, true));
        $this->assertNotNull($create, 'expected a bd create call');
        $joined = implode(' ', $create);
        $this->assertStringContainsString('--rig', $joined);
        $this->assertStringContainsString('soundit-psa', $joined);
        // labels qa + ux present among the args
        $this->assertContains('qa', $create);
        $this->assertContains('ux', $create);
    }

    public function test_dedups_against_open_qa_beads(): void
    {
        $calls = [];
        $runner = function (array $cmd) use (&$calls) {
            $calls[] = $cmd;

            return 'SHOULD_NOT_CREATE';
        };
        // an open qa bead already covers this scenario+assertion:
        $existing = fn () => [['id' => 'psa-OLD', 'dedup_key' => 'tix-resolve|Resolve does not prompt for a resolution']];

        $filer = new QaFindingFiler($runner, $existing);
        $id = $filer->file($this->finding('ux'));

        $this->assertSame('psa-OLD', $id);
        $this->assertEmpty(collect($calls)->filter(fn ($c) => in_array('create', $c, true)), 'must not create a duplicate');
    }

    public function test_rejects_invalid_kind(): void
    {
        $filer = new QaFindingFiler(fn () => '', fn () => []);
        $this->expectException(\InvalidArgumentException::class);
        $filer->file($this->finding('typo'));
    }
}
