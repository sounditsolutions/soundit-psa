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
 * Each test exhausts one limiter and shows the others still answer, so a
 * limiter that shared a counter with another one would fail here.
 *
 * All requests come from one direct address (no trusted proxy), in the
 * 192.0.2.0/24 documentation block; emails use example.test.
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

    public function test_each_form_page_allows_60_views_a_minute_per_address(): void
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
     * A plain throttle:60,1 on the pages would share the 6-a-minute POSTs'
     * per-address counter: ten page views would then refuse a password reset.
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

        for ($n = 1; $n <= 30; $n++) {
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
}
