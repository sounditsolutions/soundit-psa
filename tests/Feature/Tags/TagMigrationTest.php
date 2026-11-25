<?php

namespace Tests\Feature\Tags;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The tag migrations are additive and fully reversible: down() leaves none of
 * their tables behind, and up() after down() rebuilds them.
 */
class TagMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['tags', 'person_tag', 'client_tag', 'asset_tag', 'tag_assignment_logs'];

    private function migration(string $file): object
    {
        return require base_path('database/migrations/'.$file);
    }

    public function test_up_down_up_creates_drops_and_recreates_every_tag_table(): void
    {
        $tags = $this->migration('2026_11_25_100000_create_tags_tables.php');
        $logs = $this->migration('2026_11_25_100001_create_tag_assignment_logs_table.php');

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is missing after the suite's migrate.");
        }

        $logs->down();
        $tags->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} survived down().");
        }
        // down() touched nothing it did not create.
        $this->assertTrue(Schema::hasTable('people'));
        $this->assertTrue(Schema::hasTable('asset_person'));

        $tags->up();
        $logs->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is missing after the second up().");
        }
        $this->assertTrue(Schema::hasColumns('tags', ['id', 'name', 'name_key', 'color', 'is_active', 'created_by', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('tag_assignment_logs', [
            'id', 'tag_id', 'tag_name', 'entity_type', 'entity_id', 'client_id', 'action', 'source', 'changed_by', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('tag_assignment_logs', 'updated_at'));
    }
}
