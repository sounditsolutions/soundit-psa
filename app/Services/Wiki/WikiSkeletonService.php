<?php

namespace App\Services\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use App\Models\WikiPage;

class WikiSkeletonService
{
    public function __construct(private readonly WikiPageService $pages) {}

    /** @return array<string, array{title: string, kind: WikiPageKind, body: string}> */
    public static function blueprint(): array
    {
        $factsBlock = fn (string $anchor) => "<!-- wiki:facts:{$anchor}:start -->\n_No facts recorded yet._\n<!-- wiki:facts:{$anchor}:end -->";

        return [
            'overview' => ['title' => 'Overview', 'kind' => WikiPageKind::Overview,
                'body' => "_Hot summary — maintained automatically once mining is enabled._\n"],
            'network' => ['title' => 'Network', 'kind' => WikiPageKind::Environment,
                'body' => "## Topology\n\n## Equipment\n\n"],
            'infrastructure' => ['title' => 'Infrastructure', 'kind' => WikiPageKind::Environment,
                'body' => "## Assets\n\n".$factsBlock('assets')."\n"],
            'm365' => ['title' => 'Microsoft 365', 'kind' => WikiPageKind::Environment,
                'body' => "## Security posture\n\n".$factsBlock('security-posture')."\n"],
            'security' => ['title' => 'Security stack', 'kind' => WikiPageKind::Environment,
                'body' => "## Tooling\n\n"],
            'backup' => ['title' => 'Backup', 'kind' => WikiPageKind::Environment,
                'body' => "## Coverage\n\n"],
            'applications' => ['title' => 'Applications', 'kind' => WikiPageKind::Environment,
                'body' => "## Line of business\n\n"],
            'known-issues' => ['title' => 'Known issues', 'kind' => WikiPageKind::Environment,
                'body' => "## Active\n\n## Resolved\n\n"],
            'history' => ['title' => 'History', 'kind' => WikiPageKind::Environment,
                'body' => "## Decisions\n\n"],
            'notes' => ['title' => 'Notes', 'kind' => WikiPageKind::Note,
                'body' => "_Free-form staff notes. The AI annotates but never rewrites this page._\n"],
        ];
    }

    public function ensureForClient(Client $client): void
    {
        $existing = WikiPage::forClient($client->id)->pluck('slug')->all();

        foreach (self::blueprint() as $slug => $def) {
            if (in_array($slug, $existing, true)) {
                continue;
            }
            $this->pages->create([
                'scope' => WikiScope::Client,
                'client_id' => $client->id,
                'slug' => $slug,
                'title' => $def['title'],
                'kind' => $def['kind'],
                'body_md' => $def['body'],
            ], WikiAuthorType::System, null, 'Skeleton seeded');
        }
    }
}
