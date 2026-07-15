<?php

namespace App\Services\Cipp;

use App\Enums\ToolingGapClassification;
use App\Enums\ToolingGapSource;
use App\Models\CippMcpTool;
use App\Models\Client;
use App\Models\ToolingGap;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\CippConfig;
use App\Support\CippMcpToolPolicy;
use Illuminate\Support\Facades\Log;

class CippMcpDynamicToolExecutor
{
    private const MAX_ITEMS = 20;

    private const MAX_KEYS = 20;

    private const MAX_BODY_BYTES = 12000;

    private const MAX_STRING_CHARS = 12000;

    private const MAX_NESTED_ARRAY_DEPTH = 8;

    public function __construct(
        private readonly CippMcpClient $client,
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int|string, mixed> */
    public function execute(string $toolName, array $input, ?Client $client, ?int $clientId): array
    {
        $tool = CippMcpTool::query()->active()->where('local_name', $toolName)->first();
        if (! $tool) {
            return ['error' => "Unknown CIPP MCP catalog tool: {$toolName}"];
        }

        // The active() scope already excludes these, so reaching here means the row was
        // fetched some other way. Refuse anyway and say so loudly: this executor is a raw
        // passthrough, and the rows the policy forbids are exactly the ones where a
        // passthrough is a disclosure (psa-7lgo.7).
        $refusal = CippMcpToolPolicy::refusalReason($tool->local_name, $tool->upstream_name);
        if ($refusal !== null) {
            Log::warning('[CippMcpDynamicToolExecutor] Refused a forbidden dynamic CIPP tool', [
                'tool' => $toolName,
                'upstream_tool' => $tool->upstream_name,
                'reason' => $refusal,
            ]);

            return ['error' => "Refused CIPP MCP catalog tool ({$refusal}): {$toolName}"];
        }

        // No allow-list check here. psa-3g8y refused any upstream off
        // APPROVED_DYNAMIC_UPSTREAM_TOOLS at this point; reaching this executor now means
        // McpStaffController::toolAllowed() has already confirmed the calling token
        // EXPLICITLY grants this exact tool, and that deliberate operator grant is the
        // approval (psa-pzwv). The refusal above is the part that survives: BLOCKED and
        // curated-name collisions are danger, not un-review, so no grant buys them back.
        if (! $tool->read_only || $tool->sensitive) {
            return ['error' => 'CIPP MCP write-class catalog tools are not enabled for execution.'];
        }

        if (! CippConfig::isMcpRelayEnabled()) {
            return ['error' => 'CIPP MCP relay is not enabled or configured.'];
        }

        $tenantDomain = $client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $graphAttempt = $this->genericGraphAttempt($tool, $input);
        if ($graphAttempt !== null) {
            $this->recordGenericGraphAttempt($tool, $input, $clientId, $graphAttempt);

            if ($graphAttempt['invalid_requests'] || $graphAttempt['uninspectable_methods']) {
                return ['error' => 'CIPP generic Graph passthrough read tools only permit inspectable GET request payloads.'];
            }

            if ($graphAttempt['non_get_methods'] !== []) {
                return ['error' => 'CIPP generic Graph passthrough read tools only permit GET requests. Requested method(s): '.implode(', ', $graphAttempt['non_get_methods'])];
            }
        }

        $unknownArguments = $this->unknownArguments($tool, $input);
        if ($unknownArguments !== []) {
            return ['error' => 'Unsupported CIPP MCP argument(s): '.implode(', ', $unknownArguments)];
        }

        $missingArguments = $this->missingArguments($tool, $input);
        if ($missingArguments !== []) {
            return ['error' => 'Missing CIPP MCP argument(s): '.implode(', ', $missingArguments)];
        }

        $arguments = $input;
        unset($arguments['client_id']);
        $arguments['tenantFilter'] = $tenantDomain;

        try {
            $rows = $this->client->callTool($tool->upstream_name, $arguments);
        } catch (\Throwable $e) {
            Log::warning('[CippMcpDynamicToolExecutor] CIPP MCP catalog query failed', [
                'tool' => $toolName,
                'upstream_tool' => $tool->upstream_name,
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'CIPP catalog query failed: '.$this->textSanitizer->sanitize(
                    'CIPP catalog query error',
                    mb_substr($e->getMessage(), 0, 200),
                    200,
                ),
            ];
        }

        return $this->referenceOnlyResult($tool, $this->normalizeRows($rows), $clientId, $this->listProperties($input));
    }

    /** @return array<int, string> */
    private function unknownArguments(CippMcpTool $tool, array $input): array
    {
        $properties = $tool->publicInputSchema()['properties'] ?? [];
        $allowed = array_fill_keys(array_merge(
            ['client_id', 'ListProperties', 'listProperties'],
            array_keys((array) $properties),
        ), true);

        return array_values(array_filter(
            array_keys($input),
            fn (string $key): bool => ! isset($allowed[$key]),
        ));
    }

    /** @return array<int, string> */
    private function missingArguments(CippMcpTool $tool, array $input): array
    {
        $required = (array) ($tool->publicInputSchema()['required'] ?? []);

        return array_values(array_filter(
            $required,
            fn (mixed $key): bool => is_string($key)
                && $key !== 'client_id'
                && ! array_key_exists($key, $input),
        ));
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        if (array_is_list($rows)) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    return [['value' => $rows]];
                }
            }

            return $rows;
        }

