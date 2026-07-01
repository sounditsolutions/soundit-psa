<?php

namespace App\Support;

use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Chet\OperatorBridgeTools;
use App\Services\Triage\TriageToolDefinitions;

class McpToolRegistry
{
    /**
     * @return array<string, array{label: string, sensitive: bool, tools: array<int, array{name: string, description: string}>}>
     */
    public static function groups(): array
    {
        $general = self::shape(AssistantToolDefinitions::getTools(hasClient: false));
        $generalNames = array_flip(array_column($general, 'name'));

        $integration = self::shape(array_merge(
            TriageToolDefinitions::ninjaTools(),
            TriageToolDefinitions::levelTools(),
            TriageToolDefinitions::meshTools(),
            TriageToolDefinitions::cippTools(),
        ));
        $integrationNames = array_flip(array_column($integration, 'name'));

        $client = array_values(array_filter(
            self::shape(AssistantToolDefinitions::getTools(hasClient: true)),
            fn (array $tool): bool => ! isset($generalNames[$tool['name']])
                && ! isset($integrationNames[$tool['name']]),
        ));

        $bridge = self::shape(OperatorBridgeTools::definitions());

        return [
            'general' => ['label' => 'General (no client context)', 'sensitive' => false, 'tools' => $general],
            'client' => ['label' => 'Client-scoped', 'sensitive' => false, 'tools' => $client],
            'integration' => ['label' => 'Integration (RMM / M365)', 'sensitive' => false, 'tools' => $integration],
            'bridge' => ['label' => 'Operator bridge (sensitive)', 'sensitive' => true, 'tools' => $bridge],
        ];
    }

    /** @return array<int, string> */
    public static function allToolNames(): array
    {
        $names = [];

        foreach (self::groups() as $group) {
            foreach ($group['tools'] as $tool) {
                $names[$tool['name']] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array{name: string, description: string}>
     */
    private static function shape(array $tools): array
    {
        $shaped = [];

        foreach ($tools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $shaped[$name] = [
                'name' => $name,
                'description' => (string) ($tool['description'] ?? ''),
            ];
        }

        return array_values($shaped);
    }
}
