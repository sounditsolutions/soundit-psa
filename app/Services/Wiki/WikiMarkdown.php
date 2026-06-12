<?php

namespace App\Services\Wiki;

use App\Helpers\MarkdownRenderer;
use App\Models\WikiPage;

class WikiMarkdown
{
    public function __construct(
        private readonly WikiLinkParser $parser,
        private readonly WikiLinkResolver $resolver,
    ) {}

    /** Render a page body (or an explicit markdown string in the page's scope) to sanitized HTML. */
    public function render(WikiPage $page, ?string $markdown = null): string
    {
        $markdown ??= $page->body_md;
        $clientId = $page->client_id;

        foreach ($this->parser->parse($markdown) as $link) {
            $target = $this->resolver->resolve($link['target'], $clientId);
            $label = $link['label'] ?? ($target?->title ?? $link['target']);
            $replacement = $target
                ? '['.$label.']('.$this->urlFor($target).')'
                : $label; // unresolved: plain text; wiki_links records the dead link

            // Replace both [[t]] and [[t|label]] occurrences of this target.
            $markdown = preg_replace(
                '/\[\[\s*'.preg_quote($link['target'], '/').'\s*(\|[^\]]*)?\]\]/',
                $replacement,
                $markdown
            );
        }

        return MarkdownRenderer::render($markdown) ?? '';
    }

    private function urlFor(WikiPage $page): string
    {
        return $page->client_id
            ? route('clients.wiki.show', [$page->client_id, $page->slug])
            : route('wiki.show', $page->slug);
    }
}
