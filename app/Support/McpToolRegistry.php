<?php

namespace App\Support;

use App\Models\CippMcpTool;
use App\Services\Agent\RequestToolTool;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Chet\ChetDataSurfaceTools;
use App\Services\Chet\OperatorBridgeTools;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\Mcp\StaffTacticalAdminToolExecutor;
use App\Services\Triage\TriageToolDefinitions;

class McpToolRegistry
{
    private static ?int $memoizedRequestId = null;

    /** @var array<string, mixed> */
    private static array $memoized = [];

    /**
     * @return array<string, array{label: string, sensitive: bool, tools: array<int, array{name: string, description: string}>}>
     */
    public static function groups(): array
    {
        /** @var array<string, array{label: string, sensitive: bool, tools: array<int, array{name: string, description: string}>}> $groups */
        $groups = self::memoized('groups', function (): array {
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

            $psaActions = self::shape(self::psaActionTools());
            $psaActionNames = array_flip(array_column($psaActions, 'name'));

            $client = array_values(array_filter(
                self::shape(AssistantToolDefinitions::getTools(hasClient: true)),
                fn (array $tool): bool => ! isset($generalNames[$tool['name']])
                    && ! isset($integrationNames[$tool['name']])
                    && ! isset($psaActionNames[$tool['name']]),
            ));

            $bridge = self::shape(OperatorBridgeTools::definitions());
            $wikiWrites = self::shape([self::wikiAddFactTool(), self::wikiCreatePageTool(), self::wikiUpdatePageTool()]);
            $cippWrites = self::shape(array_merge(
                self::dynamicCippWriteTools(),
                self::cippWriteTools(),
            ));
            $tacticalActions = self::shape(self::tacticalActionTools());
            $tacticalAdmin = self::shape(self::tacticalAdminTools());
            $psaRecords = self::shape(self::psaRecordsTools());
            $psaRead = self::shape(self::psaReadTools());
            $intakeManage = self::shape(self::intakeManageTools());

            return [
                'general' => ['label' => 'General (no client context)', 'sensitive' => false, 'tools' => $general],
                'client' => ['label' => 'Client-scoped', 'sensitive' => false, 'tools' => $client],
                'integration' => ['label' => 'Integration (RMM / M365)', 'sensitive' => false, 'tools' => $integration],
                'cipp_write' => ['label' => 'CIPP write-class (sensitive)', 'sensitive' => true, 'tools' => $cippWrites],
                'tactical_action' => ['label' => 'Tactical endpoint actions (sensitive)', 'sensitive' => true, 'tools' => $tacticalActions],
                'tactical_admin' => ['label' => 'Tactical admin/provisioning (sensitive)', 'sensitive' => true, 'tools' => $tacticalAdmin],
                'wiki_write' => ['label' => 'Wiki write (sensitive)', 'sensitive' => true, 'tools' => $wikiWrites],
                'psa_action' => ['label' => 'PSA actions (sensitive)', 'sensitive' => true, 'tools' => $psaActions],
                'psa_records' => ['label' => 'PSA records — clients, people, assets (sensitive)', 'sensitive' => true, 'tools' => $psaRecords],
                'psa_read' => ['label' => 'PSA reads (sensitive)', 'sensitive' => true, 'tools' => $psaRead],
                'intake_manage' => ['label' => 'Intake email/call manage (sensitive)', 'sensitive' => true, 'tools' => $intakeManage],
                'bridge' => ['label' => 'Operator bridge (sensitive)', 'sensitive' => true, 'tools' => $bridge],
            ];
        });

        return $groups;
    }

    /** @return array<int, string> */
    public static function allToolNames(): array
    {
        /** @var array<int, string> $toolNames */
        $toolNames = self::memoized('all_tool_names', function (): array {
            $names = [];

            foreach (self::groups() as $group) {
                foreach ($group['tools'] as $tool) {
                    $names[$tool['name']] = true;
                }
            }

            return array_keys($names);
        });

        return $toolNames;
    }

