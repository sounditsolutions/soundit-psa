<?php

namespace App\Services\Qa;

class QaFinding
{
    public const KINDS = ['bug', 'ux', 'docs', 'design'];

    /** @param array<int,string> $steps */
    public function __construct(
        public readonly string $scenarioId,
        public readonly string $title,
        public readonly string $kind,
        public readonly string $severity,
        public readonly array $steps,
        public readonly string $expected,
        public readonly string $actual,
        public readonly ?string $screenshotPath = null,
    ) {}

    public function dedupKey(): string
    {
        return $this->scenarioId.'|'.$this->title;
    }

    public function body(): string
    {
        return "**Scenario:** {$this->scenarioId}\n**Severity:** {$this->severity}\n\n"
            ."**Steps to reproduce:**\n".collect($this->steps)->map(fn ($s, $i) => ($i + 1).". {$s}")->implode("\n")."\n\n"
            ."**Expected:** {$this->expected}\n**Actual:** {$this->actual}\n"
            .($this->screenshotPath ? "\n**Screenshot:** {$this->screenshotPath}\n" : '')
            ."\n_Filed by gastown.qa. dedup-key: `{$this->dedupKey()}`_";
    }
}
