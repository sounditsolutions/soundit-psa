<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Wiki\WikiOverviewComposer;
use Illuminate\Console\Command;

class WikiOverviewCommand extends Command
{
    protected $signature = 'wiki:overview {client? : Client id} {--all : Recompose every client}';

    protected $description = 'Recompose the AI hot-summary overview for a client (or all clients).';

    public function handle(WikiOverviewComposer $composer): int
    {
        $clients = $this->option('all')
            ? Client::query()->get()
            : Client::query()->whereKey($this->argument('client'))->get();

        if ($clients->isEmpty()) {
            $this->error('No matching client. Pass a client id or --all.');

            return self::FAILURE;
        }

        foreach ($clients as $client) {
            $composer->compose($client);
            $this->line("Recomposed overview for {$client->name} (#{$client->id}).");
        }

        return self::SUCCESS;
    }
}
