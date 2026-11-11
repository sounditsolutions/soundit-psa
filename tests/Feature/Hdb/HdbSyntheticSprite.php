<?php

namespace Tests\Feature\Hdb;

/**
 * Synthetic Instant Replay sprites, built in-test. No real frame is used:
 * pixels are a seeded pattern, optional noise, and a solid marker colour in
 * each frame's top-left corner so a test can tell which position a returned
 * frame was cropped from.
 */
final class HdbSyntheticSprite
{
    /** Marker colour of frame position $k (0-based); distinct for k < 64. */
    public static function marker(int $k): int
    {
        return (($k * 37 + 40) & 255) << 16 | (($k * 91 + 7) & 255) << 8 | (200 - $k);
    }

    /**
     * An RGB8 PNG with an optional `hdbc` layout chunk.
     *
     * @param  list<array{int, int}>  $positions  [left, top] per frame, oldest first; each gets a marker
     * @param  array<string, mixed>|string|null  $layout  chunk JSON (array), raw chunk data (string), or null for none
     * @param  int  $noise  random bytes written into every row (to make the file large)
     * @param  int  $markerSize  side of the solid marker square
     * @param  int|null  $filter  one PNG filter type (0-4) for every row after the first; null cycles y % $filterCycle
     */
    public static function png(int $width, int $height, array $positions = [], array|string|null $layout = null, int $noise = 0, int $markerSize = 16, int $filterCycle = 5, ?int $filter = null): string
    {
        mt_srand(12345);
        $base = '';
        for ($x = 0; $x < $width; $x++) {
            $base .= chr(($x * 3) & 255).chr(($x >> 2) & 255).chr(($x * $x) & 255);
        }
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 1]);
        $idat = '';
        for ($y = 0; $y < $height; $y++) {
            $row = $base;
            if ($noise > 0) {
                $row = substr_replace($row, random_bytes(min($noise, $width * 3)), 0, min($noise, $width * 3));
            }
            foreach ($positions as $k => [$left, $top]) {
                if ($y >= $top && $y < $top + $markerSize) {
                    $c = self::marker($k);
                    $row = substr_replace($row, str_repeat(chr($c >> 16).chr(($c >> 8) & 255).chr($c & 255), $markerSize), $left * 3, $markerSize * 3);
                }
            }
            $idat .= deflate_add($deflate, self::filter($row, $y === 0 ? null : ($prev ?? null), $filter ?? $y % $filterCycle), ZLIB_NO_FLUSH);
            $prev = $row;
        }
        $idat .= deflate_add($deflate, '', ZLIB_FINISH);
        $chunks = self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0));
        if ($layout !== null) {
            $chunks .= self::chunk('hdbc', is_string($layout) ? $layout : json_encode($layout));
        }
        // Several IDAT chunks, as real encoders write them.
        foreach (str_split($idat, 65536) as $part) {
            $chunks .= self::chunk('IDAT', $part);
        }

        return "\x89PNG\r\n\x1a\n".$chunks.self::chunk('IEND', '');
    }

    /**
     * A wide RGB8 strip whose rows repeat one 256-pixel tile, so even a
     * strip of several hundred MB of pixels deflates to well under the
     * 32 MiB download limit and builds in a few seconds. Every row uses filter 0 (the filter types are
     * covered by png() at small sizes). Each position gets its marker, as in
     * png(), and the layout chunk names the positions.
     *
     * @param  list<array{int, int}>  $positions  [left, top] per frame, oldest first
     */
    public static function tiled(int $width, int $height, int $frameWidth, int $frameHeight, array $positions, int $markerSize = 32): string
    {
        mt_srand(54321);
        $tile = '';
        for ($i = 0; $i < 768; $i++) {
            $tile .= chr(mt_rand(0, 255));
        }
        $base = substr(str_repeat($tile, intdiv($width, 256) + 1), 0, $width * 3);
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 1]);
        $idat = '';
        for ($y = 0; $y < $height; $y++) {
            $row = $base;
            foreach ($positions as $k => [$left, $top]) {
                if ($y >= $top && $y < $top + $markerSize) {
                    $c = self::marker($k);
                    $row = substr_replace($row, str_repeat(chr($c >> 16).chr(($c >> 8) & 255).chr($c & 255), $markerSize), $left * 3, $markerSize * 3);
                }
            }
            $idat .= deflate_add($deflate, "\0".$row, ZLIB_NO_FLUSH);
        }
        $idat .= deflate_add($deflate, '', ZLIB_FINISH);
        $chunks = self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .self::chunk('hdbc', json_encode(self::layout($frameWidth, $frameHeight, $positions)));
        foreach (str_split($idat, 1 << 20) as $part) {
            $chunks .= self::chunk('IDAT', $part);
        }

        return "\x89PNG\r\n\x1a\n".$chunks.self::chunk('IEND', '');
    }

    /**
     * The first $count positions of a row-major grid, $perRow frames to a row.
     *
     * @return list<array{int, int}>
     */
    public static function grid(int $count, int $perRow, int $frameWidth, int $frameHeight): array
    {
        $out = [];
        for ($k = 0; $k < $count; $k++) {
            $out[] = [($k % $perRow) * $frameWidth, intdiv($k, $perRow) * $frameHeight];
        }

        return $out;
    }

    /** A layout chunk body in the vendor's shape. */
    public static function layout(int $frameWidth, int $frameHeight, array $positions): array
    {
        return ['frame_width' => $frameWidth, 'frame_height' => $frameHeight,
            'positions' => array_map(static fn (array $p) => ['left' => $p[0], 'top' => $p[1]], $positions)];
    }

    /**
     * Header and chunks only, over a tiny IDAT that is NOT valid image data:
     * any whole-image decode of it fails, so a test that gets a layout or
     * ceiling reason back proves the check ran before any decode.
     */
    public static function headerOnly(int $width, int $height, array|string|null $layout, int $depth = 8, int $color = 2, int $interlace = 0, bool $twice = false): string
    {
        $chunks = self::chunk('IHDR', pack('NNCCCCC', $width, $height, $depth, $color, 0, 0, $interlace));
        if ($layout !== null) {
            $chunk = self::chunk('hdbc', is_string($layout) ? $layout : json_encode($layout));
            $chunks .= $twice ? $chunk.$chunk : $chunk;
        }

        return "\x89PNG\r\n\x1a\n".$chunks.self::chunk('IDAT', 'not-deflate-data').self::chunk('IEND', '');
    }

    public static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /** PNG filter $type (0-4) of $row against $prev (null = zeros), RGB8. */
    private static function filter(string $row, ?string $prev, int $type): string
    {
        if ($type === 0) {
            return "\0".$row;
        }
        $n = strlen($row);
        $out = chr($type);
        for ($i = 0; $i < $n; $i++) {
            $a = $i >= 3 ? ord($row[$i - 3]) : 0;
            $b = $prev === null ? 0 : ord($prev[$i]);
            $c = ($prev === null || $i < 3) ? 0 : ord($prev[$i - 3]);
            $pred = match ($type) {
                1 => $a,
                2 => $b,
                3 => ($a + $b) >> 1,
                default => self::paeth($a, $b, $c),
            };
            $out .= chr((ord($row[$i]) - $pred) & 255);
        }

        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }
}
