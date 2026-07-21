<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-uw2o.13 (F2 + F3): the disabled-Assistant notice, at every site that
 * shows one.
 *
 * F2 — the predicate was restated in three views and had already DRIFTED. The
 * topbar gated correctly on "an AI provider is configured"; the ticket action
 * row used a bare @else and the timeline a bare !isEnabled(), so an install
 * with NO AI provider at all — which never wanted an Assistant — was told on
 * every single ticket page that its "AI Assistant is disabled".
 *
 * The existing no-provider test passed only because it visits '/', which renders
 * the topbar and nothing else. So the one site that was right was the only site
 * under test. These tests drive the ticket page and the timeline, which is
 * exactly the gap that let the drift ship.
 *
 * F3 — the notice must tell the operator how to RECOVER, reachably. The
 * recovery text used to live only in `title` attributes: one on an inert span,
 * one on a DISABLED button (which is not keyboard-focusable at all), and the
 * timeline gave no recovery path whatsoever. Title text is not dependable for
 * keyboard or touch users, so the tests below strip every title attribute
 * before asserting — if the recovery only exists in a tooltip, they fail.
 *
 * The three-state distinction under all of this:
 *   (a) no AI provider at all  → SILENCE. The install never wanted an Assistant.
 *   (b) AI configured, not Anthropic → "unavailable" — it cannot run here.
 *   (c) Anthropic, toggle off  → "disabled" — turn it on.
 * (b) and (c) are both notice-worthy and say DIFFERENT things; (a) says nothing.
 */
class AssistantDisabledNoticeSitesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every site that renders a disabled-Assistant notice tags itself with this
     * attribute. One marker, three sites: a test can then assert the sites AGREE
     * rather than trusting three hand-written copies of the same predicate.
     */
    private const SITES = ['topbar', 'ticket-actions', 'timeline'];

    private const NOTICE_ATTR = 'data-assistant-disabled-notice';

    private User $user;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $client = Client::factory()->create();
        $this->ticket = Ticket::factory()->create(['client_id' => $client->id]);

        // An owned conversation with a fresh message: the state in which the
        // timeline renders its live input when enabled, and its read-only
        // summary (with the notice) when not.
        $conversation = AssistantConversation::create([
            'user_id' => $this->user->id,
            'context_type' => 'ticket',
            'context_id' => $this->ticket->id,
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Checking the mailbox rules now.',
        ]);
    }

    // ── the three install states ─────────────────────────────────────────────

    /** (a) nothing configured — this install never wanted an Assistant. */
    private function noAiProvider(): void
    {
        Setting::setValue('assistant_enabled', '0');
    }

    /** (b) AI configured, but not the Anthropic provider the tool loop needs. */
    private function nonAnthropicProvider(): void
    {
        Setting::setValue('ai_provider', 'openai');
        Setting::setEncrypted('ai_api_key', 'test-key');
        Setting::setValue('assistant_enabled', '1');
    }

    /** (c) eligible in every way, but the operator switched it off. */
    private function anthropicButSwitchedOff(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        Setting::setValue('assistant_enabled', '0');
    }

    private function fullyEnabled(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        Setting::setValue('assistant_enabled', '1');
    }

    private function ticketPage(): string
    {
        return (string) $this->actingAs($this->user)
            ->get(route('tickets.show', $this->ticket))
            ->assertOk()
            ->getContent();
    }

    private function marker(string $site): string
    {
        return self::NOTICE_ATTR.'="'.$site.'"';
    }

    /**
     * The rendered notice and the text immediately after it.
     *
     * Assertions about recovery text MUST be scoped this way: the word
     * "Settings" appears in the sidebar nav of every page, so a page-wide
     * assertion would pass without the notice saying anything at all.
     */
    private function noticeRegion(string $html, string $site, int $length = 500): string
    {
        $pos = strpos($html, $this->marker($site));

        // strpos returning false would cast to 0 and hand back the top of the
        // document — a guard that passes on the wrong haystack.
        $this->assertNotFalse($pos, "the {$site} notice was not rendered — this guard would silently pass on nothing");

        return substr($html, $pos, $length);
    }

    /**
     * Strips every title attribute, so an assertion afterwards can only be
     * satisfied by text a keyboard or touch user can actually reach.
     *
     * The control assertion is load-bearing: if this regex silently matched
     * nothing, stripping would be a no-op and every "reachable" test below
     * would pass on tooltip-only text — the exact vacuous green this bead keeps
     * catching.
     */
    private function withoutTooltips(string $html): string
    {
        $stripped = (string) preg_replace('/\stitle="[^"]*"/', '', $html);

        $this->assertNotSame(
            $html,
            $stripped,
            'control: the page must contain at least one title attribute, or this guard proves nothing'
        );

        return $stripped;
    }

    // ── F2: all three sites must agree ───────────────────────────────────────

    public function test_no_site_nags_an_install_that_has_no_ai_provider(): void
    {
        $this->noAiProvider();

        $html = $this->ticketPage();

        foreach (self::SITES as $site) {
            $this->assertStringNotContainsString(
                $this->marker($site),
                $html,
                "the {$site} notice must stay silent on an install with no AI provider — it never wanted an Assistant"
            );
        }

        $this->assertStringNotContainsString('AI Assistant is disabled', $html);
    }

    public function test_every_site_explains_an_anthropic_install_whose_toggle_is_off(): void
    {
        // CONTROL for the test above. Without this, "no marker present" would be
        // satisfied by a notice that never renders anywhere, and the silence
        // assertions would be worthless.
        $this->anthropicButSwitchedOff();

        $html = $this->ticketPage();

        foreach (self::SITES as $site) {
            $this->assertStringContainsString(
                $this->marker($site),
                $html,
                "the {$site} notice must explain a switched-off Assistant — default-off was approved on the condition it is not a silent absence"
            );
        }
    }

    public function test_every_site_explains_an_install_whose_provider_cannot_run_the_assistant(): void
    {
        $this->nonAnthropicProvider();

        $html = $this->ticketPage();

        foreach (self::SITES as $site) {
            $this->assertStringContainsString($this->marker($site), $html, "site: {$site}");
        }
    }

    public function test_no_site_shows_a_notice_while_the_assistant_is_running(): void
    {
        // Control: the markers must be absent for the reason under test, not
        // because they are absent unconditionally.
        $this->fullyEnabled();

        $html = $this->ticketPage();

        foreach (self::SITES as $site) {
            $this->assertStringNotContainsString($this->marker($site), $html, "site: {$site}");
        }

        $this->assertStringContainsString('id="askAiBtn"', $html, 'control: the live control must be back');
    }

    /**
     * The two ineligibility causes must not read the same. "Disabled — turn it
     * on" is actively misleading advice on an install where turning it on
     * changes nothing, because the provider is wrong.
     */
    public function test_a_wrong_provider_is_not_reported_as_merely_switched_off(): void
    {
        $this->nonAnthropicProvider();
        $wrongProvider = $this->ticketPage();

        $this->anthropicButSwitchedOff();
        $switchedOff = $this->ticketPage();

        $this->assertStringContainsString('AI Assistant is disabled', $switchedOff);
        $this->assertStringNotContainsString(
            'AI Assistant is disabled',
            $wrongProvider,
            'an Assistant that cannot run on this provider is not "disabled" — telling the operator to flip a switch that will not help is worse than saying nothing'
        );
        $this->assertStringContainsString('Anthropic', $wrongProvider);
    }

    // ── F3: the recovery path must be reachable without a tooltip ────────────

    public function test_the_ticket_action_row_states_the_recovery_path_outside_a_tooltip(): void
    {
        $this->anthropicButSwitchedOff();

        $notice = $this->noticeRegion($this->withoutTooltips($this->ticketPage()), 'ticket-actions');

        $this->assertStringContainsString(
            'Settings',
            $notice,
            'the recovery path must survive with tooltips stripped — title text is not reachable by keyboard or touch'
        );
        $this->assertStringContainsString('Integrations', $notice);
    }

    public function test_the_timeline_notice_states_a_recovery_path_at_all(): void
    {
        // The timeline previously said the conversation was read-only and
        // stopped there — no recovery path in any form, not even a tooltip.
        $this->anthropicButSwitchedOff();

        $notice = $this->noticeRegion($this->withoutTooltips($this->ticketPage()), 'timeline');

        $this->assertStringContainsString('read-only', $notice);
        $this->assertStringContainsString(
            'Settings',
            $notice,
            'the timeline notice must say how to get the Assistant back, not merely that it is gone'
        );
    }

    public function test_the_topbar_explanation_does_not_depend_on_a_tooltip(): void
    {
        $this->anthropicButSwitchedOff();

        $html = (string) $this->actingAs($this->user)->get('/')->assertOk()->getContent();

        $notice = $this->noticeRegion($this->withoutTooltips($html), 'topbar');

        $this->assertStringContainsString(
            'AI Assistant is disabled',
            $notice,
            'the topbar explanation used to live only in the title of a DISABLED button, which is not even keyboard-focusable'
        );
        $this->assertStringContainsString('Settings', $notice);
    }

    /**
     * psa-uw2o.4: the indicator must stay INERT. A dead control that looks live
     * is worse than absence — so making the explanation readable must not
     * resurrect anything clickable.
     */
    public function test_no_notice_site_resurrects_a_live_looking_control(): void
    {
        $this->anthropicButSwitchedOff();

        $html = $this->ticketPage();

        $this->assertStringNotContainsString('data-assistant-toggle', $html, 'no live topbar trigger');
        $this->assertStringNotContainsString('id="askAiBtn"', $html, 'no live Ask AI button');
        $this->assertStringNotContainsString('ai-chat-send', $html, 'no live send button');
        $this->assertStringNotContainsString('ai-chat-text', $html, 'no live chat input');
    }

    public function test_the_history_survives_at_every_state(): void
    {
        // Turning the Assistant off (or losing eligibility) must never erase the
        // record of what it already did.
        foreach (['noAiProvider', 'nonAnthropicProvider', 'anthropicButSwitchedOff'] as $state) {
            $this->{$state}();

            $this->assertStringContainsString(
                'Checking the mailbox rules now.',
                $this->ticketPage(),
                "state {$state}: the conversation history must remain visible"
            );
        }
    }

    // ── .20: the topbar must SAY the state, not whisper it ───────────────────

    /**
     * The three tests above were satisfied by a chip whose only VISIBLE text
     * was the hard-coded string "AI off" — hidden below `sm` at that, so on a
     * phone it was a bare robot icon. The state and the recovery path lived
     * only in a `title` and a `visually-hidden` span, which inverted the usual
     * failure: a screen-reader user was told more than a sighted keyboard or
     * touch user, who got an unexplained icon.
     *
     * They passed because `noticeRegion()` matches raw HTML, and raw HTML
     * cannot tell "on screen" from "in the accessibility tree". Everything
     * below walks the DOM instead, because the questions here are structural:
     * what does a sighted user at 360px read off this chip, and what does a
     * screen reader announce?
     */
    private function home(): string
    {
        return (string) $this->actingAs($this->user)->get('/')->assertOk()->getContent();
    }

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($doc);
    }

    private function topbarNotice(string $html): \DOMElement
    {
        $nodes = $this->xpath($html)->query('//*[@'.self::NOTICE_ATTR.'="topbar"]');

        $this->assertNotFalse($nodes, 'the notice XPath did not run — this guard would silently pass on nothing');
        $this->assertSame(1, $nodes->length, 'expected exactly one topbar notice element on the page');

        $element = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $element);

        return $element;
    }

    /**
     * The text a sighted user reads off the chip at the NARROWEST viewport.
     *
     * `d-none` is Bootstrap's "hidden at every breakpoint until a wider
     * `d-{bp}-*` puts it back", so at base width anything carrying it is off
     * screen — which is exactly how the old label (`d-none d-sm-inline`)
     * vanished on the viewports least able to hover a tooltip. `visually-hidden`
     * is never visible at any width. `title` never appears here at all, because
     * it is an attribute and not text.
     */
    private function visibleTextAtBaseWidth(\DOMNode $node): string
    {
        return $this->collapse($this->textOf($node, function (\DOMElement $el): bool {
            $classes = ' '.$el->getAttribute('class').' ';

            return $el->hasAttribute('hidden')
                || str_contains($classes, ' d-none ')
                || str_contains($classes, ' visually-hidden ')
                || str_contains($classes, ' visually-hidden-focusable ');
        }));
    }

    /**
     * The text assistive tech would announce for the chip.
     *
     * `aria-hidden` subtrees are dropped; `title` values are ADDED, because
     * screen readers commonly announce a title as the element's description.
     * That inclusion is the point of the duplication guard below: a sentence
     * that lives both in a `visually-hidden` span and in a `title` is a
     * sentence that can be read out twice.
     */
    private function announcedText(\DOMNode $node): string
    {
        $text = $this->textOf($node, fn (\DOMElement $el): bool => $el->getAttribute('aria-hidden') === 'true');

        foreach ($this->titlesWithin($node) as $title) {
            $text .= ' '.$title;
        }

        return $this->collapse($text);
    }

    /** @return list<string> */
    private function titlesWithin(\DOMNode $node): array
    {
        $titles = [];

        $attributes = (new \DOMXPath($node->ownerDocument))
            ->query('descendant-or-self::*/@title', $node);

        if ($attributes !== false) {
            foreach ($attributes as $attribute) {
                $titles[] = $attribute->nodeValue ?? '';
            }
        }

        return $titles;
    }

    private function textOf(\DOMNode $node, callable $isHidden): string
    {
        if ($node instanceof \DOMText) {
            return $node->wholeText;
        }

        if ($node instanceof \DOMElement && $isHidden($node)) {
            return '';
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= ' '.$this->textOf($child, $isHidden);
        }

        return $text;
    }

    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * The probe itself, under test.
     *
     * Every assertion in this section is only worth what this walker is worth.
     * If it silently counted `visually-hidden` text as visible, the whole
     * section would go green on the exact defect it exists to catch — so the
     * separation is asserted directly rather than assumed.
     */
    public function test_the_visible_text_probe_actually_separates_screen_from_accessibility_tree(): void
    {
        $this->anthropicButSwitchedOff();

        $notice = $this->topbarNotice($this->home());

        $visible = $this->visibleTextAtBaseWidth($notice);
        $announced = $this->announcedText($notice);

        $this->assertNotSame('', $visible, 'control: the chip must have some visible text at all');
        $this->assertStringContainsString(
            'AI Assistant is disabled',
            $announced,
            'control: the full sentence must reach the accessibility tree, or the separation below is measuring nothing'
        );
        $this->assertStringNotContainsString(
            'AI Assistant is disabled',
            $visible,
            'control: visually-hidden text must NOT be counted as visible, or every guard in this section is vacuous'
        );
    }

    /**
     * The blocking defect: the visible chip said "AI off" in BOTH ineligible
     * states, so the three-state model this class exists to express was
     * invisible on the one surface that appears on every page.
     */
    public function test_the_topbar_tells_the_two_causes_apart_in_visible_text(): void
    {
        $this->anthropicButSwitchedOff();
        $switchedOff = $this->visibleTextAtBaseWidth($this->topbarNotice($this->home()));

        $this->nonAnthropicProvider();
        $wrongProvider = $this->visibleTextAtBaseWidth($this->topbarNotice($this->home()));

        $this->assertNotSame(
            $switchedOff,
            $wrongProvider,
            "both causes rendered the same visible chip ('{$switchedOff}') — a switched-off Assistant and one that cannot run on this provider need different actions"
        );

        $this->assertStringContainsString('disabled', $switchedOff, "visible topbar text was '{$switchedOff}'");
        $this->assertStringNotContainsString('unavailable', $switchedOff, "visible topbar text was '{$switchedOff}'");

        $this->assertStringContainsString('unavailable', $wrongProvider, "visible topbar text was '{$wrongProvider}'");
        $this->assertStringNotContainsString(
            'disabled',
            $wrongProvider,
            "visible topbar text was '{$wrongProvider}' — telling an operator to flip a switch that will not help is worse than saying nothing"
        );
    }

    /**
     * Reviewer's requirement: the chip must give the recovery path, or a clear
     * visible pointer to where it lives. A full sentence in chrome that sits on
     * every page would be nagging, so a pointer is the shape — but it has to be
     * ON SCREEN, not in a tooltip.
     */
    public function test_the_topbar_points_at_settings_in_visible_text_for_both_causes(): void
    {
        foreach (['anthropicButSwitchedOff', 'nonAnthropicProvider'] as $state) {
            $this->{$state}();

            $visible = $this->visibleTextAtBaseWidth($this->topbarNotice($this->home()));

            $this->assertStringContainsString(
                'Settings',
                $visible,
                "state {$state}: the visible chip read '{$visible}' — a notice that names no recovery path leaves the operator to guess"
            );
        }
    }

    /**
     * The old label was `d-none d-sm-inline`, so below 576px the notice was a
     * robot icon and nothing else — on precisely the touch devices that cannot
     * hover the tooltip holding the explanation.
     */
    public function test_the_topbar_notice_is_not_icon_only_at_the_narrowest_viewport(): void
    {
        $this->anthropicButSwitchedOff();

        $notice = $this->topbarNotice($this->home());

        $this->assertStringContainsString(
            'disabled',
            $this->visibleTextAtBaseWidth($notice),
            'the state must survive at base width — a bare icon explains nothing'
        );

        // Named separately from the assertion above so a regression points at
        // its own cause rather than at "the text went missing somehow".
        $hidden = (new \DOMXPath($notice->ownerDocument))->query(
            'descendant-or-self::*[contains(concat(" ", normalize-space(@class), " "), " d-none ")]',
            $notice
        );

        $this->assertNotFalse($hidden);
        $this->assertSame(
            0,
            $hidden->length,
            'something in the notice is hidden at base width — responsive hiding is what made this notice icon-only on a phone'
        );
    }

    /**
     * Now that the visible text carries the state, the belt-and-braces copies
     * that used to compensate for it become a liability: a `title` plus a
     * `visually-hidden` span holding the same sentence is that sentence read
     * out twice.
     */
    public function test_the_topbar_does_not_announce_the_same_notice_twice(): void
    {
        $this->anthropicButSwitchedOff();

        $announced = $this->announcedText($this->topbarNotice($this->home()));

        $this->assertSame(
            1,
            substr_count($announced, 'AI Assistant is disabled'),
            "the state is announced more than once: '{$announced}'"
        );
        $this->assertSame(
            1,
            substr_count($announced, 'Turn it on in Settings'),
            "the recovery path is announced more than once: '{$announced}'"
        );
    }

    // ── F3: contrast. The topbar label must reach WCAG AA. ───────────────────

    /**
     * The stylesheets the rendered page actually links, as paths on disk.
     *
     * CDN links (Bootstrap, the icon font, Google Fonts) map to no local file
     * and drop out here, which is what we want: this asks what OUR CSS says.
     *
     * @return array<string, string> path => contents
     */
    private function linkedStylesheets(string $html): array
    {
        $hrefs = $this->xpath($html)->query('//link[@rel="stylesheet"]/@href');
        $this->assertNotFalse($hrefs);

        $sheets = [];

        foreach ($hrefs as $href) {
            $path = parse_url((string) $href->nodeValue, PHP_URL_PATH);

            if (! is_string($path)) {
                continue;
            }

            $file = public_path(ltrim($path, '/'));

            if (is_file($file)) {
                $sheets[$file] = (string) file_get_contents($file);
            }
        }

        $this->assertNotEmpty($sheets, 'the page linked no local stylesheet at all — this guard would silently pass on nothing');

        return $sheets;
    }

    /**
     * The `.assistant-status-off` block from a stylesheet the page ACTUALLY
     * LINKS — not from whichever file we assume owns it.
     *
     * That distinction is the whole point. This guard used to read
     * `public/css/assistant-chat.css` directly, and nothing in the application
     * has ever linked that file: the AA colours it measured never reached a
     * browser, so the contrast fix was real in the repository and absent on
     * screen. Sourcing the rule from the rendered page's own <link> tags is
     * what stops that from being possible again.
     */
    private function renderedStatusOffRule(): string
    {
        $blocks = [];

        foreach ($this->linkedStylesheets($this->home()) as $path => $css) {
            if (preg_match('/\.assistant-status-off\s*\{([^}]*)\}/', $css, $block) === 1) {
                $blocks[$path] = $block[1];
            }
        }

        $this->assertNotEmpty(
            $blocks,
            'no stylesheet linked by the page declares .assistant-status-off — the chip renders unstyled, and any contrast measured from an unlinked file describes nothing a user sees'
        );
        $this->assertCount(
            1,
            $blocks,
            'more than one linked stylesheet declares .assistant-status-off — this guard cannot say which one wins, so it must not claim to have measured the chip'
        );

        return (string) reset($blocks);
    }

    public function test_the_stylesheet_that_styles_the_topbar_notice_is_actually_linked(): void
    {
        $this->anthropicButSwitchedOff();

        // Deliberately its own test: if the rule is unreachable the contrast
        // test below fails too, but with a message about colours rather than
        // about delivery, which is the thing that was actually broken.
        $this->assertNotSame('', trim($this->renderedStatusOffRule()));
    }

    /**
     * The disabled topbar indicator was `.assistant-trigger` + Bootstrap's
     * `.opacity-50`. Measured against the composited backdrop that is 3.08:1
     * against the button and 3.60:1 against the navy topbar, for 0.8rem text —
     * below the 4.5:1 AA requirement for normal text.
     *
     * Global opacity is the mechanism: it fades the text and its backdrop
     * together, so no colour choice underneath can rescue it. This test
     * measures the real declared colours rather than asserting a class name, so
     * a future restyle that dips below AA fails here.
     */
    public function test_the_disabled_topbar_indicator_meets_wcag_aa_for_normal_text(): void
    {
        $this->anthropicButSwitchedOff();

        $block = [1 => $this->renderedStatusOffRule()];

        $topbar = $this->parseColor($this->declaration(
            (string) file_get_contents(public_path('css/app.css')),
            '--primary'
        ));

        $text = $this->parseColor($this->declaration($block[1], 'color'));
        $chip = $this->parseColor($this->declaration($block[1], 'background'));

        // The chip background may be translucent; composite it over the topbar.
        $chipOverTopbar = $this->composite($chip, $topbar);
        $textOverChip = $this->composite($text, $chipOverTopbar);

        $ratio = $this->contrast($textOverChip, $chipOverTopbar);

        $this->assertGreaterThanOrEqual(
            4.5,
            $ratio,
            sprintf('the disabled indicator label is %.2f:1 against its chip — WCAG AA requires 4.5:1 for normal text', $ratio)
        );

        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrast($textOverChip, $topbar),
            'the label must also clear AA against the bare topbar behind the chip'
        );
    }

    public function test_the_disabled_topbar_indicator_does_not_use_global_opacity(): void
    {
        // Opacity applied to the whole element defeats the measurement above:
        // it fades text and backdrop together, so the declared colours would no
        // longer describe what is on screen.
        $this->anthropicButSwitchedOff();

        $html = (string) $this->actingAs($this->user)->get('/')->assertOk()->getContent();

        $found = preg_match('/<[^>]*'.preg_quote($this->marker('topbar'), '/').'[^>]*>/', $html, $m);
        $this->assertSame(1, $found, 'the topbar notice element was not found — this guard would silently pass on nothing');

        $this->assertStringNotContainsString('opacity-50', $m[0], 'global opacity would drop the label back below AA');
        $this->assertDoesNotMatchRegularExpression('/opacity\s*:/', $m[0], 'no inline opacity either');
    }

    // ── colour maths (WCAG 2.1 relative luminance) ───────────────────────────

    private function declaration(string $block, string $property): string
    {
        $found = preg_match('/(?<![\w-])'.preg_quote($property, '/').'\s*:\s*([^;]+);/', $block, $m);
        $this->assertSame(1, $found, "could not read the '{$property}' declaration — this guard would silently pass on nothing");

        return trim($m[1]);
    }

    /** @return array{0:float,1:float,2:float,3:float} rgba, alpha 0..1 */
    private function parseColor(string $value): array
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-f]{6})$/i', $value, $m) === 1) {
            [$r, $g, $b] = sscanf($m[1], '%2x%2x%2x');

            return [(float) $r, (float) $g, (float) $b, 1.0];
        }

        if (preg_match('/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,\/]+([\d.]+))?\s*\)$/i', $value, $m) === 1) {
            return [(float) $m[1], (float) $m[2], (float) $m[3], isset($m[4]) ? (float) $m[4] : 1.0];
        }

        $this->fail("unsupported colour value '{$value}' — the contrast guard cannot measure it, so it must not silently pass");
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    private function composite(array $fg, array $bg): array
    {
        $a = $fg[3];

        return [
            $a * $fg[0] + (1 - $a) * $bg[0],
            $a * $fg[1] + (1 - $a) * $bg[1],
            $a * $fg[2] + (1 - $a) * $bg[2],
            1.0,
        ];
    }

    private function luminance(array $c): float
    {
        $channel = static function (float $v): float {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($c[0]) + 0.7152 * $channel($c[1]) + 0.0722 * $channel($c[2]);
    }

    private function contrast(array $a, array $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
