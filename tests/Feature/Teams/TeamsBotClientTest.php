<?php

namespace Tests\Feature\Teams;

use App\Models\Setting;
use App\Services\Teams\TeamsBotClient;
use App\Support\TeamsBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TeamsBotClient (E2a) — the outbound Teams send.
 *
 * THE SECURITY CRUX (E1 reviewer's note): a reply is sent ONLY to a trusted Bot
 * Framework channel serviceUrl host, FAIL-CLOSED. An untrusted/attacker-supplied
 * serviceUrl results in NO outbound request at all (not even a token fetch).
 */
class TeamsBotClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Setting::setValue('teams_bot_app_id', '11111111-1111-1111-1111-111111111111');
        Setting::setValue('teams_bot_tenant_id', '22222222-2222-2222-2222-222222222222');
        TeamsBotConfig::setClientSecret('the-secret');
    }

    private function fakeAzure(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'BF-TOKEN', 'expires_in' => 3600], 200),
            'smba.trafficmanager.net/*' => Http::response(['id' => 'sent-activity-1'], 201),
        ]);
    }

    // ── the serviceUrl fail-closed guard ─────────────────────────────────────

    /** @dataProvider untrustedServiceUrls */
    public function test_untrusted_service_url_sends_nothing(string $url): void
    {
        $this->fakeAzure();

        $sent = app(TeamsBotClient::class)->sendMessage($url, 'a:conv-1', 'hello');

        $this->assertFalse($sent, "must refuse to send to {$url}");
        Http::assertNothingSent(); // not even a token fetch — fail-closed BEFORE any HTTP
    }

    public static function untrustedServiceUrls(): array
    {
        return [
            'attacker host' => ['https://evil.example.com/'],
            'http not https' => ['http://smba.trafficmanager.net/amer/'],
            'lookalike suffix' => ['https://smba.trafficmanager.net.evil.com/'],
            'substring not boundary' => ['https://evilsmba.trafficmanager.net.attacker.io/'],
            'empty' => [''],
        ];
    }

    // ── the trusted send path ────────────────────────────────────────────────

    public function test_trusted_service_url_sends_the_message_with_a_bearer_token(): void
    {
        $this->fakeAzure();

        $sent = app(TeamsBotClient::class)->sendMessage('https://smba.trafficmanager.net/amer/', 'a:conv-1', 'hi there');

        $this->assertTrue($sent);

        // The Bot Framework token was acquired from the single-tenant authority with the BF scope.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'login.microsoftonline.com/22222222-2222-2222-2222-222222222222/oauth2/v2.0/token')
            && $req['scope'] === 'https://api.botframework.com/.default'
            && $req['client_id'] === '11111111-1111-1111-1111-111111111111');

        // The activity was posted to the conversation with the bearer token.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'smba.trafficmanager.net/amer/v3/conversations/')
            && str_contains($req->url(), 'activities')
            && $req->hasHeader('Authorization', 'Bearer BF-TOKEN')
            && $req['type'] === 'message'
            && $req['text'] === 'hi there');
    }

    public function test_typing_indicator_is_sent_to_a_trusted_url(): void
    {
        $this->fakeAzure();

        app(TeamsBotClient::class)->sendTyping('https://smba.trafficmanager.net/amer/', 'a:conv-1');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'activities') && $req['type'] === 'typing');
    }

    public function test_unconfigured_bot_sends_nothing(): void
    {
        Setting::where('key', 'like', 'teams_bot_%')->delete();
        $this->fakeAzure();

        $sent = app(TeamsBotClient::class)->sendMessage('https://smba.trafficmanager.net/amer/', 'a:conv-1', 'hi');

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }
}
