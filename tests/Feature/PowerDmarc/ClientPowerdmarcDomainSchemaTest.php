<?php

namespace Tests\Feature\PowerDmarc;

use App\Models\Client;
use App\Models\ClientPowerdmarcDomain;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #689 — the schema layer for PowerDMARC client->domain one-to-MANY.
 * Proves a client can hold several domains while a domain still maps to at
 * most one client. Unlike client_unifi_sites there is no legacy-column
 * backfill: this integration is greenfield, so the pivot is born as the only
 * source of truth.
 */
class ClientPowerdmarcDomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_can_map_to_multiple_powerdmarc_domains(): void
    {
        // A client with two mail domains = two pivot rows.
        $client = Client::factory()->create();
        $client->powerdmarcDomains()->create(['powerdmarc_domain_id' => 1, 'domain_name' => 'acme.com']);
        $client->powerdmarcDomains()->create(['powerdmarc_domain_id' => 2, 'domain_name' => 'acme.email']);

        $this->assertCount(2, $client->refresh()->powerdmarcDomains);
        $this->assertEqualsCanonicalizing(
            ['acme.com', 'acme.email'],
            $client->powerdmarcDomains->pluck('domain_name')->all(),
        );
        $this->assertTrue(ClientPowerdmarcDomain::first()->client->is($client));
        // The vendor id round-trips as an integer — the read grain the tools use.
        $this->assertSame(1, ClientPowerdmarcDomain::first()->powerdmarc_domain_id);
    }

    public function test_a_powerdmarc_domain_maps_to_at_most_one_client(): void
    {
        // Domain -> client stays <=1: the UNIQUE on powerdmarc_domain_id. Two
        // clients claiming one domain would make every mapped read ambiguous.
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        $a->powerdmarcDomains()->create(['powerdmarc_domain_id' => 7, 'domain_name' => 'shared.com']);

        $this->expectException(QueryException::class);
        $b->powerdmarcDomains()->create(['powerdmarc_domain_id' => 7, 'domain_name' => 'shared.com']);
    }

    public function test_deleting_a_client_cascades_its_domain_mappings(): void
    {
        // cascadeOnDelete on client_id: a hard-deleted client must not leave
        // orphan rows claiming its domains (which would block remapping them —
        // the UNIQUE would still hold the vendor id).
        $client = Client::factory()->create();
        $client->powerdmarcDomains()->create(['powerdmarc_domain_id' => 9, 'domain_name' => 'gone.com']);

        $client->forceDelete();

        $this->assertSame(0, ClientPowerdmarcDomain::where('powerdmarc_domain_id', 9)->count());
    }
}
