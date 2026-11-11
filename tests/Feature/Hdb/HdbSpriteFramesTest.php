<?php

namespace Tests\Feature\Hdb;

use App\Models\Setting;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Hdb\HdbApiReportClient;
use App\Services\Hdb\HdbReportResult;
use App\Support\HdbApiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Card 6Xiehny3: the newest Instant Replay frames, cropped from the sprite's
 * `hdbc` layout chunk by streaming, through the real report client with only
 * HTTP faked. Every sprite is synthetic (HdbSyntheticSprite).
 */
class HdbSpriteFramesTest extends TestCase
{
    use RefreshDatabase;

    private const ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
    }

    private function report(string $sprite): HdbReportResult
    {
        $data = HdbApiReportClientTest::fixture();
        $data['instant_replay']['sprite'] = '/synthetic';
        Http::fake([
            HdbApiConfig::BASE_URL.'/reports/'.self::ID => Http::response($data),
            HdbApiConfig::BASE_URL.'/reports/'.self::ID.'/instant-replay/sprite' => Http::response($sprite),
        ]);

        return (new HdbApiReportClient)->report(self::ID, 17);
    }

    /** Position k's marker colour at a frame's top-left, after any downscale. */
    private function assertFrameFrom(int $k, string $png, string $message = ''): void
    {
        $image = imagecreatefromstring($png);
        $this->assertNotFalse($image);
        $this->assertSame(HdbSyntheticSprite::marker($k), imagecolorat($image, 2, 2), $message ?: "frame cropped from position {$k}");
    }

    public function test_a_sprite_over_five_mb_and_thirty_mp_returns_the_newest_three_frames_oldest_first(): void
    {
        // 16000 x 2000 = 32 MP; noise rows push the file over 5 MB. The
        // oldest three positions sit at the far right of row 0 and the newest
        // three at the left of row 1, so returning the first three, or the
        // rightmost, would show the wrong frames.
        $positions = [[10000, 0], [12000, 0], [14000, 0], [0, 1000], [2000, 1000], [4000, 1000]];
        $sprite = HdbSyntheticSprite::png(16000, 2000, $positions, HdbSyntheticSprite::layout(2000, 1000, $positions), 3000, 32, 1);
        $this->assertGreaterThan(AssistantToolExecutor::MAX_IMAGE_PIXELS, 16000 * 2000);
        $this->assertGreaterThan(AssistantToolExecutor::MAX_ATTACHMENT_BYTES, strlen($sprite));
        $this->mock(\App\Services\AttachmentService::class)->shouldNotReceive('resizeImageBytesForAi');

        $result = $this->report($sprite);

        $this->assertNull($result->spriteReason, (string) $result->spriteDetail);
        $this->assertNull($result->screenshot);
        $this->assertCount(3, $result->frames);
        foreach ($result->frames as $i => $frame) {
            // 2000 x 1000 scaled to the 1 MP output bound: 1414 x 707 (longest side <= 1568).
            $this->assertSame([4 + $i, 6, 1414, 707], [$frame['index'], $frame['total'], $frame['width'], $frame['height']]);
            $size = getimagesizefromstring($frame['png']);
            $this->assertSame([1414, 707, 'image/png'], [$size[0], $size[1], $size['mime']]);
            $this->assertFrameFrom(3 + $i, $frame['png']);
        }
    }

    public static function fewerFrames(): array
    {
        return ['one position' => [[[40, 30]]], 'two positions' => [[[200, 0], [0, 60]]]];
    }

    /** @param list<array{int, int}> $positions */
    #[DataProvider('fewerFrames')]
    public function test_fewer_than_three_positions_return_what_exists_in_order(array $positions): void
    {
        $sprite = HdbSyntheticSprite::png(300, 120, $positions, HdbSyntheticSprite::layout(100, 60, $positions));

        $result = $this->report($sprite);

        $this->assertCount(count($positions), $result->frames);
        foreach ($result->frames as $k => $frame) {
            $this->assertSame([$k + 1, count($positions), 100, 60], [$frame['index'], $frame['total'], $frame['width'], $frame['height']]);
            $this->assertFrameFrom($k, $frame['png']);
        }
    }

    /**
     * The band unfilter carries the last row of each band into the next;
     * every PNG filter type (0-4) appears, and a frame spans several bands.
     * The crop must equal a whole-image GD decode pixel for pixel.
     */
    public function test_frames_equal_a_whole_image_decode_pixel_for_pixel_across_bands_and_filter_types(): void
    {
        $positions = [[0, 0], [500, 150], [1000, 300]];
        $sprite = HdbSyntheticSprite::png(1500, 450, $positions, HdbSyntheticSprite::layout(500, 150, $positions), 600);
        $whole = imagecreatefromstring($sprite);

        $result = $this->report($sprite);

        $this->assertCount(3, $result->frames);
        foreach ($positions as $k => [$left, $top]) {
            $frame = imagecreatefromstring($result->frames[$k]['png']);
            $diffs = 0;
            for ($y = 0; $y < 150; $y++) {
                for ($x = 0; $x < 500; $x++) {
                    $diffs += imagecolorat($frame, $x, $y) === imagecolorat($whole, $left + $x, $top + $y) ? 0 : 1;
                }
            }
            $this->assertSame(0, $diffs, "frame {$k}");
        }
    }

    public static function badLayouts(): array
    {
        $ok = HdbSyntheticSprite::layout(100, 60, [[0, 0]]);

        return [
            'not JSON' => ['{frame_width:', [], 'layout_invalid', 'not the expected JSON'],
            'positions missing' => [['frame_width' => 100, 'frame_height' => 60], [], 'layout_invalid', 'not the expected JSON'],
            'size as string' => [['frame_width' => '100'] + $ok, [], 'layout_invalid', 'not the expected JSON'],
            'zero width' => [['frame_width' => 0] + $ok, [], 'layout_invalid', 'frame size of 0x60'],
            'negative height' => [['frame_height' => -60] + $ok, [], 'layout_invalid', 'frame size of 100x-60'],
            'no positions' => [['positions' => []] + $ok, [], 'layout_invalid', 'names 0 frames'],
            'frame past the right edge' => [HdbSyntheticSprite::layout(100, 60, [[0, 0], [250, 0]]), [], 'layout_invalid', 'places frame 2 outside the image'],
            'frame past the bottom edge' => [HdbSyntheticSprite::layout(100, 60, [[0, 70]]), [], 'layout_invalid', 'places frame 1 outside the image'],
            'negative left' => [HdbSyntheticSprite::layout(100, 60, [[-1, 0]]), [], 'layout_invalid', 'places frame 1 outside the image'],
            'chunk twice' => [$ok, ['twice' => true], 'layout_invalid', 'appears more than once'],
            'palette' => [$ok, ['color' => 3], 'unsupported_format', 'color type 3'],
            'RGBA' => [$ok, ['color' => 6], 'unsupported_format', 'color type 6'],
            '16-bit' => [$ok, ['depth' => 16], 'unsupported_format', 'bit depth 16'],
            'interlaced' => [$ok, ['interlace' => 1], 'unsupported_format', 'interlace 1'],
        ];
    }

    /**
     * The sprite's image data is not valid deflate, so any decode would
     * fail as not_image: a layout reason proves the check ran before it.
     */
    #[DataProvider('badLayouts')]
    public function test_a_bad_layout_or_format_is_omitted_with_its_reason_and_the_sprite_size_without_decoding(array|string $layout, array $header, string $reason, string $says): void
    {
        $this->mock(\App\Services\AttachmentService::class)->shouldNotReceive('resizeImageBytesForAi');
        $sprite = HdbSyntheticSprite::headerOnly(300, 120, $layout, $header['depth'] ?? 8, $header['color'] ?? 2, $header['interlace'] ?? 0, $header['twice'] ?? false);

        $result = $this->report($sprite);

        $this->assertSame([], $result->frames);
        $this->assertNull($result->screenshot);
        $this->assertSame($reason, $result->spriteReason);
        $this->assertStringContainsString($says, (string) $result->spriteDetail);
        $this->assertStringContainsString(strlen($sprite).' bytes, 300x120 px', (string) $result->spriteDetail);
    }

    public function test_a_layout_chunk_with_a_bad_checksum_is_omitted_not_trusted(): void
    {
        $sprite = HdbSyntheticSprite::headerOnly(300, 120, HdbSyntheticSprite::layout(100, 60, [[0, 0]]));
        $at = strpos($sprite, 'hdbc') + 6;
        $sprite[$at] = $sprite[$at] === 'x' ? 'y' : 'x';

        $result = $this->report($sprite);

        $this->assertSame('layout_invalid', $result->spriteReason);
        $this->assertStringContainsString('unreadable (size or checksum)', (string) $result->spriteDetail);
    }

    public function test_newest_frames_past_the_work_ceiling_are_omitted_with_the_figures_before_any_inflate(): void
    {
        // Right edge 30000 px x 3 bytes x 1000 rows = 90 M bytes > 80 M.
        $sprite = HdbSyntheticSprite::headerOnly(30000, 1000, HdbSyntheticSprite::layout(1000, 1000, [[0, 0], [29000, 0]]));

        $result = $this->report($sprite);

        $this->assertSame('over_work_ceiling', $result->spriteReason);
        $this->assertStringContainsString('unfilter 90000000 bytes', (string) $result->spriteDetail);
        $this->assertStringContainsString(strlen($sprite).' bytes, 30000x1000 px', (string) $result->spriteDetail);
    }

    public function test_the_inflate_ceiling_applies_even_when_the_frames_are_narrow(): void
    {
        // Frames at the left (unfilter 3000 x 3 x 2600 = 23 M) but every row
        // above the bottom edge is inflated at full width: 60001 x 3... > 450 M.
        $sprite = HdbSyntheticSprite::headerOnly(60000, 2600, HdbSyntheticSprite::layout(1000, 600, [[0, 0], [2000, 2000]]));

        $result = $this->report($sprite);

        $this->assertSame('over_work_ceiling', $result->spriteReason);
        $this->assertStringContainsString('inflate '.((60000 * 3 + 1) * 2600).' bytes', (string) $result->spriteDetail);
    }

    public function test_frames_over_the_held_pixel_ceiling_are_omitted_as_oversize(): void
    {
        // Three 3000 x 1600 frames = 14.4 M px > 13.5 M; two of them would fit.
        $sprite = HdbSyntheticSprite::headerOnly(9000, 1600, HdbSyntheticSprite::layout(3000, 1600, [[0, 0], [3000, 0], [6000, 0]]));

        $result = $this->report($sprite);

        $this->assertSame('oversize', $result->spriteReason);
        $this->assertStringContainsString('14400000 px together', (string) $result->spriteDetail);
    }

    public function test_image_data_that_does_not_inflate_is_not_image_with_the_sprite_size(): void
    {
        $sprite = HdbSyntheticSprite::headerOnly(300, 120, HdbSyntheticSprite::layout(100, 60, [[0, 0]]));

        $result = $this->report($sprite);

        $this->assertSame('not_image', $result->spriteReason);
        $this->assertStringContainsString(strlen($sprite).' bytes, 300x120 px', (string) $result->spriteDetail);
    }

    public function test_a_sprite_without_a_layout_within_the_limits_is_still_returned_whole(): void
    {
        $result = $this->report(HdbSyntheticSprite::png(2000, 600));

        $this->assertSame([], $result->frames);
        $this->assertNull($result->spriteReason);
        $this->assertSame([1568, 470], array_slice(getimagesizefromstring($result->screenshot), 0, 2));
    }

    public function test_a_sprite_without_a_layout_over_five_mb_is_omitted_with_bytes_and_size(): void
    {
        $sprite = HdbSyntheticSprite::png(2000, 1200, [], null, 6000, 16, 1);
        $this->assertGreaterThan(AssistantToolExecutor::MAX_ATTACHMENT_BYTES, strlen($sprite));

        $result = $this->report($sprite);

        $this->assertNull($result->screenshot);
        $this->assertSame('oversize', $result->spriteReason);
        $this->assertStringContainsString(strlen($sprite).' bytes, 2000x1200 px', (string) $result->spriteDetail);
        $this->assertStringContainsString('no frame layout', (string) $result->spriteDetail);
    }

    public function test_a_sprite_without_a_layout_over_thirty_mp_is_omitted_with_bytes_and_size_before_decode(): void
    {
        $this->mock(\App\Services\AttachmentService::class)->shouldNotReceive('resizeImageBytesForAi');
        $sprite = HdbSyntheticSprite::headerOnly(6000, 6000, null);

        $result = $this->report($sprite);

        $this->assertSame('oversize', $result->spriteReason);
        $this->assertStringContainsString(strlen($sprite).' bytes, 6000x6000 px', (string) $result->spriteDetail);
    }
}
