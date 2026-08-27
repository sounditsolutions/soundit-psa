<?php

namespace App\Services\PowerDmarc;

use App\Models\Client;
use App\Models\ClientPowerdmarcDomain;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\PowerDmarcConfig;
use Illuminate\Support\Facades\Log;

/**
 * PowerDMARC read-only email-authentication tools for the staff MCP surface
 * (issue #689).
 *
 * SHAPE SOURCE: every field name below comes from the vendor's own OpenAPI 3.0
 * spec for https://app.powerdmarc.com — never from docs prose or inference. See
 * PowerDmarcClient's docblock for the shape facts that matter and
 * tests/Fixtures/powerdmarc/*.json for the vendor payloads the tests assert
 * against. The one worth repeating here: the dns-changes `data` is an OBJECT
 * keyed by 'd-M-Y' dates (not a list) — powerdmarc_get_dns_timeline flattens it.
 *
 * PERSPECTIVE: these tools answer with PowerDMARC's HOSTED-SIDE view — what the
 * PowerDMARC platform knows and has analyzed about a domain (its scores, its
 * parsed RUA reports, its observed DNS change history). That is deliberately
 * distinct from dns_email_health, which performs a live public-DNS lookup
 * independent of any vendor; the tool descriptions steer the agent accordingly.
 *
 * DATA-BOUNDARY RULE (mirrors UnifiReadOnlyToolset — one PowerDMARC account
 * covers domains belonging to many clients):
 *  - Domain METADATA is account-wide and annotated with its mapped PSA client
 *    (or null), so a human can discover what still needs mapping. Metadata ONLY.
 *  - Everything else (status, aggregate reports, DNS timeline) is
 *    MAPPED-DOMAINS-ONLY, resolved from the caller client's own
 *    client_powerdmarc_domains rows. A `domain` argument must match one of THAT
 *    client's mapped domains — there is never an account-wide fallback, so a
 *    tool call can never read another client's domain by naming it.
 *
 * READ-ONLY. The spec also exposes hosted-record management (hosted DMARC /
 * MTA-STS / BIMI updates). It is deliberately absent here and from
 * PowerDmarcClient; hosted-side writes are a separate decision with their own
 * review, not a detail of a visibility PR.
 */
class PowerDmarcReadOnlyToolset
{
    private const GENERAL_TOOL_NAMES = [
        'powerdmarc_list_domains',
    ];

