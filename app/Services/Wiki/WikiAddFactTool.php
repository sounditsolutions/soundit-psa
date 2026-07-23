<?php

namespace App\Services\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\Wiki\Mining\WikiFactExtractor;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Support\WikiConfig;

class WikiAddFactTool
{
    private const MAX_STATEMENT_LENGTH = 300;

    public function __construct(
        private readonly WikiFactService $facts,
        private readonly WikiSkeletonService $skeleton,
        private readonly WikiComposerService $composer,
        private readonly WikiRedactor $redactor,
    ) {}

    /** @return array<string, mixed> */
    public function execute(array $input, ?int $clientId, ?int $userId): array
    {
        if (! WikiConfig::isEnabled()) {
            return ['error' => 'The wiki is not enabled.'];
        }

        $actor = $userId !== null ? User::find($userId) : null;
        if ($actor === null) {
            return ['error' => 'AI actor user is required for wiki_add_fact.'];
        }

        $scope = mb_strtolower(trim((string) ($input['scope'] ?? '')));
        if (! in_array($scope, [WikiScope::Client->value, WikiScope::Global->value], true)) {
            return ['error' => 'scope must be either client or global.'];
        }

        $pageSlug = trim((string) ($input['page_slug'] ?? ''));
        $anchor = mb_strtolower(trim((string) ($input['section_anchor'] ?? '')));
        $subjectKey = trim((string) ($input['subject_key'] ?? ''));
        $statement = trim((string) ($input['statement'] ?? ''));

        $validationError = $this->validateInput($pageSlug, $anchor, $subjectKey, $statement);
        if ($validationError !== null) {
            return ['error' => $validationError];
        }

        // Scrub credential-shaped values from the free-text fact fields
        // (subject_key/statement) (psa-tk87) — the secret value never reaches storage.
        // slug and anchor carry structural charsets (redact is a no-op there, and they
        // are used as lookup keys). The injection/marker content-safety hard-block that
        // used to run before this point was REMOVED per psa-fctq (Charlie full-off): it
        // was false-positiving legit staff runbooks.
        $subjectKey = $this->redactor->redact($subjectKey);
        $statement = $this->redactor->redact($statement);

        if ($scope === WikiScope::Client->value && ! $this->isClientTarget($pageSlug, $anchor)) {
            return ['error' => 'page_slug and section_anchor are not a valid client wiki fact target.'];
        }

        $page = $scope === WikiScope::Client->value
            ? $this->clientPage($clientId, $pageSlug)
            : $this->globalPage($pageSlug);

        if (is_array($page)) {
            return $page;
        }

        if ($page->kind === WikiPageKind::Overview) {
            return ['error' => 'wiki_add_fact cannot write to overview pages.'];
        }
        if ($page->kind === WikiPageKind::Deviation) {
            return ['error' => 'wiki_add_fact cannot write to deviation pages.'];
        }
        if (! $this->sectionExists($page, $anchor)) {
            return ['error' => 'section_anchor does not exist on the target wiki page.'];
        }

        $sourceRefs = [[
            'type' => 'mcp_staff',
            'tool' => 'wiki_add_fact',
            'actor_user_id' => $actor->id,
            'scope' => $scope,
            'client_id' => $page->client_id,
            'submitted_at' => now()->toIso8601String(),
        ]];

        $fact = $this->facts->upsertCorrectionFact(
            $page,
            $anchor,
            $subjectKey,
            $statement,
            $sourceRefs,
            confirmedBy: $actor->id,
        );

        $composed = $this->composer->composeSection(
            $page->fresh(),
            $anchor,
            WikiAuthorType::Ai,
            $actor->id,
            $sourceRefs,
            "Recomposed '{$anchor}' from MCP wiki_add_fact",
        );

        return [
            'fact_id' => $fact->id,
            'page_id' => $fact->page_id,
            'scope' => $fact->scope->value,
            'client_id' => $fact->client_id,
            'page_slug' => $page->slug,
            'section_anchor' => $fact->section_anchor,
            'subject_key' => $fact->subject_key,
            'status' => $fact->status->value,
            'source_type' => $fact->source_type->value,
            'pinned' => (bool) $fact->pinned,
            'composed' => $composed,
        ];
    }

    private function validateInput(string $pageSlug, string $anchor, string $subjectKey, string $statement): ?string
    {
        if ($pageSlug === '' || strlen($pageSlug) > 255 || preg_match('/^[A-Za-z0-9][A-Za-z0-9\/_-]*$/', $pageSlug) !== 1) {
            return 'page_slug must be a wiki slug such as infrastructure or runbooks/user-onboarding.';
        }

        if ($anchor === '' || strlen($anchor) > 100 || preg_match('/^[a-z0-9][a-z0-9-]*$/', $anchor) !== 1) {
            return 'section_anchor must be a markdown section anchor such as assets or security-posture.';
        }

        if ($subjectKey === '' || strlen($subjectKey) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $subjectKey) === 1) {
            return 'subject_key is required and must be 255 characters or fewer.';
        }

        if ($statement === '' || strlen($statement) > self::MAX_STATEMENT_LENGTH) {
            return 'statement is required and must be 300 characters or fewer.';
        }

        return null;
    }

    private function isClientTarget(string $pageSlug, string $anchor): bool
    {
        $allowed = WikiFactExtractor::TARGETS[$pageSlug] ?? null;

        return is_array($allowed) && in_array($anchor, $allowed, true);
    }

    private function sectionExists(WikiPage $page, string $anchor): bool
    {
        return array_key_exists($anchor, WikiSections::split($page->body_md));
    }

    /** @return WikiPage|array{error: string} */
    private function clientPage(?int $clientId, string $pageSlug): WikiPage|array
    {
        if ($clientId === null) {
            return ['error' => 'client_id is required for wiki_add_fact client-scope writes.'];
        }

        $client = Client::find($clientId);
        if ($client === null) {
            return ['error' => 'Client not found for wiki_add_fact.'];
        }

        $this->skeleton->ensureForClient($client);

        $page = WikiPage::active()
            ->forClient($client->id)
            ->where('slug', $pageSlug)
            ->first();

        return $page ?? ['error' => "Wiki page '{$pageSlug}' not found in client scope."];
    }

    /** @return WikiPage|array{error: string} */
    private function globalPage(string $pageSlug): WikiPage|array
    {
        $page = WikiPage::active()
            ->globalScope()
            ->where('slug', $pageSlug)
            ->first();

        return $page ?? ['error' => "Global wiki page '{$pageSlug}' not found."];
    }
}
