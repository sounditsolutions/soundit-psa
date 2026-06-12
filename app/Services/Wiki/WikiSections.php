<?php

namespace App\Services\Wiki;

use Illuminate\Support\Str;

class WikiSections
{
    /**
     * Split markdown into ## sections. Key '' holds the preamble before the first ##.
     *
     * @return array<string, array{heading: string, content: string}>
     */
    public static function split(string $markdown): array
    {
        $lines = explode("\n", $markdown);
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

        if (str_contains($markdown, $start) && str_contains($markdown, $end)) {
            $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s';

            return preg_replace($pattern, $block, $markdown, 1);
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
