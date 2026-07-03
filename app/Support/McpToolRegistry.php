<?php

namespace App\Support;

use App\Models\CippMcpTool;
use App\Services\Agent\RequestToolTool;
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
            [self::proposeCloseTool(), self::sendReplyTool(), self::requestToolTool()],
            ChetDataSurfaceTools::registryGeneralTools(),
        ));
        $generalNames = array_flip(array_column($general, 'name'));

        $integration = self::shape(array_merge(
            TriageToolDefinitions::ninjaTools(),
            TriageToolDefinitions::levelTools(),
            TriageToolDefinitions::meshTools(),
            TriageToolDefinitions::cippTools(),
            self::dynamicCippReadTools(),
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
        $psaActions = self::shape(self::psaActionTools());
        $cippWrites = self::shape(self::dynamicCippWriteTools());

        return [
            'general' => ['label' => 'General (no client context)', 'sensitive' => false, 'tools' => $general],
            'client' => ['label' => 'Client-scoped', 'sensitive' => false, 'tools' => $client],
            'integration' => ['label' => 'Integration (RMM / M365)', 'sensitive' => false, 'tools' => $integration],
            'cipp_write' => ['label' => 'CIPP write-class (sensitive)', 'sensitive' => true, 'tools' => $cippWrites],
            'wiki_write' => ['label' => 'Wiki write (sensitive)', 'sensitive' => true, 'tools' => $wikiWrites],
            'psa_action' => ['label' => 'PSA actions (sensitive)', 'sensitive' => true, 'tools' => $psaActions],
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

    /** @return array<int, array<string, mixed>> */
    public static function dynamicCippReadTools(): array
    {
        try {
            return CippMcpTool::query()
                ->active()
                ->where('read_only', true)
                ->where('sensitive', false)
                ->orderBy('local_name')
                ->get()
                ->map(fn (CippMcpTool $tool): array => $tool->toolDefinition())
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function dynamicCippWriteTools(): array
    {
        try {
            return CippMcpTool::query()
                ->active()
                ->where(function ($query) {
                    $query->where('read_only', false)
                        ->orWhere('sensitive', true);
                })
                ->orderBy('local_name')
                ->get()
                ->map(fn (CippMcpTool $tool): array => $tool->toolDefinition())
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    public static function proposeCloseTool(): array
    {
        return [
            'name' => 'propose_close',
            'description' => 'Submit a held close proposal for a ticket. The call does not close the ticket directly; it records a proposal for cockpit approval. Provide concrete ticket-specific evidence in the reason.',
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
    public static function sendReplyTool(): array
    {
        return [
            'name' => 'send_reply',
            'description' => 'Submit a held client-facing reply draft for a ticket. The call does not send the reply directly; it records a draft for cockpit approval. Provide body to hold supplied reply text verbatim, or omit body to have the PSA drafter compose it.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to draft a reply for. Client-scoped callers must also include the matching client_id.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Specific evidence for why a human should approve sending this reply now.',
                    ],
                    'body' => [
                        'type' => 'string',
                        'description' => 'Optional proposed client-facing reply text. When present, it is held verbatim for cockpit review; omit to have the PSA drafter compose the body.',
                    ],
                ],
                'required' => ['ticket_id', 'reason'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function requestToolTool(): array
    {
        $tool = RequestToolTool::definition();
        $schema = $tool['input_schema'];
        $schema['properties'] = array_merge([
            'ticket_id' => [
                'type' => 'integer',
                'description' => 'The ticket ID where the tooling gap was encountered. The server derives the client from this ticket.',
            ],
        ], $schema['properties']);
        $schema['required'] = array_values(array_unique(array_merge(['ticket_id'], $schema['required'])));
        $tool['input_schema'] = $schema;

        return $tool;
    }

    /** @return array<int, array<string, mixed>> */
    public static function psaActionTools(): array
    {
        return [
            self::sendEmailTool(),
            self::stageEmailTool(),
            self::writePublicNoteTool(),
            self::stagePublicNoteTool(),
            self::proposeMergeTool(),
        ];
    }

    /** @return array<string, mixed> */
    public static function sendEmailTool(): array
    {
        return [
            'name' => 'send_email',
            'description' => 'Send a client-facing ticket email immediately. The server derives the recipient and subject from the ticket contact and ticket metadata, appends the configured AI disclosure, records a public reply note, sends the email, and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to email about. The server derives and validates the client and recipient from this ticket.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Specific ticket-based reason for sending a client-facing email now.',
                    ],
                    'body' => [
                        'type' => 'string',
                        'description' => 'Client-facing message body to send after server-side disclosure is appended.',
                    ],
                ],
                'required' => ['ticket_id', 'reason', 'body'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function stageEmailTool(): array
    {
        return [
            'name' => 'stage_email',
            'description' => 'Stage a proactive client-facing ticket email draft in the cockpit for human approval. The call does not send email directly. The server derives recipient and subject from the ticket; the supplied body is held verbatim for review.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to stage an email draft for. The server derives and validates the client and recipient from this ticket.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Specific ticket-based reason a human should approve this proactive email.',
                    ],
                    'body' => [
                        'type' => 'string',
                        'description' => 'Proposed client-facing email body. It is held verbatim for cockpit review.',
                    ],
                ],
                'required' => ['ticket_id', 'reason', 'body'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function writePublicNoteTool(): array
    {
        return [
            'name' => 'write_public_note',
            'description' => 'Write a public client-visible note to a ticket immediately. The server fixes the note visibility to public, appends the configured AI disclosure, and writes an action audit row. It does not send email. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to write the public note on. The server derives and validates the client from this ticket.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Specific ticket-based reason for publishing this client-visible note now.',
                    ],
                    'body' => [
                        'type' => 'string',
                        'description' => 'Client-visible note body to publish after server-side disclosure is appended.',
                    ],
                ],
                'required' => ['ticket_id', 'reason', 'body'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function stagePublicNoteTool(): array
    {
        return [
            'name' => 'stage_public_note',
            'description' => 'Stage a public client-visible ticket note in the cockpit for human approval. The call does not publish the note directly. The server fixes visibility to public; the supplied body is held verbatim for review.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to stage a public note for. The server derives and validates the client from this ticket.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Specific ticket-based reason a human should approve publishing this note.',
                    ],
                    'body' => [
                        'type' => 'string',
                        'description' => 'Proposed public note body. It is held verbatim for cockpit review.',
                    ],
                ],
                'required' => ['ticket_id', 'reason', 'body'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function proposeMergeTool(): array
    {
        return [
            'name' => 'propose_merge',
            'description' => 'Submit a held ticket-merge proposal for cockpit approval. The call does not merge tickets directly; approval revalidates both tickets and executes the existing merge workflow.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'primary_ticket_id' => [
                        'type' => 'integer',
                        'description' => 'Ticket ID that should remain as the primary ticket.',
                    ],
                    'secondary_ticket_id' => [
                        'type' => 'integer',
                        'description' => 'Ticket ID that should be merged into the primary ticket.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Specific ticket-based evidence that the two tickets are duplicates.',
                    ],
                ],
                'required' => ['primary_ticket_id', 'secondary_ticket_id', 'reason'],
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
                        'description' => 'Optional revision summary.',
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
                        'description' => 'Optional revision summary.',
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
