<?php

namespace App\Services\Hdb;

/**
 * The newest Instant Replay frames, cropped out of the sprite PNG without
 * decoding the whole strip.
 *
 * THE LAYOUT. The sprite carries an ancillary `hdbc` chunk holding JSON:
 * frame_width, frame_height and a positions list of {left, top}. Positions
 * are in capture order, oldest first, so the last FRAME_COUNT positions are
 * the newest frames. Nothing here guesses a grid: a sprite whose chunk is
 * missing is not handled by this class, and a chunk that is unreadable, or
 * names a frame outside the image, is an omission with a reason.
 *
 * WHY NO WHOLE DECODE. A real strip can be well over the 30 MP decode
 * guard (a GD decode of the whole strip needs about 4 bytes per pixel). A
 * PNG filter at byte x reads only bytes at or left of x in the current and
 * the previous row, so each filtered row is truncated to the frames' right
 * edge, and the rows are unfiltered by libpng in bands: each band is a small
 * stored-deflate PNG whose first row is the previous band's last row,
 * re-encoded by GD as the first row of a one-row PNG. Only the bytes left of
 * the right edge and above the bottom edge are ever unfiltered.
 *
 * WORK CEILING. The cost grows with (bytes to the right edge) x (rows to the
 * bottom edge), with the inflate of every row above the bottom edge at full
 * width, and with the pixels of the frames held and resampled. Each is
 * bounded by a constant checked before any inflate; beyond any of them,
 * nothing is cropped and the omission says why. The values were chosen so
 * that the measured CPU at the ceilings stays under 10 s on the production
 * host's slower CPU (card 6Xiehny3 probe).
 */
final class HdbSpriteFrames
{
    /** How many of the newest frames are returned. */
    public const FRAME_COUNT = 3;

    /** Longest side of a returned frame (the AI image size used elsewhere). */
    public const MAX_SIDE = 1568;

    /**
     * Pixels of one returned frame, after MAX_SIDE. It bounds the PNG encode
     * time and the response bytes. A frame of about 3.4:1 or wider is under it
     * at 1568 px wide; a 16:9 frame comes out about 1333 x 750.
     */
    public const MAX_OUTPUT_PIXELS = 1_000_000;

    /** (frames' right edge x 3 bytes) x (rows to the frames' bottom edge). */
    public const WORK_CEILING_BYTES = 80_000_000;

    /** (full row stride) x (rows to the frames' bottom edge), all inflated. */
    public const INFLATE_CEILING_BYTES = 450_000_000;

    /**
     * Pixels of the frames held whole while rows stream past (all of them
     * together). Stricter than the 30 MP whole-image decode guard, which it
     * replaces for crops: it bounds both memory (4 bytes a pixel) and the
     * copy-and-resample cost.
     */
    public const HELD_PIXELS_CEILING = 13_500_000;

    public const MAX_LAYOUT_BYTES = 65_536;

    public const MAX_POSITIONS = 4_096;

    /** GD pixels per unfilter band. */
    private const BAND_PIXELS = 150_000;

    private const INFLATE_SLICE = 4_096;

    /** zlib level for returned frames: about half the CPU of the default for about 1% more bytes. */
    private const PNG_LEVEL = 1;

    /**
     * Header facts, read without inflating anything.
     *
     * @return array{width: int, height: int, depth: int, color: int, interlace: int, layout: ?string, layouts: int, idat: list<array{int, int}>}|null
     *                                                                                                                                                 null when the bytes are not a PNG whose chunks can be walked
     */
    public static function inspect(string $png): ?array
    {
        $length = strlen($png);
        if ($length < 33 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($png, 12, 4) !== 'IHDR' || unpack('N', substr($png, 8, 4))[1] !== 13) {
            return null;
        }
        $ihdr = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', substr($png, 16, 13));
        $out = ['width' => $ihdr['width'], 'height' => $ihdr['height'], 'depth' => $ihdr['depth'], 'color' => $ihdr['color'],
            'interlace' => $ihdr['interlace'], 'layout' => null, 'layouts' => 0, 'idat' => []];
        $at = 8;
        while ($at + 12 <= $length) {
            $size = unpack('N', substr($png, $at, 4))[1];
            $type = substr($png, $at + 4, 4);
            if ($size > $length - $at - 12) {
                return null;
            }
            if ($type === 'IDAT') {
                $out['idat'][] = [$at + 8, $size];
            } elseif ($type === 'hdbc') {
                $out['layouts']++;
                $data = substr($png, $at + 8, $size);
                $valid = $size <= self::MAX_LAYOUT_BYTES && pack('N', crc32($type.$data)) === substr($png, $at + 8 + $size, 4);
                $out['layout'] = $valid ? $data : '';
            } elseif ($type === 'IEND') {
                break;
            }
            $at += 12 + $size;
        }

        return $out['idat'] === [] ? null : $out;
    }