        foreach (['Results', 'results', 'value', 'Value'] as $key) {
            if (isset($rows[$key]) && is_array($rows[$key])) {
                return $this->normalizeRows($rows[$key]);
            }
        }

        return [$rows];
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function referenceOnlyResult(CippMcpTool $tool, array $rows, ?int $clientId, ?array $listProperties): array
    {
        $items = [];
        foreach (array_slice($rows, 0, self::MAX_ITEMS) as $index => $row) {
            $items[] = $this->referenceItem($tool, $row, $index, $listProperties);
        }

        return [
            'tool' => $tool->local_name,
            'upstream_tool' => $tool->upstream_name,
            'client_id' => $clientId,
            'reference' => 'cippmcp_'.substr(sha1($tool->local_name.'|'.$clientId.'|'.json_encode($rows)), 0, 16),
            'summary' => [
                'count' => count($rows),
                'returned' => count($items),
                'truncated' => count($rows) > count($items),
            ],
            'items' => $items,
        ];
    }

    /** @param  array<string, mixed>  $row */
    private function referenceItem(CippMcpTool $tool, array $row, int $index, ?array $listProperties): array
    {
        $bodySource = $listProperties === null ? $row : $this->selectListProperties($row, $listProperties);
        $referenceSource = $listProperties === null ? $row : $bodySource;
        $item = [
            'index' => $index,
            'id' => $this->firstScalar($referenceSource, ['id', 'Id', 'ID', 'objectId', 'ObjectId', 'userId', 'UserId', 'deviceId', 'DeviceId']),
            'display' => $this->displayValue($tool, $referenceSource),
            'keys' => $this->safeKeys($bodySource),
            'body' => $this->bodyForRow($tool, $bodySource),
        ];

        return array_filter($item, fn (mixed $value, string $key): bool => $key === 'body' || ($value !== null && $value !== []), ARRAY_FILTER_USE_BOTH);
    }

