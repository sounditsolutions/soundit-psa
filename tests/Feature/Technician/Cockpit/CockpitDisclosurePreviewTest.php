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
use App\Models\User;
use App\Support\TeamsPersonaConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-u51h.2 (PRODUCT review, FAIL/REVISE): the approval card must tell the technician
 * what the CLIENT will actually receive.
 *
 * The card promised a stale, GLOBAL, AI-ONLY line ("— Sent by {aiActorName}, an AI
 * assistant for our team.") while an approved send now emits per-persona DUAL credit —
 * and it labelled the drafter with the raw `mcp-staff:<token>` audit string. The tech is
 * credited BY NAME on that mail ("Reviewed and sent by <Tech>"), so showing them copy
 * that does not match the send is the trust breach the disclosure exists to prevent.
 */
class CockpitDisclosurePreviewTest extends TestCase
{
    use RefreshDatabase;

    private function seedStagedEmailRun(User $actor, ?string $draftedByToken): TechnicianRun
    {
        // The GLOBAL actor name is the AI-actor USER's name (TechnicianConfig::aiActorName
        // -> AiActorResolver -> triage_system_user_id -> User::name) — NOT a setting string.
        // It must be a DIFFERENT user from the approving tech, or "global name" and
        // "approver name" collide and the assertions prove nothing.
        $aiActor = User::factory()->create(['name' => 'GlobalChet']);
        Setting::setValue('triage_system_user_id', (string) $aiActor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));

        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Client', 'last_name' => 'Contact',
            'email' => 'client@preview.test', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'contact_id' => $contact->id,
            'status' => TicketStatus::InProgress, 'closed_at' => null,
        ]);

        $meta = [];
        if ($draftedByToken !== null) {
            // The staging path records BOTH: the prefixed audit form and the bare label.
            $meta = ['drafted_by' => 'mcp-staff:'.$draftedByToken, 'drafted_by_token' => $draftedByToken];
        }

        return TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'stage_email',
            'content_hash' => str_repeat('d', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Your printer is back online.',
            'proposed_meta' => $meta,
        ]);
    }

    private function personaFor(string $label, string $displayName): void
    {
        // TeamsPersona::saving enforces mcp_token_label -> a real McpToken.label.
        McpToken::create(['label' => $label, 'token_hash' => hash('sha256', $label), 'tools' => ['send_email']]);
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

    public function test_the_card_previews_the_dual_credit_line_the_client_will_actually_receive(): void
    {
        $actor = User::factory()->create(['name' => 'Dana Tech']);
        $this->personaFor('robin-token', 'Robin');
        $this->seedStagedEmailRun($actor, 'robin-token');

        $this->actingAs($actor)->get(route('cockpit.index'))->assertOk()
            // The exact banner the approved send appends — persona drafter + approving human.
            ->assertSee('— Drafted by Robin, an AI assistant for our team. Reviewed and sent by Dana Tech.', false);
    }

    public function test_the_card_does_not_promise_the_stale_global_ai_only_disclosure(): void
    {
        $actor = User::factory()->create(['name' => 'Dana Tech']);
        $this->personaFor('robin-token', 'Robin');
        $this->seedStagedEmailRun($actor, 'robin-token');

        $html = $this->actingAs($actor)->get(route('cockpit.index'))->assertOk()->getContent();

        // The card must not claim an AI-ONLY send, nor sign with the GLOBAL name, when
        // this path sends dual credit as the acting token's persona.
        $this->assertStringNotContainsString('Sent by GlobalChet, an AI assistant for our team.', $html);
        $this->assertStringNotContainsString('— Sent by GlobalChet', $html);
    }

    public function test_the_card_names_the_persona_not_the_raw_mcp_audit_label(): void
    {
        $actor = User::factory()->create(['name' => 'Dana Tech']);
        $this->personaFor('robin-token', 'Robin');
        $this->seedStagedEmailRun($actor, 'robin-token');

        $html = $this->actingAs($actor)->get(route('cockpit.index'))->assertOk()->getContent();

        // "Drafted by: mcp-staff:robin-token" is an audit string, not the name the client
        // sees. It must not be what the approver is shown as the drafter.
        $this->assertStringNotContainsString('Drafted by: mcp-staff:robin-token', $html);
        $this->assertStringContainsString('Robin', $html);
    }

    /**
     * The card promises dual credit UNIFORMLY, so that promise must hold for EVERY action
     * type it renders. The view builds its card list by filtering $drafts to $replyTypes;
     * each of those four routes through the dual-credit seam (send_reply +
     * propose_resolution -> approveAndSend; stage_email + stage_public_note ->
     * approveStagedBodyAction). Add a reply type that does NOT dual-credit, or make the
     * preview map partial, and the card starts promising the client something they will
     * never receive — the same defect as psa-u51h.2, pointed the other way.
     */
    public function test_every_action_type_the_card_renders_previews_a_real_disclosure(): void
    {
        $actor = User::factory()->create(['name' => 'Dana Tech']);
        $this->personaFor('robin-token', 'Robin');
        $run = $this->seedStagedEmailRun($actor, 'robin-token');

        foreach (['send_reply', 'propose_resolution', 'stage_email', 'stage_public_note'] as $i => $type) {
            TechnicianRun::create([
                'ticket_id' => $run->ticket_id, 'client_id' => $run->client_id, 'action_type' => $type,
                'content_hash' => str_repeat((string) $i, 64), 'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => 'Draft for '.$type,
                'proposed_meta' => ['drafted_by' => 'mcp-staff:robin-token', 'drafted_by_token' => 'robin-token'],
            ]);
        }

        $html = $this->actingAs($actor)->get(route('cockpit.index'))->assertOk()->getContent();

        // Each card carries the real banner — never the empty promise a partial preview
        // map would render ("added automatically, exactly as written:" + nothing).
        $expected = '— Drafted by Robin, an AI assistant for our team. Reviewed and sent by Dana Tech.';
        $this->assertSame(5, substr_count($html, $expected), 'every rendered reply card must preview the real disclosure');
        $this->assertStringNotContainsString('exactly as written:</span>', $html);
    }

    public function test_a_run_with_no_recorded_token_previews_the_global_name_unchanged(): void
    {
        // Runs staged BEFORE psa-u51h (and native drafts) carry no bare label: the preview
        // must degrade to the global actor name exactly as the send does.
        $actor = User::factory()->create(['name' => 'Dana Tech']);
        $this->seedStagedEmailRun($actor, null);

        $this->actingAs($actor)->get(route('cockpit.index'))->assertOk()
            ->assertSee('— Drafted by GlobalChet, an AI assistant for our team. Reviewed and sent by Dana Tech.', false);
    }
}
