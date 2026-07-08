<?php

namespace App\Console\Commands;

use App\Enums\WikiAuthorType;
use App\Models\Client;
use App\Models\WikiPage;
use App\Services\Wiki\WikiPageService;
use App\Services\Wiki\WikiSections;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Console\Command;

class WikiImportSiteNotes extends Command
{
    protected $signature = 'wiki:import-site-notes';

    protected $description = 'Seed each client\'s wiki notes page from clients.site_notes (idempotent)';

    public function handle(WikiSkeletonService $skeleton, WikiPageService $pages): int
    {
        $imported = 0;

        Client::query()->whereNotNull('site_notes')->where('site_notes', '!=', '')
            ->each(function (Client $client) use ($skeleton, $pages, &$imported) {
                $skeleton->ensureForClient($client);

                $notes = WikiPage::forClient($client->id)->where('slug', 'notes')->first();
                if (! $notes || ($notes->meta['site_notes_imported_at'] ?? null)) {
                    return;
                }

                if (array_key_exists('imported-site-notes', WikiSections::split($notes->body_md))) {
                    $this->warn("Skipping {$client->name}: 'Imported site notes' section already exists.");

                    return;
                }

                $body = rtrim($notes->body_md)."\n\n## Imported site notes\n\n".trim($client->site_notes)."\n";
                $notes = $pages->updateBody($notes, $body, WikiAuthorType::System, null, 'Imported from clients.site_notes');
                $notes->update(['meta' => array_merge($notes->meta ?? [], [
                    'site_notes_imported_at' => now()->toIso8601String(),
                ])]);
                $imported++;
            });

        $this->info("Imported site notes for {$imported} client(s).");

        return self::SUCCESS;
    }
}
