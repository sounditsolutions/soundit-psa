<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\ClientStage;
use App\Enums\NoteType;
use App\Enums\PersonType;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketCategoryChangeLog;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Agent\Escalation\ClientEscalationNoiseGate;
use App\Services\Mcp\TicketTimeline;
use App\Services\Triage\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** r2 must-fix controls for reader/action containment (card sPMnZ1l4, r1 review 01a0d620). */
class IntakeR2ContainmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function formTicket(array $attrs = []): Ticket
    {
        $ticket = Ticket::factory()->create($attrs + ['status' => TicketStatus::New]);
        $ticket->forceFill(['contact_intake_origin' => true])->save();

        return $ticket->fresh();
    }

    /** contract-replacement:12 — an unverified form ticket assigned to the intake owner is not human engagement. */
    public function test_unverified_form_ticket_does_not_suppress_a_real_client_escalation(): void
    {
        $client = Client::factory()->create();
        $owner = User::factory()->create(['is_active' => true]);
        $current = Ticket::factory()->create(['client_id' => $client->id, 'status' => TicketStatus::New]);
        $this->formTicket(['client_id' => $client->id, 'assignee_id' => $owner->id]);
        $run = new TechnicianRun;
        $run->id = 999999;
        $this->assertNull(app(ClientEscalationNoiseGate::class)->suppressionFor($current, $run));
        // Positive control: an ordinary assigned sibling still suppresses.
        Ticket::factory()->create(['client_id' => $client->id, 'status' => TicketStatus::New, 'assignee_id' => $owner->id]);
        $this->assertSame('human_engaged_sibling_assigned',
            app(ClientEscalationNoiseGate::class)->suppressionFor($current, $run)['suppression_kind'] ?? null);
    }

    /** diff:11 — the taxonomy change log (C-51) still records moves on an unverified form ticket. */
    public function test_category_change_on_unverified_form_ticket_is_still_logged(): void
    {
        $leaf = TicketCategory::create(['name' => 'Synthetic leaf']);
        $ticket = $this->formTicket();
        $ticket->update(['category_id' => $leaf->id]);
        $this->assertSame(1, TicketCategoryChangeLog::where('ticket_id', $ticket->id)->count());
    }

    /** diff:14 — shared builders degrade for a form ticket instead of throwing into staff callers. */
    public function test_shared_context_builders_degrade_without_leaking(): void
    {
        $ticket = $this->formTicket(['subject' => 'FORM_SUBJECT_MARKER']);
        $this->assertSame('', app(\App\Services\Triage\ClientSituationContextBuilder::class)->build($ticket));
        $this->assertSame('', app(\App\Services\Wiki\Mining\WikiTicketContext::class)->build($ticket));
        $this->assertSame(ContextBuilder::UNVERIFIED_WITHHELD, ContextBuilder::buildForTicket($ticket));
        $this->expectException(\InvalidArgumentException::class);
        app(TicketTimeline::class)->page($ticket, []);
    }

    private function portalPerson(Client $client, bool $companyWide): Person
    {
        return Person::create(['client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Portal', 'last_name' => 'User', 'email' => 'portal-'.($companyWide ? 'cw' : 'own').'@example.test',
            'is_active' => true, 'portal_enabled' => true, 'company_wide_access' => $companyWide]);
    }

    private function upload(Person $person, Ticket $ticket, ?int $noteId = null)
    {
        Storage::fake();

        return $this->actingAs($person, 'portal')->post(route('portal.tickets.attachments.store', $ticket),
            ['file' => UploadedFile::fake()->image('synthetic.png'), 'note_id' => $noteId], ['Accept' => 'application/json']);
    }

    /** contract-replacement:13 — portal upload uses the same guard as its siblings. */
    public function test_portal_upload_to_unverified_form_ticket_is_404(): void
    {
        Setting::setValue('portal_enabled', '1');
        $client = Client::factory()->create(['stage' => ClientStage::Active]);
        $person = $this->portalPerson($client, true);
        $ticket = $this->formTicket(['client_id' => $client->id, 'contact_id' => $person->id]);
        $this->upload($person, $ticket)->assertNotFound();
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_portal_upload_to_null_contact_ticket_without_company_wide_access_is_403(): void
    {
        Setting::setValue('portal_enabled', '1');
        $client = Client::factory()->create(['stage' => ClientStage::Active]);
        $person = $this->portalPerson($client, false);
        $notMine = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => null, 'status' => TicketStatus::New]);
        $this->upload($person, $notMine)->assertForbidden();
        $mine = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'status' => TicketStatus::New]);
        $this->upload($person, $mine)->assertOk();
    }

    public function test_portal_upload_never_links_to_contained_or_trashed_note(): void
    {
        Setting::setValue('portal_enabled', '1');
        $client = Client::factory()->create(['stage' => ClientStage::Active]);
        $person = $this->portalPerson($client, true);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'status' => TicketStatus::New]);
        $contained = new TicketNote(['ticket_id' => $ticket->id, 'body' => 'x', 'note_type' => NoteType::Reply,
            'who_type' => WhoType::EndUser, 'is_private' => true, 'noted_at' => now()]);
        $contained->forceFill(['contact_intake_origin' => true])->save();
        $trashed = TicketNote::create(['ticket_id' => $ticket->id, 'body' => 'y', 'note_type' => NoteType::Reply,
            'who_type' => WhoType::EndUser, 'is_private' => false, 'noted_at' => now()]);
        $trashed->delete();
        $visible = TicketNote::create(['ticket_id' => $ticket->id, 'body' => 'z', 'note_type' => NoteType::Reply,
            'who_type' => WhoType::EndUser, 'is_private' => false, 'noted_at' => now()]);
        foreach ([$contained->id, $trashed->id] as $noteId) {
            $this->upload($person, $ticket, $noteId)->assertOk();
            $this->assertSame(Ticket::class, Attachment::latest('id')->firstOrFail()->attachable_type);
        }
        // Positive control: a portal-visible note IS linked.
        $this->upload($person, $ticket, $visible->id)->assertOk();
        $this->assertSame(TicketNote::class, Attachment::latest('id')->firstOrFail()->attachable_type);
        $this->assertSame($visible->id, (int) Attachment::latest('id')->firstOrFail()->attachable_id);
    }
}
