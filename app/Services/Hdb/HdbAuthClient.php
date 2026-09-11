<?php

namespace App\Services\Hdb;

use App\Support\HdbPortalConfig;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Support\Facades\Http;

/**
 * Authentication-only client for the HDB report portal.
 *
 * Scope is deliberately one thing: prove that the stored service-subaccount
 * credentials and two-factor seed still sign in. It fetches no report, reads no
 * press, writes nothing, and returns no text the portal produced — see
 * {@see HdbAuthResult}. The report reader is separate work (#1359) and builds on
 * this handshake rather than repeating it.
 *
 * MEASURED AT SOURCE 2026-09-11 against https://beta.helpdeskbuttons.com — the
 * login leg is not guessed:
 *
 * - `/` serves a 182-byte JS redirect to `login.php`; `login.php` 307s to `/login`.
 * - `/login` posts to ITSELF (`<form action="" method="post" id="theOnlyForm">`)
 *   with `email`, `password`, a submit named `submit`, and a hidden field `g`.
 * - **`g` is a bot trap.** The HTML ships `value='g'` (ASCII) and a DOMContentLoaded
 *   handler overwrites it with `ɡ` — U+0261 LATIN SMALL LETTER SCRIPT G. A client
 *   that posts the value it found in the HTML identifies itself as not having run
 *   the page's JavaScript. {@see FORM_GUARD_VALUE} posts the JS value.
 * - No captcha of any kind. The page's other script is a client-side
 *   pwnedpasswords k-anonymity check that runs before submit and gates nothing
 *   server-side, so it is not reproduced here: it would send a prefix of the
 *   SHA-1 of the operator's password to a third party on every connection test.
 *
 * 🔴 TWO HONEST LIMITS, because a reviewer should not have to find them:
 *
 * 1. **The second-factor leg is unverified against the live portal.** The
 *    challenge page is only reachable with real credentials, which this box
 *    does not hold, so the field is DISCOVERED from the returned form rather
 *    than hard-coded ({@see findChallengeForm}). A prompt this client cannot
 *    recognise reports REASON_TOTP_CHALLENGE_UNRECOGNISED — a distinct outcome
 *    from a refused code, so the first real run says which leg to fix.
 * 2. **Success is a NEGATIVE test.** With no observed authenticated page there
 *    is no positive marker to assert, so "authenticated" means the portal
 *    answered 2xx with a non-empty body carrying neither an unauthenticated
 *    marker nor a second-factor prompt. That is the weakest link in this class
 *    and the first thing to tighten once someone has seen a real signed-in page.
 */
final class HdbAuthClient
{
    /**
     * The value the login page's own JavaScript writes into the hidden `g`
     * field, replacing the ASCII `g` that ships in the HTML.
     */
    public const FORM_GUARD_VALUE = "\u{0261}";

    /**
     * Top-level requests one attempt may issue: GET the login page, POST the
     * credentials, POST the second factor. Guzzle-followed redirects sit inside
     * these and are separately capped by {@see MAX_REDIRECTS}.
     *
     * The happy path spends exactly this many, so the ceiling is a structural
     * invariant rather than a live limiter — it is here so that a future fourth
     * leg has to raise it deliberately. The budget reason it produces IS reached
     * in practice, by the redirect cap: {@see MAX_REDIRECTS}.
     *
     * There is no retry at any level. A refused credential re-tried is a lockout
     * risk on a service subaccount, and a transport failure tells us nothing a
     * second attempt would tell us differently inside one button press.
     */
    public const MAX_REQUESTS = 3;

    private const MAX_REDIRECTS = 5;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const TIMEOUT_SECONDS = 15;

    /**
     * Markers that prove a page is the login side of the portal. Structural, not
     * prose: a password input or the login form's own id.
     */
    private const LOGIN_FORM_MARKERS = [
        'id="theOnlyForm"',
        'name="password"',
        'type="password"',
    ];

