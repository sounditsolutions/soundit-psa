<?php

namespace Tests\Feature\PandaDoc;

use App\Services\PandaDoc\PandaDocClient;
use App\Services\PandaDoc\PandaDocClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class PandaDocClientTest extends TestCase
{
    /** @var array<int, RequestInterface> */
    private array $recorded = [];

    /**
     * Build a PandaDocClient backed by a queue of canned responses, recording
     * every outbound request for assertion.
     */
    private function client(Response ...$responses): PandaDocClient
    {
        $this->recorded = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->recorded));

        $http = new GuzzleClient([
            'base_uri' => 'https://api.pandadoc.com',
            'handler' => $stack,
        ]);

        return new PandaDocClient($http);
    }

    public function test_create_document_posts_json_and_returns_parsed_body(): void
    {
        $client = $this->client(new Response(201, [], json_encode([
            'id' => 'DOC123',
            'name' => 'MSA',
            'status' => 'document.uploaded',
        ])));

        $result = $client->createDocument(['name' => 'MSA', 'template_uuid' => 'TPL1']);

        $this->assertSame('DOC123', $result['id']);

        $request = $this->recorded[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/public/v1/documents', $request->getUri()->getPath());
        $this->assertSame(['name' => 'MSA', 'template_uuid' => 'TPL1'], json_decode((string) $request->getBody(), true));
    }

    public function test_get_document_uses_the_document_path(): void
    {
        $client = $this->client(new Response(200, [], json_encode(['id' => 'DOC123', 'status' => 'document.draft'])));

        $client->getDocument('DOC123');

        $request = $this->recorded[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/public/v1/documents/DOC123', $request->getUri()->getPath());
    }

    public function test_send_document_posts_to_send_endpoint(): void
    {
        $client = $this->client(new Response(200, [], json_encode(['status' => 'document.sent'])));

        $client->sendDocument('DOC123', ['silent' => false]);

        $request = $this->recorded[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/public/v1/documents/DOC123/send', $request->getUri()->getPath());
    }

    public function test_download_document_returns_raw_bytes(): void
    {
        $client = $this->client(new Response(200, [], '%PDF-1.7 signed bytes'));

        $bytes = $client->downloadDocument('DOC123');

        $this->assertSame('%PDF-1.7 signed bytes', $bytes);
        $this->assertSame('/public/v1/documents/DOC123/download', $this->recorded[0]['request']->getUri()->getPath());
    }

    public function test_is_healthy_true_on_success_false_on_error(): void
    {
        $this->assertTrue($this->client(new Response(200, [], json_encode(['results' => []])))->isHealthy());
        $this->assertFalse($this->client(new Response(500, [], 'boom'))->isHealthy());
    }

    public function test_api_error_throws_pandadoc_exception(): void
    {
        $this->expectException(PandaDocClientException::class);

        $this->client(new Response(400, [], 'bad request'))->createDocument([]);
    }
}
