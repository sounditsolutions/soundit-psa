<?php

namespace Tests\Feature\Assistant;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AI assistant bubble is a global `position: fixed` control anchored to the
 * bottom-right of the viewport. On mobile it overlapped the content a tech is
 * actively reading — the client Network wiki fact text (psa-guo9) and the
 * right-aligned invoice detail Line Items amount column (psa-1k6v).
 *
 * The fix tucks the bubble away while the page scrolls (assistant-bubble.js)
 * and reserves a bottom safe-area on the content wrapper, both hooked off the
 * `has-assistant-bubble` class. That class is the server-rendered contract the
 * CSS safe-area and the scroll-hide script hang off, so it must be present
 * exactly when the bubble itself is rendered, and absent otherwise.
 */
class AssistantBubbleSafeAreaTest extends TestCase
{
    use RefreshDatabase;

    private function enableAssistant(): void
    {
        // AssistantConfig::isEnabled() requires an Anthropic AI key to be
        // configured. AiConfig reads the settings table first, then falls back
        // to config('services.ai.*'), so setting the config is enough — no real
        // calls are made while rendering the layout.
        config()->set('services.ai.provider', 'anthropic');
        config()->set('services.ai.api_key', 'test-key');
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

    /**
     * psa-guo9: the reported page. A client Network wiki page must carry the
     * bubble and the safe-area hook so the fixed control tucks away from the
     * generated fact text a tech reads on a phone instead of sitting on top of
     * it. The wiki view extends layouts.app, so this guards that the shared
     * fix reaches the wiki surface specifically.
     */
    public function test_client_wiki_page_carries_the_bubble_safe_area_hook(): void
    {
        $this->enableAssistant();
        Setting::setValue('wiki_enabled', '1'); // module master switch (WikiConfig) — 404s when off
        $user = User::factory()->create();
        $client = Client::factory()->create();
        WikiPage::factory()->forClient($client)->create([
            'slug' => 'network',
            'title' => 'Network',
        ]);

        $response = $this->actingAs($user)->get("/clients/{$client->id}/wiki/network");

        $response->assertOk();
        $response->assertSee('id="assistantBubble"', false);
        $response->assertSee('has-assistant-bubble');
    }
}
