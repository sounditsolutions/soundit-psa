<?php

namespace App\Services\Wiki;

class WikiLinkParser
{
    /**
     * Extract [[target]] / [[target|label]] links.
     *
     * @return array<int, array{target: string, label: ?string}>
     */
    public function parse(string $markdown): array
    {
        if (! preg_match_all('/\[\[([^\]\|\n]+)(?:\|([^\]\n]+))?\]\]/', $markdown, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $links = [];
        foreach ($matches as $match) {
            $target = trim($match[1]);
            if ($target === '' || isset($links[$target])) {
                continue;
            }
            $links[$target] = [
                'target' => $target,
                'label' => isset($match[2]) && trim($match[2]) !== '' ? trim($match[2]) : null,
            ];
        }

        return array_values($links);
    }
}
