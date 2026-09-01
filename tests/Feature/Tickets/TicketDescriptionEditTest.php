<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketDescriptionChangeSource;
use App\Models\Ticket;
use App\Models\TicketDescriptionChangeLog;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * #992 — staff must be able to edit a ticket description from the web UI, and
 * every surface that rewrites one must leave a record.
 *
 * Charlie hit this live on 2026-09-01: a description named an unrelated
 * client, the field is CLIENT-VISIBLE, and the only mechanism that could fix
 * it was the agent surface. Three separate defects sit behind that one gap and
 * each has its own guard below:
 *
 *  1. no view posts `description`, so there is no editor at all;
 *  2. `Ticket::getRenderedDescriptionAttribute()` prefers `description_html`
 *     whenever it is non-null and NOTHING on any update path cleared it — so
 *     the obvious build is a silent no-op on email-originated tickets, i.e.
 *     precisely the ones a staff member needs to correct;
 *  3. the MCP path wrote an audit row and the web path wrote nothing.
 *
 * The audit seam is the TicketObserver, following the TicketCategoryChangeLog
 * precedent (Jeeves's ruling on #992, 2026-09-01): model-level, so web, MCP,
 * the email pipeline and anything added later are captured without opting in.
 */
class TicketDescriptionEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket create/update fire triage/technician job dispatches — not under test.
        Bus::fake();
    }

    // ── UI ──

    public function test_ticket_detail_offers_a_description_editor_loaded_with_raw_markdown(): void
    {
        $ticket = Ticket::factory()->create(['description' => 'Original **bold** text']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('id="descriptionEditForm"', false)
            ->assertSee('name="description"', false);

        // The editor must carry the RAW markdown, never the rendered HTML —
        // round-tripping rendered output back through the markdown column would
        // corrupt the stored source.
        $response->assertSee('Original **bold** text', false);
    }

    public function test_a_ticket_with_no_description_still_offers_the_editor(): void
    {
        // Phone and manual tickets routinely start empty; the old view rendered
        // the description card only when a description existed, which left no
        // way to ADD one.
        $ticket = Ticket::factory()->create(['description' => null]);

        $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('id="descriptionEditForm"', false);
    }

    // ── WRITE PATH ──

    public function test_staff_can_update_a_ticket_description_from_the_web(): void
    {
        $ticket = Ticket::factory()->create(['description' => 'Mentions the wrong client']);

        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), ['description' => 'Corrected text'])
            ->assertRedirect(route('tickets.show', $ticket));

        $this->assertSame('Corrected text', $ticket->fresh()->description);
    }

    public function test_editing_an_email_sourced_description_clears_the_pre_rendered_html(): void
    {
        // The defect that makes the naive build worthless: description_html is
        // set on email ingest (EmailService) and preferred by
        // getRenderedDescriptionAttribute(), so without this clear the page and
        // the client keep seeing the OLD text after a successful save.
        $ticket = Ticket::factory()->create([
            'description' => 'Original body naming ACME Corp',
            'description_html' => '<p>Original body naming ACME Corp</p>',
        ]);

        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), ['description' => 'Scrubbed body']);

        $ticket->refresh();

        $this->assertNull($ticket->description_html);
        $this->assertStringContainsString('Scrubbed body', (string) $ticket->rendered_description);
        $this->assertStringNotContainsString('ACME Corp', (string) $ticket->rendered_description);
    }

    public function test_a_writer_that_supplies_both_fields_keeps_its_own_rendered_html(): void
    {
        // The email ingest and importers set description and description_html in
        // one write; the clear must not fight them.
        $ticket = Ticket::factory()->create([
            'description' => 'old',
            'description_html' => '<p>old</p>',
        ]);

        $ticket->update([
            'description' => 'new body',
            'description_html' => '<p><em>new body</em></p>',
        ]);

        $this->assertSame('<p><em>new body</em></p>', $ticket->fresh()->description_html);
    }

    public function test_a_writer_that_re_supplies_its_identical_html_keeps_it(): void
    {
        // isDirty() is a value comparison, so an idempotent re-import — one that
        // writes back the SAME description_html it wrote last run alongside a
        // changed description — is not dirty on that attribute. Keying the clear
        // on write attribution instead of dirtiness is what stops the observer
        // nulling a rendering (inline images and all) the writer just supplied.
        $ticket = Ticket::factory()->create([
            'description' => 'Original body',
            'description_html' => '<p>Original body</p>',
        ]);

        $ticket->update([
            'description' => 'normalised body',
            'description_html' => '<p>Original body</p>',
        ]);

        $this->assertSame('<p>Original body</p>', $ticket->fresh()->description_html);
    }

    public function test_a_markdown_only_write_with_no_auth_context_still_clears_the_rendering(): void
    {
        // The staff MCP surface is bearer-token authenticated — no user is ever
        // logged in — so its update_ticket write has System attribution, and it
        // is the surface Charlie's live incident was remediated through. Keying
        // the clear on Staff attribution silently reinstated the no-op there.
        // The clear is keyed on whether the write SUPPLIED a rendering, which a
        // markdown-only writer never does, whatever its auth context.
        $ticket = Ticket::factory()->create([
            'description' => 'Original body naming ACME Corp',
            'description_html' => '<p>Original body naming ACME Corp</p>',
        ]);

        $this->assertFalse(auth()->check());

        app(TicketService::class)->updateTicket($ticket, ['description' => 'Corrected body']);

        $ticket->refresh();

        $this->assertNull($ticket->description_html);
        $this->assertStringContainsString('Corrected body', (string) $ticket->rendered_description);
        $this->assertStringNotContainsString('ACME Corp', (string) $ticket->rendered_description);
    }

    public function test_a_ticket_info_save_that_omits_description_does_not_clear_it(): void
    {
        // TicketUpdateRequest rules description as `nullable`, NOT `sometimes`,
        // and blanks convert to null — so a form that carried the field
        // unpopulated would wipe the description on every unrelated save. The
        // Ticket Info form must never post the key.
        $ticket = Ticket::factory()->create(['description' => 'Keep me']);

        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), ['subject' => 'Renamed subject']);

        $ticket->refresh();

        $this->assertSame('Renamed subject', $ticket->subject);
        $this->assertSame('Keep me', $ticket->description);
    }

    // ── AUDIT (Jeeves's ruling: seam 1, the observer) ──

    public function test_a_web_description_edit_writes_an_audit_row_attributed_to_the_staff_user(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'description' => 'before text',
            'description_html' => '<p>before text</p>',
        ]);

        $this->actingAs($user)
            ->patch(route('tickets.update', $ticket), ['description' => 'after text']);

        $log = TicketDescriptionChangeLog::where('ticket_id', $ticket->id)->sole();

        $this->assertSame('before text', $log->previous_description);
        $this->assertSame('after text', $log->new_description);
        $this->assertSame(TicketDescriptionChangeSource::Staff, $log->source);
        $this->assertSame($user->id, $log->changed_by);
        // The clear in updating() has already run by the time the row is
        // written, so this copy is the only place the replaced email rendering
        // survives — byte-for-byte, or the "record what you destroy" guarantee
        // is hollow.
        $this->assertSame('<p>before text</p>', $log->previous_description_html);
        $this->assertNull($ticket->refresh()->description_html);
    }

    public function test_a_non_interactive_writer_is_recorded_as_system_not_skipped(): void
    {
        // "That context touching descriptions is precisely a thing the record
        // should show, not skip" — Jeeves, #992 audit-seam ruling.
        $ticket = Ticket::factory()->create(['description' => 'before']);

        app(TicketService::class)->updateTicket($ticket, ['description' => 'after']);

        $log = TicketDescriptionChangeLog::where('ticket_id', $ticket->id)->sole();

        $this->assertSame(TicketDescriptionChangeSource::System, $log->source);
        $this->assertNull($log->changed_by);
        // No HTML existed to destroy, so none is recorded — the null doubles
        // as the "prior rendering was not email HTML" signal.
        $this->assertNull($log->previous_description_html);
    }

    public function test_the_audit_row_is_written_at_the_model_seam_so_every_surface_is_covered(): void
    {
        // Parity of trace, not parity of table: a bare model write — which is
        // what the MCP executor, queued jobs and imports ultimately perform —
        // must leave the same record as the web form, with no per-surface wiring.
        $ticket = Ticket::factory()->create(['description' => 'before']);

        $ticket->update(['description' => 'after']);

        $this->assertDatabaseHas('ticket_description_change_logs', [
            'ticket_id' => $ticket->id,
            'previous_description' => 'before',
            'new_description' => 'after',
        ]);
    }

    public function test_one_edit_writes_exactly_one_row_despite_the_html_clear(): void
    {
        // The clear in updating() makes description_html dirty in the SAME save;
        // keying the log on `description` alone is what stops it double-firing.
        // Auth context is irrelevant here: the clear is keyed on the payload.
        $ticket = Ticket::factory()->create([
            'description' => 'before',
            'description_html' => '<p>before</p>',
        ]);

        $this->actingAs(User::factory()->create());

        $ticket->update(['description' => 'after']);

        $this->assertNull($ticket->fresh()->description_html);

        $this->assertSame(1, TicketDescriptionChangeLog::where('ticket_id', $ticket->id)->count());
    }

    public function test_a_save_that_does_not_touch_the_description_writes_no_row(): void
    {
        $ticket = Ticket::factory()->create(['description' => 'unchanged']);

        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), ['subject' => 'Renamed only']);

        $this->assertSame(0, TicketDescriptionChangeLog::where('ticket_id', $ticket->id)->count());
    }

    public function test_creating_a_ticket_with_a_description_writes_no_change_row(): void
    {
        // Scoped to updates by design: a description present at creation is not
        // a change and nothing has been destroyed — the ticket row already
        // carries that text with created_by and created_at.
        $ticket = Ticket::factory()->create(['description' => 'initial text']);

        $this->assertSame(0, TicketDescriptionChangeLog::where('ticket_id', $ticket->id)->count());
    }
}
