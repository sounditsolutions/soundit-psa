<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\ControlDConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Control D client-onboarding defaults on the Control D integrations card
 * (board card 6a9b3613, build item C — entry surface only).
 *
 * Two properties carry most of the weight here.
 *
 * The first is that a BLANK SUBMIT CLEARS. Every other credential on this page is a
 * secret where blank means "keep what is stored", and copying that convention onto
 * these fields would be a real defect: an operator who empties the Tactical field id
 * to stop onboarding writing to the wrong field would be told it was saved while the
 * old id kept working. These are not secrets, nothing is masked, and clearing is a
 * real action that re-arms ControlDConfig's fail-closed refusals.
 *
 * The second is that zero headroom is a VALUE, not an absence. It means the code
 * admits exactly the assets already on record, which is a legitimate tight setting;
 * an unset field means nobody has decided yet. Collapsing the two would turn a blank
 * form into the most restrictive possible code.
 *
 * Nothing reads these settings yet — the write methods, the onboarding service and
 * its verb are separate build items. These tests fix the storage contract they will
 * consume.
 */
class ControlDOnboardingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * The Control D form posts every field it owns on each save, so a partial payload
     * would not exercise the real submit. Callers override only what they test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'api_key' => '',
            'tactical_client_field_id' => '18',
            'default_profile_id' => '5840sea5y7',
            'code_expiry_days' => '14',
            'code_device_limit_headroom' => '5',
            'code_analytics_level' => '',
            'code_intercept_mode' => '',
        ], $overrides);
    }

    private function save(array $overrides = [])
    {
        return $this->actingAs($this->user)
            ->post(route('settings.integrations.controld.update'), $this->payload($overrides));
    }

    // --- rendering ---

    public function test_the_card_renders_the_six_onboarding_fields(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Client Onboarding Defaults')
            ->assertSee('name="tactical_client_field_id"', false)
            ->assertSee('name="default_profile_id"', false)
            ->assertSee('name="code_expiry_days"', false)
            ->assertSee('name="code_device_limit_headroom"', false)
            ->assertSee('name="code_analytics_level"', false)
            ->assertSee('name="code_intercept_mode"', false);
    }

    public function test_it_renders_stored_values_back_into_the_form(): void
    {
        $this->save(['tactical_client_field_id' => '18', 'default_profile_id' => 'abc123']);

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('value="18"', false)
            ->assertSee('value="abc123"', false);
    }

    public function test_the_readiness_badge_follows_the_four_required_values(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Incomplete');

        $this->save();

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Ready');
    }

    // --- persistence ---

    public function test_it_saves_every_onboarding_default(): void
    {
        $this->save([
            'tactical_client_field_id' => '18',
            'default_profile_id' => 'prof-9',
            'code_expiry_days' => '30',
            'code_device_limit_headroom' => '7',
            'code_analytics_level' => 'some-vendor-level',
            'code_intercept_mode' => 'some-vendor-mode',
        ])->assertRedirect(route('settings.integrations'));

        $this->assertSame('18', Setting::getValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING));
        $this->assertSame('prof-9', Setting::getValue(ControlDConfig::DEFAULT_PROFILE_SETTING));
        $this->assertSame('30', Setting::getValue(ControlDConfig::CODE_EXPIRY_DAYS_SETTING));
        $this->assertSame('7', Setting::getValue(ControlDConfig::CODE_DEVICE_LIMIT_HEADROOM_SETTING));
        $this->assertSame('some-vendor-level', Setting::getValue(ControlDConfig::CODE_ANALYTICS_LEVEL_SETTING));
        $this->assertSame('some-vendor-mode', Setting::getValue(ControlDConfig::CODE_INTERCEPT_MODE_SETTING));
    }

    public function test_saving_onboarding_defaults_does_not_disturb_the_api_key(): void
    {
        Setting::setEncrypted('controld_api_key', 'live-key');

        $this->save(['api_key' => '']);

        $this->assertSame('live-key', ControlDConfig::get('api_key'));
    }

    public function test_it_trims_surrounding_whitespace(): void
    {
        $this->save(['default_profile_id' => '  prof-9  ']);

        $this->assertSame('prof-9', Setting::getValue(ControlDConfig::DEFAULT_PROFILE_SETTING));
    }

    // --- blank clears, and that is the point ---

    public function test_a_blank_submit_clears_a_stored_field_id_and_rearms_the_refusal(): void
    {
        $this->save(['tactical_client_field_id' => '18']);
        $this->assertSame(18, ControlDConfig::tacticalClientOrgFieldId());

        $this->save(['tactical_client_field_id' => '']);

        $this->assertNull(
            ControlDConfig::tacticalClientOrgFieldId(),
            'Clearing the field must refuse again, not keep the old id the way a secret would.'
        );
    }

    public function test_a_blank_submit_clears_every_other_onboarding_default(): void
    {
        $this->save([
            'default_profile_id' => 'prof-9',
            'code_expiry_days' => '30',
            'code_device_limit_headroom' => '7',
            'code_analytics_level' => 'lvl',
            'code_intercept_mode' => 'mode',
        ]);

        $this->save([
            'default_profile_id' => '',
            'code_expiry_days' => '',
            'code_device_limit_headroom' => '',
            'code_analytics_level' => '',
            'code_intercept_mode' => '',
        ]);

        $this->assertNull(ControlDConfig::defaultProfileId());
        $this->assertNull(ControlDConfig::codeExpiryDays());
        $this->assertNull(ControlDConfig::codeDeviceLimitHeadroom());
        $this->assertNull(ControlDConfig::codeAnalyticsLevel());
        $this->assertNull(ControlDConfig::codeInterceptMode());
        $this->assertFalse(ControlDConfig::isOnboardingConfigured());
    }

    // --- validation ---

    public function test_it_rejects_a_zero_or_negative_tactical_field_id(): void
    {
        foreach (['0', '-1'] as $bad) {
            $this->save(['tactical_client_field_id' => $bad])
                ->assertSessionHasErrors('tactical_client_field_id');
        }

        $this->assertNull(Setting::getValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING));
    }

    public function test_it_rejects_a_non_numeric_tactical_field_id(): void
    {
        $this->save(['tactical_client_field_id' => 'eighteen'])
            ->assertSessionHasErrors('tactical_client_field_id');
    }

    public function test_it_rejects_a_zero_or_negative_expiry(): void
    {
        $this->save(['code_expiry_days' => '0'])
            ->assertSessionHasErrors('code_expiry_days');
    }

    public function test_it_rejects_negative_headroom_but_accepts_zero(): void
    {
        $this->save(['code_device_limit_headroom' => '-1'])
            ->assertSessionHasErrors('code_device_limit_headroom');

        $this->save(['code_device_limit_headroom' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            0,
            ControlDConfig::codeDeviceLimitHeadroom(),
            'Zero headroom is a decision — it must not read back as unset.'
        );
        $this->assertTrue(ControlDConfig::isOnboardingConfigured());
    }

    public function test_a_rejected_submit_writes_nothing(): void
    {
        $this->save(['default_profile_id' => 'prof-9']);

        $this->save([
            'tactical_client_field_id' => '0',
            'default_profile_id' => 'clobbered',
        ])->assertSessionHasErrors('tactical_client_field_id');

        $this->assertSame('prof-9', Setting::getValue(ControlDConfig::DEFAULT_PROFILE_SETTING));
    }

    // --- the vendor value space is deliberately not enumerated ---

    public function test_it_stores_vendor_values_verbatim_without_an_allow_list(): void
    {
        // These two are the vendor's value space, not ours, and POST /provision under a
        // sub-organization is absent from Control D's public reference. The panel must
        // therefore carry whatever the operator was told to use rather than second-guess
        // it; the real check is the read-back where the code is cut.
        $this->save([
            'code_analytics_level' => 'level_that_this_repo_has_never_heard_of',
            'code_intercept_mode' => 'mode_that_this_repo_has_never_heard_of',
        ])->assertSessionHasNoErrors();

        $this->assertSame('level_that_this_repo_has_never_heard_of', ControlDConfig::codeAnalyticsLevel());
        $this->assertSame('mode_that_this_repo_has_never_heard_of', ControlDConfig::codeInterceptMode());
    }

    // --- readiness ---

    public function test_readiness_requires_all_four_and_ignores_the_optional_pair(): void
    {
        $this->save(['code_analytics_level' => '', 'code_intercept_mode' => '']);
        $this->assertTrue(
            ControlDConfig::isOnboardingConfigured(),
            'Analytics level and intercept mode are optional here.'
        );

        foreach ([
            'tactical_client_field_id',
            'default_profile_id',
            'code_expiry_days',
            'code_device_limit_headroom',
        ] as $required) {
            $this->save([$required => '']);
            $this->assertFalse(
                ControlDConfig::isOnboardingConfigured(),
                "Onboarding must not report ready with {$required} blank."
            );
            $this->save();
        }
    }
}
