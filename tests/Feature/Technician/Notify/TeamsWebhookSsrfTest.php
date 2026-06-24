<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Models\User;
use App\Services\Technician\Notify\TeamsNotifier;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * psa-ncl1 / CO-7: the operator-set Teams webhook URL is an attacker-influenced
 * outbound target. TeamsNotifier must not be an SSRF vector. This pins the same
 * defence-in-depth the Tactical API URL has: a save-time SafeWebhookUrl rule
 * (https-only, no private/reserved/link-local/metadata literal or DNS target)
 * PLUS a request-time peer-IP pin reusing TacticalClient::ssrfPinMiddleware, so a
 * host that passed save-time validation cannot rebind to a private/metadata
 * address at connect time (the DNS-rebind TOCTOU). The resolver is injected so
 * the matrix is deterministic — Http::fake() intercepts ABOVE Guzzle middleware
 * and would NOT exercise the pin, so the pin assertions drive a recording Guzzle
 * transport sitting under the real production middleware.
 */
class TeamsWebhookSsrfTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a TeamsNotifier whose transport is a recording handler (no network)
     * sitting UNDER the real production pin middleware, with an injected resolver.
     * $captured records whether the transport was reached and the curl options.
     */
    private function pinnedNotifier(callable $resolver, array &$captured, ?Response $response = null): TeamsNotifier
    {
        $inner = function (RequestInterface $request, array $options) use (&$captured, $response) {
            $captured['called'] = true;
            $captured['curl'] = $options['curl'] ?? null;
            $captured['uri'] = (string) $request->getUri();

            return Create::promiseFor($response ?? new Response(200, [], ''));
        };

        $stack = HandlerStack::create($inner);
        $stack->push(TeamsNotifier::ssrfPinMiddleware($resolver));

        $http = new Client(['handler' => $stack]);

        return new TeamsNotifier($http);
    }

    // ── Save-time validation (SafeWebhookUrl) ────────────────────────────────

    public function test_save_rejects_internal_metadata_webhook(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from(route('settings.integrations'))->post(
            route('settings.integrations.technician.update'),
            [
                'technician_enabled' => '1',
                'technician_teams_webhook_url' => 'http://169.254.169.254/latest/meta-data',
            ],
        )->assertSessionHasErrors('technician_teams_webhook_url');
    }

    public function test_save_rejects_non_https_webhook(): void
    {
        $user = User::factory()->create();
        Setting::setValue('technician_teams_webhook_url', 'https://existing.webhook.office.com/hook');

        $this->actingAs($user)->from(route('settings.integrations'))->post(
            route('settings.integrations.technician.update'),
            [
                'technician_enabled' => '1',
                // Plain http public host — must be rejected for non-https scheme.
                'technician_teams_webhook_url' => 'http://example.webhook.office.com/hook',
            ],
        )->assertSessionHasErrors('technician_teams_webhook_url');

        // Unchanged on rejection.
        $this->assertSame('https://existing.webhook.office.com/hook', Setting::getValue('technician_teams_webhook_url'));
    }

    public function test_save_accepts_a_public_https_webhook(): void
    {
        $user = User::factory()->create();

        // A public IP literal needs no DNS and is routable -> accepted + saved.
        $this->actingAs($user)->from(route('settings.integrations'))->post(
            route('settings.integrations.technician.update'),
            [
                'technician_enabled' => '1',
                'technician_teams_webhook_url' => 'https://93.184.216.34/hook',
            ],
        )->assertSessionHasNoErrors()->assertRedirect(route('settings.integrations'));

        $this->assertSame('https://93.184.216.34/hook', Setting::getValue('technician_teams_webhook_url'));
    }

    public function test_save_allows_clearing_the_webhook(): void
    {
        $user = User::factory()->create();
        Setting::setValue('technician_teams_webhook_url', 'https://example.webhook.office.com/hook');

        // An empty/unset webhook must NOT be rejected (nullable) and clears the value.
        $this->actingAs($user)->from(route('settings.integrations'))->post(
            route('settings.integrations.technician.update'),
            [
                'technician_enabled' => '1',
                'technician_teams_webhook_url' => '',
            ],
        )->assertSessionHasNoErrors();

        $this->assertSame('', Setting::getValue('technician_teams_webhook_url'));
    }

    // ── Request-time pin (post()) ────────────────────────────────────────────

    public function test_post_fails_closed_on_internal_resolution(): void
    {
        $captured = [];
        Setting::setValue('technician_teams_webhook_url', 'https://hook.evil.example.com/x');

        // The host resolves (via the injected resolver) to a loopback address.
        $notifier = $this->pinnedNotifier(fn (string $host) => ['127.0.0.1'], $captured);

        $this->assertFalse($notifier->post('Subject', 'Body'));
        $this->assertArrayNotHasKey('called', $captured, 'transport must NOT be reached when the host resolves internally');
    }

    public function test_post_fails_closed_on_metadata_resolution(): void
    {
        $captured = [];
        Setting::setValue('technician_teams_webhook_url', 'https://hook.evil.example.com/x');

        $notifier = $this->pinnedNotifier(fn (string $host) => ['169.254.169.254'], $captured);

        $this->assertFalse($notifier->post('Subject', 'Body'));
        $this->assertArrayNotHasKey('called', $captured, 'metadata IP must be refused at request time');
    }

    public function test_post_fails_closed_on_empty_resolution(): void
    {
        $captured = [];
        Setting::setValue('technician_teams_webhook_url', 'https://hook.example.com/x');

        // IPv6-only / NXDOMAIN under the IPv4 resolver — must fail closed, not "no pin".
        $notifier = $this->pinnedNotifier(fn (string $host) => false, $captured);

        $this->assertFalse($notifier->post('Subject', 'Body'));
        $this->assertArrayNotHasKey('called', $captured, 'an unresolvable host must be refused, never sent unpinned');
    }

    public function test_post_pins_the_validated_public_ip_and_succeeds(): void
    {
        $captured = [];
        Setting::setValue('technician_teams_webhook_url', 'https://hook.example.com/x');

        $notifier = $this->pinnedNotifier(
            fn (string $host) => ['93.184.216.34'],
            $captured,
            new Response(200, [], ''),
        );

        $this->assertTrue($notifier->post('Subject', 'Body'));
        $this->assertTrue($captured['called'] ?? false, 'a public host should reach the transport');
        // DNS-rebind defence: the connection is PINNED to the validated IP, so a
        // later DNS flip to a private address cannot redirect the request.
        $this->assertSame(
            ['hook.example.com:443:93.184.216.34'],
            $captured['curl'][CURLOPT_RESOLVE] ?? null,
            'the connection must be pinned to the validated IP via CURLOPT_RESOLVE',
        );
    }

    public function test_post_fails_closed_when_one_resolved_ip_is_private(): void
    {
        $captured = [];
        Setting::setValue('technician_teams_webhook_url', 'https://hook.example.com/x');

        // A rebind that returns one public + one private record must be refused.
        $notifier = $this->pinnedNotifier(
            fn (string $host) => ['93.184.216.34', '10.1.2.3'],
            $captured,
        );

        $this->assertFalse($notifier->post('Subject', 'Body'));
        $this->assertArrayNotHasKey('called', $captured, 'any private address in the set fails the whole request closed');
    }

    public function test_default_constructor_installs_the_pin_and_refuses_private(): void
    {
        // The PRODUCTION path: the default (no injected $http) client must install
        // the pin itself, so a host resolving to a private address is refused even
        // through the real container-autowired constructor. Fail-soft: post()
        // returns false (the refusal throws, the catch swallows it), nothing sent.
        Setting::setValue('technician_teams_webhook_url', 'https://hook.evil.example.com/x');
        Http::fake(); // would catch any accidental facade send

        $notifier = new TeamsNotifier(null, fn (string $host) => ['10.1.2.3']);

        $this->assertFalse($notifier->post('Subject', 'Body'));
        Http::assertNothingSent();
    }

    public function test_no_webhook_configured_is_a_noop_false(): void
    {
        Http::fake();
        $this->assertFalse(app(TeamsNotifier::class)->post('Subject', 'Body'));
        Http::assertNothingSent();
    }
}
