<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Jobs\GenerateTicketResolution;
use App\Jobs\MineTicketKnowledge;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\TicketResolutionDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AutoResolutionFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function enableAutoMine(): void
    {
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('wiki_auto_mine', '1');
    }

    // ── Observer tests ───────────────────────────────────────────────────────

    public function test_observer_dispatches_generate_on_terminal_transition_with_empty_resolution(): void
    {
        Bus::fake();
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $ticket->update(['status' => TicketStatus::Resolved]);

        Bus::assertDispatched(GenerateTicketResolution::class, fn ($job) => $job->ticketId === $ticket->id);
    }

    public function test_observer_dispatches_generate_on_closed_transition_with_empty_resolution(): void
    {
        Bus::fake();
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $ticket->update(['status' => TicketStatus::Closed]);

        Bus::assertDispatched(GenerateTicketResolution::class, fn ($job) => $job->ticketId === $ticket->id);
    }

    public function test_observer_does_not_dispatch_generate_when_resolution_present(): void
    {
        Bus::fake();
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        // Human resolves WITH a resolution — generate should NOT fire; mining SHOULD.
        $ticket->update([
            'status' => TicketStatus::Resolved,
            'resolution' => 'Replaced the NIC and confirmed LAN connectivity.',
        ]);

        Bus::assertNotDispatched(GenerateTicketResolution::class);
        Bus::assertDispatched(MineTicketKnowledge::class);
    }

    public function test_observer_does_not_dispatch_generate_when_auto_mine_off(): void
    {
        Bus::fake();
        Setting::setValue('wiki_enabled', '1'); // master on, mining off

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $ticket->update(['status' => TicketStatus::Resolved]);

        Bus::assertNotDispatched(GenerateTicketResolution::class);
    }

    public function test_observer_does_not_dispatch_generate_on_non_terminal_status_change(): void
    {
        Bus::fake();
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $ticket->update(['status' => TicketStatus::PendingClient]);

        Bus::assertNotDispatched(GenerateTicketResolution::class);
    }

    // ── Job tests ────────────────────────────────────────────────────────────

    public function test_job_applies_draft_and_marks_ai_drafted(): void
    {
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution' => null,
            'resolution_ai_drafted' => false,
        ]);

        $drafter = $this->createMock(TicketResolutionDrafter::class);
        $drafter->expects($this->once())
            ->method('draft')
            ->with($this->callback(fn ($t) => $t->id === $ticket->id), 'auto')
            ->willReturn('Replaced the NIC.');

        Bus::fake(); // suppress observer re-fire dispatches

        $job = new GenerateTicketResolution($ticket->id);
        $job->handle($drafter);

        $ticket->refresh();
        $this->assertSame('Replaced the NIC.', $ticket->resolution);
        $this->assertTrue($ticket->resolution_ai_drafted);
    }

    public function test_job_noop_on_null_draft(): void
    {
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution' => null,
            'resolution_ai_drafted' => false,
        ]);

        $drafter = $this->createMock(TicketResolutionDrafter::class);
        $drafter->expects($this->once())
            ->method('draft')
            ->willReturn(null);

        Bus::fake();

        $job = new GenerateTicketResolution($ticket->id);
        $job->handle($drafter);

        $ticket->refresh();
        $this->assertNull($ticket->resolution);
        $this->assertFalse($ticket->resolution_ai_drafted);
    }

    public function test_job_guard_skips_when_resolution_already_filled(): void
    {
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution' => 'Human already wrote this.',
            'resolution_ai_drafted' => false,
        ]);

        $drafter = $this->createMock(TicketResolutionDrafter::class);
        $drafter->expects($this->never())->method('draft');

        Bus::fake();

        $job = new GenerateTicketResolution($ticket->id);
        $job->handle($drafter);

        $ticket->refresh();
        $this->assertSame('Human already wrote this.', $ticket->resolution);
        $this->assertFalse($ticket->resolution_ai_drafted);
    }

    public function test_job_guard_skips_when_ticket_not_terminal(): void
    {
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $drafter = $this->createMock(TicketResolutionDrafter::class);
        $drafter->expects($this->never())->method('draft');

        Bus::fake();

        $job = new GenerateTicketResolution($ticket->id);
        $job->handle($drafter);

        $ticket->refresh();
        $this->assertNull($ticket->resolution);
    }

    public function test_job_guard_skips_when_auto_mine_off(): void
    {
        Setting::setValue('wiki_enabled', '1'); // master on, mining off

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution' => null,
        ]);

        $drafter = $this->createMock(TicketResolutionDrafter::class);
        $drafter->expects($this->never())->method('draft');

        Bus::fake();

        $job = new GenerateTicketResolution($ticket->id);
        $job->handle($drafter);

        $ticket->refresh();
        $this->assertNull($ticket->resolution);
    }

    // ── Loop-avoidance test ──────────────────────────────────────────────────

    /**
     * When the job writes the resolution (status unchanged), the observer re-fires.
     * The generate-branch checks wasChanged('status') — which is false on the resolution-
     * write — so GenerateTicketResolution is NOT dispatched again.
     * The mining branch IS dispatched (terminal + filled resolution + resolution changed).
     */
    public function test_job_save_does_not_re_dispatch_generate_but_does_dispatch_mining(): void
    {
        $this->enableAutoMine();

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution' => null,
            'resolution_ai_drafted' => false,
        ]);

        $drafter = $this->createMock(TicketResolutionDrafter::class);
        $drafter->method('draft')->willReturn('AI-generated resolution text.');

        // Fake the bus AFTER setup but BEFORE handle, so we can observe what the
        // observer dispatches when the job calls $ticket->save().
        Bus::fake();

        $job = new GenerateTicketResolution($ticket->id);
        $job->handle($drafter);

        // The job's save must NOT re-dispatch generate (loop guard).
        Bus::assertNotDispatched(GenerateTicketResolution::class);
        // The mining branch MUST fire (the wiki mining loop needs to pick it up).
        Bus::assertDispatched(MineTicketKnowledge::class, fn ($j) => true);
    }
}
