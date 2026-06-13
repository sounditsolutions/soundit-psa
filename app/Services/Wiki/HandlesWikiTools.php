<?php

namespace App\Services\Wiki;

use App\Services\Wiki\Retrieval\WikiRetrieval;
use App\Support\WikiConfig;

/**
 * The three §6 retrieval tool handlers, shared by AssistantToolExecutor and
 * TriageToolExecutor. Requires the using class to expose `protected ?int $clientId`
 * (Assistant: nullable; Triage: always set from the ticket). A null clientId is
 * VALID here — it means global-only (spec §6), unlike other client-scoped tools.
 *
 * @property ?int $clientId The using class must expose this (global-only when null).
 */
trait HandlesWikiTools
{
    private function wikiListPages(): array
    {
        if (! WikiConfig::isEnabled()) {
            return ['error' => 'The wiki is not enabled.'];
        }

        return app(WikiRetrieval::class)->listPages($this->clientId);
    }

    private function wikiSearch(array $input): array|string
    {
        if (! WikiConfig::isEnabled()) {
            return ['error' => 'The wiki is not enabled.'];
        }
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'query is required'];
        }

        return app(WikiRetrieval::class)->searchSerialized($query, $this->clientId, min(max((int) ($input['limit'] ?? 10), 1), 20));
    }

    private function wikiGetPage(array $input): array
    {
        if (! WikiConfig::isEnabled()) {
            return ['error' => 'The wiki is not enabled.'];
        }
        $slug = trim((string) ($input['slug'] ?? ''));
        if ($slug === '') {
            return ['error' => 'slug is required'];
        }

        return app(WikiRetrieval::class)->getPageView($slug, $this->clientId) ?? ['error' => "Wiki page '{$slug}' not found in scope."];
    }
}
