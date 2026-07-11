<?php

namespace Tests\Feature\Briefing;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\CallStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Alert;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Briefing\BriefingAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BriefingAssemblerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep ticket-creation observers from running triage/notification jobs.
        Queue::fake();
    }

    private function assembler(): BriefingAssembler
    {
        return app(BriefingAssembler::class);
    }

    private function openTicket(User $tech, Client $client, array $overrides = []): Ticket
    {
        return Ticket::factory()->create(array_merge([
            'assignee_id' => $tech->id,
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress->value,
            'priority' => TicketPriority::P2->value,
            'opened_at' => now()->subDays(2),
            'closed_at' => null,
            'resolved_at' => null,
            'responded_at' => null,
        ], $overrides));
    }

    private function voicemail(Client $client, array $overrides = []): PhoneCall
    {
        $call = new PhoneCall;
        $call->forceFill(array_merge([
            'call_uuid' => 'vm-'.bin2hex(random_bytes(6)),
            'from_number' => '+15551230000',
            'status' => CallStatus::Voicemail->value,
            'started_at' => now()->subMinutes(30),
            'client_id' => $client->id,
            'recording_duration' => 65,
        ], $overrides))->save();

        return $call;
    }

    public function test_open_tickets_are_scoped_to_the_technician(): void
    {
        $tech = User::factory()->tech()->create();
        $other = User::factory()->tech()->create();
        $client = Client::factory()->create(['primary_tech_id' => $tech->id, 'is_active' => true]);

        $mine1 = $this->openTicket($tech, $client, ['subject' => 'My first ticket']);
        $mine2 = $this->openTicket($tech, $client, ['subject' => 'My second ticket']);
        $theirs = $this->openTicket($other, $client, ['subject' => 'Not my ticket']);
        // A closed ticket owned by the tech must not appear.
        $closed = Ticket::factory()->create([
            'assignee_id' => $tech->id,
            'client_id' => $client->id,
            'status' => TicketStatus::Closed->value,
        ]);

        $content = $this->assembler()->assemble($tech);

        $this->assertSame(2, $content->openTicketCount);
        $this->assertFalse($content->isEmpty);
        $this->assertStringContainsString($mine1->display_id, $content->body);
        $this->assertStringContainsString($mine2->display_id, $content->body);
        $this->assertStringNotContainsString('Not my ticket', $content->body);
        $this->assertStringNotContainsString($closed->display_id, $content->body);
    }

    public function test_alerts_are_scoped_to_owned_clients_and_overnight_window(): void
    {
        $tech = User::factory()->tech()->create();
        $mineClient = Client::factory()->create(['primary_tech_id' => $tech->id, 'is_active' => true]);
        $otherClient = Client::factory()->create(['primary_tech_id' => null, 'is_active' => true]);

        // Included: active, on my client, fired overnight.
        Alert::create([
            'client_id' => $mineClient->id,
            'source' => AlertSource::Tactical->value,
            'source_alert_id' => 'a-recent',
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Active->value,
            'title' => 'Server offline',
            'fired_at' => now()->subHours(3),
        ]);
        // Excluded: too old (outside the overnight window).
        Alert::create([
            'client_id' => $mineClient->id,
            'source' => AlertSource::Tactical->value,
            'source_alert_id' => 'a-old',
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Active->value,
            'title' => 'Old stale alert',
            'fired_at' => now()->subDays(3),
        ]);
        // Excluded: resolved.
        Alert::create([
            'client_id' => $mineClient->id,
            'source' => AlertSource::Tactical->value,
            'source_alert_id' => 'a-resolved',
            'severity' => AlertSeverity::Error->value,
            'status' => AlertStatus::Resolved->value,
            'title' => 'Already resolved',
            'fired_at' => now()->subHours(1),
            'resolved_at' => now()->subMinutes(20),
        ]);
        // Excluded: not my client.
        Alert::create([
            'client_id' => $otherClient->id,
            'source' => AlertSource::Tactical->value,
            'source_alert_id' => 'a-other',
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Active->value,
            'title' => 'Someone else problem',
            'fired_at' => now()->subHours(1),
        ]);

        $content = $this->assembler()->assemble($tech);

        $this->assertSame(1, $content->alertCount);
        $this->assertStringContainsString('Server offline', $content->body);
        $this->assertStringNotContainsString('Old stale alert', $content->body);
        $this->assertStringNotContainsString('Already resolved', $content->body);
        $this->assertStringNotContainsString('Someone else problem', $content->body);
    }

    public function test_voicemails_awaiting_callback_are_scoped_to_owned_clients(): void
    {
        $tech = User::factory()->tech()->create();
        $mineClient = Client::factory()->create(['primary_tech_id' => $tech->id, 'is_active' => true]);
        $otherClient = Client::factory()->create(['primary_tech_id' => null, 'is_active' => true]);

        $awaiting = $this->voicemail($mineClient);
        // Excluded: already followed up.
        $this->voicemail($mineClient, ['followed_up_at' => now()->subMinutes(5)]);
        // Excluded: a completed call, not a voicemail.
        $this->voicemail($mineClient, ['status' => CallStatus::Completed->value]);
        // Excluded: not my client.
        $this->voicemail($otherClient);

        $content = $this->assembler()->assemble($tech);

        $this->assertSame(1, $content->voicemailCount);
        $this->assertStringContainsString('Voicemails to return (1)', $content->body);
        $this->assertStringContainsString((string) route('calls.show', $awaiting), $content->body);
    }

    public function test_sla_risk_includes_due_today_and_overdue_but_not_future(): void
    {
        $tech = User::factory()->tech()->create();
        $client = Client::factory()->create(['primary_tech_id' => $tech->id, 'is_active' => true]);

        $dueToday = $this->openTicket($tech, $client, [
            'subject' => 'Due later today',
            'due_at' => now()->addHours(2),
        ]);
        $overdue = $this->openTicket($tech, $client, [
            'subject' => 'Already overdue',
            'due_at' => now()->subHours(1),
        ]);
        $future = $this->openTicket($tech, $client, [
            'subject' => 'Due next week',
            'due_at' => now()->addWeek(),
        ]);

        $content = $this->assembler()->assemble($tech);

        $this->assertSame(2, $content->slaRiskCount);
        $this->assertStringContainsString('SLA risk today (2)', $content->body);
        $this->assertStringContainsString($dueToday->display_id, $content->body);
        $this->assertStringContainsString($overdue->display_id, $content->body);
        // The future ticket is still an open owned ticket, so it appears in the
        // "open tickets" section — but the SLA-risk count must stay at 2.
        $this->assertStringContainsString('resolution overdue by', $content->body);
    }

    public function test_is_empty_when_technician_has_no_work(): void
    {
        $tech = User::factory()->tech()->create();
        Client::factory()->create(['primary_tech_id' => $tech->id, 'is_active' => true]);

        $content = $this->assembler()->assemble($tech);

        $this->assertTrue($content->isEmpty);
        $this->assertSame(0, $content->openTicketCount);
        $this->assertSame(0, $content->alertCount);
        $this->assertSame(0, $content->voicemailCount);
        $this->assertSame(0, $content->slaRiskCount);
        $this->assertFalse($content->aiSuggestionsIncluded);
    }

    public function test_no_ai_suggestions_when_ai_not_configured(): void
    {
        // No ai_api_key set in the test env → AiConfig::isConfigured() is false.
        $tech = User::factory()->tech()->create();
        $client = Client::factory()->create(['primary_tech_id' => $tech->id, 'is_active' => true]);
        $this->openTicket($tech, $client);

        $content = $this->assembler()->assemble($tech);

        $this->assertFalse($content->isEmpty);
        $this->assertFalse($content->aiSuggestionsIncluded);
        $this->assertStringNotContainsString('Suggested next actions', $content->body);
    }
}
