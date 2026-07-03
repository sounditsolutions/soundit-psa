<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    /** @param  Builder<CippMcpTool>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @param  Builder<CippMcpTool>  $query */
    public function scopeExecutableRead(Builder $query): void
    {
        $query->where('active', true)
            ->where('read_only', true)
            ->where('sensitive', false);
    }

    public static function handles(string $toolName): bool
    {
        try {
            return static::query()
                ->active()
                ->where('local_name', $toolName)
                ->exists();
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
        $schema = is_array($this->input_schema) ? $this->input_schema : [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach (self::tenantSelectorKeys() as $key) {
            unset($properties[$key]);
        }

        $schema['type'] = $schema['type'] ?? 'object';
        $schema['properties'] = $properties;
        $schema['required'] = array_values(array_filter(
            (array) ($schema['required'] ?? []),
            fn (mixed $field): bool => is_string($field) && ! in_array($field, self::tenantSelectorKeys(), true),
        ));

        return $schema;
    }

    /** @return array<int, string> */
    public static function tenantSelectorKeys(): array
    {
        return ['tenantFilter', 'TenantFilter', 'tenant', 'Tenant'];
    }
}
