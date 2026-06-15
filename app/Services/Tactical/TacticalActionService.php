<?php

namespace App\Services\Tactical;

use App\Models\Asset;
use App\Models\TacticalActionLog;
use App\Models\User;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Tactical\Actions\InvalidActionParams;
use App\Services\Tactical\Actions\TacticalAction;
use App\Services\Tactical\Actions\TacticalActionResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The Tactical action bus (spec §5.1, §5.2) — the single chokepoint EVERY
 * endpoint-affecting action flows through. One pipeline for all of them:
 *
 *   1. resolve   — Asset → linked tactical_assets.agent_id (else `error`)
 *   2. authorize — single-tier capability gate (else `denied`)
 *   3. validate  — action->validateParams (InvalidActionParams ⇒ `rejected`)
 *   4. confirm   — destructive actions require a valid confirm token (else `blocked`)
 *   5. execute   — action->execute, catching TacticalClientException and
 *                  classifying it on the STRUCTURED signal (M2): transport
 *                  failure ⇒ `offline`; HTTP error (401/403/404/5xx) ⇒ `error`
 *   6. audit     — write exactly ONE immutable, redacted TacticalActionLog row
 *                  on EVERY path, with a correlation id
 *   7. return    — the normalized result (never an unhandled exception)
 *
 * Authenticated-hit denials only — Laravel's auth middleware rejects truly
 * unauthenticated requests before the bus, so those live in the app log (the
 * "audit all" claim is scoped to what reaches the bus; spec §11.2).
 */
