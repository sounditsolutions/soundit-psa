<?php

namespace Tests\Feature\Tags;

use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Tag;
use App\Models\TagAssignmentLog;
use App\Models\User;
use App\Services\AssetService;
use App\Services\ClientService;
use App\Services\PersonService;
use App\Services\TagService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Each merge service carries the duplicate's tags to the survivor, deduplicated,
 * through TagService, and leaves the duplicate with none. Setup follows
 * PersonMergeServiceTest, AssetMergeServiceTest and ClientMergeServiceTest.
 */
class TagMergeCarryTest extends TestCase
{
    use RefreshDatabase;

    private Tag $a;

    private Tag $b;

    private Tag $c;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();

        $this->a = Tag::create(['name' => 'Synthetic A']);
        $this->b = Tag::create(['name' => 'Synthetic B']);
        $this->c = Tag::create(['name' => 'Synthetic C', 'color' => 'info']);
    }

    private function person(Client $client): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => true,
        ]);
    }

    /** Loser {A,B}, survivor {B,C}, written through the service (source 'web'). */
    private function seedTags(Model $loser, Model $survivor): void
    {
        $tags = app(TagService::class);
        $tags->attach($loser, $this->a, null, 'web');
        $tags->attach($loser, $this->b, null, 'web');
        $tags->attach($survivor, $this->b, null, 'web');
        $tags->attach($survivor, $this->c, null, 'web');
    }

    private function assertCarried(string $pivot, string $fk, string $type, Model $loser, Model $survivor, User $merger, int $clientId): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->a->id, $this->b->id, $this->c->id],
            DB::table($pivot)->where($fk, $survivor->id)->pluck('tag_id')->all(),
            'survivor must hold A, B and C'
        );
        $this->assertSame(3, DB::table($pivot)->where($fk, $survivor->id)->count(), 'no duplicate pivot rows on the survivor');
        $this->assertSame(0, DB::table($pivot)->where($fk, $loser->id)->count(), 'the duplicate keeps no tags');

        $merge = TagAssignmentLog::where('source', 'merge')->orderBy('id')->get();
        $rows = $merge->map(fn ($r) => [$r->entity_id, $r->tag_id, $r->action])->all();
        $this->assertEqualsCanonicalizing([
            [$survivor->id, $this->a->id, 'attached'],
            [$loser->id, $this->a->id, 'detached'],
            [$loser->id, $this->b->id, 'detached'],
        ], $rows, 'one merge log row per moved link and per detach from the duplicate, none for B on the survivor');
        foreach ($merge as $row) {
            $this->assertSame($type, $row->entity_type);
            $this->assertSame($merger->id, $row->changed_by);
            $this->assertSame($clientId, $row->client_id);
        }
    }

    public function test_merge_people_carries_tags_to_the_survivor(): void
    {
        $client = Client::create(['name' => 'Synthetic Client']);
        $survivor = $this->person($client);
        $dup = $this->person($client);
        $this->seedTags($dup, $survivor);
        $merger = User::factory()->create();

        app(PersonService::class)->mergePeople($survivor, $dup, $merger->id);

        $this->assertCarried('person_tag', 'person_id', 'person', $dup, $survivor, $merger, $client->id);
    }

    public function test_merge_assets_carries_tags_to_the_survivor(): void
    {
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create();
        $dup = Asset::factory()->for($client)->create();
        $this->seedTags($dup, $survivor);
        $merger = User::factory()->create();

        app(AssetService::class)->mergeAssets($survivor, $dup, $merger->id);

        $this->assertCarried('asset_tag', 'asset_id', 'asset', $dup, $survivor, $merger, $client->id);
    }

    public function test_merge_clients_carries_tags_to_the_survivor(): void
    {
        $survivor = Client::create(['name' => 'Synthetic Survivor']);
        $dup = Client::create(['name' => 'Synthetic Duplicate']);
        $this->seedTags($dup, $survivor);
        $merger = User::factory()->create();

        app(ClientService::class)->mergeClients($survivor, $dup, $merger->id);

        $this->assertEqualsCanonicalizing(
            [$this->a->id, $this->b->id, $this->c->id],
            DB::table('client_tag')->where('client_id', $survivor->id)->pluck('tag_id')->all()
        );
        $this->assertSame(3, DB::table('client_tag')->where('client_id', $survivor->id)->count());
        $this->assertSame(0, DB::table('client_tag')->where('client_id', $dup->id)->count());

        // A client row's client_id snapshot is the row's own entity.
        $rows = TagAssignmentLog::where('source', 'merge')->get()
            ->map(fn ($r) => [$r->entity_type, $r->entity_id, $r->client_id, $r->tag_id, $r->action, $r->changed_by])->all();
        $this->assertEqualsCanonicalizing([
            ['client', $survivor->id, $survivor->id, $this->a->id, 'attached', $merger->id],
            ['client', $dup->id, $dup->id, $this->a->id, 'detached', $merger->id],
            ['client', $dup->id, $dup->id, $this->b->id, 'detached', $merger->id],
        ], $rows);
    }

    public function test_merge_clients_leaves_people_and_asset_tags_on_their_moved_records(): void
    {
        $survivor = Client::create(['name' => 'Synthetic Survivor']);
        $dup = Client::create(['name' => 'Synthetic Duplicate']);
        $person = $this->person($dup);
        $asset = Asset::factory()->for($dup)->create();
        app(TagService::class)->attach($person, $this->a, null, 'web');
        app(TagService::class)->attach($asset, $this->b, null, 'web');

        app(ClientService::class)->mergeClients($survivor, $dup, User::factory()->create()->id);

        $this->assertSame([$this->a->id], $person->fresh()->tags()->pluck('tags.id')->all());
        $this->assertSame([$this->b->id], $asset->fresh()->tags()->pluck('tags.id')->all());
        $this->assertSame($survivor->id, $person->fresh()->client_id);
    }

    public function test_an_inactive_tag_on_the_duplicate_still_carries(): void
    {
        $client = Client::create(['name' => 'Synthetic Client']);
        $survivor = $this->person($client);
        $dup = $this->person($client);
        app(TagService::class)->attach($dup, $this->a, null, 'web');
        $this->a->update(['is_active' => false]);

        app(PersonService::class)->mergePeople($survivor, $dup, User::factory()->create()->id);

        $this->assertSame([$this->a->id], $survivor->tags()->pluck('tags.id')->all());
        $this->assertSame(0, $dup->tags()->count());
    }
}
