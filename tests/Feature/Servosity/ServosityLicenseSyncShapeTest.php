<?php

namespace Tests\Feature\Servosity;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Setting;
use App\Services\Servosity\ServosityClient;
use App\Services\Servosity\ServosityLicenseSyncService;
use App\Services\Servosity\ServosityReadOnlyToolset;
use App\Services\SyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The license-sync half of the Servosity shape-proof contract (psa-z30dv.15;
 * seam 4 in ServosityShapes): getCompanies() feeds quantities, synced_at
 * stamps and deactivateMissingClients() zeroing, so it must prove the
 * documented producer shape (official OpenAPI: the DRF envelope REQUIRES
 * count + results; definitions.CompanySummaryNg REQUIRES name +
 * account_counts + issue_counts as integer maps) BEFORE the sync can act.
 * Under the legacy assoc decode, results:{} collapsed into an empty PHP
 * array, the sync read it as a successful full fetch of zero companies,
 * deactivateMissingClients() zeroed every mapped client's licenses with a
 * FRESH synced_at — and the read tool then served that fabricated zero as
 * fresh truth.
 *
 * Every test drives RAW JSON bodies through a real ServosityClient over a
 * Guzzle MockHandler (the psa-7lgo fixture rule: the exact production
 * decode, not a pre-decoded tree), through the real sync service, and — for
 * the kill-shot case — on into the real read tool.
 */
class ServosityLicenseSyncShapeTest extends TestCase
{
    use RefreshDatabase;

    private const WIRE_COMPANY_42 = '{"id":42,"name":"Company 42","account_counts":{"DRS":2},"issue_counts":{"Backup":0}}';

    /**
     * Bind a real ServosityClient whose HTTP layer replays the given raw
     * response bodies in request order.
     */
    private function bindRealClientReplaying(string ...$rawBodies): void
    {
        $queue = array_map(
            fn (string $body) => new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $body),
            $rawBodies,
        );

