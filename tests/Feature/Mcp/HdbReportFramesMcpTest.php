<?php

namespace Tests\Feature\Mcp;

use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Mcp\HdbReportTool;
use App\Support\HdbApiConfig;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Hdb\HdbApiReportClientTest;
use Tests\Feature\Hdb\HdbSyntheticSprite;
use Tests\TestCase;

/**
 * Card 6Xiehny3 at the MCP response: the real staff route, tool, reader and
 * report client, with only HTTP faked and synthetic sprites. The frames
 * arrive as native image blocks after the text, oldest first, and the text
 * carries one line per frame and no image bytes.
 */
class HdbReportFramesMcpTest extends TestCase
{
    use RefreshDatabase;

    // Deliberately synthetic; never an id copied from a client ticket.
    private const PRESS = 'deadbeef-0000-4000-8000-0000000000d7';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        Http::preventStrayRequests();
    }

    /** @return array{0: TestResponse, 1: array<string, mixed>} */
    private function getReport(?string $sprite, array $overrides = []): array
    {
        $ticket = Ticket::factory()->create(['source' => TicketSource::HelpdeskButton->value, 'client_id' => Client::factory()->create()->id]);
        $note = TicketNote::create(['ticket_id' => $ticket->id, 'note_type' => NoteType::System, 'body' => 'A synthetic note.', 'is_private' => true, 'noted_at' => now()]);
        TicketNote::whereKey($note->id)->toBase()->update(['hdb_press_id' => self::PRESS]);
        $data = HdbApiReportClientTest::fixture();
        $data['ticket_id'] = (string) $ticket->id;
        $data['instant_replay']['sprite'] = '/synthetic';
        $data = array_replace_recursive($data, $overrides);
        Http::fake(function (Request $request) use ($data, $sprite) {
            return match ($request->url()) {
                HdbApiConfig::BASE_URL.'/reports/'.self::PRESS => Http::response($data),
                HdbApiConfig::BASE_URL.'/reports/'.self::PRESS.'/instant-replay/sprite' => Http::response($sprite),
                default => throw new \LogicException('Unexpected synthetic request'),
            };
        });
        $token = McpConfig::rotateStaffToken(allowedTools: [HdbReportTool::NAME], label: 'synthetic-agent');
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => HdbReportTool::NAME, 'arguments' => ['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]],
        ]);
        $response->assertOk();

        return [$response, json_decode((string) $response->json('result.content.0.text'), true)];
    }

    /** @return list<array<string, mixed>> */
    private function imageBlocks(TestResponse $response): array
    {
        return array_values(array_filter($response->json('result.content'), static fn (array $block) => $block['type'] === 'image'));
    }

    public function test_a_sprite_over_five_mb_returns_three_image_blocks_oldest_first_and_no_base64_in_the_text(): void
    {
        // Over 5 MB and over 30 MP; the newest three positions are the last three.
        $positions = [[10000, 0], [12000, 0], [14000, 0], [0, 1000], [2000, 1000], [4000, 1000]];
        $sprite = HdbSyntheticSprite::png(16000, 2000, $positions, HdbSyntheticSprite::layout(2000, 1000, $positions), 3000, 32, 1);
        $this->assertGreaterThan(AssistantToolExecutor::MAX_ATTACHMENT_BYTES, strlen($sprite));

        [$response, $out] = $this->getReport($sprite);

        $this->assertFalse($response->json('result.isError'));
        $this->assertSame('fetched', $out['status']);
        $this->assertArrayNotHasKey('screenshot_note', $out);
        $this->assertSame(['frame 4 of 6, 1414x707 px', 'frame 5 of 6, 1414x707 px', 'frame 6 of 6, 1414x707 px'], array_column($out['screenshot']['frames'], 'frame'));
        $this->assertSame([['frame'], ['frame'], ['frame']], array_map('array_keys', $out['screenshot']['frames']));
        $this->assertSame(3, $out['screenshot']['image_blocks']);
        $this->assertStringNotContainsString('data_base64', (string) $response->json('result.content.0.text'));

        $blocks = $this->imageBlocks($response);
        $this->assertCount(3, $blocks);
        $this->assertSame('text', $response->json('result.content.0.type'));
        foreach ($blocks as $i => $block) {
            $this->assertSame('image/png', $block['mimeType']);
            $png = (string) base64_decode($block['data'], true);
            $size = getimagesizefromstring($png);
            $this->assertLessThanOrEqual(1568, max($size[0], $size[1]));
            $this->assertSame(HdbSyntheticSprite::marker(3 + $i), imagecolorat(imagecreatefromstring($png), 2, 2), "block {$i} is position ".(3 + $i));
        }
    }

    public function test_a_sprite_without_a_layout_within_the_limits_is_one_image_block(): void
    {
        [$response, $out] = $this->getReport(HdbSyntheticSprite::png(640, 360));

        $blocks = $this->imageBlocks($response);
        $this->assertCount(1, $blocks);
        $this->assertSame([640, 360], array_slice(getimagesizefromstring((string) base64_decode($blocks[0]['data'], true)), 0, 2));
        $this->assertArrayNotHasKey('data_base64', $out['screenshot']);
        $this->assertArrayNotHasKey('frames', $out['screenshot']);
        $this->assertSame(1, $out['screenshot']['image_blocks']);
    }

    public function test_an_omitted_sprite_names_its_reason_bytes_and_size_and_sends_no_image_block(): void
    {
        $sprite = HdbSyntheticSprite::png(2000, 1200, [], null, 6000, 16, 1);

        [$response, $out] = $this->getReport($sprite);

        $this->assertSame([], $this->imageBlocks($response));
        $this->assertNull($out['screenshot']);
        $this->assertSame('oversize', $out['screenshot_note']);
        $this->assertStringContainsString(strlen($sprite).' bytes, 2000x1200 px', $out['screenshot_detail']);
        $this->assertSame('fetched', $out['status']);
        $this->assertIsArray($out['report']);
    }

    public function test_a_redacted_sprite_sends_no_image_block_and_no_detail(): void
    {
        [$response, $out] = $this->getReport(null, ['instant_replay' => ['redacted' => true]]);

        $this->assertSame([], $this->imageBlocks($response));
        $this->assertSame('redacted', $out['screenshot_note']);
        $this->assertArrayNotHasKey('screenshot_detail', $out);
        Http::assertSentCount(1);
    }

    public function test_the_description_says_the_frames_arrive_as_image_blocks_with_one_line_each(): void
    {
        $description = HdbReportTool::definition()['description'];

        $this->assertStringContainsString('image content blocks', $description);
        $this->assertStringContainsString('newest 3 frames', $description);
        $this->assertStringContainsString('oldest first', $description);
        $this->assertStringContainsString('"frame k of N, W x H px"', $description);
        $this->assertStringNotContainsString('data_base64', $description);
    }
}
