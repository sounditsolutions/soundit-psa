<?php

namespace App\Services\Huntress;

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\RequestException;

/** Additional read-only operations; shares the existing mapping and sanitizer seams. */
trait HuntressExpandedReads
{
    private static function expandedDefinitions(): array
    {
        return [...self::signalDefinitions(), [
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

    private function signalRead(array $input, ?int $clientId, bool $get): array
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
        $params = [];
        $endpoint = 'signals';
        if ($get) {
            $id = $this->positiveInt($input['id'] ?? null);
            if ($id === null) {
                return ['error' => 'id must be a positive integer'];
            }
            $endpoint .= '/'.$id;
        } else {
            $params = $this->pageParams($input, default: 10, max: 500);
            foreach (['sort_field', 'sort_direction', 'investigated_at_min', 'investigated_at_max', 'entity_type', 'entity_id', 'types', 'statuses'] as $filter) {
                if (isset($input[$filter])) {
                    if (! is_string($input[$filter])) {
                        return ['error' => $filter.' must be a string'];
                    }
                    $params[$filter] = $input[$filter];
                }
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
        // Vendor Signal schema uses organization: {id, name}, not organization_id.
        $rows = $get ? [$response['signal'] ?? null] : ($response['signals'] ?? null);
        if (! is_array($rows) || ! array_is_list($rows)) {
            return ['error' => 'Huntress returned an unrecognized signals shape'];
        }
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['organization']['id'] ?? null)) {
                return ['error' => 'Huntress returned an unrecognized signal organization'];
            }
            $rowOrg = $row['organization']['id'];
            if ($org !== null && $rowOrg !== $org) {
                return ['error' => 'Huntress returned a signal outside the requested organization'];
            }
            if (! $mapped->has($rowOrg)) {
                if ($get) {
                    return ['error' => 'Signal organization is not mapped to a PSA client'];
                }

                continue;
            }
            $result[] = $this->sanitizeStructure('Huntress signal', $row);
        }

        return $get ? ['signal' => $result[0]] : ['count' => count($result), 'signals' => $result, 'next_page_token' => $this->nextPageToken($response)];
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
