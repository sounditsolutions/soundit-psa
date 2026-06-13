<?php

namespace App\Services\Wiki;

use App\Enums\WikiPageKind;
use App\Models\WikiPage;

class WikiCascadeService
{
    /**
     * Spec §4.5 merged view, most specific wins, section-level.
     *
     * @return array{body_md: string, deviation_anchors: array<int, string>}
     */
    public function mergedView(WikiPage $globalPage, int $clientId): array
    {
        $deviation = WikiPage::active()
            ->forClient($clientId)
            ->where('kind', WikiPageKind::Deviation->value)
            ->where('parent_page_id', $globalPage->id)
            ->first();

        if (! $deviation) {
            return ['body_md' => $globalPage->body_md, 'deviation_anchors' => []];
        }

        $globalSections = WikiSections::split($globalPage->body_md);
        $deviationSections = WikiSections::split($deviation->body_md);
        unset($deviationSections['']); // deviation preamble is ignored; deltas live in sections

        $marker = "*Client deviation* — overrides the standard runbook.\n\n";
        $anchors = [];
        $appendix = [];

        foreach ($deviationSections as $anchor => $section) {
            $anchors[] = $anchor;
            if (isset($globalSections[$anchor])) {
                $globalSections[$anchor]['content'] = "\n".$marker.trim($section['content'])."\n\n";
            } else {
                $appendix[$anchor] = $section;
            }
        }

        $merged = WikiSections::join($globalSections);
        foreach ($appendix as $section) {
            $merged = rtrim($merged)."\n\n## ".$section['heading']."\n\n".$marker.trim($section['content'])."\n";
        }

        return ['body_md' => $merged, 'deviation_anchors' => $anchors];
    }
}
