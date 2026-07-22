<?php

namespace App\Support;

use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Chet\ChetDataSurfaceTools;
use App\Services\Chet\OperatorBridgeTools;

/**
 * The staff MCP tool surface, classified per tool (psa-ve9v).
 *
 * Three sources of truth meet here:
 *  - the GRANT CATALOG ({@see McpToolRegistry::groups()}) — every tool that is
 *    built and token-grantable, deliberately ungated on integration config so
 *    operators can pre-grant dormant surfaces;
 *  - the LIVE surface (the config-gated assembly `tools/list` publishes) —
 *    what this instance can actually execute right now;
 *  - the caller's token grants ({@see McpStaffController::toolAllowed()}).
 *
 * From those, every catalog tool gets exactly one STATE, named for its remedy:
 *  - granted             — in this token's allowlist and live: callable now.
 *  - available_ungranted — built and configured, not in this token: an
 *    operator token grant enables it.
 *  - unavailable_config  — built but its integration is switched off or not
 *    configured on this instance: re-enabling it in Settings > Integrations
 *    or adding its credentials, not a token grant. Both causes land here, so
 *    the copy must name both remedies (psa-wzjzz made the switch a gate).
 *  - (absent from the catalog — the tool does not exist: a development build.)
 *
 * The live assembly here is the SAME code path `tools/list` uses (the
 * controller consumes {@see liveGeneralToolDefinitions()} /
 * {@see liveClientScopedToolDefinitions()}), so the classification cannot
 * drift from what the server actually publishes.
 *
 * Sibling note (psa-aob9): once per-tool mode grants exist, `granted` splits
 * into granted-immediate / granted-staged — states stay plain strings so that
 * extension is additive.
 */
class McpToolSurface
{
    public const STATE_GRANTED = 'granted';

    public const STATE_AVAILABLE_UNGRANTED = 'available_ungranted';

    public const STATE_UNAVAILABLE_CONFIG = 'unavailable_config';

    private const DESCRIPTION_MAX_LENGTH = 200;

    /**
     * State legend, keyed by state, phrased as fact + remedy (never an
     * imperative — same wake-spec posture as the tool-surface drift hint).
     *
     * @return array<string, string>
     */
    public static function states(): array
    {
        return [
            self::STATE_GRANTED => 'In this token\'s allowlist and live on this instance — callable now.',
            self::STATE_AVAILABLE_UNGRANTED => 'Built and configured on this instance but not in this token\'s allowlist — an operator token grant enables it.',
            self::STATE_UNAVAILABLE_CONFIG => 'Built but its integration is switched off or not configured on this instance — the remedy is re-enabling it in Settings > Integrations or adding its credentials, not a token grant.',
        ];
    }

