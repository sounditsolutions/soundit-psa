<?php

namespace App\Services\Hdb;

use App\Support\HdbPortalConfig;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\BadResponseException;
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
 * - **`submit` must be NON-EMPTY or the portal never evaluates the login.**
 *   Measured 2026-09-17 (made-up accounts, never the service credentials): a
 *   browser posts `submit=Submit`; posting `submit=` gets the blank login form
 *   back, byte-identical to a plain GET, for every credential pair — no error,
 *   no flash, no cookie change. Until this was measured, that silent re-serve
 *   was read as `credentials_rejected`. {@see FORM_SUBMIT_VALUE} posts the
 *   browser's value, and {@see classify} now refuses to call a re-serve a
 *   rejection (REASON_LOGIN_NOT_EVALUATED).
 * - **A refusal is a `notify--bad` block.** With a non-empty submit the portal
 *   answers a wrong pair with the login page plus a notice whose class carries
 *   `notify--bad` and whose text is `Invalid email or password`; a post whose
 *   `g` is the HTML's ASCII value gets `Invalid Captcha` in the same block. A
 *   third notice in that block — the account's IP-filter refusal, observed
 *   2026-09-17 against the real service subaccount — says the sign-in was
 *   refused on the caller's address, before the password was judged; it maps to
 *   REASON_PORTAL_IP_FILTERED ({@see REFUSAL_NOTICES}, whose order is its
 *   precedence). The portal interpolates the caller's address into that
 *   sentence, so neither the sentence nor the address is repeated outside the
 *   needle list, and neither leaves this class.
 *   Everything this client reads off a page — the notice text, the login-form
 *   and landing-redirect markers, the second-factor markers, the challenge
 *   form's action and field names and hidden values — is read only to pick a
 *   closed-vocabulary symbol or to address the next post; none of it leaves
 *   here.
 * - **`g` is a bot trap.** The HTML ships `value='g'` (ASCII) and a DOMContentLoaded
 *   handler overwrites it with `ɡ` — U+0261 LATIN SMALL LETTER SCRIPT G. A client
 *   that posts the value it found in the HTML identifies itself as not having run
 *   the page's JavaScript. {@see FORM_GUARD_VALUE} posts the JS value.
 * - No captcha of any kind. The page's other script is a client-side
 *   pwnedpasswords k-anonymity check that runs before submit and gates nothing
 *   server-side, so it is not reproduced here: it would send a prefix of the
 *   SHA-1 of the operator's password to a third party on every connection test.
 *
 * MEASURED 2026-09-17 against the production portal, once, by the operator who
 * holds the credentials — the sign-in chain this client must survive:
 *
 * - An ACCEPTED credential post answers 302 to `/home.php`, which 307s to
 *   `/home`, which 302s to `/2fa_auth.php`, which 307s to `/2fa_auth`. Four
 *   hops, all on the configured origin, and the portal serves the second-factor
 *   page at the end of them. {@see MAX_REDIRECTS} is sized from that chain.
 * - The second-factor page is titled "2FA Authentication" and carries fields
 *   `otp`, `g-recaptcha-response` and `totpskip`. `otp` matches
 *   {@see CHALLENGE_FIELD_PATTERN}, so {@see findChallengeForm} drives it.
 * - 🔴 **`g-recaptcha-response` is UNRESOLVED and deliberately not handled.**
 *   Whether the portal ENFORCES that captcha when the code is submitted has NOT
 *   been measured — the one control stopped at fetching the page. If it is
 *   enforced, automated second-factor sign-in is blocked outright and no code
 *   this client generates can pass; that is a design question for the portal
 *   owner, not a defect to work around here. Said precisely, because the loose
 *   version of this sentence was wrong: {@see findChallengeForm} forwards every
 *   hidden field at the value the portal SERVED, so the captcha field IS posted
 *   back — empty, exactly as served — and so is `totpskip`. What this class does
 *   not do is INVENT a value for either. Do not "fix" the empty captcha by
 *   supplying a token.
 *
 *   🔴 `totpskip` is forwarded at its served value, like every other
 *   hidden field: served `0`, this client posts `0`. Forwarding is not a
 *   neutral act, so the behaviour of this leg is tracked as a ticket-class
 *   residual rather than reasoned about here — the alternative (dropping a
 *   field the portal served) is its own unmeasured guess. Do not change the
 *   forwarding behaviour to "fix" this note, and do not invent a value.
 *
 * 🔴 TWO HONEST LIMITS, because a reviewer should not have to find them:
 *
 * 1. **The second-factor leg is unverified END TO END against the live portal.**
 *    The challenge PAGE is now measured (above), but no code has ever been
 *    posted back to it, so everything after the prompt — whether the captcha
 *    gates the submit, what a refused code looks like, what a signed-in page
 *    looks like — remains unobserved. The field is therefore still DISCOVERED
 *    from the returned form rather than hard-coded ({@see findChallengeForm}).
 *    A prompt this client cannot recognise reports
 *    REASON_TOTP_CHALLENGE_UNRECOGNISED — a distinct outcome from a refused
 *    code, so the first real run says which leg to fix.
 * 2. **Success is a NEGATIVE test.** With no observed authenticated page there
 *    is no positive marker to assert, so "authenticated" means the portal
 *    answered 2xx with a non-empty body carrying neither an unauthenticated
 *    marker, a refusal notice, nor a second-factor prompt. That is the weakest
 *    link in this class and the first thing to tighten once someone has seen a
 *    real signed-in page. REJECTION, by contrast, is now a positive test: the
 *    `notify--bad` notice above. An unauthenticated page without it is reported
 *    as REASON_LOGIN_NOT_EVALUATED, never as a credential verdict.
 */
