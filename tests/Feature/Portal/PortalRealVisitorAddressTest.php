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
 * config/trustedproxy.php now trusts 127.0.0.1 plus Cloudflare's published
 * ranges, and bootstrap/app.php reads X-Forwarded-For only. These tests pin:
 *  - two visitors behind the same edge get separate sign-in allowances;
 *  - a caller outside the trusted ranges cannot choose its address with the
 *    header;
 *  - behind a real edge, a forged left-hand header entry is ignored (the
 *    address is the one the edge appended);
 *  - scheme and host are not taken from forwarded headers.
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
}
