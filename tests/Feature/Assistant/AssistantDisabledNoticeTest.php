<?php

namespace Tests\Feature\Assistant;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-322qo / psa-uw2o.12: a disabled Assistant must not be a SILENT absence.
 *
 * Charlie approved default-off on that condition. The UX review pointed out
 * that the ticket "Ask AI" button is one page type, while the TOPBAR is global
 * chrome on every page — so it is the most reachable place someone looks when
 * the Assistant they were using has gone, and leaving it merely absent is the
 * failure the ruling was conditioned against.
 *
 * Two deliberate design choices, both asserted here:
 *
 *  - The notice is GATED ON AN AI PROVIDER BEING CONFIGURED. With default-off,
 *    the common case is a deployment that never wanted an assistant; nagging it
 *    on every page would be noise, and noise trains people to ignore notices —
 *    including this one.
 *  - It is a quiet DISABLED CONTROL, not a page-width banner. It stays silent
 *    until someone reaches for it, and it cannot be mistaken for a live control
 *    (the psa-uw2o.4 lesson: a dead affordance that looks live is worse than
 *    absence).
 */
class AssistantDisabledNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function withAnthropicConfigured(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
    }

    private function page(): string
    {
        return (string) $this->actingAs($this->user)->get('/')->assertOk()->getContent();
    }

    public function test_the_topbar_says_the_assistant_is_disabled_when_ai_is_configured(): void
    {
        $this->withAnthropicConfigured();
        Setting::setValue('assistant_enabled', '0');

        $html = $this->page();

        $this->assertStringContainsString(
            'AI Assistant is disabled',
            $html,
            'the topbar is global chrome — a disabled Assistant must be explained there, not silently absent'
        );
        // Must not resurrect a live trigger.
        $this->assertStringNotContainsString('data-assistant-toggle', $html);
    }

    public function test_the_default_off_state_is_explained_not_silent(): void
    {
        // The upgrade case: a key is present, the setting was never saved, so
        // the Assistant is off purely because the default flipped.
        $this->withAnthropicConfigured();

        $this->assertNull(Setting::getValue('assistant_enabled'), 'precondition: setting absent');

        $this->assertStringContainsString('AI Assistant is disabled', $this->page());
    }

    public function test_a_deployment_with_no_ai_provider_is_not_nagged(): void
    {
        // No AI configured at all — this install never wanted an assistant and
        // must see nothing about one. Without this, the notice would appear on
        // every page of every deployment that simply does not use AI.
        Setting::setValue('assistant_enabled', '0');

        $html = $this->page();

        $this->assertStringNotContainsString('AI Assistant is disabled', $html);
        $this->assertStringNotContainsString('data-assistant-toggle', $html);
    }

    public function test_the_live_trigger_returns_when_the_assistant_is_enabled(): void
    {
        // Control: proves the disabled-state assertions are not passing simply
        // because the topbar never renders an assistant control at all.
        $this->withAnthropicConfigured();
        Setting::setValue('assistant_enabled', '1');

        $html = $this->page();

        $this->assertStringContainsString('data-assistant-toggle', $html);
        $this->assertStringNotContainsString('AI Assistant is disabled', $html);
    }
}