    /**
     * The unauthenticated landing page's JS redirect. Weaker than the markers
     * above — a signed-in page may well link back to `login.php` — so it is only
     * consulted after the second-factor tests have had their say.
     */
    private const LOGGED_OUT_REDIRECT_MARKER = 'login.php';

    /**
     * Field names a second-factor prompt might use. Matched case-insensitively
     * as whole names or `_`/`-` separated parts, never as bare substrings —
     * `authenticity_token` must not read as a code field.
     */
    private const CHALLENGE_FIELD_PATTERN = '~^(?:[a-z0-9]+[_-])?(?:otp|totp|mfa|2fa|twofactor|otc|authcode|verification)(?:[_-][a-z0-9]+)?$~i';

    /**
     * Prose that says "a second factor was demanded" even when the field itself
     * is not one this client can drive. Without this list an unparsable
     * challenge would fall through to the success branch — the exact false pass
     * a fail-closed client must not produce.
     */
    private const SECOND_FACTOR_MARKERS = [
        'two-factor',
        'two factor',
        'authenticator',
        'authentication code',
        'verification code',
        'security code',
        'one-time',
        'one time code',
        '2fa',
    ];

    private int $requests = 0;

    /**
     * The URL the body most recently returned by {@see request()} was actually
     * served from — the URL asked for, or the last redirect hop Guzzle followed
     * to reach it. A form action of `""` means "post back to THIS page" per
     * HTML, and a relative one resolves against this page's directory, so the
     * challenge form is measured against this rather than against the URL the
     * leg started at.
     */
    private string $effectiveUrl = '';

    private CookieJar $cookies;

    public function __construct()
    {
        $this->cookies = new CookieJar;
    }

    /**
     * One login attempt. Never throws: every failure is a status.
     */
    public function authenticate(): HdbAuthResult
    {
        if (! HdbPortalConfig::isConfigured()) {
            return new HdbAuthResult(
                HdbAuthStatus::NotConfigured,
                HdbAuthResult::REASON_MISSING_CREDENTIALS,
                $this->requests,
            );
        }

        // WHERE the credential may go, checked before it is loaded. The Portal
        // URL setting is written from a form any authenticated user can reach
        // (psa #1344), so the admin-only gate on Test Connection does not decide
        // this; refusing here also covers resolveAction(), which measures a form
        // action against baseUrl() and so inherits whatever it names.
        $verdict = HdbPortalConfig::baseUrlVerdict();

        // A host that did not resolve is refused too — nothing is sent either
        // way — but it is NOT evidence the stored URL is wrong, and this check
        // runs on every attempt rather than only at save time. Reporting a
        // resolver outage as `portal_url_refused` would send an operator to edit
        // a Portal URL that has not changed and is not the problem.
        if ($verdict === HdbPortalConfig::BASE_URL_UNRESOLVED) {
            return new HdbAuthResult(
                HdbAuthStatus::Unreachable,
                HdbAuthResult::REASON_PORTAL_URL_UNRESOLVED,
                $this->requests,
            );
        }

        if ($verdict !== HdbPortalConfig::BASE_URL_POSTABLE) {
            return new HdbAuthResult(
                HdbAuthStatus::NotConfigured,
                HdbAuthResult::REASON_PORTAL_URL_REFUSED,
                $this->requests,
            );
        }

        $loginUrl = HdbPortalConfig::baseUrl().'/login';

        try {
            // Leg 1 — collect the session cookie the portal sets on the form.
            $page = $this->request('get', $loginUrl);
            if ($page instanceof HdbAuthResult) {
                return $page;
            }

            // Leg 2 — the credentials, with the JS-set guard value.
            $posted = $this->request('post', $loginUrl, [
                'email' => HdbPortalConfig::email(),
                'password' => (string) HdbPortalConfig::password(),
                'g' => self::FORM_GUARD_VALUE,
                'submit' => '',
            ]);
            if ($posted instanceof HdbAuthResult) {
                return $posted;
            }

            $body = (string) $posted;

            $challenge = $this->findChallengeForm($body, $this->effectiveUrl);
            if ($challenge !== null) {
                return $this->answerChallenge($challenge);
            }

            return $this->classify(
                $body,
                HdbAuthResult::REASON_CREDENTIALS_REJECTED,
                HdbAuthResult::REASON_TOTP_CHALLENGE_UNRECOGNISED,
            );
        } catch (HdbRedirectRefusedException) {
            // A hop that left the configured origin. Guzzle re-POSTs the
            // credential body on every redirect under `strict`, so the hop is
            // refused BEFORE it is followed and nothing reached that host.
            return $this->result(HdbAuthStatus::Unreachable, HdbAuthResult::REASON_REDIRECT_REFUSED);
        } catch (TooManyRedirectsException) {
            return $this->result(HdbAuthStatus::Unreachable, HdbAuthResult::REASON_REQUEST_BUDGET_EXHAUSTED);
        } catch (\Throwable) {
            // Deliberately discards the exception: a Guzzle message carries the
            // URL, and a redirected URL can carry a token.
            return $this->result(HdbAuthStatus::Unreachable, HdbAuthResult::REASON_TRANSPORT_ERROR);
        }
    }

