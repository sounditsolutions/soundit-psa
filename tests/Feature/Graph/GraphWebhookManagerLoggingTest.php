<?php

namespace Tests\Feature\Graph;

use App\Models\Setting;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphTokenRefreshFailedException;
use App\Services\Graph\GraphWebhookManager;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FlattensLogContext;
use Tests\TestCase;

/**
 * #5677 / C-56: GraphWebhookManager's create, renew, delete and renew-then-create catches log
 * the HTTP status and the exception class only, never the configured mailbox or the
 * GraphClientException message. The subscription id is kept.
 *
 * G-5: GraphClient runs over a scripted MockHandler, Http::preventStrayRequests() is on, and
 * every value is synthetic (G-13). Records are read from MessageLogged, which sees every level
 * and every channel written through Laravel's logger.
 *
 * #5733 / #5737: the 'any record' scan reads each context through FlattensLogContext, not
 * json_encode(): a 'users/' value is matched unescaped and a Throwable in context is rendered
 * (class, message, chain). test_the_any_record_scan_fails_on_a_planted_record shows both
 * plants turn the scan red.
 */
class GraphWebhookManagerLoggingTest extends TestCase
{
    use FlattensLogContext;
    use RefreshDatabase;

    private const MAILBOX = 'support@example.test';

    private const SUBSCRIPTION_ID = 'SUB-b4j-synthetic-1';

