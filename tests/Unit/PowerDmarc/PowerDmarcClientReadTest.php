<?php

namespace Tests\Unit\PowerDmarc;

use App\Services\PowerDmarc\PowerDmarcClient;
use App\Services\PowerDmarc\PowerDmarcClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * PowerDmarcClient read-path behavior (issue #689). Pure unit tests over an
 * injected Guzzle client backed by a MockHandler — no network, no Laravel
 * container.
 *
 * EVERY fixture in tests/Fixtures/powerdmarc/ is copied VERBATIM from the
 * vendor's own example payloads in the PowerDMARC OpenAPI 3.0 spec for
 * https://app.powerdmarc.com. They are NOT authored from the shape this code
 * expects — that is the whole point (CLAUDE.md: "A mock you authored from the
 * code under test proves nothing").
 *
 * Focus: the API-shape facts a naive wrapper gets wrong (see the client's
 * docblock for the full list).
 *   1. /api/v1/me is the ONE endpoint with no {data} envelope.
 *   2. /api/v1/domains is a Laravel paginator — OFFSET paging via page/perPage
 *      and meta.last_page, not a cursor.
 *   3. dns-changes `data` is an OBJECT keyed by 'd-M-Y' dates, not a list.
 *   4. current-score `details` keys are lowercase-hyphenated (mta-sts, tls-rpt)
 *      and bimi.value is an OBJECT; mta-sts.value is nullable.
 *   5. per-sending-source REQUIRES from/to/status (compliant|failed|forwarded).
 *   6. Auth is the Authorization: Bearer header.
 *   7. 429 must back off and retry, honoring Retry-After — including "0".
 */
class PowerDmarcClientReadTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /** @param array<int, Response|\Throwable> $queue */
    private function clientReturning(array $queue): PowerDmarcClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $http = new GuzzleClient([
            'base_uri' => 'https://app.powerdmarc.com/',
            'handler' => $stack,
        ]);

        return new PowerDmarcClient(['api_key' => 'test-key'], $http);
    }

    private function fixture(string $name): Response
    {
        $path = base_path("tests/Fixtures/powerdmarc/{$name}.json");

        return new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents($path));
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    public function test_it_sends_the_api_key_as_a_bearer_authorization_header(): void
    {
        $client = $this->clientReturning([$this->fixture('list_domains')]);

        $client->listDomains();

        // Shape fact 7: securitySchemes bearer — Authorization: Bearer <key>.
        $this->assertSame('Bearer test-key', $this->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_list_domains_unwraps_the_paginator_envelope_and_keeps_the_meta(): void
    {
        $client = $this->clientReturning([$this->fixture('list_domains')]);

        $result = $client->listDomains();

        $this->assertSame('/api/v1/domains', $this->lastRequest()->getUri()->getPath());
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        $this->assertSame('1', $query['page']);
        $this->assertSame('100', $query['perPage']);

        $this->assertCount(1, $result['data']);
        $this->assertSame(1, $result['data'][0]['id']);
        $this->assertSame('google.com', $result['data'][0]['name']);
        $this->assertTrue($result['data'][0]['is_dmarc_record_correct']);
        $this->assertFalse($result['data'][0]['is_setup_completed']);
        // Paging state is OFFSET-style meta, not a cursor (shape fact 2).
        $this->assertSame(1, $result['meta']['current_page']);
        $this->assertSame(1, $result['meta']['last_page']);
    }

    public function test_current_score_details_keep_the_hyphenated_keys_and_the_bimi_object_value(): void
    {
        $client = $this->clientReturning([$this->fixture('records_current_score')]);

        $result = $client->getRecordsCurrentScore(1);

        $this->assertSame('/api/v1/dns-timeline/records-current-score/1', $this->lastRequest()->getUri()->getPath());

        // `data` wraps a SINGLE OBJECT, not a list (shape fact 4).
        $details = $result['data']['details'];
        // Lowercase-hyphenated component keys — the guard against anyone
        // "tidying" these into mtaSts / tlsRpt from memory.
        $this->assertArrayHasKey('mta-sts', $details);
        $this->assertArrayHasKey('tls-rpt', $details);
        $this->assertArrayNotHasKey('MtaSts', $details);
        // mta-sts.value is nullable; bimi.value is an OBJECT (shape fact 5).
        $this->assertNull($details['mta-sts']['value']);
        $this->assertSame('not_found', $details['mta-sts']['status']);
        $this->assertIsArray($details['bimi']['value']);
        $this->assertStringContainsString('v=BIMI1', $details['bimi']['value']['default']);
    }

    public function test_domain_health_wraps_an_array_and_cases_its_statuses_pascal(): void
    {
        $client = $this->clientReturning([$this->fixture('domain_health')]);

        $result = $client->getDomainHealth(1);

        $this->assertSame('/api/v1/customers/domain-health/1', $this->lastRequest()->getUri()->getPath());

        // `data` is an ARRAY of GetDomainHealthResource here (shape fact 4)...
        $resource = $result['data'][0];
        $this->assertSame('reject', $resource['policy']);
        // ...and the per-record statuses are PascalCase, unlike current-score.
        $this->assertSame('valid', $resource['statuses']['MtaSts']);
        $this->assertSame('notFound', $resource['statuses']['Bimi']);
        $this->assertArrayNotHasKey('mta-sts', $resource['statuses']);
    }

    public function test_dns_changes_data_is_an_object_keyed_by_date_not_a_list(): void
    {
        $client = $this->clientReturning([$this->fixture('dns_changes')]);

        $result = $client->getDnsChanges(1);

        $this->assertSame('/api/v1/dns-timeline/dns-changes/1', $this->lastRequest()->getUri()->getPath());

        // Shape fact 3: keys are 'd-M-Y' date strings, values are LISTS of
        // {times, recordInfo} entries. array_is_list would be false.
        $this->assertArrayHasKey('07-Feb-2024', $result['data']);
        $entry = $result['data']['07-Feb-2024'][0];
        $this->assertIsArray($entry['times']['type'], 'times.type is an ARRAY of record types');
        $this->assertSame(['dkim'], $entry['times']['type']);
        $this->assertSame('Selector value was added', $entry['times']['change']);
    }

    public function test_dns_changes_forwards_the_optional_filters_the_spec_names(): void
    {
        $client = $this->clientReturning([$this->fixture('dns_changes')]);

        $client->getDnsChanges(1, type: 'dkim', startDate: '2024-02-01', endDate: '2024-02-29', page: 2);

        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        $this->assertSame('dkim', $query['type']);
        $this->assertSame('2024-02-01', $query['start_date']);
        $this->assertSame('2024-02-29', $query['end_date']);
        $this->assertSame('2', $query['page']);
    }

    public function test_aggregate_reports_send_the_required_from_to_status_and_domain_filter(): void
    {
        $client = $this->clientReturning([$this->fixture('aggregate_per_sending_source')]);

        $result = $client->getAggregatePerSendingSource(1, '2026-08-01', '2026-08-27', 'failed');

        $this->assertSame('/api/v1/reports/aggregate/per-sending-source', $this->lastRequest()->getUri()->getPath());
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        // Shape fact 6: from/to/status are REQUIRED upstream.
        $this->assertSame('2026-08-01', $query['from']);
        $this->assertSame('2026-08-27', $query['to']);
        $this->assertSame('failed', $query['status']);
        $this->assertSame('1', $query['domain_id']);

        $row = $result['data'][0];
        $this->assertSame('unknown', $row['org']);
        $this->assertSame(776, $row['volume']);
        $this->assertSame(66.4, $row['policy_dkim_align_percentage']);
    }

    public function test_an_undocumented_aggregate_status_is_rejected_before_any_request_is_spent(): void
    {
        $client = $this->clientReturning([]);

        try {
            $client->getAggregatePerSendingSource(1, '2026-08-01', '2026-08-27', 'quarantined');
            $this->fail('expected a PowerDmarcClientException for an undocumented status');
        } catch (PowerDmarcClientException $e) {
            $this->assertStringContainsString('compliant', $e->getMessage());
        }

        $this->assertCount(0, $this->history, 'the rejection must happen before any upstream call');
    }

    public function test_it_backs_off_and_retries_on_a_429_honoring_a_zero_retry_after(): void
    {
        // Retry-After "0" is falsy under ?: — the client must still honor it as
        // "retry immediately" rather than falling back to exponential sleep.
        $client = $this->clientReturning([
            new Response(429, ['Retry-After' => '0']),
            $this->fixture('list_domains'),
        ]);

        $result = $client->listDomains();

        $this->assertCount(1, $result['data']);
        $this->assertCount(2, $this->history, 'expected the 429 to be retried, not surfaced');
    }

    public function test_it_throws_a_typed_exception_when_the_api_rejects_the_key(): void
    {
        $client = $this->clientReturning([new Response(401, [], '{"message":"Unauthenticated."}')]);

        $this->expectException(PowerDmarcClientException::class);

        $client->listDomains();
    }

    public function test_a_degraded_response_missing_the_data_envelope_is_not_silently_an_empty_list(): void
    {
        // CLAUDE.md rule: a degraded read must SCREAM, never return a clean empty
        // result. An envelope with no `data` key is drift, not "no domains".
        $client = $this->clientReturning([
            new Response(200, [], '{"message":"ok"}'),
        ]);

        $this->expectException(PowerDmarcClientException::class);

        $client->listDomains();
    }

    public function test_is_healthy_accepts_the_unenveloped_me_payload(): void
    {
        // Shape fact 1: /api/v1/me returns the UserDataResource DIRECTLY with no
        // {data} wrapper. Run through the envelope helper this healthy answer
        // would read as drift and throw.
        $client = $this->clientReturning([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 100, 'name' => 'John Doe', 'email' => 'user@example.com', 'role' => 'Admin',
            ])),
        ]);

        $this->assertTrue($client->isHealthy());
        $this->assertSame('/api/v1/me', $this->lastRequest()->getUri()->getPath());
    }

    public function test_is_healthy_is_false_when_the_key_is_rejected(): void
    {
        $client = $this->clientReturning([new Response(401, [], '{"message":"Unauthenticated."}')]);

        $this->assertFalse($client->isHealthy());
    }

    // ── allDomains: the operator-surface page walk ─────────────────────────────

    /** @param array<int, array<string, mixed>> $rows */
    private function domainsPage(array $rows, int $currentPage, int $lastPage): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => $rows,
            'links' => ['first' => 'x', 'last' => 'x', 'prev' => null, 'next' => null],
            'meta' => ['current_page' => $currentPage, 'last_page' => $lastPage, 'per_page' => 100, 'total' => count($rows) * $lastPage],
        ]));
    }

    private function domainRow(int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'is_dmarc_record_correct' => true, 'is_setup_completed' => true];
    }

    public function test_all_domains_walks_meta_last_page_and_concatenates_every_page(): void
    {
        $client = $this->clientReturning([
            $this->domainsPage([$this->domainRow(1, 'a.com'), $this->domainRow(2, 'b.com')], 1, 2),
            $this->domainsPage([$this->domainRow(3, 'c.com')], 2, 2),
        ]);

        $rows = $client->allDomains();

        $this->assertSame(['a.com', 'b.com', 'c.com'], array_column($rows, 'name'));

        // The second request must carry page=2 — the vendor pages by OFFSET
        // (page/perPage + meta.last_page), not a cursor (shape fact 2).
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        $this->assertSame('2', $query['page']);
        $this->assertCount(2, $this->history, 'the walk must stop at meta.last_page, not over-fetch');
    }

    public function test_all_domains_throws_on_cap_exhaustion_rather_than_returning_a_partial_list(): void
    {
        // A mapping screen missing domains it never fetched would read as "those
        // domains are gone" — cap exhaustion with pages outstanding must SCREAM.
        $client = $this->clientReturning([
            $this->domainsPage([$this->domainRow(1, 'a.com')], 1, 99),
            $this->domainsPage([$this->domainRow(2, 'b.com')], 2, 99),
            $this->domainsPage([$this->domainRow(3, 'c.com')], 3, 99),
        ]);

        $this->expectException(PowerDmarcClientException::class);
        $this->expectExceptionMessage('incomplete');

        $client->allDomains(maxPages: 2);
    }

    // ── allMsspDomains: the MSSP tenant-portal enumeration (#801) ──────────────
    //
    // Unlike everything above, this endpoint's shape is NOT in the OpenAPI spec
    // (the /api/v1/mssp/* family is undocumented). The fixture
    // mssp_accounts_domains.json is authored from a LIVE MEASUREMENT of the
    // tenant portal on 2026-08-28 under a real MSSP console PAT — see the
    // allMsspDomains() docblock. Facts a naive wrapper gets wrong: rows are
    // {domain_name, domain_id, account:{account_name, id}} (NOT the end-user
    // name/id fields), and paging follows links.next rather than page/perPage.

    private const MSSP_BASE = 'https://tenant.powerdmarc.com';

    /** @param array<int, Response|\Throwable> $queue */
    private function msspClientReturning(array $queue): PowerDmarcClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $http = new GuzzleClient([
            'base_uri' => 'https://app.powerdmarc.com/',
            'handler' => $stack,
        ]);

        return new PowerDmarcClient([
            'api_key' => 'test-key',
            'mssp_base_url' => self::MSSP_BASE,
        ], $http);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function msspPage(array $rows, ?string $next): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => $rows,
            'links' => ['first' => 'x', 'last' => 'x', 'prev' => null, 'next' => $next],
            'meta' => ['current_page' => 1, 'last_page' => $next === null ? 1 : 2, 'per_page' => 15, 'total' => count($rows)],
        ]));
    }

    private function msspRow(int $domainId, string $domainName): array
    {
        return [
            'domain_name' => $domainName,
            'domain_id' => $domainId,
            'account' => ['account_name' => ucfirst(strtok($domainName, '.')), 'id' => $domainId + 10],
        ];
    }

    public function test_all_mssp_domains_hits_the_mssp_portal_host_not_the_end_user_base_url(): void
    {
        $client = $this->msspClientReturning([$this->fixture('mssp_accounts_domains')]);

        $rows = $client->allMsspDomains();

        // The request must go to the tenant-portal host ABSOLUTELY, overriding
        // the end-user base_uri the transport was built with.
        $uri = $this->lastRequest()->getUri();
        $this->assertSame('tenant.powerdmarc.com', $uri->getHost());
        $this->assertSame('/api/v1/mssp/accounts/domains', $uri->getPath());
        $this->assertSame('Bearer test-key', $this->lastRequest()->getHeaderLine('Authorization'));

        // Rows come back verbatim — measured field names, not the end-user ones.
        $this->assertSame(['acme.com', 'branch-mail.com'], array_column($rows, 'domain_name'));
        $this->assertSame([101, 102], array_column($rows, 'domain_id'));
        $this->assertSame('Acme Co', $rows[0]['account']['account_name']);
    }

    public function test_all_mssp_domains_follows_links_next_and_preserves_its_query_string(): void
    {
        $client = $this->msspClientReturning([
            $this->msspPage([$this->msspRow(101, 'a.com')], self::MSSP_BASE.'/api/v1/mssp/accounts/domains?page=2'),
            $this->msspPage([$this->msspRow(102, 'b.com')], null),
        ]);

        $rows = $client->allMsspDomains();

        $this->assertSame(['a.com', 'b.com'], array_column($rows, 'domain_name'));
        $this->assertCount(2, $this->history, 'the walk must stop at links.next=null, not over-fetch');

        // The follow-up request is the links.next URL VERBATIM — in particular
        // its ?page= cursor must survive (an empty Guzzle `query` option would
        // strip it and re-fetch page 1 forever).
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        $this->assertSame('2', $query['page']);
    }

    public function test_all_mssp_domains_refuses_an_off_host_next_url_rather_than_resending_the_key(): void
    {
        // Every request signs with the Bearer key; a next URL pointing off the
        // configured MSSP host would exfiltrate the credential (#724/#763 class).
        $client = $this->msspClientReturning([
            $this->msspPage([$this->msspRow(101, 'a.com')], 'https://attacker.example.com/api/v1/mssp/accounts/domains?page=2'),
        ]);

        try {
            $client->allMsspDomains();
            $this->fail('an off-host links.next must throw, not be followed');
        } catch (PowerDmarcClientException $e) {
            $this->assertStringContainsString('different host', $e->getMessage());
        }

        $this->assertCount(1, $this->history, 'no request may be sent to the off-host URL');
    }

    public function test_all_mssp_domains_throws_before_any_request_when_the_portal_url_is_not_configured(): void
    {
        // A client built without mssp_base_url (a direct, non-MSSP account).
        $client = $this->clientReturning([]);

        try {
            $client->allMsspDomains();
            $this->fail('an unconfigured MSSP portal URL must throw');
        } catch (PowerDmarcClientException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }

        $this->assertCount(0, $this->history, 'no request may be spent without a configured portal URL');
    }

    public function test_all_mssp_domains_missing_data_envelope_is_not_silently_an_empty_list(): void
    {
        $client = $this->msspClientReturning([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['message' => 'ok'])),
        ]);

        $this->expectException(PowerDmarcClientException::class);
        $this->expectExceptionMessage('data');

        $client->allMsspDomains();
    }

    public function test_all_mssp_domains_throws_on_cap_exhaustion_rather_than_returning_a_partial_list(): void
    {
        $next = self::MSSP_BASE.'/api/v1/mssp/accounts/domains?page=2';
        $client = $this->msspClientReturning([
            $this->msspPage([$this->msspRow(101, 'a.com')], $next),
            $this->msspPage([$this->msspRow(102, 'b.com')], $next),
            $this->msspPage([$this->msspRow(103, 'c.com')], $next),
        ]);

        $this->expectException(PowerDmarcClientException::class);
        $this->expectExceptionMessage('incomplete');

        $client->allMsspDomains(maxPages: 2);
    }
}
