<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\McpToken;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TeamsPersona;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Technician\TechnicianApprovalService;
use App\Services\Technician\TechnicianDisclosure;
use App\Support\TeamsPersonaConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * psa-u51h part 2 — an AI-STAGED, HUMAN-APPROVED-AND-SENT message credits both.
 *
 * Charlie's rule (confirmed via the manager): staged->approved = dual credit;
 * auto-sent = the AI alone. The AI half must name the persona of the token that
 * actually DRAFTED the run — which is why staging records the bare token label.
 */
class TechnicianApprovalDualCreditTest extends TestCase
{
    use RefreshDatabase;

    private function persona(string $tokenLabel, string $displayName): void
    {
        McpToken::create([
            'label' => $tokenLabel,
            'token_hash' => hash('sha256', $tokenLabel),
            'tools' => ['stage_email'],
        ]);
        TeamsPersona::create([
            'persona_key' => strtolower($displayName),
            'display_name' => $displayName,
            'mcp_token_label' => $tokenLabel,
            'enabled' => true,
            'bot_app_id' => '11111111-1111-1111-1111-111111111111',
            'tenant_id' => '22222222-2222-2222-2222-222222222222',
            'bot_client_secret' => 'secret',
        ]);
        TeamsPersonaConfig::flush();
    }

    /** @return array{0: TechnicianRun, 1: Ticket} */
    private function heldReplyRun(User $aiActor, ?string $draftedByToken): array
    {
        Setting::setValue('triage_system_user_id', (string) $aiActor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));

        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'contact_id' => $person->id, 'status' => TicketStatus::InProgress,
        ]);

        $meta = ['reasons' => ['Client asked for an ETA.']];
        if ($draftedByToken !== null) {
            $meta['drafted_by'] = 'mcp-staff:'.$draftedByToken;
            $meta['drafted_by_token'] = $draftedByToken;
        }

        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Original draft.', 'proposed_meta' => $meta,
        ]);

        return [$run, $ticket];
    }

    private function expectSend(): void
    {
        $this->mock(EmailService::class, function (MockInterface $m): void {
            $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull();
        });
    }

    private function sentBody(Ticket $ticket): string
    {
        return (string) TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->firstOrFail()->body;
    }

    public function test_approved_send_credits_the_drafting_persona_and_the_approving_technician(): void
    {
        $ai = User::factory()->create(['name' => 'Global Chet']);
        $approver = User::factory()->create(['name' => 'Jane Smith']);
        $this->persona('robin-token', 'Robin');
        [$run, $ticket] = $this->heldReplyRun($ai, 'robin-token');
        $this->expectSend();

        $result = app(TechnicianApprovalService::class)->approveAndSend($run, 'Your mailbox is migrated.', $approver->id);

        $this->assertSame('sent', $result->status);
        $this->assertStringContainsString(
            '— Drafted by Robin, an AI assistant for our team. Reviewed and sent by Jane Smith.',
            $this->sentBody($ticket),
        );
    }

    /** The disclosure scan is fail-closed; dual credit must never trip it. */
    public function test_the_dual_credit_body_still_carries_the_structural_sentinel(): void
    {
        $ai = User::factory()->create(['name' => 'Global Chet']);
        $approver = User::factory()->create(['name' => 'Jane Smith']);
        $this->persona('robin-token', 'Robin');
        [$run, $ticket] = $this->heldReplyRun($ai, 'robin-token');
        $this->expectSend();

        app(TechnicianApprovalService::class)->approveAndSend($run, 'Body.', $approver->id);

        $this->assertStringContainsString(TechnicianDisclosure::DISCLOSURE_SENTINEL, $this->sentBody($ticket));
    }

    /**
     * A run staged before psa-u51h has no drafted_by_token. It must degrade to the
     * global actor name for the AI half — never crash, never drop the dual credit.
     */
    public function test_a_run_with_no_recorded_drafting_token_falls_back_to_the_global_actor_name(): void
    {
        $ai = User::factory()->create(['name' => 'Global Chet']);
        $approver = User::factory()->create(['name' => 'Jane Smith']);
        [$run, $ticket] = $this->heldReplyRun($ai, null);
        $this->expectSend();

        app(TechnicianApprovalService::class)->approveAndSend($run, 'Body.', $approver->id);

        $this->assertStringContainsString(
            '— Drafted by Global Chet, an AI assistant for our team. Reviewed and sent by Jane Smith.',
            $this->sentBody($ticket),
        );
    }

    /**
     * approveStagedEmail shares the same body-action seam as approveAndSend — the
     * MCP-staged send_email path must credit both too, not just the native drafter.
     */
    public function test_approved_staged_email_also_carries_dual_credit(): void
    {
        $ai = User::factory()->create(['name' => 'Global Chet']);
        $approver = User::factory()->create(['name' => 'Jane Smith']);
        $this->persona('robin-token', 'Robin');
        [$run, $ticket] = $this->heldReplyRun($ai, 'robin-token');
        $run->update(['action_type' => 'stage_email']);
        $this->expectSend();

        $result = app(TechnicianApprovalService::class)->approveStagedEmail($run->fresh(), 'Your mailbox is migrated.', $approver->id);

        $this->assertSame('sent', $result->status);
        $this->assertStringContainsString(
            '— Drafted by Robin, an AI assistant for our team. Reviewed and sent by Jane Smith.',
            $this->sentBody($ticket),
        );
    }
}
