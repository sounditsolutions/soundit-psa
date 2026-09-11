<?php

namespace Tests\Feature\Integrations;

use App\Models\Setting;
use App\Services\Hdb\HdbAuthClient;
use App\Services\Hdb\HdbAuthResult;
use App\Services\Hdb\HdbAuthStatus;
use App\Support\HdbPortalConfig;
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
        // The destination is checked before the credential is loaded: the Portal
        // URL is writable by any authenticated user (psa #1344), so the admin-only
        // gate on Test Connection cannot be what decides where the password goes.
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
