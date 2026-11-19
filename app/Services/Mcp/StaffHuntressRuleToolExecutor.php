<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Huntress\HuntressClientException;
use App\Services\Huntress\HuntressReadOnlyToolset;
use App\Services\Huntress\HuntressWriteClient;
use App\Services\Huntress\HuntressWriteScopeException;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Technician\PromptFence;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\HuntressConfig;
use App\Support\TechnicianConfig;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Huntress unwanted access rules: create, update and delete, each with a staged
 * twin. The token's grant decides the lane (McpToolModes): an immediate grant
 * runs at once, a staged grant holds the call for cockpit approval. Dispatched
 * through StaffHuntressActionToolExecutor, so the huntress_action family's
 * explicit-grant gate, client_id requirement and approval route apply.
 *
 * FENCE. The Huntress organization always comes from the client's mapping
 * (clients.huntress_organization_id), never from the caller:
 *  - create sends organization_id = the mapped org. identity_id, logic and
 *    category are refused, so neither an account-scope nor an identity-scope
 *    rule can be expressed;
 *  - update and delete first read the rule through the read path
 *    (HuntressReadOnlyToolset huntress_get_unwanted_access_rule, client
 *    scoped), which refuses unless applied_to is {type: Organization, id:
 *    the mapped org}. Account, identity and unclassified scopes refuse there.
 * Every refusal sends nothing. Approval re-checks the mapping and re-reads the
 * rule, so a remap while the call is held cannot widen the write.
 *
 * BODY. Only the named fields of the vendor's UnwantedAccessRuleCreationParameters
 * / UnwantedAccessRuleUpdateParameters reach Huntress; any other argument key is
 * refused and nothing is sent. A comma in a rule value is refused: the vendor
 * creates one rule per value but returns only the last, so one rule per call
 * keeps the read-back complete.
 *
 * RESPONSE. After create/update the rule is read back by id through the same
 * read path (sanitised, UNTRUSTED-fenced strings). After delete the rule as read
 * before the delete is returned, with a GET by id that must answer 404.
 * Vendor failures are reported by HTTP status and exception class only (C-56).
 */
class StaffHuntressRuleToolExecutor
{
    public const CREATE = 'huntress_create_unwanted_access_rule';

    public const UPDATE = 'huntress_update_unwanted_access_rule';

    public const DELETE = 'huntress_delete_unwanted_access_rule';

    /** @var array<string, string> */
    private const STAGED_TO_DIRECT = [
        'huntress_stage_create_unwanted_access_rule' => self::CREATE,
        'huntress_stage_update_unwanted_access_rule' => self::UPDATE,
        'huntress_stage_delete_unwanted_access_rule' => self::DELETE,
    ];

    private const VALUE_KEYS = ['country_code', 'vpn', 'ip_address'];

    private const REASON_MAX = 500;

    private const NOTES_MAX = 1000;

    private const MAX_RUN_GENERATIONS = 20;

    private const VALUE_MAX = 100;

    /** @var array<string, array<int, string>> Argument keys each tool accepts; everything else is refused. */
    private const ALLOWED_ARGUMENT_KEYS = [
        self::CREATE => ['country_code', 'vpn', 'ip_address', 'type', 'starts_at', 'expires_at', 'notes', 'ticket_id', 'reason'],
        self::UPDATE => ['rule_id', 'starts_at', 'expires_at', 'notes', 'ticket_id', 'reason'],
        self::DELETE => ['rule_id', 'ticket_id', 'reason'],
    ];

    public function __construct(
        private readonly HuntressWriteClient $writeClient,
        private readonly ActionRedactor $redactor,
        private readonly PromptFence $fence,
    ) {}

    /** @return array<string, string> */
    public static function stagedToDirectMap(): array
    {
        return self::STAGED_TO_DIRECT;
    }

    public static function isStagedActionType(string $actionType): bool
    {
        return array_key_exists($actionType, self::STAGED_TO_DIRECT);
    }

