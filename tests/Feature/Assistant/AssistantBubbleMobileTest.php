<?php

namespace Tests\Feature\Assistant;

use Tests\TestCase;

/**
 * The floating AI assistant bubble is `position: fixed` in the bottom-right
 * corner. On phones/small tablets, form fields stack full-width, so the bubble
 * lands on top of the right edge of form controls — obscuring the dropdown
 * chevron of selectors like Priority (ticket create) or Primary Tech (client
 * create). psa-ap59: it must be hidden below the `md` breakpoint. This guards
 * the responsive rule against silent removal — a CSS media query cannot be
 * exercised in PHPUnit, so we assert the rule is present and complete.
 */
class AssistantBubbleMobileTest extends TestCase
{
    private function css(): string
    {
        $path = base_path('public/css/assistant-bubble.css');
        $this->assertFileExists($path, 'assistant-bubble.css must exist');

        return (string) file_get_contents($path);
    }

    /**
     * Extract the body of the first `@media (max-width: <bp>)` block, up to the
     * next at-rule or end of file. Good enough for this flat stylesheet (media
     * blocks contain only one nesting level).
     */
    private function mobileMediaBlock(string $css): string
    {
        $start = strpos($css, '@media (max-width: 767.98px)');
        $this->assertNotFalse(
            $start,
            'a @media (max-width: 767.98px) query must exist — mirrors the md breakpoint used across the app'
        );

        $rest = substr($css, $start + strlen('@media (max-width: 767.98px)'));
        $next = strpos($rest, '@media');

        return $next === false ? $rest : substr($rest, 0, $next);
    }

    public function test_floating_bubble_is_hidden_on_mobile_viewports(): void
    {
        $block = $this->mobileMediaBlock($this->css());

        $this->assertStringContainsString('#assistantBubble', $block,
            'the floating bubble must be targeted by the mobile hide rule');
        $this->assertStringContainsString('.ab-flyout', $block,
            'the flyout must also be hidden so it cannot be left open across a resize');

        $normalized = preg_replace('/\s+/', '', $block);
        $this->assertStringContainsString('display:none', $normalized,
            'the mobile rule must hide the assistant so it does not cover form fields');
    }
}
