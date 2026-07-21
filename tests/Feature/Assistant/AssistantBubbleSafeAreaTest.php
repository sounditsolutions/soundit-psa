<?php

namespace Tests\Feature\Assistant;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AI assistant bubble is a global `position: fixed` control anchored to
 * the bottom-right of the viewport. On mobile it was overlapping right-aligned
 * content — most visibly the invoice detail Line Items amount column (psa-1k6v).
 *
 * The fix reserves a bottom safe-area on the content wrapper, hooked off the
 * `has-assistant-bubble` class. That class is the server-rendered contract the
 * CSS safe-area and the scroll-hide script both hang off, so it must be present
 * exactly when the bubble itself is rendered, and absent otherwise.
 */
class AssistantBubbleSafeAreaTest extends TestCase
{
    use RefreshDatabase;

    private function enableAssistant(): void
    {
        // AssistantConfig::isEnabled() requires an Anthropic AI key to be
        // configured. Setting the config fallback is enough — no real calls
        // are made while rendering the layout.
        config()->set('services.ai.provider', 'anthropic');
        config()->set('services.ai.api_key', 'test-key');

        // psa-98dq (Charlie, 2026-07-21): the Assistant now DEFAULTS OFF, so a
        // key alone no longer enables it. This helper is named enableAssistant
        // and its callers mean it — say so explicitly rather than leaning on a
        // default. (Worth noting: this test is the in-repo instance of exactly
        // the thing the deploy caution is about — something that relied on
        // auto-on and went quiet when the default flipped.)
        \App\Models\Setting::setValue('assistant_enabled', '1');
    }

    public function test_content_wrapper_reserves_safe_area_when_bubble_is_shown(): void
    {
        $this->enableAssistant();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        // The bubble is rendered...
        $response->assertSee('id="assistantBubble"', false);
        // ...so the wrapper must carry the safe-area hook that keeps the fixed
        // bubble clear of the tail of the page content on small screens.
        $response->assertSee('has-assistant-bubble');
    }

    public function test_no_safe_area_hook_when_bubble_is_hidden(): void
    {
        // No AI key configured => AssistantConfig::isEnabled() is false.
        config()->set('services.ai.api_key', null);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        // Neither the bubble nor its safe-area hook should be present, so we
        // don't reserve dead space on deployments without the assistant.
        $response->assertDontSee('id="assistantBubble"', false);
        $response->assertDontSee('has-assistant-bubble');
    }
}
