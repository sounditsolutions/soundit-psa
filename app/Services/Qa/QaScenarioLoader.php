<?php

namespace App\Services\Qa;

class QaScenarioLoader
{
    /** @return array<int,QaScenario> */
    public function loadDir(string $dir): array
    {
        $out = [];
        foreach (glob(rtrim($dir, '/').'/*.md') ?: [] as $file) {
            $out = array_merge($out, $this->parse((string) file_get_contents($file)));
        }

        return $out;
    }

    /**
     * Note: `## ` at line-start inside fenced code blocks is NOT ignored — it is
     * treated as a scenario heading. Scenario .md files must not put `## ` at the
     * start of a line inside a code fence.
     *
     * @return array<int,QaScenario>
     */
    public function parse(string $markdown): array
    {
        $blocks = preg_split('/^## /m', $markdown);
        $scenarios = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '' || ! preg_match('/^([a-z0-9-]+):\s*(.+)$/m', $block, $head)) {
                continue;
            }
            $scenarios[] = new QaScenario(
                id: $head[1],
                title: trim($head[2]),
                goal: $this->field($block, 'goal'),
                setup: $this->field($block, 'setup'),
                steps: $this->list($block, 'steps'),
                expectations: $this->list($block, 'expect'),
                watchFors: $this->list($block, 'watch'),
            );
        }

        return $scenarios;
    }

    private function field(string $block, string $key): string
    {
        return preg_match('/^-\s*'.preg_quote($key, '/').':\s*(.+)$/m', $block, $m) ? trim($m[1]) : '';
    }

    /** @return array<int,string> */
    private function list(string $block, string $key): array
    {
        // Capture the lines indented under "- <key>:" until the next top-level "- " field.
        if (! preg_match('/^-\s*'.preg_quote($key, '/').':.*$((?:\n(?:\s{2,}|\s*\d+\.).*)*)/m', $block, $m)) {
            return [];
        }
        $items = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            $line = preg_replace('/^(\d+\.|-)\s*/', '', $line);
            if ($line !== '') {
                $items[] = $line;
            }
        }

        return $items;
    }
}
