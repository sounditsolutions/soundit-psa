<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssetMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function service(): AssetService
    {
        return app(AssetService::class);
    }

    private function ticketFor(Client $c, string $subject = 'Help'): Ticket
    {
        return Ticket::create([
            'client_id' => $c->id,
            'subject' => $subject,
            'type' => TicketType::ServiceRequest,
            'status' => TicketStatus::New,
            'priority' => TicketPriority::P3,
            'opened_at' => now(),
        ]);
    }

    private function alertFor(Asset $asset, string $sourceAlertId): int
    {
        return DB::table('alerts')->insertGetId([
            'asset_id' => $asset->id,
            'client_id' => $asset->client_id,
            'source' => 'ninja',
            'source_alert_id' => $sourceAlertId,
            'severity' => 'warning',
            'status' => 'active',
            'title' => 'Disk warning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_refuses_self_merge_and_cross_client_merge(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $asset = Asset::factory()->for($client)->create();
        $other = Asset::factory()->create(); // different client

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->service()->mergeAssets($asset, $asset, $user->id);
        } finally {
            // Cross-client refuses too, and nothing was mutated by either attempt.
            try {
                $this->service()->mergeAssets($asset, $other, $user->id);
                $this->fail('Cross-client merge should refuse.');
            } catch (\InvalidArgumentException) {
            }
            $this->assertNull($other->fresh()->deleted_at);
        }
    }

    public function test_refuses_when_either_side_was_already_merged_away(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create();
        $duplicate = Asset::factory()->for($client)->create();
        $previous = Asset::factory()->for($client)->create();

        $this->service()->mergeAssets($survivor, $previous, $user->id);

        try {
            $this->service()->mergeAssets($survivor, $previous->fresh(), $user->id);
            $this->fail('Merging an already-merged duplicate should refuse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already merged', $e->getMessage());
        }

        // A tombstone can never be the survivor either.
        try {
            $this->service()->mergeAssets($previous->fresh(), $duplicate, $user->id);
            $this->fail('A merged-away survivor should refuse.');
        } catch (\InvalidArgumentException|\RuntimeException) {
            $this->assertNull($duplicate->fresh()->deleted_at);
        }
    }

    public function test_refuses_when_both_carry_differing_external_identity_and_names_the_column(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create(['ninja_id' => 'ninja-100']);
        $duplicate = Asset::factory()->for($client)->create(['ninja_id' => 'ninja-200']);

        try {
            $this->service()->mergeAssets($survivor, $duplicate, $user->id);
            $this->fail('Differing live identities should refuse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ninja_id', $e->getMessage());
        }

        $this->assertNull($duplicate->fresh()->deleted_at);
        $this->assertSame('ninja-200', $duplicate->fresh()->ninja_id);
    }

    public function test_identical_external_identity_on_both_sides_is_not_a_conflict(): void
    {
        // Same vendor id on both rows is one agent double-recorded, not two devices.
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create(['ninja_id' => 'ninja-same']);
        $duplicate = Asset::factory()->for($client)->create(['level_id' => 'level-1']);
        // sqlite enforces the unique index too — clear it before both share the value.
        $duplicate->ninja_id = null;
        $duplicate->save();
        $this->assertSame([], $this->service()->assetMergeIdentityConflicts($survivor, $duplicate));

        $this->service()->mergeAssets($survivor, $duplicate, $user->id);
        $this->assertSame('ninja-same', $survivor->fresh()->ninja_id);
        $this->assertSame('level-1', $survivor->fresh()->level_id);
    }

    public function test_refuses_when_both_assets_have_tactical_detail_rows(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create();
        $duplicate = Asset::factory()->for($client)->create();
        DB::table('tactical_assets')->insert([
            ['asset_id' => $survivor->id, 'agent_id' => 'agent-a', 'created_at' => now(), 'updated_at' => now()],
            ['asset_id' => $duplicate->id, 'agent_id' => 'agent-b', 'created_at' => now(), 'updated_at' => now()],
        ]);

        try {
            $this->service()->mergeAssets($survivor, $duplicate, $user->id);
            $this->fail('Two Tactical agent records should refuse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Tactical', $e->getMessage());
        }
        $this->assertNull($duplicate->fresh()->deleted_at);
    }

    public function test_merge_moves_links_carries_identity_and_tombstones_duplicate(): void
    {
        $user = User::factory()->create(['name' => 'Merger']);
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create([
            'hostname' => 'WS-01', 'serial_number' => null, 'os' => null, 'ninja_id' => null,
        ]);
        $duplicate = Asset::factory()->for($client)->create([
            'hostname' => 'WS-01-DUP',
            'serial_number' => 'SN-777',
            'os' => 'Windows 11 Pro',
            'ninja_id' => 'ninja-777',
            'ninja_url' => 'https://ninja.example/777',
        ]);

        $sharedTicket = $this->ticketFor($client, 'Shared');
        $dupOnlyTicket = $this->ticketFor($client, 'Duplicate only');
        $survivor->tickets()->attach($sharedTicket->id);
        $duplicate->tickets()->attach($sharedTicket->id);
        $duplicate->tickets()->attach($dupOnlyTicket->id);

        $this->alertFor($duplicate, 'alert-dup-1');

        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Pat', 'last_name' => 'User', 'is_active' => true,
        ]);
        $duplicate->users()->attach($person->id, ['is_primary' => true, 'assignment_source' => 'auto']);

        $contract = Contract::create(['client_id' => $client->id, 'name' => 'Managed', 'type' => 'managed', 'start_date' => '2026-01-01']);
        $duplicate->contracts()->attach($contract->id, ['assigned_at' => now(), 'assignment_source' => 'rule']);

        DB::table('tactical_assets')->insert([
            'asset_id' => $duplicate->id, 'agent_id' => 'agent-dup', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tactical_action_logs')->insert([
            'actor_label' => 'ai-triage', 'action_key' => 'tactical.reboot', 'asset_id' => $duplicate->id,
            'target_label' => 'WS-01-DUP', 'params' => '{}', 'result_status' => 'ok',
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => now(),
        ]);
        DB::table('screenconnect_events')->insert([
            'asset_id' => $duplicate->id, 'session_id' => 'sess-1', 'event_type' => 'Connected',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $summary = $this->service()->mergeAssets($survivor, $duplicate, $user->id);

        // The shared ticket link was dropped (not duplicated); the dup-only one moved.
        $this->assertSame(1, $summary['tickets']);
        $this->assertEqualsCanonicalizing(
            [$sharedTicket->id, $dupOnlyTicket->id],
            DB::table('ticket_asset')->where('asset_id', $survivor->id)->pluck('ticket_id')->all(),
        );
        $this->assertSame(0, DB::table('ticket_asset')->where('asset_id', $duplicate->id)->count());

        $this->assertSame(1, $summary['alerts']);
        $this->assertSame($survivor->id, (int) DB::table('alerts')->where('source_alert_id', 'alert-dup-1')->value('asset_id'));

        // User moved with primary carried (survivor had none), stored as manual.
        $this->assertSame(1, $summary['users']);
        $pivot = DB::table('asset_person')->where('asset_id', $survivor->id)->where('person_id', $person->id)->first();
        $this->assertTrue((bool) $pivot->is_primary);
        $this->assertSame('manual', $pivot->assignment_source);

        // Contract moved as manual with no rule_id.
        $this->assertSame(1, $summary['contracts']);
        $contractPivot = DB::table('contract_asset')->where('asset_id', $survivor->id)->where('contract_id', $contract->id)->first();
        $this->assertSame('manual', $contractPivot->assignment_source);
        $this->assertNull($contractPivot->rule_id);

        $this->assertSame(1, $summary['tactical_logs']);
        $this->assertSame(1, $summary['screenconnect_events']);
        $this->assertSame($survivor->id, (int) DB::table('tactical_assets')->where('agent_id', 'agent-dup')->value('asset_id'));
        $this->assertSame($survivor->id, (int) DB::table('screenconnect_events')->where('session_id', 'sess-1')->value('asset_id'));

        // Fill-blank scalars carried; existing survivor values untouched.
        $survivor->refresh();
        $this->assertSame('SN-777', $survivor->serial_number);
        $this->assertSame('Windows 11 Pro', $survivor->os);
        $this->assertSame('WS-01', $survivor->hostname);

        // Identity carried with companion, duplicate stripped of ALL identities.
        $this->assertContains('ninja_id', $summary['carried_identities']);
        $this->assertSame('ninja-777', $survivor->ninja_id);
        $this->assertSame('https://ninja.example/777', $survivor->ninja_url);

        $duplicate->refresh();
        $this->assertNull($duplicate->ninja_id);
        $this->assertSame($survivor->id, $duplicate->merged_into_asset_id);
        $this->assertFalse($duplicate->is_active);
        $this->assertNotNull($duplicate->deleted_at);
        $this->assertStringContainsString("Merged into 'WS-01 (#{$survivor->id})'", $duplicate->notes);
        $this->assertStringContainsString('Merged duplicate asset', $survivor->notes);
        $this->assertStringContainsString('Merger', $survivor->notes);
    }

    public function test_survivor_keeps_its_primary_user_when_both_sides_have_one(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create();
        $duplicate = Asset::factory()->for($client)->create();

        $keep = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Keep', 'last_name' => 'Primary', 'is_active' => true,
        ]);
        $move = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Move', 'last_name' => 'Secondary', 'is_active' => true,
        ]);
        $survivor->users()->attach($keep->id, ['is_primary' => true, 'assignment_source' => 'manual']);
        $duplicate->users()->attach($move->id, ['is_primary' => true, 'assignment_source' => 'auto']);

        $this->service()->mergeAssets($survivor, $duplicate, $user->id);

        $this->assertTrue((bool) DB::table('asset_person')->where('asset_id', $survivor->id)->where('person_id', $keep->id)->value('is_primary'));
        $this->assertFalse((bool) DB::table('asset_person')->where('asset_id', $survivor->id)->where('person_id', $move->id)->value('is_primary'));
    }

    public function test_already_retired_duplicate_merges_and_keeps_its_original_retire_date(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create();
        $duplicate = Asset::factory()->for($client)->create(['ninja_id' => 'ninja-old']);

        $retiredAt = now()->subDays(10)->startOfSecond();
        $duplicate->deleted_at = $retiredAt;
        $duplicate->save();

        $this->service()->mergeAssets($survivor, $duplicate->fresh(), $user->id);

        $tombstone = Asset::withTrashed()->find($duplicate->id);
        $this->assertSame($survivor->id, $tombstone->merged_into_asset_id);
        $this->assertSame($retiredAt->timestamp, $tombstone->deleted_at->timestamp);
        $this->assertSame('ninja-old', $survivor->fresh()->ninja_id);
    }

    public function test_merge_prevents_rmm_sync_resurrecting_the_tombstone(): void
    {
        // The Ninja/Level sync and webhook paths match withTrashed()->where('ninja_id', …)
        // and write deleted_at => null straight through. After the merge that lookup
        // must land on the live survivor — never the tombstone.
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create(['ninja_id' => null]);
        $duplicate = Asset::factory()->for($client)->create(['ninja_id' => 'ninja-live']);

        $this->service()->mergeAssets($survivor, $duplicate, $user->id);

        $matches = Asset::withTrashed()->where('ninja_id', 'ninja-live')->get();
        $this->assertCount(1, $matches);
        $this->assertSame($survivor->id, $matches->first()->id);
        $this->assertNull($matches->first()->deleted_at);
    }
}
