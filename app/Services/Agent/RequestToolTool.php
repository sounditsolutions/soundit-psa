<?php

namespace App\Services\Agent;

use App\Enums\ToolingGapClassification;
use App\Enums\ToolingGapSource;
use App\Models\Ticket;
use App\Models\ToolingGap;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Support\McpToolSurface;

/**
 * The agent's recording-only self-report tool.
 *
 * When the model recognises mid-run that it lacked a tool or data it needed,
 * it calls `request_tool` to leave a durable ToolingGap(source=Agent) row for
 * the team to review. This tool:
 *
 *  - writes ONLY a ToolingGap row (source=Agent, status=Open);
 *  - never touches the ticket, the client, or any TechnicianRun;
 *  - never dispatches a job or sends anything;
 *  - does NOT count as the agent's one action per run
 *    (it is NOT in TechnicianAgent's one-action in_array list).
 *
 * The description field deliberately steers the model away from using it
 * as a substitute for actually handling the ticket.
 *
 * Auto-classification (psa-ve9v): a "tool_missing" report whose text names a
 * tool that already exists in the MCP catalog is reclassified to its real
 * remedy — ToolUngranted (operator token grant) or ToolUnconfigured (instance
 * integration config) — and the response tells the agent so. Genuinely
 * unknown capabilities still file as tool_missing (a build request).
 */
class RequestToolTool
{
    public function __construct(private readonly WikiRedactor $redactor) {}

