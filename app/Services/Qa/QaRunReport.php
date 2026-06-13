<?php

namespace App\Services\Qa;

class QaRunReport
{
    /**
     * @param  array<int,array{scenario:string,status:string}>  $results
     * @param  array<int,array{id:string,kind:string,title:string}>  $findings
     */
    public function __construct(
        private readonly string $runLabel,
        private readonly array $results,
        private readonly array $findings,
    ) {}

    public function toMarkdown(): string
    {
        $counts = ['pass' => 0, 'fail' => 0, 'error' => 0];
        foreach ($this->results as $r) {
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
        }
        $summary = "{$counts['pass']} pass · {$counts['fail']} fail · {$counts['error']} error";

        $lines = ["# QA run — {$this->runLabel}", '', "**Summary:** {$summary}", '', '## Scenarios'];
        foreach ($this->results as $r) {
            $icon = ['pass' => '✓', 'fail' => '✗', 'error' => '⚠'][$r['status']] ?? '?';
            $lines[] = "- {$icon} {$r['scenario']} ({$r['status']})";
        }
        $lines[] = '';
        $lines[] = '## Findings';
        if ($this->findings === []) {
            $lines[] = 'No findings.';
        } else {
            foreach ($this->findings as $f) {
                $lines[] = "- [{$f['kind']}] {$f['title']} ({$f['id']})";
            }
        }

        return implode("\n", $lines)."\n";
    }
}
