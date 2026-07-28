<?php

namespace Tests\Feature\Web;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-717bn.9: the ticket typeahead/popup JSON payloads already carry
 * category_id + category_path (proven by SearchTypeaheadCategoryPayloadTest,
 * psa-717bn.5), but four client-side renderers dropped the field on the
 * floor, so the category never reached the screen: the Ctrl+K command
 * palette, the softphone call-popup ticket list, the ticket-merge typeahead,
 * and the email link/bulk-link typeaheads. These are wiring guards: each
 * renderer must consume category_path and emit its category markup (leaf in
 * the row, full path in the title attribute). No JS test runner exists in
 * this no-build-step app, so the static assets are asserted at source level —
 * mirrors SidebarResizeLayoutTest.
 */
class TypeaheadCategoryRendererTest extends TestCase
{
    use RefreshDatabase;

    // ── Ctrl+K command palette (public/js/command-palette.js) ───────────────

    public function test_command_palette_renderer_consumes_category_path(): void
    {
        $js = file_get_contents(public_path('js/command-palette.js'));

        $this->assertStringContainsString('category_path', $js, 'command-palette.js never reads category_path — ticket rows lose their category');
        $this->assertStringContainsString('cmd-palette-item-category', $js, 'command-palette.js emits no category chip markup');
    }

    public function test_command_palette_css_styles_the_category_chip(): void
    {
        $css = file_get_contents(public_path('css/command-palette.css'));

        $this->assertStringContainsString('.cmd-palette-item-category', $css);
    }

    // ── softphone call-popup ticket list (public/js/softphone.js) ───────────

    public function test_softphone_ticket_list_renderer_consumes_category_path(): void
    {
        $js = file_get_contents(public_path('js/softphone.js'));

        $this->assertStringContainsString('category_path', $js, 'softphone.js never reads category_path — popup ticket rows lose their category');
        $this->assertStringContainsString('sp-ticket-cat', $js, 'softphone.js emits no category chip markup');
    }

    public function test_softphone_css_styles_the_category_chip(): void
    {
        $css = file_get_contents(public_path('css/softphone.css'));

        $this->assertStringContainsString('.sp-ticket-cat', $css);
    }

    // ── ticket-merge typeahead (inline JS on tickets/show) ──────────────────

    public function test_merge_typeahead_renderer_consumes_category_path(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('category_path', $html, 'tickets/show merge typeahead never reads category_path — result rows lose their category');
    }

    // ── email link + bulk-link typeaheads (inline JS on emails/index) ───────

    public function test_email_link_typeaheads_both_consume_category_path(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('emails.index'))
            ->assertOk()
            ->getContent();

        // Two independent renderers on this page (single-email link modal and
        // bulk-link modal) — both must consume the field.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($html, 'category_path'),
            'emails/index has two ticket typeahead renderers (link + bulk-link); each must read category_path'
        );
    }
}
