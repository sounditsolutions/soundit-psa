<?php

namespace App\Services\ControlD;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalClient;
use App\Support\ControlDConfig;
use App\Support\TacticalConfig;

/**
 * P7A74iGD (b): the deploy step of the one-approval Control D onboarding plan. It runs only
 * after the plan's code step is bound in the same run, or when the code was already bound
 * before (the caller's gate; StaffControlDOnboardingToolExecutor::approvePlan()).
 *
 * WHAT IT DOES, in order (rulings on card P7A74iGD, 18:43 and 19:01 PT):
 *  1. Resolve the client's Tactical client by the name pinned at staging (GET clients/, an
 *     exact, case-sensitive match on exactly one row) and read that client's record
 *     (GET clients/{id}/) for the configured client custom field.
 *  2. A field value that is non-empty and not equal (hash_equals) to the client's bound
 *     provisioning code refuses the step: no write and no script run. An equal value is left
 *     as it is. Only an empty (absent, null or '') value is written.
 *  3. Write: TacticalClient::setClientCustomField() with the bound code, then read the record
 *     back (GET clients/{id}/). A failed write, or a read-back that does not show the bound
 *     code, ends the step uncertain and no script is run. Nothing here retries.
 *  4. Agents: GET agents/?client={id}; only rows whose client_name is the pinned Tactical
 *     client name count as under the client. `all` runs on every such agent; a selected
 *     list runs only on its pinned agents, each refused unless it is under the client and
 *     its local agent→asset link still names the PSA asset pinned at staging.
 *  5. The deploy script (setting ControlDConfig::TACTICAL_DEPLOY_SCRIPT_SETTING) is started on
 *     each agent with runScriptAsync() and NO argument override (args null, sent as []), so
 *     the script's own client-field substitution supplies the value. "started" means Tactical
 *     accepted the run request; it does not mean the agent installed anything.
 *
 * The code is read from the client record only at steps 2–3 and goes nowhere but the field
 * write and the two comparisons: never into script arguments, results, audit text or logs.
 * No setting or id is hard-coded: an unset field id or script id refuses (fail closed).
 */
