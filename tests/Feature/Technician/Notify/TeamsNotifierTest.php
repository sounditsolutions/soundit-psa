<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Services\Technician\Notify\TeamsNotifier;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class TeamsNotifierTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a TeamsNotifier over a recording Guzzle transport sitting UNDER the
     * real production pin middleware, with an injected resolver. The production
     * post() path now uses a raw pinned Guzzle client (NOT the Http facade — so a
     * security control can't be faked away), which Http::fake() cannot intercept;
     * this seam exercises the real request the way TacticalClientSsrfPinTest does.
     *
     * @param  array<string, mixed>  $captured
     */
    private function recordingNotifier(array &$captured, ?Response $response = null): TeamsNotifier
    {
        $inner = function (RequestInterface $request, array $options) use (&$captured, $response) {
            $captured['uri'] = (string) $request->getUri();
            $captured['body'] = (string) $request->getBody();

            return Create::promiseFor($response ?? new Response(200, [], ''));
        };

        $stack = HandlerStack::create($inner);
        // Resolve to a public IP so the pin proceeds (the SSRF matrix lives in
        // TeamsWebhookSsrfTest; here we only assert the card is posted correctly).
        $stack->push(TeamsNotifier::ssrfPinMiddleware(fn (string $host) => ['93.184.216.34']));

        return new TeamsNotifier(new Client(['handler' => $stack]));
    }

    public function test_no_webhook_configured_is_a_noop_false(): void
    {
        Http::fake();
        $this->assertFalse(app(TeamsNotifier::class)->post('Subject', 'Body'));
        Http::assertNothingSent();
    }

    public function test_posts_a_card_to_the_configured_webhook(): void
    {
        Setting::setValue('technician_teams_webhook_url', 'https://example.webhook.office.com/hook');
        $captured = [];

        $this->assertTrue($this->recordingNotifier($captured)->post('Daily digest', 'You have 3 drafts.'));

        $this->assertSame('https://example.webhook.office.com/hook', $captured['uri'] ?? null);
        $this->assertStringContainsString('You have 3 drafts.', $captured['body'] ?? '');
    }

    public function test_a_non_2xx_returns_false_and_does_not_crash(): void
    {
        Setting::setValue('technician_teams_webhook_url', 'https://example.webhook.office.com/hook');
        $captured = [];

        // A 5xx from the webhook → post() returns false (Guzzle throws on 5xx by
        // default; the catch swallows it), never an uncaught error.
        $this->assertFalse(
            $this->recordingNotifier($captured, new Response(500, [], 'nope'))->post('S', 'B'),
        );
    }

    public function test_transport_throw_is_caught_and_returns_false(): void
    {
        Setting::setValue('technician_teams_webhook_url', 'https://example.webhook.office.com/hook');

        $inner = function () {
            return Create::rejectionFor(new \GuzzleHttp\Exception\ConnectException(
                'down',
                new \GuzzleHttp\Psr7\Request('POST', 'https://example.webhook.office.com/hook'),
            ));
        };
        $stack = HandlerStack::create($inner);
        $stack->push(TeamsNotifier::ssrfPinMiddleware(fn (string $host) => ['93.184.216.34']));
        $notifier = new TeamsNotifier(new Client(['handler' => $stack]));

        $this->assertFalse($notifier->post('S', 'B'));
    }
}
