<?php

namespace Tests\Unit\Huntress;

use App\Services\Huntress\HuntressClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * HuntressClient read-path behavior (psa-shej). Pure unit tests over an injected
 * Guzzle client backed by a MockHandler — no network, no Laravel container.
 *
 * Focus: the two API-shape facts a naive wrapper gets wrong (from
 * api.huntress.io/v1/swagger_doc.json):
 *   1. GET /escalations/{id} returns the escalation object DIRECTLY — no
 *      {escalation:{...}} wrapper (unlike incident_reports / organizations).
 *   2. The account is rate-limited (60 rpm); a 429 must back off and retry,
 *      not surface as an error on the first bump.
 */
class HuntressClientReadTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * @param  array<int, Response|\Throwable>  $queue
     */
    private function clientReturning(array $queue): HuntressClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $http = new GuzzleClient([
            'base_uri' => 'https://api.huntress.io/v1/',
            'handler' => $stack,
        ]);

        return new HuntressClient(['api_key' => 'k', 'api_secret' => 's'], $http);
    }

    private function lastPath(): string
    {
        return $this->history[array_key_last($this->history)]['request']->getUri()->getPath();
    }

    public function test_get_escalation_returns_the_unwrapped_object_with_no_wrapper_key(): void
    {
        // GET /escalations/{id} → EscalationWithEntities DIRECTLY (no {escalation} wrapper).
        $escalation = ['id' => 77, 'status' => 'resolved', 'subject' => 'Failed to Deliver', 'entities' => ['foo' => 'bar']];
        $client = $this->clientReturning([new Response(200, [], json_encode($escalation))]);

        $result = $client->getEscalation(77);

        $this->assertSame(77, $result['id']);
        $this->assertSame('resolved', $result['status']);
        $this->assertSame('Failed to Deliver', $result['subject']);
        $this->assertSame(['foo' => 'bar'], $result['entities']);
        $this->assertSame('/v1/escalations/77', $this->lastPath());
    }

    public function test_get_escalation_defensively_unwraps_a_wrapper_if_the_api_ever_adds_one(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['escalation' => ['id' => 9, 'status' => 'sent']])),
        ]);

        $result = $client->getEscalation(9);

        $this->assertSame(9, $result['id']);
        $this->assertSame('sent', $result['status']);
    }

    public function test_get_escalations_auto_paginates_and_unwraps_the_escalations_key(): void
    {
        // Page 1 carries a next_page_token; page 2 ends pagination. Rows live under the
        // "escalations" key (list-endpoint wrapping), flattened across pages by the client.
        $client = $this->clientReturning([
            new Response(200, [], json_encode([
                'escalations' => [['id' => 1, 'status' => 'sent'], ['id' => 2, 'status' => 'resolved']],
                'pagination' => ['next_page_token' => 'abc'],
            ])),
            new Response(200, [], json_encode([
                'escalations' => [['id' => 3, 'status' => 'resolved']],
                'pagination' => ['next_page_token' => null],
            ])),
        ]);

        $result = $client->getEscalations(['organization_id' => 42]);

        $this->assertCount(3, $result);
        $this->assertSame([1, 2, 3], array_column($result, 'id'));
        $this->assertSame('/v1/escalations', $this->lastPath());
        $this->assertCount(2, $this->history, 'both pages should have been fetched');
    }

    public function test_a_429_is_retried_after_backoff_then_succeeds(): void
    {
        // Retry-After: 0 keeps the test instant while still exercising the retry loop.
        $client = $this->clientReturning([
            new Response(429, ['Retry-After' => '0'], ''),
            new Response(200, [], json_encode(['id' => 5, 'status' => 'sent'])),
        ]);

        $result = $client->getEscalation(5);

        $this->assertSame(5, $result['id']);
        $this->assertCount(2, $this->history, 'the 429 should have been retried exactly once');
    }

    /**
     * The DEFAULT client honours the upstream Retry-After IN FULL. Its
     * callers — huntress:sync-licenses, huntress:reconcile-*, the read-only
     * MCP tools — hold no execution claim, and against the documented
     * 60 req/min account limit obeying a `Retry-After: 60` is correct: a
     * clamp here burns all three attempts in ~20 s and fails that day's
     * licence sync instead of waiting out one rate-limit window.
     */
    public function test_the_default_backoff_honours_the_upstream_retry_after_in_full(): void
    {
        $this->assertSame(60, HuntressClient::retryDelaySeconds('60', 1), 'a background reader must wait out a full rate-limit window, not fail the sync');
        $this->assertSame(1200, HuntressClient::retryDelaySeconds('1200', 1));
        $this->assertSame(0, HuntressClient::retryDelaySeconds('0', 1), 'zero means retry immediately');
        $this->assertSame(2, HuntressClient::retryDelaySeconds('', 1), 'no header → exponential default');
        $this->assertSame(4, HuntressClient::retryDelaySeconds('', 2));
        $this->assertSame(4, HuntressClient::retryDelaySeconds('soon', 2), 'non-numeric header → exponential default');
        $this->assertSame(2, HuntressClient::retryDelaySeconds('-1', 1), 'negative header → exponential default, matching the write client');
        $this->assertSame(4, HuntressClient::retryDelaySeconds('-5', 2, true), 'negative header falls back before the clamp is considered');
    }

    /**
     * Retry-After is UPSTREAM-CONTROLLED, and one caller sleeps on it while
     * holding a run's execution claim: the staged escalation-resolve approval
     * re-reads LIVE inside the synchronous cockpit request. THAT caller opts
     * in via withClampedBackoff(), and only its clone clamps — an unclamped
     * `Retry-After: 1200` would park the worker, and the claim, past
     * StaffHuntressActionToolExecutor::STALE_CLAIM_SECONDS, whose bound is
     * measured against exactly this clamp.
     */
    public function test_retry_delay_clamps_only_when_the_clamped_mode_is_requested(): void
    {
        $ceiling = HuntressClient::RETRY_AFTER_CEILING_SECONDS;

        $this->assertSame($ceiling, HuntressClient::retryDelaySeconds('1200', 1, true), 'a twenty-minute upstream header must clamp to the ceiling');
        $this->assertSame($ceiling, HuntressClient::retryDelaySeconds((string) ($ceiling + 1), 2, true));
        $this->assertSame(3, HuntressClient::retryDelaySeconds('3', 1, true), 'a sane header value is honoured');
        $this->assertSame(0, HuntressClient::retryDelaySeconds('0', 1, true), 'zero means retry immediately');
        $this->assertSame(2, HuntressClient::retryDelaySeconds('', 1, true), 'exponential default sits under the ceiling');
        $this->assertSame(4, HuntressClient::retryDelaySeconds('soon', 2, true));
    }

    public function test_with_clamped_backoff_returns_a_clamped_clone_and_leaves_the_receiver_unclamped(): void
    {
        $ceiling = HuntressClient::RETRY_AFTER_CEILING_SECONDS;
        $client = $this->clientReturning([]);
        $clamped = $client->withClampedBackoff();

        $this->assertNotSame($client, $clamped, 'the clamp arrives on a clone, never by mutating the shared instance');
        $this->assertSame($ceiling, $clamped->backoffDelaySeconds('1200', 1));
        $this->assertSame(1200, $client->backoffDelaySeconds('1200', 1), 'the receiver keeps the full upstream Retry-After after the wither');
    }
}
