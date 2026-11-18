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
use Illuminate\Support\Facades\Http;
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

    /** The help line under the clear tick box, in every state that renders the box. */
    private const HELP_LINE = '<small class="text-muted d-block">Removes both halves when you save. After that, resolve depends only on the API key and API secret above.</small>';

    /** The user-only notice: the earlier pair is whole and the main pair is not. */
    private const USER_ONLY_NOTICE = '<strong>An earlier user API key pair is still stored.</strong> Resolve is unavailable until an API key and API secret are saved above. Once they are, resolve uses the earlier pair, not the key above, until an administrator clears it here.</p>';

    /** The retired help-line sentence: the help line was replaced by ruling, so this sentence must not appear in any state. */
    private const OLD_HELP_SENTENCE = 'Resolve then uses the API key and API secret above.';

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
        $this->assertStringContainsString('Removes both halves when you save. After that, resolve depends only on the API key and API secret above.', $card);
    }

    public function test_a_lone_earlier_half_shows_the_lone_half_notice_and_the_status_line_says_this_key(): void
    {
        foreach (['huntress_user_api_key', 'huntress_user_api_secret'] as $half) {
            Setting::query()->delete();
            $this->storeMainPair();
            Setting::setEncrypted($half, self::SECRET_VALUE);

            $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);

            $this->assertStringContainsString('Escalation resolution:</strong> uses this key.', $card, $half);
            $this->assertStringContainsString('<strong>Part of an earlier user API key pair is still stored.</strong> It is not used. An administrator can clear it here.</p>', $card, $half);
            $this->assertStringNotContainsString('resolve uses the API key and API secret above. An administrator', $card, $half);
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

    public function test_the_earlier_pair_with_no_main_pair_shows_the_user_only_notice(): void
    {
        $this->storeEarlierPair();

        $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);

        $this->assertStringContainsString('Escalation resolution:</strong> unavailable until an API key and API secret are saved.', $card, 'precondition: no main pair');
        $this->assertStringContainsString('An earlier user API key pair is still stored.</strong> Resolve is unavailable until an API key and API secret are saved above. Once they are, resolve uses the earlier pair, not the key above, until an administrator clears it here.', $card);
        $this->assertStringNotContainsString('still in use for resolve', $card, 'the full-pair heading is false while resolve is unavailable');
        $this->assertStringNotContainsString('Part of an earlier user API key pair is still stored.', $card);
        $this->assertStringContainsString('>Clear the earlier user API key pair</label>', $card);
        $this->assertStringContainsString('Removes both halves when you save. After that, resolve depends only on the API key and API secret above.', $card);
    }

    public function test_the_both_pairs_state_keeps_the_full_pair_notice(): void
    {
        $this->storeMainPair();
        $this->storeEarlierPair();

        $card = $this->card($this->page());

        $this->assertStringContainsString('<strong>Earlier user API key still in use for resolve.</strong> This install saved a separate user API key pair for escalation resolution before the card moved to one key pair. Resolve keeps using that earlier pair until an administrator clears it here. Saving a new API key and API secret above changes reads only. Nothing has been deleted.</p>', $card);
        $this->assertStringNotContainsString('An earlier user API key pair is still stored.', $card);
    }

    /**
     * A lone earlier half with no main pair: which NOTICE renders. The
     * lone-half check comes before the user-only branch, so the lone-half
     * notice shows and the user-only and full-pair notices do not. The tick
     * box's help line in this state is pinned by the next test.
     */
    public function test_a_lone_earlier_half_with_no_main_pair_shows_the_lone_half_notice_not_the_user_only_notice(): void
    {
        foreach (['huntress_user_api_key', 'huntress_user_api_secret'] as $half) {
            Setting::query()->delete();
            Setting::setEncrypted($half, self::SECRET_VALUE);

            $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);

            $this->assertStringContainsString('Escalation resolution:</strong> unavailable until an API key and API secret are saved.', $card, "{$half}: precondition: no main pair");
            $this->assertStringContainsString('<strong>Part of an earlier user API key pair is still stored.</strong> It is not used. An administrator can clear it here.</p>', $card, $half);
            $this->assertStringNotContainsString('An earlier user API key pair is still stored.', $card, "{$half}: a lone half is not a pair, so the user-only notice is false");
            $this->assertStringNotContainsString('still in use for resolve', $card, $half);
        }
    }

    /**
     * A lone earlier half with no main pair: the tick box's HELP LINE. With
     * no main pair, clearing leaves resolve unavailable, so the help line is
     * the new one and the old "Resolve then uses the API key and API secret
     * above." is not on the card, not even appended after the new line.
     */
    public function test_a_lone_earlier_half_with_no_main_pair_shows_the_new_help_line_and_not_the_old_one(): void
    {
        Http::preventStrayRequests();
        foreach (['huntress_user_api_key', 'huntress_user_api_secret'] as $half) {
            Setting::query()->delete();
            Setting::setEncrypted($half, self::SECRET_VALUE);

            $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);

            $this->assertStringContainsString('name="clear_user_api_key_pair"', $card, "{$half}: precondition: the tick box renders");
            $this->assertStringContainsString('Removes both halves when you save. After that, resolve depends only on the API key and API secret above.', $card, "{$half}: the tick-box help line");
            $this->assertStringNotContainsString(self::OLD_HELP_SENTENCE, $card, "{$half}: with no main pair, clearing leaves resolve unavailable, so the old help line is false");
        }
    }

    public function test_the_earlier_pair_whole_with_one_main_half_shows_the_user_only_notice(): void
    {
        $this->storeEarlierPair();
        Setting::setEncrypted('huntress_api_key', 'synthetic-main-key');

        $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);

        $this->assertStringContainsString('Escalation resolution:</strong> unavailable until an API key and API secret are saved.', $card, 'precondition: one main half is not a main pair');
        $this->assertStringContainsString('<strong>An earlier user API key pair is still stored.</strong> Resolve is unavailable until an API key and API secret are saved above. Once they are, resolve uses the earlier pair, not the key above, until an administrator clears it here.</p>', $card);
        $this->assertStringNotContainsString('still in use for resolve', $card, 'the full-pair notice is false while resolve is unavailable');
        $this->assertStringNotContainsString('Part of an earlier user API key pair is still stored.', $card);
    }

    /**
     * The other lone main half: only the main API SECRET stored. The
     * user-only notice is keyed on both main halves ($huntressConfigured),
     * so it still renders; a branch keyed on the raw secret row would not.
     */
    public function test_the_earlier_pair_whole_with_only_the_main_secret_shows_the_user_only_notice(): void
    {
        Http::preventStrayRequests();
        $this->storeEarlierPair();
        Setting::setEncrypted('huntress_api_secret', 'synthetic-main-secret');

        $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);

        $this->assertStringContainsString('Escalation resolution:</strong> unavailable until an API key and API secret are saved.', $card, 'precondition: one main half is not a main pair');
        $this->assertStringContainsString('<strong>An earlier user API key pair is still stored.</strong> Resolve is unavailable until an API key and API secret are saved above. Once they are, resolve uses the earlier pair, not the key above, until an administrator clears it here.</p>', $card);
        $this->assertStringNotContainsString('still in use for resolve', $card, 'the full-pair notice is false while resolve is unavailable');
        $this->assertStringNotContainsString('Part of an earlier user API key pair is still stored.', $card);
    }

    // ---- status line while the integration is switched off ----

    private const SWITCHED_OFF_LINE = '<i class="bi bi-dash-circle text-secondary me-1" aria-hidden="true"></i><strong>Escalation resolution:</strong> unavailable while the Huntress integration is switched off. Any note below about an earlier key describes what resolve uses once it is switched on.';

    public function test_switched_off_with_the_main_pair_only_the_status_line_says_unavailable_not_uses_this_key(): void
    {
        Setting::setValue('huntress_enabled', '0');
        $card = $this->card($this->page());
        $this->assertStringContainsString('<strong>Escalation resolution:</strong> unavailable until an API key and API secret are saved.', $card, 'no pair: the first branch still wins while switched off');
        $this->assertStringNotContainsString('switched off', $card);

        $this->storeMainPair();
        $card = $this->card($this->page());

        $this->assertStringContainsString(self::SWITCHED_OFF_LINE, $card);
        $this->assertStringNotContainsString('uses this key', $card);
        $this->assertStringNotContainsString('id="huntress-earlier-user-pair"', $card);
    }

    public function test_switched_off_with_both_pairs_the_status_line_says_unavailable_and_the_notice_is_unchanged(): void
    {
        Setting::setValue('huntress_enabled', '0');
        $this->storeMainPair();
        $this->storeEarlierPair();

        $card = $this->card($this->page());

        $this->assertStringContainsString(self::SWITCHED_OFF_LINE, $card);
        $this->assertStringNotContainsString('uses the earlier user API key pair (see below)', $card);
        $this->assertStringNotContainsString('uses this key', $card);
        $this->assertStringContainsString('<strong>Earlier user API key still in use for resolve.</strong> This install saved a separate user API key pair for escalation resolution before the card moved to one key pair. Resolve keeps using that earlier pair until an administrator clears it here. Saving a new API key and API secret above changes reads only. Nothing has been deleted.</p>', $card);
    }

    public function test_switched_on_the_status_line_is_unchanged(): void
    {
        Setting::setValue('huntress_enabled', '1');
        $this->storeMainPair();
        $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);
        $this->assertStringContainsString('Escalation resolution:</strong> uses this key.', $card);
        $this->assertStringNotContainsString('switched off', $card);

        $this->storeEarlierPair();
        $card = html_entity_decode($this->card($this->page()), ENT_QUOTES);
        $this->assertStringContainsString('Escalation resolution:</strong> uses the earlier user API key pair (see below), not this key, until an administrator clears it.', $card);
        $this->assertStringNotContainsString('switched off', $card);
    }

    /**
     * Switched off, a lone earlier half beside the main pair: the status line
     * says switched off, and the notice is still the lone-half notice (the
     * notice block does not read the switch).
     */
    public function test_switched_off_with_a_lone_earlier_half_and_the_main_pair_shows_the_switched_off_line_and_the_lone_half_notice(): void
    {
        Http::preventStrayRequests();
        foreach (['huntress_user_api_key', 'huntress_user_api_secret'] as $half) {
            Setting::query()->delete();
            Setting::setValue('huntress_enabled', '0');
            $this->storeMainPair();
            Setting::setEncrypted($half, self::SECRET_VALUE);

            $card = $this->card($this->page());

            $this->assertStringContainsString(self::SWITCHED_OFF_LINE, $card, $half);
            $this->assertStringNotContainsString('uses this key', $card, $half);
            $this->assertStringContainsString('<strong>Part of an earlier user API key pair is still stored.</strong> It is not used. An administrator can clear it here.</p>', $card, $half);
            $this->assertStringNotContainsString('still in use for resolve', $card, $half);
            $this->assertStringNotContainsString('An earlier user API key pair is still stored.', $card, $half);
        }
    }

    /**
     * Switched off, a lone earlier half and no main pair: the first status
     * branch ("unavailable until … saved") still wins, and the notice is the
     * lone-half notice.
     */
    public function test_switched_off_with_a_lone_earlier_half_and_no_main_pair_shows_the_unsaved_line_and_the_lone_half_notice(): void
    {
        Http::preventStrayRequests();
        foreach (['huntress_user_api_key', 'huntress_user_api_secret'] as $half) {
            Setting::query()->delete();
            Setting::setValue('huntress_enabled', '0');
            Setting::setEncrypted($half, self::SECRET_VALUE);

            $card = $this->card($this->page());

            $this->assertStringContainsString('<strong>Escalation resolution:</strong> unavailable until an API key and API secret are saved.', $card, $half);
            $this->assertStringNotContainsString('switched off', $card, $half);
            $this->assertStringContainsString('<strong>Part of an earlier user API key pair is still stored.</strong> It is not used. An administrator can clear it here.</p>', $card, $half);
            $this->assertStringNotContainsString('An earlier user API key pair is still stored.', $card, $half);
        }
    }

    /**
     * Switched off, the earlier pair whole and ONE main half (the user-only
     * state): the first status branch still wins and the user-only notice
     * renders, for either main half.
     */
    public function test_switched_off_with_the_earlier_pair_and_one_main_half_shows_the_unsaved_line_and_the_user_only_notice(): void
    {
        Http::preventStrayRequests();
        foreach (['huntress_api_key' => 'synthetic-main-key', 'huntress_api_secret' => 'synthetic-main-secret'] as $mainHalf => $value) {
            Setting::query()->delete();
            Setting::setValue('huntress_enabled', '0');
            $this->storeEarlierPair();
            Setting::setEncrypted($mainHalf, $value);

            $card = $this->card($this->page());

            $this->assertStringContainsString('<strong>Escalation resolution:</strong> unavailable until an API key and API secret are saved.', $card, $mainHalf);
            $this->assertStringNotContainsString('switched off', $card, $mainHalf);
            $this->assertStringContainsString(self::USER_ONLY_NOTICE, $card, $mainHalf);
            $this->assertStringNotContainsString('still in use for resolve', $card, $mainHalf);
            $this->assertStringNotContainsString('Part of an earlier user API key pair is still stored.', $card, $mainHalf);
        }
    }

    /**
     * Switched off, the earlier pair whole, NO main pair: what the card shows
     * today, kept as shipped by ruling. The status line is the first branch,
     * "unavailable until an API key and API secret are saved" (true: resolve
     * is unavailable), not the switched-off line, and the notice is the
     * user-only notice. The page offers no switch in this state (the switch
     * renders only once a main pair is saved), so the state is reached only
     * by a request the page does not offer, or by a stored setting.
     */
    public function test_switched_off_with_the_earlier_pair_and_no_main_pair_shows_the_unsaved_line_and_the_user_only_notice(): void
    {
        Http::preventStrayRequests();
        Setting::setValue('huntress_enabled', '0');
        $this->storeEarlierPair();

        $html = $this->page();
        $card = $this->card($html);

        $this->assertStringContainsString('<i class="bi bi-dash-circle text-secondary me-1" aria-hidden="true"></i><strong>Escalation resolution:</strong> unavailable until an API key and API secret are saved. Once saved, who may use it is set per MCP token on Settings &gt; MCP tokens.', $card);
        $this->assertStringNotContainsString('switched off', $card);
        $this->assertStringContainsString(self::USER_ONLY_NOTICE, $card);
        $this->assertStringNotContainsString('still in use for resolve', $card);
        $this->assertStringNotContainsString('Part of an earlier user API key pair is still stored.', $card);
        $this->assertStringNotContainsString('id="huntress_enabled"', $html, 'the state: the switch does not render without a main pair');
    }

    /**
     * Positive control for the row 3 absence check: with a main pair saved,
     * the switch renders, switched off and switched on alike, so an absent
     * id="huntress_enabled" in the row 3 state is the missing main pair and
     * not a selector that can never match.
     */
    public function test_the_switch_renders_once_a_main_pair_is_saved(): void
    {
        Http::preventStrayRequests();
        foreach (['0' => 'switched off', '1' => 'switched on'] as $enabled => $state) {
            Setting::query()->delete();
            Setting::setValue('huntress_enabled', (string) $enabled);
            $this->storeMainPair();
            $this->storeEarlierPair();

            $this->assertStringContainsString('id="huntress_enabled"', $this->page(), "{$state}: the switch renders with a main pair saved");
        }
    }

    public function test_the_lone_half_tick_box_is_not_rendered_to_a_non_admin(): void
    {
        $this->storeMainPair();
        Setting::setEncrypted('huntress_user_api_key', self::KEY);

        $techCard = $this->card($this->page($this->tech()));
        $this->assertStringContainsString('Part of an earlier user API key pair is still stored.', $techCard, 'precondition: the lone-half notice rendered to the non-admin');
        $this->assertStringNotContainsString('name="clear_user_api_key_pair"', $techCard);
        $this->assertStringNotContainsString('Clear the earlier user API key pair', $techCard);
        $this->assertStringNotContainsString('Removes both halves when you save.', $techCard, 'the tick-box help line is administrator-only too');

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

    /**
     * An earlier-pair row that cannot be decrypted counts as STORED for the
     * notice and the clear tick box: the notice keys on the raw rows, not on
     * a decrypt. Each half alone and both, with and without a main pair.
     */
    public function test_an_undecryptable_earlier_row_still_shows_the_notice_and_the_tick_box_in_each_state(): void
    {
        Http::preventStrayRequests();
        foreach ([true, false] as $withMain) {
            foreach ([['huntress_user_api_key'], ['huntress_user_api_secret'], ['huntress_user_api_key', 'huntress_user_api_secret']] as $keys) {
                $label = implode('+', $keys).($withMain ? ', main pair' : ', no main pair');
                Setting::query()->delete();
                if ($withMain) {
                    $this->storeMainPair();
                }
                foreach ($keys as $key) {
                    DB::table('settings')->insert(['key' => $key, 'value' => 'synthetic-not-ciphertext', 'created_at' => now(), 'updated_at' => now()]);
                }

                $card = $this->card($this->page());

                $this->assertStringContainsString('id="huntress-earlier-user-pair"', $card, "{$label}: the notice renders");
                $this->assertStringContainsString('name="clear_user_api_key_pair"', $card, "{$label}: the row stays clearable");
                $this->assertSame(1, substr_count($card, self::HELP_LINE), "{$label}: the tick-box help line");
                $this->assertStringNotContainsString(self::OLD_HELP_SENTENCE, $card, "{$label}: the retired help sentence");
            }
        }
    }

    // ---- which notice wins: the two render-only choices ----

    /**
     * Choice 1: the lone-half check comes BEFORE the user-only branch. A
     * lone earlier half shows the lone-half notice whatever the main pair is:
     * none, key only, secret only, or both.
     */
    public function test_the_lone_half_check_wins_over_the_user_only_branch_in_every_main_pair_state(): void
    {
        Http::preventStrayRequests();
        $mainStates = [
            'no main pair' => [],
            'main key only' => ['huntress_api_key' => 'synthetic-main-key'],
            'main secret only' => ['huntress_api_secret' => 'synthetic-main-secret'],
            'main pair' => ['huntress_api_key' => 'synthetic-main-key', 'huntress_api_secret' => 'synthetic-main-secret'],
        ];
        foreach ($mainStates as $state => $main) {
            foreach (['huntress_user_api_key', 'huntress_user_api_secret'] as $half) {
                Setting::query()->delete();
                foreach ($main as $key => $value) {
                    Setting::setEncrypted($key, $value);
                }
                Setting::setEncrypted($half, self::SECRET_VALUE);

                $card = $this->card($this->page());

                $this->assertStringContainsString('<strong>Part of an earlier user API key pair is still stored.</strong> It is not used. An administrator can clear it here.</p>', $card, "{$state}, {$half}");
                $this->assertStringNotContainsString('An earlier user API key pair is still stored.', $card, "{$state}, {$half}: the user-only notice is false for a lone half");
                $this->assertStringNotContainsString('still in use for resolve', $card, "{$state}, {$half}");
            }
        }
    }

    /**
     * Choice 2: the user-only branch is keyed on BOTH main halves
     * ($huntressConfigured), not on either raw main row. With the earlier
     * pair whole: no main half, the key alone, or the secret alone each show
     * the user-only notice; both main halves show the full-pair notice.
     */
    public function test_the_user_only_branch_is_keyed_on_both_main_halves_not_either_raw_row(): void
    {
        Http::preventStrayRequests();
        $mainStates = [
            'no main pair' => [[], true],
            'main key only' => [['huntress_api_key' => 'synthetic-main-key'], true],
            'main secret only' => [['huntress_api_secret' => 'synthetic-main-secret'], true],
            'main pair' => [['huntress_api_key' => 'synthetic-main-key', 'huntress_api_secret' => 'synthetic-main-secret'], false],
        ];
        foreach ($mainStates as $state => [$main, $userOnly]) {
            Setting::query()->delete();
            $this->storeEarlierPair();
            foreach ($main as $key => $value) {
                Setting::setEncrypted($key, $value);
            }

            $card = $this->card($this->page());

            if ($userOnly) {
                $this->assertStringContainsString(self::USER_ONLY_NOTICE, $card, "{$state}: the user-only notice");
                $this->assertStringNotContainsString('still in use for resolve', $card, "{$state}: the full-pair notice is false while resolve is unavailable");
            } else {
                $this->assertStringContainsString('<strong>Earlier user API key still in use for resolve.</strong>', $card, "{$state}: the full-pair notice");
                $this->assertStringNotContainsString('An earlier user API key pair is still stored.', $card, "{$state}: the user-only notice is false once both main halves are saved");
            }
            $this->assertStringNotContainsString('Part of an earlier user API key pair is still stored.', $card, $state);
        }
    }

    // ---- the tick box's help line ----

    /**
     * Every state that renders the clear tick box (to an administrator) shows
     * the help line exactly once, and the retired sentence in none of them.
     */
    public function test_the_help_line_shows_exactly_once_and_the_old_sentence_never_in_every_tick_box_state(): void
    {
        Http::preventStrayRequests();
        $states = [
            'both pairs' => fn () => [$this->storeMainPair(), $this->storeEarlierPair()],
            'both pairs, switched off' => fn () => [Setting::setValue('huntress_enabled', '0'), $this->storeMainPair(), $this->storeEarlierPair()],
            'earlier pair, no main pair' => fn () => $this->storeEarlierPair(),
            'earlier pair, main key only' => fn () => [$this->storeEarlierPair(), Setting::setEncrypted('huntress_api_key', 'synthetic-main-key')],
            'earlier pair, main secret only' => fn () => [$this->storeEarlierPair(), Setting::setEncrypted('huntress_api_secret', 'synthetic-main-secret')],
            'lone key half, main pair' => fn () => [$this->storeMainPair(), Setting::setEncrypted('huntress_user_api_key', self::KEY)],
            'lone secret half, main pair' => fn () => [$this->storeMainPair(), Setting::setEncrypted('huntress_user_api_secret', self::SECRET_VALUE)],
            'lone key half, no main pair' => fn () => Setting::setEncrypted('huntress_user_api_key', self::KEY),
            'lone secret half, no main pair' => fn () => Setting::setEncrypted('huntress_user_api_secret', self::SECRET_VALUE),
            'lone half, main pair, switched off' => fn () => [Setting::setValue('huntress_enabled', '0'), $this->storeMainPair(), Setting::setEncrypted('huntress_user_api_key', self::KEY)],
        ];
        foreach ($states as $state => $arrange) {
            Setting::query()->delete();
            $arrange();

            $card = $this->card($this->page());

            $this->assertSame(1, substr_count($card, 'name="clear_user_api_key_pair"'), "{$state}: precondition: the tick box renders once");
            $this->assertSame(1, substr_count($card, self::HELP_LINE), "{$state}: the help line, exactly once");
            $this->assertSame(1, substr_count($card, 'Removes both halves when you save.'), "{$state}: the help line's text, exactly once");
            $this->assertStringNotContainsString(self::OLD_HELP_SENTENCE, $card, "{$state}: the retired help sentence");
        }
    }

    /**
     * A non-administrator gets the notice but neither the tick box nor its
     * help line, in each notice state.
     */
    public function test_the_help_line_is_not_rendered_to_a_non_admin_in_any_notice_state(): void
    {
        Http::preventStrayRequests();
        $states = [
            'both pairs' => [fn () => [$this->storeMainPair(), $this->storeEarlierPair()], 'Earlier user API key still in use for resolve.'],
            'earlier pair, no main pair' => [fn () => $this->storeEarlierPair(), 'An earlier user API key pair is still stored.'],
            'lone half, main pair' => [fn () => [$this->storeMainPair(), Setting::setEncrypted('huntress_user_api_key', self::KEY)], 'Part of an earlier user API key pair is still stored.'],
            'lone half, no main pair' => [fn () => Setting::setEncrypted('huntress_user_api_secret', self::SECRET_VALUE), 'Part of an earlier user API key pair is still stored.'],
        ];
        foreach ($states as $state => [$arrange, $notice]) {
            Setting::query()->delete();
            $arrange();

            $techCard = $this->card($this->page($this->tech()));

            $this->assertStringContainsString($notice, $techCard, "{$state}: precondition: the notice rendered to the non-admin");
            $this->assertStringNotContainsString('name="clear_user_api_key_pair"', $techCard, $state);
            $this->assertStringNotContainsString('Removes both halves when you save.', $techCard, "{$state}: the help line is administrator-only");
            $this->assertStringNotContainsString(self::OLD_HELP_SENTENCE, $techCard, $state);
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
        $this->assertStringNotContainsString('Removes both halves when you save.', $techCard, 'the tick-box help line is administrator-only too');
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
