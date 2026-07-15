<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-2wwh — the emergency kill switch gets a UI.
 *
 * Until now technician_kill_switch had ~16 production readers and ZERO writers
 * outside tests: engaging the brake on the entire AI write surface meant hand-
 * editing a settings row in prod MariaDB. That is the wrong ergonomics for the
 * one control whose whole purpose is speed under stress.
 *
 * Two safety properties are load-bearing here and each has a test that fails
 * loudly if a later refactor breaks it:
 *
 *  1. IT HAS ITS OWN FORM/ROUTE. The AI Technician form's semantics are
 *     "absent = off" (see TechnicianIntegrationToggleTest). Folding the kill
 *     switch into it would mean saving an unrelated setting silently DISARMS
 *     the emergency stop. A safety control must not be collateral damage.
 *  2. IT TAKES AN EXPLICIT, VALIDATED INTENT. `engaged` is required (0|1) —
 *     NOT $request->has(). An empty or malformed POST must 422, never
 *     silently disarm. Fail-closed: the only way to release the brake is to
 *     say so explicitly.
 */
class TechnicianKillSwitchUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // --- engage / disengage ---

    public function test_posting_engaged_engages_the_kill_switch(): void
    {
        $this->assertFalse(TechnicianConfig::killSwitchEngaged(), 'disengaged by default');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.kill-switch'), ['engaged' => '1'])
            ->assertRedirect(route('settings.integrations'));

        $this->assertTrue(TechnicianConfig::killSwitchEngaged());
    }

    public function test_posting_not_engaged_releases_the_kill_switch(): void
    {
        Setting::setValue('technician_kill_switch', '1');
        $this->assertTrue(TechnicianConfig::killSwitchEngaged());

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.kill-switch'), ['engaged' => '0'])
            ->assertRedirect(route('settings.integrations'));

        $this->assertFalse(TechnicianConfig::killSwitchEngaged());
    }

    // --- SAFETY 1: an unrelated save must not disarm the brake ---

    public function test_saving_unrelated_technician_settings_does_not_disarm_an_engaged_kill_switch(): void
    {
        // The brake is ON — an incident is in progress.
        Setting::setValue('technician_kill_switch', '1');

        // Someone saves the AI Technician card for an unrelated reason. That form's
        // semantics are "absent = off", so if the kill switch ever moves into it,
        // this save would silently release the brake mid-incident.
        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [
                'technician_enabled' => '1',
                'technician_digest_time' => '09:30',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertTrue(
            TechnicianConfig::killSwitchEngaged(),
            'an unrelated technician-settings save must NEVER release the emergency brake',
        );
    }

    // --- SAFETY 2: explicit intent only; malformed input fails closed ---

    public function test_a_post_with_no_intent_does_not_disarm_the_kill_switch(): void
    {
        Setting::setValue('technician_kill_switch', '1');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.kill-switch'), [])
            ->assertSessionHasErrors('engaged');

        $this->assertTrue(
            TechnicianConfig::killSwitchEngaged(),
            'a POST with no explicit intent must fail closed, never silently release the brake',
        );
    }

    public function test_a_post_with_a_garbage_intent_does_not_disarm_the_kill_switch(): void
    {
        Setting::setValue('technician_kill_switch', '1');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.kill-switch'), ['engaged' => 'maybe'])
            ->assertSessionHasErrors('engaged');

        $this->assertTrue(TechnicianConfig::killSwitchEngaged());
    }

    // --- auth ---

    public function test_the_kill_switch_route_requires_authentication(): void
    {
        $this->post(route('settings.integrations.technician.kill-switch'), ['engaged' => '1'])
            ->assertRedirect(route('login'));

        $this->assertFalse(TechnicianConfig::killSwitchEngaged());
    }

    // --- the control renders, and renders its true state ---

    public function test_the_integrations_page_renders_the_kill_switch_control(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Emergency stop')
            ->assertSee('Engage emergency stop')
            ->assertDontSee('Release emergency stop');
    }

    public function test_the_control_shows_when_the_brake_is_engaged(): void
    {
        Setting::setValue('technician_kill_switch', '1');

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('AI writes are paused')
            ->assertSee('Release emergency stop')
            ->assertDontSee('Engage emergency stop');
    }

    // --- SAFETY 4: the FORM must post the value its button promises ---

    /**
     * The review of 22f1602 caught the previous shape being broken in a real browser:
     * a hidden(0) + checkbox(1) pair sharing name="engaged" made form.engaged a
     * RadioNodeList (so `.checked` read undefined), and onchange="this.form.submit()"
     * bypassed onsubmit entirely, so the release confirmation never ran — one stray
     * toggle could release the brake silently. Every server-side test still passed,
     * because they POST the field directly and never touch the form.
     *
     * So: pin the FORM, not just the handler. Each state renders exactly ONE input
     * named `engaged`, carrying the value its button promises. If someone reintroduces
     * a second `engaged` input, or flips a value, these fail.
     */
    public function test_the_engage_form_posts_engaged_one_and_renders_a_single_engaged_input(): void
    {
        $html = $this->actingAs($this->user)->get(route('settings.integrations'))->getContent();
        $form = $this->killSwitchForm($html);

        $this->assertStringContainsString('name="engaged" value="1"', $form, 'the engage form must post engaged=1');
        $this->assertSame(1, substr_count($form, 'name="engaged"'), 'exactly ONE input named engaged — two makes form.engaged a RadioNodeList');
    }

    public function test_the_release_form_posts_engaged_zero_and_renders_a_single_engaged_input(): void
    {
        Setting::setValue('technician_kill_switch', '1');

        $html = $this->actingAs($this->user)->get(route('settings.integrations'))->getContent();
        $form = $this->killSwitchForm($html);

        $this->assertStringContainsString('name="engaged" value="0"', $form, 'the release form must post engaged=0');
        $this->assertSame(1, substr_count($form, 'name="engaged"'), 'exactly ONE input named engaged');
    }

    // --- SAFETY 5: engage is one click; release asks ---

    public function test_release_asks_for_confirmation_and_engage_does_not(): void
    {
        // Engaging is always safe — no friction on the way to safety.
        $engageForm = $this->killSwitchForm(
            $this->actingAs($this->user)->get(route('settings.integrations'))->getContent()
        );
        $this->assertStringNotContainsString('confirm(', $engageForm, 'engaging must be one click — never gated behind a dialog');

        // Releasing resumes autonomous client-facing writes — it must be deliberate.
        // The confirm hangs off a REAL submit button's onsubmit (which fires), not
        // form.submit() (which does not).
        Setting::setValue('technician_kill_switch', '1');
        $releaseForm = $this->killSwitchForm(
            $this->actingAs($this->user)->get(route('settings.integrations'))->getContent()
        );
        $this->assertStringContainsString('confirm(', $releaseForm, 'releasing must ask');
        $this->assertStringContainsString('type="submit"', $releaseForm, 'a real submit button — onsubmit does not fire for form.submit()');
        $this->assertStringNotContainsString('this.form.submit()', $releaseForm, 'form.submit() bypasses onsubmit and would skip the confirm');
    }

    /** Isolate the kill-switch form so assertions cannot accidentally match another card's markup. */
    private function killSwitchForm(string $html): string
    {
        $action = route('settings.integrations.technician.kill-switch');
        $start = strpos($html, $action);
        $this->assertNotFalse($start, 'the kill-switch form must be on the page');
        $formStart = strrpos(substr($html, 0, $start), '<form');
        $formEnd = strpos($html, '</form>', $start);

        return substr($html, $formStart, $formEnd - $formStart);
    }

    // --- SAFETY 3 (psa-0d0t): the control must not overstate its own reach ---

    public function test_the_control_states_what_it_does_not_stop(): void
    {
        // psa-0d0t: technician_kill_switch has ~16 readers, ALL in the agent/MCP
        // lane — zero in app/Services/Triage/. It therefore does NOT stop the
        // triage lane's own auto-closes (the hourly review close, and the junk
        // filter close which is ON by default). A brake labelled "stop the AI"
        // that leaves two of the five auto-close paths running would be a lie an
        // operator reads DURING an incident — the worst possible moment. The UI
        // must name its limit, not imply a reach it does not have.
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Does not stop AI triage')
            ->assertSee('triage');
    }
}
