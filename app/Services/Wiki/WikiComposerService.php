<?php

namespace App\Services\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiFactStatus;
use App\Models\WikiPage;

class WikiComposerService
{
    public function __construct(private readonly WikiPageService $pages) {}

    /**
     * Recompose one marked section from its facts. Returns true when the page changed.
     *
     * @param  WikiPage  $page  Must be freshly loaded; body_md is read directly for the change guard.
     */
    public function composeSection(
        WikiPage $page,
        string $anchor,
        WikiAuthorType $author = WikiAuthorType::System,
        ?int $authorId = null,
        ?array $sourceRefs = null,
        ?string $changeSummary = null,
    ): bool {
        $facts = $page->facts()
            ->where('section_anchor', $anchor)
            ->whereNot('status', WikiFactStatus::Retired->value)
            ->orderBy('subject_key')
            ->get();

        $content = $facts->isEmpty()
            ? '_No facts recorded yet._'
            : $facts->map(function ($fact) {
                $line = '- '.$fact->statement;
                if ($fact->status === WikiFactStatus::Disputed) {
                    $line .= ' *(disputed)*';
                }

                return $line;
            })->implode("\n");

        $newBody = WikiSections::spliceMarkers($page->body_md, $anchor, $content);
        if ($newBody === $page->body_md) {
            return false;
        }

        $this->pages->updateBody(
            $page, $newBody, $author, $authorId,
            $changeSummary ?? "Recomposed '{$anchor}' from facts",
            $sourceRefs,
        );

        return true;
    }
}