    private const CLIENT_TOOL_NAMES = [
        'powerdmarc_get_domain_status',
        'powerdmarc_get_aggregate_summary',
        'powerdmarc_get_dns_timeline',
    ];

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return array_merge(self::generalDefinitions(), self::clientDefinitions());
    }

    /** @return array<int, array<string, mixed>> */
    public static function generalDefinitions(): array
    {
        return [
            [
                'name' => 'powerdmarc_list_domains',
                'description' => 'List domains registered in the PowerDMARC account, each annotated with its mapped PSA client (or null when unmapped). Use this to resolve a PSA client to its PowerDMARC domain(s) — a client with several mail domains maps to several — and to discover domains that still need mapping. Returns domain metadata only (name, DMARC-record-correct and setup-completed flags); use powerdmarc_get_domain_status for a mapped client.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'page' => ['type' => 'integer', 'description' => 'Page number of the domain listing (default 1).'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function clientDefinitions(): array
    {
        return [
            [
                'name' => 'powerdmarc_get_domain_status',
                'description' => "PowerDMARC's hosted-side view of a mapped client's email-authentication posture for one domain: per-record status and published value for DMARC, SPF, DKIM, MTA-STS, TLS-RPT and BIMI, plus PowerDMARC's domain health score, policy, errors and suggestions. This is what the PowerDMARC platform knows about the domain (its own hosted analysis) — for a live public-DNS lookup independent of PowerDMARC use dns_email_health instead. The client must be mapped to the domain in Settings → Integrations → PowerDMARC → Domain Mapping.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to at least one PowerDMARC domain.'],
                        'domain' => ['type' => 'string', 'description' => "Domain name, e.g. 'example.com'. Optional when the client maps to exactly one domain; must match one of the client's mapped domains otherwise."],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name' => 'powerdmarc_get_aggregate_summary',
                'description' => "DMARC aggregate (RUA) report volumes per sending source for a mapped client's domain over a date range, as parsed and stored by PowerDMARC: sending organization, message volume, DMARC pass/fail counts and percentages, and SPF/DKIM alignment percentages. Use this to answer 'who is sending as this domain and is it passing DMARC'. Dates are YYYY-MM-DD. status is one of compliant, failed or forwarded; omit it to get all three grouped by status.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to at least one PowerDMARC domain.'],
                        'domain' => ['type' => 'string', 'description' => "Domain name, e.g. 'example.com'. Optional when the client maps to exactly one domain; must match one of the client's mapped domains otherwise."],
                        'from' => ['type' => 'string', 'description' => 'Start date, YYYY-MM-DD.'],
                        'to' => ['type' => 'string', 'description' => 'End date, YYYY-MM-DD.'],
                        'status' => ['type' => 'string', 'description' => "Optional compliance filter: 'compliant', 'failed' or 'forwarded'. Omit to get all three grouped by status."],
                    ],
                    'required' => ['client_id', 'from', 'to'],
                ],
            ],
            [
                'name' => 'powerdmarc_get_dns_timeline',
                'description' => "History of DNS record changes PowerDMARC observed for a mapped client's domain — what changed, when, the old and new record values, and the domain score after the change. Use this to answer 'did an SPF/DKIM/DMARC record change, and when' (e.g. after a sudden authentication failure). This is PowerDMARC's hosted change history, not a live DNS lookup — for current public DNS use dns_email_health.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to at least one PowerDMARC domain.'],
                        'domain' => ['type' => 'string', 'description' => "Domain name, e.g. 'example.com'. Optional when the client maps to exactly one domain; must match one of the client's mapped domains otherwise."],
                        'type' => ['type' => 'string', 'description' => "Optional record-type filter, e.g. 'dmarc', 'spf' or 'dkim'."],
                        'start_date' => ['type' => 'string', 'description' => 'Optional start date, YYYY-MM-DD.'],
                        'end_date' => ['type' => 'string', 'description' => 'Optional end date, YYYY-MM-DD.'],
                        'page' => ['type' => 'integer', 'description' => 'Page number (default 1).'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
        ];
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::GENERAL_TOOL_NAMES, true)
            || in_array($toolName, self::CLIENT_TOOL_NAMES, true);
    }

    public static function requiresClient(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_TOOL_NAMES, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $input, ?int $clientId = null): array
    {
        // OFF=OFF: the master switch withdraws the capability, not just the syncs.
        if (! PowerDmarcConfig::isAvailable()) {
            return ['error' => 'PowerDMARC is not available in this deployment — it is either switched off or has no API key configured.'];
        }

        return match ($toolName) {
            'powerdmarc_list_domains' => $this->listDomains($input),
            'powerdmarc_get_domain_status' => $this->getDomainStatus($input, $clientId),
            'powerdmarc_get_aggregate_summary' => $this->getAggregateSummary($input, $clientId),
            'powerdmarc_get_dns_timeline' => $this->getDnsTimeline($input, $clientId),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    // ── mapping helper (account-wide METADATA only) ────────────────────────────

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listDomains(array $input): array
    {
        $page = $this->positiveInt($input['page'] ?? null) ?? 1;

        try {
            $response = $this->client()->listDomains(page: $page);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        $mapped = $this->mappedClientsByDomainId();

        $domains = [];
        foreach ($this->rows($response) as $row) {
            $domainId = $this->positiveInt($row['id'] ?? null);
            if ($domainId === null) {
                continue;
            }
            $client = $mapped->get($domainId);

            // METADATA ONLY — no scores, reports or record values ride along on
            // the mapping helper; an unmapped row belongs to a domain we have not
            // associated with a client.
            $domains[] = [
                'domain_id' => $domainId,
                'name' => $this->textSanitizer->sanitizeNullable('PowerDMARC domain name', $row['name'] ?? null, 253),
                'is_dmarc_record_correct' => is_bool($row['is_dmarc_record_correct'] ?? null) ? $row['is_dmarc_record_correct'] : null,
                'is_setup_completed' => is_bool($row['is_setup_completed'] ?? null) ? $row['is_setup_completed'] : null,
                'psa_client_id' => $client?->id,
                'psa_client_name' => $client?->name,
            ];
        }

        return [
            'count' => count($domains),
            'domains' => $domains,
            'page' => $this->pageMeta($response),
        ];
    }

    // ── mapped-domain reads ────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getDomainStatus(array $input, ?int $clientId): array
    {
        $resolved = $this->resolveMappedDomain($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }
        [$client, $mapping] = $resolved;

        $out = [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
            'domain' => $mapping->domain_name,
            'powerdmarc_domain_id' => $mapping->powerdmarc_domain_id,
        ];

        // Two independent upstream reads. A failure of one is reported INLINE on
        // its section rather than sinking the whole read — but never silently:
        // an error key replaces the data, so a gap can never look like a clean
        // "all healthy".
        try {
            $score = $this->client()->getRecordsCurrentScore($mapping->powerdmarc_domain_id);
            $out['records'] = $this->projectCurrentScore($score);
        } catch (\Throwable $e) {
            $out['records'] = $this->apiError($e);
        }

        try {
            $health = $this->client()->getDomainHealth($mapping->powerdmarc_domain_id);
            $out['health'] = $this->projectDomainHealth($health);
        } catch (\Throwable $e) {
            $out['health'] = $this->apiError($e);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getAggregateSummary(array $input, ?int $clientId): array
    {
        $resolved = $this->resolveMappedDomain($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }
        [$client, $mapping] = $resolved;

        $from = trim((string) ($input['from'] ?? ''));
        $to = trim((string) ($input['to'] ?? ''));
        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if (! $this->isYmdDate($value)) {
                return ['error' => "{$field} must be a date in YYYY-MM-DD form, e.g. 2026-08-01. Received: ".mb_substr($value, 0, 40)];
            }
        }

        $status = trim((string) ($input['status'] ?? ''));
        if ($status !== '' && ! in_array($status, PowerDmarcClient::AGGREGATE_STATUSES, true)) {
            return ['error' => "status '{$status}' is not one PowerDMARC reports. Use one of: ".implode(', ', PowerDmarcClient::AGGREGATE_STATUSES).', or omit status to get all three grouped.'];
        }

        $statuses = $status !== '' ? [$status] : PowerDmarcClient::AGGREGATE_STATUSES;

        $byStatus = [];
        try {
            foreach ($statuses as $wanted) {
                $response = $this->client()->getAggregatePerSendingSource(
                    $mapping->powerdmarc_domain_id, $from, $to, $wanted,
                );

                $sources = [];
                foreach ($this->rows($response) as $row) {
                    $sources[] = [
                        'org' => $this->textSanitizer->sanitizeNullable('PowerDMARC sending org', $row['org'] ?? null, 200),
                        'volume' => $this->numberOrNull($row['volume'] ?? null),
                        'dmarc_pass_count' => $this->numberOrNull($row['policy_dmarc_pass_count'] ?? null),
                        'dmarc_fail_count' => $this->numberOrNull($row['policy_dmarc_fail_count'] ?? null),
                        'dmarc_pass_percentage' => $this->numberOrNull($row['policy_dmarc_pass_percentage'] ?? null),
                        'dmarc_fail_percentage' => $this->numberOrNull($row['policy_dmarc_fail_percentage'] ?? null),
                        'spf_align_percentage' => $this->numberOrNull($row['policy_spf_align_percentage'] ?? null),
                        'dkim_align_percentage' => $this->numberOrNull($row['policy_dkim_align_percentage'] ?? null),
                    ];
                }

                $byStatus[$wanted] = ['count' => count($sources), 'sources' => $sources];
            }
        } catch (\Throwable $e) {
            // One failed status query fails the whole read — a grouped answer
            // missing one group would present a partial picture wearing a
            // success shape.
            return $this->apiError($e);
        }

        $out = [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
            'domain' => $mapping->domain_name,
            'from' => $from,
            'to' => $to,
        ];

        if ($status !== '') {
            return $out + ['status' => $status] + $byStatus[$status];
        }

        return $out + ['statuses' => $byStatus];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getDnsTimeline(array $input, ?int $clientId): array
    {
        $resolved = $this->resolveMappedDomain($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }
        [$client, $mapping] = $resolved;

        foreach (['start_date', 'end_date'] as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '' && ! $this->isYmdDate($value)) {
                return ['error' => "{$field} must be a date in YYYY-MM-DD form, e.g. 2026-08-01. Received: ".mb_substr($value, 0, 40)];
            }
        }

        try {
            $response = $this->client()->getDnsChanges(
                $mapping->powerdmarc_domain_id,
                type: trim((string) ($input['type'] ?? '')) ?: null,
                startDate: trim((string) ($input['start_date'] ?? '')) ?: null,
                endDate: trim((string) ($input['end_date'] ?? '')) ?: null,
                page: $this->positiveInt($input['page'] ?? null) ?? 1,
            );
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        // SHAPE FACT (PowerDmarcClient fact 3): `data` is an OBJECT keyed by
        // 'd-M-Y' date strings, each holding a LIST of {times, recordInfo}
        // entries. Flatten it into one chronological list an agent can scan.
        $changes = [];
        foreach ((array) ($response['data'] ?? []) as $date => $entries) {
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $times = is_array($entry['times'] ?? null) ? $entry['times'] : [];

                $types = array_values(array_filter((array) ($times['type'] ?? []), 'is_string'));

                $changes[] = [
                    'date' => (string) $date,
                    'time' => $this->scalarOrNull($times['time'] ?? null),
                    'type' => $types !== [] ? implode(',', $types) : null,
                    'change' => $this->textSanitizer->sanitizeNullable('PowerDMARC DNS change', $times['change'] ?? null, 300),
                    'old_record' => $this->textSanitizer->sanitizeNullable('DNS record (old)', $times['old_record_string'] ?? null, 1000),
                    'new_record' => $this->textSanitizer->sanitizeNullable('DNS record (new)', $times['new_record_string'] ?? null, 1000),
                    'score' => $this->numberOrNull($times['score'] ?? null),
                    'score_mark' => $this->scalarOrNull($times['score_mark'] ?? null),
                ];
            }
        }

        return [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
            'domain' => $mapping->domain_name,
            'count' => count($changes),
            'changes' => $changes,
            'page' => $this->pageMeta($response),
        ];
    }

    // ── projections ────────────────────────────────────────────────────────────

    /**
     * CurrentScoreResource: {data: OBJECT} with lowercase-hyphenated component
     * keys (dmarc, spf, dkim, mta-sts, tls-rpt, bimi), each {status, value}.
     * bimi.value is an object and mta-sts.value nullable (shape fact 5), so
     * values go through the bounded structure sanitizer, never a string cast.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function projectCurrentScore(array $response): array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $details = is_array($data['details'] ?? null) ? $data['details'] : [];

        $components = [];
        foreach (['dmarc', 'spf', 'dkim', 'mta-sts', 'tls-rpt', 'bimi'] as $key) {
            $detail = is_array($details[$key] ?? null) ? $details[$key] : [];
            $components[$key] = [
                'status' => $this->scalarOrNull($detail['status'] ?? null),
                'value' => $this->sanitizeStructure("PowerDMARC {$key} record", $detail['value'] ?? null),
            ];
        }

        return [
            'percent' => $this->numberOrNull($data['percent'] ?? null),
            'score' => $this->numberOrNull($data['score'] ?? null),
            'score_mark' => $this->scalarOrNull($data['score_mark'] ?? null),
            'completed_actions_count' => $this->numberOrNull($data['completed_actions_count'] ?? null),
            'errors_count' => $this->numberOrNull($data['errors_count'] ?? null),
            'components' => $components,
        ];
    }

    /**
     * GetDomainHealthResource: {data: ARRAY of one resource} with PascalCase
     * `statuses` keys (shape fact 4) — projected exactly as the vendor cases them.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function projectDomainHealth(array $response): array
    {
        $resource = $this->rows($response)[0] ?? [];

        return [
            'policy' => $this->scalarOrNull($resource['policy'] ?? null),
            'percent' => $this->numberOrNull($resource['percent'] ?? null),
            'score' => $this->numberOrNull($resource['score'] ?? null),
            'score_mark' => $this->scalarOrNull($resource['scoreMark'] ?? null),
            'statuses' => $this->scalarMap($resource['statuses'] ?? null),
            // Element shapes are nested vendor structures ({record[], message,
            // text} / {url, url_text}) — bounded leaf-sanitized, never trusted.
            'errors' => $this->sanitizeStructure('PowerDMARC health error', $resource['errors'] ?? []),
            'suggestions' => $this->sanitizeStructure('PowerDMARC health suggestion', $resource['suggestions'] ?? []),
        ];
    }

    // ── scoping helpers ────────────────────────────────────────────────────────

    /**
     * Resolve the PSA client for a client-scoped tool AND the single mapped
     * PowerDMARC domain the call targets. Returns [Client, ClientPowerdmarcDomain]
     * on success, or an error payload array to hand straight back.
     *
     * Rules (the data boundary):
     *  - zero mapped domains → refusal naming the real mapping screen;
     *  - `domain` omitted + exactly one mapping → use it;
     *  - `domain` omitted + several mappings → refusal listing the mapped names;
     *  - `domain` given → must match one of THIS client's mapped domains. There
     *    is never an account-wide fallback, so naming another client's domain
     *    refuses rather than reads.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: Client, 1: ClientPowerdmarcDomain}|array{error: string}
     */
    private function resolveMappedDomain(array $input, ?int $clientId): array
    {
        $id = $clientId ?? $this->positiveInt($input['client_id'] ?? null);
        if ($id === null) {
            return ['error' => 'client_id is required'];
        }

        $client = Client::find($id);
        if ($client === null) {
            return ['error' => "PSA client {$id} was not found."];
        }

        $mappings = $client->powerdmarcDomains;
        if ($mappings->isEmpty()) {
            return ['error' => "{$client->name} is not mapped to a PowerDMARC domain. An operator can map it in Settings → Integrations → PowerDMARC → Domain Mapping, which links the client to one or more PowerDMARC domains from the live domain list. To find the right domain first, run powerdmarc_list_domains — it annotates every domain with its mapped PSA client."];
        }

        $requested = mb_strtolower(trim((string) ($input['domain'] ?? '')));

        if ($requested === '') {
            if ($mappings->count() === 1) {
                return [$client, $mappings->first()];
            }

            return ['error' => "{$client->name} is mapped to ".$mappings->count().' PowerDMARC domains — pass `domain` to pick one of: '.$mappings->pluck('domain_name')->implode(', ').'.'];
        }

        $mapping = $mappings->first(
            fn (ClientPowerdmarcDomain $row) => mb_strtolower($row->domain_name) === $requested,
        );

        if ($mapping === null) {
            // NEVER fall back to the account-wide domain list here — that would
            // let a call read a domain mapped to a different client by naming it.
            return ['error' => "'".trim((string) ($input['domain'] ?? ''))."' is not one of {$client->name}'s mapped PowerDMARC domains (".$mappings->pluck('domain_name')->implode(', ').'). If the domain belongs to this client, an operator can map it in Settings → Integrations → PowerDMARC → Domain Mapping.'];
        }

        return [$client, $mapping];
    }

    /** @return \Illuminate\Support\Collection<int, Client> PSA clients keyed by powerdmarc_domain_id. */
    private function mappedClientsByDomainId(): \Illuminate\Support\Collection
    {
        // Source of truth is the pivot (client_powerdmarc_domains); a client may
        // key several rows. Joining through Client::query() keeps the soft-delete
        // scope, so a trashed client's domains read as unmapped.
        return Client::query()
            ->join('client_powerdmarc_domains', 'client_powerdmarc_domains.client_id', '=', 'clients.id')
            ->get(['clients.id', 'clients.name', 'client_powerdmarc_domains.powerdmarc_domain_id'])
            ->keyBy('powerdmarc_domain_id');
    }

    // ── plumbing ───────────────────────────────────────────────────────────────

    private function client(): PowerDmarcClient
    {
        return app(PowerDmarcClient::class);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $response): array
    {
        $rows = $response['data'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Laravel-paginator meta projection ({current_page, last_page, per_page,
     * total} — shape fact 2).
     *
     * @param  array<string, mixed>  $response
     * @return array<string, int|float|null>
     */
    private function pageMeta(array $response): array
    {
        $meta = is_array($response['meta'] ?? null) ? $response['meta'] : [];

        return [
            'current_page' => $this->numberOrNull($meta['current_page'] ?? null),
            'last_page' => $this->numberOrNull($meta['last_page'] ?? null),
            'per_page' => $this->numberOrNull($meta['per_page'] ?? null),
            'total' => $this->numberOrNull($meta['total'] ?? null),
        ];
    }

    /** YYYY-MM-DD, parsed as well as pattern-matched so 2026-02-31 is rejected. */
    private function isYmdDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function scalarOrNull(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    private function numberOrNull(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * @return array<string, int|float|string|bool|null>
     */
    private function scalarMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item) || $item === null) {
                $out[(string) $key] = $item;
            }
        }

        return $out;
    }

    /**
     * Bounded recursive leaf-sanitizer for untrusted nested structures (record
     * values that may be strings OR objects — bimi's {default: ...} — and the
     * health errors/suggestions trees). String leaves are redacted and fenced;
     * scalars pass through; depth and breadth are capped.
     */
    private function sanitizeStructure(string $label, mixed $value, int $maxDepth = 4, int $maxItems = 30): mixed
    {
        if (is_string($value)) {
            return $this->textSanitizer->sanitizeNullable($label, $value, 1000);
        }

        if (! is_array($value) || $maxDepth <= 0) {
            return is_array($value) ? '[truncated]' : $this->scalarOrNull($value);
        }

        $out = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count++ >= $maxItems) {
                $out['_truncated'] = true;
                break;
            }
            $out[$key] = $this->sanitizeStructure($label, $item, $maxDepth - 1, $maxItems);
        }

        return $out;
    }

    private function apiError(\Throwable $e): array
    {
        Log::warning('[PowerDMARC reads] query failed', ['error' => $e->getMessage()]);

        return ['error' => 'PowerDMARC query failed: '.mb_substr($e->getMessage(), 0, 200)];
    }
}
