<?php

namespace App\Models;

use App\Support\McpInputSchema;
use App\Support\McpToolRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One tool from the Carbon Connect MCP catalogue (card PEYvh4NL), the CIPP
 * pattern (CippMcpTool). Every tool Carbon publishes is imported; none is
 * blocked here. Which agent may call one is the per-token grant's question
 * (McpStaffController::toolAllowed() requires an EXPLICIT grant of the exact
 * name, so the legacy full-surface token gets none), not this model's.
 *
 * read_only / sensitive come from the upstream annotations exactly as
 * CippMcpCatalogSyncService::catalogRows() derives them: read_only is
 * readOnlyHint === true, and sensitive is its negation. A tool with no
 * annotations, or readOnlyHint absent / false / not a boolean true, is a
 * sensitive write.
 */
class CarbonMcpTool extends Model
{
    public const NAME_PREFIX = 'carbon_';

    protected $fillable = [
        'local_name',
        'upstream_name',
        'title',
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
     * The classification, single-sourced: the sync writes it and the tests pin it.
     *
     * @param  mixed  $annotations  the upstream `annotations` object
     * @return array{read_only: bool, sensitive: bool}
     */
    public static function classify(mixed $annotations): array
    {
        $readOnly = is_array($annotations) && ($annotations['readOnlyHint'] ?? null) === true;

        return ['read_only' => $readOnly, 'sensitive' => ! $readOnly];
    }

    /** @param  Builder<CarbonMcpTool>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public static function handles(string $toolName): bool
    {
        if (! str_starts_with($toolName, self::NAME_PREFIX)) {
            return false;
        }

        try {
            return in_array($toolName, McpToolRegistry::dynamicCarbonToolNames(), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function toolDefinition(): array
    {
        return [
            'name' => $this->local_name,
            'description' => $this->publicDescription(),
            'input_schema' => $this->publicInputSchema(),
        ];
    }

    /** @return array<string, mixed> */
    public function publicInputSchema(): array
    {
        $schema = McpInputSchema::sanitizeDynamicCipp(is_array($this->input_schema) ? $this->input_schema : []);
        // client_id is the PSA boundary's own key; a Carbon tool is never client-scoped.
        $properties = (array) ($schema['properties'] ?? []);
        unset($properties['client_id']);
        $schema['properties'] = $properties === [] ? new \stdClass : $properties;
        $schema['required'] = array_values(array_filter(
            (array) ($schema['required'] ?? []),
            fn (mixed $field): bool => is_string($field) && isset($properties[$field]),
        ));

        return $schema;
    }

    public function publicDescription(): string
    {
        $description = trim((string) $this->description);
        if ($description === '') {
            $description = "Run the Carbon Connect {$this->upstream_name} tool.";
        }

        $kind = $this->read_only
            ? 'Read-only per Carbon\'s annotations.'
            : 'Carbon does not mark this tool read-only, so PSA treats it as a write.';

        return rtrim($description).' [Carbon Connect MCP, acting on the MSP\'s own Carbon account. '.$kind.']';
    }
}
