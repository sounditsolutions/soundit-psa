<?php

namespace App\Services\Huntress;

use App\Models\Client;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\HuntressConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Huntress P1 read tools for the staff MCP surface (bd psa-shej, epic psa-ppl9).
 *
 * Six general (account-scoped) read tools: list/get incident_reports, list/get
 * escalations, list/get organizations. Modeled on TacticalReadOnlyToolset and wired
 * into the Chet data surface (ChetDataSurfaceTools + ChetDataSurfaceToolExecutor).
 *
 * DATA-BOUNDARY RULE (the Huntress account can be shared across MSPs):
 *  - Organization METADATA is account-wide — the org↔client mapping helper must surface
 *    unmapped orgs so a human can map them (mirrors HuntressOrganizationController).
 *  - Incident/escalation SECURITY data is MAPPED-ORGS-ONLY — only orgs that map to a PSA
 *    client (clients.huntress_organization_id) are returned. This read-only boundary
 *    is distinct from polling, which additionally requires a validated ticket-record link.
 *    Account-level escalations with no org association (e.g. integration-health
 *    "Failed to Deliver") are kept — they are not another tenant's client data.
 *
 * REDACTION: everything fed to Chet passes per-sink redaction — free text (subject/body/
 * summary/org name) via ChetDataSurfaceTextSanitizer (normalize → redact → truncate →
 * fence); nested untrusted structures (entities, remediations) via a bounded recursive
 * leaf-sanitizer. PSA-side names (psa_client_name) are our own trusted data, not fenced.
 */
class HuntressReadOnlyToolset
{
    use HuntressExpandedReads;

    /** Max rows per organizations page this tool requests (pageParams max; the API allows 500). */
    private const ORG_LIST_MAX_LIMIT = 100;

    /**
     * Page cap for the PSA-side name scan: at most 20 pages x 100 = 2,000 organizations
     * per call, i.e. at most 20 requests against the 60 req/min account limit.
     */
    private const NAME_SCAN_MAX_PAGES = 20;

