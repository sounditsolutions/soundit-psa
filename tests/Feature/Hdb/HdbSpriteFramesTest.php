<?php

namespace Tests\Feature\Hdb;

use App\Models\Setting;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Hdb\HdbApiReportClient;
use App\Services\Hdb\HdbReportResult;
use App\Services\Hdb\HdbSpriteFrames;
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

    public static function filterTypes(): array
    {
        return ['sub' => [1], 'up' => [2], 'average' => [3], 'paeth' => [4]];
    }

    /**
     * Band carry, one filter type at a time (card 6Xiehny3, context:1): every
     * row after the first uses $filter and carries noise, so a band whose top
     * row is unfiltered against the wrong row above it gives wrong pixels in
     * every frame below the first band. Bands here are 250 rows (600 px to
     * the right edge), and each frame crosses a band boundary.
     */
    #[DataProvider('filterTypes')]
    public function test_each_filter_type_carries_across_bands_pixel_for_pixel(int $filter): void
    {
        $positions = [[0, 100], [200, 600], [400, 1050]];
        $sprite = HdbSyntheticSprite::png(600, 1600, $positions, HdbSyntheticSprite::layout(200, 500, $positions), 1800, 16, 5, $filter);

        $this->assertFramesEqualWholeDecode($sprite, $positions, 200, 500);
    }

    /**
     * Past 75,000 px to the right edge BAND_PIXELS alone gives one-row bands;
     * the floor keeps them at two rows, and the crop stays equal to a whole
     * decode there.
     */
    public function test_bands_are_never_under_two_rows_and_stay_exact_past_seventy_five_thousand_px(): void
    {
        $this->assertSame(1_500, HdbSpriteFrames::bandRows(100));
        $this->assertSame(2, HdbSpriteFrames::bandRows(75_000));
        $this->assertSame(2, HdbSpriteFrames::bandRows(75_001));
        $this->assertSame(2, HdbSpriteFrames::bandRows(99_999));

        $positions = [[0, 0], [100, 0], [79_900, 0]];
        $sprite = HdbSyntheticSprite::png(80_000, 7, $positions, HdbSyntheticSprite::layout(100, 7, $positions), 6000, 4, 5, 4);

        $this->assertFramesEqualWholeDecode($sprite, $positions, 100, 7);
    }

    /** @param  list<array{int, int}>  $positions */
    private function assertFramesEqualWholeDecode(string $sprite, array $positions, int $fw, int $fh): void
    {
        $whole = imagecreatefromstring($sprite);
        $result = $this->report($sprite);

        $this->assertCount(count($positions), $result->frames);
        foreach ($positions as $k => [$left, $top]) {
            $frame = imagecreatefromstring($result->frames[$k]['png']);
            $this->assertSame([$fw, $fh], [imagesx($frame), imagesy($frame)], "frame {$k} size");
            $diffs = 0;
            for ($y = 0; $y < $fh; $y++) {
                for ($x = 0; $x < $fw; $x++) {
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
     * The sprite's image data is not valid deflate, so a crop would fail as
     * not_image: a layout reason proves the check ran before any inflate.
     * The sprite is inside the whole-image limits, so the whole image is
     * tried once (made to fail here, as this data would) and the layout
     * reason is what comes back.
     */
    #[DataProvider('badLayouts')]
    public function test_a_bad_layout_or_format_is_omitted_with_its_reason_and_the_sprite_size_before_any_inflate(array|string $layout, array $header, string $reason, string $says): void
    {
        $this->mock(\App\Services\AttachmentService::class)->shouldReceive('resizeImageBytesForAi')->once()->andReturnNull();
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

    // ------------------------------------------------- r2: ceilings and fallback

    /** @return array{frames?: list<array<string, mixed>>, note?: ?string, reason?: string, detail?: string} */
    private function extractFrom(string $sprite): array
    {
        $info = HdbSpriteFrames::inspect($sprite);
        $this->assertNotNull($info);

        return HdbSpriteFrames::extract($sprite, $info);
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     * @param  list<int>  $positions  0-based positions the frames must come from, oldest first
     */
    private function assertFramesFrom(array $positions, int $total, array $frames): void
    {
        $this->assertSame(array_map(static fn (int $k) => $k + 1, $positions), array_column($frames, 'index'));
        $this->assertSame(array_fill(0, count($positions), $total), array_column($frames, 'total'));
        foreach ($frames as $i => $frame) {
            $this->assertFrameFrom($positions[$i], $frame['png']);
        }
    }

    public static function acceptanceRows(): array
    {
        // Ruling r2 item 3: 20 frames in one row, at 1080p (work 124.4 M) and 1440p (work 221.2 M).
        return ['1920x1080, 20 in one row' => [1920, 1080], '2560x1440, 20 in one row' => [2560, 1440]];
    }

    #[DataProvider('acceptanceRows')]
    public function test_twenty_frames_in_one_row_return_the_newest_three_cropped(int $fw, int $fh): void
    {
        $positions = HdbSyntheticSprite::grid(20, 20, $fw, $fh);
        $this->assertLessThanOrEqual(HdbSpriteFrames::WORK_CEILING_BYTES, 20 * $fw * 3 * $fh);

        $result = $this->report(HdbSyntheticSprite::tiled(20 * $fw, $fh, $fw, $fh, $positions));

        $this->assertNull($result->spriteReason, (string) $result->spriteDetail);
        $this->assertNull($result->framesNote);
        $this->assertCount(3, $result->frames);
        $this->assertFramesFrom([17, 18, 19], 20, $result->frames);
    }

    public static function gridCounts(): array
    {
        // The 3840x1130 grid, 17 frames a row, filled row-major (positions
        // 0-16 in row 0, 17-33 in row 1). Work is (right edge x 3) x (bottom
        // edge): any try that keeps position 16 (row 0, far right) and one in
        // row 1 reaches the full 65,280 px width and 2,260 rows.
        return [
            // 19: newest 3 = positions 16 (row 0, far right), 17, 18 -> 442.6 M; newest 2 = 17, 18 -> 2 x 3840 wide, 2260 rows = 52.1 M.
            '19 frames: two' => [19, [17, 18], 'unfilter 442598400 bytes, over the work ceiling of 222000000 bytes'],
            // 21: newest 3 = positions 18-20 in row 1 at the left -> 11520 x 3 x 2260 = 78.1 M.
            '21 frames: three' => [21, [18, 19, 20], null],
            // 18: newest 3 = 15, 16 (row 0, right) and 17; newest 2 = 16, 17 -> both 442.6 M; newest 1 = 17 -> 26.0 M.
            '18 frames: one' => [18, [17], 'the newest 2 frames (ending 65280 px from the left and 2260 rows from the top) would unfilter 442598400 bytes, over the work ceiling of 222000000 bytes'],
        ];
    }

    /** @param list<int> $expected */
    #[DataProvider('gridCounts')]
    public function test_on_the_seventeen_per_row_grid_the_newest_frames_fall_back_three_two_one(int $count, array $expected, ?string $says): void
    {
        $positions = HdbSyntheticSprite::grid($count, 17, 3840, 1130);

        $result = $this->report(HdbSyntheticSprite::tiled(65280, 2260, 3840, 1130, $positions));

        $this->assertNull($result->spriteReason, (string) $result->spriteDetail);
        $this->assertFramesFrom($expected, $count, $result->frames);
        if ($says === null) {
            $this->assertNull($result->framesNote);
        } else {
            $this->assertStringStartsWith(count($expected).' of the newest 3 frames: ', (string) $result->framesNote);
            $this->assertStringContainsString($says, (string) $result->framesNote);
            // G-14: only the ceiling that is over is named.
            $this->assertStringNotContainsString('inflate', (string) $result->framesNote);
            $this->assertStringNotContainsString('held-pixel', (string) $result->framesNote);
        }
    }

    /*
     * Ceiling +-1. Work is (right x 3) x bottom, so it is always a multiple
     * of 3; inflate is (W x 3 + 1) x bottom. Inside the 300,000-byte row cap
     * and the 65,536-row ceiling, work 222,000,003 does not exist (inside the
     * row cap it needs at least 24,666,667 rows; 24,666,667 is prime) and nor
     * does inflate 847,000,001 (only 79 x 10,721,519), so the over side of
     * each pair is the shape built here: work 222,000,009 (50,581 x 1,463)
     * and inflate one row past the at-ceiling strip, 250,000 x 3,389 =
     * 847,250,000.
     * Each pair has a full crop at the ceiling and one just over it, where
     * the newest three fall back to two; and a decision pair on header-only
     * sprites (no valid image data: not_image means the ceiling let it
     * through to the inflate, over_work_ceiling means it did not).
     */

    public function test_work_exactly_at_the_ceiling_crops_three_frames(): void
    {
        // Newest frame at the far right: 74,000 x 3 x 1,000 = 222,000,000.
        $positions = [[73000, 0], [0, 0], [1000, 0]];
        $this->assertSame(HdbSpriteFrames::WORK_CEILING_BYTES, 74000 * 3 * 1000);

        $out = $this->extractFrom(HdbSyntheticSprite::tiled(74000, 1000, 1000, 1000, $positions));

        $this->assertArrayHasKey('frames', $out, $out['detail'] ?? '');
        $this->assertNull($out['note']);
        $this->assertFramesFrom([0, 1, 2], 3, $out['frames']);
    }

    public function test_work_just_over_the_ceiling_falls_back_to_the_newest_two_and_says_so(): void
    {
        // 50,581 x 3 x 1,463 = 222,000,009 with the oldest of three at the far
        // right: the smallest work over the ceiling for a strip inside the row
        // cap with frames 1,000 px wide.
        $positions = [[49581, 0], [0, 0], [1000, 0]];

        $out = $this->extractFrom(HdbSyntheticSprite::tiled(50581, 1463, 1000, 1463, $positions));

        $this->assertFramesFrom([1, 2], 3, $out['frames'] ?? []);
        $this->assertSame('2 of the newest 3 frames: the newest 3 frames (ending 50581 px from the left and 1463 rows from the top) would unfilter 222000009 bytes, over the work ceiling of 222000000 bytes', $out['note']);
    }

    public function test_inflate_exactly_at_the_ceiling_with_the_work_at_it_too_crops_three_frames(): void
    {
        // Both ceilings at once, inside the row cap: (83,333 x 3 + 1) x 3,388 =
        // 847,000,000 of inflate (rows of 250,000 bytes), and the newest frame
        // ends at 21,841 px, so the work is 21,841 x 3 x 3,388 = 221,991,924.
        $positions = [[0, 0], [1000, 0], [20841, 0]];
        $this->assertSame(HdbSpriteFrames::INFLATE_CEILING_BYTES, (83333 * 3 + 1) * 3388);
        $this->assertLessThanOrEqual(HdbSpriteFrames::WORK_CEILING_BYTES, 21841 * 3 * 3388);

        $out = $this->extractFrom(HdbSyntheticSprite::tiled(83333, 3388, 1000, 3388, $positions));

        $this->assertArrayHasKey('frames', $out, $out['detail'] ?? '');
        $this->assertNull($out['note']);
        $this->assertFramesFrom([0, 1, 2], 3, $out['frames']);
    }

    public function test_inflate_just_over_the_ceiling_falls_back_to_the_newest_two_and_names_only_that_ceiling(): void
    {
        // One row past the at-ceiling strip: (83,333 x 3 + 1) x 3,389 =
        // 847,250,000; work 20 x 3 x 3,389 = 203,340, rows inside their ceiling.
        $positions = [[0, 3379], [0, 0], [10, 0]];

        $out = $this->extractFrom(HdbSyntheticSprite::tiled(83333, 3389, 10, 10, $positions, 4));

        $this->assertFramesFrom([1, 2], 3, $out['frames'] ?? []);
        $this->assertSame('2 of the newest 3 frames: the newest 3 frames (ending 20 px from the left and 3389 rows from the top) would inflate 847250000 bytes, over the inflate ceiling of 847000000 bytes', $out['note']);
    }

    public function test_rows_over_the_row_count_ceiling_fall_back_to_the_newest_two_and_name_only_that_ceiling(): void
    {
        // A strip 20 px wide and 65,537 rows tall: work (3.9 M) and inflate
        // (4.0 M) are tiny, but the oldest of the newest three ends one row
        // past the row-count ceiling.
        $this->assertSame(65_536, HdbSpriteFrames::ROWS_CEILING);
        $positions = [[0, 65527], [0, 0], [10, 0]];

        $out = $this->extractFrom(HdbSyntheticSprite::tiled(20, 65537, 10, 10, $positions, 4));

        $this->assertFramesFrom([1, 2], 3, $out['frames'] ?? []);
        $this->assertSame('2 of the newest 3 frames: the newest 3 frames (ending 20 px from the left and 65537 rows from the top) would stream 65537 rows, over the row-count ceiling of 65536 rows', $out['note']);
    }

    public static function ceilingDecisions(): array
    {
        return [
            'work at the ceiling' => [74000, 1000, 1000, 1000, [73000, 0], 'not_image', null],
            'work just over' => [50581, 1463, 1, 1, [50580, 1462], 'over_work_ceiling', 'unfilter 222000009 bytes, over the work ceiling of 222000000 bytes'],
            'inflate at the ceiling' => [83333, 3388, 1000, 3388, [0, 0], 'not_image', null],
            'inflate one row over' => [83333, 3389, 1, 1, [0, 3388], 'over_work_ceiling', 'inflate 847250000 bytes, over the inflate ceiling of 847000000 bytes'],
            'rows at the ceiling' => [500, 65536, 1, 1, [0, 65535], 'not_image', null],
            'rows one over' => [500, 65537, 1, 1, [0, 65536], 'over_work_ceiling', 'stream 65537 rows, over the row-count ceiling of 65536 rows'],
            'one frame over the held pixels' => [9000, 3400, 4000, 3400, [0, 0], 'oversize', 'hold 13600000 px (1 x 4000x3400), over the held-pixel ceiling of 13500000 px'],
            'work and inflate both over' => [90000, 3200, 1000, 1000, [89000, 2200], 'over_work_ceiling', 'unfilter 864000000 bytes, over the work ceiling of 222000000 bytes and inflate 864003200 bytes, over the inflate ceiling of 847000000 bytes'],
        ];
    }

    /** @param array{int, int} $position */
    #[DataProvider('ceilingDecisions')]
    public function test_a_single_frame_at_the_ceiling_is_cropped_and_one_over_is_omitted_naming_only_that_ceiling(int $w, int $h, int $fw, int $fh, array $position, string $reason, ?string $says): void
    {
        $this->assertGreaterThan(AssistantToolExecutor::MAX_IMAGE_PIXELS, $w * $h);
        $sprite = HdbSyntheticSprite::headerOnly($w, $h, HdbSyntheticSprite::layout($fw, $fh, [$position]));

        $result = $this->report($sprite);

        $this->assertSame([], $result->frames);
        $this->assertSame($reason, $result->spriteReason);
        if ($says !== null) {
            $this->assertStringContainsString('no frame was cropped: the newest frame (ending ', (string) $result->spriteDetail);
            $this->assertStringContainsString($says, (string) $result->spriteDetail);
            $this->assertSame(substr_count($says, 'ceiling'), substr_count((string) $result->spriteDetail, 'ceiling'), 'only the ceilings that are over are named');
            $this->assertStringContainsString(strlen($sprite).' bytes, '.$w.'x'.$h.' px', (string) $result->spriteDetail);
        }
    }

    /**
     * Twenty 3840 x 2160 frames on the 17-per-row grid (65,280 x 4,320): the
     * newest three sit at the left of row 1, so the work is 149.3 M but every
     * row is inflated, 846.0 M, inside the 847 M ceiling. Three (24.9 M px)
     * and two (16.6 M px) are over the held pixels; the newest one returns.
     */
    public function test_twenty_4k_frames_on_the_grid_return_the_newest_one(): void
    {
        $positions = HdbSyntheticSprite::grid(20, 17, 3840, 2160);

        $result = $this->report(HdbSyntheticSprite::tiled(65280, 4320, 3840, 2160, $positions));

        $this->assertNull($result->spriteReason, (string) $result->spriteDetail);
        $this->assertFramesFrom([19], 20, $result->frames);
        $this->assertSame('1 of the newest 3 frames: the newest 3 frames (ending 11520 px from the left and 4320 rows from the top) would hold 24883200 px (3 x 3840x2160), over the held-pixel ceiling of 13500000 px; '
            .'the newest 2 frames (ending 11520 px from the left and 4320 rows from the top) would hold 16588800 px (2 x 3840x2160), over the held-pixel ceiling of 13500000 px', $result->framesNote);
    }

    /**
     * Twenty 3840 x 1200 frames (two 1920 x 1200 monitors) on the 17-per-row
     * grid (65,280 x 2,400; work 82.9 M, inflate 470.0 M): three hold
     * 13.82 M px, over 13.5 M, so the held-pixel check must sit inside the
     * fallback and the newest two return.
     */
    public function test_twenty_dual_monitor_frames_on_the_grid_return_the_newest_two(): void
    {
        $positions = HdbSyntheticSprite::grid(20, 17, 3840, 1200);

        $result = $this->report(HdbSyntheticSprite::tiled(65280, 2400, 3840, 1200, $positions));

        $this->assertNull($result->spriteReason, (string) $result->spriteDetail);
        $this->assertFramesFrom([18, 19], 20, $result->frames);
        $this->assertSame('2 of the newest 3 frames: the newest 3 frames (ending 11520 px from the left and 2400 rows from the top) would hold 13824000 px (3 x 3840x1200), over the held-pixel ceiling of 13500000 px', $result->framesNote);
    }

    public function test_held_pixels_over_the_ceiling_fall_back_to_the_newest_two(): void
    {
        // Three 3000 x 1600 frames hold 14.4 M px > 13.5 M; two hold 9.6 M.
        $positions = [[0, 0], [3000, 0], [6000, 0]];

        $result = $this->report(HdbSyntheticSprite::tiled(9000, 1600, 3000, 1600, $positions));

        $this->assertNull($result->spriteReason, (string) $result->spriteDetail);
        $this->assertFramesFrom([1, 2], 3, $result->frames);
        $this->assertSame('2 of the newest 3 frames: the newest 3 frames (ending 9000 px from the left and 1600 rows from the top) would hold 14400000 px (3 x 3000x1600), over the held-pixel ceiling of 13500000 px', $result->framesNote);
    }

    public function test_image_data_that_does_not_inflate_is_not_image_with_the_sprite_size(): void
    {
        $sprite = HdbSyntheticSprite::headerOnly(300, 120, HdbSyntheticSprite::layout(100, 60, [[0, 0]]));

        $result = $this->report($sprite);

        $this->assertSame('not_image', $result->spriteReason);
        $this->assertStringContainsString(strlen($sprite).' bytes, 300x120 px', (string) $result->spriteDetail);
    }

    public static function uncroppableLayouts(): array
    {
        return [
            'frame past the right edge' => [HdbSyntheticSprite::layout(100, 60, [[0, 0], [250, 0]])],
            'not JSON' => ['{frame_width:'],
        ];
    }

    /** Ruling condition 4: a sprite returned whole before frames existed still is, chunk or not. */
    #[DataProvider('uncroppableLayouts')]
    public function test_a_sprite_whose_frames_cannot_be_cropped_is_returned_whole_within_the_limits(array|string $layout): void
    {
        $result = $this->report(HdbSyntheticSprite::png(300, 120, [[0, 0]], $layout));

        $this->assertSame([], $result->frames);
        $this->assertNull($result->spriteReason, (string) $result->spriteDetail);
        $this->assertSame([300, 120], array_slice(getimagesizefromstring((string) $result->screenshot), 0, 2));
    }

    public function test_a_row_over_the_row_ceiling_is_refused_before_any_inflate_even_when_the_frames_are_tiny(): void
    {
        // One 1 x 1 frame passes the work, inflate and held-pixel ceilings, but
        // a whole 300,001-byte row (100,000 px) would have to be buffered to reach it.
        $this->assertSame(300_000, HdbSpriteFrames::MAX_ROW_BYTES);
        $sprite = HdbSyntheticSprite::headerOnly(100000, 1, HdbSyntheticSprite::layout(1, 1, [[0, 0]]));

        $result = $this->report($sprite);

        $this->assertSame('over_work_ceiling', $result->spriteReason);
        $this->assertStringContainsString('rows are 300001 bytes each', (string) $result->spriteDetail);
        $this->assertStringContainsString(strlen($sprite).' bytes, 100000x1 px', (string) $result->spriteDetail);
    }

    public function test_a_row_at_the_row_ceiling_reaches_the_inflate(): void
    {
        // 99,999 px: rows of 299,998 bytes, inside the cap, so the header-only
        // sprite gets as far as its (invalid) image data.
        $sprite = HdbSyntheticSprite::headerOnly(99999, 1, HdbSyntheticSprite::layout(1, 1, [[0, 0]]));

        $result = $this->report($sprite);

        $this->assertSame('not_image', $result->spriteReason, (string) $result->spriteDetail);
    }

    public function test_idat_chunks_past_the_ceiling_are_counted_not_listed_and_the_frames_are_refused(): void
    {
        $sprite = HdbSyntheticSprite::png(300, 120, [[0, 0]], HdbSyntheticSprite::layout(100, 60, [[0, 0]]));
        $at = strpos($sprite, 'IDAT') - 4;
        $sprite = substr($sprite, 0, $at).str_repeat(HdbSyntheticSprite::chunk('IDAT', ''), HdbSpriteFrames::MAX_IDAT_CHUNKS).substr($sprite, $at);

        $info = HdbSpriteFrames::inspect($sprite);
        $this->assertSame(HdbSpriteFrames::MAX_IDAT_CHUNKS + 1, $info['idats']);
        $this->assertCount(HdbSpriteFrames::MAX_IDAT_CHUNKS, $info['idat']);
        $out = HdbSpriteFrames::extract($sprite, $info);
        $this->assertSame('over_work_ceiling', $out['reason'] ?? null);
        $this->assertStringContainsString('is in '.(HdbSpriteFrames::MAX_IDAT_CHUNKS + 1).' IDAT chunks', $out['detail']);

        // Inside the whole-image limits, so the client still returns it whole.
        $result = $this->report($sprite);
        $this->assertSame([], $result->frames);
        $this->assertSame([300, 120], array_slice(getimagesizefromstring((string) $result->screenshot), 0, 2));
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