    public static function handles(string $toolName): bool
    {
        return isset(self::ALLOWED_ARGUMENT_KEYS[$toolName]) || isset(self::STAGED_TO_DIRECT[$toolName]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        $value = fn (string $what): array => ['type' => 'string', 'description' => "One {$what}. Exactly one of country_code, vpn or ip_address. A comma is refused: make one call per value."];
        $date = fn (string $what): array => ['type' => 'string', 'description' => "UTC date YYYY-MM-DD when the rule {$what}."];
        $create = [
            'country_code' => $value('ISO 3166-1 alpha-2 country code in capitals, e.g. US'),
            'vpn' => $value('VPN tunnel operator name as Huntress names it (see huntress_list_known_vpns)'),
            'ip_address' => $value('IPv4 or IPv6 address or CIDR range; ip_address rules must be type expected'),
            'type' => ['type' => 'string', 'enum' => ['expected', 'unauthorized'], 'description' => 'Whether matching access is expected or unauthorized.'],
            'starts_at' => $date('becomes active; expected rules only'),
            'expires_at' => $date('expires; expected rules only'),
            'notes' => ['type' => 'string', 'description' => 'Optional note stored on the rule.'],
            'reason' => ['type' => 'string', 'description' => 'Why this rule is being written. Recorded in the audit log and shown to an approver.'],
        ];
        $rule = ['rule_id' => ['type' => 'integer', 'description' => 'Huntress unwanted access rule id (from huntress_list_unwanted_access_rules). The rule is read first and must apply to this client\'s mapped Huntress organization.']];
        $update = $rule + [
            'starts_at' => ['type' => 'string', 'description' => 'UTC date YYYY-MM-DD when the rule becomes active; an empty string clears it.'],
            'expires_at' => ['type' => 'string', 'description' => 'UTC date YYYY-MM-DD when the rule expires; an empty string clears it.'],
            'notes' => ['type' => 'string', 'description' => 'Note on the rule; an empty string clears it.'],
            'reason' => $create['reason'],
        ];
        $delete = $rule + ['reason' => $create['reason']];
        $ticket = ['ticket_id' => ['type' => 'integer', 'description' => 'PSA ticket of this client the staged proposal is held on.']];
        $fence = ' The Huntress organization is always the one mapped to client_id; other organizations, identities and the whole account are out of reach, and any parameter not listed is refused with nothing sent.';
        $grant = ' Runs at once or is held for cockpit approval according to this token\'s grant.';

        return [
            ['name' => self::CREATE, 'description' => 'Create ONE Huntress unwanted access rule for this client\'s mapped Huntress organization (POST /v1/unwanted_access_rules with organization_id set from the mapping). Returns the rule read back after the write.'.$fence.$grant,
                'input_schema' => ['type' => 'object', 'properties' => $create, 'required' => ['type', 'reason']]],
            ['name' => 'huntress_stage_create_unwanted_access_rule', 'description' => 'Stage the creation of one Huntress unwanted access rule for cockpit approval. Nothing is sent until approved; the card shows the method, path and body as they will be sent.'.$fence,
                'input_schema' => ['type' => 'object', 'properties' => $create + $ticket, 'required' => ['type', 'ticket_id', 'reason']]],
            ['name' => self::UPDATE, 'description' => 'Change the start date, expiry date or note of ONE Huntress unwanted access rule of this client\'s mapped organization (PATCH /v1/unwanted_access_rules/{id}). The rule is read first; the category, value, scope and type cannot be changed. Returns the rule read back after the write.'.$fence.$grant,
                'input_schema' => ['type' => 'object', 'properties' => $update, 'required' => ['rule_id', 'reason']]],
            ['name' => 'huntress_stage_update_unwanted_access_rule', 'description' => 'Stage a change to one Huntress unwanted access rule for cockpit approval. The rule is read first and again at approval.'.$fence,
                'input_schema' => ['type' => 'object', 'properties' => $update + $ticket, 'required' => ['rule_id', 'ticket_id', 'reason']]],
            ['name' => self::DELETE, 'description' => 'Delete ONE Huntress unwanted access rule of this client\'s mapped organization (DELETE /v1/unwanted_access_rules/{id}). The rule is read first. Returns the rule as read before the delete and whether a read by id then answers 404.'.$fence.$grant,
                'input_schema' => ['type' => 'object', 'properties' => $delete, 'required' => ['rule_id', 'reason']]],
            ['name' => 'huntress_stage_delete_unwanted_access_rule', 'description' => 'Stage the deletion of one Huntress unwanted access rule for cockpit approval. The rule is read first and again at approval.'.$fence,
                'input_schema' => ['type' => 'object', 'properties' => $delete + $ticket, 'required' => ['rule_id', 'ticket_id', 'reason']]],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel, ?string $tokenLabel = null): array
    {
        $staged = isset(self::STAGED_TO_DIRECT[$name]);
        $direct = self::STAGED_TO_DIRECT[$name] ?? $name;
        if (! isset(self::ALLOWED_ARGUMENT_KEYS[$direct])) {
            return ['error' => "Unknown Huntress rule tool: {$name}"];
        }
        $hash = $this->contentHash($name, $clientId, null, $arguments);

        // Unknown keys first: nothing else is looked at, nothing is sent.
        $unknown = array_values(array_diff(array_map('strval', array_keys($arguments)), self::ALLOWED_ARGUMENT_KEYS[$direct]));
        if ($unknown !== []) {
            $message = 'Parameter'.(count($unknown) === 1 ? '' : 's').' refused: '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::ALLOWED_ARGUMENT_KEYS[$direct]).'. The organization always comes from the client mapping. Nothing was sent.';
            $this->audit($name, 'rejected', $clientId, null, $hash, $message, $actorLabel);

            return ['error' => $message];
        }

        $reason = is_string($arguments['reason'] ?? null) ? trim($arguments['reason']) : '';
        if ($reason === '') {
            $this->audit($name, 'rejected', $clientId, null, $hash, 'reason is required.', $actorLabel);

            return ['error' => 'reason is required. Nothing was sent.'];
        }
        $reason = mb_substr($reason, 0, self::REASON_MAX);

        if (TechnicianConfig::killSwitchEngaged()) {
            $this->audit($name, 'blocked', $clientId, null, $hash, 'Technician kill-switch engaged; Huntress rule write refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; Huntress rule write refused. Nothing was sent.'];
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->audit($name, 'rejected', $clientId, null, $hash, 'Client not found.', $actorLabel);

            return ['error' => 'Client not found. Nothing was sent.'];
        }

        $orgId = $this->mappedOrgId($client);
        if ($orgId === null) {
            $message = "Client {$client->id} has no mapped Huntress organization; map it in Settings > Huntress before writing rules. Nothing was sent.";
            $this->audit($name, 'rejected', $clientId, null, $hash, $message, $actorLabel);

            return ['error' => $message];
        }

        $request = $this->buildRequest($direct, $arguments, $orgId);
        if (is_string($request)) {
            $this->audit($name, 'rejected', $clientId, null, $hash, $request, $actorLabel);

            return ['error' => $request.' Nothing was sent.'];
        }

        $ticket = null;
        if ($staged || array_key_exists('ticket_id', $arguments)) {
            $ticket = $this->ticketFor($clientId, $arguments['ticket_id'] ?? null);
            if (is_string($ticket)) {
                $this->audit($name, 'rejected', $clientId, null, $hash, $ticket, $actorLabel);

                return ['error' => $ticket.' Nothing was sent.'];
            }
        }

        $hash = $this->contentHash($name, $clientId, $ticket?->id, $request);

        // Update and delete: the rule must be read first and apply to THIS client's org.
        $current = null;
        if ($direct !== self::CREATE) {
            $current = $this->readRuleInScope($request['rule_id'], $clientId, $orgId);
            if (is_string($current)) {
                $this->audit($name, 'rejected', $clientId, $ticket, $hash, $request['target'].': '.$current, $actorLabel);

                return ['error' => $current.' Nothing was sent.'];
            }
        }

        if ($staged) {
            return $this->stage($name, $direct, $request, $client, $ticket, $current, $reason, $hash, $actorLabel, $tokenLabel);
        }

        return $this->sendNow($name, $direct, $request, $client, $ticket, $current, $reason, $hash, $actorLabel);
    }

    /**
     * The exact request for one verb, or the refusal text. Values are validated
     * against the vendor spec; the org id is the mapped one, never an argument.
     *
     * @return array{method: string, path: string, rule_id: int|null, body: array<string, mixed>|null, target: string}|string
     */
    private function buildRequest(string $direct, array $arguments, int $orgId): array|string
    {
        if ($direct === self::CREATE) {
            $values = array_values(array_intersect(self::VALUE_KEYS, array_keys($arguments)));
            if (count($values) !== 1) {
                return 'Provide exactly one of country_code, vpn or ip_address.';
            }
            $key = $values[0];
            $value = $arguments[$key];
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > self::VALUE_MAX) {
                return "{$key} must be a non-empty string of at most ".self::VALUE_MAX.' characters.';
            }
            $value = trim($value);
            if (str_contains($value, ',')) {
                return "{$key} must be one value: a comma would create several rules, and Huntress returns only the last. Make one call per value.";
            }
            if ($key === 'country_code' && ! preg_match('/^[A-Z]{2}$/', $value)) {
                return 'country_code must be one ISO 3166-1 alpha-2 code in capitals, e.g. US.';
            }
            if ($key === 'ip_address' && ! self::isIpOrCidr($value)) {
                return 'ip_address must be one IPv4 or IPv6 address or CIDR range.';
            }
            $type = $arguments['type'] ?? null;
            if (! in_array($type, ['expected', 'unauthorized'], true)) {
                return 'type is required: expected or unauthorized.';
            }
            if ($key === 'ip_address' && $type !== 'expected') {
                return 'ip_address rules must be type expected.';
            }
            $body = ['organization_id' => $orgId, $key => $value, 'type' => $type];
            foreach (['starts_at', 'expires_at'] as $date) {
                if (! array_key_exists($date, $arguments)) {
                    continue;
                }
                if ($type === 'unauthorized') {
                    return "{$date} must be omitted for unauthorized rules.";
                }
                if (! self::isDate($arguments[$date])) {
                    return "{$date} must be a UTC date YYYY-MM-DD.";
                }
                $body[$date] = $arguments[$date];
            }
            if (array_key_exists('notes', $arguments)) {
                if (! is_string($arguments['notes']) || mb_strlen($arguments['notes']) > self::NOTES_MAX) {
                    return 'notes must be a string of at most '.self::NOTES_MAX.' characters.';
                }
                $body['notes'] = $arguments['notes'];
            }

            return ['method' => 'POST', 'path' => '/v1/unwanted_access_rules', 'rule_id' => null, 'body' => $body, 'target' => "rule-org:{$orgId}"];
        }

        $ruleId = $this->positiveInt($arguments['rule_id'] ?? null);
        if ($ruleId === null) {
            return 'rule_id is required and must be a positive integer.';
        }
        $path = "/v1/unwanted_access_rules/{$ruleId}";
        if ($direct === self::DELETE) {
            return ['method' => 'DELETE', 'path' => $path, 'rule_id' => $ruleId, 'body' => null, 'target' => "rule:{$ruleId}"];
        }

        $body = [];
        foreach (['starts_at', 'expires_at'] as $date) {
            if (array_key_exists($date, $arguments)) {
                $value = $arguments[$date];
                if ($value !== null && $value !== '' && ! self::isDate($value)) {
                    return "{$date} must be a UTC date YYYY-MM-DD, or null or an empty string to clear it.";
                }
                $body[$date] = $value;
            }
        }
        if (array_key_exists('notes', $arguments)) {
            $notes = $arguments['notes'];
            if ($notes !== null && (! is_string($notes) || mb_strlen($notes) > self::NOTES_MAX)) {
                return 'notes must be a string of at most '.self::NOTES_MAX.' characters, or null to clear it.';
            }
            $body['notes'] = $notes;
        }
        if ($body === []) {
            return 'Provide at least one of starts_at, expires_at or notes.';
        }

        return ['method' => 'PATCH', 'path' => $path, 'rule_id' => $ruleId, 'body' => $body, 'target' => "rule:{$ruleId}"];
    }

