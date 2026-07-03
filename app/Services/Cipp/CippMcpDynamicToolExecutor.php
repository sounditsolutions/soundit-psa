<?php

namespace App\Services\Cipp;

use App\Models\CippMcpTool;
use App\Models\Client;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\CippConfig;
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
