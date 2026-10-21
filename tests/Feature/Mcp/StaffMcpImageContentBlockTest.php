<?php

namespace Tests\Feature\Mcp;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Graph\GraphClient;
use App\Support\McpConfig;
use App\Support\TeamsPersonaConfig;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Card 6ad8e6a1: get_ticket_attachment and get_teams_message_attachment return
 * an image as a native MCP image content block ({type:'image', data, mimeType})
 * after the JSON text block, so an MCP client sees a picture rather than a
 * base64 string. When the block is present the bytes are not repeated in the
 * text (data_base64 is dropped, image_block:true says where they went).
 * Non-image files, errors and the size ceilings are unchanged.
 *
 * Every call goes through the real staff MCP route. Tokens are granted only
 * the attachment tool, so no Alerts-Hub nudge block (which needs poll_signals)
 * can join the content list. All data is synthetic.
 */
class StaffMcpImageContentBlockTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '19:synthetic-operator@thread.v2';

    private const MSG = '1700000000001';

    private const HOSTED = 'aWQ9eF9zeW50aGV0aWMtaW1hZ2UtMSx0eXBlPTE=';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        TeamsPersonaConfig::flush();
        Setting::setValue('teams_bot_enabled', '0');
        Setting::setValue('teams_chet_routing_enabled', '1');
        Setting::setValue('teams_bot_app_id', 'synthetic-bot-app');
        Setting::setValue('teams_bot_tenant_id', 'synthetic-tenant');
        Setting::setValue('teams_chet_conversation_id', self::CHAT);
    }

    public function test_a_ticket_image_returns_a_native_image_block_after_the_text_block(): void
    {
        [$ticket, $att] = $this->ticketWithAttachment('shot.png', 'image/png', $this->png(4, 4));

        $r = $this->ticketAttachment($ticket, $att->id);

        $r->assertOk();
        $this->assertFalse((bool) $r->json('result.isError'), (string) $r->json('result.content.0.text'));
        $content = $r->json('result.content');
        $this->assertCount(2, $content);
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame(['type', 'data', 'mimeType'], array_keys($content[1]));
        $this->assertSame('image', $content[1]['type']);
        $this->assertSame('image/png', $content[1]['mimeType']);
        $bytes = base64_decode($content[1]['data'], true);
        $this->assertNotFalse($bytes);
        $this->assertSame([4, 4, IMAGETYPE_PNG], array_slice((array) getimagesizefromstring($bytes), 0, 3));

        $text = json_decode($content[0]['text'], true);
        $this->assertArrayNotHasKey('data_base64', $text, 'the bytes are not sent twice');
        $this->assertTrue($text['image_block']);
        $this->assertSame([
            'attachment_id' => $att->id,
            'filename' => 'shot.png',
            'media_type' => 'image/png',
            'is_image' => true,
            'image_block' => true,
        ], $text);
    }

    public function test_a_ticket_pdf_returns_only_the_text_block_with_its_base64(): void
    {
        $body = "%PDF-1.4 synthetic bytes\n";
        [$ticket, $att] = $this->ticketWithAttachment('report.pdf', 'application/pdf', $body);

        $r = $this->ticketAttachment($ticket, $att->id);

        $r->assertOk();
        $this->assertFalse((bool) $r->json('result.isError'));
        $content = $r->json('result.content');
        $this->assertCount(1, $content);
        $this->assertSame('text', $content[0]['type']);
        $this->assertNotContains('image', array_column($content, 'type'));

        $text = json_decode($content[0]['text'], true);
        $this->assertFalse($text['is_image']);
        $this->assertSame('application/pdf', $text['media_type']);
        $this->assertSame($body, base64_decode($text['data_base64'], true));
        $this->assertArrayNotHasKey('image_block', $text);
    }

    /**
     * An image/* file the vision path does not serve (Attachment::isImage() is false for
     * TIFF) comes back as raw guarded base64 with is_image false and an image/ media type;
     * is_image, not the media type, is what keeps it a text-only result.
     */
    public function test_a_ticket_image_file_outside_the_vision_types_returns_only_the_text_block(): void
    {
        $body = "II*\0synthetic tiff bytes";
        [$ticket, $att] = $this->ticketWithAttachment('scan.tiff', 'image/tiff', $body);

        $r = $this->ticketAttachment($ticket, $att->id);

        $r->assertOk();
        $this->assertFalse((bool) $r->json('result.isError'));
        $content = $r->json('result.content');
        $this->assertSame(['text'], array_column($content, 'type'));

        $text = json_decode($content[0]['text'], true);
        $this->assertFalse($text['is_image']);
        $this->assertSame('image/tiff', $text['media_type']);
        $this->assertSame($body, base64_decode($text['data_base64'], true));
        $this->assertArrayNotHasKey('image_block', $text);
    }

    public function test_an_oversize_ticket_image_still_hits_the_ceiling_with_no_image_block(): void
    {
        $oversize = $this->png(4, 4).str_repeat("\0", AssistantToolExecutor::MAX_ATTACHMENT_BYTES);
        [$ticket, $att] = $this->ticketWithAttachment('big.png', 'image/png', $oversize);

        $r = $this->ticketAttachment($ticket, $att->id);

        $r->assertOk();
        $this->assertTrue((bool) $r->json('result.isError'));
        $content = $r->json('result.content');
        $this->assertSame(['text'], array_column($content, 'type'));
        $this->assertSame(
            ['error' => 'Attachment is too large to return inline ('.strlen($oversize).' bytes, limit '.AssistantToolExecutor::MAX_ATTACHMENT_BYTES.'); open it in the PSA UI.'],
            json_decode($content[0]['text'], true),
        );
    }

    public function test_a_teams_image_returns_a_native_image_block_with_the_served_mime_type(): void
    {
        $this->graph(
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($this->imageOnlyMessage())),
            new Response(200, [], $this->png(5, 3)),
        );
        $token = McpConfig::rotateStaffToken(allowedTools: ['get_teams_message_attachment'], label: 'chet');

        $r = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_teams_message_attachment', 'arguments' => [
                'chat_id' => 'operator', 'message_id' => self::MSG, 'attachment_id' => 'inline-1',
            ]],
        ]);

        $r->assertOk();
        $this->assertFalse((bool) $r->json('result.isError'), (string) $r->json('result.content.0.text'));
        $content = $r->json('result.content');
        $this->assertSame(['text', 'image'], array_column($content, 'type'));
        $text = json_decode($content[0]['text'], true);
        $this->assertSame('image/png', $text['media_type']);
        $this->assertSame($text['media_type'], $content[1]['mimeType']);
        $this->assertArrayNotHasKey('data_base64', $text);
        $this->assertTrue($text['image_block']);
        $this->assertSame([5, 3, IMAGETYPE_PNG], array_slice((array) getimagesizefromstring((string) base64_decode($content[1]['data'], true)), 0, 3));
        $this->assertCount(3, $this->history, 'token, message and hosted-content reads only');
    }

    public function test_an_error_result_carries_no_image_block(): void
    {
        $client = Client::factory()->create();
        $ticketA = Ticket::factory()->create(['client_id' => $client->id]);
        [, $att] = $this->ticketWithAttachment('shot.png', 'image/png', $this->png(4, 4), $client);

        $r = $this->ticketAttachment($ticketA, $att->id);

        $r->assertOk();
        $this->assertTrue((bool) $r->json('result.isError'));
        $content = $r->json('result.content');
        $this->assertSame(['text'], array_column($content, 'type'));
        $this->assertSame(['error' => 'Attachment not found on this ticket'], json_decode($content[0]['text'], true));
    }

    // ── helpers ──

    /** @return array{0: Ticket, 1: Attachment} */
    private function ticketWithAttachment(string $name, string $mime, string $bytes, ?Client $client = null): array
    {
        $client ??= Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $att = Attachment::create([
            'filename' => $name,
            'original_filename' => $name,
            'mime_type' => $mime,
            'size_bytes' => strlen($bytes),
            'storage_path' => 'attachments/tmp',
            'attachable_type' => Ticket::class,
            'attachable_id' => $ticket->id,
        ]);
        $path = "attachments/{$att->id}/{$name}";
        Storage::disk('local')->put($path, $bytes);
        $att->update(['storage_path' => $path]);

        return [$ticket, $att];
    }

    private function ticketAttachment(Ticket $ticket, int $attachmentId): TestResponse
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['get_ticket_attachment']);

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_ticket_attachment', 'arguments' => [
                'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'attachment_id' => $attachmentId,
            ]],
        ]);
    }

    private function graph(Response ...$responses): void
    {
        $this->history = [];
        cache()->store('array')->flush();
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode(['access_token' => 'synthetic-access', 'expires_in' => 3600])),
            ...$responses,
        ]));
        $stack->push(Middleware::history($this->history));

        $this->app->instance(GraphClient::class, new GraphClient([
            'tenant_id' => 'synthetic-tenant',
            'client_id' => 'synthetic-client',
            'client_secret' => 'synthetic-not-a-real-value',
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $stack,
        ], cache()->store('array')));
    }

    private function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function imageOnlyMessage(): array
    {
        return [
            'id' => self::MSG,
            'messageType' => 'message',
            'createdDateTime' => '2026-10-02T15:12:00Z',
            'from' => ['user' => ['id' => 'synthetic-user', 'displayName' => 'Synthetic Operator']],
            'body' => ['contentType' => 'html', 'content' => '<div><img src="https://graph.microsoft.com/v1.0/chats/'.self::CHAT
                .'/messages/'.self::MSG.'/hostedContents/'.self::HOSTED.'/$value"></div>'],
            'attachments' => [],
        ];
    }
}
