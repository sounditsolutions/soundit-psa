<?php

namespace App\Helpers;

class LineDiff
{
    /**
     * Minimal LCS line diff. Deletions are emitted before additions at each divergence.
     *
     * @return array<int, array{type: 'same'|'add'|'del', line: string}>
     */
    public static function diff(string $old, string $new): array
    {
        $a = $old === '' ? [] : explode("\n", $old);
        $b = $new === '' ? [] : explode("\n", $new);
        $n = count($a);
        $m = count($b);

        // The LCS table is O(n·m) PHP array cells; thousands of lines would
        // allocate hundreds of MB. History views cap rather than OOM.
        if (max($n, $m) > 2000) {
            return [['type' => 'same', 'line' => '(diff too large to display)']];
        }

        // LCS length table.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        // Walk the table.
        $out = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['type' => 'same', 'line' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['type' => 'del', 'line' => $a[$i]];
                $i++;
            } else {
                $out[] = ['type' => 'add', 'line' => $b[$j]];
                $j++;
            }
        }
        for (; $i < $n; $i++) {
            $out[] = ['type' => 'del', 'line' => $a[$i]];
        }
        for (; $j < $m; $j++) {
            $out[] = ['type' => 'add', 'line' => $b[$j]];
        }

        return $out;
    }
}
