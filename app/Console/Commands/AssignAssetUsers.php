<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\AssetUserAssignmentService;
use Illuminate\Console\Command;

class AssignAssetUsers extends Command
{
    protected $signature = 'assets:assign-users
        {--client= : Scope to a single client ID}
        {--dry-run : Log matches without writing}';

    protected $description = 'Auto-assign device users based on RMM last-logged-on-user data';

    public function handle(AssetUserAssignmentService $service): int
    {
        $clientId = $this->option('client');
        $dryRun = $this->option('dry-run');

        if ($clientId) {
            $client = Client::find($clientId);
            if (! $client) {
                $this->error("Client {$clientId} not found.");

                return 1;
            }
            $this->info("Scoping to client: {$client->name}");
        }

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be written.');
        }

        $stats = $service->assignAll($clientId ? (int) $clientId : null, $dryRun);

        $this->info("Processed: {$stats['processed']}");
        $this->info("  Matched (new):    {$stats['matched']}");
        $this->info("  Already linked:   {$stats['already_linked']}");
        $this->info("  No match:         {$stats['no_match']}");
        $this->info("  Ambiguous:        {$stats['ambiguous']}");

        return 0;
    }
}