final class HdbAuthClient
{
    /**
     * The value the login page's own JavaScript writes into the hidden `g`
     * field, replacing the ASCII `g` that ships in the HTML.
     */
    public const FORM_GUARD_VALUE = "\u{0261}";

    /**
     * The value a browser posts for the form's submit control. The portal
     * treats an EMPTY `submit` as "no submission" and re-serves the form without
     * evaluating the credentials (measured 2026-09-17), so this is not
     * decorative: it is the field that makes the post a login attempt at all.
     */
    public const FORM_SUBMIT_VALUE = 'Submit';

    /**
     * Top-level requests one attempt may issue: GET the login page, POST the
     * credentials, POST the second factor. Guzzle-followed redirects sit inside
     * these and are separately capped by {@see MAX_REDIRECTS}.
     *
     * The happy path spends exactly this many, so the ceiling is a structural
     * invariant rather than a live limiter — it is here so that a future fourth
     * leg has to raise it deliberately. The in-band check against it is NOT
     * reachable at three legs, and the earlier claim that it was is corrected
     * here. What IS reached is the budget REASON, produced by the redirect cap
     * ({@see MAX_REDIRECTS}) — and only through {@see transportReasonFor()},
     * because Guzzle's refusal arrives wrapped rather than as itself.
     *
     * There is no retry at any level. A refused credential re-tried is a lockout
     * risk on a service subaccount, and a transport failure tells us nothing a
     * second attempt would tell us differently inside one button press.
     */
    public const MAX_REQUESTS = 3;

    /**
     * Guzzle-followed redirect hops one top-level request may spend.
     *
     * RAISED 5 -> 8 on 2026-09-17, because the real sign-in chain was measured
     * and it is 4 hops against the old cap of 5 — one hop of headroom, which is
     * tighter than the number looks. The portal answers an accepted credential
     * post with `/home.php` (302), which 307s to `/home`, which 302s to
     * `/2fa_auth.php`, which 307s to `/2fa_auth`, which serves the second-factor
     * page. Each `.php` path pairs with an extension-less one, so the portal's
     * own routing spends hops two at a time: ONE more such pair anywhere in the
     * chain would have reported REASON_REQUEST_BUDGET_EXHAUSTED for a sign-in
     * that was working. 8 leaves room for two more pairs and still stops a loop.
     *
     * This cap is what a loop actually dies on ({@see transportReasonFor}); the
     * top-level {@see MAX_REQUESTS} counts legs, not hops, and never sees these.
     */
    private const MAX_REDIRECTS = 8;

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
     * The class token the portal puts on its refusal notice. Structural: the
     * element is found by this whole-class token on its `class` attribute,
     * and only its own visible text is then compared against the known
     * notices below. An element carrying the token but no text is a
     * placeholder, not a notice.
     */
    private const REFUSAL_NOTICE_CLASS = 'notify--bad';

