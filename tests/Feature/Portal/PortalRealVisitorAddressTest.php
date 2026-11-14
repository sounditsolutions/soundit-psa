<?php

namespace Tests\Feature\Portal;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The site sits behind Cloudflare: the connecting address PHP sees is a
 * Cloudflare edge. Before this change only 127.0.0.1 was a trusted proxy, so
 * every guest throttle keyed on the edge address and unrelated visitors
 * shared one allowance (the portal sign-in pages' throttle:6,1 answered 429
 * after very few tries).
 *
 * config/trustedproxy.php lists 127.0.0.1 plus Cloudflare's published
 * ranges; TrustCloudflareHop trusts only the connecting address, and only
 * when it is in that list; bootstrap/app.php reads X-Forwarded-For only.
 * These tests pin:
 *  - two visitors behind the same edge get separate sign-in allowances;
 *  - a caller outside the trusted ranges cannot choose its address with the
 *    header;
 *  - behind a real edge, a forged left-hand header entry is ignored (the
 *    address is the one the edge appended), including when the appended
 *    entry is itself a Cloudflare address, as for a Worker's request;
 *  - an appended entry equal to the connecting address, or not a valid IP,
 *    resolves to the connecting address, never to an entry the caller wrote;
 *  - the trusted list is exactly 127.0.0.1 plus the 22 published ranges;
 *  - scheme, host and path prefix are not taken from forwarded headers.
 *
 * Addresses: the edges are synthetic hosts inside Cloudflare's published
 * ranges; visitors, forged entries and the direct caller use the
 * 192.0.2.0/24 documentation block.
 */
class PortalRealVisitorAddressTest extends TestCase
{
    use RefreshDatabase;

    private const EDGE_V4 = '173.245.48.10';

    private const EDGE_V6 = '2400:cb00::10';

    private const VISITOR_A = '192.0.2.10';

    private const VISITOR_B = '192.0.2.20';

    private const DIRECT_CALLER = '192.0.2.50';

    /** Inside 2a06:98c0::/29, the published v6 range Workers send from. */
    private const CLOUDFLARE_APPENDED = '2a06:98c0::1';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Setting::setValue('portal_enabled', '1');

