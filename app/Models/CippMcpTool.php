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
     * Rows that MAY be dispatched as dynamic tools — the two HARD gates, and only those.
     * A row surviving this scope is not thereby allowed: it is merely not forbidden.
     *
     * Authorization is a separate, stricter question answered per-token in
     * McpStaffController::toolAllowed(), which for any name CippMcpTool::handles() claims
     * requires `allowedTools !== null && allows($tool)` — an EXPLICIT operator grant of that
     * exact tool. That gate governs both tools/list and tools/call, so nothing here reaches
     * an agent without a human having picked it, and a legacy full-surface token (tools ===
     * null) — which grants nothing explicitly — gets zero dynamic CIPP tools.
     *
     * This scope used to also whereIn(APPROVED_DYNAMIC_UPSTREAM_TOOLS) (psa-3g8y). That made
     * an unapproved row inert no matter what — including ~210 tools an operator had
     * DELIBERATELY assigned to a token, which vanished on deploy and broke a trip-critical
     * agent (psa-pzwv). The default-deny's real target is AUTO-IMPORT-BY-DEFAULT — an
     * unreviewed tool going live with NO human decision — and the per-token grant gate above
     * already refuses that, strictly harder than the allow-list did. So the allow-list was
     * subtracting only operator agency, and it is gone; the two gates it fronted are not:
     *
     *   - BLOCKED_UPSTREAM_TOOLS — *** BEING RETIRED; DO NOT REASON FROM IT. *** This line
     *     used to read "is DANGER, and a grant cannot buy it back". Owner directive
     *     2026-07-15 overrules that: hard-blocking is not the model. Both its entries get
     *     wired, made CORRECT and exposed as grantable, with allow/deny left to the
     *     per-token grant — which empties the constant. See CippMcpToolPolicy's class
     *     docblock. The unblock ships WITH the correctness work, never before it (removing
     *     ListUserSigninLogs from the list while the raw passthrough still can't bridge
     *     UPN→objectID buys a confident false "no sign-ins" during compromise triage).
     *   - A curated-name collision is a privilege DOWNGRADE (the dynamic executor dispatches
     *     first, so a colliding row silently replaces the reviewed, scoped implementation
     *     with a raw passthrough). This one STAYS — but read it as CORRECTNESS/integrity,
     *     not as an allow/deny opinion: it removes no operator choice, it only stops a
     *     passthrough silently shadowing a reviewed tool of the same name.
     *
     * This is applied to the scopes below rather than to each call site, so that the whole
     * runtime surface — what tools/list advertises, what handles() claims, and what the
     * dynamic executor will look up — is safe BY CONSTRUCTION.
     *
     * Deliberately NOT a global scope: CippMcpCatalogSyncService must still be able to SEE
     * every row in order to deactivate stale ones, and it queries without the scopes.
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