    /**
     * Crop the newest frames named by the sprite's `hdbc` chunk, oldest first.
     *
     * @param  array{width: int, height: int, depth: int, color: int, interlace: int, layout: ?string, layouts: int, idat: list<array{int, int}>}  $info  from inspect(), with a layout
     * @return array{frames: list<array{png: string, width: int, height: int, index: int, total: int}>}|array{reason: string, detail: string}
     */
    public static function extract(string $png, array $info): array
    {
        $layout = self::layout($info);
        if (isset($layout['reason'])) {
            return $layout;
        }
        ['frame_width' => $fw, 'frame_height' => $fh, 'positions' => $positions] = $layout;
        $total = count($positions);
        $picked = array_slice($positions, -self::FRAME_COUNT, null, true);
        $right = max(array_map(static fn (array $p) => $p['left'] + $fw, $picked));
        $bottom = max(array_map(static fn (array $p) => $p['top'] + $fh, $picked));
        $work = $right * 3 * $bottom;
        $inflate = ($info['width'] * 3 + 1) * $bottom;
        if ($work > self::WORK_CEILING_BYTES || $inflate > self::INFLATE_CEILING_BYTES) {
            return ['reason' => 'over_work_ceiling', 'detail' => 'the newest frames end '.$right.' px from the left and '.$bottom.' rows from the top; cropping them would unfilter '.$work.' bytes and inflate '.$inflate
                .' bytes, over this tool\'s ceilings of '.self::WORK_CEILING_BYTES.' and '.self::INFLATE_CEILING_BYTES.' bytes'];
        }

        $crops = self::crop($png, $info, $picked, $fw, $fh, $right, $bottom);
        if ($crops === null) {
            return ['reason' => 'not_image', 'detail' => 'the sprite image data could not be decoded'];
        }
        $frames = [];
        foreach (array_keys($picked) as $i => $position) {
            $size = getimagesizefromstring($crops[$i]);
            $frames[] = ['png' => $crops[$i], 'width' => (int) $size[0], 'height' => (int) $size[1], 'index' => $position + 1, 'total' => $total];
        }

        return ['frames' => $frames];
    }

    /**
     * Stream-inflate the IDAT data, truncate each filtered row to the right
     * edge, and let libpng unfilter it in bands; copy each frame's rows out.
     *
     * @param  array<int, array{left: int, top: int}>  $picked
     * @return list<string>|null PNG bytes per frame, oldest first; null when the image data does not decode
     */
    private static function crop(string $png, array $info, array $picked, int $fw, int $fh, int $right, int $bottom): ?array
    {
        $stride = $info['width'] * 3 + 1;
        $keep = 1 + $right * 3;
        $bandRows = max(1, intdiv(self::BAND_PIXELS, $right));
        $outs = [];
        foreach ($picked as $k => $p) {
            $outs[$k] = imagecreatetruecolor($fw, $fh);
        }
        $carry = null;
        $rows = [];
        $row = 0;
        $flush = static function () use (&$rows, &$carry, &$row, &$outs, $picked, $fw, $fh, $right): bool {
            $count = count($rows);
            $offset = $carry === null ? 0 : 1;
            $height = $count + $offset;
            $raw = ($carry ?? '').implode('', $rows);
            $rows = [];
            $band = @imagecreatefromstring(self::pngOf($right, $height, gzcompress($raw, 0)));
            if ($band === false) {
                return false;
            }
            $first = $row - $count;
            foreach ($picked as $k => $p) {
                $from = max($p['top'], $first);
                $to = min($p['top'] + $fh, $row);
                if ($from < $to) {
                    imagecopy($outs[$k], $band, 0, $from - $p['top'], $p['left'], $from - $first + $offset, $fw, $to - $from);
                }
            }
            $carry = self::lastRow($band, $right, $height);

            return $carry !== null;
        };

        $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
        $buffer = '';
        foreach ($info['idat'] as [$start, $size]) {
            for ($at = 0; $at < $size && $row < $bottom; $at += self::INFLATE_SLICE) {
                $more = @inflate_add($inflate, substr($png, $start + $at, min(self::INFLATE_SLICE, $size - $at)), ZLIB_SYNC_FLUSH);
                if ($more === false) {
                    return null;
                }
                $buffer .= $more;
                $p = 0;
                $have = strlen($buffer);
                while ($have - $p >= $stride && $row < $bottom) {
                    $rows[] = substr($buffer, $p, $keep);
                    $p += $stride;
                    $row++;
                    if (count($rows) >= $bandRows && ! $flush()) {
                        return null;
                    }
                }
                $buffer = substr($buffer, $p);
            }
            if ($row >= $bottom) {
                break;
            }
        }
        if ($row < $bottom || ($rows !== [] && ! $flush())) {
            return null;
        }

        $result = [];
        foreach ($outs as $image) {
            $result[] = self::encode($image, $fw, $fh);
        }

        return $result;
    }