    /**
     * Leg 3 — generate the code and post it back to the form that asked.
     *
     * @param  array{name: string, action: string, hidden: array<string, string>}  $challenge
     */
    private function answerChallenge(array $challenge): HdbAuthResult
    {
        if (! HdbPortalConfig::hasTotpSecret()) {
            return $this->result(HdbAuthStatus::Rejected, HdbAuthResult::REASON_TOTP_REQUIRED_NO_SEED);
        }

        $code = HdbPortalConfig::generateTotp();
        if ($code === null) {
            return $this->result(HdbAuthStatus::Rejected, HdbAuthResult::REASON_TOTP_SEED_UNUSABLE);
        }

        $answered = $this->request('post', $challenge['action'], array_merge(
            $challenge['hidden'],
            [$challenge['name'] => $code],
        ));
        if ($answered instanceof HdbAuthResult) {
            return $answered;
        }

        $body = (string) $answered;

        // Every remaining unhappy shape after a submitted code means the same
        // thing to the operator: the code did not get them in.
        return $this->classify(
            $body,
            HdbAuthResult::REASON_TOTP_REJECTED,
            HdbAuthResult::REASON_TOTP_REJECTED,
        );
    }

    /**
     * Decide what a returned page means, fail-closed.
     *
     * Ordered strongest evidence first: a password field is structural proof of
     * the login page, second-factor prose is proof the password leg SUCCEEDED,
     * and only a page carrying neither is read as signed in — the negative
     * success test declared on the class docblock.
     */
    private function classify(string $body, string $rejectedReason, string $secondFactorReason): HdbAuthResult
    {
        foreach (self::LOGIN_FORM_MARKERS as $marker) {
            if (stripos($body, $marker) !== false) {
                return $this->result(HdbAuthStatus::Rejected, $rejectedReason);
            }
        }

        foreach (self::SECOND_FACTOR_MARKERS as $marker) {
            if (stripos($body, $marker) !== false) {
                return $this->result(HdbAuthStatus::Rejected, $secondFactorReason);
            }
        }

        if (stripos($body, self::LOGGED_OUT_REDIRECT_MARKER) !== false) {
            return $this->result(HdbAuthStatus::Rejected, $rejectedReason);
        }

        if (trim($body) === '') {
            return $this->result(HdbAuthStatus::Unreachable, HdbAuthResult::REASON_UNEXPECTED_RESPONSE);
        }

        return $this->result(HdbAuthStatus::Authenticated, HdbAuthResult::REASON_OK);
    }

