<?php

namespace App\Services\Wiki;

use Illuminate\Support\Str;

class WikiSections
{
    /**
     * Split markdown into ## sections. Key '' holds the preamble before the first ##.
     *
     * Round-trip contract: for newline-terminated input, join(split($md)) === $md
     * byte-for-byte. Input without a trailing newline is normalized to gain
     * exactly one.
     *
     * @return array<string, array{heading: string, content: string}>
     */
    public static function split(string $markdown): array
    {
        $lines = explode("\n", $markdown);
        if (end($lines) === '') {
            // A newline-terminated doc explodes into a trailing '' element;
            // drop it so join() doesn't grow the doc by one "\n" per round trip.
            array_pop($lines);
        }
        $sections = ['' => ['heading' => '', 'content' => '']];
        $current = '';

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+)$/', $line, $m)) {
                $current = self::anchorFor($m[1]);
                $sections[$current] = ['heading' => trim($m[1]), 'content' => ''];

                continue;
            }
            $sections[$current]['content'] .= $line."\n";
        }

        return $sections; // '' preamble key is always present and first
    }

    /** Rebuild markdown from split() output. */
    public static function join(array $sections): string
    {
        $out = '';
        foreach ($sections as $anchor => $section) {
            if ($anchor === '') {
                $out .= $section['content'];

                continue;
            }
            $out .= '## '.$section['heading']."\n".$section['content'];
        }

        return $out;
    }

    /** Replace the body of one section (heading kept), returning the new document. */
    public static function replace(string $markdown, string $anchor, string $newContent): string
    {
        $sections = self::split($markdown);
        if (! isset($sections[$anchor])) {
            return $markdown;
        }
        $sections[$anchor]['content'] = "\n".rtrim($newContent)."\n\n";

        return self::join($sections);
    }

    /**
     * Replace content between <!-- wiki:facts:{anchor}:start/end --> markers.
     * If the markers are missing, append them (with content) at the end of that section.
     */
    public static function spliceMarkers(string $markdown, string $anchor, string $content): string
    {
        $start = "<!-- wiki:facts:{$anchor}:start -->";
        $end = "<!-- wiki:facts:{$anchor}:end -->";
        $block = $start."\n".rtrim($content)."\n".$end;

        $hasStart = str_contains($markdown, $start);
        $hasEnd = str_contains($markdown, $end);

        if ($hasStart && $hasEnd) {
            $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s';

            return preg_replace($pattern, $block, $markdown, 1);
        }

        if ($hasStart || $hasEnd) {
            // Orphaned marker (crash mid-write, manual edit): strip every stray
            // marker before appending a fresh pair, otherwise the next splice
            // would pair the orphan with the new block's counterpart and
            // swallow the human content in between.
            $markdown = str_replace([$start."\n", $end."\n", $start, $end], '', $markdown);
        }

        $sections = self::split($markdown);
        if (! isset($sections[$anchor])) {
            // No such section: append a new one named after the anchor.
            return rtrim($markdown)."\n\n## ".Str::headline($anchor)."\n\n".$block."\n";
        }
        $sections[$anchor]['content'] = rtrim($sections[$anchor]['content'])."\n\n".$block."\n\n";

        return self::join($sections);
    }

    public static function anchorFor(string $heading): string
    {
        return Str::slug($heading);
    }
}
