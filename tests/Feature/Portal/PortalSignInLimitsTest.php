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
 * Each limit is checked on its own, and some tests use up one limiter and
 * then show another still answers: sign-in tries against page views and the
 * other forms, the other POSTs against page views, page views against a
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
     * Without the fold each spelling would get its own 5. (The test database
     * matches emails byte for byte, so this shows the key only, not what the
     * production collation matches.)
     */
    public function test_the_per_email_limit_folds_accented_letters(): void
    {
        foreach (['josé@example.test', 'jose@example.test', 'JOSÉ@example.test', 'jösé@example.test', 'jose@exámple.test'] as $email) {
            $this->signIn($email)->assertRedirect();
        }
        $this->signIn('jose@example.test')->assertStatus(429);

        // Another email from the same address is still counted on its own.
        $this->signIn('maria@example.test')->assertRedirect();
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
     * A full-width letter and a letter whose accent sits on a letter the
     * plain fold cannot map both fold to the plain letter, so they share the
     * plain spelling's 5.
     */
    public function test_the_per_email_limit_folds_full_width_and_decomposable_letters(): void
    {
        foreach (["\u{FF4A}ose@example.test", 'jose@example.test', "\u{1E31}ate@example.test", 'kate@example.test'] as $email) {
            $this->signIn($email)->assertRedirect();
        }
        $this->signIn('jose@example.test')->assertRedirect();
        $this->signIn('jose@example.test')->assertRedirect();
        $this->signIn("\u{FF4A}ose@example.test")->assertRedirect();
        $this->signIn('jose@example.test')->assertStatus(429);

        $this->signIn('kate@example.test')->assertRedirect();
        $this->signIn('kate@example.test')->assertRedirect();
        $this->signIn("\u{1E31}ate@example.test")->assertRedirect();
        $this->signIn('kate@example.test')->assertStatus(429);
    }

    /**
     * Only the first 255 characters of the email are keyed, so two long
     * emails that differ after that share one 5.
     */
    public function test_a_very_long_email_is_keyed_on_its_first_255_characters(): void
    {
        $prefix = str_repeat('a', 300);
        for ($i = 1; $i <= 5; $i++) {
            $this->signIn($prefix.$i.'@example.test')->assertRedirect();
        }
        $this->signIn($prefix.'6@example.test')->assertStatus(429);
    }

    public function test_sign_in_allows_twenty_tries_a_minute_per_address_across_emails(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->signIn("user{$i}@example.test")->assertRedirect();
        }
        $this->signIn('user21@example.test')->assertStatus(429);

        $this->fromAddress(self::OTHER_ADDRESS)->signIn('user21@example.test')->assertRedirect();
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

    /** Each limit lasts a minute: 30 seconds after it is used up it still refuses; after the minute it answers again. */
    public function test_each_limit_lasts_a_full_minute(): void
    {
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

        $this->travel(30)->seconds();
        $this->fromAddress('192.0.2.60')->get('/portal/login')->assertStatus(429);
        $this->fromAddress('192.0.2.61')->post('/portal/forgot-password', ['email' => 'x@example.test'])->assertStatus(429);
        $this->fromAddress('192.0.2.62')->signIn('a@example.test')->assertStatus(429);
        $this->fromAddress('192.0.2.63')->signIn('user21@example.test')->assertStatus(429);

        $this->travel(31)->seconds();
        $this->fromAddress('192.0.2.60')->get('/portal/login')->assertOk();
        $this->fromAddress('192.0.2.61')->post('/portal/forgot-password', ['email' => 'x@example.test'])->assertRedirect();
        $this->fromAddress('192.0.2.62')->signIn('a@example.test')->assertRedirect();
        $this->fromAddress('192.0.2.63')->signIn('user21@example.test')->assertRedirect();
    }

    /** With the portal switched off, every guest route answers 404, the limited ones included. */
    public function test_the_guest_routes_answer_404_when_the_portal_is_off(): void
    {
        Setting::setValue('portal_enabled', '0');

        $this->get('/portal/login')->assertNotFound();
        $this->signIn('a@example.test')->assertNotFound();
        $this->post('/portal/forgot-password', ['email' => 'x@example.test'])->assertNotFound();
        $this->post('/portal/request-access', ['email' => 'x@example.test'])->assertNotFound();
        $this->post('/portal/reset-password', ['token' => 't', 'email' => 'x@example.test', 'password' => 'p', 'password_confirmation' => 'p'])->assertNotFound();
        $this->get(URL::temporarySignedRoute('portal.verify-access', now()->addHour(), ['person' => 999999]))->assertNotFound();
    }
}