    /**
     * Issue one bounded request.
     *
     * @param  array<string, string>  $form
     * @return string|HdbAuthResult the response body, or a terminal result
     */
    private function request(string $method, string $url, array $form = []): string|HdbAuthResult
    {
        if ($this->requests >= self::MAX_REQUESTS) {
            return $this->result(HdbAuthStatus::Unreachable, HdbAuthResult::REASON_REQUEST_BUDGET_EXHAUSTED);
        }

        $this->requests++;

        $pending = Http::asForm()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS)
            ->withOptions([
                'cookies' => $this->cookies,
                // `strict` means a 301/302 on the credential POST is re-issued
                // AS a POST, body and all (307/308 always are), so EVERY hop is
                // another send of the decrypted password — and the first URL
                // passing the destination guard decides nothing about where hop
                // two goes. Guzzle's defaults allow `http` and check no host, so
                // both are pinned here: https only, and each hop measured
                // against the same origin the first one was. The configured
                // origin or nowhere.
                'allow_redirects' => [
                    'max' => self::MAX_REDIRECTS,
                    'strict' => true,
                    'referer' => true,
                    'protocols' => ['https'],
                    'on_redirect' => function ($request, $response, $uri) {
                        if (! $this->isOnPortalOrigin((string) $uri)) {
                            throw new HdbRedirectRefusedException;
                        }
                    },
                ],
                'http_errors' => false,
            ]);

        $response = $method === 'get' ? $pending->get($url) : $pending->post($url, $form);

        if (! $response->successful()) {
            // Fail closed: redirects are already followed, so anything that is
            // not 2xx here means we learned nothing about the credentials — and
            // "learned nothing" is never a pass.
            return $this->result(HdbAuthStatus::Unreachable, HdbAuthResult::REASON_UNEXPECTED_RESPONSE);
        }

        // The page a body came from is not always the URL we asked for: Guzzle
        // may have followed hops to get here, each one already measured against
        // the portal origin by the guard above. A form served on THIS page
        // resolves its action against THIS url — see {@see resolveAction}.
        $effective = (string) ($response->effectiveUri() ?? '');
        $this->effectiveUrl = $effective !== '' && $this->isOnPortalOrigin($effective) ? $effective : $url;

