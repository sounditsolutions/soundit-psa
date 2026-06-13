<?php

namespace Tests\Feature\Wiki;

use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Jobs\MineTicketKnowledge;
use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WikiMiningTriggerTest extends TestCase
{
    use RefreshDatabase;

    private function enableAutoMine(): void
    {
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('wiki_auto_mine', '1');
    }

    public function test_dispatches_mining_job_when_non_t2t_ticket_closed_with_resolution(): void
    {
        Bus::fake();
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Manual,
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        // Simulate close with resolution
        $ticket->update([
            'status' => TicketStatus::Closed,
            'resolution' => 'Fixed the issue by restarting the service.',
        ]);

        Bus::assertDispatched(MineTicketKnowledge::class, fn ($job) => true);
    }

    public function test_dispatches_mining_job_when_helpdesk_button_ticket_closed_with_resolution(): void
    {
        Bus::fake();
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'source' => TicketSource::HelpdeskButton,
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $ticket->update([
            'status' => TicketStatus::Closed,
            'resolution' => 'Resolved via remote session.',
        ]);

        Bus::assertDispatched(MineTicketKnowledge::class, fn ($job) => true);
    }

    public function test_does_not_dispatch_mining_when_closed_without_resolution(): void
    {
        Bus::fake();
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Manual,
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $ticket->update(['status' => TicketStatus::Closed]);

        Bus::assertNotDispatched(MineTicketKnowledge::class);
    }
}
