<?php

namespace App\Services\PowerDmarc;

use App\Support\PowerDmarcConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * PowerDMARC platform API client (issue #689). READ-ONLY.
 *
 * SHAPE SOURCE — do not re-derive these field names from memory or from vendor docs
 * prose. Every endpoint, parameter and field below is taken from the vendor's own
 * machine-readable OpenAPI 3.0 spec for https://app.powerdmarc.com (a copy of which
 * is what tests/Fixtures/powerdmarc/*.json is authored from, using the spec's own
 * example values).
 *
 * SHAPE FACTS A NAIVE WRAPPER GETS WRONG:
 *  1. GET /api/v1/me returns the UserDataResource DIRECTLY — it is the one endpoint
 *     with NO {data: ...} envelope. isHealthy() therefore must not run it through
 *     the envelope helper.
 *  2. GET /api/v1/domains is a standard Laravel paginator envelope {data, links,
 *     meta}; paging is OFFSET-style via the `page` + `perPage` query params and
 *     meta.current_page / meta.last_page — not a cursor (unlike UniFi's nextToken).
 *  3. GET /api/v1/dns-timeline/dns-changes/{domainId} returns a DnsChangeCollection
 *     whose `data` is an OBJECT keyed by 'd-M-Y' date strings (e.g. "07-Feb-2024"),
 *     each mapping to a LIST of {times: {...}, recordInfo: {...}} entries — NOT a
 *     flat list. times.type is itself an ARRAY of record types.
 *  4. GET /api/v1/customers/domain-health/{domainId} wraps an ARRAY of
 *     GetDomainHealthResource in `data`, while GET /api/v1/dns-timeline/
 *     records-current-score/{domainId} wraps a SINGLE OBJECT (CurrentScoreResource).
 *     Their component keys also differ in casing: domain-health `statuses` uses
 *     PascalCase (Dmarc, MtaSts, Spf, TlsRpt, Dkim, Bimi) while current-score
 *     `details` uses lowercase-hyphenated (dmarc, spf, dkim, mta-sts, tls-rpt,
 *     bimi). Never "normalize" either.
 *  5. In current-score `details`, bimi.value is an OBJECT ({default: "v=BIMI1;..."}),
 *     not a string, and mta-sts.value is nullable.
 *  6. GET /api/v1/reports/aggregate/per-sending-source REQUIRES from, to and status
 *     (enum: compliant | failed | forwarded). Rows carry domain_id/domain_name only
 *     when a domain_id filter is passed (and `subdomain` only when it is NOT).
 *  7. Auth is the `Authorization: Bearer <api key>` header.
 *
 * DEGRADED READS FAIL LOUD. An envelope that arrives without its `data` key is drift
 * or an upstream fault, never "no results" — it throws rather than returning []. For
 * an email-security surface a confident empty answer is worse than an error
 * (CLAUDE.md).
 *
 * WRITES ARE OUT OF SCOPE. The spec also exposes hosted-record management (hosted
 * DMARC/MTA-STS/BIMI updates and similar POST endpoints). This client deliberately
 * does not implement any of them.
 */
class PowerDmarcClient
{
    /** Documented values for the aggregate per-sending-source `status` parameter. */
    public const AGGREGATE_STATUSES = ['compliant', 'failed', 'forwarded'];

    /**
     * Upper bound on a 429 backoff sleep. Retry-After is server-supplied (and the
     * base URL is an operator-settable override), while this client runs
     * synchronously inside web page loads, mapping saves and MCP tool calls — so
     * honoring an arbitrary header would pin a php-fpm worker and the session lock
     * for as long as the vendor asks.
     */
    private const MAX_RETRY_SLEEP_SECONDS = 5;

    private Client $http;

    /**
     * @param  array{api_key?: string, base_url?: string}  $config
     * @param  Client|null  $http  Injectable transport (test seam). When null the
     *                             default Bearer-auth Guzzle client is built from config.
     */
    public function __construct(
        private readonly array $config,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($this->config['base_url'] ?? PowerDmarcConfig::DEFAULT_BASE_URL, '/').'/',
            'timeout' => 30,
        ]);
    }

    public static function fromConfig(): self
    {
        return new self([
            'api_key' => PowerDmarcConfig::get('api_key') ?? '',
            'base_url' => PowerDmarcConfig::baseUrl(),
        ]);
    }

    /**
     * List domains registered in the PowerDMARC account, one paginator page.
     *
     * Rows: id, name, is_dmarc_record_correct, is_setup_completed. Envelope is a
     * Laravel paginator ({data, links, meta}); see shape fact 2.
     *
     * @return array<string, mixed>
     */
    public function listDomains(int $page = 1, int $perPage = 100): array
    {
        return $this->getEnveloped('api/v1/domains', [
            'perPage' => (string) $perPage,
            'page' => (string) $page,
        ]);
    }

    /**
     * Every domain row in the account, walking meta.last_page (offset paging —
     * shape fact 2). Built for OPERATOR surfaces (the Settings domain-mapping page)
     * that need the whole account picture at once. Hitting $maxPages with pages
     * still outstanding THROWS rather than returning a partial list — a mapping
     * screen missing domains it never fetched would read as "those domains are
     * gone".
     *
     * @return array<int, array<string, mixed>> raw domain rows exactly as the vendor shapes them
     */
    public function allDomains(int $maxPages = 20): array
    {
        $rows = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->listDomains(page: $page);

            foreach ((array) $response['data'] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $lastPage = $response['meta']['last_page'] ?? null;
            if (! is_int($lastPage) || $page >= $lastPage) {
                return $rows;
            }
        }

        throw new PowerDmarcClientException(
            "PowerDMARC returned more than {$maxPages} pages of domains and the scan was stopped at that safe limit, ".
            'so this answer would be incomplete. Refusing rather than reporting a partial result.'
        );
    }

    /**
     * PowerDMARC's hosted analysis of a domain's health: policy, percent/score/
     * scoreMark, suggestions, errors and per-record statuses (PascalCase keys —
     * shape fact 4). `data` wraps an ARRAY of GetDomainHealthResource.
     *
     * @return array<string, mixed>
     */
    public function getDomainHealth(int $domainId): array
    {
        return $this->getEnveloped('api/v1/customers/domain-health/'.$domainId);
    }

    /**
     * Current per-record score for a domain: percent/score/score_mark, error and
     * completed-action counts, and `details` keyed dmarc/spf/dkim/mta-sts/tls-rpt/
     * bimi (lowercase-hyphenated — shape fact 4), each {status, value}. `data`
     * wraps a SINGLE OBJECT (CurrentScoreResource), and bimi.value is an object
     * (shape fact 5).
     *
     * @return array<string, mixed>
     */
    public function getRecordsCurrentScore(int $domainId): array
    {
        return $this->getEnveloped('api/v1/dns-timeline/records-current-score/'.$domainId);
    }

    /**
     * DMARC aggregate (RUA) report rows grouped per sending source over a date
     * range. from/to/status are REQUIRED upstream (shape fact 6); the vendor
     * documents from/to as "Supported formats: YYYY-MM-DD, YYYY/MM/DD, MM-DD-YYYY,
     * ISO8601". Rows: org, volume, policy_dmarc_* / policy_spf_* / policy_dkim_*
     * counts and percentages (RuaReportRecordsResource).
     *
     * @return array<string, mixed>
     */
    public function getAggregatePerSendingSource(
        int $domainId,
        string $from,
        string $to,
        string $status,
        int $page = 1,
        int $perPage = 50,
    ): array {
        if (! in_array($status, self::AGGREGATE_STATUSES, true)) {
            throw new PowerDmarcClientException(
                "Unsupported aggregate status '{$status}'; expected one of: ".implode(', ', self::AGGREGATE_STATUSES)
            );
        }

        return $this->getEnveloped('api/v1/reports/aggregate/per-sending-source', [
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'domain_id' => (string) $domainId,
            'page' => (string) $page,
            'perPage' => (string) $perPage,
        ]);
    }

    /**
     * DNS change timeline for a domain. NOTE (shape fact 3): the returned `data` is
     * an OBJECT keyed by 'd-M-Y' date strings, each holding a LIST of
     * {times, recordInfo} entries — callers must not treat it as a flat list.
     * Optional filters: $type (record type, e.g. 'dkim'), $startDate/$endDate
     * (date, e.g. 2026-02-01); paging via meta.current_page / meta.last_page.
     *
     * @return array<string, mixed>
     */
    public function getDnsChanges(
        int $domainId,
        ?string $type = null,
        ?string $startDate = null,
        ?string $endDate = null,
        int $page = 1,
        int $perPage = 50,
    ): array {
        $params = [
            'page' => (string) $page,
            'perPage' => (string) $perPage,
        ];

        if ($type !== null && $type !== '') {
            $params['type'] = $type;
        }
        if ($startDate !== null && $startDate !== '') {
            $params['start_date'] = $startDate;
        }
        if ($endDate !== null && $endDate !== '') {
            $params['end_date'] = $endDate;
        }

        return $this->getEnveloped('api/v1/dns-timeline/dns-changes/'.$domainId, $params);
    }

    public function isHealthy(): bool
    {
        try {
            // /api/v1/me is the cheapest authenticated read — and the one endpoint
            // with NO data envelope (shape fact 1), so it must NOT use getEnveloped().
            $this->request('GET', 'api/v1/me');

            return true;
        } catch (PowerDmarcClientException) {
            return false;
        }
    }

    /**
     * GET an enveloped endpoint and hand back the decoded envelope.
     *
     * The `data` key is REQUIRED: its absence means the response drifted or the
     * upstream degraded, and returning [] there would read to an agent as a clean
     * "nothing found" on a security/health surface. Fail loud instead.
     *
     * @return array<string, mixed>
     */
    private function getEnveloped(string $endpoint, array $params = []): array
    {
        $response = $this->request('GET', $endpoint, ['query' => $params]);

        if (! array_key_exists('data', $response)) {
            throw new PowerDmarcClientException(
                "PowerDMARC API response for {$endpoint} carried no `data` envelope key ".
                '(keys: '.(implode(', ', array_keys($response)) ?: 'none').'). '.
                'Treating this as drift rather than an empty result.'
            );
        }

        return $response;
    }

    /**
     * Internal request method. The API key is sent as a Bearer token and is never logged.
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.($this->config['api_key'] ?? ''),
        ]);

        // A 429 bump is transient — honor Retry-After (falling back to exponential
        // backoff) and retry a bounded number of times rather than surfacing the
        // first one as an error.
        $maxAttempts = 3;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http->request($method, $endpoint, $options);
                break;
            } catch (GuzzleException $e) {
                $status = $e instanceof RequestException && $e->getResponse() !== null
                    ? $e->getResponse()->getStatusCode()
                    : 0;

                if ($status === 429 && $attempt < $maxAttempts) {
                    $retryAfter = 2 ** $attempt;
                    // Distinguish an absent Retry-After from a present "0" — PHP's ?:
                    // treats the string "0" as falsy, which would wrongly ignore a
                    // server asking us to retry immediately.
                    if ($e instanceof RequestException && $e->getResponse() !== null) {
                        $header = $e->getResponse()->getHeaderLine('Retry-After');
                        if (is_numeric($header)) {
                            $retryAfter = (int) $header;
                        }
                    }
                    // CLAMP the server-controlled wait (see MAX_RETRY_SLEEP_SECONDS):
                    // a rate-limited account must cost a bounded wait and then a loud
                    // error, never a request that hangs for minutes or hours.
                    $retryAfter = max(0, min($retryAfter, self::MAX_RETRY_SLEEP_SECONDS));
                    Log::info("[PowerDmarcClient] Rate limited on {$endpoint}, retrying in {$retryAfter}s");
                    if ($retryAfter > 0) {
                        sleep($retryAfter);
                    }

                    continue;
                }

                Log::error("[PowerDmarcClient] {$method} {$endpoint} failed: {$e->getMessage()}");
                throw new PowerDmarcClientException(
                    "PowerDMARC API error: {$e->getMessage()}", $e->getCode(), $e
                );
            }
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
