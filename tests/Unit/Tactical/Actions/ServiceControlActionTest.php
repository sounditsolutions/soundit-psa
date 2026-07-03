<?php

namespace Tests\Unit\Tactical\Actions;

use App\Services\Tactical\Actions\InvalidActionParams;
use App\Services\Tactical\Actions\ServiceControlAction;
use App\Services\Tactical\Actions\ServiceStartTypeAction;
use App\Services\Tactical\TacticalClient;
use Mockery;
use Tests\TestCase;

class ServiceControlActionTest extends TestCase
{
    public function test_service_control_keys_and_destructive_flags_are_operation_specific(): void
    {
        $this->assertSame('tactical.service_start', (new ServiceControlAction('start'))->key());
        $this->assertSame('tactical.service_stop', (new ServiceControlAction('stop'))->key());
        $this->assertSame('tactical.service_restart', (new ServiceControlAction('restart'))->key());

        $this->assertFalse((new ServiceControlAction('start'))->isDestructive());
        $this->assertTrue((new ServiceControlAction('stop'))->isDestructive());
        $this->assertTrue((new ServiceControlAction('restart'))->isDestructive());
    }

    public function test_service_control_validates_canonical_service_name(): void
    {
        $params = (new ServiceControlAction('restart'))->validateParams(['service_name' => ' Spooler ']);

        $this->assertSame(['service_name' => 'Spooler'], $params);

        $this->expectException(InvalidActionParams::class);
        (new ServiceControlAction('restart'))->validateParams(['service_name' => '']);
    }

    public function test_service_control_execute_calls_curated_client_method(): void
    {
        $client = Mockery::mock(TacticalClient::class);
        $client->shouldReceive('controlService')
            ->once()
            ->with('AGENT-1', 'Spooler', 'restart')
            ->andReturn('The service was restarted successfully');

        $result = (new ServiceControlAction('restart'))->execute($client, 'AGENT-1', ['service_name' => 'Spooler']);

        $this->assertTrue($result->isOk());
        $this->assertSame('The service was restarted successfully', $result->stdout);
    }

    public function test_service_start_type_allowlists_upstream_start_type_values(): void
    {
        $action = new ServiceStartTypeAction;

        $this->assertSame('tactical.service_start_type', $action->key());
        $this->assertFalse($action->isDestructive());
        $this->assertSame(
            ['service_name' => 'Spooler', 'start_type' => 'autodelay'],
            $action->validateParams(['service_name' => 'Spooler', 'start_type' => 'autodelay'])
        );

        $this->expectException(InvalidActionParams::class);
        $action->validateParams(['service_name' => 'Spooler', 'start_type' => 'boot']);
    }
}
