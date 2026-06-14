<?php

namespace App\Services\Wiki;

use App\Enums\WikiScope;
use App\Models\WikiPage;
use App\Services\Wiki\Mining\WikiRedactor;
use Illuminate\Support\Str;

class WikiExportService
{
    public function __construct(private readonly WikiRedactor $redactor) {}

    /**
     * Walk wiki pages to an Obsidian-shaped vault.
     *
     * Security controls:
     *   F1 — page bodies are scanned on egress; a scan hit writes a placeholder and records the slug.
     *   F2 — output path is fenced inside storage/app, never under public/ (the web docroot).
     *
     * @return array{path:string,written:int,withheld:array<int,string>}
     */
    public function export(
        ?int $clientId = null,
        ?string $path = null,
        bool $includeArchived = false,
    ): array {
        $base = $this->safeBase($path);

        $pages = WikiPage::query()
            ->when(! $includeArchived, fn ($q) => $q->where('is_archived', false))
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->with(['facts', 'client'])
            ->get();

        $written = 0;
        $withheld = [];

        foreach ($pages as $page) {
            if ($page->scope === WikiScope::Global) {
                $dir = $base.'/global/'.$page->kind->value;
            } else {
                $dir = $base.'/clients/'.$this->clientSlug($page).'/'.$page->kind->value;
            }

            if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Export failed to create {$dir}");
            }

            // SECURITY (gate F1): page bodies carry human/site_notes/pre-merge prose never
            // output-scanned — don't write a secret/injection to a plaintext vault.
            $body = $page->body_md;
            if ($this->redactor->scan($body) !== []) {
                $body = '[Wiki page body withheld: failed content-safety scan]';
                $withheld[] = $page->slug;
            }

            $file = $dir.'/'.basename($page->slug).'.md';
            file_put_contents($file, $this->frontmatter($page)."\n".$body);
            chmod($file, 0600);
            $written++;
        }

        return ['path' => $base, 'written' => $written, 'withheld' => $withheld];
    }

    /**
     * SECURITY (gate F2): fence the output root — inside storage/app, never the web docroot, no traversal.
     *
     * Steps:
     *   1. Reject any path containing ".." — defeats the most obvious traversal attempts before any
     *      filesystem access.
     *   2. Resolve the nearest existing ancestor via realpath() — the leaf directory doesn't exist yet,
     *      so a plain realpath($base) returns false. Walking up defeats symlink escapes too.
     *   3. Require the resolved anchor to sit inside realpath(storage_path('app')).
     *   4. Require the resolved anchor to NOT sit inside the web docroot — both realpath(public_path())
     *      AND realpath(storage_path('app/public')). The latter is the symlink TARGET of public/storage
     *      in a standard deploy, so a path resolving literally under storage/app/public/ is web-reachable
     *      even though it passes the storage fence. Guard against realpath() returning false (the dir may
     *      not exist) — only compare when it resolves.
     */
    private function safeBase(?string $path): string
    {
        $base = $path ?: storage_path('app/wiki-exports/'.now()->format('Ymd-His'));

        if (str_contains($base, '..')) {
            throw new \RuntimeException('Export path may not contain "..".');
        }

        $real = $this->realAncestor($base);
        $storage = realpath(storage_path('app'));
        $public = realpath(public_path());
        $storagePublic = realpath(storage_path('app/public'));

        // Check web-reachable locations FIRST — public/ is not under storage/app, so without this
        // ordering the storage check fires first and the caller gets a less informative message.
        // storage/app/public is the symlink target of public/storage, so it IS under the storage
        // fence but still web-reachable; reject it explicitly.
        if ($public !== false && str_starts_with($real, $public)) {
            throw new \RuntimeException('Refusing to export under the web docroot.');
        }
        if ($storagePublic !== false && str_starts_with($real, $storagePublic)) {
            throw new \RuntimeException('Refusing to export under storage/app/public (web-reachable via the public/storage symlink).');
        }

        if ($storage === false || ! str_starts_with($real, $storage)) {
            throw new \RuntimeException('Export must be written under storage/app.');
        }

        return $base;
    }

    /**
     * Walk up to the nearest EXISTING ancestor directory and return its real path.
     * This is necessary because the target leaf directory does not exist yet, so
     * realpath() would return false on it; and we must resolve symlinks before comparing.
     */
    private function realAncestor(string $path): string
    {
        $candidate = $path;

        // Ensure we always work with an absolute path so dirname() terminates.
        if (! str_starts_with($candidate, '/')) {
            $candidate = getcwd().'/'.$candidate;
        }

        // Walk up until we hit an existing directory (or the filesystem root).
        while ($candidate !== '/' && ! is_dir($candidate)) {
            $candidate = dirname($candidate);
        }

        $real = realpath($candidate);
        if ($real === false) {
            throw new \RuntimeException("Cannot resolve real path of export ancestor: {$candidate}");
        }

        return $real;
    }

    /**
     * Identifiers ONLY — no source ticket content reproduced (§9).
     *
     * Frontmatter contains: title, scope, kind, slug, exported_at,
     * fact status counts, and source type:id pairs from source_refs.
     * Fact statements, ticket bodies, and user prose are NEVER reproduced.
     */
    private function frontmatter(WikiPage $page): string
    {
        $byStatus = $page->facts->countBy(fn ($f) => $f->status->value);

        $refs = $page->facts
            ->flatMap(fn ($f) => $f->source_refs ?? [])
            ->map(fn ($r) => ($r['type'] ?? '?').': '.($r['id'] ?? '?'))
            ->unique()
            ->values();

        $lines = [
            '---',
            'title: '.$page->title,
            'scope: '.$page->scope->value,
            'kind: '.$page->kind->value,
            'slug: '.$page->slug,
            'exported_at: '.now()->toIso8601String(),
            'facts: '.$byStatus->map(fn ($c, $s) => "{$s}={$c}")->values()->implode(' '),
            'sources:',
        ];

        foreach ($refs as $ref) {
            $lines[] = '  - '.$ref;
        }

        return implode("\n", [...$lines, '---']);
    }

    /** @return string Slugified client name for vault directory routing */
    private function clientSlug(WikiPage $page): string
    {
        return Str::slug($page->client?->name ?? 'unknown');
    }
}