class ControlDTacticalDeploy
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_SELECTED = 'selected';

    /** At most this many PSA assets in one selected deploy. */
    public const MAX_ASSETS = 200;

    /** Per-agent results (status only). */
    public const AGENT_STARTED = 'started';

    public const AGENT_REFUSED = 'refused';

    public const AGENT_FAILED = 'failed';

    /** Step outcomes. */
    public const STARTED = 'started';

    public const PARTIAL = 'partial';

    public const REFUSED = 'refused';

    public const UNCERTAIN = 'uncertain';

    public const FAILED = 'failed';

    public function __construct(private readonly TacticalClient $tactical) {}

    /**
     * Run the deploy step for $client under $pins (already re-derived and compared by the
     * caller). Returns status only, never the code:
     * ['outcome' => started|partial|failed|refused|uncertain, 'reason' => text,
     *  'field' => written|equal|untouched|unknown, 'tactical_client_id' => ?int,
     *  'agents' => [agent_id => started|refused|failed]].
     *
     * @return array{outcome: string, reason: string, field: string, tactical_client_id: ?int, agents: array<string, string>}
     */
    public function execute(Client $client, array $pins): array
    {
        $result = ['outcome' => self::REFUSED, 'reason' => '', 'field' => 'untouched', 'tactical_client_id' => null, 'agents' => []];
        $fieldId = ControlDConfig::tacticalClientOrgFieldId();
        $scriptId = ControlDConfig::tacticalDeployScriptId();
        if ($fieldId === null || $scriptId === null) {
            return ['reason' => 'the Tactical client custom field ID or the deploy script ID is not set'] + $result;
        }
        $name = (string) ($pins['tactical_client'] ?? '');
        $scope = $pins['scope'] ?? null;
        $code = Client::find($client->id)?->controld_provisioning_code;
        if ($name === '' || ! in_array($scope, [self::SCOPE_ALL, self::SCOPE_SELECTED], true)) {
            return ['reason' => 'the deploy pins are malformed'] + $result;
        }
        if (! is_string($code) || $code === '') {
            return ['reason' => 'the client has no bound provisioning code'] + $result;
        }

        // 1. The Tactical client: exactly one row with the pinned name (exact case).
        try {
            $rows = $this->tactical->getClients();
            $ids = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row) && ($row['name'] ?? null) === $name && is_int($row['id'] ?? null) && $row['id'] > 0) {
                    $ids[] = $row['id'];
                }
            }
            if (count($ids) !== 1) {
                return ['reason' => 'Tactical does not list exactly one client with the pinned name'] + $result;
            }
            $tacticalId = $ids[0];
            $result['tactical_client_id'] = $tacticalId;
            $current = $this->fieldValue($tacticalId, $fieldId);
        } catch (\Throwable) {
            return ['reason' => 'the Tactical client or its custom field could not be read'] + $result;
        }
        if ($current === false) {
            return ['reason' => 'the Tactical client custom field could not be read in a known shape'] + $result;
        }

        // 2–3. The client custom field: refuse a different value; write only an empty one.
        if ($current !== null && ! hash_equals($code, $current)) {
            return ['reason' => 'the Tactical client custom field already holds a different value; it was left as it is'] + $result;
        }
        if ($current === null) {
            $result['field'] = 'unknown';
            try {
                $this->tactical->setClientCustomField($tacticalId, $fieldId, $code);
                $readBack = $this->fieldValue($tacticalId, $fieldId);
            } catch (\Throwable) {
                return ['outcome' => self::UNCERTAIN, 'reason' => 'the custom field write or its read-back failed, so whether the field was set is unknown; no script was run'] + $result;
            }
            if (! is_string($readBack) || ! hash_equals($code, $readBack)) {
                return ['outcome' => self::UNCERTAIN, 'reason' => 'the read-back did not show the bound code in the custom field; no script was run'] + $result;
            }
            $result['field'] = 'written';
        } else {
            $result['field'] = 'equal';
        }

        return $this->runOnAgents($client, $pins, $tacticalId, $name, $scriptId, $result);
    }

    /**
     * 4–5. Resolve the agents under the Tactical client and start the script on each target
     * with no argument override.
     */
    private function runOnAgents(Client $client, array $pins, int $tacticalId, string $name, int $scriptId, array $result): array
    {
        try {
            $rows = $this->tactical->get('agents/?client='.$tacticalId);
        } catch (\Throwable) {
            return ['reason' => 'the client custom field is set, but the client\'s agents could not be read; no script was run'] + $result;
        }
        $under = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && ($row['client_name'] ?? null) === $name && is_string($row['agent_id'] ?? null) && $row['agent_id'] !== '') {
                $under[$row['agent_id']] = true;
            }
        }
        $targets = [];
        if ($pins['scope'] === self::SCOPE_ALL) {
            foreach (array_keys($under) as $agentId) {
                $targets[(string) $agentId] = true;
            }
        } else {
            foreach ($pins['assets'] ?? [] as $pair) {
                [$assetId, $agentId] = is_array($pair) && count($pair) === 2 ? $pair : [0, ''];
                $ok = is_int($assetId) && is_string($agentId) && isset($under[$agentId]) && self::linkedAgent($client, $assetId) === $agentId;
                $targets[(string) $agentId] = $ok;
            }
        }
        if ($targets === []) {
            return ['reason' => 'Tactical lists no agents under the client; no script was run'] + $result;
        }
        foreach ($targets as $agentId => $ok) {
            if (! $ok) {
                $result['agents'][$agentId] = self::AGENT_REFUSED;

                continue;
            }
            try {
                // No argument override: args null (sent as []), so the script substitutes the field itself.
                $this->tactical->runScriptAsync($agentId, $scriptId, null);
                $result['agents'][$agentId] = self::AGENT_STARTED;
            } catch (\Throwable) {
                $result['agents'][$agentId] = self::AGENT_FAILED;
            }
        }
        $counts = array_count_values($result['agents']);
        $started = $counts[self::AGENT_STARTED] ?? 0;
        $result['outcome'] = $started === count($result['agents']) ? self::STARTED
            : ($started > 0 ? self::PARTIAL : (($counts[self::AGENT_FAILED] ?? 0) > 0 ? self::FAILED : self::REFUSED));
        $result['reason'] = "script run requested on {$started} of ".count($result['agents']).' agent'.(count($result['agents']) === 1 ? '' : 's')
            .' (refused '.($counts[self::AGENT_REFUSED] ?? 0).', failed '.($counts[self::AGENT_FAILED] ?? 0).')';

        return $result;
    }

    /**
     * The client custom field $fieldId on Tactical client $tacticalId (GET clients/{id}/):
     * null when absent, null or '' (empty), the string when set, false for any other shape.
     * Throws on a failed read. ClientSerializer emits custom_fields rows of
     * {id, field, client, value} (string_value is write-only; tacticalrmm clients/serializers.py).
     */
    private function fieldValue(int $tacticalId, int $fieldId): string|false|null
    {
        $record = $this->tactical->get("clients/{$tacticalId}/");
        $fields = $record['custom_fields'] ?? null;
        if (! is_array($fields) || ($record['id'] ?? null) !== $tacticalId) {
            return false;
        }
        $values = [];
        foreach ($fields as $row) {
            if (! is_array($row)) {
                return false;
            }
            if (($row['field'] ?? null) === $fieldId) {
                $values[] = array_key_exists('value', $row) ? $row['value'] : null;
            }
        }
        if (count($values) > 1) {
            return false;
        }
        $value = $values[0] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : false;
    }

    /**
     * Local only (no vendor call): the deploy pins for $requested, or ['error' => text]. Used at
     * staging and again at approval; approval refuses when the two differ.
     * $requested is 'all' or a list of PSA asset ids.
     *
     * @return array{scope?: string, tactical_client?: string, assets?: list<array{0: int, 1: string}>, error?: string}
     */
    public static function pins(Client $client, mixed $requested): array
    {
        if (! TacticalConfig::isEnabled()) {
            return ['error' => 'The deploy step needs the Tactical RMM integration, which is not enabled or not configured on this instance.'];
        }
        if (ControlDConfig::tacticalClientOrgFieldId() === null) {
            return ['error' => 'The deploy step needs the Tactical client custom field ID (Settings > Integrations > Control D), which is not set.'];
        }
        if (ControlDConfig::tacticalDeployScriptId() === null) {
            return ['error' => 'The deploy step needs the Tactical deploy script ID (Settings > Integrations > Control D), which is not set.'];
        }
        $site = is_string($client->tactical_site_id) ? trim($client->tactical_site_id) : '';
        $name = $site === '' ? '' : trim(explode('|', $site, 2)[0]);
        if ($name === '') {
            return ['error' => 'The deploy step needs this client mapped to a Tactical RMM client, and it is not.'];
        }
        if ($requested === self::SCOPE_ALL) {
            return ['scope' => self::SCOPE_ALL, 'tactical_client' => $name, 'assets' => []];
        }
        if (! is_array($requested) || $requested === [] || ! array_is_list($requested) || count($requested) > self::MAX_ASSETS) {
            return ['error' => 'deploy must be "all" or a list of 1 to '.self::MAX_ASSETS.' asset ids of this client.'];
        }
        $ids = [];
        foreach ($requested as $id) {
            $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int) $id : 0);
            if ($id < 1 || in_array($id, $ids, true)) {
                return ['error' => 'deploy must be "all" or a list of distinct positive asset ids of this client.'];
            }
            $ids[] = $id;
        }
        sort($ids);
        $assets = [];
        foreach ($ids as $id) {
            $agent = self::linkedAgent($client, $id);
            if ($agent === null) {
                return ['error' => "Asset #{$id} is not a current asset of this client linked to exactly one Tactical agent, so it cannot be deployed to."];
            }
            $assets[] = [$id, $agent];
        }

        return ['scope' => self::SCOPE_SELECTED, 'tactical_client' => $name, 'assets' => $assets];
    }

    /** The one Tactical agent id linked to this client's live asset $assetId, or null. Local only. */
    private static function linkedAgent(Client $client, int $assetId): ?string
    {
        $asset = Asset::whereKey($assetId)->where('client_id', $client->id)->first();
        if ($asset === null) {
            return null;
        }
        $agents = TacticalAsset::where('asset_id', $asset->id)->pluck('agent_id')->all();
        if (count($agents) !== 1 || ! is_string($agents[0]) || $agents[0] === '') {
            return null;
        }

        return $agents[0];
    }

    /** Plain words for the proposal: the device list (ids and names from the PSA record; no secret). */
    public static function describe(array $pins): string
    {
        if (($pins['scope'] ?? null) === self::SCOPE_ALL) {
            return 'all devices that Tactical lists under its client "'.($pins['tactical_client'] ?? '').'" when this is approved';
        }
        $names = [];
        foreach ($pins['assets'] ?? [] as [$assetId, $agentId]) {
            $label = (string) (Asset::whereKey($assetId)->value('name') ?? '');
            $names[] = ($label !== '' ? "{$label} " : '')."(asset #{$assetId}, agent {$agentId})";
        }

        return count($names).' named device'.(count($names) === 1 ? '' : 's').': '.implode(', ', $names);
    }
}
