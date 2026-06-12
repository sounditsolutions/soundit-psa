<?php

namespace App\Services\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\WikiLink;
use App\Models\WikiPage;
use Illuminate\Support\Facades\DB;

class WikiPageService
{
    public function __construct(
        private readonly WikiLinkParser $parser,
        private readonly WikiLinkResolver $resolver,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, WikiAuthorType $author, ?int $authorId = null, string $changeSummary = 'Created', ?array $sourceRefs = null): WikiPage
    {
        $scope = $attributes['scope'] instanceof WikiScope ? $attributes['scope'] : WikiScope::from($attributes['scope']);
        $clientId = $attributes['client_id'] ?? null;

        // The DB unique index does not dedupe NULL client_id (global) rows — enforce here.
        $exists = WikiPage::query()
            ->where('scope', $scope->value)
            ->where('client_id', $clientId)
            ->where('slug', $attributes['slug'])
            ->exists();
        if ($exists) {
            throw new \InvalidArgumentException("Wiki page '{$attributes['slug']}' already exists in this scope.");
        }

        $this->validateDeviation($attributes, $scope);

        return DB::transaction(function () use ($attributes, $author, $authorId, $changeSummary, $sourceRefs) {
            $page = WikiPage::create([
                ...$attributes,
                'created_by_type' => $author,
            ]);
            $this->writeRevision($page, $author, $authorId, $changeSummary, $sourceRefs);
            $this->rebuildLinks($page);

            return $page;
        });
    }

    public function updateBody(WikiPage $page, string $bodyMd, WikiAuthorType $author, ?int $authorId, string $changeSummary, ?array $sourceRefs = null): WikiPage
    {
        return DB::transaction(function () use ($page, $bodyMd, $author, $authorId, $changeSummary, $sourceRefs) {
            $page->update(['body_md' => $bodyMd]);
            $this->writeRevision($page, $author, $authorId, $changeSummary, $sourceRefs);
            $this->rebuildLinks($page); // spec §5.2: synchronous, same transaction

            return $page->refresh();
        });
    }

    public function archive(WikiPage $page, WikiAuthorType $author, ?int $authorId): void
    {
        DB::transaction(function () use ($page, $author, $authorId) {
            $page->update(['is_archived' => true]);
            $this->writeRevision($page, $author, $authorId, 'Archived');
        });
    }

    public function rebuildLinks(WikiPage $page): void
    {
        WikiLink::where('from_page_id', $page->id)->delete();

        foreach ($this->parser->parse($page->body_md) as $link) {
            $target = $this->resolver->resolve($link['target'], $page->client_id);
            WikiLink::create([
                'from_page_id' => $page->id,
                'to_page_id' => $target?->id,
                'target_slug' => $link['target'],
                'anchor_text' => $link['label'],
            ]);
        }
    }

    private function writeRevision(WikiPage $page, WikiAuthorType $author, ?int $authorId, string $changeSummary, ?array $sourceRefs = null): void
    {
        $page->revisions()->create([
            'body_md' => $page->body_md,
            'meta' => $page->meta,
            'author_type' => $author,
            'author_id' => $authorId,
            'change_summary' => $changeSummary,
            'source_refs' => $sourceRefs,
        ]);
    }

    /** Spec §4.5: deviations are client-scoped, parent must be a global page with no parent. */
    private function validateDeviation(array $attributes, WikiScope $scope): void
    {
        $kind = $attributes['kind'] instanceof WikiPageKind ? $attributes['kind'] : WikiPageKind::from($attributes['kind']);
        if ($kind !== WikiPageKind::Deviation) {
            return;
        }

        $parent = isset($attributes['parent_page_id']) ? WikiPage::find($attributes['parent_page_id']) : null;
        if ($scope !== WikiScope::Client || ($attributes['client_id'] ?? null) === null) {
            throw new \InvalidArgumentException('Deviation pages must be client-scoped.');
        }
        if (! $parent || $parent->scope !== WikiScope::Global || $parent->parent_page_id !== null) {
            throw new \InvalidArgumentException('Deviation parent must be a global page with no parent (depth 1).');
        }
    }
}
