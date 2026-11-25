<?php

namespace Tests\Feature\Tags;

use App\Enums\PersonType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Tag;
use App\Models\TagAssignmentLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TagService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class TagServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function service(): TagService
    {
        return app(TagService::class);
    }

    private function client(string $name = 'Synthetic Client'): Client
    {
        return Client::create(['name' => $name]);
    }

    private function person(Client $client): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Synthetic',
            'last_name' => 'Person',
            'is_active' => true,
        ]);
    }

    private function tag(string $name, array $overrides = []): Tag
    {
        return Tag::create(array_merge(['name' => $name], $overrides));
    }

    public function test_attach_writes_one_log_row_with_actor_entity_client_snapshot_and_source(): void
    {
        $client = $this->client();
        $person = $this->person($client);
        $actor = User::factory()->create();
        $tag = $this->tag('Purchaser');

        $this->assertTrue($this->service()->attach($person, $tag, $actor, 'web'));

        $this->assertSame([$tag->id], $person->tags()->pluck('tags.id')->all());
        $this->assertSame(1, TagAssignmentLog::count());
        $log = TagAssignmentLog::first();
        $this->assertSame($tag->id, $log->tag_id);
        $this->assertSame('Purchaser', $log->tag_name);
        $this->assertSame('person', $log->entity_type);
        $this->assertSame($person->id, $log->entity_id);
        $this->assertSame($client->id, $log->client_id);
        $this->assertSame('attached', $log->action);
        $this->assertSame('web', $log->source);
        $this->assertSame($actor->id, $log->changed_by);
        $this->assertNotNull($log->created_at);
    }

    public function test_client_snapshot_is_the_client_itself_for_a_client_and_the_owner_for_an_asset(): void
    {
        $client = $this->client();
        $asset = Asset::factory()->for($client)->create();
        $tag = $this->tag('Loaner');

        $this->service()->attach($client, $tag, null, 'system');
        $this->service()->attach($asset, $tag, null, 'mcp');

        $clientRow = TagAssignmentLog::where('entity_type', 'client')->sole();
        $this->assertSame($client->id, $clientRow->entity_id);
        $this->assertSame($client->id, $clientRow->client_id);
        $this->assertNull($clientRow->changed_by);

        $assetRow = TagAssignmentLog::where('entity_type', 'asset')->sole();
        $this->assertSame($asset->id, $assetRow->entity_id);
        $this->assertSame($client->id, $assetRow->client_id);
        $this->assertSame('mcp', $assetRow->source);
    }

    public function test_duplicate_attach_writes_no_link_and_no_log(): void
    {
        $person = $this->person($this->client());
        $tag = $this->tag('VIP');

        $this->service()->attach($person, $tag, null, 'web');
        $this->assertFalse($this->service()->attach($person, $tag, null, 'web'));

        $this->assertSame(1, DB::table('person_tag')->count());
        $this->assertSame(1, TagAssignmentLog::count());
    }

    public function test_detach_writes_one_row_and_detach_of_an_unlinked_tag_writes_none(): void
    {
        $person = $this->person($this->client());
        $actor = User::factory()->create();
        $tag = $this->tag('VIP');
        $other = $this->tag('Billing contact');

        $this->service()->attach($person, $tag, $actor, 'web');
        $this->assertTrue($this->service()->detach($person, $tag, $actor, 'web'));
        $this->assertFalse($this->service()->detach($person, $other, $actor, 'web'));
        $this->assertFalse($this->service()->detach($person, $tag, $actor, 'web'));

        $this->assertSame(0, DB::table('person_tag')->count());
        $this->assertSame(['attached', 'detached'], TagAssignmentLog::orderBy('id')->pluck('action')->all());
        $detached = TagAssignmentLog::where('action', 'detached')->sole();
        $this->assertSame($tag->id, $detached->tag_id);
        $this->assertSame($actor->id, $detached->changed_by);
    }

    public function test_sync_writes_exactly_the_difference(): void
    {
        $person = $this->person($this->client());
        [$a, $b, $c] = [$this->tag('Alpha'), $this->tag('Bravo'), $this->tag('Charlie tag')];
        $this->service()->attach($person, $a, null, 'web');
        $this->service()->attach($person, $b, null, 'web');
        $before = TagAssignmentLog::max('id');

        $result = $this->service()->sync($person, [$b->id, $c->id, (string) $c->id], null, 'web');

        $this->assertSame(['attached' => [$c->id], 'detached' => [$a->id]], $result);
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $person->tags()->pluck('tags.id')->all());
        $rows = TagAssignmentLog::where('id', '>', $before)->orderBy('id')->get(['tag_id', 'action']);
        $this->assertSame([[$a->id, 'detached'], [$c->id, 'attached']], $rows->map(fn ($r) => [$r->tag_id, $r->action])->all());

        // A no-op sync writes nothing.
        $count = TagAssignmentLog::count();
        $this->service()->sync($person, [$c->id, $b->id], null, 'web');
        $this->assertSame($count, TagAssignmentLog::count());
    }

    public function test_sync_refuses_an_unknown_tag_id_and_changes_nothing(): void
    {
        $person = $this->person($this->client());
        $a = $this->tag('Alpha');
        $this->service()->attach($person, $a, null, 'web');

        try {
            $this->service()->sync($person, [999999], null, 'web');
            $this->fail('sync accepted an unknown tag id.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Unknown tag id(s): 999999.', $e->getMessage());
        }

        $this->assertSame([$a->id], $person->tags()->pluck('tags.id')->all());
        $this->assertSame(1, TagAssignmentLog::count());
    }

    public function test_an_inactive_tag_is_refused_on_attach_and_on_sync(): void
    {
        $person = $this->person($this->client());
        $retired = $this->tag('Retired tag', ['is_active' => false]);

        try {
            $this->service()->attach($person, $retired, null, 'web');
            $this->fail('attach accepted an inactive tag.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('A retired tag cannot be assigned.', $e->getMessage());
        }

        try {
            $this->service()->sync($person, [$retired->id], null, 'web');
            $this->fail('sync attached an inactive tag.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('A retired tag cannot be assigned.', $e->getMessage());
        }

        $this->assertSame(0, DB::table('person_tag')->count());
        $this->assertSame(0, TagAssignmentLog::count());
    }

    public function test_an_inactive_tag_already_linked_can_still_be_detached(): void
    {
        $person = $this->person($this->client());
        $tag = $this->tag('Soon retired');
        $this->service()->attach($person, $tag, null, 'web');
        $tag->update(['is_active' => false]);

        $this->assertTrue($this->service()->detach($person, $tag, null, 'web'));
        $this->assertSame(0, DB::table('person_tag')->count());
    }

    public function test_a_model_that_is_not_person_client_or_asset_is_refused(): void
    {
        $client = $this->client();
        $ticket = Ticket::create([
            'client_id' => $client->id,
            'subject' => 'Synthetic',
            'type' => TicketType::ServiceRequest,
            'status' => TicketStatus::New,
            'priority' => TicketPriority::P3,
            'opened_at' => now(),
        ]);
        $tag = $this->tag('Alpha');

        foreach (['attach', 'detach'] as $verb) {
            try {
                $this->service()->{$verb}($ticket, $tag, null, 'web');
                $this->fail("{$verb} accepted a Ticket.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Tags apply only to people, clients and assets.', $e->getMessage());
            }
        }
        try {
            $this->service()->sync($ticket, [$tag->id], null, 'web');
            $this->fail('sync accepted a Ticket.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Tags apply only to people, clients and assets.', $e->getMessage());
        }

        $this->assertSame(0, TagAssignmentLog::count());
    }

    public function test_an_unknown_source_is_refused(): void
    {
        $person = $this->person($this->client());
        $tag = $this->tag('Alpha');

        foreach (['attach', 'detach'] as $verb) {
            try {
                $this->service()->{$verb}($person, $tag, null, 'portal');
                $this->fail("{$verb} accepted an unknown source.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Unknown tag change source.', $e->getMessage());
            }
        }

        $this->assertSame(0, DB::table('person_tag')->count());
        $this->assertSame(0, TagAssignmentLog::count());
    }

    public function test_tag_names_are_unique_case_insensitively_after_trimming(): void
    {
        $tag = $this->tag('  Purchaser   contact ');
        $this->assertSame('Purchaser contact', $tag->name);
        $this->assertSame('purchaser contact', $tag->name_key);

        $this->expectException(QueryException::class);
        $this->tag('PURCHASER  Contact');
    }

    public function test_tag_colour_is_a_closed_list_without_gold(): void
    {
        $this->assertNotContains('gold', Tag::COLORS);
        $this->assertNotContains('signal-gold', Tag::COLORS);
        $this->assertSame('neutral', $this->tag('Default colour')->color);

        try {
            $this->tag('Gold tag', ['color' => 'signal-gold']);
            $this->fail('A gold tag colour was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Unknown tag colour.', $e->getMessage());
        }
        $this->assertSame(0, Tag::where('name', 'Gold tag')->count());
    }

    public function test_force_deleting_a_person_removes_its_pivot_rows_and_soft_delete_keeps_them(): void
    {
        $client = $this->client();
        $kept = $this->person($client);
        $gone = $this->person($client);
        $tag = $this->tag('Alpha');
        $this->service()->attach($kept, $tag, null, 'web');
        $this->service()->attach($gone, $tag, null, 'web');

        $kept->delete();
        $this->assertSame(1, DB::table('person_tag')->where('person_id', $kept->id)->count(), 'a soft delete must keep the link');

        $gone->forceDelete();
        $this->assertSame(0, DB::table('person_tag')->where('person_id', $gone->id)->count());
    }

    public function test_force_deleting_a_client_or_asset_removes_its_pivot_rows(): void
    {
        $client = $this->client();
        $asset = Asset::factory()->for($this->client('Synthetic Other'))->create();
        $tag = $this->tag('Alpha');
        $this->service()->attach($client, $tag, null, 'web');
        $this->service()->attach($asset, $tag, null, 'web');

        $client->forceDelete();
        $asset->forceDelete();

        $this->assertSame(0, DB::table('client_tag')->count());
        $this->assertSame(0, DB::table('asset_tag')->count());
    }

    public function test_deleting_a_tag_removes_its_links_and_the_log_survives(): void
    {
        $client = $this->client();
        $person = $this->person($client);
        $asset = Asset::factory()->for($client)->create();
        $tag = $this->tag('Short lived');
        $this->service()->attach($person, $tag, null, 'web');
        $this->service()->attach($client, $tag, null, 'web');
        $this->service()->attach($asset, $tag, null, 'web');
        $tagId = $tag->id;

        $tag->delete();

        $this->assertSame(0, DB::table('person_tag')->count() + DB::table('client_tag')->count() + DB::table('asset_tag')->count());
        $this->assertSame(3, TagAssignmentLog::where('tag_id', $tagId)->count());
        $this->assertSame(['Short lived'], TagAssignmentLog::where('tag_id', $tagId)->distinct()->pluck('tag_name')->all());
    }

    public function test_log_rows_refuse_update_and_delete_through_the_model(): void
    {
        $person = $this->person($this->client());
        $this->service()->attach($person, $this->tag('Alpha'), null, 'web');
        $log = TagAssignmentLog::first();

        try {
            $log->update(['action' => 'detached']);
            $this->fail('A log row was updated.');
        } catch (LogicException) {
        }
        try {
            $log->delete();
            $this->fail('A log row was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame('attached', TagAssignmentLog::first()->action);
    }
}
