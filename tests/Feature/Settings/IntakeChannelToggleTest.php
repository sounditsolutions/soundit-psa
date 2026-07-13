<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\AgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-28j4 §3.2 — the "AI Intake (front door)" card on the integrations page.
 *
 * Two Setting-backed booleans, saved through the established
 * IntegrationsController + settings/integrations.blade.php idiom.
 *
 * Auth gate: settings routes live inside Route::middleware('auth')->group(),
 * so actingAs($user) with any valid user is all that is required.
 */
class IntakeChannelToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ── persistence: each key round-trips independently ──────────────────────

    public function test_posting_both_checked_enables_both_channels(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.intake.update'), [
                'intake_call_enabled' => '1',
                'intake_email_enabled' => '1',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('1', Setting::getValue('intake_call_enabled'));
        $this->assertSame('1', Setting::getValue('intake_email_enabled'));
        $this->assertTrue(AgentConfig::intakeCallEnabled());
        $this->assertTrue(AgentConfig::intakeEmailEnabled());
    }

    /** The headline case: close calls, keep email. */
    public function test_posting_email_only_closes_calls_and_keeps_email(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.intake.update'), [
                'intake_email_enabled' => '1',
                // intake_call_enabled unchecked → absent from the POST
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertFalse(AgentConfig::intakeCallEnabled());
        $this->assertTrue(AgentConfig::intakeEmailEnabled());
    }

    public function test_posting_call_only_closes_email_and_keeps_calls(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.intake.update'), [
                'intake_call_enabled' => '1',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertTrue(AgentConfig::intakeCallEnabled());
        $this->assertFalse(AgentConfig::intakeEmailEnabled());
    }

    /**
     * An unchecked box must persist an explicit '0', not merely leave the key absent —
     * otherwise the channel would silently fall back to the legacy master and the
     * operator's "off" would not stick.
     */
    public function test_unchecked_boxes_persist_an_explicit_zero_that_beats_the_legacy_key(): void
    {
        Setting::setValue('intake_enabled', '1'); // legacy master ON

        $this->actingAs($this->user)
            ->post(route('settings.integrations.intake.update'), [])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('0', Setting::getValue('intake_call_enabled'), 'an unchecked box must write an explicit 0');
        $this->assertSame('0', Setting::getValue('intake_email_enabled'));
        $this->assertFalse(AgentConfig::intakeCallEnabled(), 'the explicit 0 must beat the legacy master ON');
        $this->assertFalse(AgentConfig::intakeEmailEnabled());
    }

    /** Saving the intake card must not disturb the legacy master key. */
    public function test_saving_does_not_rewrite_the_legacy_key(): void
    {
        Setting::setValue('intake_enabled', '1');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.intake.update'), ['intake_call_enabled' => '1'])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('1', Setting::getValue('intake_enabled'), 'the legacy key is left exactly as the operator set it');
    }

    public function test_save_flashes_a_success_message(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.intake.update'), [])
            ->assertSessionHas('success');
    }

    // ── render: the blade shows the EFFECTIVE state ──────────────────────────

    public function test_integrations_page_renders_the_intake_card(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('AI Intake')
            ->assertSee('intake_call_enabled', false)
            ->assertSee('intake_email_enabled', false);
    }

    /**
     * CRITICAL SILENT-FLIP GUARD. On a deployment carrying only the legacy
     * intake_enabled=1, both channels are effectively ON. The card must render both
     * boxes CHECKED — if it rendered the raw (absent) per-channel keys instead, the
     * boxes would show unchecked and an operator pressing Save without touching
     * anything would silently switch intake OFF.
     */
    public function test_card_renders_checked_when_only_the_legacy_key_is_set(): void
    {
        Setting::setValue('intake_enabled', '1');

        $html = $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="intake_call_enabled"[^>]*\bchecked\b/',
            $html,
            'legacy intake_enabled=1 must render the CALL box checked (else Save silently disables intake)',
        );
        $this->assertMatchesRegularExpression(
            '/id="intake_email_enabled"[^>]*\bchecked\b/',
            $html,
            'legacy intake_enabled=1 must render the EMAIL box checked',
        );
    }

    /** And the round-trip: a saved per-channel state renders back faithfully. */
    public function test_card_renders_the_saved_per_channel_state(): void
    {
        Setting::setValue('intake_call_enabled', '0');
        Setting::setValue('intake_email_enabled', '1');

        $html = $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/id="intake_call_enabled"[^>]*\bchecked\b/',
            $html,
            'a saved call-OFF must render unchecked',
        );
        $this->assertMatchesRegularExpression(
            '/id="intake_email_enabled"[^>]*\bchecked\b/',
            $html,
            'a saved email-ON must render checked',
        );
    }
}
