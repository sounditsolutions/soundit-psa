<?php

namespace App\Services\Cipp;

use App\Models\CippMcpTool;
use App\Models\Client;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\CippConfig;
use Illuminate\Support\Facades\Log;

class CippMcpDynamicToolExecutor
{
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

        return $this->referenceOnlyResult($tool, $this->normalizeRows($rows), $clientId);
    }

    /** @return array<int, string> */
    private function unknownArguments(CippMcpTool $tool, array $input): array
    {
        $properties = $tool->publicInputSchema()['properties'] ?? [];
        $allowed = array_fill_keys(array_merge(['client_id'], array_keys((array) $properties)), true);

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

        return [$rows];
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function referenceOnlyResult(CippMcpTool $tool, array $rows, ?int $clientId): array
    {
        $items = [];
        foreach (array_slice($rows, 0, 20) as $index => $row) {
            $items[] = $this->referenceItem($tool, $row, $index);
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
    private function referenceItem(CippMcpTool $tool, array $row, int $index): array
    {
        return array_filter([
            'index' => $index,
            'id' => $this->firstScalar($row, ['id', 'Id', 'ID', 'objectId', 'ObjectId', 'userId', 'UserId', 'deviceId', 'DeviceId']),
            'display' => $this->displayValue($tool, $row),
            'keys' => $this->safeKeys($row),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
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

        return array_values(array_slice($keys, 0, 20));
    }

    private function safeKey(string $key): bool
    {
        if (preg_match('/token|secret|password|phone|mobile|address|body|content/i', $key) === 1) {
            return false;
        }

        return preg_match('/id|display|name|mail|upn|type|status|state|date|time|count|sku|enabled/i', $key) === 1;
    }
}
