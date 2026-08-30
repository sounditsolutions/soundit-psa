<?php

namespace Tests\Feature\Assistant;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Offboarding\DeviceAbsenceVerifier;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Zorus\ZorusClient;
use App\Services\Zorus\ZorusClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * psa-842a: offboard verification must stop being one-eyed.
 *
 * The incident: an operator deleted a device from six vendor portals and could
 * prove removal for exactly one of them. Tactical answered live with a 404;
 * everything else reported a synced snapshot or could not be asked at all —
 * and for ScreenConnect, whose feed is webhook-driven, ABSENCE OF A WEBHOOK IS
 * INDISTINGUISHABLE FROM PRESENCE OF THE DEVICE.
 *
 * What these tests pin, in order of how much they cost if they regress:
 *
 *   1. `cannot_determine` is a first-class verdict and never rounds to absent.
 *      ScreenConnect always lands there while it is on; one such arm anywhere
 *      makes the OVERALL verdict cannot_determine even when every other arm
 *      answered absent live. This is the whole point of the tool and the thing
 *      a later "simplify it to a boolean" would quietly destroy.
 *   2. The Zorus false-positive trap: `customerUuid` on POST
 *      api/endpoints/search is SILENTLY IGNORED by the vendor and the call
 *      returns ALL endpoints (in-code note at ZorusClient::searchEndpoints).
 *      A non-empty response therefore proves nothing — presence must be
 *      established by matching the specific device CLIENT-SIDE.
 *   3. Tactical's 404-only absence rule: any other status, and any transport
 *      failure, is cannot_determine — never absent, never present.
 */
class DeviceAbsenceVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const ZORUS_CUSTOMER = 'cust-uuid-1';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function enableTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://rmm.example.test');
        Setting::setEncrypted('tactical_api_key', 'k');
    }

    private function enableZorus(): void
    {
        Setting::setValue('zorus_enabled', '1');
        Setting::setEncrypted('zorus_api_key', 'k');
    }

    private function enableScreenConnect(): void
    {
        Setting::setValue('screenconnect_enabled', '1');
        Setting::setValue('screenconnect_base_url', 'https://sc.example.test');
        Setting::setValue('screenconnect_webhook_secret', 's');
    }

    private function client(): Client
    {
        return Client::factory()->create(['zorus_customer_id' => self::ZORUS_CUSTOMER]);
    }

    /**
     * `agent_id` is the VENDOR id and lives on the linked tactical_assets row —
     * `assets.tactical_asset_id` is a local foreign key to that row, not something
     * Tactical would recognise. Pass agent_id here and the helper wires the link
     * the way the sync does.
     */
    private function asset(Client $client, array $extra = [], ?string $agentId = null): Asset
    {
        $asset = Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'hostname' => 'OFFBOARD-01',
            'name' => 'OFFBOARD-01',
            'is_active' => false,
        ], $extra));

        if ($agentId !== null) {
            $tactical = TacticalAsset::create([
                'asset_id' => $asset->id,
                'agent_id' => $agentId,
                'hostname' => $asset->hostname,
            ]);
            $asset->forceFill(['tactical_asset_id' => $tactical->id])->save();
            $asset->refresh();
        }

        return $asset;
    }

    /** @param array<int, array<string, mixed>> $endpoints */
    private function fakeZorus(array $endpoints): void
    {
        $zorus = Mockery::mock(ZorusClient::class);
        $zorus->shouldReceive('searchEndpoints')->andReturn($endpoints);
        $this->app->instance(ZorusClient::class, $zorus);
    }

    /**
     * Every Tactical fake pins the id the vendor is asked about. The id in the
     * PSA that LOOKS like an RMM id — `assets.tactical_asset_id` — is a local
     * foreign key into tactical_assets; the vendor's id is that row's agent_id.
     * A verifier that reaches for the wrong one collects a 404 on every linked
     * device and calls it `absent`, which is the exact false negative this whole
     * tool exists to prevent. A mock that accepts any argument cannot see that,
     * so these do not use a wildcard.
     */
    private function fakeTacticalAgent(array $agent, string $expectAgentId = 'agent-1'): void
    {
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')->with($expectAgentId)->andReturn($agent);
        $this->app->instance(TacticalClient::class, $tactical);
    }

    private function fakeTacticalError(TacticalClientException $e, string $expectAgentId = 'agent-1'): void
    {
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')->with($expectAgentId)->andThrow($e);
        $this->app->instance(TacticalClient::class, $tactical);
    }

    private function httpError(int $status): TacticalClientException
    {
        return new TacticalClientException("Tactical API error (HTTP {$status})", 0, null, $status, null, false);
    }

    // ── 1. cannot_determine is load-bearing ──────────────────────────────────

    public function test_screenconnect_always_reports_cannot_determine_never_present(): void
    {
        $this->enableScreenConnect();
        $client = $this->client();

        // The snapshot says the device is ONLINE and was seen moments ago. This is
        // exactly the shape a device deleted in the portal leaves behind, because
        // deletion emits no webhook — so a fresh, healthy-looking row is not
        // evidence of anything.
        $asset = $this->asset($client, [
            'screenconnect_session_id' => 'sess-1',
            'screenconnect_online' => true,
            'screenconnect_last_seen_at' => now(),
            'screenconnect_synced_at' => now(),
        ]);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['screenconnect'];

        $this->assertSame(
            DeviceAbsenceVerifier::CANNOT_DETERMINE,
            $arm['verdict'],
            'ScreenConnect is webhook-receive-only — it can never answer present or absent, however fresh the row looks',
        );
        $this->assertSame('snapshot', $arm['method'], 'the arm must declare its answer came from a snapshot, not the vendor');
        $this->assertTrue($arm['evidence']['snapshot_online'], 'the snapshot is returned for context, and is deliberately not the verdict');
    }

    public function test_one_cannot_determine_arm_makes_the_overall_verdict_cannot_determine(): void
    {
        $this->enableTactical();
        $this->enableZorus();
        $this->enableScreenConnect();
        $client = $this->client();
        $asset = $this->asset($client, [
            'zorus_endpoint_id' => 'ep-1',
            'screenconnect_session_id' => 'sess-1',
        ], agentId: 'agent-1');

        // Both live arms prove the device gone. ScreenConnect cannot be asked.
        $this->fakeTacticalError($this->httpError(404));
        $this->fakeZorus([]);

        $result = app(DeviceAbsenceVerifier::class)->verify($asset);

        $this->assertSame(DeviceAbsenceVerifier::ABSENT, $result['integrations']['tactical']['verdict']);
        $this->assertSame(DeviceAbsenceVerifier::ABSENT, $result['integrations']['zorus']['verdict']);
        $this->assertSame(
            DeviceAbsenceVerifier::CANNOT_DETERMINE,
            $result['overall']['verdict'],
            'two live absences plus one unanswerable arm is NOT a proven teardown — rounding this to absent is the bug',
        );
        $this->assertSame(['screenconnect'], $result['overall']['undetermined']);
    }

    public function test_overall_is_absent_only_when_every_applicable_arm_answered_absent_live(): void
    {
        $this->enableTactical();
        $this->enableZorus();
        // ScreenConnect deliberately left OFF, so it is not_applicable rather than
        // unanswerable — a shop that does not run it must still be able to reach a
        // clean absent.
        $client = $this->client();
        $asset = $this->asset($client, ['zorus_endpoint_id' => 'ep-1'], agentId: 'agent-1');

        $this->fakeTacticalError($this->httpError(404));
        $this->fakeZorus([]);

        $result = app(DeviceAbsenceVerifier::class)->verify($asset);

        $this->assertSame(DeviceAbsenceVerifier::ABSENT, $result['overall']['verdict']);
        $this->assertSame(['tactical', 'zorus'], $result['overall']['checked']);
        $this->assertContains('screenconnect', $result['overall']['not_applicable']);
    }

    public function test_a_present_arm_wins_over_absent_arms(): void
    {
        $this->enableTactical();
        $this->enableZorus();
        $client = $this->client();
        $asset = $this->asset($client, ['zorus_endpoint_id' => 'ep-1'], agentId: 'agent-1');

        $this->fakeTacticalError($this->httpError(404));
        $this->fakeZorus([['uuid' => 'ep-1', 'name' => 'OFFBOARD-01', 'customerUuid' => self::ZORUS_CUSTOMER]]);

        $result = app(DeviceAbsenceVerifier::class)->verify($asset);

        $this->assertSame(DeviceAbsenceVerifier::PRESENT, $result['overall']['verdict']);
        $this->assertSame(['zorus'], $result['overall']['present_in']);
    }

    // ── 2. the Zorus ignored-filter trap ─────────────────────────────────────

    public function test_zorus_absent_even_when_the_endpoint_list_comes_back_full_of_other_devices(): void
    {
        $this->enableZorus();
        $client = $this->client();
        $asset = $this->asset($client, ['zorus_endpoint_id' => 'ep-GONE']);

        // The vendor ignores customerUuid and hands back the WHOLE estate. A check
        // that concluded "present" from a non-empty response — or from a response
        // that merely contains this customer's endpoints — would report a deleted
        // device as still installed.
        $this->fakeZorus([
            ['uuid' => 'ep-OTHER-1', 'name' => 'SOMEONE-ELSE-01', 'customerUuid' => 'cust-uuid-2'],
            ['uuid' => 'ep-OTHER-2', 'name' => 'STILL-HERE-01', 'customerUuid' => self::ZORUS_CUSTOMER],
        ]);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['zorus'];

        $this->assertSame(DeviceAbsenceVerifier::ABSENT, $arm['verdict']);
        $this->assertSame('live', $arm['method']);
        $this->assertSame(
            1,
            $arm['evidence']['endpoints_scanned_for_this_customer'],
            'the whole list must be scanned, but only this client\'s own slice of it may be published back',
        );
        $this->assertArrayNotHasKey(
            'endpoints_scanned',
            $arm['evidence'],
            'the MSP-wide endpoint count is other customers\' business and must never reach a client-scoped caller',
        );
    }

    public function test_zorus_hostname_fallback_is_scoped_to_this_clients_customer(): void
    {
        $this->enableZorus();
        $client = $this->client();
        // No uuid link recorded, so the match falls back to hostname.
        $asset = $this->asset($client, ['zorus_endpoint_id' => null, 'hostname' => 'OFFBOARD-01']);

        // Another customer runs a machine with the SAME hostname. Hostnames are not
        // unique across customers; an unscoped fallback would report their machine
        // as ours and call the teardown incomplete.
        $this->fakeZorus([
            ['uuid' => 'ep-THEIRS', 'name' => 'offboard-01.theirdomain.test', 'customerUuid' => 'cust-uuid-2'],
        ]);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['zorus'];

        $this->assertSame(DeviceAbsenceVerifier::ABSENT, $arm['verdict']);
    }

    public function test_zorus_hostname_fallback_matches_the_short_name_within_the_right_customer(): void
    {
        $this->enableZorus();
        $client = $this->client();
        $asset = $this->asset($client, ['zorus_endpoint_id' => null, 'hostname' => 'OFFBOARD-01']);

        $this->fakeZorus([
            ['uuid' => 'ep-OURS', 'name' => 'offboard-01.ourdomain.test', 'customerUuid' => self::ZORUS_CUSTOMER],
        ]);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['zorus'];

        $this->assertSame(DeviceAbsenceVerifier::PRESENT, $arm['verdict']);
        $this->assertSame('hostname (no uuid link recorded)', $arm['evidence']['matched_on']);
    }

    public function test_zorus_reinstalled_agent_is_present_even_though_the_recorded_uuid_is_gone(): void
    {
        $this->enableZorus();
        $client = $this->client();
        // The PSA remembers the OLD agent's uuid; a reinstall minted a new one.
        $asset = $this->asset($client, ['zorus_endpoint_id' => 'ep-OLD', 'hostname' => 'OFFBOARD-01']);

        $this->fakeZorus([
            ['uuid' => 'ep-NEW', 'name' => 'offboard-01.ourdomain.test', 'customerUuid' => self::ZORUS_CUSTOMER],
        ]);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['zorus'];

        $this->assertSame(
            DeviceAbsenceVerifier::PRESENT,
            $arm['verdict'],
            'a machine still filtering under this customer must not read as absent just because its recorded uuid went stale — that is a false teardown proof',
        );
        $this->assertSame('ep-NEW', $arm['evidence']['endpoint_uuid']);
        $this->assertStringContainsString('reinstalled', $arm['evidence']['matched_on']);
    }

    public function test_zorus_stale_uuid_hostname_pass_is_still_scoped_to_this_customer(): void
    {
        $this->enableZorus();
        $client = $this->client();
        $asset = $this->asset($client, ['zorus_endpoint_id' => 'ep-OLD', 'hostname' => 'OFFBOARD-01']);

        // Same hostname exists live — but under ANOTHER customer. The reinstall
        // heuristic must not widen the customer scope the plain fallback honours.
        $this->fakeZorus([
            ['uuid' => 'ep-THEIRS', 'name' => 'offboard-01.theirdomain.test', 'customerUuid' => 'cust-uuid-2'],
        ]);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['zorus'];

        $this->assertSame(DeviceAbsenceVerifier::ABSENT, $arm['verdict']);
    }

    public function test_zorus_uuid_match_wins_over_an_earlier_hostname_collision(): void
    {
        $this->enableZorus();
        $client = $this->client();
        $asset = $this->asset($client, ['zorus_endpoint_id' => 'ep-MINE', 'hostname' => 'OFFBOARD-01']);

        // An identically-named machine appears FIRST in the list; the real linked
        // endpoint appears later. The uuid is the identity — evidence must name it,
        // not the name-collision row.
        $this->fakeZorus([
            ['uuid' => 'ep-TWIN', 'name' => 'OFFBOARD-01', 'customerUuid' => self::ZORUS_CUSTOMER],
            ['uuid' => 'ep-MINE', 'name' => 'offboard-01.ourdomain.test', 'customerUuid' => self::ZORUS_CUSTOMER],
        ]);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['zorus'];

        $this->assertSame(DeviceAbsenceVerifier::PRESENT, $arm['verdict']);
        $this->assertSame('endpoint uuid', $arm['evidence']['matched_on']);
        $this->assertSame('ep-MINE', $arm['evidence']['endpoint_uuid']);
    }

    public function test_zorus_sweep_reads_past_the_first_page(): void
    {
        $this->enableZorus();
        $client = $this->client();
        $asset = $this->asset($client, ['zorus_endpoint_id' => 'ep-1']);

        // A full first page of strangers, the device on page two. A sweep that
        // stopped at page one would answer a confident, wrong `absent`.
        $pageOne = array_map(
            fn (int $i) => ['uuid' => "ep-filler-{$i}", 'name' => "FILLER-{$i}", 'customerUuid' => 'cust-uuid-2'],
            range(1, 500),
        );
        $zorus = Mockery::mock(ZorusClient::class);
        $zorus->shouldReceive('searchEndpoints')->with([], 1, 500)->once()->andReturn($pageOne);
        $zorus->shouldReceive('searchEndpoints')->with([], 2, 500)->once()
            ->andReturn([['uuid' => 'ep-1', 'name' => 'OFFBOARD-01', 'customerUuid' => self::ZORUS_CUSTOMER]]);
        $this->app->instance(ZorusClient::class, $zorus);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['zorus'];

        $this->assertSame(DeviceAbsenceVerifier::PRESENT, $arm['verdict']);
        $this->assertSame(
            'ep-1',
            $arm['evidence']['endpoint_uuid'],
            'the device only appears on page two — a sweep that stopped at page one would have answered a confident, wrong absent',
        );
    }

    public function test_zorus_page_ceiling_is_cannot_determine_not_absent(): void
    {
        $this->enableZorus();
        $client = $this->client();
        $asset = $this->asset($client, ['zorus_endpoint_id' => 'ep-1']);

        // Every page comes back full — the list never ends. A truncated sweep
        // cannot prove the device is missing from the part it did not read.
        $full = array_map(
            fn (int $i) => ['uuid' => "ep-filler-{$i}", 'name' => "FILLER-{$i}", 'customerUuid' => 'cust-uuid-2'],
            range(1, 500),
        );
        $zorus = Mockery::mock(ZorusClient::class);
        $zorus->shouldReceive('searchEndpoints')->andReturn($full);
        $this->app->instance(ZorusClient::class, $zorus);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['zorus'];

        $this->assertSame(DeviceAbsenceVerifier::CANNOT_DETERMINE, $arm['verdict']);
        $this->assertSame(40, $arm['evidence']['pages_capped_at']);
    }

    public function test_zorus_client_resolves_from_the_real_container(): void
    {
        // Every other Zorus test here binds a mock, which is exactly how the
        // missing UnifiClient binding stayed invisible (AppServiceProvider's own
        // note records it). This one resolves the REAL binding: ZorusClient's
        // constructor takes an unbound array, so without the psa-842a singleton
        // this throws before any HTTP could happen.
        $this->enableZorus();

        $resolved = app(ZorusClient::class);

        $this->assertInstanceOf(ZorusClient::class, $resolved);
        $this->assertSame($resolved, app(ZorusClient::class), 'bound as a singleton, matching every sibling client');
    }

    public function test_zorus_sweep_failure_is_cannot_determine_not_absent(): void
    {
        $this->enableZorus();
        $client = $this->client();
        $asset = $this->asset($client, ['zorus_endpoint_id' => 'ep-1']);

        $zorus = Mockery::mock(ZorusClient::class);
        $zorus->shouldReceive('searchEndpoints')->andThrow(new ZorusClientException('Zorus API error: 503'));
        $this->app->instance(ZorusClient::class, $zorus);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['zorus'];

        $this->assertSame(
            DeviceAbsenceVerifier::CANNOT_DETERMINE,
            $arm['verdict'],
            'a failed sweep must never read as proof the device is gone',
        );
    }

    public function test_zorus_is_cannot_determine_when_neither_the_client_nor_the_asset_carries_a_link(): void
    {
        $this->enableZorus();
        $client = Client::factory()->create(['zorus_customer_id' => null]);
        $asset = $this->asset($client, ['zorus_endpoint_id' => null]);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['zorus'];

        $this->assertSame(
            DeviceAbsenceVerifier::CANNOT_DETERMINE,
            $arm['verdict'],
            'Zorus is ON and we simply hold no link — the vendor was NOT asked, and that must stay in the roll-up instead of being dropped as not_applicable',
        );
        $this->assertSame('none', $arm['method']);
    }

    public function test_zorus_answers_live_for_a_uuid_linked_asset_whose_client_lost_its_mapping(): void
    {
        $this->enableZorus();
        // The client mapping was never backfilled, or was cleared when the client left
        // the Active stage — the very population this arm exists for. The uuid match is
        // customer-independent, so the vendor CAN still be asked about this device.
        $client = Client::factory()->create(['zorus_customer_id' => null]);
        $asset = $this->asset($client, ['zorus_endpoint_id' => 'ep-1']);

        $this->fakeZorus([['uuid' => 'ep-1', 'name' => 'SOMEONE-ELSES-HOST-01', 'customerUuid' => 'cust-uuid-2']]);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['zorus'];

        $this->assertSame(
            DeviceAbsenceVerifier::PRESENT,
            $arm['verdict'],
            'a still-enrolled uuid-linked device must not be dropped from the quorum because its client mapping is missing',
        );
        // The verdict crosses; the other customer's data does not.
        $this->assertNull($arm['evidence']['endpoint_name'], 'the matched row belongs to another customer — its hostname must not reach this client');
        $this->assertFalse($arm['evidence']['endpoint_is_under_this_client']);
        $this->assertArrayNotHasKey('customer_uuid', $arm['evidence']);
    }

    // ── 3. Tactical: 404 is the only absence signal ──────────────────────────

    public function test_tactical_404_is_absent(): void
    {
        $this->enableTactical();
        $asset = $this->asset($this->client(), [], agentId: 'agent-1');
        $this->fakeTacticalError($this->httpError(404));

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['tactical'];

        $this->assertSame(DeviceAbsenceVerifier::ABSENT, $arm['verdict']);
        $this->assertSame('live', $arm['method']);
        $this->assertSame(404, $arm['evidence']['http_status']);
    }

    public function test_tactical_403_is_cannot_determine_not_absent(): void
    {
        $this->enableTactical();
        $asset = $this->asset($this->client(), [], agentId: 'agent-1');
        $this->fakeTacticalError($this->httpError(403));

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['tactical'];

        $this->assertSame(
            DeviceAbsenceVerifier::CANNOT_DETERMINE,
            $arm['verdict'],
            'a 403 is an auth failure — possibly a compromised key — and must never be read as a missing device',
        );
        $this->assertSame(403, $arm['evidence']['http_status']);
    }

    public function test_tactical_transport_failure_is_cannot_determine(): void
    {
        $this->enableTactical();
        $asset = $this->asset($this->client(), [], agentId: 'agent-1');
        $this->fakeTacticalError(new TacticalClientException('Tactical API error (transport failure)', 0, null, null, null, true));

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['tactical'];

        $this->assertSame(DeviceAbsenceVerifier::CANNOT_DETERMINE, $arm['verdict']);
    }

    public function test_tactical_success_is_present(): void
    {
        $this->enableTactical();
        $asset = $this->asset($this->client(), [], agentId: 'agent-1');
        $this->fakeTacticalAgent(['hostname' => 'OFFBOARD-01', 'status' => 'offline']);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['tactical'];

        $this->assertSame(
            DeviceAbsenceVerifier::PRESENT,
            $arm['verdict'],
            'an agent that answers is present — "offline" is a device state, not an absence',
        );
    }

    public function test_tactical_is_queried_with_the_vendor_agent_id_not_the_local_foreign_key(): void
    {
        $this->enableTactical();
        $asset = $this->asset($this->client(), [], agentId: 'agent-1');

        // Two different ids exist on this asset and only one means anything to
        // the vendor. If the verifier ever sends the local one, Tactical answers
        // 404 for a device it has never heard of and the arm reports ABSENT — a
        // clean, confident, wrong teardown proof on every linked device.
        $this->assertNotSame(
            'agent-1',
            (string) $asset->tactical_asset_id,
            'the local fk must not coincidentally equal the vendor id, or this test proves nothing',
        );
        $this->assertSame('agent-1', $asset->tacticalAsset->agent_id);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')
            ->once()
            ->with('agent-1')
            ->andThrow($this->httpError(404));
        $this->app->instance(TacticalClient::class, $tactical);

        $arm = app(DeviceAbsenceVerifier::class)->verify($asset)['integrations']['tactical'];

        $this->assertSame(DeviceAbsenceVerifier::ABSENT, $arm['verdict']);
        $this->assertSame('agent-1', $arm['evidence']['agent_id'], 'the evidence must name the id actually asked about');
    }

    public function test_a_missing_local_link_is_cannot_determine_and_stays_in_the_roll_up(): void
    {
        $this->enableTactical();
        $this->enableZorus();
        $client = $this->client();
        // Enrolled in Tactical, but the tactical_assets row was never synced, so the PSA
        // holds no agent id and the vendor CANNOT be asked. Dropping that arm as
        // not_applicable would let Zorus's live absence carry the whole verdict, and the
        // operator would record a teardown with a live remote-shell agent still running.
        $asset = $this->asset($client, ['zorus_endpoint_id' => 'ep-1']);
        $this->fakeZorus([]);

        $result = app(DeviceAbsenceVerifier::class)->verify($asset);

        $this->assertSame(DeviceAbsenceVerifier::CANNOT_DETERMINE, $result['integrations']['tactical']['verdict']);
        $this->assertSame('none', $result['integrations']['tactical']['method']);
        $this->assertSame(DeviceAbsenceVerifier::ABSENT, $result['integrations']['zorus']['verdict']);
        $this->assertSame(
            DeviceAbsenceVerifier::CANNOT_DETERMINE,
            $result['overall']['verdict'],
            'a vendor we hold no link into was NOT asked — filtering it out of the quorum manufactures a teardown proof',
        );
        $this->assertContains('tactical', $result['overall']['undetermined']);
        $this->assertNotContains('tactical', $result['overall']['not_applicable']);
    }

    public function test_every_reading_discloses_the_integrations_it_could_not_sweep(): void
    {
        $this->enableTactical();
        $client = $this->client();
        $asset = $this->asset($client, [], agentId: 'agent-1');
        $this->fakeTacticalError($this->httpError(404));

        $result = app(DeviceAbsenceVerifier::class)->verify($asset);

        $this->assertSame(DeviceAbsenceVerifier::ABSENT, $result['overall']['verdict']);
        $this->assertContains(
            'ninja',
            $result['overall']['not_checked'],
            'this PSA runs device-bearing lanes this tool cannot ask — an absent that hides them carries the tool\'s authority for portals it never opened',
        );
        $this->assertStringContainsString('not_checked', $result['overall']['summary']);
    }

    // ── The tool wrapper ─────────────────────────────────────────────────────

    public function test_tool_refuses_an_ambiguous_hostname_instead_of_picking_a_row(): void
    {
        $this->enableTactical();
        $client = $this->client();
        // The offboarded row and its replacement share a hostname — the normal state of
        // this tool's population, because the include_inactive default deliberately keeps
        // deactivated rows in scope.
        $old = $this->asset($client, ['hostname' => 'OFFBOARD-01', 'is_active' => false]);
        $replacement = $this->asset($client, ['hostname' => 'OFFBOARD-01', 'is_active' => true, 'name' => 'OFFBOARD-01 (replacement)']);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('verify_device_absent', ['hostname' => 'OFFBOARD-01']);

        $this->assertArrayHasKey(
            'error',
            $result,
            'answering for an arbitrary one of the colliding rows is a confident verdict about the wrong device',
        );
        $this->assertStringContainsString('Ambiguous hostname', $result['error']);
        $this->assertEqualsCanonicalizing(
            [$old->id, $replacement->id],
            array_column($result['candidates'], 'id'),
            'the caller must be told which rows collided so it can re-ask with asset_id',
        );
    }

    public function test_tool_resolves_deactivated_devices_by_default(): void
    {
        $this->enableTactical();
        $client = $this->client();
        $asset = $this->asset($client, ['is_active' => false], agentId: 'agent-1');
        $this->fakeTacticalError($this->httpError(404));

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('verify_device_absent', ['asset_id' => $asset->id]);

        $this->assertArrayNotHasKey(
            'error',
            $result,
            'a teardown check whose subject is normally already deactivated must not be fenced out by the active-only default',
        );
        $this->assertFalse($result['asset']['is_active']);
    }

    public function test_tool_honours_an_explicit_include_inactive_false(): void
    {
        $this->enableTactical();
        $client = $this->client();
        $asset = $this->asset($client, ['is_active' => false], agentId: 'agent-1');

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('verify_device_absent', ['asset_id' => $asset->id, 'include_inactive' => false]);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_tool_refuses_an_asset_belonging_to_another_client(): void
    {
        $this->enableTactical();
        $mine = $this->client();
        $theirs = Client::factory()->create();
        $theirAsset = $this->asset($theirs, [], agentId: 'agent-1');

        $result = (new AssistantToolExecutor(clientId: $mine->id))
            ->execute('verify_device_absent', ['asset_id' => $theirAsset->id]);

        $this->assertArrayHasKey(
            'error',
            $result,
            'client_id scoping is the data boundary on this surface — the widened lifecycle default must not widen it',
        );
    }

    public function test_tool_is_advertised_with_its_four_verdicts_named(): void
    {
        $names = array_column(\App\Services\Assistant\AssistantToolDefinitions::getTools(hasClient: true), 'name');
        $this->assertContains('verify_device_absent', $names);

        $tool = collect(\App\Services\Assistant\AssistantToolDefinitions::getTools(hasClient: true))
            ->firstWhere('name', 'verify_device_absent');

        foreach (['present', 'absent', 'cannot_determine', 'not_applicable'] as $verdict) {
            $this->assertStringContainsString(
                $verdict,
                $tool['description'],
                "the description must name the {$verdict} verdict — a caller that does not know cannot_determine exists will read it as absent",
            );
        }
    }
}