    /**
     * The same tools as groups(), re-cut for the operator: bucketed by
     * INTEGRATION, then by sensitivity TIER within each integration. This is
     * the shape the token detail page renders. Sensitivity is not re-derived
     * here; each tier inherits the curated `sensitive` flag of the source
     * group it came from, so the classification stays single-sourced.
     *
     * @return array<string, array{label: string, blurb: string, icon: string, accent: string, total: int, sensitive_count: int, tiers: array<int, array{key: string, label: string, sensitive: bool, tools: array<int, array{name: string, description: string, sensitive: bool}>}>}>
     */
    public static function integrationGroups(): array
    {
        /** @var array<string, mixed> $result */
        $result = self::memoized('integration_groups', function (): array {
            // Sensitive source groups map to one labelled tier under an integration.
            // [integration, tierKey, tierLabel, order]
            $sensitiveMap = [
                'psa_action' => ['psa', 'write', 'Write & act', 2],
                'psa_records' => ['psa', 'write', 'Write & act', 2],
                'psa_read' => ['psa', 'read', 'Reads', 3],
                'intake_manage' => ['psa', 'write', 'Write & act', 2],
                'cipp_write' => ['cipp', 'write', 'Write & remediate', 2],
                'tactical_action' => ['tactical', 'actions', 'Endpoint actions', 2],
                'tactical_admin' => ['tactical', 'admin', 'Admin & provisioning', 3],
                'wiki_write' => ['wiki', 'write', 'Write', 2],
                'bridge' => ['teams', 'bridge', 'Operator bridge', 2],
            ];

            /** @var array<string, array<string, array{label: string, sensitive: bool, order: int, tools: array<int, array<string, mixed>>}>> $buckets */
            $buckets = [];
            $push = static function (string $integration, string $tierKey, string $tierLabel, bool $sensitive, int $order, array $tool) use (&$buckets): void {
                if (! isset($buckets[$integration][$tierKey])) {
                    $buckets[$integration][$tierKey] = ['key' => $tierKey, 'label' => $tierLabel, 'sensitive' => $sensitive, 'order' => $order, 'tools' => []];
                }
                $buckets[$integration][$tierKey]['tools'][] = [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'sensitive' => $sensitive,
                ];
            };

            foreach (self::groups() as $key => $group) {
                if (isset($sensitiveMap[$key])) {
                    [$integration, $tierKey, $tierLabel, $order] = $sensitiveMap[$key];
                    foreach ($group['tools'] as $tool) {
                        $push($integration, $tierKey, $tierLabel, true, $order, $tool);
                    }

                    continue;
                }

                // Standard source group: split by tool prefix into each integration's Read tier.
                foreach ($group['tools'] as $tool) {
                    $push(self::integrationForToolName((string) $tool['name']), 'read', 'Read', false, 1, $tool);
                }
            }

            $out = [];
            foreach (self::integrationMeta() as $integration => $meta) {
                if (! isset($buckets[$integration])) {
                    continue;
                }

                $tiers = array_values($buckets[$integration]);
                usort($tiers, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

                $total = 0;
                $sensitiveCount = 0;
                $shapedTiers = [];
                foreach ($tiers as $tier) {
                    $total += count($tier['tools']);
                    if ($tier['sensitive']) {
                        $sensitiveCount += count($tier['tools']);
                    }
                    $shapedTiers[] = [
                        'key' => $tier['key'],
                        'label' => $tier['label'],
                        'sensitive' => $tier['sensitive'],
                        'tools' => $tier['tools'],
                    ];
                }

                $out[$integration] = [
                    'label' => $meta['label'],
                    'blurb' => $meta['blurb'],
                    'icon' => $meta['icon'],
                    'accent' => $meta['accent'],
                    'total' => $total,
                    'sensitive_count' => $sensitiveCount,
                    'tiers' => $shapedTiers,
                ];
            }

            return $out;
        });

        return $result;
    }

    /**
     * Ordered integration metadata: label, one-line blurb, bootstrap-icon, accent.
     *
     * @return array<string, array{label: string, blurb: string, icon: string, accent: string}>
     */
    public static function integrationMeta(): array
    {
        return [
            'psa' => ['label' => 'PSA Core', 'blurb' => 'Native tickets, clients, people & assets', 'icon' => 'bi-box-seam', 'accent' => '#1a365d'],
            'tactical' => ['label' => 'Tactical RMM', 'blurb' => 'Endpoint telemetry, actions & provisioning', 'icon' => 'bi-hdd-network', 'accent' => '#0e7490'],
            'cipp' => ['label' => 'CIPP · Microsoft 365', 'blurb' => 'Multi-tenant M365 management relay', 'icon' => 'bi-microsoft', 'accent' => '#2563eb'],
            'ninja' => ['label' => 'NinjaOne RMM', 'blurb' => 'Endpoint inventory & health', 'icon' => 'bi-hdd-stack', 'accent' => '#059669'],
            'teams' => ['label' => 'Teams & Operator', 'blurb' => 'Teams chat reads & the operator bridge', 'icon' => 'bi-chat-dots', 'accent' => '#4b53bc'],
            'other' => ['label' => 'Other integrations', 'blurb' => 'Level · Mailprotector · Comet · Control D · Zorus · DNS', 'icon' => 'bi-plugin', 'accent' => '#7c3aed'],
            'wiki' => ['label' => 'Wiki & runbooks', 'blurb' => 'Client wiki & internal SOP / runbook store', 'icon' => 'bi-journal-text', 'accent' => '#b45309'],
        ];
    }

    /**
     * Route a tool to its integration by name prefix. PSA-native tools carry no
     * vendor prefix and fall through to 'psa'; every vendor prefix is mapped so
     * nothing lands uncategorised (enforced by McpToolRegistryTest).
     */
    public static function integrationForToolName(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'cipp_') => 'cipp',
            str_starts_with($name, 'tactical_') => 'tactical',
            str_starts_with($name, 'ninja_') => 'ninja',
            str_starts_with($name, 'wiki_') => 'wiki',
            str_starts_with($name, 'mesh_'),
            str_starts_with($name, 'comet_'),
            str_starts_with($name, 'controld_'),
            str_starts_with($name, 'zorus_'),
            str_starts_with($name, 'dns_'),
            str_starts_with($name, 'level_') => 'other',
            str_contains($name, 'teams') => 'teams',
            default => 'psa',
        };
    }

    /** @return array<int, array<string, mixed>> */
    public static function dynamicCippReadTools(): array
    {
        /** @var array<int, array<string, mixed>> $tools */
        $tools = self::memoized('dynamic_cipp_read_tools', function (): array {
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
        });

        return $tools;
    }

    /** @return array<int, array<string, mixed>> */
    public static function dynamicCippWriteTools(): array
    {
        /** @var array<int, array<string, mixed>> $tools */
        $tools = self::memoized('dynamic_cipp_write_tools', function (): array {
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
        });

        return $tools;
    }

    /** @return array<int, string> */
    public static function dynamicCippToolNames(): array
    {
        /** @var array<int, string> $toolNames */
        $toolNames = self::memoized('dynamic_cipp_tool_names', function (): array {
            $names = [];

            foreach (array_merge(self::dynamicCippReadTools(), self::dynamicCippWriteTools()) as $tool) {
                if (isset($tool['name']) && is_string($tool['name'])) {
                    $names[$tool['name']] = true;
                }
            }

            return array_keys($names);
        });

        return $toolNames;
    }

    /** @return array<int, array<string, mixed>> */
    public static function tacticalActionTools(): array
    {
        return StaffTacticalActionToolExecutor::definitions();
    }

    /** @return array<int, array<string, mixed>> */
    public static function cippWriteTools(): array
    {
        return StaffCippWriteToolExecutor::definitions();
    }

    /** @return array<int, array<string, mixed>> */
    public static function tacticalAdminTools(): array
    {
        return StaffTacticalAdminToolExecutor::definitions();
    }

    public static function flushMemoized(): void
    {
        self::$memoized = [];
        self::$memoizedRequestId = null;
    }

    private static function memoized(string $key, callable $resolver): mixed
    {
        self::resetMemoizationForRequest();

        if (! array_key_exists($key, self::$memoized)) {
            self::$memoized[$key] = $resolver();
        }

        return self::$memoized[$key];
    }

    private static function resetMemoizationForRequest(): void
    {
        $requestId = 0;

        try {
            if (app()->bound('request')) {
                $request = app('request');
                $requestId = is_object($request) ? spl_object_id($request) : 0;
            }
        } catch (\Throwable) {
            $requestId = 0;
        }

        if (self::$memoizedRequestId !== $requestId) {
            self::$memoizedRequestId = $requestId;
            self::$memoized = [];
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
            self::createTicketTool(),
            self::sendEmailTool(),
            self::stageEmailTool(),
            self::writePublicNoteTool(),
            self::stagePublicNoteTool(),
            self::proposeMergeTool(),
            self::updateTicketTool(),
            self::setTicketStatusTool(),
            self::assignTicketTool(),
            self::assignAssetTool(),
            self::unassignAssetTool(),
            self::setTicketContactTool(),
            self::moveTicketToClientTool(),
        ];
    }

    /** @return array<string, mixed> */
    public static function createTicketTool(): array
    {
        return [
            'name' => 'create_ticket',
            'description' => 'Create a new client ticket immediately. The server fixes ticket source/type through the Assistant ticket creator, requires a concrete reason, deduplicates identical subject and description for the same client, and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'subject' => [
                        'type' => 'string',
                        'description' => 'Ticket subject.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Initial ticket description.',
                    ],
                    'priority' => [
                        'type' => 'integer',
                        'enum' => [1, 2, 3, 4],
                        'description' => 'Optional priority: 1 critical, 2 high, 3 medium, 4 low. Defaults to 3.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Specific client evidence for creating this ticket now.',
                    ],
                ],
                'required' => ['subject', 'description', 'reason'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function updateTicketTool(): array
    {
        return [
            'name' => 'update_ticket',
            'description' => 'Update the current ticket subject, description, priority, or type immediately. The server derives the ticket client from ticket_id, validates the same ticket edit rules as the web form, and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to update. The server derives the client from this ticket.',
                    ],
                    'subject' => [
                        'type' => 'string',
                        'description' => 'Optional replacement ticket subject.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Optional replacement ticket description.',
                    ],
                    'priority' => [
                        'type' => 'string',
                        'enum' => ['p1', 'p2', 'p3', 'p4'],
                        'description' => 'Optional ticket priority.',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['incident', 'service_request', 'change', 'problem'],
                        'description' => 'Optional ticket type.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Optional ticket-specific reason for this update.',
                    ],
                ],
                'required' => ['ticket_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function setTicketStatusTool(): array
    {
        return [
            'name' => 'set_ticket_status',
            'description' => 'Change a ticket status immediately. Open and in-progress transitions are direct; terminal transitions to Resolved or Closed require typed confirmation and a concrete reason. The server derives the ticket client from ticket_id and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to update. The server derives the client from this ticket.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['new', 'in_progress', 'pending_client', 'pending_third_party', 'resolved', 'closed'],
                        'description' => 'Target ticket status.',
                    ],
                    'confirm_status' => [
                        'type' => 'string',
                        'description' => 'Typed status confirmation required for Resolved and Closed transitions.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Optional ticket-specific reason for the status change. Required for Resolved and Closed transitions.',
                    ],
                    'note' => [
                        'type' => 'string',
                        'description' => 'Optional private status-change note.',
                    ],
                    'resolution' => [
                        'type' => 'string',
                        'description' => 'Optional resolution text for Resolved/Closed transitions.',
                    ],
                ],
                'required' => ['ticket_id', 'status'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function assignTicketTool(): array
    {
        return [
            'name' => 'assign_ticket',
            'description' => 'Assign or unassign the ticket technician immediately. The server derives the ticket client from ticket_id and validates the assignee user id before writing the change and audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to update. The server derives the client from this ticket.',
                    ],
                    'user_id' => [
                        'type' => 'integer',
                        'description' => 'Optional staff user ID. Omit or null to unassign.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Optional ticket-specific reason for the assignment change.',
                    ],
                ],
                'required' => ['ticket_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function assignAssetTool(): array
    {
        return [
            'name' => 'assign_asset',
            'description' => 'Link one asset to the current ticket immediately. The server derives the ticket client from ticket_id, enforces asset.client_id == ticket.client_id, and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to update. The server derives the client from this ticket.',
                    ],
                    'asset_id' => [
                        'type' => 'integer',
                        'description' => 'Asset ID to link to this ticket.',
                    ],
                    'is_primary' => [
                        'type' => 'boolean',
                        'description' => 'Whether the asset should be marked primary on the ticket. Defaults to false.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Optional ticket-specific reason for linking the asset.',
                    ],
                ],
                'required' => ['ticket_id', 'asset_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function unassignAssetTool(): array
    {
        return [
            'name' => 'unassign_asset',
            'description' => 'Unlink one asset from the current ticket immediately. The server derives the ticket client from ticket_id, enforces asset.client_id == ticket.client_id, and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to update. The server derives the client from this ticket.',
                    ],
                    'asset_id' => [
                        'type' => 'integer',
                        'description' => 'Asset ID to unlink from this ticket.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Optional ticket-specific reason for unlinking the asset.',
                    ],
                ],
                'required' => ['ticket_id', 'asset_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function setTicketContactTool(): array
    {
        return [
            'name' => 'set_ticket_contact',
            'description' => 'Set the ticket contact immediately. The server derives the ticket client from ticket_id and enforces that the contact belongs to that client before writing the change and audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to update. The server derives the client from this ticket.',
                    ],
                    'contact_id' => [
                        'type' => 'integer',
                        'description' => 'Person ID to set as the ticket contact.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Optional ticket-specific reason for the contact change.',
                    ],
                ],
                'required' => ['ticket_id', 'contact_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function moveTicketToClientTool(): array
    {
        return [
            'name' => 'move_ticket_to_client',
            'description' => 'Move the ticket to another client immediately. The server derives the source client from ticket_id, requires typed confirmation of the target client name, revalidates the target contact, detaches the old client assets through TicketService, and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket_id' => [
                        'type' => 'integer',
                        'description' => 'The ticket ID to move. The server derives the source client from this ticket.',
                    ],
                    'new_client_id' => [
                        'type' => 'integer',
                        'description' => 'Target client ID.',
                    ],
                    'new_contact_id' => [
                        'type' => 'integer',
                        'description' => 'Optional target contact ID belonging to the target client.',
                    ],
                    'confirm_client_name' => [
                        'type' => 'string',
                        'description' => 'Typed target client name confirmation.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Specific reason for moving the ticket to another client.',
                    ],
                ],
                'required' => ['ticket_id', 'new_client_id', 'confirm_client_name', 'reason'],
            ],
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

    /**
     * PSA records write-surface (P2a) — native client CRUD. All dormant until
     * explicitly granted. Dispatched through StaffPsaActionToolExecutor.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function psaRecordsTools(): array
    {
        return [
            self::createClientTool(),
            self::updateClientTool(),
            self::updateClientSiteNotesTool(),
            self::deleteClientTool(),
            self::createContactTool(),
            self::updateContactTool(),
            self::setPrimaryContactTool(),
            self::moveContactToClientTool(),
            self::deleteContactTool(),
            self::createAssetTool(),
            self::updateAssetTool(),
            self::retireAssetTool(),
            self::restoreAssetTool(),
            self::linkAssetUserTool(),
            self::unlinkAssetUserTool(),
            self::setPrimaryAssetUserTool(),
        ];
    }

    /**
     * PSA read surface (P3+) — grant-gated/dormant reads executed through
     * AssistantToolExecutor (the read executor). Two shapes coexist here:
     * contract tools are hard client-scoped (coverage/SLA only — pricing and
     * financial fields are held from v1); email-item and phone-call tools are
     * cross-client staff-class reads where client_id is an OPTIONAL filter,
     * never required — a woken Chet must be able to list unlinked/unresolved
     * items that have no client yet (mirrors find_persons/find_assets, and
     * get_ticket_detail's by-id-with-no-client-gating precedent). Lists return
     * metadata plus a short preview only; full body_text/transcription are
     * only available through the by-id get tools.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function psaReadTools(): array
    {
        return [
            self::listClientContractsTool(),
            self::getContractTool(),
            self::listEmailItemsTool(),
            self::getEmailItemTool(),
            self::listPhoneCallsTool(),
            self::getPhoneCallTool(),
        ];
    }

    /** @return array<string, mixed> */
    public static function listClientContractsTool(): array
    {
        return [
            'name' => 'list_client_contracts',
            'description' => 'List a client\'s contracts (coverage summary rows: id, name, type, status, start/end dates, SLA flag, and linked asset/person/license counts). Read-only; pricing and financial fields are not exposed. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'The client whose contracts to list.'],
                    'status' => ['type' => 'string', 'enum' => ['active', 'expired', 'cancelled'], 'description' => 'Optional status filter.'],
                    'limit' => ['type' => 'integer', 'description' => 'Max results (default 25, max 50).'],
                ],
                'required' => ['client_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function getContractTool(): array
    {
        return [
            'name' => 'get_contract',
            'description' => 'Get one contract\'s coverage detail (type, status, dates, term, auto-renew, SLA terms, notes, and linked asset/person/license/profile/document counts), scoped to the client. Read-only; pricing and financial fields are not exposed. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'The client that owns the contract.'],
                    'contract_id' => ['type' => 'integer', 'description' => 'The contract ID to read.'],
                ],
                'required' => ['client_id', 'contract_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function listEmailItemsTool(): array
    {
        return [
            'name' => 'list_email_items',
            'description' => 'List inbound/outbound email items (metadata + short body_preview only; never full bodies). Staff-class, cross-client. Filter by direction, unlinked (no ticket), client_id, since.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound'], 'description' => 'Filter by direction.'],
                    'unlinked' => ['type' => 'boolean', 'description' => 'Only items not yet linked to a ticket.'],
                    'client_id' => ['type' => 'integer', 'description' => 'Optional: scope to one client. Omit for cross-client triage of unresolved items.'],
                    'since' => ['type' => 'string', 'description' => 'ISO-8601; only items received at/after this time.'],
                    'limit' => ['type' => 'integer', 'description' => 'Max rows (default 25, cap 50).'],
                ],
                'required' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function getEmailItemTool(): array
    {
        return [
            'name' => 'get_email_item',
            'description' => 'Get one email item\'s full detail, including the full body text and its ticket/client linkage. Staff-class, cross-client — not scoped to a client_id.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'email_id' => ['type' => 'integer', 'description' => 'The email item ID to read.'],
                ],
                'required' => ['email_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function listPhoneCallsTool(): array
    {
        return [
            'name' => 'list_phone_calls',
            'description' => 'List phone calls (metadata only; never the transcript). Staff-class, cross-client. Filter by direction, unlinked (no ticket), client_id, transcription_status, since.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound'], 'description' => 'Filter by direction.'],
                    'unlinked' => ['type' => 'boolean', 'description' => 'Only calls not yet linked to a ticket.'],
                    'client_id' => ['type' => 'integer', 'description' => 'Optional: scope to one client. Omit for cross-client triage of unresolved calls.'],
                    'transcription_status' => ['type' => 'string', 'enum' => ['pending', 'processing', 'completed', 'failed'], 'description' => 'Filter by transcription status.'],
                    'since' => ['type' => 'string', 'description' => 'ISO-8601; only calls started at/after this time.'],
                    'limit' => ['type' => 'integer', 'description' => 'Max rows (default 25, cap 50).'],
                ],
                'required' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function getPhoneCallTool(): array
    {
        return [
            'name' => 'get_phone_call',
            'description' => 'Get one phone call\'s full detail, including the transcription and its ticket/client linkage. Staff-class, cross-client — not scoped to a client_id.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'phone_call_id' => ['type' => 'integer', 'description' => 'The phone call ID to read.'],
                ],
                'required' => ['phone_call_id'],
            ],
        ];
    }

    /**
     * Intake MANAGE surface (W2 Task 2/3) — the 5 front-door verbs that act on
     * unresolved email/call intake items (link to an existing ticket, create a
     * new ticket, or dismiss). Dormant until explicitly granted. Dispatched
     * through StaffPsaActionToolExecutor; each is a thin reuse of the native
     * EmailService/PhoneCallService path — no reimplementation. None of these
     * schemas carry client_id (the target email/call/ticket ids carry their
     * own client scope); the audited summary is built from ids + reason only —
     * the email body / call transcript are never included.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function intakeManageTools(): array
    {
        return [
            self::linkEmailToTicketTool(),
            self::createTicketFromEmailTool(),
            self::dismissEmailItemTool(),
            self::linkCallToTicketTool(),
            self::createTicketFromCallTool(),
        ];
    }

    /** @return array<string, mixed> */
    public static function linkEmailToTicketTool(): array
    {
        return [
            'name' => 'link_email_to_ticket',
            'description' => 'Link an unresolved inbound email item to an existing ticket immediately, reusing the native email-to-ticket linking path (records the client reply note and touches ticket activity). Audited by ids and reason only — the email body is never logged. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'email_id' => ['type' => 'integer', 'description' => 'The email item ID to link.'],
                    'ticket_id' => ['type' => 'integer', 'description' => 'The ticket ID to link the email to.'],
                    'reason' => ['type' => 'string', 'description' => 'Specific reason this email belongs on this ticket.'],
                ],
                'required' => ['email_id', 'ticket_id', 'reason'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function createTicketFromEmailTool(): array
    {
        return [
            'name' => 'create_ticket_from_email',
            'description' => 'Create a new ticket from an unresolved inbound email item immediately, reusing the native auto-create-from-email path. The email must already be resolved to a client. Audited by ids and reason only — the email body is never logged. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'email_id' => ['type' => 'integer', 'description' => 'The email item ID to create a ticket from. Must already have a resolved client_id.'],
                    'reason' => ['type' => 'string', 'description' => 'Specific reason a new ticket (rather than linking to an existing one) is the right action for this email.'],
                ],
                'required' => ['email_id', 'reason'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function dismissEmailItemTool(): array
    {
        return [
            'name' => 'dismiss_email_item',
            'description' => 'Dismiss an unresolved inbound email item immediately — marks it handled with no ticket created — reusing the native dismiss path. Audited by id and reason only — the email body is never logged. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'email_id' => ['type' => 'integer', 'description' => 'The email item ID to dismiss.'],
                    'reason' => ['type' => 'string', 'description' => 'Specific reason this email needs no ticket (e.g., resolved elsewhere, not actionable, spam).'],
                ],
                'required' => ['email_id', 'reason'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function linkCallToTicketTool(): array
    {
        return [
            'name' => 'link_call_to_ticket',
            'description' => 'Link an unresolved phone call to an existing ticket immediately, reusing the native call-linking path (sets billability when already known and drops an internal note). Audited by ids and reason only — the call transcript is never logged. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'phone_call_id' => ['type' => 'integer', 'description' => 'The phone call ID to link.'],
                    'ticket_id' => ['type' => 'integer', 'description' => 'The ticket ID to link the call to.'],
                    'reason' => ['type' => 'string', 'description' => 'Specific reason this call belongs on this ticket.'],
                ],
                'required' => ['phone_call_id', 'ticket_id', 'reason'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function createTicketFromCallTool(): array
    {
        return [
            'name' => 'create_ticket_from_call',
            'description' => 'Create a new ticket from an unresolved phone call immediately, reusing the native call-to-ticket creation path. The call must already be resolved to a client. Audited by ids and reason only — the call transcript is never logged. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'phone_call_id' => ['type' => 'integer', 'description' => 'The phone call ID to create a ticket from. Must already have a resolved client_id.'],
                    'reason' => ['type' => 'string', 'description' => 'Specific reason a new ticket (rather than linking to an existing one) is the right action for this call.'],
                ],
                'required' => ['phone_call_id', 'reason'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function createClientTool(): array
    {
        return [
            'name' => 'create_client',
            'description' => 'Create a new PSA client (company) record immediately. This is a global write — no client_id is accepted. The server validates the same fields as the client create form, normalizes the phone number, and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Client (company) name.'],
                    'notes' => ['type' => 'string', 'description' => 'Optional internal notes about the client.'],
                    'phone' => ['type' => 'string', 'description' => 'Optional main phone number.'],
                    'email' => ['type' => 'string', 'description' => 'Optional main contact email.'],
                    'website' => ['type' => 'string', 'description' => 'Optional website URL.'],
                    'address_line1' => ['type' => 'string', 'description' => 'Optional street address line 1.'],
                    'address_line2' => ['type' => 'string', 'description' => 'Optional street address line 2.'],
                    'city' => ['type' => 'string', 'description' => 'Optional city.'],
                    'state' => ['type' => 'string', 'description' => 'Optional state or region.'],
                    'postcode' => ['type' => 'string', 'description' => 'Optional postal code.'],
                    'is_active' => ['type' => 'boolean', 'description' => 'Whether the client is active. Defaults to active.'],
                    'primary_tech_id' => ['type' => 'integer', 'description' => 'Optional primary technician user ID.'],
                    'reseller_id' => ['type' => 'integer', 'description' => 'Optional reseller (parent client) ID that this client is billed through.'],
                ],
                'required' => ['name'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function updateClientTool(): array
    {
        return [
            'name' => 'update_client',
            'description' => 'Update an existing PSA client record immediately. The server acts only on the supplied client_id, validates the same fields as the client edit form, and writes an action audit row. Site notes and credentials are handled by their own tools and are not accepted here. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'The target client ID to update.'],
                    'name' => ['type' => 'string', 'description' => 'Optional replacement client name.'],
                    'notes' => ['type' => 'string', 'description' => 'Optional replacement internal notes.'],
                    'phone' => ['type' => 'string', 'description' => 'Optional replacement main phone number.'],
                    'email' => ['type' => 'string', 'description' => 'Optional replacement main contact email.'],
                    'website' => ['type' => 'string', 'description' => 'Optional replacement website URL.'],
                    'address_line1' => ['type' => 'string', 'description' => 'Optional replacement street address line 1.'],
                    'address_line2' => ['type' => 'string', 'description' => 'Optional replacement street address line 2.'],
                    'city' => ['type' => 'string', 'description' => 'Optional replacement city.'],
                    'state' => ['type' => 'string', 'description' => 'Optional replacement state or region.'],
                    'postcode' => ['type' => 'string', 'description' => 'Optional replacement postal code.'],
                    'is_active' => ['type' => 'boolean', 'description' => 'Optional active flag.'],
                    'primary_tech_id' => ['type' => 'integer', 'description' => 'Optional primary technician user ID.'],
                    'reseller_id' => ['type' => 'integer', 'description' => 'Optional reseller (parent client) ID.'],
                ],
                'required' => ['client_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function updateClientSiteNotesTool(): array
    {
        return [
            'name' => 'update_client_site_notes',
            'description' => 'Replace a PSA client\'s site notes immediately. The server acts only on the supplied client_id, honors optimistic concurrency via expected_updated_at (rejecting a stale overwrite), renders markdown, and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'The target client ID.'],
                    'site_notes' => ['type' => 'string', 'description' => 'New site notes in markdown. Pass an empty string to clear them.'],
                    'expected_updated_at' => ['type' => 'string', 'description' => 'Optional ISO-8601 timestamp of the site notes you last read. If it no longer matches, the write is rejected as a concurrent edit.'],
                ],
                'required' => ['client_id', 'site_notes'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function deleteClientTool(): array
    {
        return [
            'name' => 'delete_client',
            'description' => 'Soft-delete a PSA client immediately. The server acts only on the supplied client_id, requires a typed confirmation of the exact client name, and refuses when the client still has open tickets, active contracts, or unpaid invoices. Writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'The target client ID to delete.'],
                    'confirm_client_name' => ['type' => 'string', 'description' => 'Typed confirmation — must exactly match the target client name.'],
                    'reason' => ['type' => 'string', 'description' => 'Optional reason for the deletion, recorded in the audit log.'],
                ],
                'required' => ['client_id', 'confirm_client_name'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function createContactTool(): array
    {
        return [
            'name' => 'create_contact',
            'description' => 'Create a new contact (person) under a client immediately. The server requires client_id as the parent, validates the same fields as the contact create form, normalizes phone numbers, auto-demotes any existing primary when is_primary is set, syncs additional emails, and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => self::contactWritableProperties([
                    'client_id' => ['type' => 'integer', 'description' => 'Parent client ID this contact belongs to.'],
                ]),
                'required' => ['client_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function updateContactTool(): array
    {
        return [
            'name' => 'update_contact',
            'description' => 'Update an existing contact immediately. The server derives the client scope from contact_id (a stray client_id is rejected), validates the same fields as the contact edit form, and writes an action audit row. Use move_contact_to_client to reparent a contact. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => self::contactWritableProperties([
                    'contact_id' => ['type' => 'integer', 'description' => 'The contact (person) ID to update. The server derives the client from this contact.'],
                ]),
                'required' => ['contact_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function setPrimaryContactTool(): array
    {
        return [
            'name' => 'set_primary_contact',
            'description' => 'Mark a contact as the primary contact for its client immediately. The server derives the client scope from contact_id and demotes the prior primary, then writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'contact_id' => ['type' => 'integer', 'description' => 'The contact (person) ID to set as primary. The server derives the client from this contact.'],
                ],
                'required' => ['contact_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function moveContactToClientTool(): array
    {
        return [
            'name' => 'move_contact_to_client',
            'description' => 'Reparent a contact to another client immediately. The server derives the source client from contact_id, requires a typed confirmation of the target client name, and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'contact_id' => ['type' => 'integer', 'description' => 'The contact (person) ID to move. The server derives the source client from this contact.'],
                    'new_client_id' => ['type' => 'integer', 'description' => 'Target client ID to move the contact to.'],
                    'confirm_client_name' => ['type' => 'string', 'description' => 'Typed target client name confirmation.'],
                    'reason' => ['type' => 'string', 'description' => 'Specific reason for moving the contact.'],
                ],
                'required' => ['contact_id', 'new_client_id', 'confirm_client_name'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function deleteContactTool(): array
    {
        return [
            'name' => 'delete_contact',
            'description' => 'Soft-delete a contact immediately. The server derives the client scope from contact_id, requires a typed confirmation of the exact contact full name, and refuses when the contact still has open tickets. Writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'contact_id' => ['type' => 'integer', 'description' => 'The contact (person) ID to delete. The server derives the client from this contact.'],
                    'confirm_contact_name' => ['type' => 'string', 'description' => 'Typed confirmation — must exactly match the contact full name (first + last).'],
                    'reason' => ['type' => 'string', 'description' => 'Optional reason for the deletion, recorded in the audit log.'],
                ],
                'required' => ['contact_id', 'confirm_contact_name'],
            ],
        ];
    }

    /**
     * The contact field schema shared by create_contact / update_contact. The
     * caller passes the scope key (client_id for create, contact_id for update)
     * which is prepended. Mirrors PersonStore/UpdateRequest — deliberately
     * excludes portal_enabled / password / company_wide_access / cipp_* /
     * mailbox_* / department / office_location.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private static function contactWritableProperties(array $scope): array
    {
        return array_merge($scope, [
            'first_name' => ['type' => 'string', 'description' => 'Contact first name.'],
            'last_name' => ['type' => 'string', 'description' => 'Contact last name.'],
            'email' => ['type' => 'string', 'description' => 'Primary email address.'],
            'phone' => ['type' => 'string', 'description' => 'Phone number.'],
            'mobile' => ['type' => 'string', 'description' => 'Mobile number.'],
            'job_title' => ['type' => 'string', 'description' => 'Job title.'],
            'notes' => ['type' => 'string', 'description' => 'Internal notes about the contact.'],
            'person_type' => ['type' => 'string', 'enum' => ['user', 'service_account', 'shared_mailbox', 'room_resource'], 'description' => 'Contact type. Defaults to user.'],
            'is_primary' => ['type' => 'boolean', 'description' => 'Whether this is the primary contact for the client (demotes the prior primary).'],
            'is_active' => ['type' => 'boolean', 'description' => 'Whether the contact is active.'],
            'additional_emails' => [
                'type' => 'array',
                'description' => 'Optional additional email addresses (max 10). Replace-syncs the full list.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string', 'description' => 'Email address.'],
                        'label' => ['type' => 'string', 'description' => 'Optional label (e.g. work, personal).'],
                    ],
                    'required' => ['email'],
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public static function createAssetTool(): array
    {
        return [
            'name' => 'create_asset',
            'description' => 'Create a new asset (device) under a client immediately. The server requires client_id as the parent, validates the same fields as the asset create form, and writes an action audit row. Vendor/RMM fields are never accepted. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => self::assetWritableProperties([
                    'client_id' => ['type' => 'integer', 'description' => 'Parent client ID this asset belongs to.'],
                ]),
                'required' => ['client_id', 'name'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function updateAssetTool(): array
    {
        return [
            'name' => 'update_asset',
            'description' => 'Update an existing asset immediately. The server derives the client scope from asset_id (a stray client_id is rejected), validates the same manual fields as the asset edit form, and writes an action audit row. Vendor/RMM fields are never accepted. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => self::assetWritableProperties([
                    'asset_id' => ['type' => 'integer', 'description' => 'The asset ID to update. The server derives the client from this asset.'],
                ]),
                'required' => ['asset_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function retireAssetTool(): array
    {
        return [
            'name' => 'retire_asset',
            'description' => 'Retire (soft-delete / offboard) an asset immediately. The server derives the client scope from asset_id, requires a typed confirmation of the exact asset name, and refuses when the asset still has open tickets. Writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'asset_id' => ['type' => 'integer', 'description' => 'The asset ID to retire. The server derives the client from this asset.'],
                    'confirm_asset_name' => ['type' => 'string', 'description' => 'Typed confirmation — must exactly match the target asset name.'],
                    'reason' => ['type' => 'string', 'description' => 'Optional reason for retiring the asset, recorded in the audit log.'],
                ],
                'required' => ['asset_id', 'confirm_asset_name'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function restoreAssetTool(): array
    {
        return [
            'name' => 'restore_asset',
            'description' => 'Restore a previously retired asset immediately, reactivating it. The server derives the client scope from asset_id and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'asset_id' => ['type' => 'integer', 'description' => 'The retired asset ID to restore. The server derives the client from this asset.'],
                ],
                'required' => ['asset_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function linkAssetUserTool(): array
    {
        return [
            'name' => 'link_asset_user',
            'description' => 'Link a contact (person) to an asset as a manual user assignment immediately. The server derives the client scope from asset_id and enforces that the person belongs to the same client. Writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'asset_id' => ['type' => 'integer', 'description' => 'The asset ID. The server derives the client from this asset.'],
                    'person_id' => ['type' => 'integer', 'description' => 'The contact (person) ID to link. Must belong to the asset client.'],
                ],
                'required' => ['asset_id', 'person_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function unlinkAssetUserTool(): array
    {
        return [
            'name' => 'unlink_asset_user',
            'description' => 'Unlink a contact (person) from an asset immediately. The server derives the client scope from asset_id and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'asset_id' => ['type' => 'integer', 'description' => 'The asset ID. The server derives the client from this asset.'],
                    'person_id' => ['type' => 'integer', 'description' => 'The contact (person) ID to unlink.'],
                ],
                'required' => ['asset_id', 'person_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function setPrimaryAssetUserTool(): array
    {
        return [
            'name' => 'set_primary_asset_user',
            'description' => 'Mark a linked contact as the primary user of an asset immediately. The server derives the client scope from asset_id, requires the person to already be linked, demotes the prior primary, and writes an action audit row. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'asset_id' => ['type' => 'integer', 'description' => 'The asset ID. The server derives the client from this asset.'],
                    'person_id' => ['type' => 'integer', 'description' => 'The linked contact (person) ID to set as primary user.'],
                ],
                'required' => ['asset_id', 'person_id'],
            ],
        ];
    }

    /**
     * The asset field schema shared by create_asset / update_asset. The caller
     * passes the scope key (client_id for create, asset_id for update) which is
     * prepended. Mirrors AssetStore/UpdateRequest — deliberately excludes the
     * ~60 vendor/sync/RMM fields (ninja_*, level_*, controld_*, zorus_*, m365_*,
     * comet_*, servosity_*, screenconnect_*, tactical_asset_id, rmm_online, …).
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private static function assetWritableProperties(array $scope): array
    {
        return array_merge($scope, [
            'name' => ['type' => 'string', 'description' => 'Asset name or label.'],
            'notes' => ['type' => 'string', 'description' => 'Internal notes about the asset.'],
            'asset_type' => ['type' => 'string', 'description' => 'Asset type, e.g. Workstation, Laptop, Server, Network Device, Printer, Mobile, Other.'],
            'serial_number' => ['type' => 'string', 'description' => 'Serial number.'],
            'hostname' => ['type' => 'string', 'description' => 'Hostname.'],
            'os' => ['type' => 'string', 'description' => 'Operating system.'],
            'ip_address' => ['type' => 'string', 'description' => 'IP address (IPv4 or IPv6).'],
            'is_active' => ['type' => 'boolean', 'description' => 'Whether the asset is active.'],
        ]);
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