    /** @param  array<string, mixed>  $row */
    private function displayValue(CippMcpTool $tool, array $row): ?string
    {
        $value = $this->firstScalar($row, [
            'displayName',
            'DisplayName',
            'name',
            'Name',
            'userPrincipalName',
            'UserPrincipalName',
            'UPN',
            'upn',
            'deviceName',
            'DeviceName',
            'subject',
            'Subject',
        ]);

        if ($value === null) {
            return null;
        }

        return $this->textSanitizer->sanitize(
            'CIPP '.str_replace('_', ' ', preg_replace('/^cipp_/', '', $tool->local_name) ?? $tool->local_name).' display',
            $value,
            200,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private function firstScalar(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
                return mb_substr((string) $row[$key], 0, 200);
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $row */
    private function safeKeys(array $row): array
    {
        $keys = [];
        foreach (array_keys($row) as $key) {
            if ($this->safeKey($key)) {
                $keys[] = $key;
            }
        }

        return array_values(array_slice($keys, 0, self::MAX_KEYS));
    }

    private function safeKey(string $key): bool
    {
        if ($this->deniedValueKey($key)) {
            return false;
        }

        return preg_match('/id|display|name|mail|upn|type|status|state|date|time|count|sku|enabled/i', $key) === 1;
    }

    /**
     * @return array{
     *     invalid_requests: bool,
     *     uninspectable_methods: bool,
     *     non_get_methods: array<int, string>,
     *     methods: array<int, string>,
     *     request_endpoints: array<int, string>,
     *     request_count: int
     * }|null
     */
    private function genericGraphAttempt(CippMcpTool $tool, array $input): ?array
    {
        if (! $this->isGenericGraphTool($tool)) {
            return null;
        }

        $methodInspection = $this->methodValues($input);
        $methods = $methodInspection['methods'];
        $uninspectableMethods = $methodInspection['uninspectable'];
        $requestEndpoints = [];
        $requestCount = 0;
        $invalidRequests = false;

        if ($this->isGraphBulkTool($tool) && array_key_exists('requests', $input)) {
            $decoded = $this->decodeBulkRequests($input['requests']);
            if ($decoded === null) {
                $invalidRequests = true;
            } else {
                $requestCount = count($decoded);
                foreach ($decoded as $request) {
                    $requestMethodInspection = $this->methodValues($request);
                    $methods = array_merge($methods, $requestMethodInspection['methods']);
                    $uninspectableMethods = $uninspectableMethods || $requestMethodInspection['uninspectable'];
                    $endpoint = $this->firstTelemetryValue($request, ['url', 'URL', 'endpoint', 'Endpoint', 'uri', 'URI', 'resource', 'Resource']);
                    if ($endpoint !== null) {
                        $requestEndpoints[] = $endpoint;
                    }
                }
            }
        }

        $methods = array_values(array_unique(array_filter(array_map(
            fn (string $method): string => mb_strtoupper(trim($method)),
            $methods,
        ), fn (string $method): bool => $method !== '')));

        $nonGetMethods = array_values(array_filter($methods, fn (string $method): bool => $method !== 'GET'));

        return [
            'invalid_requests' => $invalidRequests,
            'uninspectable_methods' => $uninspectableMethods,
            'non_get_methods' => $nonGetMethods,
            'methods' => $methods,
            'request_endpoints' => array_values(array_unique($requestEndpoints)),
            'request_count' => $requestCount,
        ];
    }

    private function isGenericGraphTool(CippMcpTool $tool): bool
    {
        $local = mb_strtolower($tool->local_name);
        $upstream = mb_strtolower($tool->upstream_name);

        return in_array($upstream, ['listgraphrequest', 'listgraphbulkrequest'], true)
            || in_array($local, ['cipp_list_graph_request', 'cipp_list_graph_bulk_request', 'cipp_graph_bulk'], true);
    }

    private function isGraphBulkTool(CippMcpTool $tool): bool
    {
        $local = mb_strtolower($tool->local_name);
        $upstream = mb_strtolower($tool->upstream_name);

        return $upstream === 'listgraphbulkrequest'
            || in_array($local, ['cipp_list_graph_bulk_request', 'cipp_graph_bulk'], true);
    }

    /** @return array{methods: array<int, string>, uninspectable: bool} */
    private function methodValues(array $input): array
    {
        $methods = [];
        $uninspectable = false;

        foreach (['method', 'Method', 'type', 'Type'] as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = $this->scalarOrLabelValue($input[$key]);
            if ($value !== null && trim($value) !== '') {
                $methods[] = $value;

                continue;
            }

            $uninspectable = true;
        }

        return [
            'methods' => $methods,
            'uninspectable' => $uninspectable,
        ];
    }

    /** @return array<int, array<string, mixed>>|null */
    private function decodeBulkRequests(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return [];
            }

            $decoded = json_decode($value, true);
            if (! is_array($decoded)) {
                return null;
            }

            $value = $decoded;
        }

        if (! is_array($value)) {
            return null;
        }

        if (isset($value['requests']) && is_array($value['requests'])) {
            $value = $value['requests'];
        }

        if (! array_is_list($value)) {
            return $this->arrayHasStringKeys($value) ? [$value] : [];
        }

        $requests = [];
        foreach ($value as $request) {
            if (! is_array($request)) {
                return null;
            }

            $requests[] = $request;
        }

        return $requests;
    }

    private function arrayHasStringKeys(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (is_string($key)) {
                return true;
            }
        }

        return false;
    }

