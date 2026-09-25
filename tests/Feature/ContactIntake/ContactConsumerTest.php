<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Mcp\TicketTimeline;
use App\Services\Wiki\Mining\WikiTicketContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ContactConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_consumers_exclude_form_notes_and_preserve_private_notes(): void
    {
        Bus::fake();
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);
        foreach (['ORDINARY_PRIVATE_CONTROL' => false, 'FORM_UNVERIFIED_CONTROL' => true] as $body => $origin) {
            $note = new TicketNote;
            $note->forceFill([
                'ticket_id' => $ticket->id, 'body' => $body,
                'is_private' => true, 'note_type' => NoteType::Reply,
                'who_type' => WhoType::Agent, 'noted_at' => now(),
                'author_name' => 'Synthetic', 'contact_intake_origin' => $origin,
            ])->save();
        }
        $outputs = [
            app(WikiTicketContext::class)->build($ticket),
            json_encode(app(TicketTimeline::class)->page($ticket, ['types' => ['note']])),
            json_encode((new AssistantToolExecutor($ticket, $ticket->client_id))->execute('get_ticket_notes', ['ticket_id' => $ticket->id])),
        ];
        foreach ($outputs as $output) {
            $this->assertStringContainsString('ORDINARY_PRIVATE_CONTROL', $output);
            $this->assertStringNotContainsString('FORM_UNVERIFIED_CONTROL', $output);
        }
        // Human staff timeline deliberately retains the material for review.
        $staff = json_encode(app(TicketTimeline::class)->page($ticket, ['types' => ['note']], models: true));
        $this->assertStringContainsString('FORM_UNVERIFIED_CONTROL', $staff);
    }
}
