<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Support\HuntressConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Settings > Integrations > Huntress: the Tactical CLIENT custom field id that
 * carries each client's Huntress org key (allowlist key huntress_org_key on
 * tactical_set_client_custom_field). Card 6afe0780.
 *
 * Same storage contract as the Control D field id (ControlDOnboardingSettingsTest):
 * not a secret, so a BLANK submit clears and an OMITTED key leaves it alone; the
 * value is stored canonically so the typed reader accepts exactly what was saved;
 * and a stored value the reader refuses renders blank with a warning rather than
 * being put into a number input that would blank it silently.
 *
 * No Huntress or Tactical call is made. All values are synthetic.
 */
class HuntressOrgKeyFieldSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function save(array $fields)
    {
        return $this->actingAs($this->admin())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.huntress.update'), $fields);
    }

    private function raw(): ?string
    {
        return DB::table('settings')->where('key', HuntressConfig::TACTICAL_CLIENT_ORG_KEY_FIELD_SETTING)->value('value');
    }

    public function test_the_field_id_round_trips_through_the_form(): void
    {
        $this->save(['tactical_org_key_field_id' => '51'])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHasNoErrors();

        $this->assertSame('51', $this->raw());
        $this->assertSame(51, HuntressConfig::tacticalClientOrgKeyFieldId());

        $this->actingAs($this->admin())->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('name="tactical_org_key_field_id"', false)
            ->assertSee('value="51"', false);
    }

    public function test_a_blank_submit_clears_the_stored_id(): void
    {
        Setting::setValue(HuntressConfig::TACTICAL_CLIENT_ORG_KEY_FIELD_SETTING, '51');

        $this->save(['tactical_org_key_field_id' => ''])->assertSessionHasNoErrors();

        $this->assertSame('', (string) $this->raw());
        $this->assertNull(HuntressConfig::tacticalClientOrgKeyFieldId());
    }

    public function test_a_credentials_only_submit_leaves_the_stored_id_alone(): void
    {
        Setting::setValue(HuntressConfig::TACTICAL_CLIENT_ORG_KEY_FIELD_SETTING, '51');

        $this->save(['api_key' => 'synthetic-key', 'api_secret' => 'synthetic-secret'])->assertSessionHasNoErrors();

        $this->assertSame('51', $this->raw());
    }

    public function test_a_signed_value_is_stored_canonically(): void
    {
        // Laravel's integer rule accepts '+51'; the reader refuses it. Storing it as
        // typed would report "saved" for a value every write then refuses.
        $this->save(['tactical_org_key_field_id' => '+51'])->assertSessionHasNoErrors();

        $this->assertSame('51', $this->raw());
        $this->assertSame(51, HuntressConfig::tacticalClientOrgKeyFieldId());
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-3'],
            'not a number' => ['abc'],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function test_a_non_positive_or_non_integer_id_is_refused_and_nothing_is_saved(string $value): void
    {
        Setting::setValue(HuntressConfig::TACTICAL_CLIENT_ORG_KEY_FIELD_SETTING, '51');

        $this->save(['tactical_org_key_field_id' => $value])->assertSessionHasErrors('tactical_org_key_field_id');

        $this->assertSame('51', $this->raw());
    }

    public function test_an_unusable_stored_value_renders_blank_with_a_warning(): void
    {
        Setting::setValue(HuntressConfig::TACTICAL_CLIENT_ORG_KEY_FIELD_SETTING, '051');

        $this->assertNull(HuntressConfig::tacticalClientOrgKeyFieldId());

        $this->actingAs($this->admin())->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('id="huntress-org-key-field-unusable"', false)
            ->assertSee('<code>051</code>', false)
            ->assertDontSee('value="051"', false)
            ->assertSee('Leaving this field blank when saving clears it; enter a valid ID to replace it.')
            ->assertDontSee('An administrator can clear or replace it.');
    }

    public function test_a_non_admin_with_an_unusable_stored_value_is_told_an_administrator_fixes_it(): void
    {
        Setting::setValue(HuntressConfig::TACTICAL_CLIENT_ORG_KEY_FIELD_SETTING, '0042x');
        $tech = User::factory()->create(['role' => UserRole::Tech, 'is_active' => true]);

        $this->actingAs($tech)->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('id="huntress-org-key-field-unusable"', false)
            ->assertSee('<code>0042x</code>', false)
            ->assertSee('An administrator can clear or replace it.')
            ->assertDontSee('Leaving this field blank');
    }

    public function test_a_non_admin_submit_refused_for_the_key_pair_saves_no_field_id(): void
    {
        $tech = User::factory()->create(['role' => UserRole::Tech, 'is_active' => true]);

        $this->actingAs($tech)
            ->post(route('settings.integrations.huntress.update'), [
                'api_key' => 'synthetic-key',
                'tactical_org_key_field_id' => '51',
            ])
            ->assertForbidden();

        $this->assertNull($this->raw());
    }

    public function test_a_non_admin_submit_carrying_only_the_field_id_is_refused_and_saves_nothing(): void
    {
        Setting::setValue(HuntressConfig::TACTICAL_CLIENT_ORG_KEY_FIELD_SETTING, '42');
        $tech = User::factory()->create(['role' => UserRole::Tech, 'is_active' => true]);

        $this->actingAs($tech)
            ->post(route('settings.integrations.huntress.update'), [
                'tactical_org_key_field_id' => '51',
            ])
            ->assertForbidden();

        $this->assertSame('42', $this->raw());
    }

    public function test_a_non_admin_blank_field_id_submit_is_refused_and_clears_nothing(): void
    {
        Setting::setValue(HuntressConfig::TACTICAL_CLIENT_ORG_KEY_FIELD_SETTING, '42');
        $tech = User::factory()->create(['role' => UserRole::Tech, 'is_active' => true]);

        $this->actingAs($tech)
            ->post(route('settings.integrations.huntress.update'), [
                'tactical_org_key_field_id' => '',
            ])
            ->assertForbidden();

        $this->assertSame('42', $this->raw());
    }

    public function test_an_admin_submit_carrying_only_the_field_id_saves_it(): void
    {
        $this->save(['tactical_org_key_field_id' => '51'])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHasNoErrors();

        $this->assertSame('51', $this->raw());
    }

    public function test_a_non_admin_sees_the_field_id_read_only_with_no_submittable_input(): void
    {
        Setting::setValue(HuntressConfig::TACTICAL_CLIENT_ORG_KEY_FIELD_SETTING, '42');
        $tech = User::factory()->create(['role' => UserRole::Tech, 'is_active' => true]);

        $response = $this->actingAs($tech)->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('id="huntress-org-key-admin-only"', false)
            ->assertSee('An administrator sets this.')
            ->assertDontSee('name="tactical_org_key_field_id"', false);

        $this->assertSame(1, preg_match('/<input\b[^>]*\bid="huntress_tactical_org_key_field_id"[^>]*>/s', $response->getContent(), $m));
        $this->assertMatchesRegularExpression('/\sreadonly[\s>]/', $m[0]);
        $this->assertStringContainsString('value="42"', $m[0]);
    }

    public function test_an_admin_sees_the_editable_input_and_not_the_admin_only_note(): void
    {
        $this->actingAs($this->admin())->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('name="tactical_org_key_field_id"', false)
            ->assertDontSee('id="huntress-org-key-admin-only"', false);

        $html = $this->actingAs($this->admin())->get(route('settings.integrations'))->getContent();
        $this->assertSame(1, preg_match('/<input\b[^>]*\bid="huntress_tactical_org_key_field_id"[^>]*>/s', $html, $m));
        $this->assertStringNotContainsString('readonly', $m[0]);
        $this->assertStringNotContainsString('disabled', $m[0]);
    }

    public function test_an_edge_whitespace_value_reads_back_trimmed(): void
    {
        Setting::setValue(HuntressConfig::TACTICAL_CLIENT_ORG_KEY_FIELD_SETTING, ' 51 ');

        $this->assertSame(51, HuntressConfig::tacticalClientOrgKeyFieldId());
    }
}
