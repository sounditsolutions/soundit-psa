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

    /**
     * Posts EXACTLY the given keys and nothing else.
     *
     * payload() above always fills all six, which is faithful to the panel's own form
     * and is exactly why it cannot see the omitted-key case: this handler is a plain
     * route, and a caller that posts only an api_key is a shape the form never
     * produces but the route accepts. Every test of "omitted is not blank" goes
     * through here.
     *
     * @param  array<string, mixed>  $fields
     */
    private function savePartial(array $fields)
    {
        return $this->actingAs($this->user)
            ->post(route('settings.integrations.controld.update'), $fields);
    }

    /**
     * The six settings as stored, for asserting that a submit left them untouched.
     *
     * @return array<string, ?string>
     */
    private function storedDefaults(): array
    {
        return [
            ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING => Setting::getValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING),
            ControlDConfig::DEFAULT_PROFILE_SETTING => Setting::getValue(ControlDConfig::DEFAULT_PROFILE_SETTING),
            ControlDConfig::CODE_EXPIRY_DAYS_SETTING => Setting::getValue(ControlDConfig::CODE_EXPIRY_DAYS_SETTING),
            ControlDConfig::CODE_DEVICE_LIMIT_HEADROOM_SETTING => Setting::getValue(ControlDConfig::CODE_DEVICE_LIMIT_HEADROOM_SETTING),
            ControlDConfig::CODE_ANALYTICS_LEVEL_SETTING => Setting::getValue(ControlDConfig::CODE_ANALYTICS_LEVEL_SETTING),
            ControlDConfig::CODE_INTERCEPT_MODE_SETTING => Setting::getValue(ControlDConfig::CODE_INTERCEPT_MODE_SETTING),
        ];
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

    // --- display normalisation: the form must round-trip what is stored ---
    //
    // The defect these pin: the three numbers render into <input type="number">, and
    // the HTML value sanitisation algorithm for that type replaces a value the parser
    // cannot read as a number with the EMPTY STRING. An empty number input is still a
    // valid one, so the field submits blank and the blank-clears rule erases the
    // setting. Opening this page and pressing Save therefore destroyed stored values
    // this class elsewhere promises are supported — ' 14 ' and "\t18\n" among them.
    //
    // Observed, not theorised: headless Chromium on the actual rendered page reported
    // DOM value empty, form valid and FormData empty for the stored "\t18\n" case,
    // and the real POST that followed cleared the setting.

    /**
     * The value attribute the page renders for ONE named input.
     *
     * A whole-page assertSee('value="18"') cannot say which of this page's hundred-odd
     * inputs carried it — which is exactly how a display defect on these three fields
     * survived the original tests. Scope by name or prove nothing.
     */
    private function renderedValue(string $name): string
    {
        $html = $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match('/<input\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>/', $html, $tag),
            "No single input named {$name} was rendered."
        );

        return preg_match('/\bvalue="([^"]*)"/', $tag[0], $m) === 1 ? $m[1] : '';
    }

    /**
     * What a browser's <input type="number"> would hold, given a rendered attribute.
     *
     * A model of the HTML value sanitisation algorithm for the number state: a value
     * that is not a valid floating-point number becomes ''. PHPUnit cannot run the
     * sanitiser, so without this the round-trip half of these tests would post the raw
     * attribute — which Laravel's TrimStrings would quietly rescue, hiding the very
     * destruction being pinned. Chromium's observed behaviour on this page matches.
     */
    private function asBrowserWouldSubmit(string $rendered): string
    {
        return preg_match('/^-?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?$/', $rendered) === 1 ? $rendered : '';
    }

    public function test_stored_edge_whitespace_renders_canonically_and_survives_a_resave(): void
    {
        // The exact shape test_a_directly_stored_integer_with_edge_whitespace_still_reads_back
        // declares supported. The panel canonicalises on write and never stores it; an
        // older writer or a hand-edited row can, and this page must not eat it.
        Setting::setValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING, "\t18\n");
        Setting::setValue(ControlDConfig::CODE_EXPIRY_DAYS_SETTING, ' 14 ');
        Setting::setValue(ControlDConfig::CODE_DEVICE_LIMIT_HEADROOM_SETTING, " 0\n");

        $this->assertSame('18', $this->renderedValue('tactical_client_field_id'));
        $this->assertSame('14', $this->renderedValue('code_expiry_days'));
        $this->assertSame('0', $this->renderedValue('code_device_limit_headroom'));

        // Now submit the form exactly as a browser holding those values would.
        $this->save([
            'tactical_client_field_id' => $this->asBrowserWouldSubmit($this->renderedValue('tactical_client_field_id')),
            'code_expiry_days' => $this->asBrowserWouldSubmit($this->renderedValue('code_expiry_days')),
            'code_device_limit_headroom' => $this->asBrowserWouldSubmit($this->renderedValue('code_device_limit_headroom')),
        ])->assertRedirect(route('settings.integrations'));

        $this->assertSame(18, ControlDConfig::tacticalClientOrgFieldId());
        $this->assertSame(14, ControlDConfig::codeExpiryDays());
        $this->assertSame(0, ControlDConfig::codeDeviceLimitHeadroom());
    }

    public function test_saving_an_unrelated_field_does_not_erase_a_supported_stored_number(): void
    {
        // The harm itself, pinned without asserting anything about the markup: the
        // operator opens the page to change the profile id, presses Save, and the
        // expiry they never touched is gone. The test above goes red on the render
        // before it reaches this, so this one carries the destruction on its own.
        Setting::setValue(ControlDConfig::CODE_EXPIRY_DAYS_SETTING, ' 14 ');

        $this->save([
            'default_profile_id' => 'a-new-profile',
            'code_expiry_days' => $this->asBrowserWouldSubmit($this->renderedValue('code_expiry_days')),
        ])->assertRedirect(route('settings.integrations'));

        $this->assertSame(14, ControlDConfig::codeExpiryDays());
    }

    public function test_zero_headroom_renders_as_zero_and_not_as_blank(): void
    {
        // Zero is a value, not an absence — the class docblock's second load-bearing
        // property. A renderer written with ?: or empty() collapses it to blank, and a
        // save would then clear the tightest legitimate setting there is.
        Setting::setValue(ControlDConfig::CODE_DEVICE_LIMIT_HEADROOM_SETTING, '0');

        $this->assertSame('0', $this->renderedValue('code_device_limit_headroom'));
    }

    public function test_the_largest_supported_integer_renders_unchanged(): void
    {
        Setting::setValue(ControlDConfig::CODE_EXPIRY_DAYS_SETTING, (string) PHP_INT_MAX);

        $this->assertSame((string) PHP_INT_MAX, $this->renderedValue('code_expiry_days'));
    }

    public function test_a_malformed_stored_number_renders_blank_and_is_reported(): void
    {
        // The other side of the distinction: 'abc' is not a supported value — every
        // ControlDConfig accessor already refuses it — so clearing it on save is
        // correct. What would not be correct is doing it silently, so the operator is
        // told what is stored and what saving will do before they press the button.
        Setting::setValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING, 'abc');

        $this->assertSame('', $this->renderedValue('tactical_client_field_id'));

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('is not a whole number')
            ->assertSee('<code>abc</code>', false);
    }

    public function test_a_canonical_stored_number_is_left_exactly_as_it_is(): void
    {
        // Preservation control: green before this change and after it. If normalising
        // the display ever alters a value that was already canonical, this goes red.
        Setting::setValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING, '18');

        $this->assertSame('18', $this->renderedValue('tactical_client_field_id'));

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertDontSee('is not a whole number');
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
            // "Defaults complete", deliberately not "Ready". The predicate behind this
            // badge checks four local values; it says nothing about the integration
            // being enabled, credentialed, vendor-validated or granted, and a badge
            // reading "Ready" would be read as all four.
            ->assertSee('Defaults complete')
            ->assertDontSee('>Ready<', false);
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

    // --- omitted is not blank (Jeeves review of 54073817, finding 1) ---

    public function test_an_api_key_only_submit_leaves_every_onboarding_default_alone(): void
    {
        // The defect this guards: writing all six unconditionally from `?? ''` meant a
        // caller who posted only an api_key silently erased the onboarding defaults,
        // and the operator's next symptom would be onboarding refusing for no visible
        // reason. Blank must clear; ABSENT must not.
        $this->save();
        $before = $this->storedDefaults();

        $this->savePartial(['api_key' => 'cd-key-1'])->assertSessionHasNoErrors();

        $this->assertSame($before, $this->storedDefaults());
        $this->assertTrue(ControlDConfig::isOnboardingConfigured());
    }

    public function test_a_partial_submit_clears_only_the_field_it_actually_posts(): void
    {
        $this->save();

        // One field posted blank, the rest not offered at all. Exactly one changes.
        $this->savePartial(['code_analytics_level' => 'off', 'code_expiry_days' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('', Setting::getValue(ControlDConfig::CODE_EXPIRY_DAYS_SETTING));
        $this->assertNull(ControlDConfig::codeExpiryDays());
        $this->assertSame('off', ControlDConfig::codeAnalyticsLevel());

        $this->assertSame('18', Setting::getValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING));
        $this->assertSame('5840sea5y7', Setting::getValue(ControlDConfig::DEFAULT_PROFILE_SETTING));
        $this->assertSame('5', Setting::getValue(ControlDConfig::CODE_DEVICE_LIMIT_HEADROOM_SETTING));
    }

    public function test_an_empty_submit_changes_nothing(): void
    {
        $this->save();
        $before = $this->storedDefaults();

        $this->savePartial([])->assertSessionHasNoErrors();

        $this->assertSame($before, $this->storedDefaults());
    }

    // --- what the panel accepts is what the accessor reads back (finding 2) ---

    public function test_it_canonicalises_a_signed_integer_the_validator_accepts(): void
    {
        // Laravel's integer rule is filter_var(FILTER_VALIDATE_INT), which accepts
        // '+14' and surrounding whitespace. Stored as typed, ControlDConfig would then
        // refuse it and the panel would have reported "saved" for a value onboarding
        // will not use. Store the canonical form instead.
        $this->save([
            'tactical_client_field_id' => '+18',
            'code_expiry_days' => ' 14 ',
            'code_device_limit_headroom' => '+0',
        ])->assertSessionHasNoErrors();

        $this->assertSame('18', Setting::getValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING));
        $this->assertSame('14', Setting::getValue(ControlDConfig::CODE_EXPIRY_DAYS_SETTING));
        $this->assertSame('0', Setting::getValue(ControlDConfig::CODE_DEVICE_LIMIT_HEADROOM_SETTING));

        $this->assertSame(18, ControlDConfig::tacticalClientOrgFieldId());
        $this->assertSame(14, ControlDConfig::codeExpiryDays());
        $this->assertSame(0, ControlDConfig::codeDeviceLimitHeadroom());
    }

    public function test_every_value_the_form_accepts_is_readable_by_its_accessor(): void
    {
        // The property in one test: accepted-by-the-panel implies usable-by-onboarding.
        foreach (['1', '+1', ' 7 ', '18', '2147483648', (string) PHP_INT_MAX] as $input) {
            $this->save(['code_expiry_days' => $input])->assertSessionHasNoErrors();

            $this->assertNotNull(
                ControlDConfig::codeExpiryDays(),
                "The panel accepted {$input} but the accessor refuses it."
            );
        }
    }

    public function test_the_form_rejects_input_that_is_not_exactly_an_integer(): void
    {
        foreach (['1.0', '1e3', '0x12', 'abc', '1 4', ['18'], '-1'] as $input) {
            $this->save(['tactical_client_field_id' => $input])
                ->assertSessionHasErrors('tactical_client_field_id');
        }

        // A whitespace-only field is NOT in that list, and the distinction is the
        // app's, not a choice made here: TrimStrings and ConvertEmptyStringsToNull run
        // before validation, so '   ' reaches the rule as null and is a clear. Asserted
        // rather than assumed, because "blank clears" resting on global middleware is
        // exactly the kind of behaviour that changes under someone else's refactor.
        $this->save(['tactical_client_field_id' => '18']);
        $this->save(['tactical_client_field_id' => '   '])->assertSessionHasNoErrors();
        $this->assertSame('', Setting::getValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING));
        $this->assertNull(ControlDConfig::tacticalClientOrgFieldId());

        // Beyond the platform int. Rejected at the form, so the saturating (int) cast
        // in the canonicaliser is never reached with a value it would silently shrink.
        $this->save(['code_expiry_days' => '9223372036854775808'])
            ->assertSessionHasErrors('code_expiry_days');
    }

    public function test_the_accessors_refuse_a_stored_integer_that_is_not_exactly_one(): void
    {
        // Not reachable through the panel — this is the accessors' own fail-closed
        // contract, against a settings row written by a migration, a seeder, a console
        // command or a hand edit. PHP's (int) cast SATURATES rather than failing, so
        // without the round-trip check '9223372036854775808' would read back as a
        // wholly plausible 9223372036854775807.
        foreach ([
            '9223372036854775808',
            '99999999999999999999',
            '007',
            '+18',
            '18.0',
            '1e3',
            'eighteen',
            '',
            '   ',
        ] as $stored) {
            Setting::setValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING, $stored);
            Setting::setValue(ControlDConfig::CODE_EXPIRY_DAYS_SETTING, $stored);
            Setting::setValue(ControlDConfig::CODE_DEVICE_LIMIT_HEADROOM_SETTING, $stored);

            $this->assertNull(
                ControlDConfig::tacticalClientOrgFieldId(),
                "A stored [{$stored}] must not read back as a field id."
            );
            $this->assertNull(
                ControlDConfig::codeExpiryDays(),
                "A stored [{$stored}] must not read back as an expiry."
            );
            $this->assertNull(
                ControlDConfig::codeDeviceLimitHeadroom(),
                "A stored [{$stored}] must not read back as headroom."
            );
            $this->assertFalse(ControlDConfig::isOnboardingConfigured());
        }
    }

    public function test_the_accessors_hold_their_floors_on_a_directly_stored_value(): void
    {
        // Zero is legal headroom and an illegal field id or expiry. The floor lives in
        // the accessor as well as the form, because the form is not the only writer.
        Setting::setValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING, '0');
        Setting::setValue(ControlDConfig::CODE_EXPIRY_DAYS_SETTING, '0');
        Setting::setValue(ControlDConfig::CODE_DEVICE_LIMIT_HEADROOM_SETTING, '0');

        $this->assertNull(ControlDConfig::tacticalClientOrgFieldId());
        $this->assertNull(ControlDConfig::codeExpiryDays());
        $this->assertSame(0, ControlDConfig::codeDeviceLimitHeadroom());
    }

    public function test_a_directly_stored_integer_with_edge_whitespace_still_reads_back(): void
    {
        // The other half of the refusal list above, pinned because readInt()'s docblock
        // now states it: edge whitespace is trimmed BEFORE the digit pattern runs, so a
        // stored ' 14 ' is 14 and not a refusal. The panel canonicalises on write and
        // never stores this shape; a hand-edited row or an older writer can. Trimming
        // here is the same input contract the class docblock states — this panel's
        // choice, not a claim about Control D — so the two must not drift.
        Setting::setValue(ControlDConfig::CODE_EXPIRY_DAYS_SETTING, ' 14 ');
        Setting::setValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING, "\t18\n");

        $this->assertSame(14, ControlDConfig::codeExpiryDays());
        $this->assertSame(18, ControlDConfig::tacticalClientOrgFieldId());
    }

    public function test_the_largest_representable_integer_survives_the_round_trip(): void
    {
        // The upper edge of what readInt() may return. There is deliberately no
        // vendor-shaped ceiling below this: what Control D considers a sane expiry or
        // device limit is not in its public reference, and a limit invented here would
        // refuse a legal value. Bounding belongs at the call site that does arithmetic.
        Setting::setValue(ControlDConfig::CODE_EXPIRY_DAYS_SETTING, (string) PHP_INT_MAX);

        $this->assertSame(PHP_INT_MAX, ControlDConfig::codeExpiryDays());
    }

    // --- the optional pair: trimmed, not otherwise touched (finding 3) ---

    public function test_a_whitespace_only_optional_value_is_blank(): void
    {
        // Blank an operator cannot see. It must clear like any other blank rather than
        // become a vendor value made of spaces.
        $this->save([
            'code_analytics_level' => '   ',
            'code_intercept_mode' => "\t\n ",
        ])->assertSessionHasNoErrors();

        $this->assertSame('', Setting::getValue(ControlDConfig::CODE_ANALYTICS_LEVEL_SETTING));
        $this->assertSame('', Setting::getValue(ControlDConfig::CODE_INTERCEPT_MODE_SETTING));
        $this->assertNull(ControlDConfig::codeAnalyticsLevel());
        $this->assertNull(ControlDConfig::codeInterceptMode());

        // And they are optional: readiness does not depend on them.
        $this->assertTrue(ControlDConfig::isOnboardingConfigured());
    }

    public function test_the_interior_of_a_vendor_value_is_preserved_exactly(): void
    {
        // Trimming is the ONLY normalisation. Case, separators and interior spacing are
        // the vendor's, and this is the test that would fail if someone later added a
        // strtolower() or a slug filter "for consistency".
        $this->save([
            'default_profile_id' => '  Prof-9_A.b  ',
            'code_analytics_level' => '  Level One  ',
            'code_intercept_mode' => '  Mode_TWO  ',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Prof-9_A.b', ControlDConfig::defaultProfileId());
        $this->assertSame('Level One', ControlDConfig::codeAnalyticsLevel());
        $this->assertSame('Mode_TWO', ControlDConfig::codeInterceptMode());
    }

    // --- boundaries and output escaping (finding 4) ---

    public function test_the_string_fields_accept_sixty_four_characters_and_reject_sixty_five(): void
    {
        foreach ([
            'default_profile_id',
            'code_analytics_level',
            'code_intercept_mode',
        ] as $field) {
            $this->save([$field => str_repeat('a', 64)])->assertSessionHasNoErrors();
            $this->save([$field => str_repeat('a', 65)])->assertSessionHasErrors($field);
        }
    }

    public function test_a_hostile_stored_value_is_escaped_when_rendered_back(): void
    {
        // These fields are echoed into the form unmasked, which is the point of them —
        // an operator has to see a device limit to correct it. Rendering is therefore
        // the one place a stored value reaches a browser, and it goes through Blade's
        // escaping {{ }}, never {!! !!}.
        $hostile = '"><script>alert(1)</script>';

        $this->save(['default_profile_id' => $hostile])->assertSessionHasNoErrors();
        $this->assertSame($hostile, ControlDConfig::defaultProfileId());

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee(e($hostile), false);
    }
}