        Route::middleware('web')->get('/__test/visitor-address', fn (Request $r) => response($r->ip() ?? ''));
    }

    private function fromAddress(string $remote, ?string $forwardedFor = null): static
    {
        $this->withServerVariables(['REMOTE_ADDR' => $remote]);
        $this->defaultHeaders = [];
        if ($forwardedFor !== null) {
            $this->withHeader('X-Forwarded-For', $forwardedFor);
        }

        return $this;
    }

    /** The card's done-when: visitor A uses up the allowance, visitor B behind the same edge still gets in. */
    public function test_two_visitors_behind_one_cloudflare_edge_have_separate_sign_in_allowances(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->fromAddress(self::EDGE_V4, self::VISITOR_A)->get('/portal/login')->assertOk();
        }
        $this->fromAddress(self::EDGE_V4, self::VISITOR_A)->get('/portal/login')->assertStatus(429);

        $this->fromAddress(self::EDGE_V4, self::VISITOR_B)->get('/portal/login')->assertOk();
    }

    public function test_a_cloudflare_edge_resolves_to_the_visitor_it_forwarded_for_v4_and_v6(): void
    {
        $this->fromAddress(self::EDGE_V4, self::VISITOR_A)->get('/__test/visitor-address')->assertOk()->assertSeeText(self::VISITOR_A, false);
        $this->fromAddress(self::EDGE_V6, self::VISITOR_B)->get('/__test/visitor-address')->assertOk()->assertSeeText(self::VISITOR_B, false);
        $this->fromAddress('127.0.0.1', self::VISITOR_A)->get('/__test/visitor-address')->assertOk()->assertSeeText(self::VISITOR_A, false);
    }

    public function test_a_forged_header_from_outside_the_trusted_ranges_is_ignored(): void
    {
        $this->fromAddress(self::DIRECT_CALLER, self::VISITOR_A)->get('/__test/visitor-address')
            ->assertOk()->assertSeeText(self::DIRECT_CALLER, false);

        // Rotating the header does not buy a fresh allowance.
        for ($i = 1; $i <= 6; $i++) {
            $this->fromAddress(self::DIRECT_CALLER, '192.0.2.'.(100 + $i))->get('/portal/login')->assertOk();
        }
        $this->fromAddress(self::DIRECT_CALLER, '192.0.2.200')->get('/portal/login')->assertStatus(429);
    }

    public function test_behind_a_real_edge_a_forged_left_hand_entry_is_ignored(): void
    {
        // The visitor sends its own X-Forwarded-For; the edge appends the
        // address it actually saw. Only the appended entry counts.
        $this->fromAddress(self::EDGE_V4, '192.0.2.77, '.self::VISITOR_A)->get('/__test/visitor-address')
            ->assertOk()->assertSeeText(self::VISITOR_A, false);

        for ($i = 1; $i <= 6; $i++) {
            $this->fromAddress(self::EDGE_V4, '192.0.2.'.(100 + $i).', '.self::VISITOR_A)->get('/portal/login')->assertOk();
        }
        $this->fromAddress(self::EDGE_V4, '192.0.2.200, '.self::VISITOR_A)->get('/portal/login')->assertStatus(429);
    }

    public function test_scheme_and_host_are_not_taken_from_forwarded_headers(): void
    {
        $this->fromAddress(self::EDGE_V4, self::VISITOR_A)
            ->withHeader('X-Forwarded-Proto', 'https')
            ->withHeader('X-Forwarded-Host', 'forged.example')
            ->withHeader('X-Forwarded-Port', '8443');

        $response = $this->get('/portal/login')->assertOk();
        $response->assertDontSee('forged.example', false);
        $response->assertDontSee(':8443', false);
        $this->assertFalse(request()->isSecure());
        $this->assertSame('localhost', request()->getHost());
    }

    /**
     * A request that starts on Cloudflare's network (a Worker): the edge
     * appends an address that is itself inside Cloudflare's ranges. Only the
     * connecting hop is trusted, so the appended entry (which differs from the
     * connecting edge here) is the address and the caller-written entry to its
     * left is not used.
     */
    public function test_a_forged_entry_left_of_a_cloudflare_appended_address_is_ignored(): void
    {
        $this->fromAddress(self::EDGE_V4, '192.0.2.77, '.self::CLOUDFLARE_APPENDED)->get('/__test/visitor-address')
            ->assertOk()->assertSeeText(self::CLOUDFLARE_APPENDED, false);

        // Rotating the forged entry does not buy a fresh sign-in allowance.
        for ($i = 1; $i <= 6; $i++) {
            $this->fromAddress(self::EDGE_V4, '192.0.2.'.(100 + $i).', '.self::CLOUDFLARE_APPENDED)->get('/portal/login')->assertOk();
        }
        $this->fromAddress(self::EDGE_V4, '192.0.2.200, '.self::CLOUDFLARE_APPENDED)->get('/portal/login')->assertStatus(429);
    }

    /** Any change to the list is a deliberate edit of this test as well. */
    public function test_the_trusted_list_is_exactly_localhost_plus_cloudflares_published_ranges(): void
    {
        $this->assertSame([
            '127.0.0.1',
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ], config('trustedproxy.proxies'));
    }

    public function test_a_forged_path_prefix_does_not_change_generated_urls(): void
    {
        $this->fromAddress(self::EDGE_V4, self::VISITOR_A)
            ->withHeader('X-Forwarded-Prefix', '/forgedprefix');

        $response = $this->get('/portal/login')->assertOk();
        $response->assertDontSee('forgedprefix', false);
        $this->assertStringNotContainsString('forgedprefix', url('/portal/login'));
        $this->assertSame('', request()->getBaseUrl());
    }

    /**
     * The appended entry equals the connecting address. Symfony drops every
     * entry equal to a trusted proxy, so without the right-most cut the
     * caller's own entry to its left became the address.
     */
    public function test_an_appended_entry_equal_to_the_connecting_address_resolves_to_that_address(): void
    {
        $this->fromAddress(self::EDGE_V4, '192.0.2.77, '.self::EDGE_V4)->get('/__test/visitor-address')
            ->assertOk()->assertSeeText(self::EDGE_V4, false)->assertDontSeeText('192.0.2.77', false);

        $this->fromAddress(self::EDGE_V6, '192.0.2.99, '.self::EDGE_V6)->get('/__test/visitor-address')
            ->assertOk()->assertSeeText(self::EDGE_V6, false)->assertDontSeeText('192.0.2.99', false);
    }

    /** An appended entry that is not an address resolves to the connecting address, not the entry to its left. */
    public function test_an_appended_entry_that_is_not_an_address_resolves_to_the_connecting_address(): void
    {
        $this->fromAddress(self::EDGE_V4, '192.0.2.77, garbage')->get('/__test/visitor-address')
            ->assertOk()->assertSeeText(self::EDGE_V4, false)->assertDontSeeText('192.0.2.77', false);
    }

    /** With the appended entry equal to the connecting address, rotating the left entry buys no fresh allowance. */
    public function test_rotating_the_left_entry_beside_a_same_hop_entry_buys_no_allowance(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->fromAddress(self::EDGE_V4, '192.0.2.'.(100 + $i).', '.self::EDGE_V4)->get('/portal/login')->assertOk();
        }
        $this->fromAddress(self::EDGE_V4, '192.0.2.200, '.self::EDGE_V4)->get('/portal/login')->assertStatus(429);
    }
}
