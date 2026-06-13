<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\Wiki\WikiOverviewComposer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recomposes a client's AI hot-summary overview page. Enqueued after a fact-changing
 * mine (see MineTicketKnowledge) and by the wiki:overview command. Per-client
 * WithoutOverlapping serialises concurrent recomposes for the same client;
 * dontRelease() drops a blocked duplicate rather than re-queueing it — the composer's
 * content-hash skip makes a dropped recompose harmless (the next fact-changing mine
 * re-enqueues, and an unchanged fact set would no-op anyway).
 */
class ComposeClientOverview implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $clientId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('wiki-overview:'.$this->clientId))->dontRelease()];
    }

    public function handle(WikiOverviewComposer $composer): void
    {
        $client = Client::find($this->clientId);
        if ($client) {
            // Composer internally no-ops when the wiki is off, the daily budget is spent,
            // the fact set is unchanged since the last compose, or there are zero facts.
            $composer->compose($client);
        }
    }
}