    /**
     * The three refusal notices OBSERVED against the portal, each mapped to the
     * closed-vocabulary symbol it means. Matched case-insensitively as a
     * substring of a notice's visible text only. Any other notice text reports
     * REASON_LOGIN_REFUSED_UNRECOGNISED — fail-closed, and the text stays here.
     *
     * The captcha notice is mapped even though this client always posts the
     * JS guard value: it is the portal's word that the guard, not the
     * credentials, was judged — which is what to report if the guard's
     * expected value ever changes underneath us.
     *
     * The IP-filter notice was OBSERVED once, 2026-09-17, against the real
     * service subaccount: the portal answered the credential post with the
     * login page plus a `notify--bad` block reading "Your IP address is not on
     * the account IP Filter whitelist." Those exact bytes are pinned in
     * {@see \Tests\Feature\Integrations\HdbAuthClientTest}, which is the only
     * place they are reproduced.
     *
     * The needle is a FRAGMENT of that sentence, and the reason is a judgement,
     * not a measurement: the observed sentence carried no address, but a portal
     * that reports the caller's address in it is the likely shape, and an
     * address could not be a needle in any case. One observation cannot prove
     * the wording is stable, so this needle is a bet on its most structural
     * part. If the portal rewords around it the branch degrades to
     * REASON_LOGIN_REFUSED_UNRECOGNISED — fail-closed, exactly where it sat
     * before this entry existed, and silently. That is the known weakness here.
     *
     * ORDER IS PRECEDENCE, and it is deliberate: the loop below returns on the
     * first needle any notice matches, so the earliest entry wins on a page
     * showing several.
     *
     * 1. GUARD first, unchanged. It means the portal judged the hidden field
     *    this client posts — a change underneath us, and the only one of the
     *    three an operator cannot fix by editing a setting.
     * 2. IP FILTER second, ABOVE credentials, for the same reason the guard is
     *    above them: a perimeter refusal is a fact about WHERE the request came
     *    from. Reporting the credentials sentence for one would send an operator
     *    to re-enter a password that is probably fine — and invite the retry a
     *    service subaccount's lockout policy punishes.
     * 3. CREDENTIALS last, the only one of the three that IS a verdict on the
     *    stored pair.
     *
     * THE COST OF (2), because it is a real one and a reviewer should not have
     * to find it: on a page showing BOTH the IP-filter and the credentials
     * notice, the credentials verdict is discarded. It has not been observed
     * that the portal evaluates a password at all once the perimeter refuses,
     * so "the password was never judged" is an inference — which is why
     * REASON_PORTAL_IP_FILTERED's operator sentence does NOT exculpate the
     * stored credential and sends the operator back to it if whitelisting does
     * not resolve the sign-in. A page carrying several notices collapsing to one
     * symbol is the general shape of issue #2085, which predates this entry and
     * which this entry makes one needle wider.
     *
     * {@see \Tests\Feature\Integrations\HdbAuthClientTest} pins all three
     * boundaries of that order, not just the original guard-over-credentials
     * one.
     */
    private const REFUSAL_NOTICES = [
        'invalid captcha' => HdbAuthResult::REASON_FORM_GUARD_REFUSED,
        'ip filter whitelist' => HdbAuthResult::REASON_PORTAL_IP_FILTERED,
        'invalid email or password' => HdbAuthResult::REASON_CREDENTIALS_REJECTED,
    ];

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