    private function recordGenericGraphAttempt(CippMcpTool $tool, array $input, ?int $clientId, array $attempt): void
    {
        try {
            ToolingGap::record(
                ticketId: null,
                clientId: $clientId,
                capabilityGap: 'Build typed CIPP Graph MCP tools for common generic passthrough requests.',
                evidence: json_encode($this->genericGraphEvidence($tool, $input, $attempt), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                classification: ToolingGapClassification::ToolMissing,
                source: ToolingGapSource::Agent,
                agentNote: 'Captured generic CIPP Graph passthrough attempt for typed-tool planning.',
            );
        } catch (\Throwable $e) {
            Log::warning('[CippMcpDynamicToolExecutor] Failed to record generic Graph passthrough telemetry', [
                'tool' => $tool->local_name,
                'upstream_tool' => $tool->upstream_name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function genericGraphEvidence(CippMcpTool $tool, array $input, array $attempt): array
    {
        return array_filter([
            'tool' => $tool->local_name,
            'upstream_tool' => $tool->upstream_name,
            'outcome' => $this->genericGraphOutcome($attempt),
            'methods' => $attempt['methods'],
            'endpoint' => $this->telemetryString('CIPP Graph endpoint', $this->firstTelemetryValue($input, ['Endpoint', 'endpoint', 'url', 'URL', 'uri', 'URI', 'resource', 'Resource'])),
            'request_endpoints' => $this->telemetryStringList('CIPP Graph request endpoint', $attempt['request_endpoints']),
            'params' => $this->telemetryParams($input, $attempt),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function genericGraphOutcome(array $attempt): string
    {
        if ($attempt['invalid_requests'] || $attempt['uninspectable_methods']) {
            return 'blocked_uninspectable';
        }

        return $attempt['non_get_methods'] !== [] ? 'blocked_non_get' : 'allowed';
    }

    /** @return array<string, mixed> */
    private function telemetryParams(array $input, array $attempt): array
    {
        $params = [];

        foreach ($input as $key => $value) {
            $stringKey = is_string($key) ? $key : (string) $key;
            if (in_array($stringKey, ['client_id', 'tenantFilter', 'TenantFilter', 'tenant', 'Tenant'], true)
                || $this->deniedTelemetryKey($stringKey)) {
                continue;
            }

            if ($stringKey === 'requests') {
                $params[$stringKey] = [
                    'count' => $attempt['request_count'],
                    'methods' => $attempt['methods'],
                    'endpoints' => $this->telemetryStringList('CIPP Graph request endpoint', $attempt['request_endpoints']),
                ];

                continue;
            }

            $safeValue = $this->telemetryValue($value);
            if ($safeValue !== null) {
                $params[$stringKey] = $safeValue;
            }
        }

        return $params;
    }

    private function telemetryValue(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value) || is_numeric($value)) {
            return $this->telemetryString('CIPP Graph parameter', $value);
        }

        if (is_bool($value) || $value === null) {
            return $value;
        }

        if (! is_array($value) || $depth >= 3) {
            return null;
        }

        $safe = [];
        foreach (array_slice($value, 0, 10, preserve_keys: true) as $key => $item) {
            $stringKey = is_string($key) ? $key : (string) $key;
            if ($this->deniedTelemetryKey($stringKey)) {
                continue;
            }

            $safeValue = $this->telemetryValue($item, $depth + 1);
            if ($safeValue !== null) {
                $safe[$key] = $safeValue;
            }
        }

        return $safe;
    }

    /** @param  array<int, string>  $values */
    private function telemetryStringList(string $label, array $values): array
    {
        return array_values(array_filter(array_map(
            fn (string $value): ?string => $this->telemetryString($label, $value),
            $values,
        ), fn (?string $value): bool => $value !== null));
    }

    private function telemetryString(string $label, mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = $this->redactTelemetryCredentials($text);
        $sanitized = $this->textSanitizer->sanitize($label, $text, 500);

        return $this->unwrapSanitizedText($sanitized);
    }

    private function redactTelemetryCredentials(string $text): string
    {
        $text = preg_replace(
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i',
            '$1 [REDACTED:credential]',
            $text,
        ) ?? $text;

        $text = preg_replace(
            '/([?&;]\s*(?:access[_-]?token|auth[_-]?token|refresh[_-]?token|id[_-]?token|api[_-]?key|client[_-]?secret|secret|password|passwd|pwd|token|key)=)[^&;\s]+/i',
            '$1[REDACTED:credential]',
            $text,
        ) ?? $text;

        return preg_replace(
            '/\b(authorization|cookie|set-cookie)\s*[:=]\s*[^\s;&]+/i',
            '$1=[REDACTED:credential]',
            $text,
        ) ?? $text;
    }

    private function unwrapSanitizedText(string $sanitized): string
    {
        $lines = explode("\n", $sanitized);
        if (count($lines) >= 3
            && str_starts_with($lines[0], '=== UNTRUSTED ')
            && str_starts_with($lines[count($lines) - 1], '=== END UNTRUSTED ')) {
            array_shift($lines);
            array_pop($lines);

            return implode("\n", $lines);
        }

        return $sanitized;
    }

    private function deniedTelemetryKey(string $key): bool
    {
        return $this->deniedValueKey($key)
            || preg_match('/authorization|cookie|session|api[_-]?key|access[_-]?token|auth[_-]?token|refresh[_-]?token|id[_-]?token|client[_-]?secret/i', $key) === 1;
    }

    private function firstTelemetryValue(array $input, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = $this->scalarOrLabelValue($input[$key]);
            if ($value !== null && trim($value) !== '') {
                return mb_substr(trim($value), 0, 500);
            }
        }

        return null;
    }

    private function scalarOrLabelValue(mixed $value): ?string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            foreach (['value', 'Value', 'label', 'Label'] as $key) {
                if (array_key_exists($key, $value) && is_scalar($value[$key])) {
                    return trim((string) $value[$key]);
                }
            }
        }

        return null;
    }

    /** @return array<int, string>|null */
    private function listProperties(array $input): ?array
    {
        $value = $input['ListProperties'] ?? $input['listProperties'] ?? null;
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return null;
        }

        $properties = [];
        foreach ($value as $property) {
            if (! is_string($property) && ! is_numeric($property)) {
                continue;
            }

            $property = trim((string) $property);
            if ($property !== '') {
                $properties[$property] = $property;
            }
        }

        return $properties === [] ? null : array_values(array_slice($properties, 0, 100));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $listProperties
     * @return array<string, mixed>
     */
    private function selectListProperties(array $row, array $listProperties): array
    {
        $selected = [];

        foreach ($listProperties as $property) {
            $key = $this->matchingRowKey($row, $property);
            if ($key !== null) {
                $selected[$key] = $row[$key];
            }
        }

        return $selected;
    }

    /** @param  array<string, mixed>  $row */
    private function matchingRowKey(array $row, string $property): ?string
    {
        if (array_key_exists($property, $row)) {
            return $property;
        }

        foreach (array_keys($row) as $key) {
            if (strcasecmp($key, $property) === 0) {
                return $key;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $row */
    private function bodyForRow(CippMcpTool $tool, array $row): array
    {
        $body = $this->sanitizeBodyArray($tool, $row, '');

        return $this->capBody($body);
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return array<int|string, mixed>
     */
    private function sanitizeBodyArray(CippMcpTool $tool, array $value, string $path, int $depth = 0): array
    {
        $bounded = array_is_list($value)
            ? array_slice($value, 0, self::MAX_ITEMS, preserve_keys: true)
            : $value;
        $sanitized = [];

        foreach ($bounded as $key => $item) {
            $stringKey = is_string($key) ? $key : (string) $key;
            if ($this->deniedValueKey($stringKey)) {
                continue;
            }

            $fieldPath = $path === '' ? $stringKey : "{$path} {$stringKey}";
            if (is_array($item)) {
                $sanitized[$key] = $depth < self::MAX_NESTED_ARRAY_DEPTH
                    ? $this->sanitizeBodyArray($tool, $item, $fieldPath, $depth + 1)
                    : ['_truncated' => 'Nested array omitted'];

                continue;
            }

            $sanitized[$key] = $this->sanitizeBodyValue($tool, $fieldPath, $item);
        }

        return $sanitized;
    }

    private function sanitizeBodyValue(CippMcpTool $tool, string $field, mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->textSanitizer->sanitize($this->fieldLabel($tool, $field), $value, self::MAX_STRING_CHARS);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return null;
    }

    /** @param  array<int|string, mixed>  $body */
    private function capBody(array $body): array
    {
        if ($this->encodedLength($body) <= self::MAX_BODY_BYTES) {
            return $body;
        }

        $marker = 'Body capped to '.self::MAX_BODY_BYTES.' bytes';
        $capped = [];

        foreach ($body as $key => $value) {
            if ($key === '_truncated') {
                continue;
            }

            $candidate = $capped;
            $candidate[$key] = $this->capBodyValue($value);
            $candidate['_truncated'] = $marker;

            if ($this->encodedLength($candidate) <= self::MAX_BODY_BYTES) {
                $capped[$key] = $candidate[$key];

                continue;
            }

            if (is_string($value)) {
                $trimmed = $this->trimStringForBody($value, $capped, $key, $marker);
                if ($trimmed !== null) {
                    $capped[$key] = $trimmed;
                }
            }

            break;
        }

        $capped['_truncated'] = $marker;

        while ($this->encodedLength($capped) > self::MAX_BODY_BYTES && count($capped) > 1) {
            $keys = array_keys($capped);
            $removeKey = $keys[count($keys) - 2] ?? null;
            if ($removeKey === null) {
                break;
            }

            unset($capped[$removeKey]);
        }

        return $capped;
    }

    private function capBodyValue(mixed $value): mixed
    {
        if (is_array($value) && $this->encodedLength($value) > self::MAX_BODY_BYTES / 2) {
            return ['_truncated' => 'Value omitted by body size cap'];
        }

        return $value;
    }

    /** @param  array<int|string, mixed>  $capped */
    private function trimStringForBody(string $value, array $capped, int|string $key, string $marker): ?string
    {
        $available = self::MAX_BODY_BYTES - $this->encodedLength(array_merge($capped, ['_truncated' => $marker])) - 100;
        if ($available < 100) {
            return null;
        }

        $trimmed = mb_substr($value, 0, $available);
        $candidate = $capped;
        $candidate[$key] = $trimmed;
        $candidate['_truncated'] = $marker;

        while ($this->encodedLength($candidate) > self::MAX_BODY_BYTES && mb_strlen($trimmed) > 0) {
            $trimmed = mb_substr($trimmed, 0, -100);
            $candidate[$key] = $trimmed;
        }

        return mb_strlen($trimmed) > 0 ? $trimmed : null;
    }

    private function encodedLength(mixed $value): int
    {
        return strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function deniedValueKey(string $key): bool
    {
        return preg_match('/token|secret|password|phone|mobile|address|body|content/i', $key) === 1;
    }

    private function fieldLabel(CippMcpTool $tool, string $field): string
    {
        $toolName = str_replace('_', ' ', preg_replace('/^cipp_/', '', $tool->local_name) ?? $tool->local_name);

        return "CIPP {$toolName} {$field}";
    }
}
