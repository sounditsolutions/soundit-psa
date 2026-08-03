<?php

namespace Tests\Feature\AppRiver;

use App\Models\Setting;
use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverClientException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guard for #389. AppRiver reports a dead refresh token as
 * {"error":"invalid_request","error_description":"{\"error\":\"invalid_grant\"}"} —
 * the real code is nested one field over. Before the fix, handleRefreshFailure()
 * read the top-level "invalid_request", never matched, and never cleared the dead
 * credentials, so isConnected() stayed true and the UI kept claiming connected.
 *
 * Every test asserts inside a catch block, so every one calls fail() after the act:
 * without it, a regression that stops throwing would pass these vacuously.
 */
class AppRiverNestedOauthErrorTest extends TestCase
{
    use RefreshDatabase;

    private function seedConnectedTokens(): void
    {
        Setting::setEncrypted('appriver_client_id', 'test-client');
        Setting::setEncrypted('appriver_client_secret', 'test-secret');
        Setting::setEncrypted('appriver_access_token', 'stale-access-token');
        Setting::setEncrypted('appriver_refresh_token', 'dead-refresh-token');
        Setting::setValue('appriver_token_expires_at', now()->subMinutes(5)->toDateTimeString());
        Setting::setValue('appriver_connected_at', now()->subDays(3)->toDateTimeString());
    }

    private function clientRespondingWith(string $body): AppRiverClient
    {
        $mock = new MockHandler([
            new ClientException(
                'Client error',
                new Request('POST', 'auth/token'),
                new Response(400, [], $body),
            ),
        ]);

        return new AppRiverClient([
            'base_url' => 'https://appriver.test',
            'handler' => HandlerStack::create($mock),
        ]);
    }

    public function test_nested_invalid_grant_clears_dead_credentials(): void
    {
        $this->seedConnectedTokens();
        $this->assertTrue(AppRiverClient::isConnected());

        $client = $this->clientRespondingWith(
            '{"error":"invalid_request","error_description":"{\"error\":\"invalid_grant\"}"}'
        );

        try {
            $client->getSubscriptions('customer-1');
            $this->fail('Expected AppRiverClientException for a dead refresh token.');
        } catch (AppRiverClientException $e) {
            $this->assertSame('invalid_grant', $e->oauthError);
        }

        $this->assertFalse(
            AppRiverClient::isConnected(),
            'Dead credentials must be cleared so the integrations page stops reporting connected.'
        );
        $this->assertNull(Setting::getEncrypted('appriver_refresh_token'));
        $this->assertNotNull(
            Setting::getValue('appriver_connected_at'),
            'connected_at must survive a disconnect — it is the only field separating '.
            '"credentials died on a date" from "never connected".'
        );
    }

    public function test_top_level_error_code_is_still_honoured(): void
    {
        $this->seedConnectedTokens();

        $client = $this->clientRespondingWith(
            '{"error":"invalid_client","error_description":"Client authentication failed"}'
        );

        try {
            $client->getSubscriptions('customer-1');
            $this->fail('Expected AppRiverClientException for a rejected client credential.');
        } catch (AppRiverClientException $e) {
            $this->assertSame('invalid_client', $e->oauthError);
        }

        $this->assertFalse(AppRiverClient::isConnected());
    }

    /**
     * The nested code must never MASK an actionable top-level one. An
     * unconditional override would reproduce #389 in the opposite direction:
     * a real top-level invalid_grant hidden behind whatever the description nests.
     */
    public function test_nested_code_does_not_mask_an_actionable_top_level_code(): void
    {
        $this->seedConnectedTokens();

        $client = $this->clientRespondingWith(
            '{"error":"invalid_grant","error_description":"{\"error\":\"server_error\",\"trace\":\"abc\"}"}'
        );

        try {
            $client->getSubscriptions('customer-1');
            $this->fail('Expected AppRiverClientException for a dead refresh token.');
        } catch (AppRiverClientException $e) {
            $this->assertSame(
                'invalid_grant',
                $e->oauthError,
                'A nested non-credential code must not displace an actionable top-level one.'
            );
        }

        $this->assertFalse(AppRiverClient::isConnected());
    }

    public function test_unrelated_oauth_error_leaves_credentials_alone(): void
    {
        $this->seedConnectedTokens();

        $client = $this->clientRespondingWith(
            '{"error":"temporarily_unavailable","error_description":"try again later"}'
        );

        try {
            $client->getSubscriptions('customer-1');
            $this->fail('Expected AppRiverClientException for a transient vendor error.');
        } catch (AppRiverClientException $e) {
            $this->assertSame('temporarily_unavailable', $e->oauthError);
        }

        $this->assertTrue(
            AppRiverClient::isConnected(),
            'A transient vendor error must not disconnect a live integration.'
        );
    }

    public function test_non_json_description_does_not_break_parsing(): void
    {
        $this->seedConnectedTokens();

        $client = $this->clientRespondingWith(
            '{"error":"invalid_grant","error_description":"refresh token expired"}'
        );

        try {
            $client->getSubscriptions('customer-1');
            $this->fail('Expected AppRiverClientException for an expired refresh token.');
        } catch (AppRiverClientException $e) {
            $this->assertSame('invalid_grant', $e->oauthError);
        }

        $this->assertFalse(AppRiverClient::isConnected());
    }

    /**
     * tokenRequest() is shared with the authorization-code exchange, so the nested
     * code is normalised there too. That is harmless: handleRefreshFailure() is
     * only reached from getAccessToken() and the 401-retry path, never from
     * exchangeCode(), so a failed re-connect cannot clear a live credential set.
     */
    public function test_failed_code_exchange_does_not_clear_stored_credentials(): void
    {
        $this->seedConnectedTokens();

        $client = $this->clientRespondingWith(
            '{"error":"invalid_request","error_description":"{\"error\":\"invalid_grant\"}"}'
        );

        try {
            $client->exchangeCode('stale-authorization-code');
            $this->fail('Expected AppRiverClientException for a stale authorization code.');
        } catch (AppRiverClientException $e) {
            $this->assertSame('invalid_grant', $e->oauthError);
        }

        $this->assertTrue(
            AppRiverClient::isConnected(),
            'A failed re-connect must not clear credentials that are still stored.'
        );
    }
}
