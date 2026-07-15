<?php

namespace Tests\Feature\Mcp;

use App\Enums\EmailDirection;
use App\Enums\PersonType;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Email;
use App\Models\McpToken;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TeamsPersona;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\EmailService;
use App\Support\McpConfig;
use App\Support\TeamsPersonaConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * psa-u51h part 1, end-to-end through the real MCP controller.
 *
 * These MUST go through the HTTP surface rather than calling the executor directly:
 * the defect class here is PLUMBING. McpStaffController hands the executor
 * McpStaffToken::actorLabel() — the PREFIXED audit label "mcp-staff:{label}" — while
 * persona resolution matches on the BARE McpToken.label. Wiring the wrong one in
 * resolves no persona and silently falls back to the global name for ever. Only a
 * test that traverses the controller can catch that.
 */
class PsaActionPersonaTaglineTest extends TestCase
{
    use RefreshDatabase;

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    private function ticketWithContact(): Ticket
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);

        return Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id,
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
            'subject' => 'Printer is offline',
        ]);
    }

    /** Give the token identified by $label a Teams persona named $displayName. */
    private function personaFor(string $label, string $displayName): void
    {
        TeamsPersona::create([
            'persona_key' => strtolower($displayName),
            'display_name' => $displayName,
            'mcp_token_label' => $label,
            'enabled' => true,
            'bot_app_id' => '11111111-1111-1111-1111-111111111111',
            'tenant_id' => '22222222-2222-2222-2222-222222222222',
            'bot_client_secret' => 'secret',
        ]);
        TeamsPersonaConfig::flush();
    }

    private function expectSendCapturingNote(): void
    {
        $this->mock(EmailService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendTicketReplyNote')->once()
                ->andReturnUsing(fn (Ticket $ticket, TicketNote $note) => Email::create([
                    'graph_id' => null,
                    'direction' => EmailDirection::Outbound,
                    'from_address' => 'support@example.test',
                    'to_recipients' => [['address' => $ticket->contact->email, 'name' => null]],
                    'subject' => '['.$ticket->display_id.'] '.$ticket->subject,
                    'body_preview' => mb_substr($note->body, 0, 500),
                    'body_text' => $note->body,
                    'body_html' => '<p>'.e($note->body).'</p>',
                    'has_attachments' => false,
                    'importance' => 'normal',
                    'received_at' => now(),
                    'is_read' => true,
                    'client_id' => $ticket->client_id,
                    'person_id' => $ticket->contact_id,
                    'ticket_id' => $ticket->id,
                ]));
        });
    }

    public function test_direct_send_email_signs_with_the_acting_tokens_persona_not_the_global_actor(): void
    {
        $ticket = $this->ticketWithContact();
        $token = McpConfig::rotateStaffToken(allowedTools: ['send_email'], label: 'robin-token');
        $this->personaFor('robin-token', 'Robin');
        $this->expectSendCapturingNote();

        $response = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Confirm the fix to the client.',
            'body' => 'The printer is back online.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $note = TicketNote::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertStringContainsString('— Sent by Robin, an AI assistant for our team.', $note->body);
        $this->assertStringNotContainsString('AI Actor', $note->body);
        // The note's attributed author follows the same persona, not the global name.
        $this->assertSame('Robin', $note->author_name);
    }

    public function test_write_public_note_signs_with_the_acting_tokens_persona(): void
    {
        $ticket = $this->ticketWithContact();
        $token = McpConfig::rotateStaffToken(allowedTools: ['write_public_note'], label: 'robin-token');
        $this->personaFor('robin-token', 'Robin');

        $response = $this->callTool($token, 'write_public_note', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Record the fix for the client.',
            'body' => 'Replaced the fuser.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $note = TicketNote::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertStringContainsString('— Sent by Robin, an AI assistant for our team.', $note->body);
        $this->assertSame('Robin', $note->author_name);
    }

    /**
     * The no-persona path must stay byte-identical to pre-psa-u51h behaviour — the
     * de-hardcode is additive, never a change for deployments with no personas.
     */
    public function test_a_token_with_no_persona_still_signs_with_the_global_actor_name(): void
    {
        $ticket = $this->ticketWithContact();
        $token = McpConfig::rotateStaffToken(allowedTools: ['send_email'], label: 'plain-token');
        $this->expectSendCapturingNote();

        $response = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Confirm the fix to the client.',
            'body' => 'The printer is back online.',
        ]);

        $response->assertOk();
        $note = TicketNote::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertStringContainsString('— Sent by AI Actor, an AI assistant for our team.', $note->body);
        $this->assertSame('AI Actor', $note->author_name);
    }

    /**
     * One persona must never sign another token's mail — the whole point of so-bp4f.
     */
    public function test_a_persona_on_a_different_token_does_not_sign_this_tokens_mail(): void
    {
        $ticket = $this->ticketWithContact();
        // 'robin-token' exists and has a persona, but the caller authenticates as another.
        McpToken::create(['label' => 'robin-token', 'token_hash' => hash('sha256', 'x'), 'tools' => ['send_email']]);
        $this->personaFor('robin-token', 'Robin');
        $token = McpConfig::rotateStaffToken(allowedTools: ['send_email'], label: 'other-token');
        $this->expectSendCapturingNote();

        $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Confirm the fix to the client.',
            'body' => 'The printer is back online.',
        ]);

        $note = TicketNote::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertStringNotContainsString('Robin', $note->body);
        $this->assertStringContainsString('— Sent by AI Actor, an AI assistant for our team.', $note->body);
    }

    /**
     * The staged path defers disclosure to approval time, so the drafting token must be
     * recorded on the run in the form persona resolution can actually use (the BARE
     * label). Without this, approval-time dual credit cannot name the AI that drafted it.
     */
    public function test_staging_records_the_bare_drafting_token_label_for_approval_time_credit(): void
    {
        $ticket = $this->ticketWithContact();
        $token = McpConfig::rotateStaffToken(allowedTools: ['stage_email'], label: 'robin-token');
        $this->personaFor('robin-token', 'Robin');

        $response = $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Needs a human eye before it goes.',
            'body' => 'The printer is back online.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $run = \App\Models\TechnicianRun::where('ticket_id', $ticket->id)->firstOrFail();
        // The existing audit field keeps its prefixed value (unchanged, byte-identical)...
        $this->assertSame('mcp-staff:robin-token', $run->proposed_meta['drafted_by']);
        // ...and the bare label is recorded alongside it for persona resolution.
        $this->assertSame('robin-token', $run->proposed_meta['drafted_by_token']);
    }

    /** A recent unaddressed client reply — what send_reply requires to draft at all. */
    private function recentClientReply(Ticket $ticket): void
    {
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_name' => 'Client Contact',
            'who_type' => \App\Enums\WhoType::EndUser,
            'body' => 'Still not working — please keep this open.',
            'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false,
            'noted_at' => now(),
        ]);
    }

    /**
     * send_reply is the primary held-reply path, and it holds the draft for LATER
     * approval — so it must record the drafting token too, or the dual credit at
     * approval time cannot name the persona that wrote the words.
     */
    public function test_held_send_reply_records_the_bare_drafting_token_when_the_agent_supplies_the_body(): void
    {
        $ticket = $this->ticketWithContact();
        $this->recentClientReply($ticket);
        $token = McpConfig::rotateStaffToken(allowedTools: ['send_reply'], label: 'robin-token');
        $this->personaFor('robin-token', 'Robin');

        $response = $this->callTool($token, 'send_reply', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'The client asked for an ETA; confirm tonight.',
            'body' => 'It completes tonight.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $run = \App\Models\TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->firstOrFail();
        $this->assertSame('mcp-staff:robin-token', $run->proposed_meta['drafted_by']);
        $this->assertSame('robin-token', $run->proposed_meta['drafted_by_token']);
    }

    /**
     * The other half of the rule: on a BODYLESS call the native drafter writes the words
     * in the GLOBAL actor's voice and marks drafted_by='technician-drafter'. Crediting
     * the token's persona there would name a persona that did not write the reply, so no
     * token label is recorded and the tagline correctly falls back to the global name.
     */
    public function test_a_natively_drafted_held_reply_records_no_drafting_token(): void
    {
        $ticket = $this->ticketWithContact();
        $this->recentClientReply($ticket);
        $token = McpConfig::rotateStaffToken(allowedTools: ['send_reply'], label: 'robin-token');
        $this->personaFor('robin-token', 'Robin');

        // No 'body' => the native drafter path. Stub it so the test does not need an AI key.
        $this->mock(\App\Services\Technician\TechnicianReplyDrafter::class, function (MockInterface $m): void {
            $m->shouldReceive('draft')->once()->andReturn(
                new \App\Services\Technician\TechnicianDraft('Natively drafted body.', null, 0),
            );
        });

        $this->callTool($token, 'send_reply', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'The client asked for an ETA.',
        ]);

        $run = \App\Models\TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->firstOrFail();
        $this->assertSame('technician-drafter', $run->proposed_meta['drafted_by']);
        $this->assertNull($run->proposed_meta['drafted_by_token'] ?? null);
    }
}