    /**
     * Immediate lane: send now, then read back.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    private function sendNow(string $tool, string $direct, array $request, Client $client, ?Ticket $ticket, ?array $current, string $reason, string $hash, string $actorLabel): array
    {
        try {
            $outcome = $this->perform($direct, $request, (int) $client->id, $current);
        } catch (HuntressWriteScopeException $e) {
            $this->audit($tool, 'error', (int) $client->id, $ticket, $hash, $request['target'].': refused before sending: '.$e->getMessage(), $actorLabel);

            return ['error' => 'The Huntress rule call was refused before it was sent. Nothing was sent.'];
        } catch (HuntressClientException $e) {
            $detail = self::failureDetail($e);
            $committed = self::mayHaveCommitted($e);
            $this->audit($tool, 'error', (int) $client->id, $ticket, $hash, $request['target'].': '.$request['method'].' '.$request['path'].' failed: '.$detail.($committed ? ' (outcome unknown)' : ''), $actorLabel);

            return ['error' => $committed
                ? "Huntress rule {$request['method']} failed with an unknown outcome ({$detail}); it may have been applied. Check the rule with huntress_list_unwanted_access_rules before retrying."
                : "Huntress refused the rule {$request['method']} ({$detail}). Nothing was changed."];
        }

        // The write landed: a failing audit INSERT must not turn it into an error the agent retries.
        $this->safeAudit($tool, 'executed', (int) $client->id, $ticket, $hash, $request['target'].': MCP '.$tool.' executed: '.$request['method'].' '.$request['path'].': '.$reason, $actorLabel);

        return ['success' => true, 'method' => $request['method'], 'path' => $outcome['path'], 'body_sent' => $request['body']] + $outcome['result'];
    }

    /**
     * Send one verb and read back. Throws before the write (scope) or on the write;
     * a read-back failure after a landed write is reported in the result, never thrown.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $current
     * @return array{path: string, result: array<string, mixed>}
     */
    private function perform(string $direct, array $request, int $clientId, ?array $current): array
    {
        if ($direct === self::CREATE) {
            $response = $this->writeClient->createUnwantedAccessRule($request['body']);
            $id = $this->positiveInt(($response['unwanted_access_rule'] ?? [])['id'] ?? null);
            if ($id === null) {
                return ['path' => $request['path'], 'result' => ['read_back_error' => 'Huntress answered the create without a rule id, so the rule could not be read back. Check it with huntress_list_unwanted_access_rules.']];
            }

            return ['path' => $request['path'], 'result' => $this->readBack($id, $clientId)];
        }

        if ($direct === self::UPDATE) {
            $this->writeClient->updateUnwantedAccessRule($request['rule_id'], $request['body']);

            return ['path' => $request['path'], 'result' => $this->readBack($request['rule_id'], $clientId)];
        }

        $response = $this->writeClient->deleteUnwantedAccessRule($request['rule_id']);
        $deleted = $response['unwanted_access_rule'] ?? null;
        $after = $this->read($request['rule_id'], $clientId);
        $gone = isset($after['error']) && str_starts_with((string) $after['error'], 'Huntress HTTP 404');

        return ['path' => $request['path'], 'result' => [
            // The rule as read (sanitised) before the delete; the 202 body is not re-sanitised here.
            'deleted_rule' => $current,
            'delete_acknowledged' => is_array($deleted),
            'read_back' => $gone ? 'GET by id answered 404: the rule is gone.' : 'GET by id did not answer 404 after the delete: '.($after['error'] ?? 'the rule still reads back').'. Check it in Huntress.',
        ]];
    }

