<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\CalendarConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Calendar / Scheduling integrations settings card (psa-abl0i.2): the operator-facing door for
 * the staff MCP calendar toolset. Proves the master toggle persists, the owner-UPN allowlist is
 * normalized to a JSON array, a malformed entry REJECTS the whole save (never silently dropped),
 * and the empty case stores an empty list — the fail-closed security spine.
 */
class CalendarIntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_the_calendar_card_renders_on_the_integrations_page(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk();

        $response->assertSee('Calendar / Scheduling');
        $response->assertSee(route('settings.integrations.calendar.update'), false);
        // The security spine must be stated where the operator makes the decision.
        $response->assertSee('sole server-side control');
        $response->assertSee('fails closed');
    }

    public function test_saving_persists_enabled_and_a_normalized_allowlist(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.calendar.update'), [
                'calendar_enabled' => '1',
                // Messy on purpose: leading/trailing whitespace, a blank line, and a
                // comma-joined entry — all must normalize to a clean, order-preserving list.
                'calendar_allowed_owner_upns' => "  scheduler@yourmsp.com \n\n dispatch@yourmsp.com ,billing@yourmsp.com\n",
            ])
            ->assertRedirect(route('settings.integrations'));

        $expected = ['scheduler@yourmsp.com', 'dispatch@yourmsp.com', 'billing@yourmsp.com'];

        $this->assertTrue(CalendarConfig::isEnabled());
        $this->assertSame(json_encode($expected), Setting::getValue('calendar_allowed_owner_upns'));
        $this->assertSame($expected, CalendarConfig::allowedOwnerUpns());
        $this->assertTrue(CalendarConfig::ownerUpnAllowed('SCHEDULER@yourmsp.com'), 'matching is case-insensitive');
    }

    public function test_a_malformed_upn_is_rejected_and_nothing_is_saved(): void
    {
        $this->actingAs($this->user)
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.calendar.update'), [
                'calendar_enabled' => '1',
                'calendar_allowed_owner_upns' => "good@example.com\nnot-a-valid-upn",
            ])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHasErrors('calendar_owner_upns.1');

        // The refusal must name the remedy and say plainly that nothing was written.
        $this->assertStringContainsString(
            'nothing was saved',
            session('errors')->getBag('default')->first('calendar_owner_upns.1'),
        );

        // Nothing may be persisted on a rejected save — not the allowlist, and not the toggle
        // (a silently-dropped owner OR a half-applied enable would both be fail-open surprises).
        $this->assertNull(Setting::getValue('calendar_allowed_owner_upns'));
        $this->assertFalse(CalendarConfig::isEnabled());
    }

    public function test_an_empty_allowlist_stores_an_empty_list_fail_closed(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.calendar.update'), [
                'calendar_enabled' => '1',
                'calendar_allowed_owner_upns' => '',
            ])
            ->assertRedirect(route('settings.integrations'));

        // An empty textarea stores an empty JSON array — which denies every mailbox.
        $this->assertSame('[]', Setting::getValue('calendar_allowed_owner_upns'));
        $this->assertSame([], CalendarConfig::allowedOwnerUpns());
        $this->assertFalse(CalendarConfig::ownerUpnAllowed('anybody@yourmsp.com'), 'empty allowlist = no mailboxes');
    }

    public function test_unchecking_the_toggle_persists_an_explicit_zero(): void
    {
        Setting::setValue('calendar_enabled', '1');

        // An unchecked switch is absent from the browser POST — it must persist an explicit '0',
        // not leave the previous '1' standing (CalendarConfig requires the exact string "1").
        $this->actingAs($this->user)
            ->post(route('settings.integrations.calendar.update'), [
                'calendar_allowed_owner_upns' => 'scheduler@yourmsp.com',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('0', Setting::getValue('calendar_enabled'));
        $this->assertFalse(CalendarConfig::isEnabled());
    }
}
