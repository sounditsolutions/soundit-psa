<?php

namespace Tests\Feature\Settings;

use App\Models\Client;
use App\Models\ClientPowerdmarcDomain;
use App\Models\Setting;
use App\Models\User;
use App\Services\PowerDmarc\PowerDmarcClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PowerDMARC Domain Mapping page (issue #689) — Settings → Integrations →
 * PowerDMARC → Domain Mapping.
 *
 * The invariant this page owns: a mapping is the PAIR powerdmarc_domain_id +
 * domain_name, stored in the client_powerdmarc_domains pivot (a client may map
 * to MANY domains), and the NAME is resolved server-side from the vendor's own
 * /api/v1/domains listing at save time — never from the submitted form. The
 * tool surface's `domain` resolution in PowerDmarcReadOnlyToolset trusts that
 * pair. A domain still maps to at most one client (the UNIQUE on the pivot's
 * powerdmarc_domain_id).
 *
 * Envelope and row shapes below mirror the vendor's committed example payload
 * (tests/Fixtures/powerdmarc/list_domains.json): a Laravel paginator
 * {data, links, meta} whose rows carry id / name / is_dmarc_record_correct /
 * is_setup_completed, paged by meta.last_page (not a cursor).
 */
class PowerDmarcDomainMappingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Setting::setEncrypted('powerdmarc_api_key', 'test-key');
    }

    /** @param array<int, Response> $queue */
    private function bindClientReturning(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $http = new GuzzleClient(['base_uri' => 'https://app.powerdmarc.com/', 'handler' => $stack]);

        $this->app->instance(PowerDmarcClient::class, new PowerDmarcClient(['api_key' => 'test-key'], $http));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function domainsPage(array $rows, int $currentPage = 1, int $lastPage = 1): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => $rows,
            'links' => ['first' => 'x', 'last' => 'x', 'prev' => null, 'next' => null],
            'meta' => ['current_page' => $currentPage, 'last_page' => $lastPage, 'per_page' => 100, 'total' => count($rows) * max(1, $lastPage)],
        ]));
    }

    private function domainRow(int $id, string $name, bool $dmarcCorrect = true, bool $setupCompleted = true): array
    {
        return ['id' => $id, 'name' => $name, 'is_dmarc_record_correct' => $dmarcCorrect, 'is_setup_completed' => $setupCompleted];
    }

    public function test_the_page_redirects_to_integrations_when_powerdmarc_is_not_configured(): void
    {
        Setting::where('key', 'powerdmarc_api_key')->delete();

        $this->actingAs($this->user)
            ->get(route('settings.powerdmarc-domains.index'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error');
    }

    public function test_the_page_lists_domains_across_pages_and_preselects_the_mapped_client(): void
    {
        $mapped = Client::factory()->create(['name' => 'Acme Co']);
        ClientPowerdmarcDomain::create(['client_id' => $mapped->id, 'powerdmarc_domain_id' => 1, 'domain_name' => 'acme.com']);

        // Two paginator pages: the page must walk meta.last_page, not render
        // page 1 only (a missing domain reads as "that domain is gone").
        $this->bindClientReturning([
            $this->domainsPage([$this->domainRow(1, 'acme.com')], 1, 2),
            $this->domainsPage([$this->domainRow(2, 'branch-mail.com', false, false)], 2, 2),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('settings.powerdmarc-domains.index'))
            ->assertOk();

        $response->assertSee('acme.com');
        $response->assertSee('branch-mail.com');
        // The vendor's numeric domain id is surfaced — it is the read grain the
        // tool surface uses.
        $response->assertSee('Domain ID: 1');
        $response->assertSee('Domain ID: 2');

        // The mapped client is preselected; the other domain reads unmapped.
        $response->assertSee('data-selected="'.$mapped->id.'"', false);
    }

    public function test_saving_writes_the_id_and_name_pair_resolved_server_side(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Co']);

        $this->bindClientReturning([
            $this->domainsPage([$this->domainRow(1, 'acme.com')]),
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.powerdmarc-domains.update'), [
                'mappings' => ['1' => (string) $client->id],
                // A tampered form must not be able to choose the stored name:
                // this field is not part of the contract and must be ignored.
                'names' => ['1' => 'attacker-chosen.example'],
            ])
            ->assertRedirect(route('settings.powerdmarc-domains.index'))
            ->assertSessionHas('success');

        // The name comes from the vendor listing, never the tampered form field.
        $this->assertDatabaseHas('client_powerdmarc_domains', [
            'client_id' => $client->id,
            'powerdmarc_domain_id' => 1,
            'domain_name' => 'acme.com',
        ]);
        $this->assertDatabaseMissing('client_powerdmarc_domains', ['domain_name' => 'attacker-chosen.example']);
    }

    public function test_saving_clears_mappings_deselected_in_the_form(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Co']);
        ClientPowerdmarcDomain::create(['client_id' => $client->id, 'powerdmarc_domain_id' => 1, 'domain_name' => 'acme.com']);

        $this->bindClientReturning([
            $this->domainsPage([$this->domainRow(1, 'acme.com')]),
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.powerdmarc-domains.update'), [
                'mappings' => ['1' => ''],
            ])
            ->assertRedirect(route('settings.powerdmarc-domains.index'));

        $this->assertDatabaseMissing('client_powerdmarc_domains', ['powerdmarc_domain_id' => 1]);
    }

    public function test_saving_maps_one_client_to_two_domains(): void
    {
        // A client with two mail domains maps to BOTH — the per-domain rows
        // already express it; the pivot stores both pairs.
        $client = Client::factory()->create(['name' => 'Smart-Service']);

        $this->bindClientReturning([
            $this->domainsPage([
                $this->domainRow(1, 'smart-service.com'),
                $this->domainRow(2, 'smart-service.email'),
            ]),
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.powerdmarc-domains.update'), [
                'mappings' => [
                    '1' => (string) $client->id,
                    '2' => (string) $client->id,
                ],
            ])
            ->assertRedirect(route('settings.powerdmarc-domains.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_powerdmarc_domains', [
            'client_id' => $client->id, 'powerdmarc_domain_id' => 1, 'domain_name' => 'smart-service.com',
        ]);
        $this->assertDatabaseHas('client_powerdmarc_domains', [
            'client_id' => $client->id, 'powerdmarc_domain_id' => 2, 'domain_name' => 'smart-service.email',
        ]);
        $this->assertSame(2, $client->powerdmarcDomains()->count());
    }

    public function test_remapping_a_domain_moves_it_rather_than_duplicating_it(): void
    {
        // The other direction of the invariant: a DOMAIN maps to at most one
        // client (the UNIQUE on the pivot). A fresh save re-asserts the row.
        $a = Client::factory()->create(['name' => 'Acme Co']);
        $b = Client::factory()->create(['name' => 'Beta LLC']);
        ClientPowerdmarcDomain::create(['client_id' => $a->id, 'powerdmarc_domain_id' => 1, 'domain_name' => 'acme.com']);

        $this->bindClientReturning([
            $this->domainsPage([$this->domainRow(1, 'acme.com')]),
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.powerdmarc-domains.update'), [
                'mappings' => ['1' => (string) $b->id],
            ])
            ->assertRedirect(route('settings.powerdmarc-domains.index'));

        $this->assertSame(1, ClientPowerdmarcDomain::where('powerdmarc_domain_id', 1)->count());
        $this->assertSame($b->id, ClientPowerdmarcDomain::where('powerdmarc_domain_id', 1)->value('client_id'));
    }

    public function test_saving_skips_domains_the_key_can_no_longer_see_and_says_so(): void
    {
        $kept = Client::factory()->create(['name' => 'Acme Co']);
        $ghosted = Client::factory()->create(['name' => 'Beta LLC']);

        $this->bindClientReturning([
            $this->domainsPage([$this->domainRow(1, 'acme.com')]),
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.powerdmarc-domains.update'), [
                'mappings' => [
                    '1' => (string) $kept->id,
                    '999' => (string) $ghosted->id,
                ],
            ])
            ->assertRedirect(route('settings.powerdmarc-domains.index'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '999'));

        $this->assertDatabaseHas('client_powerdmarc_domains', ['client_id' => $kept->id, 'powerdmarc_domain_id' => 1]);
        $this->assertSame(0, $ghosted->powerdmarcDomains()->count(), 'an unverifiable domain must not be written');
    }

    public function test_a_failed_domain_listing_aborts_the_save_without_wiping_mappings(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Co']);
        ClientPowerdmarcDomain::create(['client_id' => $client->id, 'powerdmarc_domain_id' => 1, 'domain_name' => 'acme.com']);

        $this->bindClientReturning([new Response(500, [], 'upstream boom')]);

        $this->actingAs($this->user)
            ->post(route('settings.powerdmarc-domains.update'), [
                'mappings' => ['1' => ''],
            ])
            ->assertRedirect(route('settings.powerdmarc-domains.index'))
            ->assertSessionHas('error');

        // The listing failed BEFORE the DB was touched, so the mapping survives.
        $this->assertDatabaseHas('client_powerdmarc_domains', [
            'client_id' => $client->id, 'powerdmarc_domain_id' => 1, 'domain_name' => 'acme.com',
        ]);
    }

    public function test_auto_match_pairs_domains_to_clients_by_name_and_never_steals_a_mapped_one(): void
    {
        // Case-insensitive on the vendor domain name against the client name.
        $hq = Client::factory()->create(['name' => 'acme.com']);
        $taken = Client::factory()->create(['name' => 'Gamma Corp']);
        ClientPowerdmarcDomain::create(['client_id' => $taken->id, 'powerdmarc_domain_id' => 2, 'domain_name' => 'branch-mail.com']);
        $unmatched = Client::factory()->create(['name' => 'branch-mail.com']);

        $this->bindClientReturning([
            $this->domainsPage([
                $this->domainRow(1, 'ACME.com'),
                // Domain 2 is already mapped (to Gamma Corp) — auto-match must
                // not steal it for the identically-named client.
                $this->domainRow(2, 'branch-mail.com'),
            ]),
        ]);

        // Auto-match is a state-changing write, so it rides a CSRF-protected POST.
        $this->actingAs($this->user)
            ->post(route('settings.powerdmarc-domains.auto-match'))
            ->assertRedirect(route('settings.powerdmarc-domains.index'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '1'));

        $this->assertDatabaseHas('client_powerdmarc_domains', [
            'client_id' => $hq->id, 'powerdmarc_domain_id' => 1, 'domain_name' => 'ACME.com',
        ]);

        // Existing mappings are never overwritten by auto-match.
        $this->assertDatabaseHas('client_powerdmarc_domains', [
            'client_id' => $taken->id, 'powerdmarc_domain_id' => 2,
        ]);
        $this->assertSame(0, $unmatched->powerdmarcDomains()->count(), 'an already-mapped domain must not be re-assigned');
    }

    public function test_the_page_fails_loud_when_the_listing_outlives_the_page_cap(): void
    {
        // 21 pages, meta.last_page always further out: the walk stops at the
        // safe cap and must SCREAM, not render the 20 pages it happened to
        // fetch as the whole account.
        $pages = [];
        for ($i = 1; $i <= 21; $i++) {
            $pages[] = $this->domainsPage([$this->domainRow($i, "domain-{$i}.com")], $i, 99);
        }
        $this->bindClientReturning($pages);

        $this->actingAs($this->user)
            ->get(route('settings.powerdmarc-domains.index'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'incomplete'));
    }
}
