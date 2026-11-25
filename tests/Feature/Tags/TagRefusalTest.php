<?php

namespace Tests\Feature\Tags;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Tag;
use App\Models\TagAssignmentLog;
use App\Services\TagService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The refusals in Tag and TagService, and the transactions around attach, detach,
 * sync and moveAll, each pinned by a test here or in TagServiceTest that fails when
 * that refusal or transaction is removed. Every refusal is checked by its exact
 * message, so a later, accidental failure (a NOT NULL column, a second check)
 * cannot stand in for it.
 *
 * One check is not covered: sync's own assertActive loop before it writes. attach
 * repeats that check inside the same transaction, so removing the loop changes no
 * outcome and no test can fail on it. The message it would throw is pinned through
 * attach.
 */
class TagRefusalTest extends TestCase
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

    private function client(): Client
    {
        return Client::create(['name' => 'Synthetic Client']);
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

    private function assertRefused(string $message, Closure $call): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());

            return;
        }
        $this->fail("Expected the refusal: {$message}");
    }

    public function test_a_name_whose_lower_cased_key_is_longer_than_the_column_is_refused(): void
    {
        // The declared column length (sqlite does not enforce it, so read the schema source).
        $migration = file_get_contents(base_path('database/migrations/2026_11_25_100000_create_tags_tables.php'));
        $this->assertStringContainsString("\$table->string('name_key', 64)", $migration);

        // "İ" (U+0130) lower-cases to "i" plus U+0307: 64 characters become 128.
        $name = str_repeat('İ', 64);
        $this->assertSame(64, mb_strlen($name));
        $this->assertSame(128, mb_strlen(Tag::nameKey($name)));

        $this->assertRefused(
            'This tag name is longer than 64 characters once lower-cased.',
            fn () => Tag::create(['name' => $name]),
        );
        $this->assertSame(0, Tag::count());
        $this->assertSame(64, Tag::NAME_KEY_MAX);

        // A name of the same length whose key does not grow is accepted.
        $this->assertSame(Tag::NAME_MAX, mb_strlen(Tag::create(['name' => str_repeat('A', Tag::NAME_MAX)])->name_key));
    }

    public function test_a_blank_name_is_refused(): void
    {
        $this->assertRefused('A tag name cannot be blank.', fn () => Tag::create(['name' => " \t "]));
        $this->assertSame(0, Tag::count());
    }

    public function test_a_name_over_name_max_is_refused(): void
    {
        $this->assertRefused(
            'A tag name can be at most 64 characters.',
            fn () => Tag::create(['name' => str_repeat('a', Tag::NAME_MAX + 1)]),
        );
        $this->assertSame(0, Tag::count());
    }

    public function test_a_rename_is_normalized_and_validated_again(): void
    {
        $tag = Tag::create(['name' => 'Alpha']);

        $tag->update(['name' => '  New   Name ']);
        $row = DB::table('tags')->where('id', $tag->id)->first();
        $this->assertSame('New Name', $row->name);
        $this->assertSame('new name', $row->name_key);

        $this->assertRefused('A tag name cannot be blank.', fn () => $tag->fresh()->update(['name' => '   ']));
        $this->assertRefused(
            'A tag name can be at most 64 characters.',
            fn () => $tag->fresh()->update(['name' => str_repeat('b', Tag::NAME_MAX + 1)]),
        );

        $row = DB::table('tags')->where('id', $tag->id)->first();
        $this->assertSame('New Name', $row->name);
        $this->assertSame('new name', $row->name_key);
    }

    public function test_an_unsaved_tag_is_refused_before_any_link_is_touched(): void
    {
        $person = $this->person($this->client());
        $a = Tag::create(['name' => 'Alpha']);
        $b = Tag::create(['name' => 'Bravo']);
        $this->service()->attach($person, $a, null, 'web');
        $this->service()->attach($person, $b, null, 'web');
        $unsaved = new Tag(['name' => 'Never saved']);

        // detach(null) on the relation would remove every link of the person.
        $this->assertRefused('Cannot use an unsaved tag.', fn () => $this->service()->detach($person, $unsaved, null, 'web'));
        $this->assertRefused('Cannot use an unsaved tag.', fn () => $this->service()->attach($person, $unsaved, null, 'web'));

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $person->tags()->pluck('tags.id')->all());
        $this->assertSame(2, TagAssignmentLog::count());
    }

    public function test_an_unsaved_record_is_refused_on_attach_detach_sync_and_move(): void
    {
        $client = $this->client();
        $tag = Tag::create(['name' => 'Alpha']);
        $unsaved = new Person([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Unsaved',
            'last_name' => 'Person',
        ]);
        $saved = $this->person($client);

        $this->assertRefused('Cannot tag an unsaved record.', fn () => $this->service()->attach($unsaved, $tag, null, 'web'));
        $this->assertRefused('Cannot tag an unsaved record.', fn () => $this->service()->detach($unsaved, $tag, null, 'web'));
        $this->assertRefused('Cannot tag an unsaved record.', fn () => $this->service()->sync($unsaved, [$tag->id], null, 'web'));
        $this->assertRefused('Cannot tag an unsaved record.', fn () => $this->service()->moveAll($saved, $unsaved, null));

        $this->assertSame(0, DB::table('person_tag')->count());
        $this->assertSame(0, TagAssignmentLog::count());
    }

    public function test_sync_refuses_an_unknown_source_even_when_there_is_nothing_to_change(): void
    {
        $person = $this->person($this->client());
        $tag = Tag::create(['name' => 'Alpha']);
        $this->service()->attach($person, $tag, null, 'web');

        // Nothing to attach or detach, so only sync's own source check can refuse.
        $this->assertRefused('Unknown tag change source.', fn () => $this->service()->sync($person, [$tag->id], null, 'portal'));
        $this->assertRefused('Unknown tag change source.', fn () => $this->service()->sync($this->person($this->client()), [], null, 'portal'));

        $this->assertSame([$tag->id], $person->tags()->pluck('tags.id')->all());
        $this->assertSame(1, TagAssignmentLog::count());
    }

    public function test_move_all_refuses_an_unknown_source_even_when_there_is_nothing_to_move(): void
    {
        $client = $this->client();
        $from = $this->person($client);
        $to = $this->person($client);

        // $from has no tags, so only moveAll's own source check can refuse.
        $this->assertRefused('Unknown tag change source.', fn () => $this->service()->moveAll($from, $to, null, 'portal'));

        $this->assertSame(0, DB::table('person_tag')->count());
        $this->assertSame(0, TagAssignmentLog::count());
    }

    public function test_move_all_refuses_entities_of_different_types(): void
    {
        $client = $this->client();
        $person = $this->person($client);
        $tag = Tag::create(['name' => 'Alpha']);
        $this->service()->attach($person, $tag, null, 'web');

        $this->assertRefused(
            'Tags can only move between entities of one type.',
            fn () => $this->service()->moveAll($person, $client, null),
        );

        $this->assertSame([$tag->id], $person->tags()->pluck('tags.id')->all());
        $this->assertSame(0, DB::table('client_tag')->count());
        $this->assertSame(1, TagAssignmentLog::count());
    }

    public function test_attach_rolls_back_the_link_when_the_log_write_fails(): void
    {
        $person = $this->person($this->client());
        $tag = Tag::create(['name' => 'Alpha']);
        TagAssignmentLog::creating(function (): void {
            throw new RuntimeException('Synthetic log failure');
        });

        try {
            $this->service()->attach($person, $tag, null, 'web');
            $this->fail('attach returned although the log write failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic log failure', $e->getMessage());
        }

        $this->assertSame(0, DB::table('person_tag')->count(), 'The link survived a failed log write.');
        $this->assertSame(0, TagAssignmentLog::count());
    }

    /** Make every later log write whose action is $action throw. */
    private function failLogWrites(string $action): void
    {
        TagAssignmentLog::creating(function (TagAssignmentLog $log) use ($action): void {
            if ($log->action === $action) {
                throw new RuntimeException('Synthetic log failure');
            }
        });
    }

    private function assertSyntheticLogFailure(string $verb, Closure $call): void
    {
        try {
            $call();
            $this->fail("{$verb} returned although the log write failed.");
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic log failure', $e->getMessage());
        }
    }

    public function test_detach_keeps_the_link_when_the_log_write_fails(): void
    {
        $person = $this->person($this->client());
        $tag = Tag::create(['name' => 'Alpha']);
        $this->service()->attach($person, $tag, null, 'web');
        $this->failLogWrites('detached');

        $this->assertSyntheticLogFailure('detach', fn () => $this->service()->detach($person, $tag, null, 'web'));

        $this->assertSame([$tag->id], $person->tags()->pluck('tags.id')->all(), 'The link was removed although its log write failed.');
        $this->assertSame(['attached'], TagAssignmentLog::orderBy('id')->pluck('action')->all());
    }

    public function test_sync_rolls_back_its_earlier_changes_when_a_later_log_write_fails(): void
    {
        $person = $this->person($this->client());
        $a = Tag::create(['name' => 'Alpha']);
        $b = Tag::create(['name' => 'Bravo']);
        $this->service()->attach($person, $a, null, 'web');
        // sync detaches A first (logged), then attaches B, whose log write fails.
        $this->failLogWrites('attached');

        $this->assertSyntheticLogFailure('sync', fn () => $this->service()->sync($person, [$b->id], null, 'web'));

        $this->assertSame([$a->id], $person->tags()->pluck('tags.id')->all(), 'sync left a partial change behind.');
        $this->assertSame(['attached'], TagAssignmentLog::orderBy('id')->pluck('action')->all());
    }

    public function test_move_all_rolls_back_the_survivor_side_when_a_later_log_write_fails(): void
    {
        $client = $this->client();
        $from = $this->person($client);
        $to = $this->person($client);
        $tag = Tag::create(['name' => 'Alpha']);
        $this->service()->attach($from, $tag, null, 'web');
        // moveAll links and logs the tag on $to first, then detaches it from $from,
        // whose log write fails.
        $this->failLogWrites('detached');

        $this->assertSyntheticLogFailure('moveAll', fn () => $this->service()->moveAll($from, $to, null, 'system'));

        $this->assertSame([], $to->tags()->pluck('tags.id')->all(), 'moveAll left the survivor-side link behind.');
        $this->assertSame([$tag->id], $from->tags()->pluck('tags.id')->all());
        $this->assertSame(1, TagAssignmentLog::count());
    }
}
