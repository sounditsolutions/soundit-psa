<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiRunStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Models\WikiRun;
use App\Services\Wiki\SyncFactWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncFactWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_noop_when_wiki_disabled(): void
    {
        $client = Client::factory()->create();

        app(SyncFactWriter::class)->safeWriteAssetFacts($client);

        $this->assertSame(0, WikiRun::count());
        $this->assertSame(0, WikiPage::count());
    }

    public function test_writes_asset_facts_and_recomposes_infrastructure(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create();
        Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'DC01',
            'os' => 'Windows Server 2022',
            'ram_gb' => 32,
            'asset_type' => 'Server',
        ]);

        app(SyncFactWriter::class)->safeWriteAssetFacts($client);

        $this->assertSame(1, WikiRun::count());
        $this->assertSame(WikiRunStatus::Completed, WikiRun::first()->status);

        $facts = WikiFact::where('client_id', $client->id)->pluck('statement', 'subject_key');
        $this->assertSame('DC01 runs Windows Server 2022', $facts['asset:dc01:os']);
        $this->assertSame('DC01 has 32 GB RAM', $facts['asset:dc01:ram']);
        $this->assertSame('DC01 is a Server', $facts['asset:dc01:type']);

        $infra = WikiPage::forClient($client->id)->where('slug', 'infrastructure')->first();
        $this->assertStringContainsString('- DC01 runs Windows Server 2022', $infra->body_md);
    }

    public function test_writes_m365_facts_from_cipp_snapshot(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create([
            'cipp_conditional_access_policies' => [
                ['displayName' => 'Require MFA', 'state' => 'enabled'],
                ['displayName' => 'Block legacy auth', 'state' => 'enabled'],
            ],
            'cipp_transport_rules' => [['Name' => 'External tag', 'State' => 'Enabled']],
        ]);

        app(SyncFactWriter::class)->safeWriteM365Facts($client);

        $facts = WikiFact::where('client_id', $client->id)->pluck('statement', 'subject_key');
        $this->assertSame('M365 tenant has 2 conditional access policies', $facts['m365:ca-policies']);
        $this->assertSame('M365 tenant has 1 mail transport rule', $facts['m365:transport-rules']);
    }

    public function test_writes_facts_for_many_assets_in_one_run(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create();
        Asset::factory()->count(50)->create(['client_id' => $client->id]);

        $run = app(SyncFactWriter::class)->writeAssetFacts($client);

        $this->assertSame(WikiRunStatus::Completed, $run->status);
        $this->assertSame(150, $run->stage_results['infrastructure']['facts_written']); // type/os/ram per factory asset (cpu/serial/ip are null)
        $this->assertSame(150, WikiFact::where('client_id', $client->id)->count());
    }

    public function test_safe_wrapper_swallows_exceptions(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->make(); // unsaved → writer will throw internally

        app(SyncFactWriter::class)->safeWriteAssetFacts($client); // must not throw

        $this->assertTrue(true);
    }

    public function test_stale_asset_facts_are_retired_when_asset_removed(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create();
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'DC01',
            'os' => 'Windows Server 2022',
            'ram_gb' => 32,
            'asset_type' => 'Server',
        ]);

        // First sync — facts are written.
        app(SyncFactWriter::class)->writeAssetFacts($client);
        $this->assertGreaterThan(0, WikiFact::where('client_id', $client->id)->where('status', 'confirmed')->count());

        // Remove the asset, then re-sync.
        $asset->forceDelete();
        app(SyncFactWriter::class)->writeAssetFacts($client);

        // All the old asset facts should now be retired.
        $active = WikiFact::where('client_id', $client->id)
            ->whereNot('status', 'retired')
            ->count();
        $this->assertSame(0, $active);

        $retired = WikiFact::where('client_id', $client->id)
            ->where('status', 'retired')
            ->count();
        $this->assertGreaterThan(0, $retired);
    }

    public function test_null_hostname_assets_are_skipped(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create();
        Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'NORMAL01',
            'os' => 'Windows 11 Pro',
            'ram_gb' => 16,
            'asset_type' => 'Workstation',
        ]);
        Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => null,
            'os' => 'Windows 11 Pro',
            'ram_gb' => 16,
            'asset_type' => 'Workstation',
        ]);

        app(SyncFactWriter::class)->writeAssetFacts($client);

        $facts = WikiFact::where('client_id', $client->id)->get();
        // Only the normal asset's facts should exist — null hostname is skipped.
        $this->assertGreaterThan(0, $facts->count());
        $this->assertTrue($facts->every(fn ($f) => str_starts_with($f->subject_key, 'asset:normal01:')));
    }
}