    /**
     * @param  CookieJar|null  $cookies  the jar the handshake's session lands in
     *
     * The parameter is the ONLY change this slice makes to a class proven live
     * on 2026-09-18, and it is deliberately additive: every existing caller
     * constructs with no arguments and gets the private jar it always got.
     *
     * {@see HdbReportClient} passes its OWN jar so the report fetches ride the
     * session this handshake established, rather than logging in a second time
     * or — far worse — growing a second login path. Sharing a jar is sharing a
     * session: a caller that passes one is asking for exactly that, and it is
     * the caller's job not to hand the same jar to two different identities.
     * Nothing else about the handshake changes, and this class still writes
     * nothing outside the jar it was given.
     */
    public function __construct(?CookieJar $cookies = null)
    {
        $this->cookies = $cookies ?? new CookieJar;
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

        // WHERE the credential may go, checked before it is loaded. Writing the
        // Portal URL is admin-only, so this is no longer the only thing between a
        // non-admin and the credential — but it runs on EVERY attempt, which the
        // write gate cannot: a URL stored before that gate existed, or typed
        // wrong by an admin since, is still refused here. It also covers
        // resolveAction(), which measures a form action against baseUrl() and so
        // inherits whatever the setting names.
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

            // Leg 2 — the credentials, with the JS-set guard value and the
            // browser's submit value. Both are load-bearing: see the class
            // docblock for what the portal does when either is wrong.
            $posted = $this->request('post', $loginUrl, [
                'email' => HdbPortalConfig::email(),
                'password' => (string) HdbPortalConfig::password(),
                'g' => self::FORM_GUARD_VALUE,
                'submit' => self::FORM_SUBMIT_VALUE,
            ]);
            if ($posted instanceof HdbAuthResult) {
                return $posted;
            }

            $body = (string) $posted;

            // The portal's own refusal notice outranks everything else on the
            // page, INCLUDING a code-shaped form: a page that has just said the
            // credentials were refused is not a second-factor prompt, and a
            // live one-time code is never posted to it.
            $noticed = $this->refusalNoticeReason($body);
            if ($noticed !== null) {
                return $this->result(HdbAuthStatus::Rejected, $noticed);
            }

            $challenge = $this->findChallengeForm($body, $this->effectiveUrl);
            if ($challenge !== null) {
                return $this->answerChallenge($challenge);
            }

            // No notice, no drivable challenge: an unauthenticated page here is
            // the portal not having evaluated the post — not a credential verdict.
            return $this->classify(
                $body,
                HdbAuthResult::REASON_LOGIN_NOT_EVALUATED,
                HdbAuthResult::REASON_TOTP_CHALLENGE_UNRECOGNISED,
            );
        } catch (HdbRedirectRefusedException) {
            // A hop that left the configured origin, refused BEFORE it was
            // followed, so nothing reached that host. This is THE password
            // guard on the redirect chain: browser semantics drop the body on a
            // 300/301/302/303, but EVERY OTHER 3xx — 307 and 308, and anything
            // future — carries it verbatim, and the portal's own chain uses
            // both. So an off-origin hop can still be a send of the decrypted
            // credential, and is refused on its destination rather than on a
            // guess about what it would have carried.
            return $this->result(HdbAuthStatus::Unreachable, HdbAuthResult::REASON_REDIRECT_REFUSED);
        } catch (\Throwable $e) {
            // The exception NEVER reaches the operator: a Guzzle message carries
            // the URL, and a redirected URL can carry a token. Only its class and
            // its response status choose the symbol.
            return $this->result(HdbAuthStatus::Unreachable, $this->transportReasonFor($e));
        }
    }

    /**
     * Which closed-vocabulary symbol a thrown transport failure means.
     *
     * Laravel's HTTP client does not let Guzzle's transfer exceptions through.
     * PendingRequest::send() catches TransferException, and for one carrying a
     * response — which both refusals below do, a 3xx — it re-throws
     * Illuminate\Http\Client\ConnectionException with the Guzzle exception only
     * on getPrevious(), because Response::toException() is null for a 3xx. So
     * catching either of them by type on the try above was dead code, and a
     * portal that was reached and looping reported as a firewall problem.
     *
     * Both refusals are raised inside Guzzle's own RedirectMiddleware BEFORE
     * `on_redirect` is reached — guardMax() throws TooManyRedirectsException and
     * redirectUri() throws BadResponseException for a hop whose scheme is not in
     * `protocols` — so neither can ever arrive as an
     * {@see HdbRedirectRefusedException}, and each needs its own mapping here.
     *
     * Decided on class and response status ONLY. The vendor messages quote the
     * refused URL and are one dependency bump from changing, so nothing here
     * reads them and nothing off the exception reaches the returned symbol.
     */
    private function transportReasonFor(\Throwable $e): string
    {
        $cause = $e;

        while ($cause !== null) {
            if ($cause instanceof TooManyRedirectsException) {
                return HdbAuthResult::REASON_REQUEST_BUDGET_EXHAUSTED;
            }

            if ($cause instanceof BadResponseException && $this->isRefusedRedirect($cause)) {
                return HdbAuthResult::REASON_REDIRECT_REFUSED;
            }

            $cause = $cause->getPrevious();
        }

        return HdbAuthResult::REASON_TRANSPORT_ERROR;
    }

    /**
     * Whether a Guzzle exception is the redirect middleware refusing a hop
     * rather than an ordinary bad response.
     *
     * Structural, never textual: the refusal carries the 3xx that proposed the
     * hop, Location header and all. Nothing else in this client produces that
     * shape — `http_errors` is off, so a status code never raises on its own.
     */
    private function isRefusedRedirect(BadResponseException $e): bool
    {
        $response = $e->getResponse();
        $status = $response->getStatusCode();

        return $status >= 300 && $status < 400 && $response->hasHeader('Location');
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
        // thing to the operator: the code did not get them in. That includes a
        // refusal notice — this leg has never been observed live, so its notice
        // texts are not read into finer symbols.
        if ($this->refusalNoticeReason($body) !== null) {
            return $this->result(HdbAuthStatus::Rejected, HdbAuthResult::REASON_TOTP_REJECTED);
        }

        return $this->classify(
            $body,
            HdbAuthResult::REASON_TOTP_REJECTED,
            HdbAuthResult::REASON_TOTP_REJECTED,
        );
    }

    /**
     * Decide what a returned page means, fail-closed.
     *
     * Called only for a page that carries NO refusal notice — each leg reads
     * the notice first ({@see refusalNoticeReason}) and returns on it, because
     * the notice is the portal's own POSITIVE word that it evaluated the post
     * and said no. From there, strongest evidence first: a password field is
     * structural proof of the login page — which, without a notice, means the
     * post was NOT evaluated; second-factor prose is proof the password leg
     * SUCCEEDED; and only a page carrying none of these is read as signed in —
     * the negative success test declared on the class docblock. That last
     * branch is where a notice this client failed to find would land, which is
     * why the notice read is a real HTML parse and not a substring hunt.
     *
     * @param  string  $reServedReason  the symbol an unauthenticated page reports
     */
    private function classify(string $body, string $reServedReason, string $secondFactorReason): HdbAuthResult
    {
        foreach (self::LOGIN_FORM_MARKERS as $marker) {
            if (stripos($body, $marker) !== false) {
                return $this->result(HdbAuthStatus::Rejected, $reServedReason);
            }
        }

        foreach (self::SECOND_FACTOR_MARKERS as $marker) {
            if (stripos($body, $marker) !== false) {
                return $this->result(HdbAuthStatus::Rejected, $secondFactorReason);
            }
        }

        if (stripos($body, self::LOGGED_OUT_REDIRECT_MARKER) !== false) {
            return $this->result(HdbAuthStatus::Rejected, $reServedReason);
        }

        if (trim($body) === '') {
            return $this->result(HdbAuthStatus::Unreachable, HdbAuthResult::REASON_UNEXPECTED_RESPONSE);
        }

        return $this->result(HdbAuthStatus::Authenticated, HdbAuthResult::REASON_OK);
    }

    /**
     * Non-rendered containers. libxml's HTML parser is HTML4-era and gives
     * these no special content model, so a notice inside one would sit in the
     * tree like any other element while a browser shows nothing. Never walked
     * into, and never read as a notice themselves when one carries the class.
     */
    private const HIDDEN_CONTAINERS = ['script', 'style', 'template', 'noscript', 'head'];

    /**
     * The closed-vocabulary symbol for the portal's refusal notice on a page,
     * or null when the page carries no notice.
     *
     * Structural first, textual second, and the text is read ONLY inside the
     * notice element. The page is parsed as HTML by libxml (an HTML4-era
     * recovering parser — not a browser's tree builder, which is why the
     * containers a browser would not render are excluded by hand), every
     * rendered element whose `class` attribute carries
     * {@see REFUSAL_NOTICE_CLASS} as a whole class name is a notice, and its
     * own visible text — script and style content dropped, whitespace
     * collapsed — is compared against the measured notices. Words in a
     * comment, a script, another attribute, an attribute merely NAMED like
     * `class`, or a template/noscript block are not a notice. An empty notice
     * is a placeholder and reports nothing on its own.
     *
     * Every notice on the page is read, and {@see REFUSAL_NOTICES}'s order is
     * the precedence: the guard sentence outranks the IP-filter one, which
     * outranks the credentials one, because a page carrying more than one has
     * judged the earlier thing and that is the fact the operator needs — and
     * neither the guard nor the IP filter is a verdict on the stored password. A page whose notices are all
     * unfamiliar reports REASON_LOGIN_REFUSED_UNRECOGNISED — fail-closed, the
     * text discarded here. So does a page libxml could not parse, or a notice
     * whose text is not valid UTF-8: the portal said something this client
     * could not read, which is never "no notice".
     *
     * Nothing off the page reaches the returned symbol: the match selects a
     * constant, and the notice text is discarded here.
     */
    private function refusalNoticeReason(string $body): ?string
    {
        $document = $this->parseHtml($body);
        if ($document === null) {
            return HdbAuthResult::REASON_LOGIN_REFUSED_UNRECOGNISED;
        }

        // ancestor-or-SELF: an element inside a hidden container is not a
        // notice, and neither is a hidden container that carries the class
        // itself — a browser renders its own text no more than a child's.
        $hidden = implode(' or ', array_map(
            fn (string $tag) => "ancestor-or-self::{$tag}",
            self::HIDDEN_CONTAINERS,
        ));
        $notices = (new \DOMXPath($document))->query("//*[@class][not({$hidden})]");

        $texts = [];

        foreach ($notices ?: [] as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }

            // HTML splits a class attribute on ASCII whitespace only — space,
            // tab, LF, FF, CR — never on VT or Unicode spaces.
            $classes = preg_split('~[ \t\n\f\r]+~', trim($element->getAttribute('class'), " \t\n\f\r")) ?: [];
            if (! in_array(self::REFUSAL_NOTICE_CLASS, $classes, true)) {
                continue;
            }

            $text = $this->visibleText($element);
            if ($text === null) {
                return HdbAuthResult::REASON_LOGIN_REFUSED_UNRECOGNISED;
            }

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        if ($texts === []) {
            return null;
        }

        // Guard, then IP filter, then credentials: REFUSAL_NOTICES is ordered
        // that way and its docblock says why. First needle matched wins.
        foreach (self::REFUSAL_NOTICES as $needle => $reason) {
            foreach ($texts as $text) {
                if (mb_stripos($text, $needle) !== false) {
                    return $reason;
                }
            }
        }

        return HdbAuthResult::REASON_LOGIN_REFUSED_UNRECOGNISED;
    }

    /**
     * Parse a page as HTML, or null when libxml could not.
     *
     * Told to read the bytes as UTF-8 via a leading XML encoding declaration —
     * the one hint libxml's HTML parser honours ahead of a <meta charset>. It
     * is a recovering parser: malformed markup produces a partial tree, not a
     * failure, and only a total failure returns null. A refusal page whose
     * notice was lost to recovery therefore reads as "no notice" here and
     * lands on the structural markers in {@see classify}; that is the negative
     * success test's own documented weakness, not a new one.
     *
     * Entities are substituted by the parser, so `&amp;` and friends never
     * reach the comparison as markup. libxml's external entity loader is off
     * by default and neither LIBXML_DTDLOAD nor LIBXML_NOENT is set, so no
     * external DTD or entity is loaded from anywhere; LIBXML_NONET is belt
     * and braces on top of that, not the thing doing the work. Keep it so.
     *
     * The error buffer is process-global. Internal-error mode is restored
     * after the parse, and the buffer is cleared only when this call was the
     * one that switched it on — a caller already collecting its own
     * diagnostics keeps them (with this parse's appended, which it can see).
     */
    private function parseHtml(string $body): ?\DOMDocument
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$body,
                LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR,
            );
        } finally {
            if (! $previous) {
                libxml_clear_errors();
            }
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $document : null;
    }

    /**
     * The text a person would see inside an element: hidden containers
     * skipped, comments ignored, element boundaries treated as whitespace so
     * an inline tag never glues two words together, whitespace collapsed.
     *
     * Null when the text is not valid UTF-8 — the caller reports that as an
     * unrecognised notice rather than as a placeholder.
     */
    private function visibleText(\DOMNode $node): ?string
    {
        $parts = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $parts[] = $child->wholeText;
            } elseif ($child instanceof \DOMElement) {
                if (in_array(strtolower($child->tagName), self::HIDDEN_CONTAINERS, true)) {
                    continue;
                }

                $inner = $this->visibleText($child);
                if ($inner === null) {
                    return null;
                }

                $parts[] = $inner;
            }
        }

        $joined = implode(' ', $parts);
        if (! mb_check_encoding($joined, 'UTF-8')) {
            return null;
        }

        $collapsed = preg_replace('~\s+~u', ' ', $joined);

        return $collapsed === null ? null : trim($collapsed);
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
                // BROWSER SEMANTICS, and the correction of this client's worst
                // bug: `strict => false` makes Guzzle turn a 300/301/302/303 on
                // the credential POST into a GET, exactly as a browser does
                // (RedirectMiddleware downgrades on `303 || (<= 302 && !strict)`,
                // so the rule is every 3xx up to 302, plus 303 — and NOTHING
                // above it). Under
                // `strict => true` — what shipped until 2026-09-17 — the POST
                // method and body were re-issued at every hop, so the login body
                // was posted at `/home.php`, `/home`, `/2fa_auth.php` and
                // `/2fa_auth`, and the vendor's second-factor page answered 500
                // "Fatal error." on a POST it never expects. The client failed
                // closed on that and reported `unexpected_response` — its own
                // crash, four hops after a sign-in the portal had ACCEPTED.
                //
                // `strict` was justified in this file as a password protection.
                // It was the opposite: it re-sent the password to four further
                // endpoints. THE PASSWORD GUARD IS THE ORIGIN PIN BELOW —
                // measured on the real chain, every hop stayed on the configured
                // origin and nothing left it. That pin stays, and it still earns
                // its keep with `strict` off: a 307/308 preserves the body
                // whatever `strict` says, and this portal's chain is half 307s.
                //
                // Guzzle's defaults allow `http` and check no host, so both are
                // pinned: https only, and each hop measured against the same
                // origin the first one was. The configured origin or nowhere.
                'allow_redirects' => [
                    'max' => self::MAX_REDIRECTS,
                    'strict' => false,
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
        } elseif (str_starts_with($action, '#')) {
            // Fragment-only reference, per RFC 3986: the SAME page, path and
            // query intact. The fragment is never sent, so it is dropped rather
            // than resolved.
            $resolved = (string) preg_replace('~#.*$~', '', $pageUrl);
        } elseif (str_starts_with($action, '?')) {
            // Query-only reference, per RFC 3986: the page's own path is KEPT
            // and only the query is replaced. Falling into the path-relative
            // branch below would drop the last segment and post the live code to
            // the directory index — a working portal reported as a stale seed.
            $resolved = (string) preg_replace('~[?#].*$~', '', $pageUrl).$action;
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
     * Applied to every redirect hop as well as to form actions, and it is what
     * actually keeps the credential on the configured origin — the redirect
     * policy's `strict` flag never did that job and is off ({@see request}).
     * A 307/308 re-sends the body verbatim no matter what `strict` says, and a
     * followed GET still carries the session cookie, so the first URL passing
     * {@see HdbPortalConfig::baseUrlVerdict()} is not enough on its own: hop two
     * is measured as hop one was.
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

        // The scheme's DEFAULT port is normalised away rather than carried
        // through. The other side of every comparison is a PSR-7 `Uri` — the one
        // Guzzle hands `on_redirect`, or `effectiveUri()` — whose filterPort()
        // drops `:443` for https, while a stored Portal URL of
        // `https://host:443` passes the config guard untouched. Keeping the port
        // here would refuse every same-origin hop and absolute same-origin form
        // action on a setting this module itself calls postable.
        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':'.$parts['port'] : '';

        return 'https://'.$host
            .$port
            .rtrim((string) ($parts['path'] ?? ''), '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function result(HdbAuthStatus $status, string $reason): HdbAuthResult
    {
        return new HdbAuthResult($status, $reason, $this->requests);
    }
}
