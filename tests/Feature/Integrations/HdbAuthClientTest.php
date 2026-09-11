<?php

namespace Tests\Feature\Integrations;

use App\Models\Setting;
use App\Services\Hdb\HdbAuthClient;
use App\Services\Hdb\HdbAuthResult;
use App\Services\Hdb\HdbAuthStatus;
use App\Services\Hdb\HdbRedirectRefusedException;
use App\Support\HdbPortalConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The HDB report-portal login handshake (psa #340).
 *
 * Two properties carry most of the weight here, and both are security ones
 * rather than functional ones:
 *
 * 1. **Nothing the portal says escapes.** The integrations page assigns a test
 *    result through `innerHTML`, so any vendor text that reached the result
 *    object would be a write primitive on an admin's DOM. Every fake response
 *    below carries a beacon string, and every assertion checks it did not
 *    survive.
 * 2. **Unknown means failure.** A page this client cannot classify must never
 *    read as a successful login — a connection test that lies is worse than one
 *    that errors, because it stops an operator investigating.
 *
 * The shapes fed in are honest about what was measured: the LOGIN page is the
 * real one, byte-observed 2026-09-11. The two-factor challenge is NOT — it is
 * unreachable without credentials, so those cases assert the client's stated
 * behaviour on plausible shapes, and the unrecognised-prompt case exists because
 * the real one may well be none of them.
 */
class HdbAuthClientTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://portal.example.test';

    private const LOGIN_URL = 'portal.example.test/login';

    /** Text planted in every fake response body; must never reach the operator. */
    private const BEACON = 'VENDOR-TEXT-<script>alert(1)</script>-BEACON';

    /** base32 of the RFC 6238 secret; any decodable seed would do. */
    private const SEED = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function setUp(): void
    {
        parent::setUp();

        // The destination guard resolves the host and fails closed on NXDOMAIN.
        // This portal name is an RFC 6761 example that resolves nowhere, so the
        // resolution step gets an injected public answer; every other part of
        // the guard still applies to it.
        $this->app->instance(HdbPortalConfig::HOST_RESOLVER, fn (string $host) => ['93.184.216.34']);

        Setting::setValue('hdb_base_url', self::BASE);
        Setting::setValue('hdb_email', 'reports@example.test');
        Setting::setEncrypted('hdb_password', 'service-account-password');
    }

    /** The portal's real login form, reduced to the parts the client reads. */
    private function loginPage(): string
    {
        return <<<'HTML'
            <html><body><!-- VENDOR-TEXT-<script>alert(1)</script>-BEACON -->
            <form action="" method="post" id="theOnlyForm">
                <input type="email" name="email" id="email">
                <input type="password" name="password" id="password">
                <input type="hidden" name="g" value="g">
                <input type="submit" name="submit" id="submitButton" disabled hidden>
            </form></body></html>
            HTML;
    }

    private function signedInPage(): string
    {
        return '<html><body><h1>Reports</h1><!-- '.self::BEACON.' --><a href="/logout">Sign out</a></body></html>';
    }

    private function challengePage(string $fieldName = 'otp', string $action = ''): string
    {
        return '<html><body><!-- '.self::BEACON.' --><p>Enter the verification code from your authenticator.</p>'
            .'<form action="'.$action.'" method="post">'
            .'<input type="hidden" name="challenge_id" value="abc123">'
            .'<input type="text" name="'.$fieldName.'" maxlength="6">'
            .'<input type="submit" name="submit"></form></body></html>';
    }

    private function assertNothingLeaked(HdbAuthResult $result): void
    {
        $this->assertStringNotContainsString(self::BEACON, $result->message());
        $this->assertStringNotContainsString('<script', $result->message());
        $this->assertStringNotContainsString(self::BEACON, $result->reason);
    }

    public function test_it_reports_not_configured_without_sending_anything(): void
    {
        Setting::setEncrypted('hdb_password', '');
        Http::fake();

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthStatus::NotConfigured, $result->status);
        $this->assertSame(HdbAuthResult::REASON_MISSING_CREDENTIALS, $result->reason);
        $this->assertSame(0, $result->requests);
        Http::assertNothingSent();
    }

    public function test_it_authenticates_when_the_portal_serves_a_page_that_is_not_the_login_form(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->signedInPage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertTrue($result->ok());
        $this->assertSame(HdbAuthResult::REASON_OK, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_it_posts_the_javascript_guard_value_not_the_one_in_the_html(): void
    {
        // The hidden field `g` ships as ASCII 'g' and the page's own JS rewrites
        // it to U+0261 before submit. Posting what the HTML contained is how the
        // portal spots a client that never ran the script.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->signedInPage()),
        ]);

        (new HdbAuthClient)->authenticate();

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $g = $request->data()['g'] ?? null;

            return $g === "\u{0261}" && $g !== 'g';
        });
    }

    public function test_it_posts_the_stored_credentials_and_a_submit_field(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->signedInPage()),
        ]);

        (new HdbAuthClient)->authenticate();

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && ($data['email'] ?? null) === 'reports@example.test'
                && ($data['password'] ?? null) === 'service-account-password'
                && array_key_exists('submit', $data);
        });
    }

    public function test_a_re_served_login_form_is_a_credential_rejection(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->loginPage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_CREDENTIALS_REJECTED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_it_answers_a_recognised_second_factor_challenge(): void
    {
        Setting::setEncrypted('hdb_totp_secret', self::SEED);

        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->challengePage())
                ->push($this->signedInPage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertTrue($result->ok());
        $this->assertSame(3, $result->requests);
        $this->assertNothingLeaked($result);

        // Six digits into the field the page named, plus the hidden field it
        // carried — a challenge id dropped on the floor is a failed second leg.
        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && isset($data['otp'])
                && preg_match('/^\d{6}$/', (string) $data['otp']) === 1
                && ($data['challenge_id'] ?? null) === 'abc123';
        });
    }

    public function test_a_challenge_with_no_stored_seed_says_so_specifically(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->challengePage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        // The password WAS accepted. Reporting this as "credentials rejected"
        // would send an operator to re-type a password that is already correct.
        $this->assertSame(HdbAuthResult::REASON_TOTP_REQUIRED_NO_SEED, $result->reason);
        $this->assertSame(2, $result->requests);
        $this->assertNothingLeaked($result);
    }

    public function test_a_challenge_with_an_undecodable_seed_says_so_specifically(): void
    {
        Setting::setEncrypted('hdb_totp_secret', 'not-base32!');

        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->challengePage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthResult::REASON_TOTP_SEED_UNUSABLE, $result->reason);
        $this->assertSame(2, $result->requests);
        $this->assertNothingLeaked($result);
    }

    public function test_an_unrecognised_second_factor_prompt_is_never_read_as_success(): void
    {
        // The one honest gap in this client: the real challenge page has not been
        // observed. A prompt whose field this client cannot name must fail, and
        // must fail DISTINCTLY, so the first real run says which leg to fix.
        Setting::setEncrypted('hdb_totp_secret', self::SEED);

        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->challengePage('please_type_the_thing')),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthResult::REASON_TOTP_CHALLENGE_UNRECOGNISED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_a_query_only_challenge_action_keeps_the_page_path(): void
    {
        // `?submit=1` is a query-only reference: per RFC 3986 it keeps the
        // page's own path and replaces only the query. Resolving it against the
        // page's DIRECTORY would post the live code to /?submit=1, whose answer
        // then misreports as a refused seed on a portal that is working.
        Setting::setEncrypted('hdb_totp_secret', self::SEED);

        Http::fake([
            'portal.example.test/*' => Http::sequence()
                ->push($this->loginPage())
                ->push($this->challengePage('otp', '?submit=1'))
                ->push($this->signedInPage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertTrue($result->ok());
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::BASE.'/login?submit=1');
    }

    public function test_a_portal_url_with_an_explicit_default_port_still_matches_its_own_origin(): void
    {
        // Guzzle's PSR-7 Uri drops the scheme's default port from every redirect
        // hop and from effectiveUri(), so a stored `:443` compared verbatim would
        // refuse every same-origin hop and absolute same-origin action — on a
        // Portal URL the config guard accepts.
        Setting::setValue('hdb_base_url', self::BASE.':443');
        Setting::setEncrypted('hdb_totp_secret', self::SEED);

        Http::fake([
            'portal.example.test*' => Http::sequence()
                ->push($this->loginPage())
                ->push($this->challengePage('otp', self::BASE.'/2fa'))
                ->push($this->signedInPage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertTrue($result->ok());
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_starts_with($request->url(), self::BASE.'/2fa'));
    }

    public function test_it_refuses_to_post_a_code_to_a_challenge_form_on_another_host(): void
    {
        Setting::setEncrypted('hdb_totp_secret', self::SEED);

        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->challengePage('otp', 'https://evil.example.test/collect')),
            '*' => Http::response('should never be reached', 200),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthResult::REASON_TOTP_CHALLENGE_UNRECOGNISED, $result->reason);

        // A live one-time code is a credential. It goes to the configured origin
        // or nowhere.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example.test'));
    }

    public function test_a_refused_code_reports_as_a_rejected_code(): void
    {
        Setting::setEncrypted('hdb_totp_secret', self::SEED);

        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->challengePage())
                ->push($this->challengePage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_TOTP_REJECTED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_it_does_not_retry_a_refused_code(): void
    {
        Setting::setEncrypted('hdb_totp_secret', self::SEED);

        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->challengePage())
                ->push($this->challengePage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        // Retrying a second factor against a service subaccount is a lockout
        // risk, and the next code is 30 seconds away regardless.
        $this->assertSame(3, $result->requests);
        $this->assertLessThanOrEqual(HdbAuthClient::MAX_REQUESTS, $result->requests);
        Http::assertSentCount(3);
    }

    public function test_a_transport_failure_is_unreachable_and_leaks_no_exception_text(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 6: Could not resolve host: '.self::BEACON);
        });

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthStatus::Unreachable, $result->status);
        $this->assertSame(HdbAuthResult::REASON_TRANSPORT_ERROR, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_a_server_error_is_never_read_as_a_successful_login(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push(self::BEACON, 500),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Unreachable, $result->status);
        $this->assertSame(HdbAuthResult::REASON_UNEXPECTED_RESPONSE, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_a_forbidden_response_is_never_read_as_a_successful_login(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push(self::BEACON, 403),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthResult::REASON_UNEXPECTED_RESPONSE, $result->reason);
    }

    public function test_an_empty_body_is_never_read_as_a_successful_login(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push('   ', 200),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthResult::REASON_UNEXPECTED_RESPONSE, $result->reason);
    }

    public function test_the_logged_out_landing_redirect_is_not_read_as_a_successful_login(): void
    {
        // The portal's `/` is a 182-byte JS redirect to login.php. Landing back
        // on it means the session did not take.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push('<html><body><script>window.location = "login.php";</script></body></html>'),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthResult::REASON_CREDENTIALS_REJECTED, $result->reason);
    }

    public function test_it_uses_the_default_portal_host_when_none_is_stored(): void
    {
        Setting::setValue('hdb_base_url', '');

        Http::fake([
            'beta.helpdeskbuttons.com/login' => Http::sequence()
                ->push($this->loginPage())
                ->push($this->signedInPage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertTrue($result->ok());
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://beta.helpdeskbuttons.com/login'));
    }

    public function test_it_refuses_to_spend_the_credential_on_a_plain_http_portal_url(): void
    {
        // The destination is checked before the credential is loaded, and on
        // EVERY attempt rather than only at save time — so a URL stored before
        // the write gate was admin-only, or one an admin typed wrong since, is
        // refused here rather than spent.
        Setting::setValue('hdb_base_url', 'http://attacker.example');
        Http::fake();

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthResult::REASON_PORTAL_URL_REFUSED, $result->reason);
        $this->assertSame(0, $result->requests);
        Http::assertNothingSent();
    }

    public function test_it_refuses_to_spend_the_credential_on_an_internal_address(): void
    {
        // The same primitive reaches link-local metadata services, so an IP
        // literal is refused whatever its scheme.
        Setting::setValue('hdb_base_url', 'https://169.254.169.254');
        Http::fake();

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthResult::REASON_PORTAL_URL_REFUSED, $result->reason);
        $this->assertNothingLeaked($result);
        Http::assertNothingSent();
    }

    public function test_a_portal_host_that_does_not_resolve_is_unreachable_not_a_refused_url(): void
    {
        // The destination guard runs on EVERY attempt, so a resolver outage hits
        // a saved, unchanged, valid URL. Fail closed — nothing is sent — but the
        // operator must be able to tell this from a URL we refused on sight.
        $this->app->instance(HdbPortalConfig::HOST_RESOLVER, fn (string $host) => []);
        Http::fake();

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Unreachable, $result->status);
        $this->assertSame(HdbAuthResult::REASON_PORTAL_URL_UNRESOLVED, $result->reason);
        $this->assertSame(0, $result->requests);
        Http::assertNothingSent();
    }

    /**
     * The redirect control, pulled out of the options array and invoked.
     *
     * `Http::fake()` installs its stub OUTSIDE Guzzle's RedirectMiddleware, so a
     * faked 302 comes back as a body and is never followed — no amount of faking
     * reaches `allow_redirects`, and that is why three cycles of work on this
     * guard shipped with no coverage at all. Measured on `67348bf8`: deleting
     * `'protocols' => ['https']` AND the whole `on_redirect` closure left all 24
     * tests in this file, and all 93 HDB tests, green. The seam that does work is
     * the options array the fake callback is handed: the closure the client
     * installed can be taken out of it and called with the hop URI Guzzle would
     * have passed.
     *
     * @return array{max: int, strict: bool, referer: bool, protocols: array<int, string>, on_redirect: callable}
     */
    private function capturedRedirectOptions(): array
    {
        $captured = null;

        Http::fake(function (Request $request, array $options) use (&$captured) {
            $captured ??= $options;

            return Http::response($this->loginPage());
        });

        (new HdbAuthClient)->authenticate();

        $this->assertIsArray($captured, 'Nothing was sent, so no request options were captured.');
        $this->assertIsArray($captured['allow_redirects'] ?? null, 'The client sent a request with no redirect policy at all.');

        return $captured['allow_redirects'];
    }

    public function test_every_request_carries_the_redirect_guard(): void
    {
        $allow = $this->capturedRedirectOptions();

        // Guzzle's own default allows http and checks no host; both are pinned.
        $this->assertSame(['https'], $allow['protocols'] ?? null);
        $this->assertSame(5, $allow['max'] ?? null);
        $this->assertIsCallable($allow['on_redirect'] ?? null);
    }

    public function test_the_redirect_guard_refuses_a_hop_to_another_host(): void
    {
        $onRedirect = $this->capturedRedirectOptions()['on_redirect'];

        // `strict` re-POSTs the credential body on every hop, so an off-origin
        // hop must be refused BEFORE it is followed.
        $this->expectException(HdbRedirectRefusedException::class);

        $onRedirect(null, null, new Uri('https://attacker.example/login'));
    }

    public function test_the_redirect_guard_refuses_a_lookalike_host(): void
    {
        $onRedirect = $this->capturedRedirectOptions()['on_redirect'];

        $this->expectException(HdbRedirectRefusedException::class);

        $onRedirect(null, null, new Uri('https://portal.example.test.evil.test/login'));
    }

    public function test_the_redirect_guard_refuses_a_cleartext_hop_on_the_portals_own_host(): void
    {
        $onRedirect = $this->capturedRedirectOptions()['on_redirect'];

        // Both halves refuse this hop, but not at the same moment, and the
        // earlier one wins: `protocols` refuses it inside RedirectMiddleware
        // BEFORE on_redirect is reached, so Guzzle never hands this closure an
        // http hop at all — pinned by
        // test_guzzle_refuses_a_scheme_downgrade_before_the_on_redirect_guard_sees_it.
        // So this is not "the second of the two locks" as it used to claim; it is
        // the closure holding the line if `protocols` were ever relaxed. The
        // reachable path is
        // test_a_refused_scheme_downgrade_reports_as_a_refused_redirect_not_a_network_fault.
        $this->expectException(HdbRedirectRefusedException::class);

        $onRedirect(null, null, new Uri('http://portal.example.test/login'));
    }

    public function test_the_redirect_guard_follows_a_hop_inside_the_portal_origin(): void
    {
        $onRedirect = $this->capturedRedirectOptions()['on_redirect'];

        try {
            $onRedirect(null, null, new Uri('https://portal.example.test/login?next=reports'));
            $onRedirect(null, null, new Uri('https://portal.example.test:443/reports'));
        } catch (HdbRedirectRefusedException) {
            $this->fail('A hop inside the configured portal origin was refused.');
        }

        // Saying what that second hop is actually worth, in place of the filler
        // assertTrue(true) this test used to end on: PSR-7's Uri drops a scheme's
        // default port at construction, so the :443 argument reaches the guard
        // with no port on it and cannot tell a port-blind comparator from a
        // port-aware one. It pins the comparator's INPUT, not the guard. The port
        // case that can really differ lives on the stored-setting side, and
        // test_a_portal_url_with_an_explicit_default_port_still_matches_its_own_origin
        // is what covers that.
        $this->assertSame(
            'https://portal.example.test/reports',
            (string) new Uri('https://portal.example.test:443/reports'),
        );
    }

    public function test_a_refused_redirect_is_reported_as_one_and_leaks_nothing(): void
    {
        Http::fake(fn () => throw new HdbRedirectRefusedException);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Unreachable, $result->status);
        $this->assertSame(HdbAuthResult::REASON_REDIRECT_REFUSED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_a_redirect_loop_reports_as_the_request_budget_not_a_network_fault(): void
    {
        // Guzzle throws this from RedirectMiddleware::guardMax(), but Laravel
        // does not let it out: PendingRequest::send() catches TransferException
        // and — because Response::toException() is null for a 3xx — re-throws it
        // as ConnectionException with the Guzzle exception only on getPrevious().
        // Without the unwrapping this reads REASON_TRANSPORT_ERROR: a portal that
        // was reached and looping, reported as a firewall problem.
        Http::fake(fn () => throw new TooManyRedirectsException(
            'Will not follow more than 5 redirects to '.self::BEACON,
            new GuzzleRequest('GET', self::BASE.'/login'),
            new GuzzleResponse(302, ['Location' => self::BASE.'/login']),
        ));

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Unreachable, $result->status);
        $this->assertSame(HdbAuthResult::REASON_REQUEST_BUDGET_EXHAUSTED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_a_refused_scheme_downgrade_reports_as_a_refused_redirect_not_a_network_fault(): void
    {
        // The `protocols` half of the guard refuses inside RedirectMiddleware, so
        // it never raises HdbRedirectRefusedException — it raises a
        // BadResponseException carrying the 302 that proposed the downgrade,
        // wrapped exactly like the loop above. A deliberate downgrade attempt
        // must not read as a DNS blip. The beacon sits in the vendor message on
        // purpose: the symbol is chosen by class and status, never by text.
        Http::fake(fn () => throw new BadResponseException(
            'Redirect URI, http://portal.example.test/'.self::BEACON.', does not use one of the allowed redirect protocols: https',
            new GuzzleRequest('GET', self::BASE.'/login'),
            new GuzzleResponse(302, ['Location' => 'http://portal.example.test/login']),
        ));

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Unreachable, $result->status);
        $this->assertSame(HdbAuthResult::REASON_REDIRECT_REFUSED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_guzzle_refuses_a_scheme_downgrade_before_the_on_redirect_guard_sees_it(): void
    {
        // Http::fake() cannot settle this one: its stub handler is installed
        // OUTSIDE RedirectMiddleware, so a faked 302 comes back as a body and is
        // never followed. A real Guzzle stack over a MockHandler, fed the
        // client's own captured options, is the seam that actually runs the
        // middleware — which is what makes the two mappings above a measurement
        // rather than a guess about vendor internals.
        $client = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([
                new GuzzleResponse(302, ['Location' => 'http://portal.example.test/login']),
                new GuzzleResponse(200, [], 'must never be reached'),
            ])),
            'http_errors' => false,
        ]);

        try {
            $client->get(self::BASE.'/login', [
                'allow_redirects' => $this->capturedRedirectOptions(),
            ]);

            $this->fail('Guzzle followed an https to http downgrade hop.');
        } catch (HdbRedirectRefusedException) {
            $this->fail('on_redirect refused the hop; `protocols` is what refuses it, one step earlier.');
        } catch (BadResponseException $e) {
            $this->assertSame(302, $e->getResponse()->getStatusCode());
            $this->assertTrue($e->getResponse()->hasHeader('Location'));
        }
    }

    public function test_guzzle_stops_a_same_origin_redirect_loop_at_the_clients_own_cap(): void
    {
        // The other half of the same measurement: a same-origin loop satisfies
        // on_redirect every time, so what ends it is `max`, and what it throws is
        // the class the mapping reads.
        $client = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler(array_map(
                fn () => new GuzzleResponse(302, ['Location' => self::BASE.'/login']),
                range(1, 8),
            ))),
            'http_errors' => false,
        ]);

        $this->expectException(TooManyRedirectsException::class);

        $client->get(self::BASE.'/login', [
            'allow_redirects' => $this->capturedRedirectOptions(),
        ]);
    }

    public function test_every_reason_it_can_return_has_an_operator_message(): void
    {
        // message() falls back to a generic sentence on an unknown reason, which
        // would silently swallow a new one. This is the guard that says so.
        $reasons = array_filter(
            (new \ReflectionClass(HdbAuthResult::class))->getConstants(),
            fn (string $name) => str_starts_with($name, 'REASON_'),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertNotEmpty($reasons);

        $fallback = (new HdbAuthResult(HdbAuthStatus::Unreachable, 'a-reason-nobody-declared'))->message();

        foreach ($reasons as $name => $reason) {
            $message = (new HdbAuthResult(HdbAuthStatus::Unreachable, $reason))->message();

            $this->assertNotSame($fallback, $message, "{$name} has no operator message of its own.");
            $this->assertNotSame('', trim($message));
        }
    }
}
