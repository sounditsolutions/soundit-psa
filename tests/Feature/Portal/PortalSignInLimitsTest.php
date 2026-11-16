<?php

namespace Tests\Feature\Portal;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The portal's guest routes used to sit under one throttle:6,1, page views
 * included, so opening the sign-in page and submitting it cost two of six
 * tries. routes/portal.php now gives them three separate limiters:
 *  - page views of /login, /forgot-password, /request-access and the reset
 *    form: 60 a minute per address (portal-page);
 *  - POST /login: 5 a minute per email and address, plus 20 a minute per
 *    address alone (portal-login);
 *  - the other POSTs and GET /verify-access/{person}: 6 a minute per address
 *    (portal-guest).
 *
 * Each limit is checked on its own. Some tests run one limiter out and then
 * show another still answers: sign-in tries against page views and the other
 * forms, and the other POSTs against page views. Others spend only part of
 * one allowance, enough that a counter shared with it would refuse, and show
 * the other still answers: page views against sign-in tries and against a
 * password-reset request, and a plain throttled route against all three.
 *
 * Requests come straight from addresses in the 192.0.2.0/24 documentation
 * block (no trusted proxy); a test that needs a second allowance switches
 * address. Emails use example.test.
 */
class PortalSignInLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = '192.0.2.30';

    private const OTHER_ADDRESS = '192.0.2.31';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Setting::setValue('portal_enabled', '1');
        $this->withServerVariables(['REMOTE_ADDR' => self::ADDRESS]);
    }

    private function fromAddress(string $address): static
    {
        $this->withServerVariables(['REMOTE_ADDR' => $address]);

        return $this;
    }

    private function signIn(string $email): TestResponse
    {
        return $this->post('/portal/login', ['email' => $email, 'password' => 'not-a-real-passphrase']);
    }

    public function test_page_views_do_not_use_up_sign_in_tries(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->get('/portal/login')->assertOk();
        }

        // Five failed tries for one email still answer with the form's error,
        // not 429: the 30 page views above counted against none of them.
        for ($i = 1; $i <= 5; $i++) {
            $this->signIn('someone@example.test')->assertRedirect()->assertSessionHasErrors('email');
        }
        $this->signIn('someone@example.test')->assertStatus(429);
    }

    /**
     * The four form pages share one portal-page counter per address. Each
     * page is checked here from its own address, so this test shows the
     * limit of 60, not that the pages share it.
     */
    public function test_the_page_limit_is_60_views_a_minute_per_address(): void
    {
        foreach (['/portal/login', '/portal/forgot-password', '/portal/request-access', '/portal/reset-password/sometoken'] as $i => $path) {
            $this->fromAddress('192.0.2.'.(40 + $i));
            for ($n = 1; $n <= 60; $n++) {
                $this->get($path)->assertOk();
            }
            $this->get($path)->assertStatus(429);
        }
    }

    public function test_sign_in_allows_five_tries_a_minute_per_email_and_address(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->signIn('a@example.test')->assertRedirect();
        }
        $this->signIn('a@example.test')->assertStatus(429);

        // Same address, another email: a colleague in the same office can still sign in.
        $this->signIn('b@example.test')->assertRedirect();

        // Same email, another address: the per-email limit is per address too.
        $this->fromAddress(self::OTHER_ADDRESS)->signIn('a@example.test')->assertRedirect();
    }

    public function test_the_per_email_limit_ignores_case_and_surrounding_spaces(): void
    {
        foreach (['a@example.test', 'A@Example.TEST', ' a@example.test', 'a@EXAMPLE.test', 'A@example.test'] as $email) {
            $this->signIn($email)->assertRedirect();
        }
        $this->signIn('a@example.test ')->assertStatus(429);
    }

    /**
     * The typed email is folded: accented and plain spellings share one 5.
     * Without the fold, lower-casing alone would join only the spellings that
     * differ by case; the others would each count apart. (The test database
     * matches emails byte for byte, so this shows the key only, not what the
     * production collation matches.)
     */
    public function test_the_per_email_limit_folds_accented_letters(): void
    {
        foreach (['café@example.test', 'cafe@example.test', 'CAFÉ@example.test', 'cäfé@example.test', 'cafe@exámple.test'] as $email) {
            $this->signIn($email)->assertRedirect();
        }
        $this->signIn('cafe@example.test')->assertStatus(429);

        // Another email from the same address is still counted on its own.
        $this->signIn('other@example.test')->assertRedirect();
    }

    /**
     * An email that is not a string (here an array) is keyed as an empty
     * email. The form refuses it with a validation error, not a server
     * error, and it gets 5 tries a minute per address like any other email.
     */
    public function test_a_non_string_email_gets_the_same_five_tries(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/portal/login', ['email' => ['a@example.test'], 'password' => 'x'])
                ->assertRedirect()->assertSessionHasErrors('email');
        }
        $this->post('/portal/login', ['email' => ['a@example.test'], 'password' => 'x'])->assertStatus(429);

        $this->signIn('a@example.test')->assertRedirect();
    }

    /**
     * A full-width letter, and an accented letter that has no plain spelling
     * of its own until its accent is split off, both fold to the plain letter,
     * so they share the plain spelling's 5.
     */
    public function test_the_per_email_limit_folds_full_width_and_decomposable_letters(): void
    {
        foreach (["\u{FF43}afe@example.test", 'cafe@example.test', "\u{1E31}iosk@example.test", 'kiosk@example.test'] as $email) {
            $this->signIn($email)->assertRedirect();
        }
        $this->signIn('cafe@example.test')->assertRedirect();
        $this->signIn('cafe@example.test')->assertRedirect();
        $this->signIn("\u{FF43}afe@example.test")->assertRedirect();
        $this->signIn('cafe@example.test')->assertStatus(429);

        $this->signIn('kiosk@example.test')->assertRedirect();
        $this->signIn('kiosk@example.test')->assertRedirect();
        $this->signIn("\u{1E31}iosk@example.test")->assertRedirect();
        $this->signIn('kiosk@example.test')->assertStatus(429);
    }

    /**
     * A letter with no plain spelling is dropped from the key, so a spelling
     * with such a letter added shares the plain spelling's 5.
     */
    public function test_a_letter_with_no_plain_spelling_is_dropped(): void
    {
        foreach (["al\u{5F20}pha@example.test", 'alpha@example.test', "al\u{5F20}pha@example.test", 'alpha@example.test'] as $email) {
            $this->signIn($email)->assertRedirect();
        }
        $this->signIn("al\u{5F20}pha@example.test")->assertRedirect();
        $this->signIn('alpha@example.test')->assertStatus(429);
    }

    /**
     * A letter that does not decompose but has a plain spelling (l with a
     * stroke) folds to that spelling, so it shares the plain spelling's 5.
     */
    public function test_a_letter_with_a_plain_spelling_and_no_decomposition_folds_to_it(): void
    {
        foreach (["\u{0142}edger@example.test", 'ledger@example.test', "\u{0141}edger@example.test", 'ledger@example.test'] as $email) {
            $this->signIn($email)->assertRedirect();
        }
        $this->signIn("\u{0142}edger@example.test")->assertRedirect();
        $this->signIn('ledger@example.test')->assertStatus(429);
    }

    /**
     * The email is lower-cased before the fold as well as after it, because
     * the plain-letter step can spell a capital and its small letter
     * differently (Greek capital gamma as "g", small gamma as "gh"). Both
     * cases of the letter share one 5.
     */
    public function test_a_capital_is_lower_cased_before_the_fold(): void
    {
        foreach (["al\u{0393}pha@example.test", "al\u{03B3}pha@example.test", "al\u{0393}pha@example.test", "al\u{03B3}pha@example.test"] as $email) {
            $this->signIn($email)->assertRedirect();
        }
        $this->signIn("al\u{0393}pha@example.test")->assertRedirect();
        $this->signIn("al\u{03B3}pha@example.test")->assertStatus(429);
    }

    /**
     * A capital that only appears once the email is decomposed (a
     * double-struck H becomes H) is lower-cased again, so it shares the plain
     * spelling's 5.
     */
    public function test_a_capital_produced_by_the_fold_is_lower_cased(): void
    {
        foreach (["\u{210D}arbor@example.test", 'harbor@example.test', "\u{210D}arbor@example.test", 'harbor@example.test'] as $email) {
            $this->signIn($email)->assertRedirect();
        }
        $this->signIn("\u{210D}arbor@example.test")->assertRedirect();
        $this->signIn('harbor@example.test')->assertStatus(429);
    }

    /**
     * A combining mark is dropped from the key once the email is decomposed,
     * so a spelling with a mark added shares the plain spelling's 5. The mark
     * here (a nasal sign) is one the plain-letter step would otherwise spell
     * as letters, so only the mark step keeps it out of the key.
     */
    public function test_a_combining_mark_is_dropped_from_the_key(): void
    {
        foreach (["al\u{0981}pha@example.test", 'alpha@example.test', "al\u{0981}pha@example.test", 'alpha@example.test'] as $email) {
            $this->signIn($email)->assertRedirect();
        }
        $this->signIn("al\u{0981}pha@example.test")->assertRedirect();
        $this->signIn('alpha@example.test')->assertStatus(429);
    }

    /**
     * A character that is not a letter and has no plain spelling (a soft
     * hyphen) is dropped from the key, so it shares the plain spelling's 5.
     */
    public function test_a_non_letter_with_no_plain_spelling_is_dropped(): void
    {
        foreach (["al\u{00AD}pha@example.test", 'alpha@example.test', "al\u{00AD}pha@example.test", 'alpha@example.test'] as $email) {
            $this->signIn($email)->assertRedirect();
        }
        $this->signIn("al\u{00AD}pha@example.test")->assertRedirect();
        $this->signIn('alpha@example.test')->assertStatus(429);
    }

    /**
     * Only the first 255 characters of the email are keyed: emails that
     * share their first 255 characters and differ at the 256th share one 5,
     * and an email that differs at the 255th has a counter of its own.
     */
    public function test_a_very_long_email_is_keyed_on_its_first_255_characters(): void
    {
        $prefix = str_repeat('a', 255);
        for ($i = 1; $i <= 5; $i++) {
            $this->signIn($prefix.$i.'@example.test')->assertRedirect();
        }
        $this->signIn($prefix.'6@example.test')->assertStatus(429);

        $this->signIn(str_repeat('a', 254).'b@example.test')->assertRedirect();
    }

    public function test_sign_in_allows_twenty_tries_a_minute_per_address_across_emails(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->signIn("user{$i}@example.test")->assertRedirect();
        }
        $this->signIn('user21@example.test')->assertStatus(429);

        $this->fromAddress(self::OTHER_ADDRESS)->signIn('user21@example.test')->assertRedirect();
    }

    /**
     * A sign-in refused by the 20-a-minute address limit still counts
     * against that email's 5 (the stricter direction, kept on purpose). The
     * address limit fills at second 0 and the email's tries are refused at
     * second 30, so at second 60 the address limit has ended and the email's
     * 5 have not: that email is still refused, another email answers.
     */
    public function test_a_sign_in_refused_by_the_address_limit_still_counts_against_the_email(): void
    {
        $this->freezeSecond();
        for ($i = 1; $i <= 20; $i++) {
            $this->signIn("user{$i}@example.test")->assertRedirect();
        }

        $this->travel(30)->seconds();
        for ($i = 1; $i <= 5; $i++) {
            $this->signIn('a@example.test')->assertStatus(429);
        }

        $this->travel(30)->seconds();
        $this->signIn('b@example.test')->assertRedirect();
        $this->signIn('a@example.test')->assertStatus(429);

        $this->travel(30)->seconds();
        $this->signIn('a@example.test')->assertRedirect();
    }

    public function test_used_up_sign_in_tries_do_not_block_the_page_or_the_other_forms(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->signIn("user{$i}@example.test");
        }
        $this->signIn('user21@example.test')->assertStatus(429);

        $this->get('/portal/login')->assertOk();
        $this->post('/portal/forgot-password', ['email' => 'user1@example.test'])->assertRedirect();
    }

    public function test_the_other_posts_stay_at_six_a_minute_per_address(): void
    {
        $posts = [
            ['/portal/forgot-password', ['email' => 'x@example.test']],
            ['/portal/request-access', ['email' => 'x@example.test']],
            ['/portal/reset-password', ['token' => 't', 'email' => 'x@example.test', 'password' => 'p', 'password_confirmation' => 'p']],
        ];
        foreach ($posts as $i => [$path, $body]) {
            $this->fromAddress('192.0.2.'.(50 + $i));
            for ($n = 1; $n <= 6; $n++) {
                $this->post($path, $body)->assertRedirect();
            }
            $this->post($path, $body)->assertStatus(429);

            // Page views are on their own limiter.
            $this->get('/portal/login')->assertOk();
        }
    }

    /**
     * If the pages shared portal-guest's 6-a-minute counter, these twelve page
     * views would make the password-reset request below answer 429.
     */
    public function test_page_views_do_not_use_up_the_other_forms_tries(): void
    {
        foreach (['/portal/forgot-password', '/portal/request-access', '/portal/login'] as $path) {
            for ($n = 1; $n <= 4; $n++) {
                $this->get($path)->assertOk();
            }
        }

        $this->post('/portal/forgot-password', ['email' => 'x@example.test'])->assertRedirect();
    }

    public function test_the_verify_access_link_stays_at_six_a_minute_per_address(): void
    {
        $url = URL::temporarySignedRoute('portal.verify-access', now()->addHour(), ['person' => 999999]);

        for ($n = 1; $n <= 6; $n++) {
            $this->get($url)->assertRedirect(route('portal.login'));
        }
        $this->get($url)->assertStatus(429);
    }

    /** No new wording: the 429 is the framework's existing Too Many Requests page. */
    public function test_a_refused_sign_in_shows_the_existing_too_many_requests_page(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->signIn('a@example.test');
        }

        $this->signIn('a@example.test')->assertStatus(429)->assertSee('Too Many Requests');
    }

    /**
     * A plain throttle:N,M keys a signed-out caller by address alone, so every
     * such route shares one counter per address. Requests to another throttled
     * route (here a test route under throttle:120,1, as the API routes are)
     * must not use up the portal's sign-in allowance.
     */
    public function test_requests_to_another_throttled_route_do_not_use_up_the_portal_allowance(): void
    {
        Route::middleware('throttle:120,1')->get('/_limits-test/other', fn () => 'ok');

        // 60 is the page limit: a page limiter that shared this counter
        // would refuse the first page view below.
        for ($n = 1; $n <= 60; $n++) {
            $this->get('/_limits-test/other')->assertOk();
        }

        $this->get('/portal/login')->assertOk();
        $this->get('/portal/forgot-password')->assertOk();
        $this->get('/portal/request-access')->assertOk();
        $this->signIn('someone@example.test')->assertRedirect()->assertSessionHasErrors('email');
        $this->post('/portal/forgot-password', ['email' => 'x@example.test'])->assertRedirect();
        $this->post('/portal/request-access', ['email' => 'x@example.test'])->assertRedirect();
        $this->get(URL::temporarySignedRoute('portal.verify-access', now()->addHour(), ['person' => 999999]))
            ->assertRedirect(route('portal.login'));
    }

    /**
     * Four page views on each of four pages, then 44 more on one: the four
     * pages share one 60, so a counter per path would let the 61st through.
     */
    public function test_the_four_pages_share_one_60(): void
    {
        $pages = ['/portal/login', '/portal/forgot-password', '/portal/request-access', '/portal/reset-password/sometoken'];
        foreach ($pages as $path) {
            for ($n = 1; $n <= 4; $n++) {
                $this->get($path)->assertOk();
            }
        }
        for ($n = 1; $n <= 44; $n++) {
            $this->get('/portal/login')->assertOk();
        }
        $this->get('/portal/forgot-password')->assertStatus(429);
    }

    /** The other POSTs and the verify-access link share one 6: a counter per path would let the 7th through. */
    public function test_the_other_posts_and_the_verify_access_link_share_one_6(): void
    {
        $this->post('/portal/forgot-password', ['email' => 'x@example.test'])->assertRedirect();
        $this->post('/portal/forgot-password', ['email' => 'x@example.test'])->assertRedirect();
        $this->post('/portal/request-access', ['email' => 'x@example.test'])->assertRedirect();
        $this->post('/portal/request-access', ['email' => 'x@example.test'])->assertRedirect();
        $this->post('/portal/reset-password', ['token' => 't', 'email' => 'x@example.test', 'password' => 'p', 'password_confirmation' => 'p'])->assertRedirect();
        $this->get(URL::temporarySignedRoute('portal.verify-access', now()->addHour(), ['person' => 999999]))->assertRedirect(route('portal.login'));

        $this->post('/portal/forgot-password', ['email' => 'x@example.test'])->assertStatus(429);
    }

    /**
     * Each limit lasts a full minute from its first counted request: 59
     * seconds later it still refuses; at 60 seconds it answers again. Time is
     * frozen so every request counts from the same second.
     */
    public function test_each_limit_lasts_a_full_minute(): void
    {
        $this->freezeSecond();
        $this->fromAddress('192.0.2.60');
        for ($n = 1; $n <= 60; $n++) {
            $this->get('/portal/login');
        }
        $this->fromAddress('192.0.2.61');
        for ($n = 1; $n <= 6; $n++) {
            $this->post('/portal/forgot-password', ['email' => 'x@example.test']);
        }
        $this->fromAddress('192.0.2.62');
        for ($n = 1; $n <= 5; $n++) {
            $this->signIn('a@example.test');
        }
        $this->fromAddress('192.0.2.63');
        for ($n = 1; $n <= 20; $n++) {
            $this->signIn("user{$n}@example.test");
        }

        $this->travel(59)->seconds();
        $this->fromAddress('192.0.2.60')->get('/portal/login')->assertStatus(429);
        $this->fromAddress('192.0.2.61')->post('/portal/forgot-password', ['email' => 'x@example.test'])->assertStatus(429);
        $this->fromAddress('192.0.2.62')->signIn('a@example.test')->assertStatus(429);
        $this->fromAddress('192.0.2.63')->signIn('user21@example.test')->assertStatus(429);

        $this->travel(1)->seconds();
        $this->fromAddress('192.0.2.60')->get('/portal/login')->assertOk();
        $this->fromAddress('192.0.2.61')->post('/portal/forgot-password', ['email' => 'x@example.test'])->assertRedirect();
        $this->fromAddress('192.0.2.62')->signIn('a@example.test')->assertRedirect();
        $this->fromAddress('192.0.2.63')->signIn('user21@example.test')->assertRedirect();
    }

    /** With the portal switched off, every guest route answers 404: the four pages, the four POSTs and the verify-access link. */
    public function test_the_guest_routes_answer_404_when_the_portal_is_off(): void
    {
        Setting::setValue('portal_enabled', '0');

        $this->get('/portal/login')->assertNotFound();
        $this->get('/portal/forgot-password')->assertNotFound();
        $this->get('/portal/reset-password/sometoken')->assertNotFound();
        $this->get('/portal/request-access')->assertNotFound();
        $this->signIn('a@example.test')->assertNotFound();
        $this->post('/portal/forgot-password', ['email' => 'x@example.test'])->assertNotFound();
        $this->post('/portal/request-access', ['email' => 'x@example.test'])->assertNotFound();
        $this->post('/portal/reset-password', ['token' => 't', 'email' => 'x@example.test', 'password' => 'p', 'password_confirmation' => 'p'])->assertNotFound();
        $this->get(URL::temporarySignedRoute('portal.verify-access', now()->addHour(), ['person' => 999999]))->assertNotFound();
    }
}
