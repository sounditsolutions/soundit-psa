<?php

namespace Tests\Feature\Mcp;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class McpTokenTrustFlagMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_only_chet_and_preserves_existing_non_chet_rows(): void
    {
        Schema::dropIfExists('mcp_tokens');
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100)->unique();
            $table->string('token_hash', 64);
            $table->string('token_prefix', 32)->nullable();
            $table->json('tools')->nullable();
            $table->text('directive')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        DB::table('mcp_tokens')->insert([
            [
                'label' => 'chet',
                'token_hash' => str_repeat('a', 64),
                'tools' => json_encode(['find_staff']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'label' => 'office-teams-pack',
                'token_hash' => str_repeat('b', 64),
                'tools' => json_encode(['poll_operator_messages']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration = require database_path('migrations/2026_07_03_000002_add_trust_flags_to_mcp_tokens_table.php');
        $migration->up();

        $chet = DB::table('mcp_tokens')->where('label', 'chet')->first();
        $teams = DB::table('mcp_tokens')->where('label', 'office-teams-pack')->first();

        $this->assertEquals(1, $chet->ai_actor);
        $this->assertEquals(1, $chet->require_explicit_client_scope);
        $this->assertEquals(0, $teams->ai_actor);
        $this->assertEquals(0, $teams->require_explicit_client_scope);

        DB::table('mcp_tokens')->insert([
            'label' => 'post-migration-row',
            'token_hash' => str_repeat('c', 64),
            'tools' => json_encode(['find_staff']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $postMigration = DB::table('mcp_tokens')->where('label', 'post-migration-row')->first();
        $this->assertEquals(0, $postMigration->ai_actor);
        $this->assertEquals(0, $postMigration->require_explicit_client_scope);
    }
}
