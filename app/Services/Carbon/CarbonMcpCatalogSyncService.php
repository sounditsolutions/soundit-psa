<?php

namespace App\Services\Carbon;

use App\Models\CarbonMcpTool;
use App\Models\Setting;
use App\Services\Cipp\CippMcpCatalogSyncResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sync the Carbon Connect MCP catalogue into carbon_mcp_tools (card PEYvh4NL),
 * the CippMcpCatalogSyncService pattern: every advertised tool is added or
 * updated (schema, description, annotations, classification), and an active
 * row Carbon no longer advertises is deactivated, never deleted, so a token's
 * stored grant of it simply stops resolving.
 *
 * Nothing is filtered by name: Charlie's ruling (08:57 PT 10/21) is that every
 * tool Carbon publishes is integrated and the token grants decide who calls it.
 */
class CarbonMcpCatalogSyncService
{
    public function __construct(private readonly CarbonMcpClient $client) {}

    public function sync(?CarbonMcpClient $client = null): CippMcpCatalogSyncResult
    {
        $tools = ($client ?? $this->client)->listTools();
        $rows = $this->catalogRows($tools);

        $result = DB::transaction(function () use ($tools, $rows): CippMcpCatalogSyncResult {
            $now = now();
            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                $existing = CarbonMcpTool::query()->where('upstream_name', $row['upstream_name'])->first();
                if ($existing === null) {
                    CarbonMcpTool::create($row + ['active' => true, 'last_seen_at' => $now]);
                    $created++;

                    continue;
                }

                $existing->fill($row + ['active' => true, 'last_seen_at' => $now]);
                if ($existing->isDirty(array_merge(array_keys($row), ['active']))) {
                    $updated++;
                }
                $existing->save();
            }

            $activeUpstream = array_column($rows, 'upstream_name');
            $deactivated = CarbonMcpTool::query()
                ->where('active', true)
                ->when($activeUpstream !== [], fn ($q) => $q->whereNotIn('upstream_name', $activeUpstream))
                ->update(['active' => false]);

            return new CippMcpCatalogSyncResult(
                seen: count($tools),
                active: count($rows),
                created: $created,
                updated: $updated,
                deactivated: (int) $deactivated,
            );
        });

        // Deactivation went through a bulk update, which fires no model events.
        \App\Support\McpToolRegistry::flushMemoized();

        $summary = self::summary($result);
        Setting::setValue(CarbonMcpConnector::LAST_SYNC_AT, now()->toIso8601String());
        Setting::setValue(CarbonMcpConnector::LAST_SYNC_SUMMARY, $summary);

        return $result;
    }

    public static function summary(CippMcpCatalogSyncResult $r): string
    {
        return "Carbon MCP catalogue sync complete: {$r->seen} seen, {$r->active} active, {$r->created} created, {$r->updated} updated, {$r->deactivated} deactivated.";
    }

    /** carbon_ + the upstream name in snake_case. */
    public static function localNameFor(string $upstreamName): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '_', trim($upstreamName)) ?? '';
        $name = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name) ?? $name;
        $name = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name) ?? $name;
        $name = mb_strtolower(trim($name, '_'));

        return CarbonMcpTool::NAME_PREFIX.$name;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function catalogRows(array $tools): array
    {
        $rows = [];
        $byLocal = [];

        foreach ($tools as $tool) {
            $upstream = trim((string) ($tool['name'] ?? ''));
            if ($upstream === '' || mb_strlen($upstream) > 200) {
                Log::warning('[CarbonMcpCatalogSync] Skipped a tool with no usable name', ['length' => mb_strlen($upstream)]);

                continue;
            }

            $local = self::localNameFor($upstream);
            if ($local === CarbonMcpTool::NAME_PREFIX) {
                Log::warning('[CarbonMcpCatalogSync] Skipped a tool whose name has no letters or digits', ['upstream_name' => $upstream]);

                continue;
            }
            if (isset($byLocal[$local]) && $byLocal[$local] !== $upstream) {
                throw new CarbonMcpException("Carbon MCP catalogue local-name collision: {$local} maps to {$byLocal[$local]} and {$upstream}; catalogue not changed.");
            }
            $byLocal[$local] = $upstream;

            $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
            $schema = $tool['inputSchema'] ?? null;
            $title = $tool['title'] ?? ($annotations['title'] ?? null);

            $rows[] = [
                'local_name' => $local,
                'upstream_name' => $upstream,
                'title' => is_string($title) && $title !== '' ? mb_substr($title, 0, 255) : null,
                'description' => is_string($tool['description'] ?? null) ? mb_substr(trim($tool['description']), 0, 4000) : null,
                'input_schema' => is_array($schema) ? $schema : ['type' => 'object', 'properties' => []],
                'annotations' => $annotations,
            ] + CarbonMcpTool::classify($annotations);
        }

        $conflict = CarbonMcpTool::query()
            ->whereIn('local_name', array_keys($byLocal))
            ->get(['local_name', 'upstream_name'])
            ->first(fn (CarbonMcpTool $t): bool => $byLocal[$t->local_name] !== $t->upstream_name);
        if ($conflict !== null) {
            throw new CarbonMcpException("Carbon MCP catalogue local-name collision: {$conflict->local_name} already maps to {$conflict->upstream_name}; catalogue not changed.");
        }

        return $rows;
    }
}
