<?php

namespace App\Services\Cipp;

use App\Models\CippMcpTool;
use App\Support\CippMcpToolPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CippMcpCatalogSyncService
{
    private const CURATED_UPSTREAM_TOOLS = [
        'ListUsers',
        'ListMailboxes',
        'ListLicenses',
        'ListDevices',
        'ListSignIns',
        'ListGroups',
        'ListUserGroups',
        'ListmailboxPermissions',
        'ListUserMailboxRules',
        'ListDefenderState',
        'ListConditionalAccessPolicies',
        'ListUserConditionalAccessPolicies',
        'ListAuditLogs',
        'ListMessageTrace',
        'ListMailQuarantine',
        'ListMFAUsers',
        'ListOAuthApps',
    ];

    public function __construct(
        private readonly CippMcpClient $client,
    ) {}

    public function sync(?CippMcpClient $client = null): CippMcpCatalogSyncResult
    {
        $client ??= $this->client;
        $tools = $client->listTools();
        $seen = count($tools);
        $rows = $this->catalogRows($tools);

        $this->assertNoLocalNameCollisions($rows);

        return DB::transaction(function () use ($seen, $rows): CippMcpCatalogSyncResult {
            $now = now();
            $activeNames = array_column($rows, 'local_name');
            $created = 0;
            $updated = 0;

            $this->assertNoExistingLocalNameConflicts($rows);

            foreach ($rows as $row) {
                $tool = CippMcpTool::query()->where('upstream_name', $row['upstream_name'])->first();

                if (! $tool) {
                    CippMcpTool::create(array_merge($row, [
                        'active' => true,
                        'last_seen_at' => $now,
                    ]));
                    $created++;

                    continue;
                }

                $changes = array_merge($row, [
                    'active' => true,
                    'last_seen_at' => $now,
                ]);
                $tool->fill($changes);
                if ($tool->isDirty(array_merge(array_keys($row), ['active']))) {
                    $updated++;
                }
                $tool->save();
            }

            $deactivated = CippMcpTool::query()
                ->where('active', true)
                ->when($activeNames !== [], fn ($query) => $query->whereNotIn('local_name', $activeNames))
                ->update(['active' => false]);

            return new CippMcpCatalogSyncResult(
                seen: $seen,
                active: count($rows),
                created: $created,
                updated: $updated,
                deactivated: (int) $deactivated,
            );
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function catalogRows(array $tools): array
    {
        $rows = [];

        foreach ($tools as $tool) {
            $upstreamName = trim((string) ($tool['name'] ?? ''));
            if ($upstreamName === '' || in_array($upstreamName, self::CURATED_UPSTREAM_TOOLS, true)) {
                continue;
            }

            $localName = self::localNameFor($upstreamName);

            // The same policy the runtime enforces, applied here so an offending row is
            // never written in the first place. Skipped rather than thrown: refusing one
            // tool keeps the rest of the catalog syncing and the safe implementation live.
            // But it is logged loudly — it means CIPP has grown a tool that collides with
            // our surface, and somebody needs to look.
            $refusal = CippMcpToolPolicy::refusalReason($localName, $upstreamName);
            if ($refusal !== null) {
                Log::warning('[CippMcpCatalogSync] Refused a dynamic CIPP tool', [
                    'upstream_name' => $upstreamName,
                    'local_name' => $localName,
                    'reason' => $refusal,
                ]);

                continue;
            }

            $readOnly = ($tool['annotations']['readOnlyHint'] ?? null) === true;

            $rows[] = [
                'local_name' => $localName,
                'upstream_name' => $upstreamName,
                'category' => $this->categoryFor($tool),
                'description' => $this->descriptionFor($tool, $upstreamName),
                'input_schema' => $this->inputSchemaFor($tool),
                'annotations' => is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [],
                'read_only' => $readOnly,
                'sensitive' => ! $readOnly,
            ];
        }

        return $rows;
    }

    public static function localNameFor(string $upstreamName): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '_', trim($upstreamName)) ?? '';
        $name = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name) ?? $name;
        $name = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name) ?? $name;
        $name = mb_strtolower(trim($name, '_'));

        return 'cipp_'.$name;
    }

    /** @param  array<string, mixed>  $tool */
    private function categoryFor(array $tool): ?string
    {
        $category = trim((string) ($tool['category'] ?? ''));
        if ($category !== '') {
            return $category;
        }

        $description = (string) ($tool['description'] ?? '');
        if (preg_match('/^\[([^\]]+)\]/', $description, $matches) === 1) {
            return trim($matches[1]) ?: null;
        }

        return null;
    }

    /** @param  array<string, mixed>  $tool */
    private function descriptionFor(array $tool, string $upstreamName): string
    {
        $description = trim((string) ($tool['description'] ?? ''));

        return $description !== '' ? $description : "Run CIPP {$upstreamName}.";
    }

    /** @param  array<string, mixed>  $tool */
    private function inputSchemaFor(array $tool): array
    {
        $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? [];

        return is_array($schema)
            ? $schema
            : ['type' => 'object', 'properties' => []];
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function assertNoLocalNameCollisions(array $rows): void
    {
        $byLocalName = [];
        foreach ($rows as $row) {
            $localName = (string) $row['local_name'];
            $upstreamName = (string) $row['upstream_name'];
            if (isset($byLocalName[$localName]) && $byLocalName[$localName] !== $upstreamName) {
                throw new \RuntimeException("CIPP MCP catalog local-name collision: {$localName} maps to {$byLocalName[$localName]} and {$upstreamName}.");
            }

            $byLocalName[$localName] = $upstreamName;
        }
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function assertNoExistingLocalNameConflicts(array $rows): void
    {
        foreach ($rows as $row) {
            $existing = CippMcpTool::query()
                ->where('local_name', $row['local_name'])
                ->where('upstream_name', '!=', $row['upstream_name'])
                ->first();

            if ($existing) {
                throw new \RuntimeException("CIPP MCP catalog local-name collision: {$row['local_name']} already maps to {$existing->upstream_name}.");
            }
        }
    }
}
