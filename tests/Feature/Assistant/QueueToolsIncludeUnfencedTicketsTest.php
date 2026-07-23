<?php

namespace Tests\Feature\Assistant;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-6usr: the staff-MCP queue read tools carried an active-client EXISTS fence
 * (`whereHas('client', active())`) that the web ticket list does NOT. It silently
 * dropped three classes of ticket the queue most needs to surface:
 *
 *   1. client_id IS NULL — the UNRESOLVED INTAKE tickets;
 *   2. tickets at is_active = false clients;
 *   3. tickets at soft-deleted clients.
 *
 * The tools' own published descriptions say "all tickets in the PSA (not scoped to
 * any client)" / "all open tickets across the board", and get_ticket_detail on the
 * same surface applies no fence — so a ticket the queue could not find was still
 * readable by id. This is the CLAUDE.md rule-3 false-clear, on the queue: an agent
 * asking "what's unhandled?" got a confident UNDER-COUNT with no signal.
 *
 * Fix (option a): drop the fence so the staff surface matches the web and its own
 * contract. Staff already see every ticket on the web dashboard — this widens no
 * boundary. (The real client-lock lives on the PORTAL executor, untouched here.)
 */
class QueueToolsIncludeUnfencedTicketsTest extends TestCase
{
    use RefreshDatabase;

    /** A distinctive token shared by every probe ticket so search_all_tickets can target them. */
    private const PROBE = 'zzqueueprobe';

    /**
     * Build one open ticket in each of the three fenced-out classes plus one at an
     * active client as a control. Returns [control, nullClient, inactiveClient, deletedClient].
     *
     * @return array{0: Ticket, 1: Ticket, 2: Ticket, 3: Ticket}
     */
    private function buildProbeQueue(?int $assigneeId = null): array
    {
        $activeClient = Client::factory()->create(['is_active' => true]);
        $inactiveClient = Client::factory()->create(['is_active' => false]);
        $deletedClient = Client::factory()->create(['is_active' => true]);

        $make = fn (string $label, ?int $clientId) => Ticket::factory()->create([
            'client_id' => $clientId,
            'subject' => self::PROBE.' '.$label,
            'status' => TicketStatus::InProgress->value,
            'assignee_id' => $assigneeId,
        ]);

        $control = $make('active', $activeClient->id);
        $nullClient = $make('intake', null);
        $inactive = $make('inactive', $inactiveClient->id);
        $deleted = $make('deleted', $deletedClient->id);

        // Soft-delete the client AFTER the ticket is attached, mirroring a real
        // client offboarded while its tickets are still open.
        $deletedClient->delete();

        return [$control, $nullClient, $inactive, $deleted];
    }

    public function test_get_queue_stats_counts_null_inactive_and_deleted_client_tickets(): void
    {
        [$control, $nullClient, $inactive, $deleted] = $this->buildProbeQueue();

        $stats = (new AssistantToolExecutor)->execute('get_queue_stats', []);

        // All four probe tickets are open, so total_open must count every one of
        // them — the unresolved-intake (null client) row included.
        $this->assertSame(4, $stats['total_open'], 'the queue overview must not under-count fenced-out tickets');
    }

    public function test_list_open_tickets_returns_null_inactive_and_deleted_client_tickets(): void
    {
        [$control, $nullClient, $inactive, $deleted] = $this->buildProbeQueue();

        $result = (new AssistantToolExecutor)->execute('list_open_tickets', []);
        $ids = array_column($result['tickets'], 'id');

        $this->assertContains($control->id, $ids);
        $this->assertContains($nullClient->id, $ids, 'unresolved-intake (null client) ticket must appear');
        $this->assertContains($inactive->id, $ids, 'ticket at an inactive client must appear');
        $this->assertContains($deleted->id, $ids, 'ticket at a soft-deleted client must appear');
    }

    public function test_search_all_tickets_returns_null_inactive_and_deleted_client_tickets(): void
    {
        [$control, $nullClient, $inactive, $deleted] = $this->buildProbeQueue();

        $result = (new AssistantToolExecutor)->execute('search_all_tickets', ['query' => self::PROBE, 'limit' => 30]);
        $ids = array_column($result['tickets'], 'id');

        $this->assertContains($control->id, $ids);
        $this->assertContains($nullClient->id, $ids, 'search across "all tickets in the PSA" must include the null-client intake ticket');
        $this->assertContains($inactive->id, $ids);
        $this->assertContains($deleted->id, $ids);
    }

    public function test_list_my_tickets_returns_the_assignees_tickets_regardless_of_client_state(): void
    {
        $user = User::factory()->create();
        [$control, $nullClient, $inactive, $deleted] = $this->buildProbeQueue(assigneeId: $user->id);

        $result = (new AssistantToolExecutor(userId: $user->id))->execute('list_my_tickets', []);
        $ids = array_column($result['tickets'], 'id');

        $this->assertContains($control->id, $ids);
        $this->assertContains($nullClient->id, $ids, 'a ticket assigned to me must appear even with no client');
        $this->assertContains($inactive->id, $ids, 'a ticket assigned to me must appear even at an inactive client');
        $this->assertContains($deleted->id, $ids, 'a ticket assigned to me must appear even at a soft-deleted client');
    }
}
