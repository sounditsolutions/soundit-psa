<?php

namespace Tests\Feature\Assistant;

use App\Models\Setting;
use App\Models\User;
use App\Support\AssistantConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-e317t / psa-uw2o.13 (F4): the settings card could silently rewrite the
 * safety gate it exists to restore.
 *
 * The card rendered its checkbox from the COMPOSITE AssistantConfig::isEnabled()
 * — which is `operator intent AND eligibility`. So on an install with
 * ai_provider=openai, a key present, and a stored assistant_enabled='1', the box
 * rendered UNCHECKED even though the operator had switched it on. An unchecked
 * box is absent from a browser's POST body, so merely saving the card to change
 * "Messages per Conversation" wrote assistant_enabled='0' and destroyed the
 * stored intent. Nothing told the operator that had happened.
 *
 * That is what made it a blocker rather than a cosmetic bug: every disabled
 * notice in the UI directs the operator to this card to turn the Assistant back
 * on, so the recovery path was booby-trapped.
 *
 * The fix is the distinction the whole bead turns on: a checkbox records
 * OPERATOR INTENT ("I want this on"), which is NOT the same question as
 * ELIGIBILITY ("it can run here"). Intent drives the checkbox; eligibility is
 * reported separately, next to it.
 *
 * This repo had already learned this exact lesson once — see
 * Tests\Feature\Settings\IntakeChannelToggleTest's "CRITICAL SILENT-FLIP GUARD",
 * and the comment in IntegrationsController::index() that it produced. The
 * Assistant card shipped with the inverse of that bug and no guard at all.
 */
class AssistantSettingsCardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function html(): string
    {
        return (string) $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->getContent();
    }

    /** The checkbox tag exactly as rendered, so tests can read its checked state. */
    private function checkboxTag(string $html): string
    {
        $found = preg_match('/<input[^>]*id="assistant_enabled"[^>]*>/', $html, $m);

        // Never let this guard pass on nothing: a rename would otherwise make
        // every "is it checked" assertion below trivially true.
        $this->assertSame(1, $found, 'the assistant_enabled checkbox was not rendered at all — this guard would silently pass on an empty haystack');

        return $m[0];
    }

    /**
     * Replays what a REAL BROWSER would submit from the page as rendered:
     * an unchecked checkbox is simply absent from the POST body, a checked one
     * is sent. This is the whole mechanism of the bug — asserting on the
     * checkbox attribute alone would not prove the setting actually survives.
     */
    private function saveCardAsBrowserWould(string $html): void
    {
        $payload = [
            // the innocuous edit the operator actually came here to make
            'assistant_max_messages' => 60,
            'assistant_daily_token_limit' => 500000,
        ];

        if (str_contains($this->checkboxTag($html), 'checked')) {
            $payload['assistant_enabled'] = '1';
        }

        $this->actingAs($this->user)
            ->post(route('settings.integrations.assistant.update'), $payload)
            ->assertRedirect();
    }

    private function withKey(string $provider): void
    {
        Setting::setValue('ai_provider', $provider);
        Setting::setEncrypted('ai_api_key', 'test-key');
    }

    // ── the checkbox reflects stored intent, not the composite ───────────────

    public function test_the_checkbox_shows_stored_intent_when_the_provider_is_not_anthropic(): void
    {
        $this->withKey('openai');
        Setting::setValue('assistant_enabled', '1');

        $this->assertStringContainsString(
            'checked',
            $this->checkboxTag($this->html()),
            'the operator switched this on; the box must show what they chose, not whether it can currently run'
        );
    }

    public function test_the_checkbox_shows_stored_intent_when_no_api_key_is_configured(): void
    {
        Setting::setValue('ai_provider', 'anthropic'); // provider set, but no key
        Setting::setValue('assistant_enabled', '1');

        $this->assertStringContainsString(
            'checked',
            $this->checkboxTag($this->html()),
            'a missing key is an eligibility problem, not a record of what the operator wants'
        );
    }

    // ── the headline: saving the card must not rewrite the gate ──────────────

    public function test_editing_an_unrelated_field_does_not_silently_disable_the_assistant(): void
    {
        $this->withKey('openai');
        Setting::setValue('assistant_enabled', '1');

        $this->saveCardAsBrowserWould($this->html());

        $this->assertSame(
            '1',
            Setting::getValue('assistant_enabled'),
            'saving this card to change the message cap must not destroy the stored on/off intent — '.
            'this card is the documented recovery path for a disabled Assistant'
        );
    }

    public function test_a_round_trip_save_is_stable_for_an_eligible_install(): void
    {
        $this->withKey('anthropic');
        Setting::setValue('assistant_enabled', '1');

        $this->saveCardAsBrowserWould($this->html());

        $this->assertSame('1', Setting::getValue('assistant_enabled'));
        $this->assertTrue(AssistantConfig::isEnabled(), 'an eligible, switched-on Assistant must survive a save untouched');
    }

    // ── CONTROLS: the fix must not weaken the off switch ─────────────────────

    public function test_unticking_the_box_still_disables_the_assistant(): void
    {
        // The obvious wrong fix for the above is to stop writing '0', or to
        // render the box always-checked. Either would break the off switch,
        // which is the entire point of this bead.
        $this->withKey('anthropic');
        Setting::setValue('assistant_enabled', '1');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.assistant.update'), [
                'assistant_max_messages' => 50,
                'assistant_daily_token_limit' => 500000,
                // assistant_enabled deliberately absent — the operator unticked it
            ])
            ->assertRedirect();

        $this->assertSame('0', Setting::getValue('assistant_enabled'), 'an unticked box must persist an explicit 0');
        $this->assertFalse(AssistantConfig::isEnabled(), 'unticking the box must actually stop the Assistant');
    }

    public function test_an_unticked_box_renders_unticked(): void
    {
        // Control for the two "shows stored intent" tests above: without this
        // they could pass on a box that is simply always checked.
        $this->withKey('anthropic');
        Setting::setValue('assistant_enabled', '0');

        $this->assertStringNotContainsString('checked', $this->checkboxTag($this->html()));
    }

    public function test_the_box_renders_unticked_by_default(): void
    {
        // psa-98dq: the Assistant DEFAULTS OFF. The card must not present an
        // absent setting as an intent to run.
        $this->withKey('anthropic');
        $this->assertNull(Setting::getValue('assistant_enabled'), 'precondition: setting absent');

        $this->assertStringNotContainsString('checked', $this->checkboxTag($this->html()));
    }

    // ── eligibility is reported, not folded into the checkbox ────────────────

    public function test_the_card_says_why_the_assistant_cannot_run_on_a_non_anthropic_provider(): void
    {
        $this->withKey('openai');
        Setting::setValue('assistant_enabled', '1');

        $html = $this->html();

        $this->assertStringContainsString(
            'cannot run with the current AI settings',
            $html,
            'switched on but ineligible is a real state and must be stated, not silently rendered as "off"'
        );
        $this->assertStringContainsString('Anthropic', $html);
    }

    public function test_the_card_does_not_claim_active_when_it_cannot_run(): void
    {
        // The other way to get this wrong: report intent as if it were effect.
        $this->withKey('openai');
        Setting::setValue('assistant_enabled', '1');

        $html = $this->html();

        $this->assertStringContainsString('cannot run with the current AI settings', $html);
        $this->assertStringNotContainsString(
            'badge bg-success ms-2">Active',
            $html,
            'an Assistant that cannot run must never be badged Active'
        );
    }

    public function test_an_eligible_switched_on_assistant_is_badged_active_and_shows_no_eligibility_warning(): void
    {
        // Control: proves the two assertions above are not passing simply
        // because the card never says "Active" or always says "cannot run".
        $this->withKey('anthropic');
        Setting::setValue('assistant_enabled', '1');

        $html = $this->html();

        $this->assertStringContainsString('badge bg-success ms-2">Active', $html);
        $this->assertStringNotContainsString('cannot run with the current AI settings', $html);
    }
}
