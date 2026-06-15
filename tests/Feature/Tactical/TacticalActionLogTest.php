<?php

namespace Tests\Feature\Tactical;

use App\Models\TacticalActionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Task 2 (P2): tactical_action_logs is an APPEND-ONLY audit table.
 *
 * Two enforcement layers (spec §11 / amendment M6):
 *   - The Eloquent model blocks update()/delete() via boot updating/deleting
 *     guards. This runs everywhere, including SQLite (the test DB).
 *   - The DB itself blocks raw UPDATE/DELETE via BEFORE-triggers, but those are
 *     MariaDB/MySQL-only. The trigger tests below are skipped on SQLite, so on
 *     CI/SQLite only the model guard is exercised. The trigger path is verified
 *     against MariaDB at deploy/CI (see the report caveat).
 */
class TacticalActionLogTest extends TestCase
{
    use RefreshDatabase;

    private function makeRow(array $overrides = []): TacticalActionLog
    {
        return TacticalActionLog::create(array_merge([
            'actor_id' => null,
            'actor_label' => 'tech@example.com',
            'action_key' => 'tactical.run_script',
            'agent_id' => 'AGENT-123',
            'asset_id' => null,
            'ticket_id' => null,
            'target_label' => 'WORKSTATION-01',
            'params' => ['script' => 201, 'args' => ['--foo']],
            'result_status' => 'ok',
            'retcode' => 0,
            'output' => 'done',
            'message' => null,
            'correlation_id' => (string) Str::uuid(),
        ], $overrides));
    }

    public function test_a_log_row_can_be_created(): void
    {
        $row = $this->makeRow();

        $this->assertDatabaseHas('tactical_action_logs', [
            'id' => $row->id,
            'action_key' => 'tactical.run_script',
            'result_status' => 'ok',
        ]);

        // params is cast to array on the way back out.
        $this->assertSame(['script' => 201, 'args' => ['--foo']], $row->fresh()->params);
        // No updated_at — append-only.
        $this->assertNull($row->getAttribute('updated_at'));
    }

    public function test_model_update_throws(): void
    {
        $row = $this->makeRow();

        $this->expectException(LogicException::class);

        $row->update(['result_status' => 'error']);
    }

    public function test_model_save_of_a_dirty_existing_row_throws(): void
    {
        $row = $this->makeRow();
        $row->message = 'tampered';

        $this->expectException(LogicException::class);

        $row->save();
    }

    public function test_model_delete_throws(): void
    {
        $row = $this->makeRow();

        $this->expectException(LogicException::class);

        $row->delete();
    }

    public function test_db_triggers_block_raw_update_and_delete(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("DB triggers are MariaDB/MySQL-only; driver is [{$driver}]. Model guard covers SQLite.");
        }

        $row = $this->makeRow();

        // Raw query-builder UPDATE must be blocked by the BEFORE UPDATE trigger.
        try {
            DB::table('tactical_action_logs')->where('id', $row->id)->update(['result_status' => 'error']);
            $this->fail('Expected the BEFORE UPDATE trigger to block a raw update');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }

        // Raw query-builder DELETE must be blocked by the BEFORE DELETE trigger.
        try {
            DB::table('tactical_action_logs')->where('id', $row->id)->delete();
            $this->fail('Expected the BEFORE DELETE trigger to block a raw delete');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }

        // Row survived both attempts.
        $this->assertDatabaseHas('tactical_action_logs', ['id' => $row->id, 'result_status' => 'ok']);
    }

    public function test_db_triggers_exist_in_information_schema(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("information_schema.TRIGGERS check is MariaDB/MySQL-only; driver is [{$driver}].");
        }

        $triggers = DB::table('information_schema.TRIGGERS')
            ->where('EVENT_OBJECT_TABLE', 'tactical_action_logs')
            ->pluck('EVENT_MANIPULATION')
            ->map(fn ($e) => strtoupper((string) $e))
            ->all();

        $this->assertContains('UPDATE', $triggers, 'A BEFORE UPDATE trigger must exist');
        $this->assertContains('DELETE', $triggers, 'A BEFORE DELETE trigger must exist');
    }
}
