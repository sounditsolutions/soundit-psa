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
use GuzzleHttp\Middleware;
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
 * real one, byte-observed 2026-09-11. The two REFUSAL notices (`notify--bad`
 * block: "Invalid email or password" / "Invalid Captcha") and the BLANK
 * re-serve (login page again, no notice, byte-identical to the GET) were
 * measured 2026-09-17 with made-up accounts, and the fixtures below are written
 * from that described structure — not pasted from a captured page, which would
 * carry session tokens. The two-factor challenge is NOT measured — it is
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

    /**
     * The IP-filter refusal notice EXACTLY as observed 2026-09-17 19:59Z against
     * the real service subaccount: a `notify--bad` block carrying this sentence,
     * with no address interpolated into it. Pinned byte-for-byte here because it
     * is the payload the needle in {@see HdbAuthClient} was derived from, and a
     * needle can only be checked against the bytes it came from (C-56 rule 2).
     * This is the ONLY place the observed sentence is reproduced; the notices
     * used by the leak cases below are deliberately DIFFERENT, wider shapes.
     */
    private const IP_FILTER_NOTICE_OBSERVED = 'Your IP address is not on the account IP Filter whitelist.';

    /**
     * Addresses, a host and a contact planted inside the leak fixtures.
     * Documentation ranges only — RFC 5737 (v4), RFC 3849 (v6) and RFC 2606
     * (names) — so no real infrastructure is named anywhere in this file.
     */
    private const LEAK_ADDRESS = '203.0.113.47';

    private const LEAK_ADDRESS_V6 = '2001:db8::47';

    private const LEAK_HOST = 'psa.example.invalid';

    private const LEAK_EMAIL = 'support@example.invalid';

    /**
     * Address shapes a vendor notice could carry. A leak guard that only knows
     * IPv4 reports the contract as held for an IPv6 caller — so both shapes,
     * plus a bracketed host:port and a CIDR, are asserted against.
     *
     * @return list<string>
     */
    private static function addressShapePatterns(): array
    {
        return [
            '~\b\d{1,3}(?:\.\d{1,3}){3}\b~',          // dotted quad
            '~\b\d{1,3}(?:\.\d{1,3}){3}/\d{1,2}\b~',  // v4 CIDR
            '~(?:[0-9a-f]{1,4}:){1,7}:(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?|(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}~i', // v6, compressed or full; a bracketed host:port contains one
        ];
    }

    private function assertCarriesNoAddressShape(string $emitted, string $what): void
    {
        foreach (self::addressShapePatterns() as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $emitted, "{$what} carries an address-shaped string.");
        }
    }

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

    /**
     * The login form re-served WITH the portal's refusal notice, as measured
     * 2026-09-17: the page is the login page plus a block whose class carries
     * `notify--bad` and whose text is the portal's sentence. The beacon rides
     * inside the notice as VISIBLE text (a trailing span), not only as a
     * comment, so a client that copied notice text out would fail the leak
     * assertions on every notice test.
     */
    private function refusedLoginPage(string $notice): string
    {
        return <<<HTML
            <html><body><!-- VENDOR-TEXT-<script>alert(1)</script>-BEACON -->
            <div class="notify notify--bad" role="alert"><span class="notify__text">{$notice}</span> <span class="notify__ref">VENDOR-TEXT-<script>alert(1)</script>-BEACON</span></div>
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

    /**
     * The portal's REAL second-factor page, reduced to the parts this client
     * reads. Measured 2026-09-17 21:59Z by the operator who holds the
     * credentials, one shot: the page served at the end of the sign-in chain is
     * titled "2FA Authentication", is 7,229 bytes, and carries the fields `otp`,
     * `g-recaptcha-response` and `totpskip`. Two session cookies were issued.
     *
     * HONEST about what is measured (C-56 rule 2): the TITLE and the three FIELD
     * NAMES are measured. Everything else — element types, the empty captcha
     * value, `totpskip`'s `0`, the form action, the prose — is WRITTEN, not
     * captured, because the captured page carried live session tokens and was
     * never saved. The fixture also carries a FOURTH control the
     * measurement did not record at all — a submit input — present because the
     * client's discovery scan must be shown ignoring it; nothing is asserted
     * about it, and its presence is not evidence the live form has one.
     *
     * And the tests below DO assert off some of those written parts — an earlier
     * draft of this docblock claimed they did not, which was false and is the
     * kind of claim this class exists to stamp out. Specifically: that the
     * captcha key is present at all (true only for the `<input>` shape), that it
     * is empty, that `totpskip` round-trips as `0`, and that the leg completes
     * in three requests (which needs the written `action=""`). Each of those
     * pins the client's FORWARD-AS-SERVED rule against the fixture it is handed;
     * none is evidence about the live page's markup, and none should be read as
     * such.
     *
     * ONE ASSUMPTION IS CALLED OUT RATHER THAN BURIED: the ELEMENT TYPE of
     * `g-recaptcha-response` was not recorded, only its name — and the same is
     * true of `totpskip`, whose type and served `0` are equally invented here;
     * the captcha's is singled out only because reCAPTCHA has a conventional
     * shape to be wrong about. This fixture makes
     * it a hidden `<input>` with an empty value, which is the case that puts the
     * field in front of {@see HdbAuthClient::findChallengeForm} — the harder
     * case, because the client then has to decide what to send for it. If the
     * live page renders it as reCAPTCHA normally does, a `<textarea>`, the
     * client's input-only scan never sees it and forwards nothing for it, which
     * is the same conservative outcome by a shorter route. Either way this
     * client invents no value, and that is what the tests below pin.
     */
    private function measuredTwoFactorPage(): string
    {
        return '<html><head><title>2FA Authentication</title></head><body><!-- '.self::BEACON.' -->'
            .'<p>Enter the verification code from your authenticator.</p>'
            .'<form action="" method="post">'
            .'<input type="text" name="otp" maxlength="6">'
            .'<input type="hidden" name="g-recaptcha-response" value="">'
            .'<input type="hidden" name="totpskip" value="0">'
            .'<input type="submit" name="submit"></form></body></html>';
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

    public function test_it_posts_the_stored_credentials_and_the_browsers_non_empty_submit_value(): void
    {
        // The portal only evaluates a login whose `submit` field is non-empty
        // (measured 2026-09-17: `submit=` gets the blank form back, byte-
        // identical to a GET, for every credential pair). A browser posts
        // `submit=Submit`. Pinning the exact value, not just the key.
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
                && ($data['submit'] ?? null) === 'Submit';
        });
    }

    public function test_a_blank_re_served_login_form_means_the_post_was_not_evaluated(): void
    {
        // Measured 2026-09-17: the portal answers an un-evaluated post with the
        // login page and NO notice — the exact bytes a GET returns. For a week
        // that read as `credentials_rejected`, and it never can again: without
        // the portal's own refusal notice there is no credential verdict.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->loginPage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_LOGIN_NOT_EVALUATED, $result->reason);
        $this->assertNotSame(HdbAuthResult::REASON_CREDENTIALS_REJECTED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_the_portals_invalid_credentials_notice_is_a_credential_rejection(): void
    {
        // The positive marker, measured 2026-09-17 with a made-up account:
        // a `notify--bad` block reading "Invalid email or password".
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->refusedLoginPage('Invalid email or password. Try again.')),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_CREDENTIALS_REJECTED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_the_portals_captcha_notice_is_a_guard_refusal_not_a_credential_verdict(): void
    {
        // Measured 2026-09-17: posting the HTML's ASCII `g` instead of the
        // JS-written U+0261 gets "Invalid Captcha" in the same block. The
        // credentials were never judged, so the symbol must not say they were.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->refusedLoginPage('Invalid Captcha')),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_FORM_GUARD_REFUSED, $result->reason);
        $this->assertNotSame(HdbAuthResult::REASON_CREDENTIALS_REJECTED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_the_portals_ip_filter_notice_is_its_own_reason_not_unrecognised(): void
    {
        // OBSERVED 2026-09-17 19:59Z against the real service subaccount:
        // the portal answered the credential post with the login page plus a
        // `notify--bad` block naming the account's IP Filter whitelist. That is
        // a perimeter refusal, not a verdict on the credentials, and before this
        // case existed it reported REASON_LOGIN_REFUSED_UNRECOGNISED — which
        // tells an operator to suspect a portal change when the actual fix is a
        // whitelist entry. No live call is made here: fixture only.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->refusedLoginPage(self::IP_FILTER_NOTICE_OBSERVED)),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_PORTAL_IP_FILTERED, $result->reason);
        $this->assertNotSame(HdbAuthResult::REASON_LOGIN_REFUSED_UNRECOGNISED, $result->reason);
        $this->assertNotSame(HdbAuthResult::REASON_CREDENTIALS_REJECTED, $result->reason);
        $this->assertNothingLeaked($result);

        // The message promises "nothing was retried"; this is that promise.
        // Two requests: GET the login page, POST the credentials. No third.
        $this->assertSame(2, $result->requests);
    }

    public function test_the_ip_filter_branch_leaks_no_vendor_sentence_address_host_or_email(): void
    {
        // Same leak contract as the other two notices, and the reason this case
        // is worth its own test: the notice a real portal prints here is the one
        // most likely to carry an ADDRESS, and the integrations page renders a
        // test result through `innerHTML`. Nothing off the page may reach the
        // symbol, the operator sentence, the log or the audit row.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->refusedLoginPage(
                    'Your IP address '.self::LEAK_ADDRESS.' ('.self::LEAK_ADDRESS_V6.') is not on the '
                    .'account IP Filter whitelist. Contact '.self::LEAK_EMAIL.' or visit '
                    .self::LEAK_HOST.' to add it.',
                )),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthResult::REASON_PORTAL_IP_FILTERED, $result->reason);
        $this->assertNothingLeaked($result);

        foreach (['reason' => $result->reason, 'message' => $result->message()] as $what => $emitted) {
            $this->assertStringNotContainsString(self::LEAK_ADDRESS, $emitted);
            $this->assertStringNotContainsString(self::LEAK_ADDRESS_V6, $emitted);
            $this->assertStringNotContainsString(self::LEAK_HOST, $emitted);
            $this->assertStringNotContainsString(self::LEAK_EMAIL, $emitted);
            $this->assertCarriesNoAddressShape($emitted, $what);

            // The vendor sentence's DISTINCTIVE parts, case-insensitively. Not
            // "IP Filter whitelist": our own fixed prose legitimately contains
            // that phrase, so asserting its absence would pass only on a casing
            // accident and would break the moment the prose is recapitalised.
            $this->assertStringNotContainsStringIgnoringCase('Your IP address', $emitted);
            $this->assertStringNotContainsStringIgnoringCase('is not on the account', $emitted);
            $this->assertStringNotContainsStringIgnoringCase('to add it', $emitted);
        }

        // The operator sentence must still name the remedy, or the new symbol
        // buys nothing over the unrecognised one it replaces. Asserted on our
        // own prose, which is fixed and ours to pin.
        $this->assertStringContainsString('IP filter whitelist', $result->message());
        $this->assertStringContainsString('outbound address', $result->message());
    }

    public function test_the_ip_filter_notice_outranks_the_credentials_notice_when_both_are_shown(): void
    {
        // PRECEDENCE, decided deliberately: a perimeter refusal is a fact about
        // WHERE the request came from, so a page showing it has not judged the
        // stored password — exactly the argument that puts the guard notice
        // ahead of the credentials one. The IP-filter entry therefore sits with
        // the guard, ABOVE credentials.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push(str_replace(
                    '<form ',
                    '<div class="notify--bad">Invalid email or password</div>'
                    .'<div class="notify--bad">'.self::IP_FILTER_NOTICE_OBSERVED.'</div><form ',
                    $this->loginPage(),
                )),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthResult::REASON_PORTAL_IP_FILTERED, $result->reason);
        $this->assertNotSame(HdbAuthResult::REASON_CREDENTIALS_REJECTED, $result->reason);
        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertFalse($result->ok());
        $this->assertNothingLeaked($result);

        // The operator is NOT told the password is fine on a page that also
        // showed the credentials notice: this symbol reports a perimeter
        // refusal, and its prose sends them back to the password if
        // whitelisting does not resolve it.
        $this->assertStringContainsString('re-check the password', $result->message());
    }

    public function test_the_guard_notice_still_outranks_the_ip_filter_notice(): void
    {
        // And the existing top of the order is not disturbed: the guard notice
        // means the portal judged the hidden field this client posts, which is
        // a change underneath us and the only one an operator cannot fix by
        // editing an account setting.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push(str_replace(
                    '<form ',
                    '<div class="notify--bad">'.self::IP_FILTER_NOTICE_OBSERVED.'</div>'
                    .'<div class="notify--bad">Invalid Captcha</div><form ',
                    $this->loginPage(),
                )),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthResult::REASON_FORM_GUARD_REFUSED, $result->reason);
        $this->assertNotSame(HdbAuthResult::REASON_PORTAL_IP_FILTERED, $result->reason);
        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertFalse($result->ok());
        $this->assertNothingLeaked($result);
    }

    public function test_the_status_vocabulary_is_still_four_cases(): void
    {
        // A new REASON is the closed-vocabulary extension point; a new STATUS is
        // not. An IP-filter refusal is a Rejected, like every other refusal
        // notice, and this pins that nobody added a fifth case to say so.
        // Compared as a SET: declaration order carries no behaviour, so pinning
        // it would fail a harmless reordering and prove nothing.
        $values = array_map(fn (HdbAuthStatus $case) => $case->value, HdbAuthStatus::cases());

        $this->assertCount(4, $values);
        $this->assertEqualsCanonicalizing(
            ['authenticated', 'rejected', 'unreachable', 'not_configured'],
            $values,
        );
    }

    public function test_an_unfamiliar_refusal_notice_is_reported_as_unrecognised_not_as_credentials(): void
    {
        // A notice this client has not seen is still the portal saying no — but
        // WHAT it said is unknown, so it is neither a credential verdict nor a
        // guard refusal, and its text stays inside the client.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->refusedLoginPage('Account locked: '.self::BEACON)),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_LOGIN_REFUSED_UNRECOGNISED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    /**
     * Page fragments that carry the notice words WITHOUT being a notice. Each
     * is spliced in front of the login form; the correct reading of every one
     * is "blank re-serve", never a credential verdict.
     *
     * @return array<string, array{string}>
     */
    public static function notNoticeFragments(): array
    {
        return [
            'empty placeholder block' => ['<div class="notify notify--bad"></div>'],
            'words in a script' => ['<script>var msg = "Invalid email or password";</script>'],
            'words in a comment' => ['<!-- Invalid Captcha -->'],
            'script inside a placeholder block' => ['<div class="notify--bad"><script>t("Invalid Captcha")</script></div>'],
            'style inside a placeholder block' => ['<div class="notify--bad"><style>.x:after{content:"Invalid Captcha"}</style></div>'],
            'token on an attribute merely named like class' => ['<div data-class="notify--bad" ng-class="notify--bad">Invalid email or password</div>'],
            'token inside another attribute value' => ['<div title=\'class="notify--bad"\'>Invalid email or password</div>'],
            'token as a prefix of a longer class' => ['<div class="notify--bad-hint">Invalid email or password</div>'],
            'words in a placeholder whose child is an empty same-tag element' => ['<div class="notify--bad"><div></div></div><div>Invalid email or password</div>'],
            'notice inside a template' => ['<template><div class="notify--bad">Invalid email or password</div></template>'],
            'notice inside noscript' => ['<noscript><div class="notify--bad">Invalid email or password</div></noscript>'],
            'the token on a script element itself' => ['<script class="notify--bad">var msg = "Invalid email or password";</script>'],
            'the token on a template element itself' => ['<template class="notify--bad">Invalid email or password</template>'],
            'the token on a noscript element itself' => ['<noscript class="notify--bad">Invalid Captcha</noscript>'],
            'the token on a style element itself' => ['<style class="notify--bad">.x:after{content:"Invalid email or password"}</style>'],
            'token joined to another class by a vertical tab' => ["<div class=\"notify--bad\x0Bhint\">Invalid email or password</div>"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notNoticeFragments')]
    public function test_notice_words_outside_a_notice_are_not_a_refusal(string $fragment): void
    {
        // The marker is structural: the whole-class token on a `class`
        // attribute, and the visible text INSIDE that element. Anything else
        // on the page is not the portal refusing anything.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push(str_replace('<form ', $fragment.'<form ', $this->loginPage())),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthResult::REASON_LOGIN_NOT_EVALUATED, $result->reason);
    }

    /**
     * Notice shapes a parser must still read as the credentials notice.
     *
     * @return array<string, array{string}>
     */
    public static function awkwardNoticeShapes(): array
    {
        return [
            'unquoted class attribute' => ['<div class=notify--bad>Invalid email or password</div>'],
            'single-quoted class attribute' => ["<div class='notify notify--bad'>Invalid email or password</div>"],
            'custom element tag' => ['<x-notice class="notify--bad">Invalid email or password</x-notice>'],
            'sentence split by inline tags' => ['<div class="notify--bad">Invalid <b>email</b> or <i>password</i>.</div>'],
            'nested same-tag child carrying the sentence' => ['<div class="notify--bad"><div><span>Invalid email or password</span></div></div>'],
            'notice never closed' => ['<div class="notify--bad">Invalid email or password'],
            'entity-encoded text' => ['<div class="notify--bad">Invalid&nbsp;email&#32;or&#x20;password</div>'],
            'an unfamiliar notice stacked before the real one' => ['<div class="notify--bad">Your session expired.</div><div class="notify--bad">Invalid email or password</div>'],
            'a later > inside an earlier attribute' => ['<div title="a>b" class="notify--bad">Invalid email or password</div>'],
            'classes separated by a tab and a newline' => ["<div class=\"notify\tnotify--bad\nis-open\">Invalid email or password</div>"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('awkwardNoticeShapes')]
    public function test_awkward_but_real_notice_markup_is_still_read(string $fragment): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push(str_replace('<form ', $fragment.'<form ', $this->loginPage())),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthResult::REASON_CREDENTIALS_REJECTED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_the_guard_notice_outranks_the_credentials_notice_when_both_are_shown(): void
    {
        // A page that judged the guard did not judge the credentials, whatever
        // else it printed. The guard symbol is the one the operator can act on.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push(str_replace(
                    '<form ',
                    '<div class="notify--bad">Invalid email or password</div><div class="notify--bad">Invalid Captcha</div><form ',
                    $this->loginPage(),
                )),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthResult::REASON_FORM_GUARD_REFUSED, $result->reason);
    }

    public function test_a_notice_that_is_not_valid_utf8_is_unrecognised_not_a_placeholder(): void
    {
        // A stray latin-1 byte inside the notice must not collapse it to an
        // empty placeholder: the portal said something, and the client could
        // not read it. Fail-closed, and never through to the success branch.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push("<html><body><div class=\"notify--bad\">Acc\xE9s refus\xE9</div></body></html>"),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthResult::REASON_LOGIN_REFUSED_UNRECOGNISED, $result->reason);
    }

    public function test_a_refusal_notice_with_no_login_form_is_still_a_refusal_not_a_pass(): void
    {
        // A standalone refusal page (a lockout notice, say) carries no password
        // field, no form id and no landing redirect — exactly the shape the
        // negative success test would otherwise wave through.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push('<html><body><div class=notify--bad>Account locked: '.self::BEACON.'</div></body></html>'),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_LOGIN_REFUSED_UNRECOGNISED, $result->reason);
        $this->assertNothingLeaked($result);
    }

    public function test_a_refusal_notice_outranks_a_code_shaped_form_so_no_code_is_posted(): void
    {
        // A page that has just said the credentials were refused is not a
        // second-factor prompt, whatever else it carries. A live one-time code
        // must not be generated for it and nothing further is sent.
        Setting::setEncrypted('hdb_totp_secret', self::SEED);

        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push('<html><body><div class="notify notify--bad">Invalid email or password. Try again.</div>'
                    .'<form action="" method="post"><input type="text" name="otp"><input type="submit" name="submit"></form>'
                    .'</body></html>'),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_CREDENTIALS_REJECTED, $result->reason);
        $this->assertSame(2, $result->requests);
        Http::assertSentCount(2);
    }

    public function test_a_refusal_notice_after_a_submitted_code_is_a_rejected_code(): void
    {
        // The second-factor leg has never been observed live, so a notice there
        // is not read into finer symbols: whatever it says, the code did not
        // get the operator in, and nothing is retried. Pinned with the captcha
        // notice, whose credential-leg symbol differs, so routing leg 3 through
        // the credential-leg reading would fail this.
        Setting::setEncrypted('hdb_totp_secret', self::SEED);

        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->challengePage())
                ->push($this->refusedLoginPage('Invalid Captcha')),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_TOTP_REJECTED, $result->reason);
        $this->assertNotSame(HdbAuthResult::REASON_FORM_GUARD_REFUSED, $result->reason);
        $this->assertSame(3, $result->requests);
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

    public function test_the_portals_measured_2fa_page_engages_the_existing_totp_path(): void
    {
        // POINT 3 of the ruling, as a measurement rather than an assumption: now
        // that browser semantics let the chain REACH the second-factor page, does
        // the path this client already had engage on the page the portal actually
        // serves? The field `otp` matches CHALLENGE_FIELD_PATTERN, the page has no
        // password input, so findChallengeForm drives it — and with no seed
        // stored the answer is the existing `totp_required_no_seed`, not a new
        // symbol and not `unexpected_response`.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->measuredTwoFactorPage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_TOTP_REQUIRED_NO_SEED, $result->reason);
        $this->assertSame(2, $result->requests);
        $this->assertNothingLeaked($result);
    }

    public function test_it_posts_a_code_to_the_portals_measured_2fa_page_and_invents_no_captcha_value(): void
    {
        // The other half of point 3, plus the FLAG the ruling asked to measure
        // and NOT to solve. The page carries `g-recaptcha-response` and
        // `totpskip` as hidden fields, so findChallengeForm forwards them at
        // their SERVED values — empty and `0`. That is the correct conservative
        // behaviour and it is pinned here precisely so nobody later "fixes" the
        // empty captcha by inventing a token or flips `totpskip` to skip the
        // second factor. Whether the portal ENFORCES the captcha on submit is
        // unmeasured and is a design question, not something this client guesses.
        Setting::setEncrypted('hdb_totp_secret', self::SEED);

        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->measuredTwoFactorPage())
                ->push($this->signedInPage()),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertTrue($result->ok());
        $this->assertSame(3, $result->requests);
        $this->assertNothingLeaked($result);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            if ($request->method() !== 'POST' || ! isset($data['otp'])) {
                return false;
            }

            return preg_match('/^\d{6}$/', (string) $data['otp']) === 1
                && array_key_exists('g-recaptcha-response', $data)
                && $data['g-recaptcha-response'] === ''   // served empty, forwarded empty
                && ($data['totpskip'] ?? null) === '0';   // served 0, never flipped
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
        // on it means the session did not take — and, with no refusal notice,
        // that the post was not evaluated. It is not a credential verdict.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push('<html><body><script>window.location = "login.php";</script></body></html>'),
        ]);

        $result = (new HdbAuthClient)->authenticate();

        $this->assertFalse($result->ok());
        $this->assertSame(HdbAuthStatus::Rejected, $result->status);
        $this->assertSame(HdbAuthResult::REASON_LOGIN_NOT_EVALUATED, $result->reason);
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
     * `Http::fake()` short-circuits before any hop is followed, so a faked 302
     * comes back as a body and `allow_redirects` is never exercised — which is
     * why three cycles of work on this guard shipped with no coverage at all.
     *
     * The MECHANISM, corrected 2026-09-17 after this docblock asserted the
     * opposite: the stub does not sit outside RedirectMiddleware. Laravel pushes
     * it LAST in PendingRequest::pushHandlers(), which makes it the INNERMOST
     * handler, inside the middleware. The middleware runs — it is what merges
     * its own defaults into the options, which is why `track_redirects` appears
     * in the capture below — but the stub answers with a plain response that is
     * never re-dispatched, so no hop is taken. Same observable effect, and the
     * false version of this sentence was worth correcting on a branch whose
     * subject is comments that argue the opposite of the code.
     *
     * Measured on `67348bf8` and NO LONGER TRUE, kept because it is why this
     * seam exists: deleting `'protocols' => ['https']` AND the whole
     * `on_redirect` closure left all 24 tests in this file, and all 93 HDB
     * tests, green. That same mutant now fails 5 tests (measured 2026-09-17),
     * because the tests below drive the closure through this seam. History, not
     * a standing claim about the current suite.
     *
     * The seam that does work is the options array the fake callback is handed:
     * the closure the client installed can be taken out of it and called with
     * the hop URI Guzzle would have passed.
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
        $this->assertSame(8, $allow['max'] ?? null);
        $this->assertIsCallable($allow['on_redirect'] ?? null);
    }

    public function test_the_redirect_policy_follows_browser_semantics_rather_than_re_posting(): void
    {
        // The EFFECTIVE policy, and the honest limit of this assertion.
        //
        // Deleting `'strict' => false` from the client is an EQUIVALENT mutant,
        // not an escape — and the mechanism matters, because an earlier draft of
        // this comment named the wrong one. It is NOT Laravel: PendingRequest
        // merges only its own defaults and never sets `allow_redirects`, and
        // Guzzle's Client::prepareDefaults is a shallow `$options + $defaults`,
        // so a request-level array would suppress a config-level one entirely.
        // The injection is RedirectMiddleware::__invoke doing
        // `$options['allow_redirects'] += self::$defaultSettings`, whose
        // defaults already carry `'strict' => false`. That is also why
        // `track_redirects` — a key nothing in this client or in Laravel ever
        // sets — appears in the capture at all. So
        // this seam cannot distinguish "stated false" from "defaulted false",
        // and asserting the key's PRESENCE would be theatre: it passes on the
        // mutant too. What it CAN settle is the one that matters, the regression
        // back to `strict => true`, which fails here and in the behavioural
        // guard below. The line stays in the client to make the decision legible
        // in source, and that is a readability argument, not a tested one — said
        // plainly rather than dressed up as coverage.
        $this->assertFalse($this->capturedRedirectOptions()['strict'] ?? null);
    }

    /**
     * The portal's measured sign-in chain, replayed through a REAL Guzzle stack.
     *
     * `Http::fake()` cannot settle any of this: its stub short-circuits before
     * any hop is followed, so a faked 302 comes back as a body and the redirect
     * policy is never exercised. (The stub is INNERMOST, not outside the
     * middleware — see {@see capturedRedirectOptions} for the mechanism and for
     * the correction of the opposite claim.) Only a real stack over a
     * MockHandler, handed the client's own captured options, runs the middleware
     * that decides method and body per hop.
     *
     * The responses are the ones measured 2026-09-17 21:58Z against the portal:
     * an accepted credential post 302s to `/home.php`, which 307s to `/home`,
     * which 302s to `/2fa_auth.php`, which 307s to `/2fa_auth`.
     *
     * @return list<\Psr\Http\Message\RequestInterface> every request the stack issued, in order
     */
    private function replayMeasuredSignInChain(): array
    {
        $stack = HandlerStack::create(new MockHandler([
            new GuzzleResponse(302, ['Location' => self::BASE.'/home.php']),
            new GuzzleResponse(307, ['Location' => self::BASE.'/home']),
            new GuzzleResponse(302, ['Location' => self::BASE.'/2fa_auth.php']),
            new GuzzleResponse(307, ['Location' => self::BASE.'/2fa_auth']),
            new GuzzleResponse(200, [], $this->measuredTwoFactorPage()),
        ]));

        $issued = [];
        $stack->push(Middleware::history($issued));

        $response = (new GuzzleClient(['handler' => $stack, 'http_errors' => false]))
            ->post(self::BASE.'/login', [
                'allow_redirects' => $this->capturedRedirectOptions(),
                'form_params' => ['email' => 'reports@example.test', 'password' => 'service-account-password'],
            ]);

        $this->assertSame(200, $response->getStatusCode(), 'The measured chain did not reach the second-factor page.');

        return array_map(fn (array $entry) => $entry['request'], $issued);
    }

    public function test_the_credential_body_is_not_re_posted_across_the_portals_own_redirects(): void
    {
        // THE DEFECT, as a behavioural guard. Under the shipped `strict => true`
        // every hop below was re-issued as a POST carrying the login body, so the
        // vendor's second-factor page — which never expects a POST — answered 500
        // "Fatal error.", and this client failed closed on it and reported
        // `unexpected_response`: its own crash, four hops after a sign-in the
        // portal had ACCEPTED. Browser semantics are the fix, and the shape of
        // them is per status: a 3xx up to 302, and 303, become a bodyless GET,
        // while every higher 3xx (307/308) keeps the method and body by
        // definition of those codes.
        $issued = $this->replayMeasuredSignInChain();

        $shape = array_map(
            fn ($request) => $request->getMethod().' '.$request->getUri()->getPath(),
            $issued,
        );

        $this->assertSame([
            'POST /login',      // the credentials
            'GET /home.php',    // 302 -> a browser GETs it, body dropped
            'GET /home',        // 307 preserves the method, and the method is now GET
            'GET /2fa_auth.php',
            'GET /2fa_auth',    // the page that used to be POSTed and 500
        ], $shape);

        // Assert the credential-specific property FIRST and the stronger
        // emptiness property second. PHPUnit aborts a method on its first
        // failure, so ordering these the other way round — as this guard first
        // did — left the password checks running only against a string already
        // proven empty, where they can never fail. This way, a hop that ever
        // carries a legitimate non-credential body fails on emptiness while the
        // password check stays live rather than being deleted alongside it.
        foreach (array_slice($issued, 1) as $hop) {
            $body = (string) $hop->getBody();

            $this->assertStringNotContainsString('service-account-password', $body, 'A redirect hop carried the stored password.');
            $this->assertStringNotContainsString('password', $body, 'A redirect hop carried a password field.');
            $this->assertSame('', $body, 'A redirect hop re-sent a request body.');
        }
    }

    public function test_a_307_answering_the_credential_post_still_carries_the_body_and_is_bounded_only_by_the_origin_pin(): void
    {
        // THE LIMIT OF THIS FIX, pinned as behaviour so it cannot be forgotten.
        // `strict => false` converts a 3xx up to 302, and 303, into a GET — it
        // does NOT and cannot stop anything above that (307, 308, and any future
        // code in that range), which preserve method and body by definition. The measured chain happens to open with a 302, so
        // every later hop is already a GET; a portal that answered the credential
        // POST with a 307 instead would re-POST the password onward exactly as
        // before. The ONLY thing bounding that is the origin pin, and the pin
        // bounds the host, and bounds the path only to a PREFIX of the
        // configured base — `isOnPortalOrigin` accepts the base or anything
        // under `base/`, so any path under the origin qualifies. Said exactly,
        // because "the pin bounds the HOST, not the path" (an earlier draft)
        // reads as though the path were unchecked, and the two statements
        // differ for a base URL that carries a path of its own.
        //
        // This is not a defect introduced here and it is not something this
        // client can fix by a flag — it is the residual the docblocks claim, and
        // this test is what makes the claim checkable.
        //
        // WHAT THIS TEST IS NOT: it is not a requirement that the password be
        // re-sent. It characterises Guzzle's 307 rule as it stands today. If the
        // on_redirect callback is ever hardened to bound an ON-ORIGIN 307
        // carrying a credential body — which it can be, since the callback
        // already receives the request and response and currently reads only the
        // URI — then this test SHOULD go red, and the right response is to
        // rewrite it around the new bound, not to weaken the bound. Said here so
        // a red line in this file never argues for keeping fail-open behaviour.
        $stack = HandlerStack::create(new MockHandler([
            new GuzzleResponse(307, ['Location' => self::BASE.'/2fa_auth']),
            new GuzzleResponse(200, [], $this->measuredTwoFactorPage()),
        ]));

        $issued = [];
        $stack->push(Middleware::history($issued));

        (new GuzzleClient(['handler' => $stack, 'http_errors' => false]))
            ->post(self::BASE.'/login', [
                'allow_redirects' => $this->capturedRedirectOptions(),
                'form_params' => ['email' => 'reports@example.test', 'password' => 'service-account-password'],
            ]);

        $hop = $issued[1]['request'];

        $this->assertSame('POST', $hop->getMethod(), 'A 307 no longer preserves the method: either the dependency changed or the client now bounds this hop. Re-read the comment above before changing this test.');
        $this->assertStringContainsString('service-account-password', (string) $hop->getBody());

        // And the guard that DOES bound it: same body, off-origin destination,
        // refused before it is followed.
        $this->expectException(HdbRedirectRefusedException::class);

        $this->capturedRedirectOptions()['on_redirect'](null, null, new Uri('https://attacker.example/2fa_auth'));
    }

    public function test_the_redirect_cap_clears_the_portals_measured_sign_in_chain(): void
    {
        // The chain above spends 4 hops. The cap was 5 — one hop of headroom on a
        // portal that routes in `.php`/extension-less PAIRS, so one more pair
        // anywhere would have reported `request_budget_exhausted` for a sign-in
        // that was working. This pins that the measured chain clears the cap with
        // room, rather than pinning the integer twice.
        $hops = count($this->replayMeasuredSignInChain()) - 1;

        $this->assertSame(4, $hops, 'The measured sign-in chain is not 4 hops any more.');
        $this->assertGreaterThanOrEqual($hops + 2, $this->capturedRedirectOptions()['max']);
    }

    public function test_the_origin_pin_still_refuses_an_off_origin_hop_with_browser_semantics(): void
    {
        // The password guard, and the thing `strict` was wrongly credited with.
        // It must hold with `strict => false` too: a 307/308 re-sends the body
        // verbatim whatever `strict` says, and a followed GET still carries the
        // session cookie.
        $onRedirect = $this->capturedRedirectOptions()['on_redirect'];

        $this->expectException(HdbRedirectRefusedException::class);

        $onRedirect(null, null, new Uri('https://attacker.example/2fa_auth'));
    }

    public function test_the_redirect_guard_refuses_a_hop_to_another_host(): void
    {
        $onRedirect = $this->capturedRedirectOptions()['on_redirect'];

        // An off-origin hop is refused BEFORE it is followed: a 307/308 would
        // re-send the credential body verbatim, and even a bodyless GET carries
        // the session cookie.
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
        // Http::fake() cannot settle this one: its stub short-circuits before a
        // hop is followed, so a faked 302 comes back as a body and the redirect
        // policy never runs. (Innermost, not outside the middleware — mechanism
        // in capturedRedirectOptions().) A real Guzzle stack over a MockHandler, fed the
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
        //
        // The queue is sized FROM the client's own cap rather than hard-coded.
        // It used to hold 8 responses against a cap of 5; raising the cap to 8
        // exhausted the mock first and this test failed with "Mock queue is
        // empty" — a MockHandler artefact reported as if Guzzle had stopped
        // enforcing the cap. Deriving the count means the next cap change cannot
        // silently turn this measurement into noise.
        //
        // The COST of deriving it: a self-sizing queue would keep passing for
        // max => 50 or max => 1000, so on its own it can no longer notice an
        // over-permissive cap. The bound below is what restores that, and 12 is
        // deliberately LOOSER than the constant's own arithmetic (4 measured
        // hops plus two routing pairs = 8): this test catches a cap that has
        // escaped its rationale entirely, while the rationale itself is argued
        // in MAX_REDIRECTS's docblock and pinned exactly by
        // test_every_request_carries_the_redirect_guard. Two ceilings, stated
        // rather than blurred.
        //
        // No guard against an ABSENT `max`: it cannot be absent. The capture is
        // taken inside RedirectMiddleware, which has already merged its own
        // defaults (`max => 5`) into the array — so an assertion on its presence
        // would be failure-unreachable. An earlier draft shipped exactly that
        // assertion with a message describing an impossible state; this comment
        // is what replaced it.
        $max = $this->capturedRedirectOptions()['max'];

        $this->assertLessThanOrEqual(12, $max, 'The redirect cap has grown past anything this portal\'s measured 4-hop chain justifies.');

        $overCap = $max + 2;

        $client = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler(array_map(
                fn () => new GuzzleResponse(302, ['Location' => self::BASE.'/login']),
                range(1, $overCap),
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
