<?php

namespace App\Jobs;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\TicketResolutionDrafter;
use App\Support\AiConfig;
use App\Support\WikiConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class GenerateTicketResolution implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $ticketId,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('ticket-resolution:'.$this->ticketId))->dontRelease()];
    }

    public function handle(TicketResolutionDrafter $drafter): void
    {
        $ticket = Ticket::find($this->ticketId);

        if (! $ticket) {
            return;
        }

        // Guard: only if STILL terminal and STILL empty (a human/another path may have
        // filled the resolution since dispatch), and auto-mine is still on.
        $isTerminal = in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true);

        if (! $isTerminal || filled($ticket->resolution) || ! WikiConfig::autoMineEnabled()) {
            return;
        }

        // AI-drafting a resolution is entirely AI-driven; without a configured provider the
        // drafter throws deep inside AiClient (a TypeError on the null key). Skip cleanly —
        // the Client Wiki settings card warns the operator to configure an AI provider.
        if (! AiConfig::isConfigured()) {
            Log::info('[GenerateTicketResolution] Skipping — no AI provider configured', [
                'ticket_id' => $this->ticketId,
            ]);

            return;
        }

        // null on junk / no-substance / budget exhausted / unsafe output — no-op.
        $draft = $drafter->draft($ticket, 'auto');

        if ($draft === null) {
            return;
        }

        $ticket->resolution = $draft;
        $ticket->resolution_ai_drafted = true;

        // Saving fires the observer again. The generate-branch keys on wasChanged('status'),
        // which is false here (only resolution changed) — so no loop. The existing mining
        // branch (keyed on filled(resolution) + wasChanged('resolution')) fires once.
        $ticket->save();
    }
}
