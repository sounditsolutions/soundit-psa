<?php

namespace Tests\Feature\Servosity;

use App\Jobs\RunTacticalScriptJob;
use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
use App\Models\User;
use App\Services\Servosity\ServosityDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * bd psa-nfqd: enabling Servosity backup must fire the Tactical deploy script
 * through the queued, audited action bus (RunTacticalScriptJob) — not the old
 * raw TacticalClient::runScriptAsync bypass. The deploy runs asynchronously so
 * the enable request never blocks on the ~10-minute installer.
 */
class ServosityBackupDeployTest extends TestCase
{
    use RefreshDatabase;

    private function prop(object $obj, string $name): mixed
    {
        $p = new \ReflectionProperty($obj, $name);
        $p->setAccessible(true);

        return $p->getValue($obj);
    }

    public function test_enabling_backup_dispatches_the_deploy_through_the_action_bus_job(): void
    {
        Queue::fake();

        // enableBackup talks to Servosity + Tactical for real — mock it so the
        // test exercises only the controller's dispatch wiring.
        $this->mock(ServosityDeploymentService::class, function ($m) {
            $m->shouldReceive('enableBackup')->once();
        });

        $user = User::factory()->create();
        $client = Client::factory()->create(['servosity_company_id' => 123]);
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'WS-1',
            'servosity_backup_enabled' => false,
        ]);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'WS-1',
            'status' => 'online',
        ]);

        $resp = $this->actingAs($user)->post(route('assets.servosity.toggle-backup', $asset));

        $resp->assertRedirect();
        $resp->assertSessionHas('success');

        Queue::assertPushed(RunTacticalScriptJob::class, function (RunTacticalScriptJob $job) use ($asset, $user) {
            return $this->prop($job, 'assetId') === $asset->id
                && $this->prop($job, 'scriptId') === 218
                && $this->prop($job, 'scriptTimeout') === 600
                && $this->prop($job, 'actorId') === $user->id
                && $this->prop($job, 'actorLabel') === 'servosity-deploy'
                // Servosity creds travel only as Tactical-side {{agent.*}}
                // template placeholders — never as literal secrets.
                && str_contains($this->prop($job, 'args'), '{{agent.ServosityCredPass}}');
        });
    }

    public function test_enabling_without_a_servosity_company_mapping_does_not_dispatch(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $client = Client::factory()->create(['servosity_company_id' => null]);
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'WS-2',
            'servosity_backup_enabled' => false,
        ]);

        $resp = $this->actingAs($user)->post(route('assets.servosity.toggle-backup', $asset));

        $resp->assertRedirect();
        $resp->assertSessionHas('error');
        Queue::assertNotPushed(RunTacticalScriptJob::class);
    }
}
