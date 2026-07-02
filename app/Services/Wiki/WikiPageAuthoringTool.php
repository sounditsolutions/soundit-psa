<?php

namespace App\Services\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Support\WikiConfig;

class WikiPageAuthoringTool
{
    private const MAX_BODY_LENGTH = 200000;

    private const AI_MARKER_PREFIX = '> AI-authored draft by ';

    public function __construct(
        private readonly WikiPageService $pages,
        private readonly WikiRedactor $redactor,
    ) {}

    /** @return array<string, mixed> */
    public function create(array $input, ?int $clientId, ?int $userId): array
    {
        $base = $this->prepare($input, $clientId, $userId, 'wiki_create_page');
        if (isset($base['error'])) {
            return $base;
        }

        /** @var User $actor */
        $actor = $base['actor'];
        $body = $this->withAiMarker($base['body_md'], $actor);
        $meta = $this->aiMeta(null, $actor, 'wiki_create_page');
        $sourceRefs = $this->sourceRefs($actor, 'wiki_create_page');
        $summary = $base['change_summary'] ?: 'Created by MCP wiki_create_page';

        try {
            $page = $this->pages->create([
                'scope' => WikiScope::Global,
                'client_id' => null,
                'slug' => $base['slug'],
                'title' => $base['title'],
                'kind' => WikiPageKind::Runbook,
                'parent_page_id' => null,
                'body_md' => $body,
                'meta' => $meta,
            ], WikiAuthorType::Ai, $actor->id, $summary, $sourceRefs);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        return $this->result($page);
    }

    /** @return array<string, mixed> */
    public function update(array $input, ?int $clientId, ?int $userId): array
    {
        $base = $this->prepare($input, $clientId, $userId, 'wiki_update_page');
        if (isset($base['error'])) {
            return $base;
        }

        $page = WikiPage::active()
            ->globalScope()
            ->where('slug', $base['slug'])
            ->first();

        if (! $page) {
            return ['error' => "Global wiki page '{$base['slug']}' not found."];
        }

        if ($page->client_id !== null || $page->scope !== WikiScope::Global) {
            return ['error' => 'wiki_update_page can only update global SOP/runbook pages.'];
        }

        if ($page->kind !== WikiPageKind::Runbook) {
            return ['error' => 'wiki_update_page can only update runbook/SOP pages.'];
        }

        /** @var User $actor */
        $actor = $base['actor'];
        $body = $this->withAiMarker($base['body_md'], $actor);
        $meta = $this->aiMeta($page, $actor, 'wiki_update_page');
        $sourceRefs = $this->sourceRefs($actor, 'wiki_update_page');
        $summary = $base['change_summary'] ?: 'Updated by MCP wiki_update_page';

        $page = $this->pages->updateContent(
            $page,
            $base['title'],
            $body,
            $meta,
            WikiAuthorType::Ai,
            $actor->id,
            $summary,
            $sourceRefs,
        );

        return $this->result($page);
    }

    /**
     * @return array{actor: User, slug: string, title: string, body_md: string, change_summary: string}|array{error: string}
     */
    private function prepare(array $input, ?int $clientId, ?int $userId, string $tool): array
    {
        if (! WikiConfig::isEnabled()) {
            return ['error' => 'The wiki is not enabled.'];
        }

        if ($clientId !== null) {
            return ['error' => 'client_id must be omitted for wiki page authoring writes.'];
        }

        $actor = $userId !== null ? User::find($userId) : null;
        if ($actor === null) {
            return ['error' => "AI actor user is required for {$tool}."];
        }

        $slug = trim((string) ($input['slug'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $body = (string) ($input['body_md'] ?? '');
        $summary = trim((string) ($input['change_summary'] ?? ''));

        $validationError = $this->validateInput($slug, $title, $body, $summary);
        if ($validationError !== null) {
            return ['error' => $validationError];
        }

        if ($this->hasSafetyViolation([$title, $body, $summary])) {
            return ['error' => 'Submitted wiki page failed content safety scan.'];
        }

        return [
            'actor' => $actor,
            'slug' => $slug,
            'title' => $title,
            'body_md' => $body,
            'change_summary' => $summary,
        ];
    }

    private function validateInput(string $slug, string $title, string $body, string $summary): ?string
    {
        if ($slug === '' || strlen($slug) > 255 || preg_match('/^[a-z0-9][a-z0-9\/-]*$/', $slug) !== 1) {
            return 'slug must be a lowercase wiki slug under runbooks/ or sops/.';
        }

        if (! preg_match('/^(?:runbooks|sops)\/[a-z0-9](?:[a-z0-9\/-]*[a-z0-9])?$/', $slug) || str_contains($slug, '//')) {
            return 'slug must start with runbooks/ or sops/ and include a page name.';
        }

        if ($title === '' || strlen($title) > 255) {
            return 'title is required and must be 255 characters or fewer.';
        }

        if (trim($body) === '' || strlen($body) > self::MAX_BODY_LENGTH) {
            return 'body_md is required and must be 200000 characters or fewer.';
        }

        if (strlen($summary) > 255) {
            return 'change_summary must be 255 characters or fewer.';
        }

        return null;
    }

    /** @param  array<int, string>  $values */
    private function hasSafetyViolation(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== '' && $this->redactor->scan($value) !== []) {
                return true;
            }
        }

        return false;
    }

    private function withAiMarker(string $body, User $actor): string
    {
        $body = preg_replace('/^\s*>\s*AI-authored draft by [^\r\n]+\.\s*(?:\r?\n){1,2}/', '', $body) ?? $body;

        return self::AI_MARKER_PREFIX.$this->actorName($actor).".\n\n".$body;
    }

    private function actorName(User $actor): string
    {
        $name = trim((string) $actor->name);
        $name = preg_replace('/[^A-Za-z0-9 ._-]+/', '', $name) ?? '';

        return $name !== '' ? $name : 'AI actor';
    }

    /** @return array<string, mixed> */
    private function aiMeta(?WikiPage $page, User $actor, string $tool): array
    {
        return [
            ...($page->meta ?? []),
            'ai_authored' => true,
            'ai_author_user_id' => $actor->id,
            'ai_author_name' => $this->actorName($actor),
            'ai_author_tool' => $tool,
            'ai_authored_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function sourceRefs(User $actor, string $tool): array
    {
        return [[
            'type' => 'mcp_staff',
            'tool' => $tool,
            'actor_user_id' => $actor->id,
            'scope' => WikiScope::Global->value,
            'client_id' => null,
            'submitted_at' => now()->toIso8601String(),
        ]];
    }

    /** @return array<string, mixed> */
    private function result(WikiPage $page): array
    {
        $revision = $page->revisions()->first();

        return [
            'page_id' => $page->id,
            'revision_id' => $revision?->id,
            'scope' => $page->scope->value,
            'client_id' => $page->client_id,
            'slug' => $page->slug,
            'title' => $page->title,
            'kind' => $page->kind->value,
            'ai_authored' => (bool) ($page->meta['ai_authored'] ?? false),
        ];
    }
}
