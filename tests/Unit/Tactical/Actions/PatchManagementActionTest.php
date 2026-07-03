<?php

namespace Tests\Unit\Tactical\Actions;

use App\Services\Tactical\Actions\PatchAction;
use App\Services\Tactical\Actions\PatchInstallAction;
use App\Services\Tactical\Actions\PatchScanAction;
use App\Services\Tactical\TacticalClient;
use Tests\TestCase;

class PatchManagementActionTest extends TestCase
{
    public function test_patch_action_allowlists_action_values_and_canonicalizes_patch_id(): void
    {
        $action = new PatchAction;

        $this->assertSame('tactical.patch_action', $action->key());
        $this->assertFalse($action->isDestructive());
        $this->assertSame(['patch_id' => 44, 'action' => 'approve'], $action->validateParams([
            'patch_id' => '44',
            'action' => 'APPROVE',
        ]));

        $this->expectExceptionMessage('action must be one of: approve, ignore, nothing, inherit.');
        $action->validateParams(['patch_id' => 44, 'action' => 'install']);
    }

    public function test_scan_and_install_flags_and_execution(): void
    {
        $scanClient = \Mockery::mock(TacticalClient::class);
        $scanClient->shouldReceive('scanPatches')->once()->with('agent-1')->andReturn('scan queued');

        $scan = new PatchScanAction;
        $this->assertSame('tactical.patch_scan', $scan->key());
        $this->assertFalse($scan->isDestructive());
        $this->assertSame('scan queued', $scan->execute($scanClient, 'agent-1', [])->stdout);

        $installClient = \Mockery::mock(TacticalClient::class);
        $installClient->shouldReceive('installApprovedPatches')->once()->with('agent-1')->andReturn('install queued');

        $install = new PatchInstallAction;
        $this->assertSame('tactical.patch_install', $install->key());
        $this->assertTrue($install->isDestructive());
        $this->assertSame('install queued', $install->execute($installClient, 'agent-1', [])->stdout);
    }
}
