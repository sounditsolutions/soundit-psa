<?php

namespace Tests\Unit;

use App\Support\AvatarHelper;
use PHPUnit\Framework\TestCase;

class AvatarHelperTest extends TestCase
{
    public function test_crop_to_square_jpeg_produces_a_square_jpeg(): void
    {
        $png = $this->samplePng(300, 120);

        $jpeg = AvatarHelper::cropToSquareJpeg($png, 200, 85);

        $this->assertNotNull($jpeg);

        $info = getimagesizefromstring($jpeg);
        $this->assertNotFalse($info);
        $this->assertSame(200, $info[0], 'width');
        $this->assertSame(200, $info[1], 'height');
        $this->assertSame('image/jpeg', $info['mime']);
    }

    public function test_crop_honours_custom_size(): void
    {
        $jpeg = AvatarHelper::cropToSquareJpeg($this->samplePng(64, 64), 48);

        $info = getimagesizefromstring($jpeg);
        $this->assertSame(48, $info[0]);
        $this->assertSame(48, $info[1]);
    }

    public function test_returns_null_for_non_image_bytes(): void
    {
        $this->assertNull(AvatarHelper::cropToSquareJpeg('this is not an image'));
    }

    public function test_returns_null_for_empty_string(): void
    {
        $this->assertNull(AvatarHelper::cropToSquareJpeg(''));
    }

    private function samplePng(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 200));
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return ob_get_clean();
    }
}
