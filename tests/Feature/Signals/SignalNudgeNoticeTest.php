<?php

namespace Tests\Feature\Signals;

use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalInboxEntry;
use App\Services\Signals\SignalNudgeNotice;
use App\Services\Signals\SignalRelayMatrix;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalNudgeNoticeTest extends TestCase
{
    use RefreshDatabase;

    private function notice(): SignalNudgeNotice
    {
        return app(SignalNudgeNotice::class);
    }

    private function unackedEntry(string $tokenLabel, string $typeKey): void
    {
        $destinationId = $this->destinationId($tokenLabel);
        $routeId = (int) \App\Models\SignalRoute::where('managed_token_label', $tokenLabel)->firstOrFail()->id;

        $event = SignalEvent::create([
            'type_key' => $typeKey,
            'entity_type' => 'ticket',
            'entity_id' => 1,
            'summary' => 'evt',
            'context' => [],
            'occurred_at' => now(),
        ]);
        $delivery = \App\Models\SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => $routeId,
            'step_order' => 1,
            'destination_id' => $destinationId,
            'status' => 'delivered',
        ]);
        SignalInboxEntry::create([
            'destination_id' => $destinationId,
            'event_id' => $event->id,
            'delivery_id' => $delivery->id,
            'payload' => ['event' => $typeKey],
        ]);
    }

    private function chetDestinationId(): int
    {
        return (int) SignalDestination::where('mcp_token_label', 'Chet')->firstOrFail()->id;
    }

    private function setUpChetWithNudge(): void
    {
        McpConfig::rotateStaffToken(['poll_signals'], 'Chet');
        $matrix = app(SignalRelayMatrix::class);
        $matrix->setRelay('Chet', 'ticket.created', true);
        $matrix->setNudge('Chet', 'ticket.created', true);      // ticket.created also-nudges
        $matrix->setRelay('Chet', 'intake.email_received', true); // relayed, does NOT nudge
    }

    public function test_no_notice_when_there_are_no_unacked_entries(): void
    {
        $this->setUpChetWithNudge();

        $this->assertNull($this->notice()->pendingNoticeFor('Chet'));
    }

    public function test_no_notice_when_unacked_entries_are_not_nudge_worthy(): void
    {
        $this->setUpChetWithNudge();
        $this->unackedEntry('Chet', 'intake.email_received'); // relayed, not nudge

        $this->assertNull($this->notice()->pendingNoticeFor('Chet'));
    }

    public function test_notice_fires_on_a_nudge_worthy_unacked_entry_and_counts_total_unread(): void
    {
        $this->setUpChetWithNudge();
        $this->unackedEntry('Chet', 'intake.email_received'); // not nudge
        $this->unackedEntry('Chet', 'ticket.created');        // nudge-worthy

        $text = $this->notice()->pendingNoticeFor('Chet');
        $this->assertNotNull($text);
        $this->assertStringContainsString('poll_signals', $text);
        $this->assertStringContainsString('2', $text); // total unread across the token's inbox
    }

    public function test_no_notice_once_the_nudge_worthy_entry_is_acked(): void
    {
        $this->setUpChetWithNudge();
        $this->unackedEntry('Chet', 'ticket.created');

        SignalInboxEntry::query()->update(['acked_at' => now()]);

        $this->assertNull($this->notice()->pendingNoticeFor('Chet'));
    }

    public function test_no_notice_when_token_cannot_poll_signals(): void
    {
        // A token WITHOUT the poll_signals grant cannot consume — nudging it is dead noise.
        McpConfig::rotateStaffToken(['find_clients'], 'NoPoll');
        $matrix = app(SignalRelayMatrix::class);
        // Force a relay route + nudge for NoPoll directly (the guard is independent of config validity).
        $matrix->setRelay('NoPoll', 'ticket.created', true);
        $matrix->setNudge('NoPoll', 'ticket.created', true);
        $this->unackedEntry('NoPoll', 'ticket.created');

        $this->assertNull($this->notice()->pendingNoticeFor('NoPoll'));
    }

    private function destinationId(string $label): int
    {
        return (int) SignalDestination::where('mcp_token_label', $label)->firstOrFail()->id;
    }
}
