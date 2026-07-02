<?php

namespace Tests\Feature\Signals;

use App\Models\SignalConfigLog;
use App\Models\SignalDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertNotSame(
            'https://x.example/hook',
            DB::table('signal_destinations')->whereKey($destination->id)->value('address'),
        );
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
}
