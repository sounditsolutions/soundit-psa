<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * XULQ2iix: the cockpit toast showed its secret row and Copy button on EVERY toast,
 * and Copy on an error toast put the string 'null' on the clipboard. Cause: the row's
 * x-show element also carried Bootstrap's .d-flex (display:flex !important), which
 * beats Alpine's inline display:none.
 *
 * WHAT THIS CAN PROVE: on the rendered page, (1) the element bound to x-show="!!toast.secret"
 * carries no class whose Bootstrap rule is `display: … !important` (d-flex, d-block, d-grid,
 * d-inline*, d-table*), and (2) copyToastSecret returns before writeText when the secret is
 * not a non-empty string. WHAT IT CANNOT PROVE: there is no browser in CI, so it does not
 * execute Alpine or the CSS cascade; that the row is actually hidden is inferred from (1).
 */
class CockpitToastSecretTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        return $this->actingAs(User::factory()->create())->get(route('cockpit.index'))->assertOk()->getContent();
    }

    public function test_the_secret_row_x_show_element_carries_no_important_display_utility(): void
    {
        $html = $this->page();
        $this->assertSame(1, preg_match_all('/<[a-z]+\b[^>]*x-show="!!toast\.secret"[^>]*>/', $html, $matches), 'exactly one secret row');
        $tag = $matches[0][0];
        $this->assertSame(1, preg_match('/\bclass="([^"]*)"/', $tag, $class), $tag);
        $this->assertDoesNotMatchRegularExpression('/(^|\s)d-(flex|block|grid|inline|inline-flex|inline-block|table|table-cell|table-row)(\s|$)/', $class[1], 'a display:!important utility on the x-show element defeats x-show');
        // The Copy button is still inside the hidden row (it disappears with it).
        $this->assertMatchesRegularExpression('/x-show="!!toast\.secret"[^>]*>\s*<div class="d-flex[^"]*">\s*<code[^>]*x-text="toast\.secret"><\/code>\s*<button[^>]*copyToastSecret/', $html);
    }

    public function test_copy_refuses_an_absent_or_empty_secret_before_writing_the_clipboard(): void
    {
        $html = $this->page();
        $this->assertSame(1, preg_match('/async copyToastSecret\(toast, event\) \{(.*?)navigator\.clipboard\.writeText/s', $html, $body));
        $this->assertMatchesRegularExpression("/if \\(typeof toast\\?\\.secret !== 'string' \\|\\| toast\\.secret === ''\\) \\{\\s*return;\\s*\\}/", $body[1], 'the guard precedes writeText');
    }
}
