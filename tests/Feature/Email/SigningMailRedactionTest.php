<?php

namespace Tests\Feature\Email;

use App\Models\Setting;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SigningMailRedactionTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $handler = HandlerStack::create(new MockHandler([new Response(202)]));
        $handler->push(Middleware::history($this->history));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'synthetic-test-token', 3600);
        $this->app->instance(GraphClient::class, new GraphClient([
            'request_timeout' => 1,
            'token_timeout' => 1,
            'handler' => $handler,
        ], $cache));
        Setting::updateOrCreate(['key' => 'graph_mailbox'], ['value' => 'sender@example.test']);
    }

    public function test_redaction_affects_only_persisted_content_and_precedes_preview_truncation(): void
    {
        $token = str_repeat('A', 48);
        $link = 'https://example.test/sign/'.$token;
        $body = str_repeat('x', 475)."\n".$link;
        $email = app(EmailService::class)->sendNew('recipient@example.test', 'DRAFT — Review', $body, redact: [$link]);
        $this->assertCount(1, $this->history);
        $payload = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertStringContainsString($link, $payload['message']['body']['content']);
        $email->refresh();
        foreach (['body_text', 'body_html', 'body_preview'] as $column) {
            $this->assertStringNotContainsString($token, $email->$column);
            $this->assertStringContainsString('[signing link]', $email->$column);
        }
        $this->assertStringNotContainsString($token, $email->toJson());
        $this->assertSame(mb_substr(str_replace($link, '[signing link]', $body), 0, 500), $email->body_preview);
    }

    public function test_default_empty_redaction_preserves_original_bodies(): void
    {
        $body = 'DRAFT — Review https://example.test/sign/'.str_repeat('B', 48);
        $email = app(EmailService::class)->sendNew('recipient@example.test', 'DRAFT — Review', $body);
        $payload = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame($body, $email->body_text);
        $this->assertSame(mb_substr($body, 0, 500), $email->body_preview);
        $this->assertSame($payload['message']['body']['content'], $email->body_html);
    }
}
