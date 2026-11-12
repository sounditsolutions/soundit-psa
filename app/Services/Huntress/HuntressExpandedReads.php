<?php

namespace App\Services\Huntress;

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\RequestException;

/** Additional read-only operations; shares the existing mapping and sanitizer seams. */
trait HuntressExpandedReads
{
    private static function expandedDefinitions(): array
    {
        return [...self::logSourceDefinitions(), ...self::incidentDetailDefinitions(), ...self::signalDefinitions(), ...self::endpointDefinitions(), [
            'name' => 'huntress_siem_query',
            'description' => 'Query Huntress SIEM logs for one mapped PSA client. The organization filter is inserted before all other stages. Zero matches is not an all-clear; check log sources too. Requires the SIEM feature.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer'],
                    'organization_id' => ['type' => 'integer'],
                    'esql' => ['type' => 'string'],
                    'range_start' => ['type' => 'string', 'description' => 'ISO 8601 start timestamp.'],
                    'range_end' => ['type' => 'string', 'description' => 'ISO 8601 end timestamp.'],
                    'page_token' => ['type' => 'string'],
                ],
                'required' => ['client_id', 'esql', 'range_start', 'range_end'],
            ],
        ]];
    }

    private static function logSourceDefinitions(): array
    {
        $scope = ['client_id' => ['type' => 'integer'], 'organization_id' => ['type' => 'integer']];

        return [
            ['name' => 'huntress_list_siem_log_sources', 'description' => 'List Huntress SIEM log sources in mapped organizations, optionally selecting misconfigured sources.',
                'input_schema' => ['type' => 'object', 'properties' => $scope + ['misconfigured' => ['type' => 'boolean'], 'only_for_account' => ['type' => 'boolean'], 'type_code' => ['type' => 'string'], 'limit' => ['type' => 'integer'], 'page_token' => ['type' => 'string']], 'required' => []]],
            ['name' => 'huntress_get_siem_log_source', 'description' => 'Read one Huntress SIEM log source in a mapped organization.',
                'input_schema' => ['type' => 'object', 'properties' => $scope + ['id' => ['type' => 'integer']], 'required' => ['id']]],
        ];
    }

    private static function signalDefinitions(): array
    {
        $scope = ['client_id' => ['type' => 'integer'], 'organization_id' => ['type' => 'integer']];
        $filters = [];
        // Vendor Swagger getV1Signals parameters; types/statuses are literal comma-separated strings.
        foreach (['sort_field', 'sort_direction', 'investigated_at_min', 'investigated_at_max', 'entity_type', 'entity_id', 'types', 'statuses', 'page_token'] as $name) {
            $filters[$name] = ['type' => 'string'];
        }

        return [
            ['name' => 'huntress_list_signals', 'description' => 'List Huntress signals in mapped organizations, optionally for one client. Types and statuses filters are comma-separated strings.',
                'input_schema' => ['type' => 'object', 'properties' => $scope + $filters + ['limit' => ['type' => 'integer']], 'required' => []]],
            ['name' => 'huntress_get_signal', 'description' => 'Read one Huntress signal in a mapped organization.',
                'input_schema' => ['type' => 'object', 'properties' => $scope + ['id' => ['type' => 'integer']], 'required' => ['id']]],
        ];
    }

    private const ENDPOINT_FILTERS = [
        'unwanted_access_rules' => ['type', 'status', 'scope', 'category', 'country_code', 'vpn', 'ip_address', 'logic'],
        'identities' => ['sort_field', 'sort_direction', 'tenant_type', 'risk_level'],
        'log_sources' => ['type_code'],
        'misconfigured_log_sources' => [],
        'platform_actions' => ['sort_field', 'sort_direction', 'status', 'severity', 'subtype'],
        'agents' => ['sort_field', 'sort_direction', 'platform', 'hostname', 'os', 'version'],
        'reports' => ['sort_field', 'sort_direction', 'period_min', 'period_max', 'type'],
        'signals' => ['sort_field', 'sort_direction', 'investigated_at_min', 'investigated_at_max', 'entity_type', 'entity_id', 'types', 'statuses'],
    ];

    private static function endpointDefinitions(): array
    {
        $definitions = [];
        // Vendor getV1Agents/getV1Reports: scalar string filters and common pagination.
        foreach (['unwanted_access_rules' => 'unwanted_access_rule', 'identities' => 'identity', 'agents' => 'agent', 'reports' => 'report', 'platform_actions' => 'platform_action'] as $plural => $singular) {
            $scope = ['client_id' => ['type' => 'integer'], 'organization_id' => ['type' => 'integer']];
            $filters = [];
            foreach (self::ENDPOINT_FILTERS[$plural] as $filter) {
                $filters[$filter] = ['type' => 'string'];
            }
            if ($plural === 'unwanted_access_rules') {
                $filters['identity_id'] = ['type' => 'integer'];
            }
            if ($plural === 'identities') {
                foreach (['billable', 'enabled', 'external', 'mfa_enabled'] as $filter) {
                    $filters[$filter] = ['type' => 'boolean'];
                }
                $filters['escalation_id'] = ['type' => 'integer'];
            }
            $definitions[] = ['name' => 'huntress_list_'.$plural, 'description' => 'List Huntress '.$plural.' in mapped organizations, optionally for one client.',
                'input_schema' => ['type' => 'object', 'properties' => $scope + $filters + ['limit' => ['type' => 'integer'], 'page_token' => ['type' => 'string']], 'required' => []]];
            $definitions[] = ['name' => 'huntress_get_'.$singular, 'description' => 'Read one Huntress '.$singular.' in a mapped organization.',
                'input_schema' => ['type' => 'object', 'properties' => $scope + ['id' => ['type' => 'integer']], 'required' => ['id']]];
        }

        return $definitions;
    }

    private function signalRead(array $input, ?int $clientId, bool $get, string $family = 'signals'): array
    {
        if ($family === 'log_sources' && ! $get) {
            foreach (['misconfigured', 'only_for_account'] as $key) {
                if (array_key_exists($key, $input) && ! is_bool($input[$key])) {
                    return ['error' => $key.' must be a boolean'];
                }
            }
            if ($input['misconfigured'] ?? false) {
                $family = 'misconfigured_log_sources';
            }
        }
        $singular = ['unwanted_access_rules' => 'unwanted_access_rule', 'identities' => 'identity', 'log_sources' => 'log_source', 'misconfigured_log_sources' => 'log_source', 'signals' => 'signal', 'agents' => 'agent', 'reports' => 'report', 'platform_actions' => 'platform_action'][$family];
        foreach (['client_id', 'organization_id'] as $key) {
            if (array_key_exists($key, $input) && $this->positiveInt($input[$key]) === null) {
                return ['error' => $key.' must be a positive integer'];
            }
        }
        $clientId ??= $this->positiveInt($input['client_id'] ?? null);
        $org = $this->scopedOrgId($input, $clientId);
        if (is_array($org)) {
            return $org;
        }
        $mapped = $this->mappedClientsByOrgId();
        if ($org !== null && ! $mapped->has($org)) {
            return ['error' => 'Organization is not mapped to a PSA client'];
        }
        $params = [];
        $endpoint = match ($family) {
            'log_sources' => 'siem/log_sources',
            'misconfigured_log_sources' => 'siem/log_sources/misconfigured',
            default => $family,
        };
        if ($get) {
            $id = $this->positiveInt($input['id'] ?? null);
            if ($id === null) {
                return ['error' => 'id must be a positive integer'];
            }
            $endpoint .= '/'.$id;
        } else {
            $params = $this->pageParams($input, default: 10, max: 500);
            foreach (self::ENDPOINT_FILTERS[$family] as $filter) {
                if (isset($input[$filter])) {
                    if (! is_string($input[$filter])) {
                        return ['error' => $filter.' must be a string'];
                    }
                    $params[$filter] = $input[$filter];
                }
            }
            if ($family === 'unwanted_access_rules' && array_key_exists('identity_id', $input)) {
                $identity = $this->positiveInt($input['identity_id']);
                if ($identity === null) {
                    return ['error' => 'identity_id must be a positive integer'];
                }
                $params['identity_id'] = $identity;
            }
            if ($family === 'identities') {
                foreach (['billable', 'enabled', 'external', 'mfa_enabled'] as $filter) {
                    if (array_key_exists($filter, $input)) {
                        if (! is_bool($input[$filter])) {
                            return ['error' => $filter.' must be a boolean'];
                        }
                        // Swagger boolean parameters have no encoding override.
                        $params[$filter] = $input[$filter] ? 'true' : 'false';
                    }
                }
                if (array_key_exists('escalation_id', $input)) {
                    $value = $this->positiveInt($input['escalation_id']);
                    if ($value === null) {
                        return ['error' => 'escalation_id must be a positive integer'];
                    }
                    $params['escalation_id'] = $value;
                }
            }
            if ($family === 'log_sources' && array_key_exists('only_for_account', $input)) {
                // Swagger boolean has no serialization override: use its literal boolean text.
                $params['only_for_account'] = $input['only_for_account'] ? 'true' : 'false';
            }
            if ($org !== null) {
                $params['organization_id'] = $org;
            }
        }
        try {
            $response = $this->client()->get($endpoint, $params);
        } catch (HuntressClientException $e) {
            return $this->expandedApiError($e);
        }
        // Vendor Signal uses organization: {id, name}; Agent/SummaryReport use organization_id.
        $rows = $get ? [$response[$singular] ?? null] : ($response[$family] ?? null);
        if (! is_array($rows) || ! array_is_list($rows)) {
            return ['error' => 'Huntress returned an unrecognized collection shape'];
        }
        $result = [];
        $dropped = 0;
        foreach ($rows as $row) {
            if ($family === 'unwanted_access_rules') {
                // Swagger only classifies applied_to.type=Organization with an integer id.
                // Account/identity shapes are unspecified: fail closed, per STOP1.
                $applied = is_array($row) ? ($row['applied_to'] ?? null) : null;
                if (! is_array($applied) || ($applied['type'] ?? null) !== 'Organization' || ! is_int($applied['id'] ?? null)) {
                    if ($get) {
                        return ['error' => 'Unwanted access rule scope is unclassified'];
                    }
                    $dropped++;

                    continue;
                }
                if ($org !== null && $applied['id'] !== $org) {
                    return ['error' => 'Huntress returned a row outside the requested organization'];
                }
                if (! $mapped->has($applied['id'])) {
                    if ($get) {
                        return ['error' => 'Row organization is not mapped to a PSA client'];
                    }
                    $dropped++;

                    continue;
                }
                $result[] = $this->sanitizeStructure('Huntress unwanted access rule', $row);

                continue;
            }
            if ($family === 'platform_actions') {
                // PlatformAction Swagger example and measured rows use organizations: [{id, name}].
                $organizations = is_array($row) ? ($row['organizations'] ?? null) : null;
                if (! is_array($organizations) || ! array_is_list($organizations)) {
                    return ['error' => 'Huntress returned an unrecognized organizations shape'];
                }
                $allowed = true;
                foreach ($organizations as $organization) {
                    if (! is_array($organization) || ! is_int($organization['id'] ?? null)) {
                        return ['error' => 'Huntress returned an unrecognized row organization'];
                    }
                    if ($org !== null && $organization['id'] !== $org) {
                        return ['error' => 'Huntress returned a row outside the requested organization'];
                    }
                    $allowed = $allowed && $mapped->has($organization['id']);
                }
                if (! $allowed) {
                    if ($get) {
                        return ['error' => 'Row organization is not mapped to a PSA client'];
                    }

                    continue;
                }
                $result[] = $this->sanitizeStructure('Huntress platform action', $row);

                continue;
            }
            $rowOrg = in_array($family, ['identities', 'signals', 'log_sources', 'misconfigured_log_sources'], true) ? ($row['organization']['id'] ?? null) : ($row['organization_id'] ?? null);
            if (! is_array($row) || ! is_int($rowOrg)) {
                // Identity.account is never a substitute for organization.id (Forge STOP3).
                if ($family === 'identities' && ! $get) {
                    continue;
                }

                return ['error' => 'Huntress returned an unrecognized row organization'];
            }
            if ($org !== null && $rowOrg !== $org) {
                return ['error' => 'Huntress returned a row outside the requested organization'];
            }
            if (! $mapped->has($rowOrg)) {
                if ($get) {
                    return ['error' => 'Row organization is not mapped to a PSA client'];
                }

                continue;
            }
            $result[] = $this->sanitizeStructure('Huntress '.$singular, $row);
        }

        $output = $get ? [$singular => $result[0]] : ['count' => count($result), $family => $result, 'next_page_token' => $this->nextPageToken($response)];
        if ($family === 'unwanted_access_rules' && ! $get) {
            $output['dropped_unclassified'] = $dropped;
            $output['note'] = 'Only classifiable mapped Organization rules are shown; other scopes and unmapped rules are dropped and counted. This list may be incomplete.';
        }

        return $output;
    }

    private const REMEDIATION_FILTERS = [
        'types' => ['assisted', 'manual', 'containment'],
        'statuses' => ['unapproved', 'approved', 'completed', 'failed', 'cancelled'],
    ];

    private static function incidentDetailDefinitions(): array
    {
        $definitions = [];
        foreach (['list_incident_comments', 'list_incident_remediations', 'get_incident_remediation'] as $tool) {
            $get = str_starts_with($tool, 'get_');
            $properties = ['incident_report_id' => ['type' => 'integer'], 'client_id' => ['type' => 'integer'], 'organization_id' => ['type' => 'integer']];
            $required = ['incident_report_id'];
            if ($get) {
                $properties['remediation_id'] = ['type' => 'integer'];
                $required[] = 'remediation_id';
            } else {
                $properties += ['limit' => ['type' => 'integer'], 'page_token' => ['type' => 'string'], 'sort_field' => ['type' => 'string'], 'sort_direction' => ['type' => 'string']];
                if ($tool === 'list_incident_remediations') {
                    foreach (self::REMEDIATION_FILTERS as $name => $enum) {
                        $properties[$name] = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $enum]];
                    }
                }
            }
            $definitions[] = ['name' => 'huntress_'.$tool, 'description' => 'Read Huntress incident '.($tool === 'list_incident_comments' ? 'comments' : 'remediations').'. The parent incident must belong to a mapped organization.',
                'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => $required]];
        }

        return $definitions;
    }

    private function incidentDetailRead(array $input, ?int $clientId, string $family, bool $get = false): array
    {
        foreach (['client_id', 'organization_id'] as $key) {
            if (array_key_exists($key, $input) && $this->positiveInt($input[$key]) === null) {
                return ['error' => $key.' must be a positive integer'];
            }
        }
        $clientId ??= $this->positiveInt($input['client_id'] ?? null);
        $org = $this->scopedOrgId($input, $clientId);
        if (is_array($org)) {
            return $org;
        }
        $mapped = $this->mappedClientsByOrgId();
        if ($org !== null && ! $mapped->has($org)) {
            return ['error' => 'Organization is not mapped to a PSA client'];
        }
        $parent = $this->positiveInt($input['incident_report_id'] ?? null);
        $child = $this->positiveInt($input['remediation_id'] ?? null);
        if ($parent === null || ($get && $child === null)) {
            return ['error' => 'incident_report_id and, for a get, remediation_id must be positive integers'];
        }
        $params = $get ? [] : $this->pageParams($input, default: 10, max: 500);
        if (! $get) {
            foreach (['sort_field', 'sort_direction'] as $filter) {
                if (isset($input[$filter])) {
                    if (! is_string($input[$filter])) {
                        return ['error' => $filter.' must be a string'];
                    }
                    $params[$filter] = $input[$filter];
                }
            }
            if ($family === 'remediations') {
                foreach (self::REMEDIATION_FILTERS as $filter => $enum) {
                    if (array_key_exists($filter, $input)) {
                        if (! is_array($input[$filter]) || ! array_is_list($input[$filter])) {
                            return ['error' => $filter.' must be an array'];
                        }
                        foreach ($input[$filter] as $value) {
                            if (! in_array($value, $enum, true)) {
                                return ['error' => 'Invalid '.$filter.' value'];
                            }
                        }
                        // Vendor description says comma-separated; literal bracket names per Forge ruling.
                        $params[$filter.'[]'] = implode(',', $input[$filter]);
                    }
                }
            }
        }
        try {
            // Children carry no org. Prove the parent before sending any child request.
            $report = $this->client()->getIncidentReport($parent);
            $parentOrg = $report['organization_id'] ?? null;
            if (! is_int($parentOrg) || ! $mapped->has($parentOrg) || ($org !== null && $parentOrg !== $org)) {
                return ['error' => 'Parent incident organization is outside the mapped scope'];
            }
            $response = $this->client()->get('incident_reports/'.$parent.'/'.$family.($get ? '/'.$child : ''), $params);
        } catch (HuntressClientException $e) {
            return $this->expandedApiError($e);
        }
        $rows = $get ? [$response['remediation'] ?? null] : ($response[$family] ?? null);
        if (! is_array($rows) || ! array_is_list($rows)) {
            return ['error' => 'Huntress returned an unrecognized collection shape'];
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null)) {
                return ['error' => 'Huntress returned an unrecognized incident detail shape'];
            }
        }
        $rows = $this->sanitizeStructure('Huntress incident detail', $rows);

        return $get ? ['remediation' => $rows[0]] : ['count' => count($rows), $family => $rows, 'next_page_token' => $this->nextPageToken($response)];
    }

    private function siemQuery(array $input, ?int $clientId): array
    {
        $clientId ??= $this->positiveInt($input['client_id'] ?? null);
        if ($clientId === null) {
            return ['error' => 'client_id is required'];
        }
        $org = $this->scopedOrgId($input, $clientId);
        if (is_array($org)) {
            return $org;
        }
        if (! is_string($input['esql'] ?? null)) {
            return ['error' => 'esql is required'];
        }
        $query = trim($input['esql']);
        // Reject comments before parsing: they can hide source and pipeline boundaries.
        if (str_contains($query, '//') || str_contains($query, '/*')) {
            return ['error' => 'ESQL comments are refused'];
        }
        if (! preg_match('/^FROM\s+logs\b(.*)$/is', $query, $matches)) {
            return ['error' => 'ESQL must begin with FROM logs'];
        }
        $rest = trim($matches[1]);
        if ($rest !== '' && ! str_starts_with($rest, '|')) {
            return ['error' => 'FROM logs must be followed by a pipeline or nothing'];
        }
        if (preg_match('/\b(FROM|ENRICH|LOOKUP|JOIN)\b/i', $rest, $forbidden)) {
            return ['error' => 'ESQL token refused: '.strtoupper($forbidden[1])];
        }
        try {
            $start = $this->isoTimestamp($input['range_start'] ?? null);
            $end = $this->isoTimestamp($input['range_end'] ?? null);
        } catch (\Throwable) {
            return ['error' => 'range_start and range_end must be ISO 8601 timestamps'];
        }
        if ($start >= $end) {
            return ['error' => 'range_start must be before range_end'];
        }
        $sent = 'FROM logs | WHERE organization_id == '.$org.($rest === '' ? '' : ' '.$rest);
        $body = ['esql' => $sent, 'range_start' => $input['range_start'], 'range_end' => $input['range_end']];
        if (isset($input['page_token'])) {
            if (! is_string($input['page_token'])) {
                return ['error' => 'page_token must be a string'];
            }
            $body['page_token'] = $input['page_token'];
        }
        try {
            $response = $this->client()->post('siem/query', $body);
        } catch (HuntressClientException $e) {
            return $this->expandedApiError($e, true);
        }
        // Vendor SiemQueryResult: logs list, pagination object; ECS dotted keys stay intact.
        if (! isset($response['logs'], $response['pagination']) || ! is_array($response['logs'])
            || ! array_is_list($response['logs']) || ! is_array($response['pagination'])) {
            return ['error' => 'Huntress SIEM returned an unrecognized response shape'];
        }
        $logs = [];
        foreach ($response['logs'] as $row) {
            if (! is_array($row)) {
                return ['error' => 'Huntress SIEM returned an unrecognized row shape'];
            }
            // The injected first-stage WHERE is the fence. STATS/DROP may remove
            // organization_id; this row-level backstop applies only when it survives.
            if (array_key_exists('organization_id', $row) && $row['organization_id'] !== $org) {
                return ['error' => 'Huntress SIEM returned a row outside the requested organization'];
            }
            $logs[] = $this->sanitizeStructure('Huntress SIEM log', $row);
        }
        $token = $response['pagination']['next_page_token'] ?? null;
        if ($token !== null && ! is_string($token)) {
            return ['error' => 'Huntress SIEM returned an unrecognized pagination cursor'];
        }
        $result = ['count' => count($logs), 'logs' => $logs, 'next_page_token' => $this->nextPageToken($response), 'esql_sent' => $sent];
        if ($logs === []) {
            $result['note'] = '0 rows matched this organization and range. A misconfigured or absent log source also yields 0; check huntress_list_siem_log_sources. This is not an all-clear.';
        }

        return $result;
    }

    private function isoTimestamp(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new \InvalidArgumentException('ISO 8601 timestamp required');
        }

        if (! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            || (int) substr($value, 11, 2) > 23 || (int) substr($value, 14, 2) > 59
            || (int) substr($value, 17, 2) > 59) {
            throw new \InvalidArgumentException('Invalid calendar timestamp');
        }

        return CarbonImmutable::parse($value);
    }

    private function expandedApiError(HuntressClientException $e, bool $siem = false): array
    {
        $previous = $e->getPrevious();
        $response = $previous instanceof RequestException ? $previous->getResponse() : null;
        $status = $response?->getStatusCode() ?? $e->getCode();
        $body = $response ? json_decode((string) $response->getBody(), true) : null;
        $detail = $e::class;
        if (is_array($body) && is_string($body['error'] ?? null)) {
            // Redact before truncation, so a truncated credential cannot escape matching.
            $safe = preg_replace([
                '~https?://[^\s<>"\']+~i',
                '~\bAuthorization\s*:\s*[^\r\n]+~i',
                '~\b(?:api[_ -]?key|api[_ -]?secret|token|secret|key)\s*[:=]\s*[^\s,;]+~i',
                '~\b[A-Za-z0-9_+/=-]{32,}\b~',
            ], '[redacted]', $body['error']);
            $detail = $this->textSanitizer->sanitizeNullable('Huntress API error', $safe, 300);
        }
        $error = 'Huntress HTTP '.$status.': '.$detail;
        if ($status === 403) {
            $error .= "; the Huntress API key in Settings lacks access to this endpoint; Charlie can widen the key's scope";
        }
        if ($status === 404 && $siem) {
            $error .= '; SIEM query is not enabled for this account';
        }

        return ['error' => $error];
    }
}