    /** @return array<string, mixed> */
    private function readBack(int $ruleId, int $clientId): array
    {
        $read = $this->read($ruleId, $clientId);
        if (isset($read['unwanted_access_rule'])) {
            return ['unwanted_access_rule' => $read['unwanted_access_rule']];
        }

        return ['read_back_error' => 'The write was sent, but reading the rule back failed: '.($read['error'] ?? 'no rule returned').'.'];
    }

    /**
     * The rule as the client-scoped read returns it, or the refusal text. The read
     * refuses unless applied_to is the client's mapped Organization.
     *
     * @return array<string, mixed>|string
     */
    private function readRuleInScope(int $ruleId, int $clientId, int $orgId): array|string
    {
        $read = $this->read($ruleId, $clientId);
        if (isset($read['error'])) {
            return "Rule {$ruleId} could not be confirmed as this client's rule: ".$read['error'].'.';
        }
        // The read has already refused any applied_to other than {type: Organization,
        // integer id} equal to this client's org. Its string leaves come back fenced,
        // so only the integer id is re-checked here.
        $rule = $read['unwanted_access_rule'] ?? null;
        $applied = is_array($rule) ? ($rule['applied_to'] ?? null) : null;
        if (! is_array($applied) || ($applied['id'] ?? null) !== $orgId) {
            return "Rule {$ruleId} does not apply to this client's Huntress organization.";
        }

        return $rule;
    }

