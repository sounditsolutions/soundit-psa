<?php

namespace Tests\Unit\Support;

use App\Support\McpInputSchema;
use PHPUnit\Framework\TestCase;

/**
 * A no-argument tool declares `"properties": {}`. json_decode($json, true) renders BOTH `{}` and
 * `[]` as the same empty PHP array, and array_is_list([]) is TRUE — so the list guard in
 * validatePropertiesKeyword() reported every parameterless CIPP tool as malformed.
 *
 * On prod (2026-08-19) that was 26 of 219 dynamic CIPP rows, every one of them this exact case and
 * NONE genuinely malformed. Because publicInputSchema() validates on every tool-surface render, the
 * false positive was re-logged on each listing — it dominated a 4.4 GB unrotated laravel.log.
 */
class McpInputSchemaEmptyPropertiesTest extends TestCase
{
    public function test_empty_properties_map_is_valid(): void
    {
        $this->assertSame([], McpInputSchema::validationErrors([
            'type' => 'object',
            'properties' => [],
        ]), 'an empty properties map declares no properties and is valid JSON Schema');
    }

    public function test_empty_properties_object_is_valid(): void
    {
        $this->assertSame([], McpInputSchema::validationErrors([
            'type' => 'object',
            'properties' => new \stdClass,
        ]));
    }

    public function test_non_empty_list_properties_is_still_rejected(): void
    {
        // The guard must keep its teeth: a populated list where an object belongs is still wrong.
        $this->assertContains('$.properties must be an object', McpInputSchema::validationErrors([
            'type' => 'object',
            'properties' => ['alpha', 'beta'],
        ]));
    }

    public function test_scalar_properties_is_still_rejected(): void
    {
        $this->assertContains('$.properties must be an object', McpInputSchema::validationErrors([
            'type' => 'object',
            'properties' => 'nope',
        ]));
    }

    public function test_sanitizer_still_emits_an_object_for_the_empty_case(): void
    {
        $sanitized = McpInputSchema::sanitizeDynamicCipp(['type' => 'object', 'properties' => []]);

        $this->assertSame('object', $sanitized['type']);
        $this->assertSame([], $sanitized['required']);
    }
}
