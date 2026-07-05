<?php

namespace Tests\Feature\Signals;

use App\Models\SignalConfigLog;
use App\Models\SignalDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class SignalDestinationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_destination_casts_enabled_and_encrypts_address_at_rest(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://x.example/hook',
        ]);

        $fresh = $destination->fresh();

        $this->assertTrue($fresh->enabled);
        $this->assertSame('https://x.example/hook', $fresh->address);

        $rawAddress = DB::table('signal_destinations')->where('id', $destination->id)->value('address');

        $this->assertNotNull($rawAddress);
        $this->assertNotSame('https://x.example/hook', $rawAddress);
    }

    public function test_config_log_record_inserts_actor_action_subject_and_changes(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://x.example/hook',
        ]);

        SignalConfigLog::record(null, 'created', $destination, ['label' => 'Ops webhook']);

        $this->assertDatabaseHas('signal_config_log', [
            'user_id' => null,
            'action' => 'created',
            'subject_type' => SignalDestination::class,
            'subject_id' => $destination->id,
        ]);

        $this->assertSame(
            ['label' => 'Ops webhook'],
            SignalConfigLog::query()->firstOrFail()->changes,
        );
    }

    public function test_config_log_is_append_only_at_the_model_layer(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://x.example/hook',
        ]);

        SignalConfigLog::record(null, 'created', $destination, ['label' => 'Ops webhook']);
        $row = SignalConfigLog::query()->firstOrFail();

        $this->expectException(LogicException::class);

        $row->update(['action' => 'tampered']);
    }

    public function test_config_log_delete_is_blocked_at_the_model_layer(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://x.example/hook',
        ]);

        SignalConfigLog::record(null, 'created', $destination, ['label' => 'Ops webhook']);
        $row = SignalConfigLog::query()->firstOrFail();

        $this->expectException(LogicException::class);

        $row->delete();
    }

    public function test_config_log_db_triggers_block_raw_update_and_delete(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("DB triggers are MariaDB/MySQL-only; driver is [{$driver}]. Model guard covers SQLite.");
        }

        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://x.example/hook',
        ]);

        SignalConfigLog::record(null, 'created', $destination, ['label' => 'Ops webhook']);
        $row = SignalConfigLog::query()->firstOrFail();

        try {
            DB::table('signal_config_log')->where('id', $row->id)->update(['action' => 'tampered']);
            $this->fail('Expected the BEFORE UPDATE trigger to block a raw update');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }

        try {
            DB::table('signal_config_log')->where('id', $row->id)->delete();
            $this->fail('Expected the BEFORE DELETE trigger to block a raw delete');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }

        $this->assertDatabaseHas('signal_config_log', [
            'id' => $row->id,
            'action' => 'created',
        ]);
    }
}