        $this->app->instance(ServosityClient::class, new ServosityClient([
            'api_token' => 'fixture-token',
            'base_url' => 'https://api.servosity.example',
            'handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($queue)),
        ]));
    }

    private function runSync(): SyncResult
    {
        return (new ServosityLicenseSyncService(app(ServosityClient::class)))->syncLicenses();
    }

    private function mappedClient(string $name, int $companyId): Client
    {
        return Client::factory()->create(['name' => $name, 'servosity_company_id' => $companyId]);
    }

    private function servosityLicense(Client $client, string $skuId, int $quantity, \Carbon\Carbon $syncedAt): License
    {
        $type = LicenseType::updateOrCreate(
            ['vendor' => 'servosity', 'vendor_sku_id' => $skuId],
            ['name' => "Servosity {$skuId}", 'is_active' => true],
        );

        return License::create([
            'license_type_id' => $type->id,
            'client_id' => $client->id,
            'vendor_ref' => (string) $client->servosity_company_id,
            'quantity' => $quantity,
            'status' => 'active',
            'synced_at' => $syncedAt,
        ]);
    }

    public function test_wire_empty_object_results_aborts_the_sync_and_the_read_tool_serves_no_fresh_zero(): void
    {
        // THE kill-shot (psa-z30dv.15), end to end: results:{} is drift, not
        // an empty company list. The sync must abort before any write — no
        // deactivation, no zeroing, no fresh synced_at — and the read tool
        // must then serve the OLD counts with their honest staleness.
        Setting::setEncrypted('servosity_api_token', 't');
        Setting::setValue('servosity_enabled', '1');
        $client = $this->mappedClient('Acme', 42);
        $oldStamp = now()->subDays(10)->startOfSecond();
        $license = $this->servosityLicense($client, 'dr_server', 5, $oldStamp);

        $this->bindRealClientReplaying(
            '{"count":0,"next":null,"previous":null,"results":{}}',              // sync fetch: drift, not "no companies"
            '{"count":1,"next":null,"previous":null,"results":['.self::WIRE_COMPANY_42.']}', // read leg: live summary
            '{"count":0,"next":null,"previous":null,"results":[]}',              // read leg: live DR list
        );

        $result = $this->runSync();

        $this->assertGreaterThan(0, $result->errors, 'the abort must be loud, not a clean no-op');
        $this->assertSame(0, $result->deactivated, 'an unproven fetch must never deactivate');
        $this->assertSame(0, $result->created + $result->updated);
        $license->refresh();
        $this->assertSame(5, $license->quantity, 'quantities must survive an unproven fetch');
        $this->assertSame('active', $license->status);
        $this->assertTrue($oldStamp->equalTo($license->synced_at), 'an unproven fetch must not stamp synced_at');

        $read = app(ServosityReadOnlyToolset::class)->execute('servosity_get_backup_posture', [], $client->id);

        $row = collect($read['synced_account_counts'])->firstWhere('vendor_sku_id', 'dr_server');
        $this->assertSame(5, $row['quantity'], 'the read surface serves the old truth, not a fabricated zero');
        $this->assertSame($oldStamp->toIso8601ZuluString(), $row['synced_at']);
        $this->assertTrue($row['stale'], 'and it says HOW old that truth is');
        $this->assertTrue($read['data_stale']);
        $this->assertSame($oldStamp->toIso8601ZuluString(), $read['data_as_of']);
    }

    public function test_wire_valid_empty_results_is_the_verified_full_list_empty_and_may_deactivate(): void
    {
        // The explicit counter-case: results:[] is the DOCUMENTED empty — a
        // well-formed answer that this account has no companies. Only then is
        // deactivating mapped clients' licenses legitimate.
        $client = $this->mappedClient('Acme', 42);
        $license = $this->servosityLicense($client, 'dr_server', 5, now()->subDay());
        $this->bindRealClientReplaying('{"count":0,"next":null,"previous":null,"results":[]}');

        $result = $this->runSync();

        $this->assertSame(0, $result->errors);
        $this->assertSame(1, $result->deactivated);
        $license->refresh();
        $this->assertSame(0, $license->quantity);
        $this->assertSame('suspended', $license->status);
    }

    public function test_wire_invalid_json_aborts_the_sync_without_zeroing_or_stamping(): void
    {
        $client = $this->mappedClient('Acme', 42);
        $oldStamp = now()->subDays(3)->startOfSecond();
        $license = $this->servosityLicense($client, 'dr_server', 5, $oldStamp);
        $this->bindRealClientReplaying('this is not JSON {');

        $result = $this->runSync();

        $this->assertGreaterThan(0, $result->errors);
        $this->assertSame(0, $result->deactivated);
        $license->refresh();
        $this->assertSame(5, $license->quantity);
        $this->assertTrue($oldStamp->equalTo($license->synced_at));
    }

    /**
     * Every drifted envelope/row shape must abort the whole sync — a strict
     * CompanySummaryNg proof, not a per-field shrug. account_counts:[] is
     * the row-level identity case: a JSON ARRAY where the documented shape
     * is an object of integer counts used to collapse into a clean empty
     * map (quantity 0 everywhere) under the assoc decode.
     *
     * @return array<string, array{0: string}>
     */
    public static function driftedCompaniesBodyProvider(): array
    {
        return [
            'top-level JSON array' => ['[]'],
            'results as empty JSON object' => ['{"count":0,"next":null,"previous":null,"results":{}}'],
            'results missing' => ['{"count":1,"next":null,"previous":null}'],
            'count as string' => ['{"count":"1","next":null,"previous":null,"results":[]}'],
            'row not an object' => ['{"count":1,"next":null,"previous":null,"results":["garbage"]}'],
            'row without an id' => ['{"count":1,"next":null,"previous":null,"results":[{"name":"Company 42","account_counts":{},"issue_counts":{}}]}'],
            'row with a string id' => ['{"count":1,"next":null,"previous":null,"results":[{"id":"42","name":"Company 42","account_counts":{},"issue_counts":{}}]}'],
            'row without a name' => ['{"count":1,"next":null,"previous":null,"results":[{"id":42,"account_counts":{},"issue_counts":{}}]}'],
            'account_counts as JSON array' => ['{"count":1,"next":null,"previous":null,"results":[{"id":42,"name":"Company 42","account_counts":[],"issue_counts":{}}]}'],
            'account_counts with a string value' => ['{"count":1,"next":null,"previous":null,"results":[{"id":42,"name":"Company 42","account_counts":{"DRS":"2"},"issue_counts":{}}]}'],
            'issue_counts as JSON array' => ['{"count":1,"next":null,"previous":null,"results":[{"id":42,"name":"Company 42","account_counts":{},"issue_counts":[]}]}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driftedCompaniesBodyProvider')]
    public function test_wire_every_drifted_companies_shape_aborts_the_sync(string $rawBody): void
    {
        $client = $this->mappedClient('Acme', 42);
        $oldStamp = now()->subDays(3)->startOfSecond();
        $license = $this->servosityLicense($client, 'dr_server', 5, $oldStamp);
        $this->bindRealClientReplaying($rawBody);

        $result = $this->runSync();

        $this->assertGreaterThan(0, $result->errors, 'drift must abort loudly');
        $this->assertSame(0, $result->deactivated);
        $this->assertSame(0, $result->created + $result->updated);
        $license->refresh();
        $this->assertSame(5, $license->quantity);
        $this->assertTrue($oldStamp->equalTo($license->synced_at));
    }

    public function test_wire_a_wrong_typed_next_url_aborts_rather_than_truncating(): void
    {
        // `next` is documented as a URI string or null. Reading anything else
        // as "last page" would silently truncate the walk — and every mapped
        // client on the unseen pages would be "missing" and get zeroed. The
        // abort must also be all-or-nothing: page 1's perfectly valid row
        // must not have been upserted either.
        $client = $this->mappedClient('Acme', 42);
        $oldStamp = now()->subDays(3)->startOfSecond();
        $license = $this->servosityLicense($client, 'dr_server', 5, $oldStamp);
        $this->bindRealClientReplaying(
            '{"count":200,"next":123,"previous":null,"results":['.self::WIRE_COMPANY_42.']}',
        );

        $result = $this->runSync();

        $this->assertGreaterThan(0, $result->errors);
        $this->assertSame(0, $result->deactivated);
        $this->assertSame(0, $result->created + $result->updated, 'all-or-nothing: even the valid page-1 row must not land');
        $license->refresh();
        $this->assertSame(5, $license->quantity);
        $this->assertTrue($oldStamp->equalTo($license->synced_at));
    }

    public function test_wire_a_proven_multi_page_walk_syncs_every_page(): void
    {
        // The strict rewrite must not break the legitimate path: two proven
        // pages land licenses for clients on both, with fresh stamps.
        $acme = $this->mappedClient('Acme', 42);
        $rival = $this->mappedClient('Beta LLC', 77);
        $this->bindRealClientReplaying(
            '{"count":2,"next":"https://api.servosity.example/api/v1/companies/summary-ng/?page=2","previous":null,"results":['.self::WIRE_COMPANY_42.']}',
            '{"count":2,"next":null,"previous":null,"results":[{"id":77,"name":"Company 77","account_counts":{"Pro":9},"issue_counts":{}}]}',
        );

        $result = $this->runSync();

        $this->assertSame(0, $result->errors);
        $this->assertSame(2, $result->created);
        $drServer = License::whereHas('licenseType', fn ($q) => $q->where('vendor_sku_id', 'dr_server'))
            ->where('client_id', $acme->id)->first();
        $pro = License::whereHas('licenseType', fn ($q) => $q->where('vendor_sku_id', 'pro'))
            ->where('client_id', $rival->id)->first();
        $this->assertSame(2, $drServer->quantity);
        $this->assertSame(9, $pro->quantity);
        $this->assertNotNull($drServer->synced_at, 'a PROVEN fetch does stamp');
    }
}
