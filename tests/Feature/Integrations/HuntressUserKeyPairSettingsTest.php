<?php

namespace Tests\Feature\Integrations;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Services\Huntress\HuntressWriteClient;
use App\Services\Huntress\HuntressWriteScopeException;
use App\Support\HuntressConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Settings > Integrations > Huntress: one API key pair for reads and
 * escalation resolution, and the admin-only control that clears an earlier,
 * separate user API key pair (issues #1023 and #581 introduced that pair).
 *
 * WHAT THESE CONTROLS PIN
 * -----------------------
 * The card renders one "API key" / "API secret" pair and no user-pair inputs;
 * the pair round-trips encrypted and is never rendered back; a blank submit
 * keeps it; the field hint and the status line read the stored state in the
 * approved words; the notice and tick box show while either half of the
 * earlier pair is stored, the tick box to administrators only; clearing
 * removes both halves and nothing else; a non-admin clear is refused 403 and
 * saves nothing; the API key and API secret inputs render to administrators
 * only, and a non-admin submit carrying either half is refused 403 and saves
 * nothing; a submit naming the retired user inputs writes nothing to
 * the earlier pair; and the remedy location names labels the page renders.
 *
 * WHAT THEY DO NOT
 * ----------------
 * No Huntress call is made. All values are synthetic.
 */
class HuntressUserKeyPairSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-user-key-fixture-value';

    private const SECRET_VALUE = 'synthetic-user-secret-fixture-value';

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function tech(): User
    {
        return User::factory()->create(['role' => UserRole::Tech, 'is_active' => true]);
    }

    private function save(array $fields, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.huntress.update'), $fields);
    }

    private function raw(string $key): ?string
    {
        return DB::table('settings')->where('key', $key)->value('value');
    }

    private function storeEarlierPair(string $key = self::KEY, string $secret = self::SECRET_VALUE): void
    {
        Setting::setEncrypted('huntress_user_api_key', $key);
        Setting::setEncrypted('huntress_user_api_secret', $secret);
    }

    private function storeMainPair(): void
    {
        Setting::setEncrypted('huntress_api_key', 'synthetic-main-key');
        Setting::setEncrypted('huntress_api_secret', 'synthetic-main-secret');
    }

    private function page(?User $as = null): string
    {
        $response = $this->actingAs($as ?? $this->admin())->get(route('settings.integrations'));
        $response->assertOk();

        return $response->getContent();
    }

    /** The Huntress API card from its header to its Save button. */
    private function card(string $html): string
    {
        $start = strpos($html, 'Huntress EDR / ITDR');
        $this->assertNotFalse($start, 'precondition failed: the Huntress card did not render');
        $end = strpos($html, 'Save Huntress Settings', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    // ---- one pair on the card ----

    public function test_the_card_renders_one_pair_and_no_user_pair_inputs(): void
    {
        $card = $this->card($this->page());

        $this->assertStringContainsString('>API key</label>', $card);
        $this->assertStringContainsString('>API secret</label>', $card);
        $this->assertStringContainsString('name="api_key"', $card);
        $this->assertStringContainsString('name="api_secret"', $card);
        $this->assertStringNotContainsString('name="user_api_key"', $card);
        $this->assertStringNotContainsString('name="user_api_secret"', $card);
        $this->assertStringNotContainsString('Escalation resolution (write) credential', $card);
        $this->assertStringNotContainsString('User API key</label>', $card);
    }

    public function test_the_setup_text_says_create_a_user_api_key_and_what_it_carries(): void
    {
        $card = $this->card($this->page());

        $this->assertStringContainsString('In Huntress, create a <strong>user API key</strong> for the Huntress user the PSA should act as. (Huntress has deprecated the account-level API key.)', $card);
        $this->assertStringContainsString("The key carries that user's permissions in Huntress. A read-only user's key gives reads only. Resolving SOC escalations needs a user whose role may resolve them.", html_entity_decode($card, ENT_QUOTES));
        $this->assertStringNotContainsString('Account &rarr; API Credentials', $card);
    }

    // ---- round trip, never echoed, blank keeps ----

    public function test_the_pair_round_trips_encrypted_through_the_form(): void
    {
        $this->save(['api_key' => self::KEY, 'api_secret' => self::SECRET_VALUE])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHasNoErrors();

        $this->assertStringNotContainsString(self::KEY, (string) $this->raw('huntress_api_key'), 'the API key is stored in plaintext');
        $this->assertStringNotContainsString(self::SECRET_VALUE, (string) $this->raw('huntress_api_secret'), 'the API secret is stored in plaintext');
        $this->assertSame(self::KEY, HuntressConfig::get('api_key'));
        $this->assertSame(self::SECRET_VALUE, HuntressConfig::get('api_secret'));
        $this->assertTrue(HuntressConfig::isConfigured());
    }

    public function test_the_page_never_renders_a_stored_value(): void
    {
        Setting::setEncrypted('huntress_api_key', self::KEY);
        Setting::setEncrypted('huntress_api_secret', self::SECRET_VALUE);
        $this->storeEarlierPair('synthetic-earlier-key', 'synthetic-earlier-secret');

        $html = $this->page();
        $this->card($html); // precondition: the card rendered

        foreach ([self::KEY, self::SECRET_VALUE, 'synthetic-earlier-key', 'synthetic-earlier-secret', $this->raw('huntress_api_key'), $this->raw('huntress_user_api_key')] as $value) {
            $this->assertStringNotContainsString($value, $html, 'a stored value or its ciphertext was rendered into the page');
        }
    }

    public function test_a_blank_submit_keeps_the_stored_pair(): void
    {
        $this->storeMainPair();
        $before = [$this->raw('huntress_api_key'), $this->raw('huntress_api_secret')];

        $this->save(['api_key' => '', 'api_secret' => ''])->assertSessionHasNoErrors();
        $this->save([])->assertSessionHasNoErrors();

        $this->assertSame($before, [$this->raw('huntress_api_key'), $this->raw('huntress_api_secret')], 'a blank submit rewrote the stored pair');
    }

    // ---- field hint ----

    public function test_the_field_hint_reads_the_stored_state(): void
    {
        $card = $this->card($this->page());
        $this->assertSame(2, substr_count($card, '<div class="form-text">Encrypted at rest.</div>'), 'nothing saved: the hint has no value to keep');
        $this->assertStringNotContainsString('Leave blank to keep the saved value.', $card);
        $this->assertStringContainsString('placeholder="Enter user API key"', $card);
        $this->assertStringContainsString('placeholder="Enter user API secret"', $card);

        $this->storeMainPair();
        $card = $this->card($this->page());
        $this->assertSame(2, substr_count($card, 'Saved. Encrypted at rest. Leave blank to keep the saved value.'));
        $this->assertSame(2, substr_count($card, 'placeholder="••••••••"'));
    }

    // ---- status line ----

    public function test_the_status_line_when_no_pair_is_saved(): void
    {
        $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);

        $this->assertStringContainsString('Escalation resolution:</strong> unavailable until an API key and API secret are saved. Once saved, who may use it is set per MCP token on Settings > MCP tokens.', $card);
        $this->assertStringNotContainsString('uses this key', $card);
    }

    public function test_the_status_line_when_the_pair_is_saved(): void
    {
        $this->storeMainPair();

        $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);

        $this->assertStringContainsString("Escalation resolution:</strong> uses this key. Available only to MCP tokens granted it on Settings > MCP tokens, and every resolve waits for a person's approval in the cockpit. Huntress refuses it unless this key's user may resolve escalations.", $card);
        $this->assertStringNotContainsString('unavailable until', $card);
        $this->assertStringNotContainsString('Earlier user API key still in use', $card);
    }

    public function test_the_status_line_and_notice_while_the_earlier_pair_is_stored(): void
    {
        $this->storeMainPair();
        $this->storeEarlierPair();

        $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);

        $this->assertStringContainsString("Escalation resolution:</strong> uses the earlier user API key pair (see below), not this key, until an administrator clears it. Available only to MCP tokens granted it on Settings > MCP tokens, and every resolve waits for a person's approval in the cockpit.", $card);
        $this->assertStringNotContainsString('uses this key.', $card);
        $this->assertStringContainsString('Earlier user API key still in use for resolve.</strong> This install saved a separate user API key pair for escalation resolution before the card moved to one key pair. Resolve keeps using that earlier pair until an administrator clears it here. Saving a new API key and API secret above changes reads only. Nothing has been deleted.', $card);
        $this->assertStringContainsString('>Clear the earlier user API key pair</label>', $card);
        $this->assertStringContainsString('Removes both halves when you save. Resolve then uses the API key and API secret above.', $card);
    }

    public function test_a_lone_earlier_half_shows_the_lone_half_notice_and_the_status_line_says_this_key(): void
    {
        foreach (['huntress_user_api_key', 'huntress_user_api_secret'] as $half) {
            Setting::query()->delete();
            $this->storeMainPair();
            Setting::setEncrypted($half, self::SECRET_VALUE);

            $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);

            $this->assertStringContainsString('Escalation resolution:</strong> uses this key.', $card, $half);
            $this->assertStringContainsString('Part of an earlier user API key pair is still stored.</strong> It is not used: resolve uses the API key and API secret above. An administrator can clear it here.', $card, $half);
            $this->assertStringNotContainsString('Earlier user API key still in use for resolve.', $card, "{$half}: the full-pair notice is false for a lone half");
            $this->assertStringContainsString('name="clear_user_api_key_pair"', $card, 'a lone half must stay clearable');
        }
    }

    public function test_the_full_pair_notice_does_not_carry_the_lone_half_text(): void
    {
        $this->storeMainPair();
        $this->storeEarlierPair();

        $card = $this->card($this->page());

        $this->assertStringContainsString('Earlier user API key still in use for resolve.', $card);
        $this->assertStringNotContainsString('Part of an earlier user API key pair is still stored.', $card);
    }

    public function test_the_lone_half_tick_box_is_not_rendered_to_a_non_admin(): void
    {
        $this->storeMainPair();
        Setting::setEncrypted('huntress_user_api_key', self::KEY);

        $techCard = $this->card($this->page($this->tech()));
        $this->assertStringContainsString('Part of an earlier user API key pair is still stored.', $techCard, 'precondition: the lone-half notice rendered to the non-admin');
        $this->assertStringNotContainsString('name="clear_user_api_key_pair"', $techCard);
        $this->assertStringNotContainsString('Clear the earlier user API key pair', $techCard);

        $this->assertStringContainsString('name="clear_user_api_key_pair"', $this->card($this->page()), 'positive control: an admin gets the box');
    }

    public function test_the_page_still_renders_when_an_earlier_row_cannot_be_decrypted(): void
    {
        $this->storeMainPair();
        foreach ([['huntress_user_api_key', 'huntress_user_api_secret'], ['huntress_user_api_key']] as $keys) {
            Setting::whereIn('key', ['huntress_user_api_key', 'huntress_user_api_secret'])->delete();
            foreach ($keys as $key) {
                DB::table('settings')->insert(['key' => $key, 'value' => 'synthetic-not-ciphertext', 'created_at' => now(), 'updated_at' => now()]);
            }

            $card = $this->card($this->page());

            $this->assertStringContainsString('id="huntress-earlier-user-pair"', $card, implode('+', $keys).': the notice still renders');
            $this->assertStringContainsString('name="clear_user_api_key_pair"', $card, implode('+', $keys).': an undecryptable row stays clearable');
        }
    }

    public function test_no_notice_and_no_tick_box_without_an_earlier_half(): void
    {
        $this->storeMainPair();

        $card = $this->card($this->page());

        $this->assertStringNotContainsString('id="huntress-earlier-user-pair"', $card);
        $this->assertStringNotContainsString('name="clear_user_api_key_pair"', $card);
    }

    // ---- clear: admin only ----

    public function test_the_tick_box_is_rendered_to_administrators_only(): void
    {
        $this->storeMainPair();
        $this->storeEarlierPair();

        $adminCard = $this->card($this->page());
        $this->assertStringContainsString('name="clear_user_api_key_pair"', $adminCard);

        $techCard = $this->card($this->page($this->tech()));
        $this->assertStringContainsString('Earlier user API key still in use for resolve.', $techCard, 'precondition: the notice rendered to the non-admin');
        $this->assertStringNotContainsString('name="clear_user_api_key_pair"', $techCard);
        $this->assertStringNotContainsString('Clear the earlier user API key pair', $techCard);
    }

    public function test_the_clear_control_removes_both_halves_and_nothing_else(): void
    {
        $this->storeMainPair();
        $this->storeEarlierPair();
        $mainBefore = [$this->raw('huntress_api_key'), $this->raw('huntress_api_secret')];

        $this->save(['clear_user_api_key_pair' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Huntress credentials saved. The earlier user API key pair was cleared.');

        $this->assertNull($this->raw('huntress_user_api_key'));
        $this->assertNull($this->raw('huntress_user_api_secret'));
        $this->assertFalse(HuntressConfig::hasEarlierUserPair());
        $this->assertSame($mainBefore, [$this->raw('huntress_api_key'), $this->raw('huntress_api_secret')], 'clearing the earlier pair must not touch the main pair');
    }

    public function test_one_save_may_carry_a_new_pair_and_the_clear_tick(): void
    {
        $this->storeMainPair();
        $this->storeEarlierPair();

        $this->save(['api_key' => 'synthetic-new-key', 'api_secret' => 'synthetic-new-secret', 'clear_user_api_key_pair' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame('synthetic-new-key', HuntressConfig::get('api_key'));
        $this->assertSame('synthetic-new-secret', HuntressConfig::get('api_secret'));
        $this->assertNull($this->raw('huntress_user_api_key'));
        $this->assertNull($this->raw('huntress_user_api_secret'));
        $this->assertSame(['api_key' => 'synthetic-new-key', 'api_secret' => 'synthetic-new-secret'], HuntressConfig::writeCredentials());
    }

    public function test_a_non_admin_clear_is_refused_and_saves_nothing(): void
    {
        $this->storeMainPair();
        $this->storeEarlierPair();
        $before = DB::table('settings')->orderBy('key')->pluck('value', 'key')->all();

        $this->save(['clear_user_api_key_pair' => '1'], $this->tech())->assertForbidden();
        $this->save(['api_key' => 'synthetic-new-key', 'api_secret' => 'synthetic-new-secret', 'clear_user_api_key_pair' => '1'], $this->tech())->assertForbidden();

        $this->assertSame($before, DB::table('settings')->orderBy('key')->pluck('value', 'key')->all(), 'a refused non-admin submit changed a stored setting');
        $this->assertSame(self::KEY, HuntressConfig::get('user_api_key'));
    }

    // ---- the API key pair: administrators only ----

    /** @return array<string, array{0: UserRole}> */
    public static function nonAdminRoles(): array
    {
        return [
            'tech' => [UserRole::Tech],
            'contractor' => [UserRole::Contractor],
            'billing' => [UserRole::Billing],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonAdminRoles')]
    public function test_a_non_admin_submit_carrying_the_pair_is_refused_and_saves_nothing(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $this->storeMainPair();
        $before = DB::table('settings')->orderBy('key')->pluck('value', 'key')->all();

        $this->save(['api_key' => 'synthetic-new-key', 'api_secret' => 'synthetic-new-secret'], $user)->assertForbidden();
        $this->save(['api_key' => 'synthetic-new-key'], $user)->assertForbidden();
        $this->save(['api_secret' => 'synthetic-new-secret'], $user)->assertForbidden();

        $this->assertSame($before, DB::table('settings')->orderBy('key')->pluck('value', 'key')->all(), 'a refused non-admin submit changed a stored setting');
        $this->assertSame(['api_key' => 'synthetic-main-key', 'api_secret' => 'synthetic-main-secret'], HuntressConfig::writeCredentials());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonAdminRoles')]
    public function test_a_non_admin_submit_with_no_stored_pair_saves_nothing(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $before = DB::table('settings')->orderBy('key')->pluck('value', 'key')->all();

        $this->save(['api_key' => 'synthetic-new-key', 'api_secret' => 'synthetic-new-secret'], $user)->assertForbidden();

        $this->assertSame($before, DB::table('settings')->orderBy('key')->pluck('value', 'key')->all());
        $this->assertFalse(HuntressConfig::isConfigured());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonAdminRoles')]
    public function test_the_key_inputs_are_absent_for_a_non_admin(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $this->storeMainPair();

        $card = $this->card($this->page($user));

        $this->assertStringNotContainsString('name="api_key"', $card);
        $this->assertStringNotContainsString('name="api_secret"', $card);
        $this->assertStringContainsString('An administrator enters this key.', $card);
        $this->assertStringContainsString('id="huntress-resolve-status"', $card, 'the status lines still render to a non-admin');
    }

    public function test_an_admin_sees_the_inputs_and_not_the_admin_only_line(): void
    {
        $card = $this->card($this->page());

        $this->assertStringContainsString('name="api_key"', $card);
        $this->assertStringContainsString('name="api_secret"', $card);
        $this->assertStringNotContainsString('An administrator enters this key.', $card);
    }

    public function test_an_over_length_key_or_secret_is_refused_and_saves_nothing(): void
    {
        $this->storeMainPair();
        $before = DB::table('settings')->orderBy('key')->pluck('value', 'key')->all();
        $long = str_repeat('k', 501);

        $this->save(['api_key' => $long, 'api_secret' => 'synthetic-new-secret'])->assertSessionHasErrors('api_key');
        $this->save(['api_key' => 'synthetic-new-key', 'api_secret' => $long])->assertSessionHasErrors('api_secret');

        $this->assertSame($before, DB::table('settings')->orderBy('key')->pluck('value', 'key')->all(), 'an over-length submit changed a stored setting');
    }

    public function test_a_failed_save_does_not_flash_either_value_back(): void
    {
        $long = str_repeat('s', 501);

        $this->save(['api_key' => 'synthetic-flash-key', 'api_secret' => $long])->assertSessionHasErrors('api_secret');
        $this->assertNull(session()->getOldInput('api_key'), 'the API key was flashed into the session');
        $this->assertNull(session()->getOldInput('api_secret'), 'the API secret was flashed into the session');
        $this->assertStringNotContainsString('synthetic-flash-key', serialize(session()->all()));

        $this->save(['api_key' => $long, 'api_secret' => 'synthetic-flash-secret'])->assertSessionHasErrors('api_key');
        $this->assertNull(session()->getOldInput('api_key'));
        $this->assertNull(session()->getOldInput('api_secret'));
        $this->assertStringNotContainsString('synthetic-flash-secret', serialize(session()->all()));
    }

    public function test_an_admin_save_of_a_new_pair_still_works(): void
    {
        $this->storeMainPair();

        $this->save(['api_key' => 'synthetic-admin-key', 'api_secret' => 'synthetic-admin-secret'])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', 'Huntress credentials saved.');

        $this->assertSame(['api_key' => 'synthetic-admin-key', 'api_secret' => 'synthetic-admin-secret'], HuntressConfig::writeCredentials());
    }

    public function test_a_submit_naming_the_retired_user_inputs_writes_nothing_to_the_earlier_pair(): void
    {
        $this->save(['user_api_key' => self::KEY, 'user_api_secret' => self::SECRET_VALUE])->assertSessionHasNoErrors();
        $this->assertNull($this->raw('huntress_user_api_key'));
        $this->assertNull($this->raw('huntress_user_api_secret'));

        $this->storeEarlierPair();
        $before = [$this->raw('huntress_user_api_key'), $this->raw('huntress_user_api_secret')];
        $this->save(['user_api_key' => 'synthetic-other', 'user_api_secret' => 'synthetic-other'])->assertSessionHasNoErrors();
        $this->assertSame($before, [$this->raw('huntress_user_api_key'), $this->raw('huntress_user_api_secret')], 'a retired input overwrote the earlier pair');
    }

    // ---- remedy messages name the real field ----

    public function test_the_remedy_location_names_labels_the_page_renders(): void
    {
        $card = $this->card($this->page());

        $this->assertSame('Settings > Integrations > Huntress EDR / ITDR > API key and API secret', HuntressConfig::WRITE_CREDENTIAL_LOCATION);
        $this->assertStringContainsString('>API key</label>', $card);
        $this->assertStringContainsString('>API secret</label>', $card);
    }

    public function test_the_write_client_remedy_points_at_the_one_pair(): void
    {
        try {
            (new HuntressWriteClient([]))->resolveEscalation(1);
            $this->fail('expected HuntressWriteScopeException');
        } catch (HuntressWriteScopeException $e) {
            $this->assertSame(
                'Huntress API key pair is not configured; nothing was sent. Save the pair in Settings > Integrations > Huntress EDR / ITDR > API key and API secret.',
                $e->getMessage(),
            );
        }
    }
}
