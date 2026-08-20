<?php

namespace Tests\Feature\Cipp;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\Person;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippContactEnrichmentService;
use App\Services\SyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * enrichInactiveAccounts() clears cipp_inactive client-wide before re-flagging, so
 * the two ways CIPP can answer "no rows" have to stay distinguishable.
 *
 * A payload that arrived and is empty means nobody is inactive and MUST clear stale
 * flags — that is the healthy steady state of a small tenant, and this pass is the
 * only writer of the flag, so skipping it would leave the flag stuck true forever.
 * An UNREAD answer (empty, unparseable or non-list body) reaches us as null from
 * CippClient::listInactiveAccounts() and must leave every flag alone.
 *
 * Red-check: widen the guard to `if ($inactive === null || $inactive === [])` and
 * test_empty_list_that_was_read_clears_a_stale_flag fails — the flag stays true.
 */
class CippInactiveEmptyPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_empty_list_that_was_read_clears_a_stale_flag(): void
    {
        $client = $this->activeClient();
        $person = $this->flaggedPerson($client, 'obj-empty');

        (new CippContactEnrichmentService($this->cippReturningInactive([])))
            ->enrichForClient($client, new SyncResult);

        $this->assertFalse(
            (bool) $person->fresh()->cipp_inactive,
            'A read payload naming nobody means nobody is inactive — the stale flag must clear.'
        );
    }

    public function test_unread_payload_does_not_clear_the_flag(): void
    {
        $client = $this->activeClient();
        $person = $this->flaggedPerson($client, 'obj-unread');

        (new CippContactEnrichmentService($this->cippReturningInactive(null)))
            ->enrichForClient($client, new SyncResult);

        $this->assertTrue(
            (bool) $person->fresh()->cipp_inactive,
            'An unread ListInactiveAccounts response is not "nobody is inactive" — it must not clear the flag.'
        );
    }

    public function test_populated_payload_still_clears_a_person_who_dropped_off_it(): void
    {
        $client = $this->activeClient();
        $reactivated = $this->flaggedPerson($client, 'obj-gone');
        $stillInactive = $this->flaggedPerson($client, 'obj-listed');

        (new CippContactEnrichmentService($this->cippReturningInactive([
            ['azureAdUserId' => 'obj-listed', 'lastSignInDateTime' => '2026-01-02T03:04:05Z'],
        ])))->enrichForClient($client, new SyncResult);

        $this->assertFalse(
            (bool) $reactivated->fresh()->cipp_inactive,
            'A person absent from a payload that WAS read must still drop the flag.'
        );
        $this->assertTrue((bool) $stillInactive->fresh()->cipp_inactive);
        $this->assertNotNull($stillInactive->fresh()->last_sign_in_at);
    }

    public function test_populated_payload_naming_nobody_we_know_still_clears(): void
    {
        // The distinction is read-vs-unread, not matched-vs-unmatched: a payload
        // that arrived and names only strangers is a real "nobody here is inactive".
        $client = $this->activeClient();
        $person = $this->flaggedPerson($client, 'obj-ours');

        (new CippContactEnrichmentService($this->cippReturningInactive([
            ['azureAdUserId' => 'obj-someone-else'],
        ])))->enrichForClient($client, new SyncResult);

        $this->assertFalse((bool) $person->fresh()->cipp_inactive);
    }

    private function cippReturningInactive(?array $rows): CippClient
    {
        $cipp = Mockery::mock(CippClient::class)->shouldIgnoreMissing();
        $cipp->shouldReceive('listInactiveAccounts')->andReturn($rows);

        return $cipp;
    }

    private function activeClient(): Client
    {
        return Client::factory()->create([
            'cipp_tenant_domain' => 'contoso.onmicrosoft.com',
            'stage' => ClientStage::Active,
            'is_active' => true,
        ]);
    }

    private function flaggedPerson(Client $client, string $objectId): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'email' => 'p'.uniqid().'@example.test',
            'is_active' => true,
            'cipp_user_id' => $objectId,
            'cipp_inactive' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