    /** The Anthropic tool definition for `request_tool`. */
    public static function definition(): array
    {
        return [
            'name' => 'request_tool',
            'description' => 'Report a tooling problem so the team can improve your future capabilities. Use it '
                .'when you lacked a tool or data you needed, when relevant information existed but you could not '
                .'retrieve it, OR when an EXISTING tool you called MISBEHAVED (errored, timed out, or returned '
                .'wrong/empty results). This is an INTERNAL note for the team, NOT an action on the ticket: it '
                .'does nothing to the ticket and is not a substitute for handling it. It does NOT count as your '
                .'one action — you may still propose_close / send_reply / flag_attention as well. Describe the '
                .'problem in ABSTRACT, reusable terms; never include secrets, credentials, or specific values. '
                .'If you suspect the capability already exists, name the tool: a report matching a built tool is '
                .'auto-classified with its real remedy (grant or configuration) instead of filing a build request.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'capability_gap' => [
                        'type' => 'string',
                        'description' => 'The missing capability OR the symptom of the broken tool, in abstract, '
                            .'reusable terms — e.g. "needs to check recent ticket history for prior context on the '
                            .'same client", or "device lookup returned an empty list for a client that clearly has '
                            .'devices". No secrets, no instance-specific identifiers.',
                    ],
                    'classification' => [
                        'type' => 'string',
                        'enum' => ['tool_missing', 'tool_unused', 'tool_broken'],
                        'description' => 'tool_missing = you had no way to obtain this; tool_unused = the '
                            .'capability/data existed but you did not use it; tool_broken = an existing tool you '
                            .'called misbehaved (errored, timed out, or returned wrong/empty data).',
                    ],
                    'tool_name' => [
                        'type' => 'string',
                        'description' => 'For tool_broken only: the name of the tool that misbehaved '
                            .'(e.g. "ninja_get_devices"). Omit for tool_missing / tool_unused.',
                    ],
                    'note' => [
                        'type' => 'string',
                        'description' => 'Optional: one line on why it would have helped, or what you expected the '
                            .'tool to return, on THIS ticket.',
                    ],
                ],
                'required' => ['capability_gap', 'classification'],
            ],
        ];
    }

    /**
     * Recording-only: writes a ToolingGap(source=Agent). Touches NO ticket/client.
     * Returns the model-facing confirmation string.
     *
     * `$granted` is the caller's live grant check (the MCP boundary passes its
     * toolAllowed); null means no grant context (internal technician runs).
     *
     * @param  callable(string): bool|null  $granted
     */
    public function execute(Ticket $ticket, array $input, ?callable $granted = null): string
    {
        $gap = trim((string) ($input['capability_gap'] ?? ''));
        if ($gap === '') {
            return 'Nothing recorded (no capability gap described).';
        }

        $note = isset($input['note']) ? mb_substr(trim((string) $input['note']), 0, 500) : null;

        // tool_name identifies the misbehaving tool on tool_broken reports. Abstract and
        // forwardable (a bare tool name), but still model-supplied — cap and scan it too.
        $toolName = isset($input['tool_name']) ? mb_substr(trim((string) $input['tool_name']), 0, 100) : null;

        // Security: capability_gap / tool_name are forwardable ABSTRACT columns — scan every
        // model-supplied field before storing. A secret or injection pattern must never reach the DB.
        if ($this->redactor->scan($gap) !== []
            || ($note !== null && $this->redactor->scan($note) !== [])
            || ($toolName !== null && $toolName !== '' && $this->redactor->scan($toolName) !== [])) {
            return 'Report rejected: the capability description contained sensitive or injection-like content. '
                .'Please re-describe the capability in abstract terms with no secrets, credentials, or specific values.';
        }

        $classification = ToolingGapClassification::fromAgentInput($input['classification'] ?? null);
        $advice = null;

        if ($classification === ToolingGapClassification::ToolMissing) {
            [$classification, $matchedTool, $advice] = $this->autoClassify($gap, $note, $toolName, $granted);
            $toolName = $matchedTool ?? $toolName;
        }

        ToolingGap::record(
            ticketId: $ticket->id,
            clientId: $ticket->client_id,
            capabilityGap: mb_substr($gap, 0, 500),
            evidence: "Agent self-report on ticket #{$ticket->id}",
            classification: $classification,
            source: ToolingGapSource::Agent,
            agentNote: ($note === '' ? null : $note),
            toolName: ($toolName === '' ? null : $toolName),
        );

        $confirmation = 'Logged a tooling-gap for the team to review. This did NOT change the ticket — continue handling it.';

        return $advice === null ? $confirmation : $confirmation.' '.$advice;
    }

    /**
     * Reclassify a "tool_missing" report that names a tool already in the MCP
     * catalog. Deterministic (lexical match only). Returns the classification,
     * the matched tool name (or null), and model-facing advice (or null).
     *
     * @param  callable(string): bool|null  $granted
     * @return array{0: ToolingGapClassification, 1: ?string, 2: ?string}
     */
    private function autoClassify(string $gap, ?string $note, ?string $toolName, ?callable $granted): array
    {
        $missing = [ToolingGapClassification::ToolMissing, null, null];

        try {
            $matches = McpToolSurface::matchCatalogTools(implode(' ', array_filter([$gap, $note, $toolName])));
            if ($matches === []) {
                return $missing;
            }

            $states = McpToolSurface::classifyNames($matches, $granted);
        } catch (\Throwable) {
            // Classification is best-effort triage; the report itself must never fail on it.
            return $missing;
        }

        $byState = [];
        foreach ($states as $tool => $state) {
            $byState[$state][] = $tool;
        }

        // A granted match means the capability was already callable — the
        // report is a "not used", not a build request.
        if (isset($byState[McpToolSurface::STATE_GRANTED])) {
            $matched = $byState[McpToolSurface::STATE_GRANTED][0];

            return [
                ToolingGapClassification::ToolUnused,
                $matched,
                "This capability already exists as the tool '{$matched}', which is granted to this caller. "
                    .'Recorded as tool_unused; if that tool misbehaved, re-file with classification=tool_broken and tool_name.',
            ];
        }

        if (isset($byState[McpToolSurface::STATE_AVAILABLE_UNGRANTED])) {
            $matched = $byState[McpToolSurface::STATE_AVAILABLE_UNGRANTED][0];

            return [
                ToolingGapClassification::ToolUngranted,
                $matched,
                "This capability already exists as the tool '{$matched}' — it is built and configured on this instance "
                    .'but not granted here. Ask your operator to enable it; recorded as an enablement request, not a build gap.',
            ];
        }

        $matched = $byState[McpToolSurface::STATE_UNAVAILABLE_CONFIG][0];

        return [
            ToolingGapClassification::ToolUnconfigured,
            $matched,
            "This capability already exists as the tool '{$matched}', but its integration is switched off or not "
                .'configured on this instance. Ask your operator to re-enable it in Settings > Integrations or add '
                .'its credentials; recorded as a configuration request, not a build gap.',
        ];
    }
}
