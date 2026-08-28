<?php

namespace Tests\Feature\Settings;

use App\Models\Client;
use App\Models\ClientPowerdmarcDomain;
use App\Models\ClientPowerdmarcKey;
use App\Models\Setting;
use App\Models\User;
use App\Services\PowerDmarc\PowerDmarcClient;
use App\Services\PowerDmarc\PowerDmarcReadOnlyToolset;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Per-client PowerDMARC API keys (ops 440/442).
 *
 * The invariants this surface owns:
 *  - a per-client key is a CREDENTIAL WRITE: admin-gated (the #762 pattern),
 *    encrypted at rest, never serialized, capped at 5000 chars with rendered
 *    errors (the #751 lesson), removable only by the explicit Clear checkbox;
 *  - mapped-domain reads SIGN with the caller client's own key when one is
 *    stored and fall back to the account-level key when not — direct
 *    (non-MSSP) deployments keep working with no per-client keys at all;
 *  - an auth failure names WHICH credential was refused, because that decides
 *    the remedy (store a per-client key vs rotate the one that failed);
 *  - the per-client key UI stays reachable when the account-level domain
 *    listing itself fails, since on an MSSP account that listing can 403 and
 *    the keys are the remedy.
 */
class PowerDmarcPerClientKeyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setEncrypted('powerdmarc_api_key', 'account-key');
        Setting::setValue('powerdmarc_enabled', '1');
    }

    /** @param array<int, Response> $queue */
    private function bindClientReturning(array $queue): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $http = new GuzzleClient(['base_uri' => 'https://app.powerdmarc.com/', 'handler' => $stack]);

        $this->app->instance(PowerDmarcClient::class, new PowerDmarcClient(['api_key' => 'account-key'], $http));
    }

    private function currentScoreResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['percent' => 90, 'score' => 9, 'score_mark' => 'A', 'completed_actions_count' => 4, 'errors_count' => 0, 'details' => []],
        ]));
    }

    private function domainHealthResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => [['policy' => 'quarantine', 'percent' => 90, 'score' => 9, 'scoreMark' => 'A', 'statuses' => [], 'errors' => [], 'suggestions' => []]],
        ]));
    }

    private function forbiddenResponse(): Response
    {
        return new Response(403, ['Content-Type' => 'application/json'], json_encode(['message' => 'Forbidden']));
    }

    private function authorizationHeaders(): array
    {
        return array_map(
            fn (array $entry) => $entry['request']->getHeaderLine('Authorization'),
            $this->history,
        );
    }

    // ── credential-write route ─────────────────────────────────────────────────

    public function test_an_admin_can_store_a_per_client_key_encrypted_at_rest(): void
    {
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('settings.powerdmarc-domains.keys.update'), [
                'keys' => [$client->id => 'client-portal-jwt-1'],
            ])
            ->assertRedirect(route('settings.powerdmarc-domains.index'))
            ->assertSessionHas('success');

        $row = ClientPowerdmarcKey::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('client-portal-jwt-1', $row->api_key);
        $this->assertNull($row->verified_at, 'a freshly saved key has not passed a Test Connection');

        // Encrypted at rest: the raw column must not carry the plaintext token.
        $raw = DB::table('client_powerdmarc_keys')->where('client_id', $client->id)->value('api_key');
        $this->assertNotSame('client-portal-jwt-1', $raw);
        $this->assertStringNotContainsString('client-portal-jwt-1', (string) $raw);
    }

    public function test_a_non_admin_cannot_write_per_client_keys(): void
    {
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->tech()->create())
            ->post(route('settings.powerdmarc-domains.keys.update'), [
                'keys' => [$client->id => 'client-portal-jwt-1'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('client_powerdmarc_keys', ['client_id' => $client->id]);
    }

    public function test_blank_and_masked_submits_keep_the_stored_key(): void
    {
        $client = Client::factory()->create();
        ClientPowerdmarcKey::create(['client_id' => $client->id, 'api_key' => 'stored-jwt']);

        foreach (['', '••••••••'] as $submitted) {
            $this->actingAs(User::factory()->create())
                ->post(route('settings.powerdmarc-domains.keys.update'), [
                    'keys' => [$client->id => $submitted],
                ])
                ->assertRedirect(route('settings.powerdmarc-domains.index'));

            $this->assertSame('stored-jwt', ClientPowerdmarcKey::where('client_id', $client->id)->firstOrFail()->api_key);
        }
    }

    public function test_clear_removes_the_key_and_wins_over_a_typed_value(): void
    {
        $client = Client::factory()->create();
        ClientPowerdmarcKey::create(['client_id' => $client->id, 'api_key' => 'stored-jwt']);

        // Clear checked AND a value typed into the same row: the explicit clear
        // wins — nothing may resurrect the credential the operator just removed.
        $this->actingAs(User::factory()->create())
            ->post(route('settings.powerdmarc-domains.keys.update'), [
                'keys' => [$client->id => 'typed-anyway'],
                'clear' => [$client->id => '1'],
            ])
            ->assertRedirect(route('settings.powerdmarc-domains.index'));

        $this->assertDatabaseMissing('client_powerdmarc_keys', ['client_id' => $client->id]);
    }

    public function test_an_overlong_key_is_rejected_with_a_rendered_error(): void
    {
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('settings.powerdmarc-domains.keys.update'), [
                'keys' => [$client->id => str_repeat('x', 5001)],
            ])
            ->assertSessionHasErrors('keys.'.$client->id);

        $this->assertDatabaseMissing('client_powerdmarc_keys', ['client_id' => $client->id]);
    }

    public function test_replacing_a_key_resets_its_verification(): void
    {
        $client = Client::factory()->create();
        ClientPowerdmarcKey::create(['client_id' => $client->id, 'api_key' => 'old-jwt', 'verified_at' => now()]);

        $this->actingAs(User::factory()->create())
            ->post(route('settings.powerdmarc-domains.keys.update'), [
                'keys' => [$client->id => 'new-jwt'],
            ])
            ->assertRedirect(route('settings.powerdmarc-domains.index'));

        $row = ClientPowerdmarcKey::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('new-jwt', $row->api_key);
        $this->assertNull($row->verified_at, 'a rotated key has not been re-verified');
    }

    public function test_the_key_section_stays_reachable_when_the_domain_listing_fails(): void
    {
        Client::factory()->create(['name' => 'Acme Co']);
        // The account-level /domains listing 403s — the MSSP-account shape.
        $this->bindClientReturning([$this->forbiddenResponse()]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.powerdmarc-domains.index'))
            ->assertOk();

        $response->assertSee('Could not list PowerDMARC domains with the account-level key');
        $response->assertSee('Per-Client API Keys');
        $response->assertSee('Acme Co');
        $response->assertDontSee('Save Mappings');
    }

    // ── read-path credential resolution ────────────────────────────────────────

    public function test_mapped_domain_reads_sign_with_the_per_client_key(): void
    {
        $client = Client::factory()->create();
        ClientPowerdmarcDomain::create(['client_id' => $client->id, 'powerdmarc_domain_id' => 7, 'domain_name' => 'acme.com']);
        ClientPowerdmarcKey::create(['client_id' => $client->id, 'api_key' => 'client-jwt-7']);

        $this->bindClientReturning([$this->currentScoreResponse(), $this->domainHealthResponse()]);

        $result = app(PowerDmarcReadOnlyToolset::class)
            ->execute('powerdmarc_get_domain_status', ['client_id' => $client->id]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertCount(2, $this->history);
        $this->assertSame(['Bearer client-jwt-7', 'Bearer client-jwt-7'], $this->authorizationHeaders());
    }

    public function test_mapped_domain_reads_fall_back_to_the_account_key_without_a_per_client_key(): void
    {
        $client = Client::factory()->create();
        ClientPowerdmarcDomain::create(['client_id' => $client->id, 'powerdmarc_domain_id' => 7, 'domain_name' => 'acme.com']);

        $this->bindClientReturning([$this->currentScoreResponse(), $this->domainHealthResponse()]);

        $result = app(PowerDmarcReadOnlyToolset::class)
            ->execute('powerdmarc_get_domain_status', ['client_id' => $client->id]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(['Bearer account-key', 'Bearer account-key'], $this->authorizationHeaders());
    }

    public function test_an_auth_refusal_names_the_credential_that_was_used(): void
    {
        $client = Client::factory()->create();
        ClientPowerdmarcDomain::create(['client_id' => $client->id, 'powerdmarc_domain_id' => 7, 'domain_name' => 'acme.com']);

        // Account-key fallback refused → the remedy is storing a per-client key.
        $this->bindClientReturning([$this->forbiddenResponse(), $this->forbiddenResponse()]);
        $result = app(PowerDmarcReadOnlyToolset::class)
            ->execute('powerdmarc_get_domain_status', ['client_id' => $client->id]);
        $this->assertStringContainsString('ACCOUNT-LEVEL', $result['records']['error']);
        $this->assertStringContainsString('per-client API key', $result['records']['error']);

        // Stored per-client key refused → the remedy is rotating THAT key.
        ClientPowerdmarcKey::create(['client_id' => $client->id, 'api_key' => 'client-jwt-7']);
        $this->bindClientReturning([$this->forbiddenResponse(), $this->forbiddenResponse()]);
        $result = app(PowerDmarcReadOnlyToolset::class)
            ->execute('powerdmarc_get_domain_status', ['client_id' => $client->id]);
        $this->assertStringContainsString('PER-CLIENT', $result['records']['error']);
    }

    // ── per-client Test Connection ─────────────────────────────────────────────

    public function test_the_key_test_verifies_a_real_per_domain_read_for_a_mapped_client(): void
    {
        $client = Client::factory()->create();
        ClientPowerdmarcDomain::create(['client_id' => $client->id, 'powerdmarc_domain_id' => 7, 'domain_name' => 'acme.com']);
        ClientPowerdmarcKey::create(['client_id' => $client->id, 'api_key' => 'client-jwt-7']);

        $this->bindClientReturning([$this->domainHealthResponse()]);

        $this->actingAs(User::factory()->create())
            ->post(route('settings.powerdmarc-domains.keys.test', $client))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(['Bearer client-jwt-7'], $this->authorizationHeaders());
        $this->assertNotNull(ClientPowerdmarcKey::where('client_id', $client->id)->firstOrFail()->verified_at);
    }

    public function test_a_refused_key_test_reports_failure_and_does_not_verify(): void
    {
        $client = Client::factory()->create();
        ClientPowerdmarcDomain::create(['client_id' => $client->id, 'powerdmarc_domain_id' => 7, 'domain_name' => 'acme.com']);
        ClientPowerdmarcKey::create(['client_id' => $client->id, 'api_key' => 'client-jwt-7']);

        $this->bindClientReturning([$this->forbiddenResponse()]);

        $this->actingAs(User::factory()->create())
            ->post(route('settings.powerdmarc-domains.keys.test', $client))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertNull(ClientPowerdmarcKey::where('client_id', $client->id)->firstOrFail()->verified_at);
    }

    public function test_the_key_test_without_a_mapping_is_only_an_identity_probe(): void
    {
        $client = Client::factory()->create();
        ClientPowerdmarcKey::create(['client_id' => $client->id, 'api_key' => 'client-jwt-7']);

        // /api/v1/me answers WITHOUT a data envelope (shape fact 1).
        $this->bindClientReturning([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 1, 'email' => 'x@example.com'])),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('settings.powerdmarc-domains.keys.test', $client))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertStringContainsString('no mapped domain', $response->json('message'));
        // Identity is weaker evidence than a per-domain read: not marked verified.
        $this->assertNull(ClientPowerdmarcKey::where('client_id', $client->id)->firstOrFail()->verified_at);
    }
}
