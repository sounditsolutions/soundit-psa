<?php

namespace Tests\Feature\Assistant;

use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-823 (T-22797): asset visibility on the entity read surface.
 *
 * The incident this pins: find_assets required `query`, so the only way to ask
 * "what devices does this client have" was a probe query — and a probe like "-"
 * returns a clean count:0 byte-identical to a truly asset-less client. A real
 * device (no hyphen in hostname or serial) was written up as absent on a ticket.
 *
 * Three-part fix, per Chet's build request (Charlie-approved op 452):
 *   1. get_ticket_detail and get_client each return a flat `assets` block
 *      (linked / owned devices) so the common read paths carry the fleet.
 *   2. Entity gets return one shallow `related` layer of id+name stubs by
 *      default, with a strict allow-listed `expand` opt-in for deeper rows —
 *      never always-on deep nesting, and no invoice stubs on ticket reads.
 *   3. find_assets `query` is optional — omitting it LISTS the scoped set, and
 *      every response carries total/has_more so truncation is visible.
 */
class AssetVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function asset(int $clientId, string $hostname, bool $active = true, array $extra = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'client_id' => $clientId,
            'hostname' => $hostname,
            'name' => $hostname,
            'is_active' => $active,
        ], $extra));
    }

    private function person(int $clientId, string $lastName, bool $active = true): Person
    {
        return Person::create([
            'client_id' => $clientId,
            'person_type' => PersonType::User,
            'first_name' => 'Vis',
            'last_name' => $lastName,
            'email' => strtolower($lastName).'@example.test',
            'is_active' => $active,
        ]);
    }

    // ── Part 1: flat assets on get_ticket_detail ─────────────────────────────

    public function test_get_ticket_detail_lists_linked_assets_flat(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $primary = $this->asset($client->id, 'VIS-PRIMARY');
        $secondary = $this->asset($client->id, 'VIS-SECONDARY', active: false);
        $ticket->assets()->attach($primary->id, ['is_primary' => true]);
        $ticket->assets()->attach($secondary->id, ['is_primary' => false]);

        $result = (new AssistantToolExecutor)->execute('get_ticket_detail', ['ticket_id' => $ticket->id]);

        $this->assertArrayHasKey('assets', $result, 'a ticket read must carry its linked devices');
        $byId = collect($result['assets'])->keyBy('id');
        $this->assertCount(2, $byId);

        $row = $byId[$primary->id];
        $this->assertSame('VIS-PRIMARY', $row['hostname']);
        $this->assertSame($primary->asset_type, $row['type']);
        $this->assertTrue($row['is_active']);
        $this->assertTrue($row['is_primary']);

        // A deactivated but still-linked asset stays visible — the link is the
        // relevance signal — and is flagged, not hidden.
        $this->assertFalse($byId[$secondary->id]['is_active']);
        $this->assertFalse($byId[$secondary->id]['is_primary']);
    }

    public function test_get_ticket_detail_assets_empty_when_none_linked(): void
    {
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);

        $result = (new AssistantToolExecutor)->execute('get_ticket_detail', ['ticket_id' => $ticket->id]);

        $this->assertSame([], $result['assets']);
        $this->assertArrayNotHasKey('expanded', $result, 'expanded is opt-in only');
    }

    // ── Part 2: related stubs + expand ───────────────────────────────────────

    public function test_get_ticket_detail_related_stubs_carry_ids(): void
    {
        $client = Client::factory()->create();
        $contact = $this->person($client->id, 'Contact');
        $assignee = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'assignee_id' => $assignee->id,
        ]);

        $result = (new AssistantToolExecutor)->execute('get_ticket_detail', ['ticket_id' => $ticket->id]);

        $this->assertSame($client->id, $result['related']['client']['id']);
        $this->assertSame($client->name, $result['related']['client']['name']);
        $this->assertSame($contact->id, $result['related']['contact']['id']);
        $this->assertSame($assignee->id, $result['related']['assignee']['id']);
    }

    public function test_get_ticket_detail_expand_assets_returns_fuller_rows(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $asset = $this->asset($client->id, 'VIS-EXPAND', extra: ['serial_number' => 'SER-823', 'os' => 'Windows 11 Pro']);
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        $result = (new AssistantToolExecutor)->execute('get_ticket_detail', [
            'ticket_id' => $ticket->id,
            'expand' => ['assets'],
        ]);

        $rows = $result['expanded']['assets'];
        $this->assertCount(1, $rows);
        $this->assertSame('SER-823', $rows[0]['serial_number']);
        $this->assertSame('Windows 11 Pro', $rows[0]['os']);
        $this->assertTrue($rows[0]['is_primary']);
    }

    public function test_expand_fails_closed_on_unknown_or_malformed_values(): void
    {
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);
        $x = new AssistantToolExecutor;

        // Unknown value: refused with the supported list named — a typo'd
        // expansion must never silently degrade to the shallow default.
        $unknown = $x->execute('get_ticket_detail', ['ticket_id' => $ticket->id, 'expand' => ['invoices']]);
        $this->assertArrayHasKey('error', $unknown);
        $this->assertStringContainsString('assets', $unknown['error']);

        // Non-array: refused, not coerced.
        $malformed = $x->execute('get_ticket_detail', ['ticket_id' => $ticket->id, 'expand' => 'assets']);
        $this->assertArrayHasKey('error', $malformed);
    }

    public function test_get_asset_related_block_orients_without_expanding(): void
    {
        $client = Client::factory()->create();
        $asset = $this->asset($client->id, 'VIS-REL');
        $activeUser = $this->person($client->id, 'Activeuser');
        $offboarded = $this->person($client->id, 'Goneuser', active: false);
        $asset->users()->attach([$activeUser->id, $offboarded->id]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        $result = (new AssistantToolExecutor(clientId: $client->id))->execute('get_asset', ['asset_id' => $asset->id]);

        $this->assertSame($client->id, $result['related']['client']['id']);

        $userIds = array_column($result['related']['users'], 'id');
        $this->assertContains($activeUser->id, $userIds);
        $this->assertNotContains($offboarded->id, $userIds, 'assigned-user stubs follow the active-only house fence');

        $this->assertSame(1, $result['related']['tickets_count']);
        $this->assertSame($ticket->id, $result['related']['recent_tickets'][0]['id']);
        $this->assertArrayNotHasKey('expanded', $result);

        $expanded = (new AssistantToolExecutor(clientId: $client->id))->execute('get_asset', [
            'asset_id' => $asset->id,
            'expand' => ['tickets'],
        ]);
        $this->assertSame($ticket->id, $expanded['expanded']['tickets'][0]['id']);
        $this->assertArrayHasKey('priority', $expanded['expanded']['tickets'][0]);
    }

    public function test_get_person_related_assets_active_only_with_expand(): void
    {
        $client = Client::factory()->create();
        $person = $this->person($client->id, 'Assignee');
        $active = $this->asset($client->id, 'VIS-PERSON', extra: ['serial_number' => 'SER-P1']);
        $retired = $this->asset($client->id, 'VIS-OLDBOX', active: false);
        $person->assets()->attach([$active->id, $retired->id]);

        $result = (new AssistantToolExecutor(clientId: $client->id))->execute('get_person', ['person_id' => $person->id]);

        $assetIds = array_column($result['related']['assets'], 'id');
        $this->assertContains($active->id, $assetIds);
        $this->assertNotContains($retired->id, $assetIds);
        $this->assertSame($client->id, $result['related']['client']['id']);

        $expanded = (new AssistantToolExecutor(clientId: $client->id))->execute('get_person', [
            'person_id' => $person->id,
            'expand' => ['assets'],
        ]);
        $this->assertSame('SER-P1', $expanded['expanded']['assets'][0]['serial_number']);
    }

    // ── Part 1: owned fleet on get_client ────────────────────────────────────

    public function test_get_client_lists_active_fleet_with_uncapped_count(): void
    {
        $client = Client::factory()->create();
        $a1 = $this->asset($client->id, 'VIS-FLEET1');
        $a2 = $this->asset($client->id, 'VIS-FLEET2');
        $gone = $this->asset($client->id, 'VIS-DECOM', active: false);

        $result = (new AssistantToolExecutor(clientId: $client->id))->execute('get_client', []);

        $ids = array_column($result['assets'], 'id');
        $this->assertContains($a1->id, $ids);
        $this->assertContains($a2->id, $ids);
        $this->assertNotContains($gone->id, $ids, 'the profile fleet block is in-service devices only');
        $this->assertSame(2, $result['assets_count']);
    }

    // ── Part 3: find_assets without query ────────────────────────────────────

    public function test_find_assets_omitted_query_lists_client_assets(): void
    {
        // The T-22797 repro: the real device has NO hyphen anywhere, so the old
        // probe find_assets(query="-") returned a clean zero and the client was
        // written up as asset-less. Omitting query must list the fleet.
        $client = Client::factory()->create();
        $device = $this->asset($client->id, 'EXAMPLEDELLDSKTP', extra: ['serial_number' => 'ABC123XYZ']);

        $x = new AssistantToolExecutor(clientId: $client->id);

        $probe = $x->execute('find_assets', ['query' => '-']);
        $this->assertSame(0, $probe['count'], 'precondition: the hyphen probe still misses this device');
        $this->assertSame(0, $probe['total']);
        $this->assertFalse($probe['has_more'], 'an empty search must read as a true zero, not truncation');

        $list = $x->execute('find_assets', []);
        $this->assertSame(1, $list['count']);
        $this->assertSame($device->id, $list['assets'][0]['id']);
        $this->assertStringContainsString('list-all', $list['scope']);
    }

    public function test_find_assets_reports_total_and_has_more_when_truncated(): void
    {
        $client = Client::factory()->create();
        foreach (['VIS-T1', 'VIS-T2', 'VIS-T3'] as $h) {
            $this->asset($client->id, $h);
        }

        $result = (new AssistantToolExecutor(clientId: $client->id))->execute('find_assets', ['limit' => 2]);

        $this->assertSame(2, $result['count']);
        $this->assertSame(3, $result['total']);
        $this->assertTrue($result['has_more']);
    }

    public function test_find_assets_list_all_keeps_the_active_fence(): void
    {
        $client = Client::factory()->create();
        $active = $this->asset($client->id, 'VIS-ACT');
        $inactive = $this->asset($client->id, 'VIS-INACT', active: false);

        $x = new AssistantToolExecutor(clientId: $client->id);

        $ids = array_column($x->execute('find_assets', [])['assets'], 'id');
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);

        $withInactive = array_column($x->execute('find_assets', ['include_inactive' => true])['assets'], 'id');
        $this->assertContains($inactive->id, $withInactive);
    }

    public function test_find_assets_cross_client_list_all_spans_clients(): void
    {
        $c1 = Client::factory()->create();
        $c2 = Client::factory()->create();
        $a1 = $this->asset($c1->id, 'VIS-X1');
        $a2 = $this->asset($c2->id, 'VIS-X2');

        $result = (new AssistantToolExecutor)->execute('find_assets', []);

        $ids = array_column($result['assets'], 'id');
        $this->assertContains($a1->id, $ids);
        $this->assertContains($a2->id, $ids);
        $this->assertStringContainsString('cross-client', $result['scope']);
    }
}
