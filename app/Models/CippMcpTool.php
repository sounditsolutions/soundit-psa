<?php

namespace App\Models;

use App\Support\CippMcpToolPolicy;
use App\Support\McpInputSchema;
use App\Support\McpToolRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CippMcpTool extends Model
{
    protected $fillable = [
        'local_name',
        'upstream_name',
        'category',
        'description',
        'input_schema',
        'annotations',
        'read_only',
        'sensitive',
        'active',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'annotations' => 'array',
            'read_only' => 'boolean',
            'sensitive' => 'boolean',
            'active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (): void {
            McpToolRegistry::flushMemoized();
        });
        static::deleted(function (): void {
            McpToolRegistry::flushMemoized();
        });
    }

    /**
     * Rows dispatchable as dynamic tools: default-deny to the reviewed allow-list, then
     * belt-and-suspenders exclusion of blocked upstreams and curated-name squatters.
     *
     * The whereIn(APPROVED) is the PRIMARY gate now — an already-imported row whose
     * upstream is not on the allow-list is inert the moment it is read, which is what
     * closes the import-by-default hole at DEPLOY rather than at the next optional weekly
     * catalog sync (psa-3g8y, extending psa-7lgo.7). The two whereNotIn clauses are kept:
     * they are redundant while APPROVED holds only reviewed read tools, but they are
     * self-documenting and still refuse a blocked/colliding name if one were ever mistakenly
     * added to APPROVED.
     *
     * This is applied to the scopes below rather than to each call site, so that the whole
     * runtime surface — what tools/list advertises, what handles() claims, and what the
     * dynamic executor will look up — is safe BY CONSTRUCTION.
     *
     * Deliberately NOT a global scope: CippMcpCatalogSyncService must still be able to SEE
     * every row (including now-unapproved ones) in order to deactivate them, and it queries
     * without the scopes.
     *
     * @param  Builder<CippMcpTool>  $query
     */
    public function scopeDispatchable(Builder $query): void
    {
        $query->whereIn('upstream_name', CippMcpToolPolicy::APPROVED_DYNAMIC_UPSTREAM_TOOLS)
            ->whereNotIn('upstream_name', CippMcpToolPolicy::BLOCKED_UPSTREAM_TOOLS)
            ->whereNotIn('local_name', CippMcpToolPolicy::curatedLocalToolNames());
    }

    /** @param  Builder<CippMcpTool>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true)->dispatchable();
    }

    /** @param  Builder<CippMcpTool>  $query */
    public function scopeExecutableRead(Builder $query): void
    {
        $query->where('active', true)
            ->where('read_only', true)
            ->where('sensitive', false)
            ->dispatchable();
    }

    public static function handles(string $toolName): bool
    {
        try {
            return in_array($toolName, McpToolRegistry::dynamicCippToolNames(), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function toolDefinition(): array
    {
        return [
            'name' => $this->local_name,
            'description' => $this->description ?: "Run the CIPP {$this->upstream_name} tool for the selected client's tenant.",
            'input_schema' => $this->publicInputSchema(),
        ];
    }

    /** @return array<string, mixed> */
    public function publicInputSchema(): array
    {
        $rawSchema = is_array($this->input_schema) ? $this->input_schema : [];
        $rawErrors = McpInputSchema::validationErrors($rawSchema);
        if ($rawErrors !== []) {
            Log::warning('[MCP/staff] Sanitized invalid dynamic CIPP MCP schema', [
                'tool' => $this->local_name,
                'upstream_tool' => $this->upstream_name,
                'errors' => array_slice($rawErrors, 0, 10),
            ]);
        }

        $schema = McpInputSchema::sanitizeDynamicCipp($rawSchema);
        $properties = (array) ($schema['properties'] ?? []);

        foreach (self::tenantSelectorKeys() as $key) {
            unset($properties[$key]);
        }

        $schema['type'] = 'object';
        $schema['properties'] = $properties === [] ? new \stdClass : $properties;
        $schema['required'] = array_values(array_filter(
            (array) ($schema['required'] ?? []),
            fn (mixed $field): bool => is_string($field)
                && isset($properties[$field])
                && ! in_array($field, self::tenantSelectorKeys(), true),
        ));

        return $schema;
    }

    /** @return array<int, string> */
    public static function tenantSelectorKeys(): array
    {
        return ['tenantFilter', 'TenantFilter', 'tenant', 'Tenant'];
    }
}
