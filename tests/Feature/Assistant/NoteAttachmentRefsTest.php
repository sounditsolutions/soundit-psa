<?php

namespace Tests\Feature\Assistant;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Attachment DISCOVERY on the note-serving verbs (T-22774, T-22777).
 *
 * get_ticket_attachment can fetch a file given its id — but the served note
 * bodies are strip_tags'd, which removes the /attachments/{id}/... links, and
 * inline email images never had a body link at all. So real attachments were
 * undiscoverable over MCP: the operator agent could see in the cockpit that a
 * client sent photos, and had no way to learn their ids. These tests pin the
 * fix: get_ticket_notes and get_ticket_detail serve metadata refs (id,
 * filename, mime, size, is_inline) for every live attachment on a served note,
 * and get_ticket_detail also lists ticket-level attachments. Refs only — the
 * bytes still go through get_ticket_attachment's ceilings and refusals.
 */
class NoteAttachmentRefsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function note(Ticket $ticket, string $body = 'Client reply with photos'): TicketNote
    {
        return TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Tracey',
            'body' => $body,
            'note_type' => 'reply',
            'noted_at' => now(),
        ]);
    }

    private function attachmentOn(
        string $attachableType,
        int $attachableId,
        string $filename = 'photo.jpg',
        string $mime = 'image/jpeg',
        bool $inline = false,
    ): Attachment {
        return Attachment::create([
            'filename' => $filename,
            'original_filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => 12345,
            'storage_path' => "attachments/tmp/{$filename}",
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
            'is_inline' => $inline,
        ]);
    }

    public function test_get_ticket_notes_serves_attachment_refs_including_inline(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $note = $this->note($ticket);
        $regular = $this->attachmentOn(TicketNote::class, $note->id, 'photo.jpg', 'image/jpeg', false);
        $inline = $this->attachmentOn(TicketNote::class, $note->id, 'screenshot.png', 'image/png', true);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_notes', ['ticket_id' => $ticket->id]);

        $this->assertArrayNotHasKey('error', $result);
        $served = collect($result)->firstWhere('author', 'Tracey');
        $this->assertNotNull($served);
        $this->assertArrayHasKey('attachments', $served);
        $this->assertCount(2, $served['attachments']);

        $byId = collect($served['attachments'])->keyBy('attachment_id');
        $this->assertSame('photo.jpg', $byId[$regular->id]['filename']);
        $this->assertSame('image/jpeg', $byId[$regular->id]['mime_type']);
        $this->assertSame(12345, $byId[$regular->id]['size_bytes']);
        $this->assertFalse($byId[$regular->id]['is_inline']);
        // The inline arm is T-22777: an image pasted into an email body has no
        // link in any note text, so the ref here is its ONLY discovery lane.
        $this->assertSame('screenshot.png', $byId[$inline->id]['filename']);
        $this->assertTrue($byId[$inline->id]['is_inline']);
    }

    public function test_refs_are_metadata_only_and_absent_notes_get_a_stable_empty_list(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $bare = $this->note($ticket, 'No files here');
        $with = $this->note($ticket, 'One file');
        $this->attachmentOn(TicketNote::class, $with->id);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_notes', ['ticket_id' => $ticket->id]);

        $this->assertArrayNotHasKey('error', $result);
        foreach ($result as $servedNote) {
            $this->assertArrayHasKey('attachments', $servedNote);
            $this->assertIsArray($servedNote['attachments']);
            foreach ($servedNote['attachments'] as $ref) {
                // Discovery must not become an exfiltration lane: refs never
                // carry bytes; content flows only through get_ticket_attachment.
                $this->assertArrayNotHasKey('data_base64', $ref);
                $this->assertArrayNotHasKey('storage_path', $ref);
            }
        }
        $counts = collect($result)->map(fn ($n) => count($n['attachments']))->all();
        $this->assertContains(0, $counts);
        $this->assertContains(1, $counts);
    }

    public function test_soft_deleted_attachments_are_not_served(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $note = $this->note($ticket);
        $kept = $this->attachmentOn(TicketNote::class, $note->id, 'kept.pdf', 'application/pdf');
        $gone = $this->attachmentOn(TicketNote::class, $note->id, 'gone.pdf', 'application/pdf');
        $gone->delete();

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_notes', ['ticket_id' => $ticket->id]);

        $served = collect($result)->firstWhere('author', 'Tracey');
        $ids = collect($served['attachments'])->pluck('attachment_id');
        $this->assertTrue($ids->contains($kept->id));
        $this->assertFalse($ids->contains($gone->id));
    }

    public function test_get_ticket_detail_serves_ticket_level_and_per_note_refs(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $onTicket = $this->attachmentOn(Ticket::class, $ticket->id, 'diagram.pdf', 'application/pdf');
        $note = $this->note($ticket);
        $onNote = $this->attachmentOn(TicketNote::class, $note->id, 'screenshot.png', 'image/png', true);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_detail', ['ticket_id' => $ticket->id]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayHasKey('attachments', $result);
        $this->assertSame([$onTicket->id], collect($result['attachments'])->pluck('attachment_id')->all());
        $this->assertSame('diagram.pdf', $result['attachments'][0]['filename']);

        $served = collect($result['recent_notes'])->firstWhere('author', 'Tracey');
        $this->assertNotNull($served);
        $this->assertSame([$onNote->id], collect($served['attachments'])->pluck('attachment_id')->all());
        $this->assertTrue($served['attachments'][0]['is_inline']);
    }
}