    /** @return array<string, mixed> */
    private function read(int $ruleId, int $clientId): array
    {
        try {
            return $this->toolset()->execute('huntress_get_unwanted_access_rule', ['id' => $ruleId], $clientId);
        } catch (\Throwable $e) {
            // A read never throws past here: after a landed write that would reopen the run.
            return ['error' => 'the rule read failed: '.class_basename($e)];
        }
    }

    private function toolset(): HuntressReadOnlyToolset
    {
        return app(HuntressReadOnlyToolset::class);
    }

    /**
     * Staged lane: store the exact request (encrypted) and show it on the card.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    private function stage(string $tool, string $direct, array $request, Client $client, Ticket $ticket, ?array $current, string $reason, string $hash, string $actorLabel, ?string $tokenLabel): array
    {
        $slot = $this->runSlot($ticket->id, $tool, $hash);
        if ($slot === null) {
            return ['error' => 'This ticket already carries '.self::MAX_RUN_GENERATIONS.' identical Huntress rule proposals; nothing was staged. Raise a new ticket for a further one.'];
        }
        $live = TechnicianRun::query()
            ->where('ticket_id', $ticket->id)->where('action_type', $tool)->where('content_hash', $slot)
            ->where('state', TechnicianRunState::AwaitingApproval->value)->first();
        if ($live !== null) {
            return ['success' => true, 'idempotent' => true, 'ticket_id' => $ticket->id, 'run_id' => $live->id, 'message' => 'Already staged; awaiting approval. Nothing was sent.'];
        }

        $meta = [
            ...DraftedByToken::meta($actorLabel, $tokenLabel),
            'reasons' => [$reason],
            'direct_tool' => $direct,
            'redacted_params' => ['method' => $request['method'], 'path' => $request['path']],
            'sensitive_inputs' => [],
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => $direct,
                'client_id' => $client->id,
                'ticket_id' => $ticket->id,
                'org_id' => $request['body']['organization_id'] ?? $this->mappedOrgId($client),
                'method' => $request['method'],
                'path' => $request['path'],
                'rule_id' => $request['rule_id'],
                'body' => $request['body'],
                'target' => $request['target'],
            ], JSON_THROW_ON_ERROR)),
        ];

        $content = $this->display($request, $client, $current)
            ."\n".$this->fence->fence('AGENT SUPPLIED REASON', $reason);

        $run = TechnicianRun::firstOrCreate(
            ['ticket_id' => $ticket->id, 'action_type' => $tool, 'content_hash' => $slot],
            ['client_id' => $client->id, 'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => $content, 'proposed_meta' => $meta, 'confidence' => null, 'tokens_used' => 0],
        );
        if (! $run->wasRecentlyCreated) {
            if ($run->state === TechnicianRunState::Executing) {
                return ['success' => true, 'idempotent' => true, 'ticket_id' => $ticket->id, 'run_id' => $run->id, 'message' => 'This proposal is being executed now; nothing new was staged.'];
            }
            $run->update(['state' => TechnicianRunState::AwaitingApproval->value, 'proposed_content' => $content, 'proposed_meta' => $meta]);
        }

        $this->audit($tool, 'awaiting_approval', (int) $client->id, $ticket, $hash, $request['target'].': MCP staged '.$tool.': '.$request['method'].' '.$request['path'].': '.$reason, $actorLabel, $run->id);

        return ['success' => true, 'ticket_id' => $ticket->id, 'ticket_display_id' => $ticket->display_id, 'run_id' => $run->id, 'method' => $request['method'], 'path' => $request['path'], 'body' => $request['body'], 'message' => 'Staged for cockpit approval. Nothing was sent.'];
    }

    /**
     * The (ticket, action, content_hash) key this proposal may use. A Done run
     * is the record of an earlier approval, which may have ended with an unknown
     * outcome or been undone since; it is never answered as "already executed"
     * and never rewritten. An identical proposal after it takes the next
     * generation of the key. Null when the generations are exhausted.
     */
    private function runSlot(int $ticketId, string $tool, string $baseHash): ?string
    {
        for ($generation = 0; $generation < self::MAX_RUN_GENERATIONS; $generation++) {
            $hash = $generation === 0 ? $baseHash : hash('sha256', $baseHash.':'.$generation);
            $existing = TechnicianRun::query()
                ->where('ticket_id', $ticketId)->where('action_type', $tool)->where('content_hash', $hash)->first();
            if ($existing === null || $existing->state !== TechnicianRunState::Done) {
                return $hash;
            }
        }

        return null;
    }