    private const GRAPH_ERROR_TEXT = 'B4J-SYNTHETIC-SUBSCRIPTION-ERROR';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->logged[] = $m;
        });
        Setting::setValue('graph_mailbox', self::MAILBOX);
    }

    private function manager(Response ...$responses): GraphWebhookManager
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'b4j-synthetic-seeded-access', 3600);

        return new GraphWebhookManager(new GraphClient([
            'tenant_id' => 'tenant-b4j-synthetic',
            'client_id' => 'b4j-client',
            'client_secret' => 'b4j-synthetic-secret-not-real',
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $stack,
        ], $cache));
    }

    private static function graphError(int $status): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => ['code' => 'ExtensionError', 'message' => self::GRAPH_ERROR_TEXT.' for '.self::MAILBOX],
        ]));
    }

    private static function subscription(): Response
    {
        return new Response(201, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => self::SUBSCRIPTION_ID, 'expirationDateTime' => '2026-10-10T00:00:00Z',
        ]));
    }

    /** @return list<string> */
    private static function needles(): array
    {
        return [self::MAILBOX, rawurlencode(self::MAILBOX), 'support@', 'users/', self::GRAPH_ERROR_TEXT, 'Graph API error', 'subscriptions'];
    }

    /** @return list<string> the needles $text carries */
    private static function carried(string $text): array
    {
        return array_values(array_filter(self::needles(), fn (string $n) => str_contains($text, $n)));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> GraphWebhook records by message */
    private function webhookRecords(): array
    {
        $out = [];
        foreach ($this->logged as $m) {
            if (str_starts_with($m->message, '[GraphWebhook]')) {
                $out[$m->message] = [$m->level, $m->context];
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: list<string>}> [level message, needles] for each record that carries one */
    private function recordsCarryingANeedle(): array
    {
        $out = [];
        foreach ($this->logged as $m) {
            $carried = self::carried($m->message.' '.self::flattenForScan($m->context));
            if ($carried !== []) {
                $out[] = ["{$m->level} {$m->message}", $carried];
            }
        }

        return $out;
    }

    private function assertNoNeedleInAnyRecord(): void
    {
        $this->assertSame([], $this->recordsCarryingANeedle());
    }

    public function test_a_failed_create_logs_status_and_class_only(): void
    {
        $manager = $this->manager(self::graphError(400));
        try {
            $manager->createSubscription();
            $this->fail('no throw');
        } catch (GraphClientException $e) {
            // Positive control: the failure did carry the mailbox (in Graph's body).
            $this->assertContains(self::MAILBOX, self::carried(json_encode($e->getResponseBody()) ?: ''));
        }
        $this->assertStringStartsWith('users/'.self::MAILBOX.'/', json_decode((string) $this->history[0]['request']->getBody(), true)['resource'] ?? '', 'positive control: the mailbox was sent');

        $this->assertSame(
            ['error', ['status' => 400, 'exception' => GraphClientException::class]],
            $this->webhookRecords()['[GraphWebhook] Failed to create subscription'] ?? null,
        );
        $this->assertNoNeedleInAnyRecord();
    }

    public function test_a_failed_renew_logs_the_subscription_id_status_and_class_only(): void
    {
        $manager = $this->manager(self::graphError(404));
        try {
            $manager->renewSubscription(self::SUBSCRIPTION_ID);
            $this->fail('no throw');
        } catch (GraphClientException) {
        }

        $this->assertSame(
            ['error', ['subscription_id' => self::SUBSCRIPTION_ID, 'status' => 404, 'exception' => GraphClientException::class]],
            $this->webhookRecords()['[GraphWebhook] Failed to renew subscription'] ?? null,
        );
        $this->assertNoNeedleInAnyRecord();
    }

    public function test_a_failed_delete_logs_the_subscription_id_status_and_class_only(): void
    {
        Setting::setValue('graph_subscription_id', self::SUBSCRIPTION_ID);
        $this->manager(self::graphError(500))->deleteSubscription();

        $this->assertSame(
            ['warning', ['subscription_id' => self::SUBSCRIPTION_ID, 'status' => 500, 'exception' => GraphClientException::class]],
            $this->webhookRecords()['[GraphWebhook] Failed to delete subscription'] ?? null,
        );
        $this->assertNull(Setting::getValue('graph_subscription_id'), 'the delete still clears the settings');
        $this->assertNoNeedleInAnyRecord();
    }

    /** The renewal-fallback warning in ensureSubscription(), and the class of a refresh failure. */
    public function test_a_failed_renewal_that_falls_back_to_create_logs_status_and_class_only(): void
    {
        Setting::setValue('graph_subscription_id', self::SUBSCRIPTION_ID);
        Setting::setValue('graph_subscription_expiry', now()->addHour()->toIso8601String());

        $this->manager(
            new Response(401, [], '{}'),
            new Response(200, ['Content-Type' => 'application/json'], '{}'),
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['access_token' => 'b4j-synthetic-issued-access', 'expires_in' => 3600])),
            self::subscription(),
        )->ensureSubscription();

        $records = $this->webhookRecords();
        $this->assertSame(
            ['warning', ['status' => 401, 'exception' => GraphTokenRefreshFailedException::class]],
            $records['[GraphWebhook] Renewal failed, creating new subscription'] ?? null,
        );
        $this->assertArrayHasKey('[GraphWebhook] Subscription created', $records, 'positive control: the fallback create ran');
        $this->assertNoNeedleInAnyRecord();
    }

    /** Control: the scan fires on the record shape #5677 removed. */
    public function test_the_needle_scan_fires_on_the_old_record_shape(): void
    {
        $old = json_encode(['mailbox' => self::MAILBOX, 'error' => 'Graph API error: POST subscriptions returned 400']);
        $this->assertContains(self::MAILBOX, self::carried((string) $old));
        $this->assertContains('Graph API error', self::carried((string) $old));
    }

    /**
     * #5733 / #5737 positive control: after a real failing create, a planted record with the
     * endpoint in its context, or with a Guzzle exception object in its context, turns the
     * 'any record' scan red. json_encode() sees neither: it escapes the slash, and it renders
     * the exception as {}.
     */
    public function test_the_any_record_scan_fails_on_a_planted_record(): void
    {
        $manager = $this->manager(self::graphError(400));
        try {
            $manager->createSubscription();
        } catch (GraphClientException) {
        }
        $this->assertSame([], $this->recordsCarryingANeedle(), 'clean before the plant');
        $real = count($this->logged);

        $endpoint = ['method' => 'POST', 'status' => 400, 'endpoint' => 'users/'.self::SUBSCRIPTION_ID.'/x'];
        $thrown = ['method' => 'POST', 'status' => 0, 'error' => new \GuzzleHttp\Exception\ConnectException(
            'refused for https://example.test/v1.0/users/'.self::SUBSCRIPTION_ID, new \GuzzleHttp\Psr7\Request('POST', 'subscriptions'),
        )];
        foreach (['endpoint in context' => $endpoint, 'Throwable in context' => $thrown] as $name => $context) {
            $this->assertNotContains('users/', self::carried((string) json_encode($context)), "{$name}: json_encode cannot see it");
            $this->logged = array_slice($this->logged, 0, $real);
            $this->logged[] = new MessageLogged('error', 'Graph API request failed', $context);
            $this->assertSame([['error Graph API request failed', ['users/']]], $this->recordsCarryingANeedle(), $name);
        }
    }
}
