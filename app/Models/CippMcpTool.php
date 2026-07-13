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
     * Rows the policy forbids from ever being a dynamic tool — a blocked upstream
     * endpoint, or a row squatting on a curated tool's local name.
     *
     * This is applied to the scopes below rather than to each call site, so that the
     * whole runtime surface — what tools/list advertises, what handles() claims, and what
     * the dynamic executor will look up — is safe BY CONSTRUCTION. An offending row that
     * is already in the table is inert on read, which is what makes the guard immediate at
     * deploy instead of eventual at the next catalog sync (psa-7lgo.7).
     *
     * Deliberately NOT a global scope: CippMcpCatalogSyncService must still be able to SEE
     * these rows in order to deactivate them, and it queries without the scopes.
     *
     * @param  Builder<CippMcpTool>  $query
     */
    public function scopeDispatchable(Builder $query): void
    {
        $query->whereNotIn('upstream_name', CippMcpToolPolicy::BLOCKED_UPSTREAM_TOOLS)
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
