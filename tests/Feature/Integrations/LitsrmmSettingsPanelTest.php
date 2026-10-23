<?php

namespace Tests\Feature\Integrations;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Services\Litsrmm\LitsrmmClient;
use App\Services\Litsrmm\LitsrmmClientException;
use App\Support\LitsrmmConfig;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stage 1b: the settings surface for LITSRMM.
 *
 * Stage 1 made the integration exist; without this, the only way to configure
 * it was env or config, so an operator could not use it at all. Jeeves's stage-1
 * ruling put the panel and Test connection here.
 *
 * WHAT THESE CONTROLS PIN
 * -----------------------
 * That an operator can set the host, key and toggle; that the key is never
 * rendered back to the page; that Test connection reaches the REAL client
 * rather than a second hand-built request; and that each not-configured case
 * names the field the operator has to fix.
 *
 * WHAT THEY DO NOT
 * ----------------
 * No live call is made. isHealthy() is exercised through the Guzzle handler
 * seam, so these tests say nothing about whether the vendor's real /v1/health
 * behaves as its proposal describes.
 */
class LitsrmmSettingsPanelTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /**
     * Bind a LitsrmmClient whose transport is observable, so a test can prove
     * what left the process instead of trusting a return value.
     */
    private function bindClient(array $responses): void
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        $this->app->bind(LitsrmmClient::class, fn () => new LitsrmmClient([
            'api_key' => 'panel-token-value',
            'base_url' => 'https://litsrmm.test',
            'handler' => $stack,
            'request_timeout' => 5,
        ]));
    }

    private function configure(): void
    {
        Setting::setEncrypted('litsrmm_api_key', 'panel-token-value');
        Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');
    }

    // ---- the panel renders and can be configured at all ----

    #[DataProvider('l4CardStates')]
    public function test_l4_card_explains_pending_schedule(bool $configured, bool $enabled, bool $scheduled, string $unconfiguredBy): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        if ($configured) {
            $this->configure();
        } elseif ($unconfiguredBy === 'url') {
            Setting::setEncrypted('litsrmm_api_key', 'panel-token-value');
            Setting::setValue('litsrmm_base_url', '');
        } elseif ($unconfiguredBy === 'key') {
            Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');
            Setting::where('key', 'litsrmm_api_key')->delete();
            config(['services.litsrmm.api_key' => null]);
        }
        if (! $configured) {
            $this->assertFalse(LitsrmmConfig::isConfigured());
        }
        Setting::setValue('litsrmm_enabled', $enabled ? '1' : '0');
        Setting::setValue('litsrmm_sync_schedule_enabled', $scheduled ? '1' : '0');
        $response = $this->actingAs($this->admin())->get(route('settings.integrations'))->assertOk();
        $text = 'Sync every 4 hours is still on. Scheduled sync will run once the integration is configured and enabled.';

        if ($scheduled && (! $configured || ! $enabled)) {
            $response->assertSee($text);
            $this->assertPendingLineSitsDirectlyUnderTheSwitch($response->getContent(), $text);
        } else {
            $response->assertDontSee($text);
        }
        if ($configured && $enabled) {
            $response->assertSee(route('settings.integrations.litsrmm.sync-devices'));
        } else {
            $response->assertDontSee(route('settings.integrations.litsrmm.sync-devices'));
        }
    }

    public function test_schedule_on_is_switchable_when_not_configured(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        Setting::setValue('litsrmm_base_url', '');
        Setting::setValue('litsrmm_sync_schedule_enabled', '1');
        $this->assertFalse(LitsrmmConfig::isConfigured());

        $response = $this->actingAs($this->admin())->get(route('settings.integrations'))->assertOk();
        $response->assertSee('action="'.route('settings.integrations.litsrmm.schedule').'"', false);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//form[@action="'.route('settings.integrations.litsrmm.schedule').'"]//input[@id="litsrmm_sync_schedule" and @checked]')->length);
        $response->assertSee('Sync every 4 hours is still on. Scheduled sync will run once the integration is configured and enabled.');
        $this->assertPendingLineSitsDirectlyUnderTheSwitch($response->getContent(), 'Sync every 4 hours is still on. Scheduled sync will run once the integration is configured and enabled.');
    }

    public function test_schedule_off_has_no_form_when_not_configured(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        Setting::setValue('litsrmm_base_url', '');
        Setting::setValue('litsrmm_sync_schedule_enabled', '0');
        $this->assertFalse(LitsrmmConfig::isConfigured());

        $this->actingAs($this->admin())->get(route('settings.integrations'))->assertOk()
            ->assertDontSee(route('settings.integrations.litsrmm.schedule'));
    }

    /**
     * The pending line's exact text must appear once in the page, exactly one
     * <p> must carry it once whitespace is normalised, and that <p> must be
     * the element directly after the form-check div that holds the switch,
     * inside the schedule form, with its trimmed text equal to the line.
     * Moving it outside the form, above the switch, or below the "Off by
     * default" form-text fails here.
     *
     * A second copy fails if its bytes contain the line anywhere, including a
     * copy embedded verbatim inside longer text, because substr_count then
     * finds two. It also fails if it is a second <p> whose whitespace-
     * normalised text, inline children included, equals the line. That covers
     * a <br> with whitespace beside it, and a <br> inserted inside the text
     * without replacing a character, since a <br> adds no text. Block children
     * in libxml's HTML4 element set (div, table, lists, headings, pre, form,
     * blockquote) do not count: libxml closes an open <p> before them. HTML5
     * elements (section, article, aside, nav, header, footer, main, figure,
     * details) do not close it, so their text joins the <p> text. If they add
     * non-whitespace text beyond the line, that <p> no longer equals the line;
     * if they are empty or only wrap words of the line, it still equals the
     * line and the copy fails.
     *
     * A copy whose bytes do not contain the line passes both copy checks if it
     * sits outside any <p>, or if its <p> text still differs once normalised.
     * Joining the words with a non-breaking space, or putting a <br> in place
     * of the space between two words, does that. Such a copy that is an
     * element and sits directly after the switch displaces the line and fails
     * the position check. Such a copy that is not an element (a bare text node
     * or a comment) and sits outside any <p> passes every check, even directly
     * after the switch, because the position check looks only at elements; the
     * view emits neither.
     */
    private function assertPendingLineSitsDirectlyUnderTheSwitch(string $html, string $text): void
    {
        $this->assertSame(1, substr_count($html, $text), 'the exact pending line text must appear exactly once in the page');
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//p[normalize-space(.)="'.$text.'"]')->length, 'exactly one <p> must have the pending line as its whole whitespace-normalised text');
        $switch = '//form[@action="'.route('settings.integrations.litsrmm.schedule').'"]'
            .'/div[contains(concat(" ", normalize-space(@class), " "), " form-check ")][.//input[@id="litsrmm_sync_schedule"]]';
        $this->assertSame(1, $xpath->query($switch)->length, 'the schedule form must hold the switch in a form-check div');
        $next = $xpath->query($switch.'/following-sibling::*[1]')->item(0);
        $this->assertNotNull($next, 'an element must follow the switch inside the schedule form');
        $this->assertSame('p', $next->nodeName, 'the pending line must be the element directly after the switch');
        $this->assertSame($text, trim($next->textContent), 'the element directly after the switch must be the pending line');
    }

    public static function l4CardStates(): array
    {
        return LitsrmmSyncControlsTest::l4ScheduleStates();
    }

    public function test_the_integrations_page_renders_a_litsrmm_card(): void
    {
        $response = $this->actingAs($this->admin())->get('/settings/integrations');

        $response->assertOk();
        $response->assertSee('Leif IT Solutions RMM', false);
    }

    public function test_an_operator_can_save_a_base_url_and_api_key(): void
    {
        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'https://rmm.example.com',
            'api_key' => 'operator-entered-key',
        ]);

        $response->assertRedirect(route('settings.integrations'));
        $response->assertSessionHas('success');

        $this->assertSame('https://rmm.example.com', Setting::getValue('litsrmm_base_url'));
        $this->assertSame('operator-entered-key', Setting::getEncrypted('litsrmm_api_key'),
            'the key must round-trip, or an operator cannot configure the integration at all');
    }

    public function test_a_trailing_slash_on_the_base_url_is_normalised_away(): void
    {
        $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'https://rmm.example.com/',
        ]);

        // The client joins paths onto this value; a stored trailing slash makes
        // every request carry a double slash, which some hosts route differently.
        $this->assertSame('https://rmm.example.com', Setting::getValue('litsrmm_base_url'));
    }

    public function test_saving_one_field_does_not_clear_the_other(): void
    {
        $this->configure();

        // The host is re-submitted UNCHANGED here. That is the case this test
        // is about: "leave blank to keep current" must be true for the key.
        // Moving the host to a NEW value additionally requires the key, which
        // test_changing_the_host_requires_re_entering_the_key covers.
        $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'https://litsrmm.test',
        ]);

        $this->assertSame('https://litsrmm.test', Setting::getValue('litsrmm_base_url'));
        $this->assertSame('panel-token-value', Setting::getEncrypted('litsrmm_api_key'),
            'submitting only the host must not wipe the key: the form says "leave blank to keep current"');
    }

    public function test_a_malformed_base_url_is_refused_and_nothing_is_stored(): void
    {
        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'not-a-url',
        ]);

        $response->assertSessionHasErrors('base_url');
        $this->assertNull(Setting::getValue('litsrmm_base_url'),
            'a refused submission must not half-apply');
    }

    // ---- the key is never echoed back ----

    public function test_the_stored_api_key_is_never_rendered_into_the_page(): void
    {
        $this->configure();

        $response = $this->actingAs($this->admin())->get('/settings/integrations');

        $response->assertOk();
        // Asserting on the whole body, not on a field: the hazard is the value
        // appearing ANYWHERE - a value attribute, a data- attribute, a JS blob.
        $this->assertStringNotContainsString('panel-token-value', $response->getContent(),
            'the API key must not be recoverable from the settings page or its HTML source');
    }

    public function test_the_base_url_i_s_shown_so_an_operator_can_confirm_the_host(): void
    {
        $this->configure();

        $response = $this->actingAs($this->admin())->get('/settings/integrations');

        // The counterpart to the control above: hiding the host too would leave
        // an operator unable to tell which instance is configured. This control
        // exists so "hide everything" cannot satisfy the test above.
        $response->assertSee('https://litsrmm.test', false);
    }

    // ---- Test connection reaches the real client ----

    public function test_test_connection_reports_success_and_stamps_the_time(): void
    {
        $this->configure();
        $this->bindClient([new Response(200, [], json_encode(['ok' => true]))]);

        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertCount(1, $this->history,
            'the button must reach the real client, not report success from configuration alone');
        $this->assertStringEndsWith('/v1/health', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('Bearer panel-token-value', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertNotNull(Setting::getValue('litsrmm_connected_at'));
    }

    public function test_test_connection_reports_failure_without_stamping_the_time(): void
    {
        $this->configure();
        $this->bindClient([new Response(500, [], 'upstream exploded')]);

        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test');

        $response->assertOk();
        $response->assertJson(['success' => false]);
        $this->assertNull(Setting::getValue('litsrmm_connected_at'),
            'a failed test must not leave a "last successful test" timestamp behind');
    }

    public function test_a_missing_api_key_and_a_missing_host_are_reported_separately(): void
    {
        // Two different operator actions, so one shared "not configured"
        // message would send them looking at the wrong field.
        Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');
        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test');
        $response->assertJson(['success' => false]);
        $this->assertStringContainsString('API key', $response->json('message'));

        Setting::setEncrypted('litsrmm_api_key', 'panel-token-value');
        Setting::setValue('litsrmm_base_url', '');
        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test');
        $response->assertJson(['success' => false]);
        $this->assertStringContainsString('Base URL', $response->json('message'));
    }

    public function test_a_switched_off_integration_says_so_rather_than_blaming_credentials(): void
    {
        $this->configure();
        Setting::setValue('litsrmm_enabled', '0');
        $this->bindClient([new Response(200, [], json_encode(['ok' => true]))]);

        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test');

        $response->assertJson(['success' => false]);
        $this->assertStringContainsString('switched off', $response->json('message'),
            'isHealthy() is gated on isAvailable(), so a disabled vendor fails the test - say why');
        $this->assertCount(0, $this->history,
            'and it must not reach the network while disabled');
    }

    // ---- the toggle is reachable from the panel ----

    public function test_the_toggle_route_accepts_litsrmm(): void
    {
        $this->configure();

        $response = $this->actingAs($this->admin())->post('/settings/integrations/toggle', [
            'integration' => 'litsrmm',
        ]);

        $response->assertRedirect(route('settings.integrations'));
        // No 'enabled' key submitted = the unchecked checkbox = switched off.
        $this->assertSame('0', Setting::getValue('litsrmm_enabled'));

        $this->actingAs($this->admin())->post('/settings/integrations/toggle', [
            'integration' => 'litsrmm',
            'enabled' => '1',
        ]);
        $this->assertSame('1', Setting::getValue('litsrmm_enabled'));
    }

    public function test_the_test_button_is_wired_to_a_route_the_javascript_knows(): void
    {
        $this->configure();

        $response = $this->actingAs($this->admin())->get('/settings/integrations');
        $body = $response->getContent();

        // The page's testConnection() helper looks the URL up in an explicit
        // route map keyed by service name. A button whose key is missing from
        // that map fetches undefined and fails silently, which looks exactly
        // like a broken integration. Both halves are asserted because either
        // one alone passes while the button does nothing.
        $this->assertStringContainsString("testConnection('litsrmm')", $body,
            'the button must call the shared helper');
        $this->assertStringContainsString("litsrmm: '".route('settings.integrations.litsrmm.test')."'", $body,
            'and the helper must be able to resolve a URL for it');
    }

    // ---- plaintext transport is refused in BOTH layers ----

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function baseUrlSchemes(): array
    {
        return [
            'https remote' => ['https://rmm.partner.example', true],
            'https loopback' => ['https://127.0.0.1:8443', true],
            'http remote host' => ['http://rmm.partner.example', false],
            'http remote ip' => ['http://203.0.113.10', false],
            'http localhost' => ['http://localhost:8080', true],
            'http 127.0.0.1' => ['http://127.0.0.1', true],
            // 127.0.0.0/8 in full, not only .1.
            'http 127.9.9.9' => ['http://127.9.9.9', true],
            'http ipv6 loopback' => ['http://[::1]:9000', true],
            // The trap a substring test falls into: a different machine with a
            // reassuring name.
            'http localhost-lookalike' => ['http://localhost.attacker.example', false],
            'http 127-lookalike' => ['http://127.0.0.1.attacker.example', false],
            'no scheme' => ['rmm.partner.example', false],
            'ftp' => ['ftp://rmm.partner.example', false],
        ];
    }

    #[DataProvider('baseUrlSchemes')]
    public function test_the_client_refuses_plaintext_unless_the_host_is_loopback(string $url, bool $allowed): void
    {
        if ($allowed) {
            LitsrmmClient::assertTransportIsSafe($url);
            $this->assertTrue(true, $url.' must be accepted');

            return;
        }

        $this->expectException(LitsrmmClientException::class);
        LitsrmmClient::assertTransportIsSafe($url);
    }

    public function test_a_refused_plaintext_request_never_builds_the_authorization_header(): void
    {
        Setting::setEncrypted('litsrmm_api_key', 'panel-token-value');
        Setting::setValue('litsrmm_base_url', 'http://rmm.partner.example');

        // A 200 is queued deliberately: if the guard leaks, the call SUCCEEDS
        // and the history records the token, so this fails loudly rather than
        // passing because a fixture happened to error.
        $mock = new MockHandler([new Response(200, [], json_encode(['data' => []]))]);
        $stack = HandlerStack::create($mock);
        $history = [];
        $stack->push(Middleware::history($history));

        $client = new LitsrmmClient([
            'api_key' => 'panel-token-value',
            'base_url' => 'http://rmm.partner.example',
            'handler' => $stack,
        ]);

        try {
            $client->get('v1/clients');
            $this->fail('a plaintext base URL must be refused');
        } catch (LitsrmmClientException $e) {
            $this->assertStringContainsString('https', $e->getMessage());
        }

        $this->assertCount(0, $history,
            'the refusal must happen before any request is composed, so no credential can have left the process');

        // MEASURED LIMIT of this control, stated rather than implied: moving the
        // guard BELOW the header construction still passes here, because the
        // assertion observes what LEFT the process and under either ordering
        // Guzzle is never reached. The two are equivalent for exposure - the
        // header is built into a local array and discarded - so the early
        // placement is a defence-in-depth choice, not something this test
        // pins. What it does pin is that no request is composed at all, which
        // is the property that matters: a credential cannot leak from a call
        // that was never made.
    }

    public function test_the_refusal_message_never_carries_the_credential(): void
    {
        Setting::setEncrypted('litsrmm_api_key', 'panel-token-value');
        Setting::setValue('litsrmm_base_url', 'http://rmm.partner.example');
        \Illuminate\Support\Facades\Log::spy();

        $client = new LitsrmmClient([
            'api_key' => 'panel-token-value',
            'base_url' => 'http://rmm.partner.example',
        ]);

        try {
            $client->get('v1/clients');
        } catch (LitsrmmClientException $e) {
            $this->assertStringNotContainsString('panel-token-value', $e->getMessage(),
                'the exception explaining a credential risk must not itself carry the credential');
        }

        // The warning names the scheme and host so an operator can act, and
        // must not name the key. This is the axis the history assertion cannot
        // see: a refusal that logs the token has leaked it without sending it.
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                $blob = $message.' '.json_encode($context);

                return str_contains($blob, 'plaintext') && ! str_contains($blob, 'panel-token-value');
            })
            ->once();
    }

    public function test_a_loopback_http_host_still_reaches_the_network(): void
    {
        Setting::setEncrypted('litsrmm_api_key', 'panel-token-value');
        Setting::setValue('litsrmm_base_url', 'http://127.0.0.1:8080');

        $mock = new MockHandler([new Response(200, [], json_encode(['data' => []]))]);
        $stack = HandlerStack::create($mock);
        $history = [];
        $stack->push(Middleware::history($history));

        $client = new LitsrmmClient([
            'api_key' => 'panel-token-value',
            'base_url' => 'http://127.0.0.1:8080',
            'handler' => $stack,
        ]);
        $client->getClients();

        // The positive control for the guard: a version that refused every
        // plain-http host would pass every refusal case above and fail here.
        $this->assertCount(1, $history);
        $this->assertSame('Bearer panel-token-value', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_the_form_refuses_a_plaintext_base_url_and_stores_nothing(): void
    {
        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'http://rmm.partner.example',
            'api_key' => 'operator-entered-key',
        ]);

        $response->assertSessionHasErrors('base_url');
        $this->assertNull(Setting::getValue('litsrmm_base_url'));
        $this->assertNull(Setting::getValue('litsrmm_api_key'),
            'a refused submission must not half-apply: the key must not be stored beside a rejected host');
    }

    public function test_the_form_accepts_https_and_loopback_http(): void
    {
        $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'https://rmm.partner.example',
        ])->assertSessionHasNoErrors();
        $this->assertSame('https://rmm.partner.example', Setting::getValue('litsrmm_base_url'));

        // Key supplied with it, because this run moves the host from the
        // https value set just above.
        $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'http://localhost:8080',
            'api_key' => 'freshly-entered-key',
        ])->assertSessionHasNoErrors();
        $this->assertSame('http://localhost:8080', Setting::getValue('litsrmm_base_url'));
    }

    public function test_test_connection_names_a_plaintext_host_rather_than_blaming_credentials(): void
    {
        // A host set through env, or stored before this rule existed, never
        // passed the form. isHealthy() would catch the refusal and return a
        // bare false, which reads as "check your credentials" (diff:7).
        Setting::setEncrypted('litsrmm_api_key', 'panel-token-value');
        Setting::setValue('litsrmm_base_url', 'http://rmm.partner.example');

        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test');

        $response->assertJson(['success' => false]);
        $this->assertStringContainsString('https', $response->json('message'));
        $this->assertNull(Setting::getValue('litsrmm_connected_at'));
    }

    public function test_a_failed_submit_does_not_flash_the_credential_into_the_session(): void
    {
        // The hazard is NOT the rendered page - the view never calls old() for
        // these fields. It is the session store: a failed validate() redirects
        // withInput(), and the framework's $dontFlash covers only the password
        // fields, so api_key would sit in _old_input in cleartext (this app
        // runs SESSION_ENCRYPT=false).
        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'not-a-url',
            'api_key' => 'secret-should-not-persist',
            'webhook_secret' => 'webhook-should-not-persist',
        ]);

        $response->assertSessionHasErrors('base_url');

        $old = session('_old_input') ?? [];
        $blob = json_encode($old);
        $this->assertStringNotContainsString('secret-should-not-persist', (string) $blob,
            'a rejected submission must not leave the API key in the session store');
        $this->assertStringNotContainsString('webhook-should-not-persist', (string) $blob,
            'nor the webhook secret');
    }

    public function test_changing_the_host_requires_re_entering_the_key(): void
    {
        $this->configure();

        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'https://attacker.example',
        ]);

        // Otherwise someone who can edit settings but cannot read the
        // encrypted key could re-point the host and press Test connection,
        // and the key they were never shown would be sent there.
        $response->assertSessionHas('error');
        $this->assertSame('https://litsrmm.test', Setting::getValue('litsrmm_base_url'),
            'the host must not move while the old key stays behind');
    }

    public function test_the_host_can_be_changed_when_the_key_is_supplied_with_it(): void
    {
        $this->configure();

        $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'https://moved.example.com',
            'api_key' => 'freshly-entered-key',
        ])->assertSessionHasNoErrors();

        $this->assertSame('https://moved.example.com', Setting::getValue('litsrmm_base_url'));
        $this->assertSame('freshly-entered-key', Setting::getEncrypted('litsrmm_api_key'));
    }

    public function test_a_stored_key_with_no_host_cannot_be_pointed_at_a_host_without_re_entering_it(): void
    {
        // The guard protects the stored KEY, so it must hold whether or not a
        // host is stored yet. Saving the key before the host leaves exactly
        // this state, and the first host is as much a new destination as a
        // changed one.
        Setting::setEncrypted('litsrmm_api_key', 'panel-token-value');
        $this->bindClient([new Response(200, [], json_encode(['ok' => true]))]);

        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'https://attacker.example',
        ]);

        $response->assertSessionHas('error');
        $this->assertNull(Setting::getValue('litsrmm_base_url'),
            'a host must not be attached to a stored key by someone who did not supply that key');

        // And the end-to-end consequence: Test connection has nowhere to send it.
        $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test')
            ->assertJson(['success' => false]);
        $this->assertCount(0, $this->history,
            'the stored key must not have left the process');
    }

    public function test_the_credential_routes_refuse_a_non_admin(): void
    {
        $tech = User::factory()->create(['role' => UserRole::Tech]);

        // Access control was resting entirely on which middleware group the
        // routes happen to sit in, with no control saying so.
        $this->actingAs($tech)->post('/settings/integrations/litsrmm', [
            'base_url' => 'https://rmm.partner.example',
            'api_key' => 'tech-should-not-write',
        ])->assertForbidden();

        $this->actingAs($tech)->post('/settings/integrations/litsrmm/test')->assertForbidden();

        $this->assertNull(Setting::getValue('litsrmm_base_url'),
            'a refused write must not land');
    }

    // ---- the three badge states are distinguishable ----

    public function test_a_configured_but_disabled_integration_does_not_read_as_unconfigured(): void
    {
        $this->configure();
        Setting::setValue('litsrmm_enabled', '0');

        $response = $this->actingAs($this->admin())->get('/settings/integrations');

        // Collapsing these two states is how an operator concludes their
        // credentials were lost and re-enters them.
        $response->assertSee('Configured (disabled)', false);
    }

    public function test_an_unconfigured_integration_hides_the_enable_toggle(): void
    {
        $response = $this->actingAs($this->admin())->get('/settings/integrations');

        $response->assertOk();
        $this->assertStringNotContainsString('id="litsrmm_enabled"', $response->getContent(),
            'offering a switch for a vendor with no credentials invites turning on something inert');
    }
}