    /**
     * The general (no client context required) live tool definitions, exactly
     * as `tools/list` publishes them (before the per-token grant filter).
     * Transport built-ins (whoami, list_tool_surface) are prepended by the
     * controller, not here.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function liveGeneralToolDefinitions(): array
    {
        return array_merge(
            AssistantToolDefinitions::getTools(hasClient: false),
            [
                McpToolRegistry::proposeCloseTool(),
                McpToolRegistry::sendReplyTool(),
                McpToolRegistry::requestToolTool(),
                McpToolRegistry::wikiAddFactTool(),
                McpToolRegistry::wikiCreatePageTool(),
                McpToolRegistry::wikiUpdatePageTool(),
            ],
            McpToolRegistry::psaActionTools(),
            McpToolRegistry::psaRecordsTools(),
            McpToolRegistry::psaReadTools(),
            McpToolRegistry::intakeManageTools(),
            // PSA-native like its psa_* neighbours: no integration config to gate
            // on, so publication is unconditional and the executor's only refusal
            // (the kill switch, writes only) is runtime state, not liveness.
            McpToolRegistry::taxonomyTools(),
            TacticalConfig::isConfigured() ? McpToolRegistry::tacticalAdminTools() : [],
            ChetDataSurfaceTools::generalTools(),
            OperatorBridgeTools::definitions(),
        );
    }

    /**
     * The client-scoped live tool definitions, exactly as `tools/list`
     * publishes them (before the per-token grant filter).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function liveClientScopedToolDefinitions(): array
    {
        // CIPP publishes on TWO paths, and each must be gated on the SAME predicate its
        // executor uses — publish and dispatch answering one question, not two (psa-wzjzz).
        //
        //   - Static curated writes (cippWriteTools) execute against the CIPP REST API
        //     (CippClient), whose liveness is isEnabled() && isConfigured().
        //   - The DYNAMIC catalog (dynamicCippRead/WriteTools) executes through
        //     CippMcpDynamicToolExecutor, which refuses on !isMcpRelayEnabled()
        //     (= isEnabled() && cipp_mcp_enabled && isMcpConfigured()) — a DIFFERENT
        //     subsystem with its own sub-switch and credentials.
        //
        // The first cut of this fix gated the dynamic rows on isEnabled() alone. That closed
        // the cipp_enabled='0' bypass but left a new split: with the integration on and the
        // relay sub-switch off, the tool published as live and then refused at execution.
        // Gating each path on its own executor's predicate removes the divergence by
        // construction — there is no state where one says live and the other refuses.
        //
        // Gating happens at THIS publication site, not inside the dynamicCipp* helpers,
        // because those also feed McpToolRegistry::groups() — the GRANT CATALOG, deliberately
        // ungated so a tool can be pre-granted before its integration is switched on. Gating
        // the helper would conflate "grantable" with "live".
        $cippRestLive = CippConfig::isEnabled() && CippConfig::isConfigured();
        $cippMcpLive = CippConfig::isMcpRelayEnabled();

        return array_merge(
            AssistantToolDefinitions::getTools(hasClient: true),
            ChetDataSurfaceTools::clientTools(),
            $cippMcpLive ? McpToolRegistry::dynamicCippReadTools() : [],
            $cippMcpLive ? McpToolRegistry::dynamicCippWriteTools() : [],
            $cippRestLive ? McpToolRegistry::cippWriteTools() : [],
            TacticalConfig::isConfigured() ? McpToolRegistry::tacticalActionTools() : [],
        );
    }

    /**
     * Names of every tool live on this instance right now (config-gated,
     * grant-agnostic).
     *
     * @return array<int, string>
     */
    public static function liveToolNames(): array
    {
        $names = [];

        foreach (array_merge(self::liveGeneralToolDefinitions(), self::liveClientScopedToolDefinitions()) as $tool) {
            if (isset($tool['name']) && is_string($tool['name']) && $tool['name'] !== '') {
                $names[$tool['name']] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Classify the full grant catalog for one caller. `$granted` is the
     * caller's live grant check (the controller's toolAllowed); null means no
     * grant context (internal callers) — nothing classifies as granted.
     *
     * @param  callable(string): bool|null  $granted
     * @return array<int, array{name: string, category: string, state: string, description: string}>
     */
    public static function classify(?callable $granted): array
    {
        $live = array_flip(self::liveToolNames());
        $entries = [];

        foreach (McpToolRegistry::groups() as $categoryKey => $group) {
            foreach ($group['tools'] as $tool) {
                $name = (string) $tool['name'];
                $entries[] = [
                    'name' => $name,
                    'category' => $categoryKey,
                    'state' => self::stateFor($name, isset($live[$name]), $granted),
                    'description' => self::oneLineDescription((string) ($tool['description'] ?? '')),
                ];
            }
        }

        return $entries;
    }

    /**
     * Classify specific catalog tool names for one caller. Unknown names are
     * skipped. The live-surface assembly only runs when `$names` is non-empty,
     * so no-match callers (the common request_tool case) pay nothing.
     *
     * @param  array<int, string>  $names
     * @param  callable(string): bool|null  $granted
     * @return array<string, string> tool name => state
     */
    public static function classifyNames(array $names, ?callable $granted): array
    {
        $names = array_values(array_intersect($names, McpToolRegistry::allToolNames()));
        if ($names === []) {
            return [];
        }

        $live = array_flip(self::liveToolNames());
        $states = [];

        foreach ($names as $name) {
            $states[$name] = self::stateFor($name, isset($live[$name]), $granted);
        }

        return $states;
    }

    /**
     * Deterministic catalog-tool mention detection for request_tool
     * auto-classification. Matches exact snake_case tool-name tokens
     * ("huntress_list_escalations") and spaced tool names ("create ticket
     * from email") against the grant catalog. Purely lexical — no AI call.
     *
     * @return array<int, string> catalog tool names mentioned in the text
     */
    public static function matchCatalogTools(string $text): array
    {
        $normalized = ' '.trim((string) preg_replace('/[^a-z0-9_]+/', ' ', mb_strtolower($text))).' ';
        if (trim($normalized) === '') {
            return [];
        }

        $matches = [];

        foreach (McpToolRegistry::allToolNames() as $name) {
            if (str_contains($normalized, ' '.$name.' ')
                || str_contains($normalized, ' '.str_replace('_', ' ', $name).' ')) {
                $matches[] = $name;
            }
        }

        // Most specific first: "create ticket from email" mentions both
        // create_ticket and create_ticket_from_email — the longer match is
        // the real reference.
        usort($matches, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $matches;
    }

    /** @param  callable(string): bool|null  $granted */
    private static function stateFor(string $name, bool $isLive, ?callable $granted): string
    {
        if (! $isLive) {
            return self::STATE_UNAVAILABLE_CONFIG;
        }

        return $granted !== null && $granted($name)
            ? self::STATE_GRANTED
            : self::STATE_AVAILABLE_UNGRANTED;
    }

    /**
     * Catalog descriptions run to several sentences; the surface listing wants
     * one line. First sentence, capped.
     */
    private static function oneLineDescription(string $description): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $description));

        $period = mb_strpos($text, '. ');
        if ($period !== false) {
            $text = mb_substr($text, 0, $period + 1);
        }

        if (mb_strlen($text) > self::DESCRIPTION_MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH - 1)).'…';
        }

        return $text;
    }
}
