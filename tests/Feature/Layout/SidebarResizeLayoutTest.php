<?php

namespace Tests\Feature\Layout;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * psa-vzk1: Resizing from a mobile to a desktop viewport left the fixed sidebar
 * overlapping the main content. Root cause: the sidebar width, topbar offset,
 * and content margin carried always-on CSS transitions (meant for the collapse
 * toggle) that ALSO fired when a viewport resize crossed a breakpoint — the
 * content margin eased in over 200ms while the fixed sidebar snapped in at once,
 * so the content sat under the sidebar during the animation. A fresh desktop
 * load has no prior value to animate from, so it laid out correctly.
 *
 * Fix: the layout transitions are opt-in via a `.sidebar-animate` class that
 * sidebar.js adds only for the duration of an explicit toggle, so viewport
 * resizes reflow instantly. The layout is static CSS/JS (no build step), so
 * these are asset-content regression guards plus a render smoke for the page
 * named in the report (client overview).
 */
class SidebarResizeLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_client_overview_renders_with_sidebar_layout(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $resp = $this->actingAs($user)->get(route('clients.show', $client))->assertOk();

        // The fixed sidebar and the offset content wrapper both render — the
        // structural pieces whose transition timing the fix corrects.
        $resp->assertSee('psa-sidebar', false);
        $resp->assertSee('psa-content-wrapper', false);
    }

    public function test_base_layout_selectors_have_no_always_on_transition(): void
    {
        $css = file_get_contents(public_path('css/sidebar.css'));

        // Each base layout rule must NOT carry a transition. An always-on
        // transition here animates margin/width when a viewport resize crosses a
        // breakpoint, which is what left the sidebar overlapping content.
        $baseRules = [
            'sidebar' => '/Sidebar Container ──.*?\n\.psa-sidebar\s*\{(.*?)\}/s',
            'topbar' => '/── Topbar ──.*?\n\.psa-topbar\s*\{(.*?)\}/s',
            'content wrapper' => '/Content Wrapper ──.*?\n\.psa-content-wrapper\s*\{(.*?)\}/s',
        ];

        foreach ($baseRules as $label => $pattern) {
            $this->assertMatchesRegularExpression($pattern, $css, "Base .psa-{$label} rule not found");
            preg_match($pattern, $css, $m);
            $this->assertStringNotContainsString(
                'transition',
                $m[1],
                "Base {$label} rule must not carry an always-on transition (psa-vzk1)"
            );
        }
    }

    public function test_layout_transitions_are_gated_behind_sidebar_animate(): void
    {
        $css = file_get_contents(public_path('css/sidebar.css'));

        // The collapse-toggle animation is preserved, but only under the
        // JS-applied .sidebar-animate class so it never fires on resize.
        $this->assertMatchesRegularExpression(
            '/body\.sidebar-animate\s+\.psa-sidebar\s*\{[^}]*transition:\s*width/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/body\.sidebar-animate\s+\.psa-topbar\s*\{[^}]*transition:\s*left/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/body\.sidebar-animate\s+\.psa-content-wrapper\s*\{[^}]*transition:\s*margin-left/s',
            $css
        );
    }

    public function test_sidebar_js_enables_animation_only_for_explicit_toggles(): void
    {
        $js = file_get_contents(public_path('js/sidebar.js'));

        // The helper that opts a toggle into the animation exists...
        $this->assertStringContainsString('function animateLayout()', $js);
        $this->assertStringContainsString("classList.add('sidebar-animate')", $js);

        // ...and is invoked at the explicit-toggle sites (collapse button,
        // billing expand, cross-tab storage sync) — not on resize.
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($js, 'animateLayout();'),
            'animateLayout() should be called from each explicit toggle handler'
        );

        // The resize handler must not opt into the animation.
        $this->assertDoesNotMatchRegularExpression(
            '/addEventListener\(\s*[\'"]resize[\'"][^}]*animateLayout/s',
            $js,
            'Resize must reflow instantly, never animate (psa-vzk1)'
        );
    }
}