    /**
     * A band's last row as one FILTERED PNG row (filter byte included), read
     * back from a one-row PNG that GD encodes; no per-pixel PHP. Any filter
     * type is valid on the first row of an image, because the row above it is
     * taken as zeros by encoder and decoder alike, so this row goes verbatim
     * at the top of the next band.
     */
    private static function lastRow(\GdImage $band, int $width, int $height): ?string
    {
        $line = imagecrop($band, ['x' => 0, 'y' => $height - 1, 'width' => $width, 'height' => 1]);
        if ($line === false) {
            return null;
        }
        ob_start();
        imagepng($line, null, 0);
        $bytes = (string) ob_get_clean();
        $info = self::inspect($bytes);
        if ($info === null || $info['color'] !== 2 || $info['depth'] !== 8) {
            return null;
        }
        $data = '';
        foreach ($info['idat'] as [$start, $size]) {
            $data .= substr($bytes, $start, $size);
        }
        $raw = @gzuncompress($data);

        return is_string($raw) && strlen($raw) === 1 + $width * 3 && ord($raw[0]) <= 4 ? $raw : null;
    }

    private static function encode(\GdImage $image, int $width, int $height): string
    {
        $scale = min(1, self::MAX_SIDE / max($width, $height), sqrt(self::MAX_OUTPUT_PIXELS / ($width * $height)));
        if ($scale < 1) {
            $w = max(1, (int) round($width * $scale));
            $h = max(1, (int) round($height * $scale));
            $small = imagecreatetruecolor($w, $h);
            imagecopyresampled($small, $image, 0, 0, 0, 0, $w, $h, $width, $height);
            $image = $small;
        }
        ob_start();
        imagepng($image, null, self::PNG_LEVEL);

        return (string) ob_get_clean();
    }

    private static function pngOf(int $width, int $height, string $idat): string
    {
        $chunk = static fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', $idat).$chunk('IEND', '');
    }

    /** @return array{frame_width: int, frame_height: int, positions: list<array{left: int, top: int}>}|array{reason: string, detail: string} */
    private static function layout(array $info): array
    {
        $bad = static fn (string $why) => ['reason' => 'layout_invalid', 'detail' => 'the frame layout chunk '.$why];
        if ($info['layouts'] !== 1 || ! is_string($info['layout']) || $info['layout'] === '') {
            return $bad($info['layouts'] > 1 ? 'appears more than once' : 'is unreadable (size or checksum)');
        }
        if ($info['depth'] !== 8 || $info['color'] !== 2 || $info['interlace'] !== 0) {
            return ['reason' => 'unsupported_format', 'detail' => 'only 8-bit RGB non-interlaced sprites are cropped (this one: bit depth '.$info['depth'].', color type '.$info['color'].', interlace '.$info['interlace'].')'];
        }
        $json = json_decode($info['layout'], true, 4);
        $fw = $json['frame_width'] ?? null;
        $fh = $json['frame_height'] ?? null;
        $positions = $json['positions'] ?? null;
        if (! is_int($fw) || ! is_int($fh) || ! is_array($positions) || ! array_is_list($positions)) {
            return $bad('is not the expected JSON');
        }
        if ($fw <= 0 || $fh <= 0) {
            return $bad('names a frame size of '.$fw.'x'.$fh);
        }
        if ($positions === [] || count($positions) > self::MAX_POSITIONS) {
            return $bad('names '.count($positions).' frames');
        }
        $held = min(self::FRAME_COUNT, count($positions)) * $fw * $fh;
        if ($held > self::HELD_PIXELS_CEILING) {
            return ['reason' => 'oversize', 'detail' => 'the newest frames are '.$fw.'x'.$fh.' px each, '.$held.' px together, over this tool\'s ceiling of '.self::HELD_PIXELS_CEILING.' px'];
        }
        $out = [];
        foreach ($positions as $k => $p) {
            $left = is_array($p) ? ($p['left'] ?? null) : null;
            $top = is_array($p) ? ($p['top'] ?? null) : null;
            if (! is_int($left) || ! is_int($top) || $left < 0 || $top < 0 || $left + $fw > $info['width'] || $top + $fh > $info['height']) {
                return $bad('places frame '.($k + 1).' outside the image');
            }
            $out[] = ['left' => $left, 'top' => $top];
        }

        return ['frame_width' => $fw, 'frame_height' => $fh, 'positions' => $out];
    }
}
