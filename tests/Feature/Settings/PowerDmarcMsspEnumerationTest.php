<?php

namespace Tests\Feature\Settings;

use App\Models\Client;
use App\Models\ClientPowerdmarcDomain;
use App\Models\Setting;
use App\Models\User;
use App\Services\PowerDmarc\PowerDmarcClient;
use App\Support\PowerDmarcConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * PowerDMARC MSSP enumeration lane (#801): with powerdmarc_mssp_base_url set,
 * the Domain Mapping page enumerates via the MSSP tenant-portal surface
 * (GET {mssp}/api/v1/mssp/accounts/domains) instead of the end-user
 * /api/v1/domains — the route an MSSP account key 403s as steady state, which
 * is why no mapping could ever be created on the account class an MSSP
 * actually runs.
 *
 * The server-side name-resolution invariant is unchanged: a mapping is still
 * the pair powerdmarc_domain_id + domain_name resolved from a vendor listing
 * at save time, never from the submitted form — the listing is just signed
 * against the MSSP surface. Payload shapes below mirror the committed fixture
 * tests/Fixtures/powerdmarc/mssp_accounts_domains.json, which is authored from
 * a live measurement of the tenant portal (2026-08-28) — the /api/v1/mssp/*
 * family is absent from the vendor's OpenAPI spec.
 */
class PowerDmarcMsspEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private const MSSP_BASE = 'https://tenant.powerdmarc.com';

    private User $user;

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Setting::setEncrypted('powerdmarc_api_key', 'test-key');
        Setting::setValue('powerdmarc_mssp_base_url', self::MSSP_BASE);
    }

    /** @param array<int, Response> $queue */
    private function bindClientReturning(array $queue): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $http = new GuzzleClient(['base_uri' => 'https://app.powerdmarc.com/', 'handler' => $stack]);

        $this->app->instance(PowerDmarcClient::class, new PowerDmarcClient([
            'api_key' => 'test-key',
            'mssp_base_url' => self::MSSP_BASE,
        ], $http));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function msspPage(array $rows, ?string $next = null): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => $rows,
            'links' => ['first' => 'x', 'last' => 'x', 'prev' => null, 'next' => $next],
            'meta' => ['current_page' => 1, 'last_page' => $next === null ? 1 : 2, 'per_page' => 15, 'total' => count($rows)],
        ]));
    }

    private function msspRow(int $domainId, string $domainName, string $accountName = 'Some Account'): array
    {
        return [
            'domain_name' => $domainName,
            'domain_id' => $domainId,
            'account' => ['account_name' => $accountName, 'id' => $domainId + 10],
        ];
    }

    public function test_the_mapping_page_enumerates_via_the_mssp_surface_when_the_portal_url_is_set(): void
    {
        $this->bindClientReturning([
            $this->msspPage([
                $this->msspRow(101, 'acme.com', 'Acme Co'),
                $this->msspRow(102, 'branch-mail.com'),
            ]),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('settings.powerdmarc-domains.index'))
            ->assertOk();

        $response->assertSee('acme.com');
        $response->assertSee('branch-mail.com');
        $response->assertSee('Domain ID: 101');
        $response->assertSee('Domain ID: 102');

        // The one upstream request went to the MSSP portal, not the end-user
        // /api/v1/domains route (which 403s an MSSP key as steady state).
        $this->assertCount(1, $this->history);
        $uri = $this->history[0]['request']->getUri();
        $this->assertSame('tenant.powerdmarc.com', $uri->getHost());
        $this->assertSame('/api/v1/mssp/accounts/domains', $uri->getPath());
    }

    public function test_the_mssp_rows_render_without_health_badges_not_with_wrong_ones(): void
    {
        // The MSSP surface carries no health booleans; the projection must
        // yield null (the blade's em-dash), never a fabricated true/false.
        $this->bindClientReturning([
            $this->msspPage([$this->msspRow(101, 'acme.com')]),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('settings.powerdmarc-domains.index'))
            ->assertOk();

        $response->assertDontSee('badge bg-success');
        $response->assertDontSee('badge bg-danger');
    }

    public function test_saving_writes_the_id_and_name_pair_resolved_from_the_mssp_listing(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Co']);

        // index render + the update()'s own save-time listing.
        $this->bindClientReturning([
            $this->msspPage([$this->msspRow(101, 'acme.com')]),
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.powerdmarc-domains.update'), [
                'mappings' => ['101' => (string) $client->id],
                // A tampered form must not be able to choose the stored name:
                'domain_names' => ['101' => 'evil.example.com'],
            ])
            ->assertRedirect(route('settings.powerdmarc-domains.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_powerdmarc_domains', [
            'client_id' => $client->id,
            'powerdmarc_domain_id' => 101,
            'domain_name' => 'acme.com',
        ]);
    }

    public function test_auto_match_maps_by_name_through_the_mssp_listing(): void
    {
        $client = Client::factory()->create(['name' => 'acme.com']);

        $this->bindClientReturning([
            $this->msspPage([$this->msspRow(101, 'acme.com'), $this->msspRow(102, 'unmatched.com')]),
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.powerdmarc-domains.auto-match'))
            ->assertRedirect(route('settings.powerdmarc-domains.index'));

        $this->assertDatabaseHas('client_powerdmarc_domains', [
            'client_id' => $client->id,
            'powerdmarc_domain_id' => 101,
            'domain_name' => 'acme.com',
        ]);
        $this->assertSame(1, ClientPowerdmarcDomain::count());
    }

    public function test_a_failed_mssp_listing_renders_the_inline_error_not_the_end_user_fallback(): void
    {
        // Deliberately config-driven, never catch-and-fall-back: a transient
        // fault on the configured lane must surface as the banner (#789's
        // adjudicated shape), not silently enumerate a different surface
        // whose rows may differ.
        $this->bindClientReturning([
            new Response(500, ['Content-Type' => 'application/json'], json_encode(['message' => 'upstream boom'])),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('settings.powerdmarc-domains.index'))
            ->assertOk();

        $response->assertSee('PowerDMARC API error', false);
        $this->assertCount(1, $this->history, 'the failure must not trigger a second request to the end-user surface');
    }

    public function test_the_mssp_portal_url_saves_and_blank_clears_the_lane_off(): void
    {
        // A literal public IP, same as the base_url tests: SafeWebhookUrl
        // resolves hostnames live, so a made-up tenant name would be rejected
        // as unresolvable in CI.
        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => '',
                'base_url' => '',
                'mssp_base_url' => 'https://93.184.216.34/mssp-portal/',
            ])
            ->assertSessionHasNoErrors();

        // Trailing slash is normalized off by the accessor.
        $this->assertSame('https://93.184.216.34/mssp-portal', PowerDmarcConfig::msspBaseUrl());

        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => '',
                'base_url' => '',
                'mssp_base_url' => '',
            ]);

        $this->assertNull(PowerDmarcConfig::msspBaseUrl(), 'a blank MSSP portal URL must switch the lane off');
    }

    public function test_the_mssp_portal_url_rejects_http_and_private_targets(): void
    {
        // Same credential-exfiltration class as base_url (#724/#763): every
        // MSSP enumeration request signs with the Bearer key against this host.
        foreach ([
            'http://93.184.216.34/pdmarc-proxy',
            'https://169.254.169.254/latest/meta-data',
            'https://10.0.0.5/pdmarc',
        ] as $url) {
            $this->actingAs($this->user)
                ->post(route('settings.integrations.powerdmarc.update'), [
                    'api_key' => '',
                    'base_url' => '',
                    'mssp_base_url' => $url,
                ])
                ->assertSessionHasErrors('mssp_base_url');
        }

        // setUp stored a value; a rejected save must leave it untouched.
        $this->assertSame(self::MSSP_BASE, PowerDmarcConfig::msspBaseUrl(), 'a rejected MSSP portal URL must not be stored');
    }
}