    private const GENERAL_TOOL_NAMES = [
        'huntress_list_incident_reports',
        'huntress_get_incident_report',
        'huntress_list_escalations',
        'huntress_get_escalation',
        'huntress_list_organizations',
        'huntress_get_organization',
    ];

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            ...self::expandedDefinitions(),
            [
                'name' => 'huntress_list_incident_reports',
                'description' => 'List Huntress incident reports for organizations mapped to a PSA client. Filter by organization_id, status, severity, platform, agent_id, or indicator_type. Returns one page plus next_page_token; malware/incident bodies are on the get tool, not the list.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID: resolves the Huntress organization through the PSA mapping (preferred over organization_id). An unmapped client is an error, not an empty list.'],
                        'organization_id' => ['type' => 'integer', 'description' => 'Huntress organization ID (must map to a PSA client). Omit to page across all mapped organizations.'],
                        'status' => ['type' => 'string', 'description' => 'Filter by status: sent, closed, dismissed, auto_remediating, deleting, partner_dismissed.'],
                        'severity' => ['type' => 'string', 'description' => 'Filter by severity: low, high, critical.'],
                        'platform' => ['type' => 'string', 'description' => 'Filter by platform: windows, darwin, microsoft_365, google, linux, email_security, other.'],
                        'agent_id' => ['type' => 'integer', 'description' => 'Filter by Huntress agent ID.'],
                        'indicator_type' => ['type' => 'string', 'description' => 'Filter by indicator type, e.g. footholds, ransomware_canaries, process_detections.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max reports to return (default 25, max 100).'],
                        'page_token' => ['type' => 'string', 'description' => 'Opaque cursor from a previous response next_page_token.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'huntress_get_incident_report',
                'description' => 'Get one Huntress incident report by ID, including the incident body, indicators, and remediations. Only reports in an organization mapped to a PSA client are returned.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'incident_report_id' => ['type' => 'integer', 'description' => 'Huntress incident report ID. IDs from legacy infection_reports URLs are valid here.'],
                    ],
                    'required' => ['incident_report_id'],
                ],
            ],
            [
                'name' => 'huntress_list_escalations',
                'description' => 'List Huntress SOC escalations that concern a PSA-mapped organization or the account as a whole (e.g. integration-health "Failed to Deliver"). Filter by organization_id, status, severity, or subtype. Returns one page plus next_page_token.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID: resolves the Huntress organization through the PSA mapping (preferred over organization_id). An unmapped client is an error, not an empty list.'],
                        'organization_id' => ['type' => 'integer', 'description' => 'Huntress organization ID (must map to a PSA client). Omit to page across mapped + account-level escalations.'],
                        'status' => ['type' => 'string', 'description' => 'Filter by escalation status.'],
                        'severity' => ['type' => 'string', 'description' => 'Filter by severity.'],
                        'subtype' => ['type' => 'string', 'description' => 'Filter by escalation subtype.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max escalations to return (default 25, max 100).'],
                        'page_token' => ['type' => 'string', 'description' => 'Opaque cursor from a previous response next_page_token.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'huntress_get_escalation',
                'description' => 'Get one Huntress escalation by ID, including resolve status, subtype, associated organizations, and affected entities. Only escalations touching a PSA-mapped organization (or account-level escalations) are returned.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'escalation_id' => ['type' => 'integer', 'description' => 'Huntress escalation ID.'],
                    ],
                    'required' => ['escalation_id'],
                ],
            ],
            [
                'name' => 'huntress_list_organizations',
                'description' => 'List Huntress organizations, each annotated with its mapped PSA client (or null when unmapped). For a PSA client, pass client_id: the organization is resolved through the PSA mapping (a foreign key), not by name. Use name only to find organizations that are NOT mapped yet: it is a case-insensitive substring match done in the PSA over the account\'s organization list (scan capped at 2,000 organizations). Zero results for a name means no organization name contains that text; it does NOT mean a client is unmapped.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID: returns the Huntress organization mapped to it (an unmapped client is an error, not an empty list). Do not combine with name/key/page_token.'],
                        'name' => ['type' => 'string', 'description' => 'Case-insensitive substring of the organization name, matched in the PSA (e.g. "dent" matches "Example Dental Group"). For unmapped organizations; returns all matches up to limit in one answer, no paging.'],
                        'key' => ['type' => 'string', 'description' => 'Filter by organization key (exact, applied by Huntress).'],
                        'limit' => ['type' => 'integer', 'description' => 'Max organizations to return (default 50, max 100).'],
                        'page_token' => ['type' => 'string', 'description' => 'Opaque cursor from a previous response next_page_token.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'huntress_get_organization',
                'description' => 'Get one Huntress organization, annotated with its mapped PSA client (or null when unmapped), including agent, identity, and incident counts. Pass client_id to get the organization mapped to a PSA client, or organization_id for a specific organization.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID: resolves the organization through the PSA mapping. An unmapped client is an error.'],
                        'organization_id' => ['type' => 'integer', 'description' => 'Huntress organization ID.'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function generalDefinitions(): array
    {
        return self::definitions();
    }

    /** @return array<int, array<string, mixed>> All Huntress reads are general — none are PSA-client-scoped. */
    public static function clientDefinitions(): array
    {
        return [];
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::GENERAL_TOOL_NAMES, true)
            || in_array($toolName, array_column(self::expandedDefinitions(), 'name'), true);
    }

    public static function requiresClient(string $toolName): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $input, ?int $clientId = null): array
    {
        if (! HuntressConfig::isConfigured()) {
            return ['error' => 'Huntress is not configured'];
        }

        return match ($toolName) {
            'huntress_list_known_vpns' => $this->knownVpnRead($input, $clientId),
            'huntress_list_unwanted_access_rules' => $this->signalRead($input, $clientId, false, 'unwanted_access_rules'),
            'huntress_get_unwanted_access_rule' => $this->signalRead($input, $clientId, true, 'unwanted_access_rules'),
            'huntress_list_identities' => $this->signalRead($input, $clientId, false, 'identities'),
            'huntress_get_identity' => $this->signalRead($input, $clientId, true, 'identities'),
            'huntress_list_siem_log_sources' => $this->signalRead($input, $clientId, false, 'log_sources'),
            'huntress_get_siem_log_source' => $this->signalRead($input, $clientId, true, 'log_sources'),
            'huntress_list_incident_comments' => $this->incidentDetailRead($input, $clientId, 'comments'),
            'huntress_list_incident_remediations' => $this->incidentDetailRead($input, $clientId, 'remediations'),
            'huntress_get_incident_remediation' => $this->incidentDetailRead($input, $clientId, 'remediations', true),
            'huntress_list_platform_actions' => $this->signalRead($input, $clientId, false, 'platform_actions'),
            'huntress_get_platform_action' => $this->signalRead($input, $clientId, true, 'platform_actions'),
            'huntress_siem_query' => $this->siemQuery($input, $clientId),
            'huntress_list_agents' => $this->signalRead($input, $clientId, false, 'agents'),
            'huntress_get_agent' => $this->signalRead($input, $clientId, true, 'agents'),
            'huntress_list_reports' => $this->signalRead($input, $clientId, false, 'reports'),
            'huntress_get_report' => $this->signalRead($input, $clientId, true, 'reports'),
            'huntress_list_signals' => $this->signalRead($input, $clientId, false),
            'huntress_get_signal' => $this->signalRead($input, $clientId, true),
            'huntress_list_incident_reports' => $this->listIncidentReports($input, $clientId),
            'huntress_get_incident_report' => $this->getIncidentReport($input),
            'huntress_list_escalations' => $this->listEscalations($input, $clientId),
            'huntress_get_escalation' => $this->getEscalation($input),
            'huntress_list_organizations' => $this->listOrganizations($input, $clientId),
            'huntress_get_organization' => $this->getOrganization($input, $clientId),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    // ── incident reports ───────────────────────────────────────────────────────

    private function listIncidentReports(array $input, ?int $clientId): array
    {
        $mapped = $this->mappedClientsByOrgId();
        $params = $this->pageParams($input, default: 25, max: 100);

        foreach (['status', 'severity', 'platform', 'indicator_type'] as $filter) {
            $value = trim((string) ($input[$filter] ?? ''));
            if ($value !== '') {
                $params[$filter] = $value;
            }
        }

        $agentId = $this->positiveInt($input['agent_id'] ?? null);
        if ($agentId !== null) {
            $params['agent_id'] = $agentId;
        }

        $orgId = $this->scopedOrgId($input, $clientId);
        if (is_array($orgId)) {
            return $orgId;
        }
        if ($orgId !== null) {
            if (! $mapped->has($orgId)) {
                return ['error' => "Organization {$orgId} is not mapped to a PSA client."];
            }
            $params['organization_id'] = $orgId;
        }

        try {
            $response = $this->client()->get('incident_reports', $params);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        $reports = [];
        foreach ($this->rows($response, 'incident_reports') as $row) {
            $rowOrg = $this->positiveInt($row['organization_id'] ?? null);
            if ($rowOrg === null || ! $mapped->has($rowOrg)) {
                continue; // mapped-orgs-only: never surface another MSP's incident
            }
            $reports[] = $this->mapIncidentReport($row, $mapped, detail: false);
        }

        return [
            'count' => count($reports),
            'incident_reports' => $reports,
            'next_page_token' => $this->nextPageToken($response),
        ];
    }

    private function getIncidentReport(array $input): array
    {
        $id = $this->positiveInt($input['incident_report_id'] ?? null);
        if ($id === null) {
            return ['error' => 'incident_report_id is required'];
        }

        try {
            $report = $this->client()->getIncidentReport($id);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        if (empty($report)) {
            return ['error' => "Incident report {$id} was not found."];
        }

        $mapped = $this->mappedClientsByOrgId();
        $orgId = $this->positiveInt($report['organization_id'] ?? null);
        if ($orgId === null || ! $mapped->has($orgId)) {
            return ['error' => "Incident report {$id} was not found or is not in a mapped organization."];
        }

        return $this->mapIncidentReport($report, $mapped, detail: true);
    }

    // ── escalations ────────────────────────────────────────────────────────────

    private function listEscalations(array $input, ?int $clientId): array
    {
        $mapped = $this->mappedClientsByOrgId();
        $params = $this->pageParams($input, default: 25, max: 100);

        foreach (['status', 'severity', 'subtype'] as $filter) {
            $value = trim((string) ($input[$filter] ?? ''));
            if ($value !== '') {
                $params[$filter] = $value;
            }
        }

        $orgId = $this->scopedOrgId($input, $clientId);
        if (is_array($orgId)) {
            return $orgId;
        }
        if ($orgId !== null) {
            if (! $mapped->has($orgId)) {
                return ['error' => "Organization {$orgId} is not mapped to a PSA client."];
            }
            $params['organization_id'] = $orgId;
        }

        try {
            $response = $this->client()->get('escalations', $params);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        $escalations = [];
        foreach ($this->rows($response, 'escalations') as $row) {
            if (! $this->escalationInScope($row, $mapped)) {
                continue;
            }
            $escalations[] = $this->mapEscalation($row, $mapped, detail: false);
        }

        return [
            'count' => count($escalations),
            'escalations' => $escalations,
            'next_page_token' => $this->nextPageToken($response),
        ];
    }

    private function getEscalation(array $input): array
    {
        $id = $this->positiveInt($input['escalation_id'] ?? null);
        if ($id === null) {
            return ['error' => 'escalation_id is required'];
        }

        try {
            $escalation = $this->client()->getEscalation($id);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        if (empty($escalation)) {
            return ['error' => "Escalation {$id} was not found."];
        }

        $mapped = $this->mappedClientsByOrgId();
        if (! $this->escalationInScope($escalation, $mapped)) {
            return ['error' => "Escalation {$id} was not found or is not in a mapped organization."];
        }

        return $this->mapEscalation($escalation, $mapped, detail: true);
    }

    // ── organizations (account-wide mapping helper) ─────────────────────────────

    private function listOrganizations(array $input, ?int $clientId): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $key = trim((string) ($input['key'] ?? ''));

        // The client link is a foreign key (clients.huntress_organization_id), so a
        // client-scoped lookup goes through it, never through a name search (Charlie
        // 10/01, Jeeves ruling on card tIP4JoIG). name/key are for UNMAPPED orgs.
        if ($clientId !== null) {
            if ($name !== '' || $key !== '' || trim((string) ($input['page_token'] ?? '')) !== '') {
                return ['error' => 'client_id resolves the Huntress organization through the PSA mapping; do not combine it with name, key or page_token. Use name/key only to find organizations that are not mapped to a PSA client.'];
            }
            $orgId = $this->orgIdForClient($clientId);
            if (is_array($orgId)) {
                return $orgId;
            }
            $org = $this->fetchOrganization($orgId);
            if (isset($org['error'])) {
                return $org;
            }

            return [
                'count' => 1,
                'organizations' => [$org],
                'next_page_token' => null,
                'match' => 'psa_client_mapping',
            ];
        }

        $mapped = $this->mappedClientsByOrgId();

        if ($name !== '') {
            return $this->listOrganizationsByNameSubstring($input, $name, $key, $mapped);
        }

        $params = $this->pageParams($input, default: 50, max: self::ORG_LIST_MAX_LIMIT);
        if ($key !== '') {
            $params['key'] = $key;
        }

        try {
            $response = $this->client()->get('organizations', $params);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        $organizations = array_map(
            fn (array $row): array => $this->mapOrganization($row, $mapped),
            $this->rows($response, 'organizations'),
        );

        return [
            'count' => count($organizations),
            'organizations' => $organizations,
            'next_page_token' => $this->nextPageToken($response),
        ];
    }

    /**
     * name is a case-insensitive SUBSTRING filter applied PSA-side. Huntress's own
     * `name` query parameter matches the whole name exactly (observed: a partial
     * name returns 0 where the full name returns 1), so it is never sent upstream.
     * Instead the org list is paged — ORG_LIST_MAX_LIMIT rows per page, the same
     * max this tool's pageParams() already allows (the API's own max is 500,
     * api.huntress.io/v1/swagger_doc.json GET /v1/organizations) — for at most
     * NAME_SCAN_MAX_PAGES pages, and filtered here.
     *
     * Fail closed (C-56): an envelope that is not {organizations: [objects...],
     * pagination: {...}} or a cursor that is neither a string nor absent/null is an
     * error, never "no match"; a repeated cursor is an error; a scan that stops at
     * the page cap with more pages left reports scan_complete=false and, if nothing
     * matched, refuses rather than claim no organization contains the text.
     *
     * @param  Collection<int, Client>  $mapped
     */
    private function listOrganizationsByNameSubstring(array $input, string $name, string $key, Collection $mapped): array
    {
        if (trim((string) ($input['page_token'] ?? '')) !== '') {
            return ['error' => 'page_token cannot be combined with name: the name filter scans the organization list itself and returns all matches (up to limit) in one answer.'];
        }

        $limit = $this->limit($input, default: 50, max: self::ORG_LIST_MAX_LIMIT);
        $needle = mb_strtolower($name);
        $params = ['limit' => self::ORG_LIST_MAX_LIMIT];
        if ($key !== '') {
            $params['key'] = $key;
        }

        $matches = [];
        $scanned = 0;
        $pages = 0;
        $seenTokens = [];
        $next = null;

        do {
            try {
                $response = $this->client()->get('organizations', $params);
            } catch (\Throwable $e) {
                return $this->apiError($e);
            }
            $pages++;

            $page = $this->organizationsPage($response);
            if ($page === null) {
                Log::warning('[Huntress reads] organizations list returned an unrecognised shape', ['page' => $pages]);

                return ['error' => 'Huntress returned an organizations list in an unrecognised shape, so the name search could not be completed. This is not a "no match" answer.'];
            }

            [$rows, $next] = $page;
            $scanned += count($rows);
            foreach ($rows as $row) {
                $orgName = $row['name'] ?? null;
                if (is_string($orgName) && str_contains(mb_strtolower($orgName), $needle)) {
                    $matches[] = $row;
                }
            }

            if ($next !== null) {
                if (isset($seenTokens[$next])) {
                    return ['error' => 'Huntress repeated a pagination cursor while listing organizations, so the name search could not be completed. This is not a "no match" answer.'];
                }
                $seenTokens[$next] = true;
                $params['page_token'] = $next;
            }
        } while ($next !== null && $pages < self::NAME_SCAN_MAX_PAGES);

        $complete = $next === null;
        if (! $complete && $matches === []) {
            return ['error' => 'No organization name containing that text was found in the first '.$scanned.' organizations, but the account has more than the '.self::NAME_SCAN_MAX_PAGES.'-page scan limit covers, so absence cannot be asserted. Narrow with key, or use client_id for a mapped client.'];
        }

        $total = count($matches);
        $organizations = array_map(
            fn (array $row): array => $this->mapOrganization($row, $mapped),
            array_slice($matches, 0, $limit),
        );

        return [
            'count' => count($organizations),
            'total_matches' => $total,
            'organizations' => $organizations,
            'next_page_token' => null,
            'match' => 'name_substring_case_insensitive',
            'organizations_scanned' => $scanned,
            'scan_complete' => $complete,
            'note' => $complete
                ? ($total === 0
                    ? 'No Huntress organization name contains this text (case-insensitive). That says nothing about whether a PSA client is mapped — use client_id to look up a mapped client.'
                    : null)
                : 'Scan stopped at the '.self::NAME_SCAN_MAX_PAGES.'-page limit; more organizations exist that were not searched.',
        ];
    }

    /**
     * Strict read of one GET /v1/organizations page (swagger: required organizations
     * + pagination; Pagination.next_page_token is a string). Returns [rows, next] or
     * null on any other shape.
     *
     * @param  array<string, mixed>  $response
     * @return array{0: array<int, array<string, mixed>>, 1: ?string}|null
     */
    private function organizationsPage(array $response): ?array
    {
        $rows = $response['organizations'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row) && $row !== []) {
                return null;
            }
        }

        if (! array_key_exists('pagination', $response) || ! is_array($response['pagination'])) {
            return null;
        }
        $token = $response['pagination']['next_page_token'] ?? null;
        if ($token !== null && ! is_string($token)) {
            return null;
        }

        return [$rows, ($token === null || $token === '') ? null : $token];
    }

    private function getOrganization(array $input, ?int $clientId): array
    {
        $id = $this->positiveInt($input['organization_id'] ?? null);

        if ($clientId !== null) {
            $mappedId = $this->orgIdForClient($clientId);
            if (is_array($mappedId)) {
                return $mappedId;
            }
            if ($id !== null && $id !== $mappedId) {
                return ['error' => "organization_id {$id} is not the Huntress organization mapped to PSA client {$clientId}. Pass one or the other."];
            }
            $id = $mappedId;
        }

        if ($id === null) {
            return ['error' => 'organization_id or client_id is required'];
        }

        return $this->fetchOrganization($id);
    }

    /**
     * The Huntress org id a PSA client is mapped to, or an error payload. Unmapped is
     * an explicit refusal, never an empty result.
     *
     * @return int|array{error: string}
     */
    private function orgIdForClient(int $clientId): int|array
    {
        $client = Client::find($clientId);
        if ($client === null) {
            return ['error' => "PSA client {$clientId} was not found."];
        }

        $orgId = $this->positiveInt($client->huntress_organization_id);
        if ($orgId === null) {
            return ['error' => "PSA client {$clientId} ({$client->name}) is not mapped to a Huntress organization. Map it in Settings > Huntress Organization Mapping, or search unmapped organizations with huntress_list_organizations name (case-insensitive substring)."];
        }

        return $orgId;
    }

    /**
     * Resolve the client_id / organization_id pair for the security list tools to the
     * organization_id to send, or an error payload.
     *
     * @return int|array{error: string}|null
     */
    private function scopedOrgId(array $input, ?int $clientId): int|array|null
    {
        $orgId = $this->positiveInt($input['organization_id'] ?? null);
        if ($clientId === null) {
            return $orgId;
        }

        $mappedId = $this->orgIdForClient($clientId);
        if (is_array($mappedId)) {
            return $mappedId;
        }
        if ($orgId !== null && $orgId !== $mappedId) {
            return ['error' => "organization_id {$orgId} is not the Huntress organization mapped to PSA client {$clientId}. Pass one or the other."];
        }

        return $mappedId;
    }

    private function fetchOrganization(int $id): array
    {
        try {
            $response = $this->client()->getOrganization($id);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        // GET /organizations/{id} wraps as {organization: {...}}.
        $org = $response['organization'] ?? $response;
        if (empty($org) || ! is_array($org)) {
            return ['error' => "Organization {$id} was not found."];
        }

        return $this->mapOrganization($org, $this->mappedClientsByOrgId());
    }

    // ── mappers ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Client>  $mapped
     * @return array<string, mixed>
     */
    private function mapIncidentReport(array $row, Collection $mapped, bool $detail): array
    {
        $orgId = $this->positiveInt($row['organization_id'] ?? null);
        $client = $orgId !== null ? $mapped->get($orgId) : null;

        $mappedRow = [
            'id' => $this->positiveInt($row['id'] ?? null),
            'organization_id' => $orgId,
            'psa_client_id' => $client?->id,
            'psa_client_name' => $client?->name,
            'agent_id' => $this->positiveInt($row['agent_id'] ?? null),
            'platform' => $this->scalarOrNull($row['platform'] ?? null),
            'severity' => $this->scalarOrNull($row['severity'] ?? null),
            'status' => $this->scalarOrNull($row['status'] ?? null),
            'sent_at' => $this->scalarOrNull($row['sent_at'] ?? null),
            'closed_at' => $this->scalarOrNull($row['closed_at'] ?? null),
            'status_updated_at' => $this->scalarOrNull($row['status_updated_at'] ?? null),
            'indicator_types' => $this->stringList($row['indicator_types'] ?? null),
            'indicator_counts' => $this->scalarMap($row['indicator_counts'] ?? null),
            'subject' => $this->textSanitizer->sanitizeNullable('Huntress incident subject', $row['subject'] ?? null, 500),
            'summary' => $this->textSanitizer->sanitizeNullable('Huntress incident summary', $row['summary'] ?? null, 2000),
        ];

        if ($detail) {
            $mappedRow['body'] = $this->textSanitizer->sanitizeNullable('Huntress incident body', $row['body'] ?? null, 8000);
            $mappedRow['remediations'] = $this->sanitizeStructure('Huntress remediation detail', $row['remediations'] ?? null);
        }

        return $mappedRow;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Client>  $mapped
     * @return array<string, mixed>
     */
    private function mapEscalation(array $row, Collection $mapped, bool $detail): array
    {
        $mappedRow = [
            'id' => $this->positiveInt($row['id'] ?? null),
            'status' => $this->scalarOrNull($row['status'] ?? null),
            'resolved_at' => $this->scalarOrNull($row['resolved_at'] ?? null),
            'severity' => $this->scalarOrNull($row['severity'] ?? null),
            'subtype' => $this->scalarOrNull($row['subtype'] ?? null),
            'type' => $this->scalarOrNull($row['type'] ?? null),
            'created_at' => $this->scalarOrNull($row['created_at'] ?? null),
            'updated_at' => $this->scalarOrNull($row['updated_at'] ?? null),
            'subject' => $this->textSanitizer->sanitizeNullable('Huntress escalation subject', $row['subject'] ?? null, 500),
            'organizations' => $this->mapEscalationOrganizations($row, $mapped),
        ];

        if ($detail) {
            $mappedRow['entities'] = $this->sanitizeStructure('Huntress escalation entity', $row['entities'] ?? null);
        }

        return $mappedRow;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Client>  $mapped
     * @return array<int, array<string, mixed>>
     */
    private function mapEscalationOrganizations(array $row, Collection $mapped): array
    {
        $out = [];
        foreach ((array) ($row['organizations'] ?? []) as $org) {
            $orgId = is_array($org) ? $this->positiveInt($org['id'] ?? null) : $this->positiveInt($org);
            if ($orgId === null) {
                continue;
            }
            $client = $mapped->get($orgId);
            $out[] = [
                'id' => $orgId,
                'name' => is_array($org)
                    ? $this->textSanitizer->sanitizeNullable('Huntress organization name', $org['name'] ?? null, 300)
                    : null,
                'psa_client_id' => $client?->id,
                'psa_client_name' => $client?->name,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Client>  $mapped
     * @return array<string, mixed>
     */
    private function mapOrganization(array $row, Collection $mapped): array
    {
        $id = $this->positiveInt($row['id'] ?? null);
        $client = $id !== null ? $mapped->get($id) : null;

        return [
            'id' => $id,
            'name' => $this->textSanitizer->sanitizeNullable('Huntress organization name', $row['name'] ?? null, 300),
            'key' => $this->scalarOrNull($row['key'] ?? null),
            'agents_count' => $this->positiveIntOrZero($row['agents_count'] ?? null),
            'billable_identity_count' => $this->positiveIntOrZero($row['billable_identity_count'] ?? null),
            'sat_learner_count' => $this->positiveIntOrZero($row['sat_learner_count'] ?? null),
            'incident_reports_count' => $this->positiveIntOrZero($row['incident_reports_count'] ?? null),
            'logs_sources_count' => $this->positiveIntOrZero($row['logs_sources_count'] ?? null),
            'created_at' => $this->scalarOrNull($row['created_at'] ?? null),
            'updated_at' => $this->scalarOrNull($row['updated_at'] ?? null),
            'psa_client_id' => $client?->id,
            'psa_client_name' => $client?->name,
        ];
    }

    // ── scoping helpers ─────────────────────────────────────────────────────────

    /**
     * An escalation is in scope when it touches at least one PSA-mapped organization,
     * OR carries no organization association at all (account-level, e.g. integration
     * health). Escalations that touch only unmapped orgs are out of scope.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Client>  $mapped
     */
    private function escalationInScope(array $row, Collection $mapped): bool
    {
        $orgIds = $this->escalationOrgIds($row);
        if ($orgIds === []) {
            return true;
        }

        return array_intersect($orgIds, $mapped->keys()->all()) !== [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, int>
     */
    private function escalationOrgIds(array $row): array
    {
        $ids = [];
        foreach ((array) ($row['organizations'] ?? []) as $org) {
            $id = is_array($org) ? $this->positiveInt($org['id'] ?? null) : $this->positiveInt($org);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return Collection<int, Client> Mapped PSA clients keyed by their huntress_organization_id. */
    private function mappedClientsByOrgId(): Collection
    {
        return Client::whereNotNull('huntress_organization_id')
            ->get(['id', 'name', 'huntress_organization_id'])
            ->keyBy('huntress_organization_id');
    }

    // ── plumbing ─────────────────────────────────────────────────────────────────

    private function client(): HuntressClient
    {
        return app(HuntressClient::class);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function pageParams(array $input, int $default, int $max): array
    {
        $params = ['limit' => $this->limit($input, $default, $max)];

        $token = trim((string) ($input['page_token'] ?? ''));
        if ($token !== '') {
            $params['page_token'] = $token;
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $response, string $key): array
    {
        $rows = $response[$key] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /** @param array<string, mixed> $response */
    private function nextPageToken(array $response): ?string
    {
        $token = $response['pagination']['next_page_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function apiError(\Throwable $e): array
    {
        Log::warning('[Huntress reads] query failed', ['error' => $e->getMessage()]);

        return ['error' => 'Huntress query failed: '.mb_substr($e->getMessage(), 0, 200)];
    }

    /**
     * Bounded recursive leaf-sanitizer for arbitrary untrusted nested structures
     * (escalation entities, incident remediations). String leaves are redacted and
     * fenced; numbers/bools/null pass through; depth and breadth are capped.
     */
    private function sanitizeStructure(string $label, mixed $value, int $maxDepth = 4, int $maxItems = 30): mixed
    {
        if (is_string($value)) {
            return $this->textSanitizer->sanitizeNullable($label, $value, 500);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            if ($maxDepth <= 0) {
                return '[truncated: max depth]';
            }

            $out = [];
            $count = 0;
            foreach ($value as $k => $v) {
                if ($count++ >= $maxItems) {
                    $out['_truncated'] = true;
                    break;
                }
                $out[$k] = $this->sanitizeStructure($label, $v, $maxDepth - 1, $maxItems);
            }

            return $out;
        }

        return null;
    }

    private function limit(array $input, int $default, int $max): int
    {
        $limit = $this->positiveInt($input['limit'] ?? null) ?? $default;

        return min(max($limit, 1), $max);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function positiveIntOrZero(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function scalarOrNull(mixed $value): mixed
    {
        return is_scalar($value) ? $value : null;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }

    /**
     * @return array<string, int|float|string|bool>
     */
    private function scalarMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = $v;
            }
        }

        return $out;
    }
}
