<?php

namespace Tests\Unit\Wiki;

use App\Helpers\LineDiff;
use PHPUnit\Framework\TestCase;

class LineDiffTest extends TestCase
{
    public function test_diff_marks_added_removed_and_unchanged_lines(): void
    {
        $old = "a\nb\nc";
        $new = "a\nB\nc\nd";

        $diff = LineDiff::diff($old, $new);

        $this->assertSame([
            ['type' => 'same', 'line' => 'a'],
            ['type' => 'del', 'line' => 'b'],
            ['type' => 'add', 'line' => 'B'],
            ['type' => 'same', 'line' => 'c'],
            ['type' => 'add', 'line' => 'd'],
        ], $diff);
    }

    public function test_identical_inputs_are_all_same(): void
    {
        $diff = LineDiff::diff("x\ny", "x\ny");

        $this->assertSame([['type' => 'same', 'line' => 'x'], ['type' => 'same', 'line' => 'y']], $diff);
    }

    public function test_oversized_inputs_short_circuit(): void
    {
        $old = str_repeat("line\n", 2001);
        $new = str_repeat("other\n", 2001);

        $diff = LineDiff::diff($old, $new);

        $this->assertSame([['type' => 'same', 'line' => '(diff too large to display)']], $diff);
    }
}
