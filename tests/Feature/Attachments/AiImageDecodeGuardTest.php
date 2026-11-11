<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\AttachmentService;
use App\Services\Triage\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Decode guard in AttachmentService::resizeImageBytesForAi (card riQQkVLF).
 *
 * Every fixture is synthetic and built in the test. Each refusal fixture is
 * bytes that GD WOULD decode without the guard, so each refusal test fails
 * when its guard clause is removed. The over-cap PNG is a one-byte-per-pixel
 * palette image; with the guard in place it is never decoded.
 */
class AiImageDecodeGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): AttachmentService
    {
        return app(AttachmentService::class);
    }

    private function gdBytes(string $format, int $w = 16, int $h = 12): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 200, 40, 40));
        ob_start();
        $format === 'png' ? imagepng($im) : imagejpeg($im, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /** A 24-bit BMP with real pixel data (GD decodes it). */
    private function bmpBytes(int $w, int $h): string
    {
        $size = (($w * 3 + 3) & ~3) * $h;
        $dib = pack('VVVvvVVVVVV', 40, $w, $h, 1, 24, 0, $size, 2835, 2835, 0, 0);

        return 'BM'.pack('VvvV', 14 + 40 + $size, 0, 0, 54).$dib.str_repeat("\x00", $size);
    }

    /**
     * A type-0 WBMP. Wider than 2048 px, getimagesizefromstring() cannot read
     * its header (returns false) while imagecreatefromstring() still decodes it.
     */
    private function wbmpBytes(int $w, int $h): string
    {
        $mb = function (int $n): string {
            $out = chr($n & 0x7F);
            for ($n >>= 7; $n > 0; $n >>= 7) {
                $out = chr(($n & 0x7F) | 0x80).$out;
            }

            return $out;
        };

        return "\x00\x00".$mb($w).$mb($h).str_repeat(str_repeat("\xFF", intdiv($w + 7, 8)), $h);
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /** A valid, decodable 8-bit palette PNG (all one colour; compresses to a few KB). */
    private function palettePngBytes(int $w, int $h): string
    {
        $z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $row = str_repeat("\x00", $w + 1);
        $idat = '';
        for ($y = 0; $y < $h; $y++) {
            $idat .= deflate_add($z, $row, ZLIB_NO_FLUSH);
        }
        $idat .= deflate_add($z, '', ZLIB_FINISH);

        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', pack('NN', $w, $h)."\x08\x03\x00\x00\x00")
            .$this->pngChunk('PLTE', "\x10\x20\x30")
            .$this->pngChunk('IDAT', $idat)
            .$this->pngChunk('IEND', '');
    }

    /** Width x height just over the cap: 6000 x 5001. */
    private function overCapPngBytes(): string
    {
        $w = 6000;
        $h = intdiv(AssistantToolExecutor::MAX_IMAGE_PIXELS, $w) + 1;
        $bytes = $this->palettePngBytes($w, $h);

        $info = getimagesizefromstring($bytes);
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
        $this->assertGreaterThan(AssistantToolExecutor::MAX_IMAGE_PIXELS, $info[0] * $info[1]);

        return $bytes;
    }

    private function attach(Ticket $ticket, string $bytes, string $name): Attachment
    {
        $att = Attachment::create([
            'filename' => $name,
            'original_filename' => $name,
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
            'storage_path' => 'attachments/tmp',
            'attachable_type' => Ticket::class,
            'attachable_id' => $ticket->id,
        ]);
        $path = "attachments/{$att->id}/{$name}";
        Storage::disk('local')->put($path, $bytes);
        $att->update(['storage_path' => $path]);

        return $att;
    }

    /** @param  array<int, array<string, mixed>>  $blocks */
    private function imageBlocks(array $blocks): array
    {
        return array_values(array_filter($blocks, fn ($b) => ($b['type'] ?? null) === 'image'));
    }

    /** Fixture control: GD decodes these bytes, so only the guard can refuse them. */
    private function assertGdDecodes(string $bytes): void
    {
        $im = @imagecreatefromstring($bytes);
        $this->assertNotFalse($im, 'fixture must be decodable by GD');
        imagedestroy($im);
    }

    public function test_ordinary_png_still_comes_back(): void
    {
        $out = $this->service()->resizeImageBytesForAi($this->gdBytes('png'), 'image/png');

        $this->assertNotNull($out);
        $info = getimagesizefromstring((string) base64_decode($out, true));
        $this->assertSame([16, 12, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
    }

    public function test_ordinary_jpeg_still_comes_back(): void
    {
        $out = $this->service()->resizeImageBytesForAi($this->gdBytes('jpeg'), 'image/jpeg');

        $this->assertNotNull($out);
        $info = getimagesizefromstring((string) base64_decode($out, true));
        $this->assertSame([16, 12, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
    }

    public function test_bmp_inside_the_pixel_cap_is_refused_whatever_the_stated_type(): void
    {
        $bytes = $this->bmpBytes(4, 3);
        $this->assertSame(IMAGETYPE_BMP, getimagesizefromstring($bytes)[2]);
        $this->assertGdDecodes($bytes);

        $this->assertNull($this->service()->resizeImageBytesForAi($bytes, 'image/png'));
    }

    public function test_wbmp_with_no_readable_header_is_refused(): void
    {
        $bytes = $this->wbmpBytes(2049, 1);
        $this->assertFalse(@getimagesizefromstring($bytes));
        $this->assertGdDecodes($bytes);

        $this->assertNull($this->service()->resizeImageBytesForAi($bytes, 'image/png'));
    }

    public function test_png_over_the_pixel_cap_is_refused_on_the_triage_path(): void
    {
        $ticket = Ticket::factory()->create();
        $this->attach($ticket, $this->gdBytes('png'), 'ordinary.png');
        $this->attach($ticket, $this->overCapPngBytes(), 'over-cap.png');

        $images = $this->imageBlocks(ContextBuilder::buildMultimodalContent($ticket->fresh()));

        // The ordinary PNG is the positive control: the path emits images at all.
        $this->assertCount(1, $images);
        $info = getimagesizefromstring((string) base64_decode($images[0]['source']['data'], true));
        $this->assertSame([16, 12], [$info[0], $info[1]]);
    }

    public function test_png_over_the_pixel_cap_is_refused_on_the_assistant_path(): void
    {
        $ticket = Ticket::factory()->create();
        $this->attach($ticket, $this->gdBytes('png'), 'ordinary.png');
        $this->attach($ticket, $this->overCapPngBytes(), 'over-cap.png');

        $method = new \ReflectionMethod(AssistantService::class, 'buildTicketImageBlocks');
        $images = $this->imageBlocks($method->invoke(app(AssistantService::class), $ticket->fresh()));

        $this->assertCount(1, $images);
        $info = getimagesizefromstring((string) base64_decode($images[0]['source']['data'], true));
        $this->assertSame([16, 12], [$info[0], $info[1]]);
    }
}