        return $response->body();
    }

    /**
     * Find a DRIVABLE second-factor prompt in a page, or null.
     *
     * A form qualifies when it carries a field named like a one-time code AND no
     * password field — the login form itself must never be mistaken for a
     * challenge, or a plain credential rejection would report as a 2FA problem.
     *
     * Null does not mean "no challenge", only "none this client can drive".
     * {@see classify} is what turns that into `totp_challenge_unrecognised`.
     *
     * @return array{name: string, action: string, hidden: array<string, string>}|null
     */
    private function findChallengeForm(string $body, string $pageUrl): ?array
    {
        if (! preg_match_all('~<form\b[^>]*>(.*?)</form>~is', $body, $forms, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($forms as $form) {
            $openTag = substr($form[0], 0, (int) strpos($form[0], '>') + 1);
            $inner = $form[1];

            preg_match_all('~<input\b[^>]*>~i', $inner, $inputs);
            $fields = $inputs[0] ?? [];

            $codeField = null;
            $hidden = [];
            $hasPassword = false;

            foreach ($fields as $input) {
                $name = $this->attr($input, 'name');
                $type = strtolower((string) $this->attr($input, 'type'));

                if ($type === 'password') {
                    $hasPassword = true;
                    break;
                }

                if ($name === null || $name === '') {
                    continue;
                }

                if ($type === 'hidden') {
                    $hidden[$name] = (string) $this->attr($input, 'value');

                    continue;
                }

                if ($codeField === null && preg_match(self::CHALLENGE_FIELD_PATTERN, $name)) {
                    $codeField = $name;
                }
            }

            if ($hasPassword || $codeField === null) {
                continue;
            }

            $action = $this->resolveAction($this->attr($openTag, 'action'), $pageUrl);
            if ($action === null) {
                // Cross-origin form action: refuse rather than post a live
                // one-time code to a host the operator never configured. The
                // caller reads this as an unrecognised challenge, which is the
                // correct operator-facing outcome — it is not a signed-in page.
                return null;
            }

            return ['name' => $codeField, 'action' => $action, 'hidden' => $hidden];
        }

        return null;
    }

    /**
     * Resolve a form action against the page it was served on.
     *
     * Returns null when the action leaves the configured portal origin. Empty or
     * missing action means "post back to this page", per HTML.
     */
    private function resolveAction(?string $action, string $pageUrl): ?string
    {
        $action = trim((string) $action);

        if ($action === '') {
            return $pageUrl;
        }

        if (str_starts_with($action, '//')) {
            return null;
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $action)) {
            return $this->isOnPortalOrigin($action) ? $action : null;
        }

        if (str_starts_with($action, '/')) {
            $resolved = HdbPortalConfig::baseUrl().'/'.ltrim($action, '/');
        } else {
            // Page-relative, per HTML: against the DIRECTORY of the page the
            // form was served on, not against the origin — a challenge served
            // at /auth/verify posts `verify2` to /auth/verify2.
            $directory = (string) preg_replace('~[^/]*$~', '', (string) preg_replace('~[?#].*$~', '', $pageUrl));
            $resolved = rtrim($directory, '/').'/'.$action;
        }

        // Whatever the shape, a live one-time code only ever goes to the
        // configured origin — including when a degenerate page URL produced
        // something that is no longer a URL at all.
        return $this->isOnPortalOrigin($resolved) ? $resolved : null;
    }

    /** One HTML attribute off a single tag, or null. */
    private function attr(string $tag, string $attribute): ?string
    {
        // NOT `\b`: a word boundary matches between `-` and a letter, so
        // `data-name=` satisfies `\bname=` and — preg_match taking the leftmost
        // hit — an earlier `data-*` attribute wins over the real one. That would
        // name the wrong field for the code, or read `data-type="password"` as a
        // password input and suppress the challenge entirely.
        $pattern = '~(?<![\w-])'.preg_quote($attribute, '~').'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i';

        if (! preg_match($pattern, $tag, $m)) {
            return null;
        }

        // Only the alternative that matched is populated, and a legitimately
        // empty `action=""` leaves group 1 set but blank — hence ?? throughout.
        $value = $m[1] ?? '';
        $value = $value !== '' ? $value : ($m[2] ?? '');
        $value = $value !== '' ? $value : ($m[3] ?? '');

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    }

    /**
     * Whether a URL is the configured portal origin, or somewhere under it.
     *
     * Applied to every redirect hop as well as to form actions: under `strict`
     * redirects each hop re-sends the credential body, so the first URL passing
     * {@see HdbPortalConfig::baseUrlVerdict()} is not enough on its own.
     *
     * Scheme and host are lowercased first, because a Location header may spell
     * either differently from the stored setting; the rest is a path-boundary
     * prefix test, so `portal.example.com.evil.test` cannot pass as
     * `portal.example.com`. Anything that is not absolute https, or that carries
     * inline credentials, is refused outright.
     */
    private function isOnPortalOrigin(string $url): bool
    {
        $target = $this->comparableUrl($url);
        $base = $this->comparableUrl(HdbPortalConfig::baseUrl());

        if ($target === null || $base === null) {
            return false;
        }

        return $target === $base
            || str_starts_with($target, $base.'/')
            || str_starts_with($target, $base.'?');
    }

    /** A URL reduced to a comparable form, or null when it is not absolute https. */
    private function comparableUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return 'https://'.$host
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .rtrim((string) ($parts['path'] ?? ''), '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function result(HdbAuthStatus $status, string $reason): HdbAuthResult
    {
        return new HdbAuthResult($status, $reason, $this->requests);
    }
}
