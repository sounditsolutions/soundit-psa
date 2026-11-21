<?php

namespace App\Services\ControlD;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Support\ControlDConfig;
use App\Support\TacticalConfig;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

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
 *     each agent with runScriptAsync() (POST agents/{id}/runscript/) and EXACTLY the argument
 *     list pinned with the plan (#8466). That list is the script's own `args` as Tactical stored
 *     them, read at staging by scriptArgs() and again at approval, where a changed list is
 *     drift. It is kept only in the plan's encrypted payload: the card shows how many
 *     arguments there are and that the placeholder is among them, never a value.
 *
 * THE PLACEHOLDER (inferred from Tactical's source, scripts/models.py parse_script_args and
 * tacticalrmm/utils.py get_db_value; to be verified at the production done-check). The PSA sends
 * the argument list with the literal self::CODE_PLACEHOLDER in it. Inferred, not observed: before
 * Tactical hands the arguments to the agent it substitutes the {{…}} span of an argument with
 * the value of the CLIENT custom field NAMED ControlDOrgCode for the agent's client, so an
 * argument with a second {{…}} token is not filled as described. Tactical looks the field up by
 * that name; the field step 3 writes is chosen by the configured field id. So the deploy works
 * only when the configured field id is the client field named ControlDOrgCode. Nothing here
 * checks that. When the named field resolves to nothing, Tactical passes the argument on
 * unaltered. Tactical does not fall back to a script's stored arguments when the request
 * sends none, so an empty list would start the script without the code. "started" means
 * Tactical accepted the run request; it does not mean the agent installed anything.
 *
 * WHERE THE CODE IS READ. The decrypted code is read from the client record (a) at staging
 * and again at approval, by StaffControlDOnboardingToolExecutor::derivePlan(), only to check
 * that no argument of the script contains it (an undecryptable stored code refuses there,
 * audited, with no exception text); (b) once in execute(), before step 1, to refuse a pinned
 * argument that contains it; and (c) execute() reuses that one read at steps 2 and 3, for the
 * comparison with the field and the field write. It goes nowhere but the field write and
 * those comparisons: never into script arguments, results, audit text or logs. The arguments
 * the PSA sends carry the placeholder, not the code. A script argument that contains the
 * bound code, or that still carries a code-shaped run once every placeholder is taken out of
 * it, refuses at staging, at approval and in execute() before any Tactical request.
 * ControlDClient::carriesCodeRun() decides
 * "code-shaped": 32 or more letters and digits in one run (invisible format and control
 * characters ignored), or pieces separated only by whitespace, each holding a digit or 16 or
 * more characters long, that together make 32 or more. The refusal names the argument's
 * position, never its value. The screen is per argument and joins pieces only across
 * whitespace, so a code split across two arguments, or split by punctuation inside one
 * argument, is not caught (a stated limit, Jeeves r2 split-code ruling A).
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

    /** #8466: the Tactical client-field placeholder the deploy script's own arguments must carry. */
    public const CODE_PLACEHOLDER = '{{client.ControlDOrgCode}}';

    /**
     * #8466 r3: what ControlDClient::carriesCodeRun() flags, in the words every refusal uses. True
     * of every argument it can be printed for: the run is either contiguous (once invisible
     * characters are dropped) or rejoined from pieces separated only by whitespace, and pieces
     * are joined only when each holds a digit or is 16 or more characters long.
     */
    public const CODE_SHAPE_WORDS = '(32 or more letters and digits, in one run once invisible characters are dropped, or in pieces separated only by whitespace, each holding a digit or 16 or more characters long)';

    public function __construct(private readonly TacticalClient $tactical) {}

    /**
     * Run the deploy step for $client under $pins (already re-derived and compared by the
     * caller). The Tactical field and script ids are the pinned ones: when either setting no longer
     * equals its pin, the step refuses before any Tactical request. Returns status only, never the code:
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
        if (($pins['field_id'] ?? null) !== $fieldId || ($pins['script_id'] ?? null) !== $scriptId) {
            return ['reason' => 'the Tactical client custom field ID or the deploy script ID differs from the one pinned on the card'] + $result;
        }
        $name = (string) ($pins['tactical_client'] ?? '');
        $scope = $pins['scope'] ?? null;
        $args = $pins['script_args'] ?? null;
        if ($name === '' || ! in_array($scope, [self::SCOPE_ALL, self::SCOPE_SELECTED], true) || self::argsProblem($args) !== null) {
            return ['reason' => 'the deploy pins are malformed'] + $result;
        }
        try {
            $code = Client::find($client->id)?->controld_provisioning_code;
        } catch (DecryptException) {
            return ['reason' => 'the client\'s stored provisioning code cannot be decrypted; no script was run'] + $result;
        }
        if (! is_string($code) || $code === '') {
            return ['reason' => 'the client has no bound provisioning code'] + $result;
        }
        foreach ($args as $i => $arg) {
            if (str_contains($arg, $code)) {
                return ['reason' => 'pinned script argument '.($i + 1).' contains the provisioning code itself; the PSA sends only the placeholder, so no script was run'] + $result;
            }
        }
        $run = self::codeShapedArgument($args);
        if ($run !== null) {
            return ['reason' => 'pinned script argument '.$run.' carries a code-shaped run '.self::CODE_SHAPE_WORDS.' besides the placeholder; no script was run'] + $result;
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

        return $this->runOnAgents($client, $pins, $tacticalId, $name, $scriptId, $args, $result);
    }

    /**
     * 4–5. Resolve the agents under the Tactical client and start the script on each target
     * with exactly the pinned argument list.
     *
     * @param  list<string>  $args
     */
    private function runOnAgents(Client $client, array $pins, int $tacticalId, string $name, int $scriptId, array $args, array $result): array
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
                // #8466: exactly the pinned list; Tactical fills the placeholder from the client field named ControlDOrgCode (inferred; see the class docblock).
                $this->tactical->runScriptAsync($agentId, $scriptId, $args);
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
     * #8466: the deploy script's own argument list as Tactical stores it (GET scripts/{id}/,
     * the `args` field), or ['error' => text]. Read at staging and again at approval; the
     * caller pins it and compares it. Only `args` is taken from the response: the script body
     * and every other field are never returned, stored, audited or logged. A failed request, an
     * answer that is not this script's record, or the script's record without an `args` key
     * refuses through scriptReadFailure() (status, class, one warning line; each with its own
     * words), never as a problem with the args. Otherwise refuses unless the
     * list is non-empty, holds only strings, and one of them contains self::CODE_PLACEHOLDER.
     * There is no fallback for an empty list. The caller screens the list for the code and for
     * code-shaped runs (codeShapedArgument()).
     *
     * @return array{args?: list<string>, error?: string, read_failed?: true}
     */
    public function scriptArgs(int $scriptId): array
    {
        try {
            // Typed array: a 200 whose JSON is not an object throws a TypeError here, caught below.
            $detail = $this->tactical->getScriptDetail($scriptId);
        } catch (\Throwable $e) {
            return self::scriptReadFailure($scriptId, $e);
        }
        // C-56: an answer that is not this script's record, or the record without an `args` key, is a
        // degraded read, reported as such, never as a problem with the args.
        // (r3: the old `! is_array($detail)` arm is gone; getScriptDetail() is typed array, so it could not fire.)
        if (($detail['id'] ?? null) !== $scriptId || ! array_key_exists('args', $detail)) {
            return self::scriptReadFailure($scriptId, null, ($detail['id'] ?? null) === $scriptId ? self::READ_NO_ARGS_FIELD : self::READ_OTHER_RECORD);
        }
        $args = $detail['args'];
        $problem = self::argsProblem($args);
        if ($problem !== null) {
            return ['error' => "The deploy script's arguments in Tactical {$problem}. The deploy step runs the script with its own arguments, and they must include ".self::CODE_PLACEHOLDER.', which Tactical fills from the client custom field named ControlDOrgCode (inferred from Tactical\'s source and to be verified at the production done-check; it works only when the configured client custom field id is that field).'];
        }

        return ['args' => $args];
    }

    /** scriptReadFailure() $answer: Tactical answered with a record that is not the script's. */
    public const READ_OTHER_RECORD = 'other_record';

    /** scriptReadFailure() $answer: Tactical answered with the script's record, which has no `args` field. */
    public const READ_NO_ARGS_FIELD = 'no_args_field';

    /**
     * C-56 (#8466): the refusal when the deploy script's arguments could not be taken from
     * Tactical, with the HTTP status (when there is one) and the exception class, and one
     * warning log line that carries the script id, the status and the class only: never the args
     * or a response body. Each case has its own words (r3: every sentence true of its case):
     *  - a TacticalClientException with a status: Tactical answered with that HTTP status;
     *  - a TacticalClientException without one: the read got no answer (transport, or refused
     *    before sending);
     *  - any other Throwable from the read (a 2xx whose JSON is not an object ends as a TypeError
     *    in the array-typed read): the read ended in that error;
     *  - $e null: Tactical answered, with another record (self::READ_OTHER_RECORD) or with the
     *    script's record lacking an `args` field (self::READ_NO_ARGS_FIELD).
     *
     * @return array{error: string, read_failed: true}
     */
    public static function scriptReadFailure(int $scriptId, ?\Throwable $e, string $answer = self::READ_OTHER_RECORD): array
    {
        $status = $e instanceof TacticalClientException ? $e->statusCode() : null;
        $class = $e === null ? null : $e::class;
        Log::warning('[ControlDTacticalDeploy] The deploy script\'s arguments could not be taken from Tactical', ['script_id' => $scriptId, 'status' => $status, 'exception' => $class]);
        if ($e === null) {
            $detail = $answer === self::READ_NO_ARGS_FIELD
                ? 'Tactical answered with the script\'s record, but the record has no args field'
                : 'Tactical answered, but not with the script\'s record';

            return ['error' => "The deploy step needs the deploy script's own arguments, and {$detail}. The answer could not be used; this is not a problem with the script's arguments.", 'read_failed' => true];
        }
        $detail = match (true) {
            $status !== null => "Tactical answered the read with HTTP {$status} (".class_basename($class).'). This is a failed read',
            $e instanceof TacticalClientException => 'the read got no answer from Tactical ('.class_basename($class).'). This is a failed read',
            default => 'the read ended in an error in the PSA ('.class_basename($class).'; an answer that is not a JSON object ends this way)',
        };

        return ['error' => "The deploy step needs the deploy script's own arguments, and {$detail}; this is not a problem with the script's arguments.", 'read_failed' => true];
    }

    /**
     * #8466 r3 (contract:5, diff:7): the refusal when the deploy step faulted in the PSA before it
     * had the script's arguments (resolving or constructing the Tactical client, for example). No
     * read result is claimed: Tactical may not have been contacted. One warning line with the
     * script id and the exception class only.
     *
     * @return array{error: string}
     */
    public static function scriptArgsFault(int $scriptId, \Throwable $e): array
    {
        Log::warning('[ControlDTacticalDeploy] The deploy step faulted before it had the script\'s arguments', ['script_id' => $scriptId, 'exception' => $e::class]);

        return ['error' => "The deploy step needs the deploy script's own arguments, and it faulted in the PSA before it had them (".class_basename($e::class)."). This is not a problem with the script's arguments."];
    }

    /**
     * #8466 (Jeeves, diff:1 A): the 1-based position of the first argument that, once every
     * occurrence of the exact self::CODE_PLACEHOLDER is taken out of it, still carries a
     * code-shaped run (ControlDClient::carriesCodeRun(); the class docblock says exactly what that
     * flags); null when none does. Fails closed: a legitimate literal of that shape refuses too.
     * Per argument only, so a code split across two arguments is not caught (stated limit).
     *
     * @param  list<string>  $args
     */
    public static function codeShapedArgument(array $args): ?int
    {
        foreach (array_values($args) as $i => $arg) {
            if (ControlDClient::carriesCodeRun(str_replace(self::CODE_PLACEHOLDER, '', $arg))) {
                return $i + 1;
            }
        }

        return null;
    }

    /** Why $args cannot be the deploy script's argument list, or null when it can. */
    private static function argsProblem(mixed $args): ?string
    {
        if (! is_array($args) || $args === [] || ! array_is_list($args)) {
            return 'are empty or not a list';
        }
        $found = false;
        foreach ($args as $arg) {
            if (! is_string($arg)) {
                return 'are not all text';
            }
            $found = $found || str_contains($arg, self::CODE_PLACEHOLDER);
        }

        return $found ? null : 'do not include '.self::CODE_PLACEHOLDER;
    }

    /**
     * Local only (no vendor call): the deploy pins for $requested, or ['error' => text]. Used at
     * staging and again at approval; approval refuses when the two differ.
     * $requested is 'all' or a list of PSA asset ids.
     *
     * @return array{scope?: string, tactical_client?: string, assets?: list<array{0: int, 1: string}>, field_id?: int, script_id?: int, error?: string}
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
            return ['scope' => self::SCOPE_ALL, 'tactical_client' => $name, 'assets' => [], 'field_id' => ControlDConfig::tacticalClientOrgFieldId(), 'script_id' => ControlDConfig::tacticalDeployScriptId()];
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

        return ['scope' => self::SCOPE_SELECTED, 'tactical_client' => $name, 'assets' => $assets, 'field_id' => ControlDConfig::tacticalClientOrgFieldId(), 'script_id' => ControlDConfig::tacticalDeployScriptId()];
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