class TacticalActionService
{
    public function __construct(
        private readonly TacticalClient $client,
        private readonly ActionRedactor $redactor = new ActionRedactor,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  string|null  $actorLabel  attribution when there is no User (M1:
     *                                   the AI-triage path passes 'ai-triage')
     * @param  int|null  $ticketId  ticket-originated paths pass the ticket id so
     *                              the audit row carries per-incident ITIL history (m1)
     */
    public function dispatch(
        TacticalAction $action,
        Asset $target,
        ?User $actor,
        array $params,
        ?string $confirmToken = null,
        ?string $actorLabel = null,
        ?int $ticketId = null,
    ): TacticalActionResult {
        $correlationId = (string) Str::uuid();
        $agentId = $target->tacticalAsset?->agent_id;
        $label = $this->resolveActorLabel($actor, $actorLabel);

        // 1. resolve
        if (empty($agentId)) {
            return $this->audit(
                $action, $target, $actor, $label, null, $params, $ticketId, $correlationId,
                TacticalActionResult::error('Asset is not linked to a Tactical agent'),
            );
        }

        // 2. authorize — single-tier: any authenticated staff OR a system label.
        if ($actor === null && $actorLabel === null) {
            return $this->audit(
                $action, $target, null, $label, $agentId, $params, $ticketId, $correlationId,
                TacticalActionResult::denied('Not authorized to dispatch Tactical actions'),
            );
        }

        // 3. validate + normalize params.
        try {
            $params = $action->validateParams($params);
        } catch (InvalidActionParams $e) {
            return $this->audit(
                $action, $target, $actor, $label, $agentId, $params, $ticketId, $correlationId,
                TacticalActionResult::rejected($e->getMessage()),
            );
        }

        // 4. confirm — destructive actions require a token bound to {action,agent,actor}.
        if ($action->isDestructive()) {
            $ok = $confirmToken !== null && TacticalActionConfirmToken::verify(
                $confirmToken,
                $action->key(),
                $agentId,
                $actor?->id,
                $this->payloadHash($action, $params),
            );

            if (! $ok) {
                return $this->audit(
                    $action, $target, $actor, $label, $agentId, $params, $ticketId, $correlationId,
                    TacticalActionResult::blocked('Confirmation required (or it expired) for this destructive action'),
                );
            }
        }

        // 5. execute + classify.
        try {
            $result = $action->execute($this->client, $agentId, $params);
        } catch (TacticalClientException $e) {
            if ($this->isAgentOffline($e)) {
                $result = TacticalActionResult::offline('Tactical agent is unreachable (offline)');
            } else {
                // M2: a genuine HTTP error (auth/4xx/5xx) is NEVER collapsed to offline.
                Log::error('[TacticalActionService] action failed with an HTTP error', [
                    'action' => $action->key(),
                    'agent_id' => $agentId,
                    'status' => $e->statusCode(),
                    'correlation_id' => $correlationId,
                ]);
                $result = TacticalActionResult::error(
                    'Tactical API error'.($e->statusCode() ? " (HTTP {$e->statusCode()})" : '')
                );
            }
        }

        // 6 + 7. audit and return.
        return $this->audit(
            $action, $target, $actor, $label, $agentId, $params, $ticketId, $correlationId, $result,
        );
    }

    /**
     * Is this failure the agent being unreachable (offline) vs. a genuine error?
     *
     * Transport failures (no HTTP response) are always offline. Additionally,
     * Tactical signals an unreachable agent with an HTTP 400 whose body is a
     * known marker (e.g. "Unable to contact the agent" / "natsdown") — confirmed
     * against a live agent. Those classify as offline; everything else
     * (401/403/404/5xx, or any other status) stays a real error to surface.
     *
     * The marker body-sniff is SCOPED to HTTP 400 (code-review M2 hardening):
     * the markers are matched as substrings, so a genuine auth/permission error
     * (401/403) or a 5xx whose body merely CONTAINS "agent is offline" must NOT
     * be reclassified as a safe offline no-op — that would mask a key compromise
     * or RBAC failure. Only Tactical's 400 natsdown carries the marker.
     */
    private function isAgentOffline(TacticalClientException $e): bool
    {
        if ($e->isTransportFailure()) {
            return true;
        }

        if ($e->statusCode() !== 400) {
            return false;
        }

        $body = strtolower((string) $e->responseBody());

        if ($body === '') {
            return false;
        }

        foreach (['unable to contact the agent', 'natsdown', 'agent is offline', 'nats timeout'] as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hook for a payload-bound confirm token (amendment M8). Reboot has no
     * free-text payload (null); P3's ad-hoc `cmd` will override an action method
     * to return sha256 of the canonical resolved command. Kept here so the bus
     * binds the hash uniformly without each action re-deriving it.
     *
     * @param  array<string, mixed>  $params
     */
    private function payloadHash(TacticalAction $action, array $params): ?string
    {
        if (method_exists($action, 'payloadHash')) {
            /** @var string|null $hash */
            $hash = $action->payloadHash($params);

            return $hash;
        }

        return null;
    }

    private function resolveActorLabel(?User $actor, ?string $actorLabel): string
    {
        if ($actorLabel !== null && $actorLabel !== '') {
            return $actorLabel;
        }

        return $actor?->email ?? $actor?->name ?? 'unknown';
    }

    /**
     * Write exactly one immutable, redacted audit row and return the result
     * unchanged. Redaction (B1) is applied PER VALUE before building the JSON
     * column — never redact(json_encode(...)).
     *
     * @param  array<string, mixed>  $params
     */
    private function audit(
        TacticalAction $action,
        Asset $target,
        ?User $actor,
        string $actorLabel,
        ?string $agentId,
        array $params,
        ?int $ticketId,
        string $correlationId,
        TacticalActionResult $result,
    ): TacticalActionResult {
        $audit = $result->audit();

        TacticalActionLog::create([
            'actor_id' => $actor?->id,
            'actor_label' => $actorLabel,
            'action_key' => $action->key(),
            'agent_id' => $agentId,
            'asset_id' => $target->id,
            'ticket_id' => $ticketId,
            'target_label' => $this->redactor->redactString((string) ($target->hostname ?? $target->name ?? 'unknown')),
            'params' => $this->redactor->redactParams($params),
            'result_status' => $audit['result_status'],
            'retcode' => $audit['retcode'],
            'output' => $this->redactor->redactOutput($audit['output']),
            'message' => $audit['message'] !== null
                ? $this->redactor->redactString($audit['message'])
                : null,
            'correlation_id' => $correlationId,
        ]);

        return $result;
    }
}
