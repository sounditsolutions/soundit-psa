<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Support\TacticalConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P4 chunk 3 (plan Task 6 + amendment L; psa-6h5r): the configurable
 * tactical_web_url Setting and the "Open in Tactical RMM" link fix.
 *
 * tactical_web_url is the *dashboard* base — distinct from the API URL, a plain
 * (NOT encrypted) DB-backed Setting, saved through updateTactical. It is
 * validated https://-only with a parseable host (javascript:/non-URL rejected),
 * and per spec §11 it is NEVER derived from api_url. The asset-page footer link
 * uses it (not apiUrl()) and is HIDDEN when unset — no fallback to the API root
 * (today's bug). The link renders rel="noopener noreferrer".
 */
class TacticalWebUrlTest extends TestCase
{
    use RefreshDatabase;

    // A public IP literal for the API URL: SafeTacticalUrl resolves hostnames via
    // DNS (failing closed on NXDOMAIN), so example.com would be rejected — a
    // routable literal needs no DNS and keeps these tests hermetic. (The web URL
    // rule does NOT resolve, so its cases use plain hostnames freely.)
    private const API_URL = 'https://93.184.216.34';

    protected function setUp(): void
    {
        parent::setUp();
        // A configured Tactical instance (so the card + link region render).
        Setting::setValue('tactical_api_url', self::API_URL);
        Setting::setEncrypted('tactical_api_key', 'svc-key-abc123');
    }

    private function save(User $user, array $extra = [])
    {
        return $this->actingAs($user)->from(route('settings.integrations'))->post(
            route('settings.integrations.tactical.update'),
            array_merge(['api_url' => self::API_URL], $extra),
        );
    }

    private function linkedAsset(): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'status' => 'online',
            'synced_at' => now()->subMinutes(5),
            'last_seen_at' => now()->subMinutes(5),
        ]);

        // The asset page renders snapshot-only — bind a client that EXPLODES if
        // the page makes any outbound call, so these link tests stay hermetic.
        $stack = HandlerStack::create(new MockHandler([]));
        $http = new GuzzleClient(['base_uri' => 'https://api-rmm.example.com/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));

        return $asset->refresh();
    }

    // ── saving the setting ───────────────────────────────────────────────────

    public function test_saving_accepts_a_valid_https_web_url(): void
    {
        $user = User::factory()->create();

        $this->save($user, ['web_url' => 'https://rmm.example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.integrations'));

        // Persisted plain (not encrypted) and readable via the accessor.
        $this->assertSame('https://rmm.example.com', Setting::getValue('tactical_web_url'));
        $this->assertSame('https://rmm.example.com', TacticalConfig::webUrl());
    }

    public function test_web_url_is_stored_plain_not_encrypted(): void
    {
        $this->save(User::factory()->create(), ['web_url' => 'https://rmm.example.com']);

        // A plain Setting round-trips through getValue (an encrypted one would not).
        $this->assertSame('https://rmm.example.com', Setting::getValue('tactical_web_url'));
    }

    public function test_saving_rejects_http_web_url(): void
    {
        $this->save(User::factory()->create(), ['web_url' => 'http://rmm.example.com'])
            ->assertSessionHasErrors('web_url');

        $this->assertNull(Setting::getValue('tactical_web_url'));
    }

    public function test_saving_rejects_javascript_scheme(): void
    {
        $this->save(User::factory()->create(), ['web_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('web_url');

        $this->assertNull(Setting::getValue('tactical_web_url'));
    }

    public function test_saving_rejects_a_non_url(): void
    {
        $this->save(User::factory()->create(), ['web_url' => 'not a url'])
            ->assertSessionHasErrors('web_url');

        $this->assertNull(Setting::getValue('tactical_web_url'));
    }

    public function test_web_url_error_message_labels_the_web_url_field_not_the_api_url(): void
    {
        // amendment L: the reused SSRF copy says "Tactical API URL"; the web-url
        // field must NOT inherit that mislabel.
        $resp = $this->save(User::factory()->create(), ['web_url' => 'http://rmm.example.com']);

        $errors = session('errors')->get('web_url');
        $this->assertNotEmpty($errors);
        $this->assertStringNotContainsStringIgnoringCase('API URL', $errors[0]);
    }

    public function test_blank_web_url_is_allowed_and_does_not_clobber_api_url(): void
    {
        // The alert-threshold mini-form re-POSTs without a web_url; saving must
        // not require it and must not disturb the api_url.
        $this->save(User::factory()->create())
            ->assertSessionHasNoErrors();

        $this->assertSame(self::API_URL, Setting::getValue('tactical_api_url'));
        $this->assertNull(TacticalConfig::webUrl());
    }

    // ── the asset-page "Open in Tactical" link ───────────────────────────────

    public function test_open_in_tactical_link_uses_the_web_url_when_set(): void
    {
        Setting::setValue('tactical_web_url', 'https://rmm.example.com');
        $asset = $this->linkedAsset();

        $resp = $this->actingAs(User::factory()->create())->get(route('assets.show', $asset));

        $resp->assertOk();
        $resp->assertSee('href="https://rmm.example.com"', false);
        $resp->assertSeeText('Open in Tactical RMM');
    }

    public function test_open_in_tactical_link_renders_rel_noopener(): void
    {
        Setting::setValue('tactical_web_url', 'https://rmm.example.com');
        $asset = $this->linkedAsset();

        $resp = $this->actingAs(User::factory()->create())->get(route('assets.show', $asset));

        $resp->assertOk();
        $resp->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_open_in_tactical_link_is_hidden_when_web_url_unset(): void
    {
        // No tactical_web_url set — the footer link must NOT render, and it must
        // NOT fall back to the API root (today's psa-6h5r bug).
        $asset = $this->linkedAsset();

        $resp = $this->actingAs(User::factory()->create())->get(route('assets.show', $asset));

        $resp->assertOk();
        $resp->assertDontSee('Open in Tactical RMM');
        // The dangerous fallback: a link pointing at the API root must be absent.
        $resp->assertDontSee('href="'.self::API_URL.'"', false);
    }

    public function test_panels_root_carries_the_web_url_for_the_view_in_tactical_affordance(): void
    {
        // Chunk 2's panel "View in Tactical" reads root.dataset.webUrl — it must
        // be populated from the web URL (not the API URL).
        Setting::setValue('tactical_web_url', 'https://rmm.example.com');
        $asset = $this->linkedAsset();

        $resp = $this->actingAs(User::factory()->create())->get(route('assets.show', $asset));

        $resp->assertOk();
        $resp->assertSee('data-web-url="https://rmm.example.com"', false);
    }
}
