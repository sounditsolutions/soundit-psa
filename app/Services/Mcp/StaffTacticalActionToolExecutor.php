<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Models\Asset;
use App\Models\TacticalActionLog;
use App\Models\TacticalScript;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\SafeTacticalWebUrl;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Tactical\Actions\InvalidActionParams;
use App\Services\Tactical\Actions\PatchAction;
use App\Services\Tactical\Actions\PatchInstallAction;
use App\Services\Tactical\Actions\PatchScanAction;
use App\Services\Tactical\Actions\RebootAction;
use App\Services\Tactical\Actions\RecoverAction;
use App\Services\Tactical\Actions\RunCommandAction;
use App\Services\Tactical\Actions\RunScriptAction;
use App\Services\Tactical\Actions\ServiceControlAction;
use App\Services\Tactical\Actions\ServiceStartTypeAction;
use App\Services\Tactical\Actions\SetMaintenanceAction;
use App\Services\Tactical\Actions\ShutdownAction;
use App\Services\Tactical\Actions\TacticalAction;
use App\Services\Tactical\Actions\TacticalActionResult;
use App\Services\Tactical\DetailSyncResult;
use App\Services\Tactical\TacticalActionConfirmToken;
use App\Services\Tactical\TacticalActionService;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Tactical\TacticalDeviceSyncService;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\TacticalConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StaffTacticalActionToolExecutor
{
    private const DIRECT_DEDUP_HOURS = 24;

    /** @var array<int, string> */
    private const UPSTREAM_IDENTIFIER_KEYS = [
        'agent_id',
        'upstream_agent_id',
        'tactical_agent_id',
        'tactical_client_id',
        'tactical_site_id',
        'upstream_client_id',
        'upstream_site_id',
        'client_name',
        'site_name',
        'tactical_client_name',
        'tactical_site_name',
        'tactical_script_id',
        'service_id',
        'svcname',
        'upstream_service_name',
        'tactical_service_name',
        'winupdate_id',
        'update_id',
        'update_pk',
        'patch_pk',
        'upstream_winupdate_id',
        'tactical_patch_id',
    ];

    /** @var array<string, int> */
    private const COOLDOWNS = [
        'tactical_run_script' => 120,
        'tactical_stage_script' => 120,
        'tactical_run_command' => 300,
        'tactical_stage_command' => 300,
        'tactical_reboot_device' => 300,
        'tactical_stage_reboot' => 300,
        'tactical_shutdown_device' => 300,
        'tactical_stage_shutdown' => 300,
        'tactical_recover_mesh' => 60,
        'tactical_stage_recover_mesh' => 60,
        'tactical_set_maintenance' => 60,
        'tactical_stage_maintenance' => 60,
        'tactical_open_remote_control' => 60,
        'tactical_refresh_device_snapshot' => 60,
        'tactical_start_service' => 120,
        'tactical_stop_service' => 300,
        'tactical_stage_stop_service' => 300,
        'tactical_restart_service' => 300,
        'tactical_stage_restart_service' => 300,
        'tactical_set_service_start_type' => 300,
        'tactical_scan_patches' => 300,
        'tactical_set_patch_action' => 120,
        'tactical_install_approved_patches' => 600,
        'tactical_stage_install_approved_patches' => 600,
    ];

    /** @var array<string, string> */
    private const STAGED_TO_DIRECT = [
        'tactical_stage_script' => 'tactical_run_script',
        'tactical_stage_command' => 'tactical_run_command',
        'tactical_stage_reboot' => 'tactical_reboot_device',
        'tactical_stage_shutdown' => 'tactical_shutdown_device',
        'tactical_stage_recover_mesh' => 'tactical_recover_mesh',
        'tactical_stage_maintenance' => 'tactical_set_maintenance',
        'tactical_stage_stop_service' => 'tactical_stop_service',
        'tactical_stage_restart_service' => 'tactical_restart_service',
        'tactical_stage_install_approved_patches' => 'tactical_install_approved_patches',
    ];

    public function __construct(
        private readonly TacticalActionService $bus,
        private readonly TacticalClient $client,
        private readonly TacticalDeviceSyncService $sync,
        private readonly ActionRedactor $redactor,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::runScriptTool(),
            self::stageScriptTool(),
            self::runCommandTool(),
            self::stageCommandTool(),
            self::rebootTool(),
            self::stageRebootTool(),
            self::shutdownTool(),
            self::stageShutdownTool(),
            self::recoverTool(),
            self::stageRecoverTool(),
            self::setMaintenanceTool(),
            self::stageMaintenanceTool(),
            self::remoteControlTool(),
            self::refreshSnapshotTool(),
            self::startServiceTool(),
            self::stopServiceTool(),
            self::stageStopServiceTool(),
            self::restartServiceTool(),
            self::stageRestartServiceTool(),
            self::setServiceStartTypeTool(),
            self::scanPatchesTool(),
            self::setPatchActionTool(),
            self::installApprovedPatchesTool(),
            self::stageInstallApprovedPatchesTool(),
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
        return self::handles($toolName);
    }

    public static function isStagedActionType(string $actionType): bool
    {
        return array_key_exists($actionType, self::STAGED_TO_DIRECT);
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel): array
    {
        if (! TacticalConfig::isConfigured()) {
            return ['error' => 'Tactical RMM is not configured'];
        }

        if (isset(self::STAGED_TO_DIRECT[$name])) {
            return $this->stageAction($name, $arguments, $clientId, $actorLabel);
        }

        return match ($name) {
            'tactical_run_script',
            'tactical_run_command',
            'tactical_reboot_device',
            'tactical_shutdown_device',
            'tactical_recover_mesh',
            'tactical_set_maintenance',
            'tactical_start_service',
            'tactical_stop_service',
            'tactical_restart_service',
            'tactical_set_service_start_type',
            'tactical_scan_patches',
            'tactical_set_patch_action',
            'tactical_install_approved_patches' => $this->executeBusTool($name, $arguments, $clientId, $actorLabel),
            'tactical_open_remote_control' => $this->openRemoteControl($arguments, $clientId, $actorLabel),
            'tactical_refresh_device_snapshot' => $this->refreshSnapshot($arguments, $clientId, $actorLabel),
            default => ['error' => "Unknown Tactical action tool: {$name}"],
        };
    }

    public function approveStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        if (! self::isStagedActionType($run->action_type) || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        try {
            $payload = $this->decryptRunPayload($run);
            if ($payload === null) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $directTool = (string) ($payload['direct_tool'] ?? '');
            if (! isset(self::STAGED_TO_DIRECT[$run->action_type]) || self::STAGED_TO_DIRECT[$run->action_type] !== $directTool) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $asset = Asset::with('tacticalAsset')->find((int) ($payload['asset_id'] ?? 0));
            $ticket = Ticket::find((int) ($payload['ticket_id'] ?? 0));
            if (! $asset || ! $ticket || (int) $ticket->client_id !== (int) $run->client_id) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if (! $ticket->assets()->where('assets.id', $asset->id)->exists()) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if ($error = $this->linkedAgentError($asset)) {
                $this->auditAttempt($run->action_type, 'rejected', (int) $run->client_id, $ticket, $asset, $run->content_hash, $error, $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', (int) $run->client_id, $ticket, $asset, $run->content_hash, 'Technician kill-switch engaged; staged Tactical action refused.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $payload = $this->revalidateStagedPayload($payload, $asset);
            if (isset($payload['error'])) {
                $this->auditAttempt($run->action_type, 'rejected', (int) $run->client_id, $ticket, $asset, $run->content_hash, (string) $payload['error'], $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if ($this->cooldownActive($directTool, $asset, $ticket, self::COOLDOWNS[$directTool] ?? 60)) {
                $this->auditAttempt($run->action_type, 'blocked', (int) $run->client_id, $ticket, $asset, $run->content_hash, 'Tactical staged action cooldown active; approval refused before upstream call.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            [$action, $params] = $this->actionAndParamsFromPayload($directTool, $payload);
            $approver = User::find($approverId);
            $token = $this->confirmTokenFor($action, $asset, $params, $approver?->id);
            $result = $this->bus->dispatch(
                $action,
                $asset,
                $approver,
                $params,
                $token,
                $this->approverLabel($approverId),
                $ticket->id,
            );

            // Offline device (bd psa-xr84): instead of the silent dead-end, park the
            // approved action in the queue to auto-run on reconnect. v1 = scripts only.
            $agentId = (string) ($asset->tacticalAsset->agent_id ?? '');
            $willQueue = $result->isOffline()
                && $agentId !== ''
                && $directTool === 'tactical_run_script'
                && TacticalConfig::offlineQueueEnabled();

            $status = $result->isOk() ? 'executed' : ($willQueue ? 'queued_offline' : $result->status);
            $this->auditAttempt(
                $run->action_type,
                $status,
                (int) $run->client_id,
                $ticket,
                $asset,
                $run->content_hash,
                $this->approvalSummary($run, $result),
                $this->approverLabel($approverId),
                $run->id,
                $approverId,
            );

            if ($willQueue) {
                return $this->enqueueOfflineRun($run, $agentId, $directTool, $params, $approverId);
            }

            if (! $result->isOk()) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $run->advanceTo(TechnicianRunState::Done);

            return new TechnicianApprovalResult('executed');
        } catch (\Throwable $e) {
            $run->releaseClaim();

            throw $e;
        }
    }

    /**
     * Park a claimed (executing) run in the offline queue, or coalesce onto an
     * identical one already waiting (bd psa-xr84 §5). Dedup key = (agent, script,
     * args): a duplicate approval bumps the existing row's coalesce_count and this
     * run supersedes rather than creating a second queued row. The approver is
     * persisted so the eventual reconnect-run attributes its audit correctly.
     */
    private function enqueueOfflineRun(TechnicianRun $run, string $agentId, string $directTool, array $params, int $approverId): TechnicianApprovalResult
    {
        $dedupKey = $this->queueDedupKey($agentId, $directTool, $params);

        $existing = TechnicianRun::query()
            ->where('state', TechnicianRunState::QueuedOffline->value)
            ->where('queued_dedup_key', $dedupKey)
            ->whereKeyNot($run->getKey())
            ->first();

        if ($existing !== null) {
            $existing->increment('coalesce_count');
            $run->markSuperseded();

            return new TechnicianApprovalResult('queued_offline');
        }

        // Preserve the original window on a re-queue (failed reconnect-run); set it
        // fresh on the first enqueue.
        $queuedAt = $run->queued_at ?? now();
        $expiresAt = $run->expires_at ?? now()->addDays(TacticalConfig::offlineQueueExpiryDays());

        $run->queueForOffline($agentId, $dedupKey, $queuedAt, $expiresAt);
        $run->proposed_meta = array_merge($run->proposed_meta ?? [], ['queued_approver_id' => $approverId]);
        $run->save();

        return new TechnicianApprovalResult('queued_offline');
    }

    /** sha256 of (agent, direct tool, canonical params) — one queued row per identical action. */
    private function queueDedupKey(string $agentId, string $directTool, array $params): string
    {
        return hash('sha256', (string) json_encode([
            'agent' => $agentId,
            'tool' => $directTool,
            'params' => $this->canonical($params),
        ]));
    }

    /** @return array<string, mixed> */
    private function executeBusTool(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->context($tool, $arguments, $clientId, $actorLabel, requireTicket: false);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Asset $asset */
        $asset = $context['asset'];
        /** @var Ticket|null $ticket */
        $ticket = $context['ticket'];
        $reason = $context['reason'];

        if ($error = $this->confirmHostnameError($tool, $arguments, $asset)) {
            $contentHash = $this->contentHash($tool, $clientId, $asset->id, $ticket?->id, $arguments);
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $asset, $contentHash, $error, $actorLabel);

            return ['error' => $error];
        }

        $prepared = $this->prepareArgumentsForTool($tool, $arguments, $asset);
        if (isset($prepared['error'])) {
            $contentHash = $this->contentHash($tool, $clientId, $asset->id, $ticket?->id, $arguments);
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $asset, $contentHash, (string) $prepared['error'], $actorLabel);

            return ['error' => $prepared['error']];
        }
        $arguments = $prepared['arguments'] ?? $arguments;

        [$action, $params, $paramError] = $this->actionAndParamsFromArguments($tool, $arguments);
        $contentHash = $this->contentHash($tool, $clientId, $asset->id, $ticket?->id, $params ?: $arguments);

        if ($paramError !== null) {
            $result = $this->bus->dispatch($action, $asset, null, $params ?: $arguments, null, $actorLabel, $ticket?->id);
            $this->auditAttempt($tool, $result->status, $clientId, $ticket, $asset, $contentHash, $paramError, $actorLabel);

            return ['error' => $paramError, 'tactical_status' => $result->status];
        }

        if ($error = $this->confirmServiceNameError($tool, $arguments, $params)) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $asset, $contentHash, $error, $actorLabel);

            return ['error' => $error];
        }

        if ($error = $this->confirmPatchInstallError($tool, $arguments)) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $asset, $contentHash, $error, $actorLabel);

            return ['error' => $error];
        }

        if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $asset, $contentHash, "Duplicate {$tool} suppressed before upstream call.", $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already executed identical Tactical action recently; no upstream call was made.',
            ];
        }

        if ($this->cooldownActive($tool, $asset, $ticket, self::COOLDOWNS[$tool] ?? 60)) {
            $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $asset, $contentHash, "{$tool} cooldown active; upstream call refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no upstream call was made."];
        }

        $token = $this->confirmTokenFor($action, $asset, $params, null);
        $result = $this->bus->dispatch($action, $asset, null, $params, $token, $actorLabel, $ticket?->id);
        $status = $result->isOk() ? 'executed' : $result->status;
        $this->auditAttempt($tool, $status, $clientId, $ticket, $asset, $contentHash, $this->directSummary($tool, $asset, $reason, $result), $actorLabel);

        if (! $result->isOk()) {
            return [
                'error' => $result->message ?? "Tactical action failed ({$result->status}).",
                'tactical_status' => $result->status,
            ];
        }

        return [
            'success' => true,
            'tactical_status' => $result->status,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
            'retcode' => $result->retcode,
            'message' => $result->stdout ?: 'Tactical action executed.',
        ];
    }

    /** @return array<string, mixed> */
    private function stageAction(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->context($tool, $arguments, $clientId, $actorLabel, requireTicket: true);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Asset $asset */
        $asset = $context['asset'];
        /** @var Ticket $ticket */
        $ticket = $context['ticket'];
        $reason = $context['reason'];
        $directTool = self::STAGED_TO_DIRECT[$tool];

        [$action, $params, $paramError, $display] = $this->stagePayload($tool, $directTool, $arguments, $asset);
        $contentHash = $this->contentHash($tool, $clientId, $asset->id, $ticket->id, $params ?: $arguments);

        if ($paramError !== null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $asset, $contentHash, $paramError, $actorLabel);

            return ['error' => $paramError];
        }

        if ($this->alreadyAwaitingOrExecuted($tool, $clientId, $contentHash)) {
            $run = TechnicianRun::query()
                ->where('ticket_id', $ticket->id)
                ->where('action_type', $tool)
                ->where('content_hash', $contentHash)
                ->where('state', TechnicianRunState::AwaitingApproval->value)
                ->first();

            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $run?->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        if ($this->proposalCooldownActive($tool, $ticket, self::COOLDOWNS[$tool] ?? 60)) {
            $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $asset, $contentHash, "{$tool} cooldown active; staged proposal refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no proposal was staged."];
        }

        $meta = [
            'drafted_by' => $actorLabel,
            'reasons' => [$reason],
            'direct_tool' => $directTool,
            'asset_id' => $asset->id,
            'asset_hostname' => $this->targetHostname($asset),
            'redacted_params' => $this->redactor->redactParams($params),
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => $directTool,
                'asset_id' => $asset->id,
                'ticket_id' => $ticket->id,
                'client_id' => $clientId,
                'params' => $params,
            ], JSON_THROW_ON_ERROR)),
        ];

        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $clientId,
            'action_type' => $tool,
            'content_hash' => $contentHash,
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => $display."\nReason: ".$reason,
            'proposed_meta' => $meta,
            'confidence' => null,
            'tokens_used' => 0,
        ]);

        TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', $tool)
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->where('id', '!=', $run->id)
            ->get()
            ->each
            ->markSuperseded();

        $this->auditAttempt($tool, 'awaiting_approval', $clientId, $ticket, $asset, $contentHash, "MCP staged {$tool} for {$this->targetHostname($asset)}: {$reason}", $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Staged for cockpit approval.',
        ];
    }

    /** @return array<string, mixed> */
    private function openRemoteControl(array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->context('tactical_open_remote_control', $arguments, $clientId, $actorLabel, requireTicket: false);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Asset $asset */
        $asset = $context['asset'];
        /** @var Ticket|null $ticket */
        $ticket = $context['ticket'];
        $type = $this->linkType($arguments['type'] ?? null);
        $contentHash = $this->contentHash('tactical_open_remote_control', $clientId, $asset->id, $ticket?->id, ['type' => $type]);

        if ($type === null) {
            $this->auditAttempt('tactical_open_remote_control', 'rejected', $clientId, $ticket, $asset, $contentHash, 'type must be one of: control, terminal, file.', $actorLabel);

            return ['error' => 'type must be one of: control, terminal, file'];
        }

        if ($this->alreadyExecuted('tactical_open_remote_control', $clientId, $contentHash)) {
            $this->auditAttempt('tactical_open_remote_control', 'blocked', $clientId, $ticket, $asset, $contentHash, 'Duplicate remote-control request suppressed before upstream call.', $actorLabel);

            return ['error' => 'Duplicate remote-control request suppressed; open a fresh session after the cooldown.'];
        }

        if ($this->cooldownActive('tactical_open_remote_control', $asset, $ticket, self::COOLDOWNS['tactical_open_remote_control'])) {
            $this->auditAttempt('tactical_open_remote_control', 'blocked', $clientId, $ticket, $asset, $contentHash, 'Remote-control cooldown active; upstream call refused.', $actorLabel);

            return ['error' => 'tactical_open_remote_control cooldown active for this target; no upstream call was made.'];
        }

        $agentId = (string) $asset->tacticalAsset->agent_id;

        try {
            $links = $this->client->getMeshCentralLinks($agentId);
        } catch (TacticalClientException) {
            $this->auditRemoteControl($asset, $ticket, $actorLabel, $type, 'error');
            $this->auditAttempt('tactical_open_remote_control', 'error', $clientId, $ticket, $asset, $contentHash, 'Could not reach Tactical to open a remote session.', $actorLabel);

            return ['error' => 'Could not reach Tactical to open a remote session.'];
        }

        $url = $links[$type] ?? null;
        $valid = $url !== null && Validator::make(['u' => $url], ['u' => [new SafeTacticalWebUrl]])->passes();
        if (! $valid) {
            $this->auditRemoteControl($asset, $ticket, $actorLabel, $type, 'error');
            $this->auditAttempt('tactical_open_remote_control', 'error', $clientId, $ticket, $asset, $contentHash, "MeshCentral {$type} link is not available for this device.", $actorLabel);

            return ['error' => "MeshCentral {$type} link is not available for this device."];
        }

        $this->auditRemoteControl($asset, $ticket, $actorLabel, $type, 'ok');
        $this->auditAttempt('tactical_open_remote_control', 'executed', $clientId, $ticket, $asset, $contentHash, "Remote-control {$type} session opened for {$this->targetHostname($asset)}.", $actorLabel);

        return [
            'success' => true,
            'url' => $url,
            'message' => 'Remote-control session opened.',
        ];
    }

    /** @return array<string, mixed> */
    private function refreshSnapshot(array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->context('tactical_refresh_device_snapshot', $arguments, $clientId, $actorLabel, requireTicket: false);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Asset $asset */
        $asset = $context['asset'];
        /** @var Ticket|null $ticket */
        $ticket = $context['ticket'];
        $contentHash = $this->contentHash('tactical_refresh_device_snapshot', $clientId, $asset->id, $ticket?->id, ['refresh' => true]);

        if ($this->cooldownActive('tactical_refresh_device_snapshot', $asset, $ticket, self::COOLDOWNS['tactical_refresh_device_snapshot'])) {
            $this->auditAttempt('tactical_refresh_device_snapshot', 'blocked', $clientId, $ticket, $asset, $contentHash, 'Snapshot refresh cooldown active; upstream read refused.', $actorLabel);

            return ['error' => 'tactical_refresh_device_snapshot cooldown active for this target; no upstream call was made.'];
        }

        $result = $this->sync->syncDeviceDetail($asset);
        $status = $result->ok ? 'executed' : 'error';
        $this->auditAttempt('tactical_refresh_device_snapshot', $status, $clientId, $ticket, $asset, $contentHash, $this->refreshSummary($asset, $result), $actorLabel);

        if (! $result->ok) {
            return [
                'error' => $result->message ?? 'Could not refresh Tactical snapshot.',
                'degraded' => true,
            ];
        }

        return [
            'success' => true,
            'status' => $result->status,
            'fresh_as_of' => $result->freshAsOf?->toISOString(),
            'message' => 'Tactical snapshot refreshed.',
        ];
    }

    /**
     * @return array{asset?: Asset, ticket?: Ticket|null, reason?: string, error?: string}
     */
    private function context(string $tool, array $arguments, int $clientId, string $actorLabel, bool $requireTicket): array
    {
        if ($keys = $this->upstreamIdentifierKeys($arguments)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, $this->contentHash($tool, $clientId, null, null, $arguments), 'Caller-supplied upstream Tactical identifiers are not accepted: '.implode(', ', $keys).'.', $actorLabel);

            return ['error' => 'Caller-supplied upstream Tactical identifiers are not accepted; provide PSA asset_id or hostname instead.'];
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, $this->contentHash($tool, $clientId, null, null, $arguments), 'reason is required.', $actorLabel);

            return ['error' => 'reason is required'];
        }

        if (TechnicianConfig::killSwitchEngaged()) {
            $this->auditAttempt($tool, 'blocked', $clientId, null, null, $this->contentHash($tool, $clientId, null, null, $arguments), 'Technician kill-switch engaged; Tactical MCP action refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; Tactical MCP action refused'];
        }

        $asset = $this->resolveAsset($arguments, $clientId);
        if (is_array($asset)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, $this->contentHash($tool, $clientId, null, null, $arguments), $asset['error'], $actorLabel);

            return $asset;
        }

        if ($error = $this->linkedAgentError($asset)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $asset, $this->contentHash($tool, $clientId, $asset->id, null, $arguments), $error, $actorLabel);

            return ['error' => $error];
        }

        $ticket = $this->ticketForContext($arguments['ticket_id'] ?? null, $clientId, $asset, $requireTicket);
        if (is_array($ticket)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $asset, $this->contentHash($tool, $clientId, $asset->id, null, $arguments), $ticket['error'], $actorLabel);

            return $ticket;
        }

        return [
            'asset' => $asset,
            'ticket' => $ticket,
            'reason' => $reason,
        ];
    }

    /** @return Asset|array{error: string} */
    private function resolveAsset(array $arguments, int $clientId): Asset|array
    {
        $assetId = $this->positiveInteger($arguments['asset_id'] ?? null);
        $hostname = trim((string) ($arguments['hostname'] ?? ''));

        if ($assetId === null && $hostname === '') {
            return ['error' => 'asset_id or hostname is required'];
        }

        $asset = null;
        if ($assetId !== null) {
            $asset = Asset::with('tacticalAsset')
                ->whereKey($assetId)
                ->where('client_id', $clientId)
                ->first();
            if (! $asset) {
                return ['error' => 'Asset not found or belongs to a different client'];
            }
        }

        if ($hostname !== '') {
            $byHostname = Asset::with('tacticalAsset')
                ->where('client_id', $clientId)
                ->whereHas('tacticalAsset', fn ($query) => $query->whereRaw('LOWER(hostname) = ?', [mb_strtolower($hostname)]))
                ->first();

            if (! $byHostname) {
                return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
            }

            if ($asset !== null && ! $asset->is($byHostname)) {
                return ['error' => 'asset_id and hostname resolve to different devices'];
            }

            $asset = $byHostname;
        }

        return $asset;
    }

    /** @return Ticket|null|array{error: string} */
    private function ticketForContext(mixed $ticketIdValue, int $clientId, Asset $asset, bool $required): Ticket|array|null
    {
        $ticketId = $this->positiveInteger($ticketIdValue);
        if ($ticketId === null) {
            return $required ? ['error' => 'ticket_id is required for staged Tactical actions'] : null;
        }

        $ticket = Ticket::find($ticketId);
        if (! $ticket || (int) $ticket->client_id !== $clientId) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }

        if (! $ticket->assets()->where('assets.id', $asset->id)->exists()) {
            return ['error' => 'Asset is not linked to this ticket'];
        }

        return $ticket;
    }

    /**
     * @return array{0: TacticalAction, 1: array<string, mixed>, 2: string|null}
     */
    private function actionAndParamsFromArguments(string $tool, array $arguments): array
    {
        try {
            return match ($tool) {
                'tactical_run_script' => [new RunScriptAction($this->redactor), $this->scriptParams($arguments), null],
                'tactical_run_command' => $this->commandAction($arguments),
                'tactical_reboot_device' => [new RebootAction, [], null],
                'tactical_shutdown_device' => [new ShutdownAction, [], null],
                'tactical_recover_mesh' => [new RecoverAction, ['mode' => 'mesh'], null],
                'tactical_set_maintenance' => [new SetMaintenanceAction, ['enabled' => $arguments['enabled'] ?? null], null],
                'tactical_start_service' => [new ServiceControlAction('start'), ['service_name' => $arguments['service_name'] ?? null], null],
                'tactical_stop_service' => [new ServiceControlAction('stop'), ['service_name' => $arguments['service_name'] ?? null], null],
                'tactical_restart_service' => [new ServiceControlAction('restart'), ['service_name' => $arguments['service_name'] ?? null], null],
                'tactical_set_service_start_type' => [new ServiceStartTypeAction, ['service_name' => $arguments['service_name'] ?? null, 'start_type' => $arguments['start_type'] ?? null], null],
                'tactical_scan_patches' => [new PatchScanAction, [], null],
                'tactical_set_patch_action' => [new PatchAction, ['patch_id' => $arguments['patch_id'] ?? null, 'action' => $arguments['action'] ?? null], null],
                'tactical_install_approved_patches' => [new PatchInstallAction, [], null],
                default => throw new \InvalidArgumentException("Unsupported Tactical action tool {$tool}"),
            };
        } catch (\InvalidArgumentException|InvalidActionParams $e) {
            return [$this->fallbackAction($tool), $this->rawParamsForTool($tool, $arguments), $e->getMessage()];
        }
    }

    /**
     * @return array{0: TacticalAction, 1: array<string, mixed>, 2: string|null, 3: string}
     */
    private function stagePayload(string $stageTool, string $directTool, array $arguments, Asset $asset): array
    {
        $prepared = $this->prepareArgumentsForTool($directTool, $arguments, $asset);
        if (isset($prepared['error'])) {
            return [$this->fallbackAction($directTool), $this->rawParamsForTool($directTool, $arguments), (string) $prepared['error'], ''];
        }
        $arguments = $prepared['arguments'] ?? $arguments;

        [$action, $params, $error] = $this->actionAndParamsFromArguments($directTool, $arguments);
        if ($error !== null) {
            return [$action, $params, $error, ''];
        }

        $display = $this->stagedDisplay($stageTool, $directTool, $asset, $action, $params);

        return [$action, $params, null, $display];
    }

    /**
     * @return array{0: TacticalAction, 1: array<string, mixed>}
     */
    private function actionAndParamsFromPayload(string $directTool, array $payload): array
    {
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        return match ($directTool) {
            'tactical_run_script' => [new RunScriptAction($this->redactor), $params],
            'tactical_run_command' => [new RunCommandAction($this->redactor), $params],
            'tactical_reboot_device' => [new RebootAction, $params],
            'tactical_shutdown_device' => [new ShutdownAction, $params],
            'tactical_recover_mesh' => [new RecoverAction, $params],
            'tactical_set_maintenance' => [new SetMaintenanceAction, $params],
            'tactical_start_service' => [new ServiceControlAction('start'), $params],
            'tactical_stop_service' => [new ServiceControlAction('stop'), $params],
            'tactical_restart_service' => [new ServiceControlAction('restart'), $params],
            'tactical_set_service_start_type' => [new ServiceStartTypeAction, $params],
            'tactical_scan_patches' => [new PatchScanAction, $params],
            'tactical_set_patch_action' => [new PatchAction, $params],
            'tactical_install_approved_patches' => [new PatchInstallAction, $params],
            default => throw new \InvalidArgumentException("Unsupported staged Tactical direct tool {$directTool}"),
        };
    }

    /**
     * @return array{0: RunCommandAction, 1: array{cmd: string, shell: string, timeout: int}, 2: null}
     */
    private function commandAction(array $arguments): array
    {
        $action = new RunCommandAction($this->redactor);
        $params = $action->validateParams([
            'shell' => $arguments['shell'] ?? null,
            'cmd' => $arguments['cmd'] ?? null,
            'timeout' => $arguments['timeout'] ?? null,
        ]);

        return [$action, $params, null];
    }

    /** @return array{tactical_script_id: int, args: string, timeout: int} */
    private function scriptParams(array $arguments): array
    {
        $script = $this->scriptFromArguments($arguments);
        $timeout = $this->positiveInteger($arguments['timeout'] ?? null)
            ?? (int) ($script->default_timeout ?? 120);

        return [
            'tactical_script_id' => (int) $script->tactical_script_id,
            'args' => (string) ($arguments['args'] ?? ''),
            'timeout' => $timeout,
        ];
    }

    private function scriptFromArguments(array $arguments): TacticalScript
    {
        $scriptId = $this->positiveInteger($arguments['script_id'] ?? null);
        $scriptName = trim((string) ($arguments['script_name'] ?? ''));

        $query = TacticalScript::query()->where('hidden', false);
        if ($scriptId !== null) {
            $script = (clone $query)->whereKey($scriptId)->first();
        } elseif ($scriptName !== '') {
            $script = (clone $query)->whereRaw('LOWER(name) = ?', [mb_strtolower($scriptName)])->first();
        } else {
            throw new \InvalidArgumentException('script_id or script_name is required');
        }

        if (! $script || (int) $script->tactical_script_id <= 0) {
            throw new \InvalidArgumentException('Tactical script not found in the local visible catalog');
        }

        return $script;
    }

    private function fallbackAction(string $tool): TacticalAction
    {
        return match ($tool) {
            'tactical_run_script' => new RunScriptAction($this->redactor),
            'tactical_run_command' => new RunCommandAction($this->redactor),
            'tactical_reboot_device' => new RebootAction,
            'tactical_shutdown_device' => new ShutdownAction,
            'tactical_recover_mesh' => new RecoverAction,
            'tactical_set_maintenance' => new SetMaintenanceAction,
            'tactical_start_service' => new ServiceControlAction('start'),
            'tactical_stop_service' => new ServiceControlAction('stop'),
            'tactical_restart_service' => new ServiceControlAction('restart'),
            'tactical_set_service_start_type' => new ServiceStartTypeAction,
            'tactical_scan_patches' => new PatchScanAction,
            'tactical_set_patch_action' => new PatchAction,
            'tactical_install_approved_patches' => new PatchInstallAction,
            default => new RecoverAction,
        };
    }

    /** @return array<string, mixed> */
    private function rawParamsForTool(string $tool, array $arguments): array
    {
        return match ($tool) {
            'tactical_run_script' => [
                'tactical_script_id' => $arguments['script_id'] ?? null,
                'args' => $arguments['args'] ?? '',
                'timeout' => $arguments['timeout'] ?? null,
            ],
            'tactical_run_command' => [
                'shell' => $arguments['shell'] ?? null,
                'cmd' => $arguments['cmd'] ?? null,
                'timeout' => $arguments['timeout'] ?? null,
            ],
            'tactical_set_maintenance' => ['enabled' => $arguments['enabled'] ?? null],
            'tactical_recover_mesh' => ['mode' => 'mesh'],
            'tactical_start_service', 'tactical_stop_service', 'tactical_restart_service' => [
                'service_name' => $arguments['service_name'] ?? null,
            ],
            'tactical_set_service_start_type' => [
                'service_name' => $arguments['service_name'] ?? null,
                'start_type' => $arguments['start_type'] ?? null,
            ],
            'tactical_set_patch_action' => [
                'patch_id' => $arguments['patch_id'] ?? null,
                'action' => $arguments['action'] ?? null,
            ],
            default => [],
        };
    }

    private function confirmTokenFor(TacticalAction $action, Asset $asset, array $params, ?int $actorId): ?string
    {
        if (! $action->isDestructive()) {
            return null;
        }

        $payloadHash = null;
        if (method_exists($action, 'payloadHash')) {
            $payloadHash = $action->payloadHash($params);
        }

        return TacticalActionConfirmToken::issue(
            $action->key(),
            (string) $asset->tacticalAsset->agent_id,
            $actorId,
            $payloadHash,
        );
    }

    private function confirmHostnameError(string $tool, array $arguments, Asset $asset): ?string
    {
        if (! in_array($tool, ['tactical_run_command', 'tactical_reboot_device', 'tactical_shutdown_device', 'tactical_stop_service', 'tactical_restart_service', 'tactical_install_approved_patches'], true)) {
            return null;
        }

        $typed = trim((string) ($arguments['confirm_hostname'] ?? ''));
        $expected = $this->targetHostname($asset);
        if ($expected === '' || strcasecmp($expected, $typed) !== 0) {
            return 'The typed hostname does not match this device. Tactical action cancelled.';
        }

        return null;
    }

    private function confirmPatchInstallError(string $tool, array $arguments): ?string
    {
        if ($tool !== 'tactical_install_approved_patches') {
            return null;
        }

        $typed = mb_strtolower(trim((string) ($arguments['confirm_install'] ?? '')));
        if ($typed !== 'install approved patches') {
            return 'Type "install approved patches" to confirm this Windows patch install. Tactical patch install cancelled.';
        }

        return null;
    }

    private function confirmServiceNameError(string $tool, array $arguments, array $params): ?string
    {
        if (! in_array($tool, ['tactical_stop_service', 'tactical_restart_service'], true)) {
            return null;
        }

        $typed = trim((string) ($arguments['confirm_service_name'] ?? ''));
        $expected = trim((string) ($params['service_name'] ?? ''));
        if ($expected === '' || strcasecmp($expected, $typed) !== 0) {
            return 'The typed service name does not match this service. Tactical service action cancelled.';
        }

        return null;
    }

    private function linkedAgentError(Asset $asset): ?string
    {
        $asset->loadMissing('tacticalAsset');

        return empty($asset->tacticalAsset?->agent_id)
            ? 'Device has no Tactical agent'
            : null;
    }

    /**
     * @return array{arguments?: array<string, mixed>, error?: string}
     */
    private function prepareArgumentsForTool(string $tool, array $arguments, Asset $asset): array
    {
        if ($tool === 'tactical_set_service_start_type' && ServiceStartTypeAction::normalizeStartType($arguments['start_type'] ?? null) === null) {
            return ['error' => 'start_type must be one of: '.implode(', ', ServiceStartTypeAction::ALLOWED_START_TYPES).'.'];
        }

        if ($tool === 'tactical_set_patch_action') {
            if (PatchAction::normalizeAction($arguments['action'] ?? null) === null) {
                return ['error' => 'action must be one of: '.implode(', ', PatchAction::ALLOWED_ACTIONS).'.'];
            }

            $resolved = $this->resolvePatchId($asset, $arguments);
            if (isset($resolved['error'])) {
                return ['error' => $resolved['error']];
            }

            $arguments['patch_id'] = $resolved['patch_id'];
        }

        if (! $this->isServiceTool($tool)) {
            return ['arguments' => $arguments];
        }

        $resolved = $this->resolveServiceName($asset, $arguments);
        if (isset($resolved['error'])) {
            return ['error' => $resolved['error']];
        }

        $arguments['service_name'] = $resolved['service_name'];

        return ['arguments' => $arguments];
    }

    /**
     * @return array{patch_id?: int, error?: string}
     */
    private function resolvePatchId(Asset $asset, array $arguments): array
    {
        $patchId = $this->positiveInteger($arguments['patch_id'] ?? null);
        $guid = isset($arguments['guid']) && is_scalar($arguments['guid']) ? trim((string) $arguments['guid']) : '';
        $kb = isset($arguments['kb']) && is_scalar($arguments['kb']) ? trim((string) $arguments['kb']) : '';
        $title = isset($arguments['title']) && is_scalar($arguments['title']) ? trim((string) $arguments['title']) : '';

        if ($patchId === null && $guid === '' && $kb === '' && $title === '') {
            return ['error' => 'patch_id, guid, kb, or title is required'];
        }

        try {
            $patches = $this->client->getPatches((string) $asset->tacticalAsset->agent_id);
        } catch (TacticalClientException) {
            return ['error' => 'Could not read Tactical Windows updates to resolve patch scope; no patch action was sent.'];
        }

        foreach ($patches as $patch) {
            if (! is_array($patch)) {
                continue;
            }

            $rowId = $this->positiveInteger($patch['id'] ?? null);
            if ($rowId === null) {
                continue;
            }

            $rowGuid = isset($patch['guid']) && is_scalar($patch['guid']) ? trim((string) $patch['guid']) : '';
            $rowKb = isset($patch['kb']) && is_scalar($patch['kb']) ? trim((string) $patch['kb']) : '';
            $rowTitle = isset($patch['title']) && is_scalar($patch['title']) ? trim((string) $patch['title']) : '';

            if (
                ($patchId !== null && $rowId === $patchId)
                || ($guid !== '' && strcasecmp($rowGuid, $guid) === 0)
                || ($kb !== '' && strcasecmp($rowKb, $kb) === 0)
                || ($title !== '' && strcasecmp($rowTitle, $title) === 0)
            ) {
                return ['patch_id' => $rowId];
            }
        }

        return ['error' => 'Windows update was not found on the server-derived Tactical agent; no patch action was sent.'];
    }

    /**
     * @return array{service_name?: string, error?: string}
     */
    private function resolveServiceName(Asset $asset, array $arguments): array
    {
        $selector = isset($arguments['service_name']) && is_scalar($arguments['service_name'])
            ? trim((string) $arguments['service_name'])
            : '';
        if ($selector === '') {
            return ['error' => 'service_name is required'];
        }

        try {
            $services = $this->client->getServices((string) $asset->tacticalAsset->agent_id);
        } catch (TacticalClientException) {
            return ['error' => 'Could not read Tactical services to resolve service scope; no service action was sent.'];
        }

        $selectorLower = mb_strtolower($selector);
        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            $name = isset($service['name']) && is_scalar($service['name']) ? trim((string) $service['name']) : '';
            $displayName = isset($service['display_name']) && is_scalar($service['display_name']) ? trim((string) $service['display_name']) : '';
            if ($name === '') {
                continue;
            }

            if (mb_strtolower($name) === $selectorLower || ($displayName !== '' && mb_strtolower($displayName) === $selectorLower)) {
                return ['service_name' => $name];
            }
        }

        return ['error' => 'Service was not found on the server-derived Tactical agent; no service action was sent.'];
    }

    /** @return array<string, mixed> */
    private function revalidateStagedPayload(array $payload, Asset $asset): array
    {
        $directTool = (string) ($payload['direct_tool'] ?? '');
        if (! $this->isServiceTool($directTool)) {
            return $payload;
        }

        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $prepared = $this->prepareArgumentsForTool($directTool, $params, $asset);
        if (isset($prepared['error'])) {
            return ['error' => $prepared['error']];
        }

        $payload['params'] = $prepared['arguments'] ?? $params;

        return $payload;
    }

    private function isServiceTool(string $tool): bool
    {
        return in_array($tool, [
            'tactical_start_service',
            'tactical_stop_service',
            'tactical_restart_service',
            'tactical_set_service_start_type',
        ], true);
    }

    /** @return array<int, string> */
    private function upstreamIdentifierKeys(array $arguments): array
    {
        $keys = [];
        foreach (self::UPSTREAM_IDENTIFIER_KEYS as $key) {
            if (array_key_exists($key, $arguments)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function alreadyExecuted(string $tool, int $clientId, string $contentHash): bool
    {
        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $clientId)
            ->where('content_hash', $contentHash)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->exists();
    }

    private function alreadyAwaitingOrExecuted(string $tool, int $clientId, string $contentHash): bool
    {
        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $clientId)
            ->where('content_hash', $contentHash)
            ->whereIn('result_status', ['awaiting_approval', 'executed'])
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->exists();
    }

    private function cooldownActive(string $tool, Asset $asset, ?Ticket $ticket, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        $since = now()->subSeconds($cooldownSeconds);

        if ($actionKey = $this->tacticalActionKey($tool)) {
            return TacticalActionLog::query()
                ->where('action_key', $actionKey)
                ->where('asset_id', $asset->id)
                ->where('created_at', '>=', $since)
                ->exists();
        }

        $query = TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $asset->client_id)
            ->where('created_at', '>=', $since)
            ->whereIn('result_status', ['executed', 'awaiting_approval']);

        if ($ticket !== null) {
            $query->where('ticket_id', $ticket->id);
        }

        return $query->exists();
    }

    private function proposalCooldownActive(string $tool, Ticket $ticket, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('ticket_id', $ticket->id)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->whereIn('result_status', ['awaiting_approval', 'executed'])
            ->exists();
    }

    private function tacticalActionKey(string $tool): ?string
    {
        return match ($tool) {
            'tactical_run_script', 'tactical_stage_script' => 'tactical.run_script',
            'tactical_run_command', 'tactical_stage_command' => 'tactical.run_command',
            'tactical_reboot_device', 'tactical_stage_reboot' => 'tactical.reboot',
            'tactical_shutdown_device', 'tactical_stage_shutdown' => 'tactical.shutdown',
            'tactical_recover_mesh', 'tactical_stage_recover_mesh' => 'tactical.recover',
            'tactical_set_maintenance', 'tactical_stage_maintenance' => 'tactical.set_maintenance',
            'tactical_open_remote_control' => 'tactical.remote_control',
            'tactical_start_service' => 'tactical.service_start',
            'tactical_stop_service', 'tactical_stage_stop_service' => 'tactical.service_stop',
            'tactical_restart_service', 'tactical_stage_restart_service' => 'tactical.service_restart',
            'tactical_set_service_start_type' => 'tactical.service_start_type',
            'tactical_scan_patches' => 'tactical.patch_scan',
            'tactical_set_patch_action' => 'tactical.patch_action',
            'tactical_install_approved_patches', 'tactical_stage_install_approved_patches' => 'tactical.patch_install',
            default => null,
        };
    }

    private function auditAttempt(
        string $actionType,
        string $resultStatus,
        ?int $clientId,
        ?Ticket $ticket,
        ?Asset $asset,
        string $contentHash,
        string $summary,
        string $actorLabel,
        ?int $runId = null,
        ?int $approverId = null,
    ): void {
        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::aiActorUserId(),
            'approver_user_id' => $approverId,
            'actor_label' => $actorLabel,
            'action_type' => $actionType,
            'tier' => TechnicianTier::Approve->value,
            'result_status' => $resultStatus,
            'ticket_id' => $ticket?->id,
            'client_id' => $clientId,
            'run_id' => $runId,
            'content_hash' => $contentHash,
            'summary' => mb_substr($this->redactor->redactString($this->summaryWithTarget($summary, $asset)), 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }

    private function auditRemoteControl(Asset $asset, ?Ticket $ticket, string $actorLabel, string $type, string $status): void
    {
        TacticalActionLog::create([
            'actor_id' => null,
            'actor_label' => $actorLabel,
            'action_key' => 'tactical.remote_control',
            'agent_id' => (string) $asset->tacticalAsset->agent_id,
            'asset_id' => $asset->id,
            'ticket_id' => $ticket?->id,
            'target_label' => $this->redactor->redactString($this->targetHostname($asset)),
            'params' => ['link_type' => $type],
            'result_status' => $status,
            'message' => $status === 'ok' ? 'Remote session opened.' : 'Remote session open failed.',
            'correlation_id' => (string) Str::uuid(),
        ]);
    }

    private function decryptRunPayload(TechnicianRun $run): ?array
    {
        $ciphertext = $run->proposed_meta['encrypted_payload'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        $json = Crypt::decryptString($ciphertext);
        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private function contentHash(string $tool, int $clientId, ?int $assetId, ?int $ticketId, array $params): string
    {
        return hash('sha256', json_encode([
            'tool' => $tool,
            'client_id' => $clientId,
            'asset_id' => $assetId,
            'ticket_id' => $ticketId,
            'params' => $this->canonical($params),
        ]));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }

    private function requiredString(array $arguments, string $key): ?string
    {
        if (! array_key_exists($key, $arguments) || ! is_scalar($arguments[$key])) {
            return null;
        }

        $value = trim((string) $arguments[$key]);

        return $value !== '' ? $value : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function linkType(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['control', 'terminal', 'file'], true)
            ? $value
            : null;
    }

    private function targetHostname(Asset $asset): string
    {
        return trim((string) ($asset->tacticalAsset?->hostname ?? $asset->hostname ?? $asset->name ?? ''));
    }

    private function summaryWithTarget(string $summary, ?Asset $asset): string
    {
        if ($asset === null) {
            return $summary;
        }

        return "asset #{$asset->id} ({$this->targetHostname($asset)}): ".$summary;
    }

    private function directSummary(string $tool, Asset $asset, string $reason, TacticalActionResult $result): string
    {
        return "{$tool} for {$this->targetHostname($asset)} {$result->status}: {$reason}";
    }

    private function approvalSummary(TechnicianRun $run, TacticalActionResult $result): string
    {
        return "Operator-approved {$run->action_type} {$result->status}.";
    }

    private function refreshSummary(Asset $asset, DetailSyncResult $result): string
    {
        return $result->ok
            ? 'Refreshed Tactical snapshot for '.$this->targetHostname($asset).'.'
            : 'Tactical snapshot refresh degraded for '.$this->targetHostname($asset).': '.($result->message ?? 'unknown error');
    }

    private function stagedDisplay(string $stageTool, string $directTool, Asset $asset, TacticalAction $action, array $params): string
    {
        $summary = match ($directTool) {
            'tactical_run_command' => 'Run command on '.$this->targetHostname($asset).': '.$action->summary($params),
            'tactical_run_script' => 'Run script on '.$this->targetHostname($asset).': '.$action->summary($params),
            'tactical_reboot_device' => 'Reboot '.$this->targetHostname($asset).'.',
            'tactical_shutdown_device' => (new ShutdownAction)->summary([]).' Target: '.$this->targetHostname($asset).'.',
            'tactical_recover_mesh' => 'Recover Mesh agent services on '.$this->targetHostname($asset).'.',
            'tactical_set_maintenance' => (new SetMaintenanceAction)->summary($params).' on '.$this->targetHostname($asset).'.',
            'tactical_stop_service', 'tactical_restart_service' => $action->summary($params).' on '.$this->targetHostname($asset).'.',
            'tactical_install_approved_patches' => 'Install approved Windows patches on '.$this->targetHostname($asset).'.',
            default => "{$stageTool} for ".$this->targetHostname($asset).'.',
        };

        return $this->redactor->redactString($summary);
    }

    private function approverLabel(int $approverId): string
    {
        $user = User::find($approverId);

        return $user?->email ?? $user?->name ?? "approver:{$approverId}";
    }

    /** @return array<string, mixed> */
    private static function targetProperties(bool $ticket = false): array
    {
        $properties = [
            'asset_id' => [
                'type' => 'integer',
                'description' => 'Optional PSA asset ID. The server verifies it belongs to client_id and derives the Tactical agent.',
            ],
            'hostname' => [
                'type' => 'string',
                'description' => 'Optional device hostname. The server resolves it within client_id and derives the Tactical agent.',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Specific operational reason for this Tactical action.',
            ],
        ];

        if ($ticket) {
            $properties['ticket_id'] = [
                'type' => 'integer',
                'description' => 'Required ticket ID for cockpit-held actions. The asset must already be linked to this ticket.',
            ];
        } else {
            $properties['ticket_id'] = [
                'type' => 'integer',
                'description' => 'Optional ticket ID for incident attribution. If supplied, the asset must already be linked to this ticket.',
            ];
        }

        return $properties;
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    private static function runScriptTool(): array
    {
        return self::tool(
            'tactical_run_script',
            'Run a visible local-catalog Tactical script on one server-derived endpoint immediately. Requires an explicit token grant, a concrete reason, kill-switch/cooldown gates, and writes both TechnicianActionLog and TacticalActionLog audit rows before returning the result.',
            array_merge(self::targetProperties(), [
                'script_id' => ['type' => 'integer', 'description' => 'Local PSA tactical_scripts.id. Upstream Tactical script IDs are rejected.'],
                'script_name' => ['type' => 'string', 'description' => 'Optional exact visible local script name when script_id is not known.'],
                'args' => ['type' => 'string', 'description' => 'Optional script arguments. Avoid inline secrets; audits are redacted.'],
                'timeout' => ['type' => 'integer', 'description' => 'Timeout seconds, 10 to 600.'],
            ]),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageScriptTool(): array
    {
        return self::tool(
            'tactical_stage_script',
            'Stage a Tactical script run for cockpit approval. The MCP call makes no upstream Tactical call. Approval revalidates the ticket/asset scope and dispatches through the audited TacticalActionService bus.',
            array_merge(self::targetProperties(ticket: true), [
                'script_id' => ['type' => 'integer', 'description' => 'Local PSA tactical_scripts.id. Upstream Tactical script IDs are rejected.'],
                'script_name' => ['type' => 'string', 'description' => 'Optional exact visible local script name when script_id is not known.'],
                'args' => ['type' => 'string', 'description' => 'Optional script arguments. The held execution payload is encrypted at rest.'],
                'timeout' => ['type' => 'integer', 'description' => 'Timeout seconds, 10 to 600.'],
            ]),
            ['ticket_id', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function runCommandTool(): array
    {
        return self::tool(
            'tactical_run_command',
            'Run an arbitrary command on one endpoint immediately. This is arbitrary remote code execution: it can change or destroy data, alter security posture, and disrupt service. Requires an explicit token grant, kill-switch, reason, confirm_hostname friction, dedup/cooldown, and audited TacticalActionService dispatch.',
            array_merge(self::targetProperties(), [
                'confirm_hostname' => ['type' => 'string', 'description' => 'Typed target hostname. Defense-in-depth friction only; grant, held/default posture, kill-switch, and cooldown are the real gates.'],
                'shell' => ['type' => 'string', 'enum' => ['cmd', 'powershell', 'shell'], 'description' => 'Command shell.'],
                'cmd' => ['type' => 'string', 'description' => 'Command body to execute. Avoid inline secrets; audits redact known credential shapes.'],
                'timeout' => ['type' => 'integer', 'description' => 'Timeout seconds, 10 to 600.'],
            ]),
            ['reason', 'confirm_hostname', 'shell', 'cmd', 'timeout'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageCommandTool(): array
    {
        return self::tool(
            'tactical_stage_command',
            'Stage an arbitrary endpoint command for cockpit approval instead of running it now. The MCP call makes no upstream Tactical call; the execution payload is encrypted at rest and approval revalidates scope before audited dispatch.',
            array_merge(self::targetProperties(ticket: true), [
                'shell' => ['type' => 'string', 'enum' => ['cmd', 'powershell', 'shell'], 'description' => 'Command shell.'],
                'cmd' => ['type' => 'string', 'description' => 'Command body to hold for approval. Avoid inline secrets.'],
                'timeout' => ['type' => 'integer', 'description' => 'Timeout seconds, 10 to 600.'],
            ]),
            ['ticket_id', 'reason', 'shell', 'cmd', 'timeout'],
        );
    }

    /** @return array<string, mixed> */
    private static function rebootTool(): array
    {
        return self::tool(
            'tactical_reboot_device',
            'Reboot one server-derived endpoint immediately. This disrupts the user and active services. Requires an explicit token grant, reason, confirm_hostname friction, kill-switch, cooldown, and audited TacticalActionService dispatch.',
            array_merge(self::targetProperties(), [
                'confirm_hostname' => ['type' => 'string', 'description' => 'Typed target hostname. Defense-in-depth friction only.'],
            ]),
            ['reason', 'confirm_hostname'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRebootTool(): array
    {
        return self::tool(
            'tactical_stage_reboot',
            'Stage an endpoint reboot for cockpit approval. The MCP call makes no upstream Tactical call; approval revalidates scope and dispatches through TacticalActionService.',
            self::targetProperties(ticket: true),
            ['ticket_id', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function shutdownTool(): array
    {
        return self::tool(
            'tactical_shutdown_device',
            'Shut down one server-derived endpoint immediately. The device powers off and cannot be powered back on remotely; recovery requires physical/IPMI access. Requires an explicit token grant, reason, confirm_hostname friction, kill-switch, cooldown, and audited TacticalActionService dispatch.',
            array_merge(self::targetProperties(), [
                'confirm_hostname' => ['type' => 'string', 'description' => 'Typed target hostname. Defense-in-depth friction only.'],
            ]),
            ['reason', 'confirm_hostname'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageShutdownTool(): array
    {
        return self::tool(
            'tactical_stage_shutdown',
            'Stage an endpoint shutdown for cockpit approval. Shutdown powers off the device and it cannot be powered back on remotely; approval revalidates scope before dispatch.',
            self::targetProperties(ticket: true),
            ['ticket_id', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function recoverTool(): array
    {
        return self::tool(
            'tactical_recover_mesh',
            'Recover Mesh agent services on one server-derived endpoint immediately. Requires an explicit token grant, reason, kill-switch, cooldown, and audited TacticalActionService dispatch.',
            self::targetProperties(),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRecoverTool(): array
    {
        return self::tool(
            'tactical_stage_recover_mesh',
            'Stage a Mesh agent service recovery for cockpit approval. The MCP call makes no upstream Tactical call; approval revalidates scope before dispatch.',
            self::targetProperties(ticket: true),
            ['ticket_id', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setMaintenanceTool(): array
    {
        return self::tool(
            'tactical_set_maintenance',
            'Enable or disable Tactical maintenance mode for one server-derived endpoint immediately. This suppresses or resumes alerting. Requires an explicit token grant, reason, kill-switch, cooldown, and audited TacticalActionService dispatch.',
            array_merge(self::targetProperties(), [
                'enabled' => ['type' => 'boolean', 'description' => 'true to enable maintenance mode, false to disable it.'],
            ]),
            ['reason', 'enabled'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageMaintenanceTool(): array
    {
        return self::tool(
            'tactical_stage_maintenance',
            'Stage a Tactical maintenance-mode change for cockpit approval. The MCP call makes no upstream Tactical call; approval revalidates scope before dispatch.',
            array_merge(self::targetProperties(ticket: true), [
                'enabled' => ['type' => 'boolean', 'description' => 'true to enable maintenance mode, false to disable it.'],
            ]),
            ['ticket_id', 'reason', 'enabled'],
        );
    }

    /** @return array<string, mixed> */
    private static function remoteControlTool(): array
    {
        return self::tool(
            'tactical_open_remote_control',
            'Mint a one-time MeshCentral remote-control link for one server-derived endpoint. Requires an explicit token grant, reason, kill-switch, cooldown, URL-free audits, and returns the URL with no-store response headers.',
            array_merge(self::targetProperties(), [
                'type' => ['type' => 'string', 'enum' => ['control', 'terminal', 'file'], 'description' => 'Remote session link type.'],
            ]),
            ['reason', 'type'],
        );
    }

    /** @return array<string, mixed> */
    private static function refreshSnapshotTool(): array
    {
        return self::tool(
            'tactical_refresh_device_snapshot',
            'Refresh the local Tactical device snapshot for one server-derived endpoint. This is a live read plus local write, not an endpoint mutation; it still requires an explicit token grant, reason, kill-switch, and cooldown.',
            self::targetProperties(),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function serviceSelectorProperties(): array
    {
        return [
            'service_name' => [
                'type' => 'string',
                'description' => 'Service selector. The server resolves this against the current service list for the server-derived Tactical agent and then sends the canonical service name upstream.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function destructiveServiceConfirmProperties(): array
    {
        return [
            'confirm_hostname' => [
                'type' => 'string',
                'description' => 'Typed target hostname. Defense-in-depth friction only.',
            ],
            'confirm_service_name' => [
                'type' => 'string',
                'description' => 'Typed canonical service name. The server compares it after resolving service_name from the current service list.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function startServiceTool(): array
    {
        return self::tool(
            'tactical_start_service',
            'Start one Windows service on a server-derived endpoint immediately using Tactical POST services/{agent_id}/{svcname}/ with sv_action=start. Requires an explicit token grant, reason, kill-switch, current service-list resolution, dedup/cooldown, and audited TacticalActionService dispatch.',
            array_merge(self::targetProperties(), self::serviceSelectorProperties()),
            ['reason', 'service_name'],
        );
    }

    /** @return array<string, mixed> */
    private static function stopServiceTool(): array
    {
        return self::tool(
            'tactical_stop_service',
            'Stop one Windows service on a server-derived endpoint immediately. This can interrupt applications or dependent services. Requires an explicit token grant, reason, confirm_hostname, confirm_service_name, kill-switch, current service-list resolution, dedup/cooldown, and audited TacticalActionService dispatch.',
            array_merge(self::targetProperties(), self::serviceSelectorProperties(), self::destructiveServiceConfirmProperties()),
            ['reason', 'service_name', 'confirm_hostname', 'confirm_service_name'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageStopServiceTool(): array
    {
        return self::tool(
            'tactical_stage_stop_service',
            'Stage a Windows service stop for cockpit approval instead of stopping it now. Stopping a service can interrupt applications or dependent services; approval revalidates ticket, asset, kill-switch, cooldown, and current service scope before dispatch.',
            array_merge(self::targetProperties(ticket: true), self::serviceSelectorProperties()),
            ['ticket_id', 'reason', 'service_name'],
        );
    }

    /** @return array<string, mixed> */
    private static function restartServiceTool(): array
    {
        return self::tool(
            'tactical_restart_service',
            'Restart one Windows service on a server-derived endpoint immediately. Tactical stops then starts the service, which can interrupt applications or dependent services. Requires an explicit token grant, reason, confirm_hostname, confirm_service_name, kill-switch, current service-list resolution, dedup/cooldown, and audited TacticalActionService dispatch.',
            array_merge(self::targetProperties(), self::serviceSelectorProperties(), self::destructiveServiceConfirmProperties()),
            ['reason', 'service_name', 'confirm_hostname', 'confirm_service_name'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRestartServiceTool(): array
    {
        return self::tool(
            'tactical_stage_restart_service',
            'Stage a Windows service restart for cockpit approval instead of restarting it now. Tactical stops then starts the service; approval revalidates ticket, asset, kill-switch, cooldown, and current service scope before dispatch.',
            array_merge(self::targetProperties(ticket: true), self::serviceSelectorProperties()),
            ['ticket_id', 'reason', 'service_name'],
        );
    }

    /** @return array<string, mixed> */
    private static function setServiceStartTypeTool(): array
    {
        return self::tool(
            'tactical_set_service_start_type',
            'Set one Windows service start type on a server-derived endpoint using Tactical PUT services/{agent_id}/{svcname}/ with startType. This can affect service startup after reboot or recovery. Requires an explicit token grant, reason, kill-switch, current service-list resolution, start_type allowlist, dedup/cooldown, and audited TacticalActionService dispatch.',
            array_merge(self::targetProperties(), self::serviceSelectorProperties(), [
                'start_type' => [
                    'type' => 'string',
                    'enum' => ServiceStartTypeAction::ALLOWED_START_TYPES,
                    'description' => 'Allowed Tactical startType value.',
                ],
            ]),
            ['reason', 'service_name', 'start_type'],
        );
    }

    /** @return array<string, mixed> */
    private static function patchSelectorProperties(): array
    {
        return [
            'patch_id' => [
                'type' => 'integer',
                'description' => 'Optional Windows update id from tactical_get_device_patches. The server re-reads current patches for the derived Tactical agent and sends the matching id upstream.',
            ],
            'guid' => [
                'type' => 'string',
                'description' => 'Optional Windows update GUID selector. The server resolves it against the current patch list for the derived Tactical agent.',
            ],
            'kb' => [
                'type' => 'string',
                'description' => 'Optional KB selector such as KB5000001. The server resolves it against the current patch list for the derived Tactical agent.',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Optional exact patch title selector. The server resolves it against the current patch list for the derived Tactical agent.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function scanPatchesTool(): array
    {
        return self::tool(
            'tactical_scan_patches',
            'Start a Windows update scan on one server-derived endpoint using Tactical POST winupdate/{agent_id}/scan/. Requires explicit grant, reason, kill-switch, dedup/cooldown, and audited TacticalActionService dispatch.',
            self::targetProperties(),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setPatchActionTool(): array
    {
        return self::tool(
            'tactical_set_patch_action',
            'Set one visible Windows update action on a server-derived endpoint using Tactical PUT winupdate/{pk}/ with action only. The upstream serializer accepts broad fields; this tool narrows it to action=approve|ignore|nothing|inherit after resolving the update from the current agent patch list.',
            array_merge(self::targetProperties(), self::patchSelectorProperties(), [
                'action' => [
                    'type' => 'string',
                    'enum' => PatchAction::ALLOWED_ACTIONS,
                    'description' => 'Allowed Tactical WinUpdate action value.',
                ],
            ]),
            ['reason', 'action'],
        );
    }

    /** @return array<string, mixed> */
    private static function installApprovedPatchesTool(): array
    {
        return self::tool(
            'tactical_install_approved_patches',
            'Install all approved Windows updates on one server-derived endpoint immediately using Tactical POST winupdate/{agent_id}/install/. Approved Windows updates can reboot, interrupt users, and change system state. Requires explicit grant, reason, confirm_hostname, confirm_install, kill-switch, dedup/cooldown, and audited TacticalActionService dispatch.',
            array_merge(self::targetProperties(), [
                'confirm_hostname' => ['type' => 'string', 'description' => 'Typed target hostname. Defense-in-depth friction only.'],
                'confirm_install' => ['type' => 'string', 'description' => 'Type exactly: install approved patches'],
            ]),
            ['reason', 'confirm_hostname', 'confirm_install'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageInstallApprovedPatchesTool(): array
    {
        return self::tool(
            'tactical_stage_install_approved_patches',
            'Stage installation of all approved Windows updates on one endpoint for cockpit approval. Approved Windows updates can reboot, interrupt users, and change system state; approval revalidates ticket, asset, kill-switch, cooldown, and server-derived agent scope before dispatch.',
            self::targetProperties(ticket: true),
            ['ticket_id', 'reason'],
        );
    }
}
