<?php

namespace App\Support;

use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Chet\ChetDataSurfaceTools;
use App\Services\Chet\OperatorBridgeTools;
use App\Services\Triage\TriageToolDefinitions;

class McpToolRegistry
{
    /**
     * @return array<string, array{label: string, sensitive: bool, tools: array<int, array{name: string, description: string}>}>
     */
    public static function groups(): array
    {
        $general = self::shape(array_merge(
            AssistantToolDefinitions::getTools(hasClient: false),
            [self::proposeCloseTool()],
            ChetDataSurfaceTools::registryGeneralTools(),
        ));
        $generalNames = array_flip(array_column($general, 'name'));

        $integration = self::shape(array_merge(
            TriageToolDefinitions::ninjaTools(),
            TriageToolDefinitions::levelTools(),
            TriageToolDefinitions::meshTools(),
            TriageToolDefinitions::cippTools(),
            ChetDataSurfaceTools::registryIntegrationTools(),
        ));
        $integrationNames = array_flip(array_column($integration, 'name'));

        $client = array_values(array_filter(
            self::shape(AssistantToolDefinitions::getTools(hasClient: true)),
            fn (array $tool): bool => ! isset($generalNames[$tool['name']])
                && ! isset($integrationNames[$tool['name']]),
        ));

        $bridge = self::shape(OperatorBridgeTools::definitions());
        $wikiWrites = self::shape([self::wikiAddFactTool(), self::wikiCreatePageTool(), self::wikiUpdatePageTool()]);

        return [
            'general' => ['label' => 'General (no client context)', 'sensitive' => false, 'tools' => $general],
            'client' => ['label' => 'Client-scoped', 'sensitive' => false, 'tools' => $client],
            'integration' => ['label' => 'Integration (RMM / M365)', 'sensitive' => false, 'tools' => $integration],
            'wiki_write' => ['label' => 'Wiki write (sensitive)', 'sensitive' => true, 'tools' => $wikiWrites],
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

    /** @return array<string, mixed> */
    public static function proposeCloseTool(): array
    {
        return [
            'name' => 'propose_close',
            'description' => 'Submit a held AI Technician close proposal for a ticket. This never closes the ticket directly; it records a proposal in the Technician cockpit for human approval. Provide concrete ticket-specific evidence in the reason.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to propose closing. The server derives and re-validates the ticket client from this ID.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Specific evidence for why a human should approve closing this ticket.',
                    ],
                    'confidence' => [
                        'type' => 'number',
                        'description' => 'Confidence from 0 to 1 that closing is the right action. MCP proposals are always held for human approval regardless of this value.',
                    ],
                ],
                'required' => ['ticket_id', 'reason', 'confidence'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function wikiAddFactTool(): array
    {
        return [
            'name' => 'wiki_add_fact',
            'description' => 'Add one subject-keyed wiki fact through the governed fact store. Direct write: content is safety-scanned, stored as a pinned correction fact, and the target section is recomposed from fact markers. Use scope=client with client_id for client facts, or scope=global without client_id for internal SOP/norm facts. No raw page edits.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'scope' => [
                        'type' => 'string',
                        'enum' => ['client', 'global'],
                        'description' => 'client writes to one client wiki; global writes to internal SOP/runbook pages.',
                    ],
                    'client_id' => [
                        'type' => 'integer',
                        'description' => 'Required when scope=client. Omit when scope=global.',
                    ],
                    'page_slug' => [
                        'type' => 'string',
                        'description' => 'Existing wiki page slug, e.g. infrastructure or runbooks/close-eligibility.',
                    ],
                    'section_anchor' => [
                        'type' => 'string',
                        'description' => 'Markdown section anchor to compose into, e.g. assets, equipment, eligibility.',
                    ],
                    'subject_key' => [
                        'type' => 'string',
                        'description' => 'Stable lowercase identity for dedupe, e.g. asset:dc01:role or sop:close-eligibility:evidence.',
                    ],
                    'statement' => [
                        'type' => 'string',
                        'description' => 'One atomic factual sentence, max 300 chars. No passwords, tokens, instructions, prompts, or marker strings.',
                    ],
                ],
                'required' => ['scope', 'page_slug', 'section_anchor', 'subject_key', 'statement'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function wikiCreatePageTool(): array
    {
        return [
            'name' => 'wiki_create_page',
            'description' => 'Create an internal global SOP/runbook wiki page under runbooks/* or sops/*. Direct write: title and body are safety-scanned, the page is AI-authored, revisioned, and marked as an AI draft. No client pages, overview pages, deviation pages, or out-of-namespace writes.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => 'New global page slug under runbooks/* or sops/*, e.g. runbooks/password-reset or sops/account-lockout.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Human-readable SOP/runbook title. Scanned before storage.',
                    ],
                    'body_md' => [
                        'type' => 'string',
                        'description' => 'Markdown body for the SOP/runbook. Scanned before storage; do not include secrets, prompts, instructions, or wiki fact marker strings.',
                    ],
                    'change_summary' => [
                        'type' => 'string',
                        'description' => 'Optional revision summary for Charlie review.',
                    ],
                ],
                'required' => ['slug', 'title', 'body_md'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function wikiUpdatePageTool(): array
    {
        return [
            'name' => 'wiki_update_page',
            'description' => 'Update an existing internal global SOP/runbook wiki page under runbooks/* or sops/*. Direct write: title and body are safety-scanned, the page remains AI-authored, revisioned, and marked as an AI draft. No client pages, overview pages, deviation pages, or out-of-namespace writes.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Existing global page slug under runbooks/* or sops/*.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Replacement human-readable SOP/runbook title. Scanned before storage.',
                    ],
                    'body_md' => [
                        'type' => 'string',
                        'description' => 'Replacement markdown body for the SOP/runbook. Scanned before storage; do not include secrets, prompts, instructions, or wiki fact marker strings.',
                    ],
                    'change_summary' => [
                        'type' => 'string',
                        'description' => 'Optional revision summary for Charlie review.',
                    ],
                ],
                'required' => ['slug', 'title', 'body_md'],
            ],
        ];
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
