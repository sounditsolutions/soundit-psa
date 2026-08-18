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
 * enrichInactiveAccounts() clears cipp_inactive client-wide before it knows the
 * payload said anything. CippClient::get() decodes an empty, null or unparseable
 * body to [] (json_decode(...) ?? []), so "[]" cannot be read as "nobody is
 * inactive" — it is exactly as likely to be a degraded upstream, and clearing on
 * it drops every inactive flag at the client.
 *
 * Red-check: revert the guard to `if (! is_array($inactive))` and
 * test_empty_inactive_payload_does_not_clear_the_flag fails — the flag is false.
 */
class CippInactiveEmptyPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_empty_inactive_payload_does_not_clear_the_flag(): void
    {
        $client = $this->activeClient();
        $person = $this->flaggedPerson($client, 'obj-empty');

        (new CippContactEnrichmentService($this->cippReturningInactive([])))
            ->enrichForClient($client, new SyncResult);

        $this->assertTrue(
            (bool) $person->fresh()->cipp_inactive,
            'An empty ListInactiveAccounts payload is unread, not "nobody is inactive" — it must not clear the flag.'
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

    private function cippReturningInactive(array $rows): CippClient
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
