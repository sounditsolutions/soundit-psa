<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\TechnicianActionLog;
use App\Services\Cipp\CippContactSyncService;
use App\Services\SyncResult;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Support\CippConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Str;

/**
 * CIPP admin-class MCP tools: operational maintenance of the PSA's own CIPP-derived
 * data, as distinct from StaffCippWriteToolExecutor, which writes to the customer's
 * M365 tenant. Nothing here changes tenant state; the upstream calls are reads and
 * the writes land in the PSA's people table.
 *
 * The shape is deliberately the one StaffTacticalAdminToolExecutor already uses for
 * tactical_sync_devices_now — explicit grant, required reason, kill-switch,
 * server-derived client scope, cooldown, TechnicianActionLog audit — with one
 * considered departure documented on syncPeople().
 */
class StaffCippAdminToolExecutor
{
    /** @var array<int, string> */
    private const CLIENT_SCOPED_TOOLS = [
        'cipp_sync_people_now',
    ];

    /** @var array<string, int> */
    private const COOLDOWNS = [
        'cipp_sync_people_now' => 300,
    ];

    public function __construct(
        private readonly CippContactSyncService $contactSync,
        private readonly ActionRedactor $redactor,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::syncPeopleNowTool(),
        ];
    }

    /** @return array<int, string> */
    public static function toolNames(): array
    {
        return array_column(self::definitions(), 'name');
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::toolNames(), true);
    }

    public static function requiresClient(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_SCOPED_TOOLS, true);
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel): array
    {
        if (! CippConfig::isEnabled() || ! CippConfig::isConfigured()) {
            return ['error' => 'CIPP is not enabled or configured'];
        }

        return match ($name) {
            'cipp_sync_people_now' => $this->syncPeople($arguments, $clientId, $actorLabel),
            default => ['error' => "Unknown CIPP admin tool: {$name}"],
        };
    }

    /**
     * Run the existing per-client CIPP person sync on demand for one mapped tenant.
     *
     * Departure from the Tactical sync-now shape, deliberate: there is NO 24-hour
     * alreadyExecuted() short-circuit here. That guard exists to stop a repeated
     * one-shot mutation (create a site, generate an installer) from firing twice, and
     * its content hash for a sync is constant per client — so applying it to a refresh
     * would answer the second call in a day with {success, idempotent} and NO sync,
     * which is precisely the failure this tool exists to remove. A sync is idempotent
     * by construction; running it again is the point. The 5-minute cooldown remains as
     * the runaway guard, and it is what bounds upstream API cost.
     *
     * @return array<string, mixed>
     */
    private function syncPeople(array $arguments, int $clientId, string $actorLabel): array
    {
        $tool = 'cipp_sync_people_now';
        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $contentHash = $this->contentHash($tool, $clientId, 'person-sync', ['client_id' => $clientId]);

        $client = Client::find($clientId);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }
        if (! is_string($client->cipp_tenant_domain) || trim($client->cipp_tenant_domain) === '') {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'Client has no CIPP tenant mapping.', $actorLabel);

            return ['error' => 'Client has no CIPP tenant mapping; nothing to sync for this client'];
        }
        if ($this->cooldownActive($tool, $clientId, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Person sync cooldown active; upstream call refused.', $actorLabel);

            return ['error' => "{$tool} cooldown active for this client; no upstream call was made. The scheduled sync is unaffected."];
        }

        $result = new SyncResult;

        try {
            $ran = $this->contactSync->syncClientContacts($client, $result);
        } catch (\Throwable $e) {
            $this->auditAttempt($tool, 'error', $clientId, $contentHash, 'CIPP person sync failed: '.$e->getMessage(), $actorLabel);

            return ['error' => 'CIPP person sync failed for this client; no people were changed.'];
        }

        // A sync already in flight for this client is NOT a completed sync. Saying
        // "no changes" here would hand the caller the same ambiguity that made this
        // tool necessary: nothing found, versus nothing looked yet.
        if (! $ran) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Person sync already in progress for this client; on-demand run skipped.', $actorLabel);

            return [
                'success' => false,
                'in_flight' => true,
                'synced' => false,
                'message' => 'A CIPP person sync for this client is already running; this call synced nothing. Re-read the person in a moment rather than treating the current roster as complete.',
            ];
        }

        $status = $result->errors > 0 ? 'error' : 'executed';
        $this->auditAttempt($tool, $status, $clientId, $contentHash, 'CIPP person sync complete: '.$result->summary().'.', $actorLabel);

        if ($result->errors > 0) {
            return [
                'error' => $result->summary(),
                'errors' => $result->errorMessages,
                'synced' => true,
            ];
        }

        return [
            'success' => true,
            'synced' => true,
            'summary' => $result->summary(),
            'created' => $result->created,
            'updated' => $result->updated,
            'deactivated' => $result->deactivated,
        ];
    }

    /** @return array<string, mixed> */
    private function baseGuard(string $tool, array $arguments, ?int $clientId, string $actorLabel): array
    {
        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $this->contentHash($tool, $clientId, 'guard', $arguments), 'reason is required.', $actorLabel);

            return ['error' => 'reason is required'];
        }

        if (TechnicianConfig::killSwitchEngaged()) {
            $this->auditAttempt($tool, 'blocked', $clientId, $this->contentHash($tool, $clientId, 'guard', $arguments), 'Technician kill-switch engaged; CIPP MCP admin action refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; CIPP MCP admin action refused'];
        }

        return ['reason' => $reason];
    }

    private function cooldownActive(string $tool, ?int $clientId, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->where('result_status', 'executed')
            ->exists();
    }

    private function auditAttempt(
        string $actionType,
        string $resultStatus,
        ?int $clientId,
        string $contentHash,
        string $summary,
        string $actorLabel,
    ): void {
        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::aiActorUserId(),
            'approver_user_id' => null,
            'actor_label' => $actorLabel,
            'action_type' => $actionType,
            'tier' => TechnicianTier::Approve->value,
            'result_status' => $resultStatus,
            'ticket_id' => null,
            'client_id' => $clientId,
            'run_id' => null,
            'content_hash' => $contentHash,
            'summary' => mb_substr($this->redactor->redactString($summary), 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }

    private function contentHash(string $tool, ?int $clientId, string $target, array $params): string
    {
        ksort($params);

        return hash('sha256', json_encode([
            'tool' => $tool,
            'client_id' => $clientId,
            'target' => $target,
            'params' => $params,
        ]));
    }

    private function requiredString(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array<string, mixed> */
    private static function syncPeopleNowTool(): array
    {
        return self::tool(
            'cipp_sync_people_now',
            'Run the existing CIPP/M365 person sync on demand for one PSA client mapping, so a mailbox created in the tenant today is visible to the PSA today instead of after the next nightly pass. Reads from CIPP and writes only to the PSA people table; it makes no change to the customer tenant. Server-derived client scope; a client with no CIPP tenant mapping is refused. Returns what the sync did (created/updated/deactivated). If a sync for this client is already running, it returns in_flight with synced=false and changes nothing — that is NOT a "no such person" answer. Requires explicit grant, reason, kill-switch, and a 5-minute per-client cooldown that bounds upstream API cost.',
            self::reasonProperties(),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function reasonProperties(): array
    {
        return [
            'reason' => [
                'type' => 'string',
                'description' => 'Specific operational reason for this on-demand CIPP sync.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private static function tool(string $name, string $description, array $properties, array $required): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'input_schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
            ],
        ];
    }
}
