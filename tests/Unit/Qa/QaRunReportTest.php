<?php

namespace Tests\Unit\Qa;

use App\Services\Qa\QaRunReport;
use PHPUnit\Framework\TestCase;

class QaRunReportTest extends TestCase
{
    public function test_renders_summary_and_findings(): void
    {
        $report = new QaRunReport(
            runLabel: 'wiki smoke 2026-06-13',
            results: [
                ['scenario' => 'wiki-enable', 'status' => 'pass'],
                ['scenario' => 'wiki-mine', 'status' => 'fail'],
                ['scenario' => 'wiki-search', 'status' => 'error'],
            ],
            findings: [
                ['id' => 'psa-1', 'kind' => 'bug', 'title' => 'Mining never runs (no queue worker)'],
            ],
        );

        $md = $report->toMarkdown();

        $this->assertStringContainsString('wiki smoke 2026-06-13', $md);
        $this->assertStringContainsString('1 pass', $md);
        $this->assertStringContainsString('1 fail', $md);
        $this->assertStringContainsString('1 error', $md);
        $this->assertStringContainsString('psa-1', $md);
        $this->assertStringContainsString('Mining never runs', $md);
    }

    public function test_clean_run_reports_no_findings(): void
    {
        $report = new QaRunReport('clean', [['scenario' => 'x', 'status' => 'pass']], []);

        $md = $report->toMarkdown();

        $this->assertStringContainsString('1 pass', $md);
        $this->assertStringContainsString('No findings', $md);
    }
}
