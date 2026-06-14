<?php

namespace App\Console\Commands;

use App\Services\Wiki\WikiExportService;
use Illuminate\Console\Command;

class WikiExportCommand extends Command
{
    protected $signature = 'wiki:export
        {client? : Optional client ID — export only this client\'s pages}
        {--all : Export all clients (default when no client argument given)}
        {--path= : Override the output directory (must be inside storage/app, not under public/)}
        {--include-archived : Include archived pages in the export}';

    protected $description = 'Dump wiki pages to an Obsidian-shaped vault (identifier-only frontmatter, bodies scanned on egress).';

    public function handle(WikiExportService $service): int
    {
        $clientId = $this->argument('client') ? (int) $this->argument('client') : null;
        $path = $this->option('path') ?: null;
        $includeArchived = (bool) $this->option('include-archived');

        try {
            $result = $service->export($clientId, $path, $includeArchived);
        } catch (\RuntimeException $e) {
            $this->error('Export aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Exported {$result['written']} page(s) to: {$result['path']}");

        if ($result['withheld'] !== []) {
            $this->warn(count($result['withheld']).' page(s) withheld (failed content-safety scan): '.implode(', ', $result['withheld']));
        }

        return self::SUCCESS;
    }
}