    /**
     * The approver's card: method, path and body exactly as they will be sent.
     * Agent-supplied and vendor strings appear only inside the JSON body or an
     * UNTRUSTED fence.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $current
     */
    private function display(array $request, Client $client, ?array $current): string
    {
        $verb = ['POST' => 'Create', 'PATCH' => 'Update', 'DELETE' => 'Delete'][$request['method']];
        $lines = [
            "{$verb} a Huntress unwanted access rule for {$client->name}.",
            'Request as it will be sent: '.$request['method'].' '.$request['path'],
        ];
        $lines[] = $this->fence->fence('REQUEST BODY', $request['body'] === null
            ? '(no body)'
            : json_encode($request['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($current !== null) {
            $lines[] = $this->fence->fence('HUNTRESS RULE AS READ', json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $lines[] = 'Approval re-checks the client mapping'.($request['rule_id'] !== null ? ' and re-reads the rule' : '').' before sending.';

        return implode("\n", $lines);
    }

    /**
     * Approval: replay the stored request exactly, after re-checking the fence.
     */
    public function approveStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        if (! self::isStagedActionType($run->action_type) || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        try {
            return $this->approveClaimed($run, $approverId);
        } catch (\Throwable $e) {
            // Reached only before the write call: after it, every failure is caught in
            // approveClaimed() so the run is never given back for a second send.
            $run->releaseClaim();

            throw $e;
        }
    }

    private function approveClaimed(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        $payload = $this->decryptPayload($run);
        $direct = (string) ($payload['direct_tool'] ?? '');
        if ($payload === null || self::STAGED_TO_DIRECT[$run->action_type] !== $direct) {
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined', message: 'The held payload could not be read; deny this proposal and stage it again. Nothing was sent.');
        }

        $client = Client::find((int) ($payload['client_id'] ?? 0));
        $ticket = Ticket::automationVisible()->find((int) ($payload['ticket_id'] ?? 0));
        if (! $client || (int) $client->id !== (int) $run->client_id || ! $ticket || (int) $ticket->client_id !== (int) $client->id) {
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined', message: 'The client or ticket no longer matches this proposal. Nothing was sent.');
        }

        $request = [
            'method' => (string) ($payload['method'] ?? ''),
            'path' => (string) ($payload['path'] ?? ''),
            'rule_id' => $this->positiveInt($payload['rule_id'] ?? null),
            'body' => is_array($payload['body'] ?? null) ? $payload['body'] : null,
            'target' => (string) ($payload['target'] ?? ''),
        ];
        $hash = (string) $run->content_hash;
        $approver = $this->approverLabel($approverId);
        $decline = function (string $status, string $message) use ($run, $client, $ticket, $hash, $request, $approver, $approverId): TechnicianApprovalResult {
            $this->audit($run->action_type, $status, (int) $client->id, $ticket, $hash, $request['target'].': Approval refused: '.$message, $approver, $run->id, $approverId);
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined', message: $message.' Nothing was sent.');
        };

        if (! HuntressConfig::isEnabled() || ! HuntressConfig::isConfigured()) {
            return $decline('blocked', 'Huntress is not enabled or its API key pair is not saved.');
        }
        if (TechnicianConfig::killSwitchEngaged()) {
            return $decline('blocked', 'Technician kill-switch engaged.');
        }

        // A remap while held must not widen the write: the stored org must still be the mapping.
        $orgId = $this->mappedOrgId($client);
        if ($orgId === null || $orgId !== ($payload['org_id'] ?? null)) {
            return $decline('blocked', "Client {$client->id} is no longer mapped to the Huntress organization this proposal was staged for.");
        }
        if ($direct === self::CREATE && ($request['body']['organization_id'] ?? null) !== $orgId) {
            return $decline('blocked', 'The held body does not name the mapped organization.');
        }

        $current = null;
        if ($direct !== self::CREATE) {
            if ($request['rule_id'] === null) {
                return $decline('blocked', 'The held proposal has no rule id.');
            }
            $current = $this->readRuleInScope($request['rule_id'], (int) $client->id, $orgId);
            if (is_string($current)) {
                return $decline('blocked', $current);
            }
        }

        try {
            $outcome = $this->perform($direct, $request, (int) $client->id, $current);
        } catch (HuntressWriteScopeException $e) {
            return $decline('error', 'The Huntress rule call was refused before it was sent.');
        } catch (HuntressClientException $e) {
            $detail = self::failureDetail($e);
            if (! self::mayHaveCommitted($e)) {
                return $decline('error', "Huntress refused the rule {$request['method']} ({$detail}).");
            }
            $fault = "The Huntress rule {$request['method']} {$request['path']} failed with an unknown outcome ({$detail}); it may have been applied. Do NOT re-approve: check the rule in Huntress.";
            $this->safeAudit($run->action_type, 'error', (int) $client->id, $ticket, $hash, $request['target'].': '.$fault, $approver, $run->id, $approverId);
            try {
                $closed = $run->advanceTo(TechnicianRunState::Done);
            } catch (\Throwable) {
                $closed = false;
            }

            return new TechnicianApprovalResult('executed_with_fault', message: $fault.($closed ? '' : ' The run was not closed because this request no longer holds it; check the run.'));
        }

        // The write landed: nothing below may throw out and give the run back for a second send.
        try {
            $closed = $run->advanceTo(TechnicianRunState::Done);
        } catch (\Throwable $e) {
            Log::error('[StaffHuntressRuleToolExecutor] closing the run failed after a Huntress rule write', ['run_id' => $run->id, 'exception' => $e::class]);
            $closed = false;
        }
        $this->safeAudit($run->action_type, 'executed', (int) $client->id, $ticket, $hash, $request['target'].': Operator-approved '.$run->action_type.' executed: '.$request['method'].' '.$request['path'], $approver, $run->id, $approverId);
        if (! $closed) {
            return TechnicianApprovalResult::executedNotClosed('The Huntress rule '.$request['method'], 'the rule in Huntress');
        }

        $readBack = $outcome['result'];
        $summary = isset($readBack['read_back_error']) ? ' '.$readBack['read_back_error'] : (isset($readBack['read_back']) ? ' '.$readBack['read_back'] : ' The rule was read back after the write.');

        return new TechnicianApprovalResult('executed', message: "Huntress rule {$request['method']} {$request['path']} sent.".$summary);
    }

    // ── helpers ──

    private function mappedOrgId(Client $client): ?int
    {
        return $this->positiveInt($client->huntress_organization_id);
    }

    private function ticketFor(int $clientId, mixed $value): Ticket|string
    {
        $id = $this->positiveInt($value);
        if ($id === null) {
            return 'ticket_id is required for a staged Huntress rule write and must be a positive integer.';
        }
        $ticket = Ticket::automationVisible()->find($id);
        if (! $ticket || (int) $ticket->client_id !== $clientId) {
            return 'Ticket not found or belongs to a different client.';
        }

        return $ticket;
    }

    private static function isDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function isIpOrCidr(string $value): bool
    {
        $parts = explode('/', $value);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;

        return ctype_digit($parts[1]) && (int) $parts[1] <= $max;
    }

    /** C-56: class and HTTP status only, never the vendor message. */
    private static function failureDetail(\Throwable $e): string
    {
        $status = (int) $e->getCode();

        return 'Huntress HTTP '.($status > 0 ? $status : 'no response').': '.class_basename($e);
    }

    /** Only a vendor refusal (4xx other than 408) proves nothing was written. */
    private static function mayHaveCommitted(HuntressClientException $e): bool
    {
        $status = (int) $e->getCode();

        return ! ($status >= 400 && $status < 500 && $status !== 408);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }

    private function contentHash(string $tool, int $clientId, ?int $ticketId, array $params): string
    {
        unset($params['reason'], $params['target']);
        ksort($params);

        return hash('sha256', json_encode(['tool' => $tool, 'client_id' => $clientId, 'ticket_id' => $ticketId, 'params' => $params]));
    }

    private function decryptPayload(TechnicianRun $run): ?array
    {
        $ciphertext = $run->proposed_meta['encrypted_payload'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }
        try {
            $payload = json_decode(Crypt::decryptString($ciphertext), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private function approverLabel(int $approverId): string
    {
        $user = User::find($approverId);

        return $user?->email ?? $user?->name ?? "approver:{$approverId}";
    }

    /** An audit row after a write landed: a failing INSERT must not hide the outcome. */
    private function safeAudit(string $type, string $status, ?int $clientId, ?Ticket $ticket, string $hash, string $summary, string $actor, ?int $runId = null, ?int $approverId = null): void
    {
        try {
            $this->audit($type, $status, $clientId, $ticket, $hash, $summary, $actor, $runId, $approverId);
        } catch (\Throwable $e) {
            Log::error('[StaffHuntressRuleToolExecutor] audit row failed after a Huntress rule write', ['run_id' => $runId, 'result_status' => $status, 'exception' => $e::class]);
        }
    }

    private function audit(string $type, string $status, ?int $clientId, ?Ticket $ticket, string $hash, string $summary, string $actor, ?int $runId = null, ?int $approverId = null): void
    {
        if ($clientId !== null && ! Client::whereKey($clientId)->exists()) {
            $clientId = null;
        }

        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::aiActorUserId(),
            'approver_user_id' => $approverId,
            'actor_label' => $actor,
            'action_type' => $type,
            'tier' => TechnicianTier::Approve->value,
            'result_status' => $status,
            'ticket_id' => $ticket?->id,
            'client_id' => $clientId,
            'run_id' => $runId,
            'content_hash' => $hash,
            'summary' => mb_substr($this->redactor->redactString($summary), 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
