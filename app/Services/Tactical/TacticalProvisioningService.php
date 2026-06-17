<?php

namespace App\Services\Tactical;

use App\Models\Setting;
use App\Support\TacticalConfig;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent, no-clobber, perms-aware provisioning of Tactical's alert→ticket
 * pipeline (P7 / G2–G5).
 *
 * Flow:
 *  1. Ensure a webhook key exists (generates + stores if absent).
 *  2. Upsert URLAction — PUT if stored id, POST if not; on PUT 404 fall back to
 *     POST and overwrite the stored id. IMMEDIATELY strip rest_headers + rest_body
 *     from the decoded response before any log/audit/return (G3: Tactical echoes
 *     the key back in its response).
 *  3. Upsert AlertTemplate — same PUT/POST/404-fallback pattern.
 *  4. GET core/settings; if a *different* alert_template is already the default,
 *     record the prior id + return a warning (do NOT silently clobber). Only call
 *     setDefaultAlertTemplate when the current default is null or already ours (G4).
 *  5. Store ids + provisioned_at.
 *  6. Audit with actor id + outcome, secret-free (G2).
 *
 * All Tactical calls go through TacticalClient (X-API-KEY + SSRF pin — G5).
 * 403 → actionable error message, never a generic 500 (G2).
 */
class TacticalProvisioningService
{
    public function __construct(private readonly TacticalClient $client) {}

    /**
     * Provision (or re-provision) the Tactical alert→ticket pipeline.
     *
     * @param  int  $actorId  The authenticated user's id — written to the audit row.
     * @return array{success: bool, message: string, warning?: string}
     */
    public function provision(int $actorId): array
    {
        try {
            return $this->doProvision($actorId);
        } catch (TacticalClientException $e) {
            $message = $this->mapException($e);
            $this->writeAudit($actorId, false, null, null, $message);
            // FIX 1 (security): NEVER pass $e->getMessage() to any sink on the
            // failure path — Guzzle's BodySummarizer bakes the response body (which
            // can include rest_headers containing the X-Webhook-Key) into the
            // exception message. Log only the status code, not the raw body summary.
            Log::warning('[TacticalProvisioning] Provision failed', [
                'actor_id' => $actorId,
                'status'   => $e->statusCode(),
            ]);

            return ['success' => false, 'message' => $message];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function doProvision(int $actorId): array
    {
        // 1. Ensure webhook key exists
        $webhookKey = TacticalConfig::get('webhook_key');
        if (! $webhookKey) {
            $webhookKey = TacticalConfig::generateWebhookKey();
            Setting::setEncrypted('tactical_webhook_key', $webhookKey);
        }

        // 2. Upsert URLAction
        $urlActionId = TacticalConfig::urlActionId();
        $urlActionBody = $this->buildUrlActionBody($webhookKey);

        if ($urlActionId !== null) {
            try {
                // PUT returns "ok" scalar (live-verified) — no id to extract; we
                // already have it and just need to confirm the update succeeded.
                $this->client->updateUrlAction($urlActionId, $urlActionBody);
                $newUrlActionId = $urlActionId;
            } catch (TacticalClientException $e) {
                if ($e->statusCode() === 404) {
                    // Human deleted it in Tactical — fall back to create + find.
                    $this->client->createUrlAction($urlActionBody);
                    $newUrlActionId = $this->findUrlActionIdByName($urlActionBody['name']);
                } else {
                    throw $e;
                }
            }
        } else {
            // POST returns "ok" scalar (live-verified 2026-06-17 — not an object
            // with `id`). Resolve the id by GETting the list and matching by name.
            $this->client->createUrlAction($urlActionBody);
            $newUrlActionId = $this->findUrlActionIdByName($urlActionBody['name']);
        }

        // G3: The webhook key is in rest_headers in the body we SENT, not in the
        // response (Tactical returns "ok", not an echo of the body). Nothing to
        // strip from the response; the urlActionBody local is discarded here.

        Setting::setValue('tactical_url_action_id', (string) $newUrlActionId);

        // 3. Upsert AlertTemplate
        $alertTemplateId = TacticalConfig::alertTemplateId();
        $templateBody = $this->buildAlertTemplateBody($newUrlActionId);

        if ($alertTemplateId !== null) {
            try {
                // PUT returns "ok" scalar (live-verified) — id already known.
                $this->client->updateAlertTemplate($alertTemplateId, $templateBody);
                $newAlertTemplateId = $alertTemplateId;
            } catch (TacticalClientException $e) {
                if ($e->statusCode() === 404) {
                    $this->client->createAlertTemplate($templateBody);
                    $newAlertTemplateId = $this->findAlertTemplateIdByName($templateBody['name']);
                } else {
                    throw $e;
                }
            }
        } else {
            // POST returns "ok" scalar (live-verified 2026-06-17 — not an object
            // with `id`). Resolve the id by GETting the list and matching by name.
            $this->client->createAlertTemplate($templateBody);
            $newAlertTemplateId = $this->findAlertTemplateIdByName($templateBody['name']);
        }

        Setting::setValue('tactical_alert_template_id', (string) $newAlertTemplateId);

        // 4. GET core/settings; no-clobber check
        $warning = null;
        $coreSettings = $this->client->getCoreSettings();
        $currentDefault = $coreSettings['alert_template'] ?? null;

        if ($currentDefault !== null && (int) $currentDefault !== $newAlertTemplateId) {
            // A different template is already the default — do NOT silently clobber.
            Setting::setValue('tactical_prior_default_alert_template_id', (string) $currentDefault);
            // FIX 2: Operator-facing copy must accurately reflect that the existing
            // default was NOT changed — the old "Changed your … default … to ours"
            // was false (we skipped setDefaultAlertTemplate) and would mislead an
            // operator into believing auto-ticketing is already live.
            $warning = "A different alert template (id {$currentDefault}) is already your Tactical global default; "
                . "it was left unchanged to avoid clobbering it. "
                . "To activate PSA auto-ticketing, set the default to template id {$newAlertTemplateId} "
                . "in Tactical → Settings → Alert Templates.";
            // We intentionally do NOT call setDefaultAlertTemplate here.
        } else {
            // Empty or already ours — safe to set.
            $this->client->setDefaultAlertTemplate($newAlertTemplateId);
        }

        // 5. Store provisioned_at
        Setting::setValue('tactical_webhook_provisioned_at', now()->toIso8601String());

        // 6. Audit (secret-free)
        $this->writeAudit($actorId, true, $newUrlActionId, $newAlertTemplateId, null);

        $result = [
            'success' => true,
            'message' => "Tactical alert→ticket pipeline provisioned. URLAction id: {$newUrlActionId}, AlertTemplate id: {$newAlertTemplateId}.",
        ];

        if ($warning !== null) {
            $result['warning'] = $warning;
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function buildUrlActionBody(string $webhookKey): array
    {
        return [
            'name'         => 'PSA Ticket Webhook',
            'desc'         => 'PSA integration: creates and resolves tickets from Tactical alerts.',
            'pattern'      => url('/api/webhooks/tactical'),
            'action_type'  => 'rest',
            'rest_method'  => 'post',
            'rest_headers' => json_encode([
                'Content-Type'  => 'application/json',
                'X-Webhook-Key' => $webhookKey,
            ]),
            'rest_body'    => json_encode([
                'alert_id'   => '{{alert.id}}',
                'alert_type' => '{{alert.alert_type}}',
                'severity'   => '{{alert.severity}}',
                'agent'      => '{{agent.hostname}}',
                'client'     => '{{client.name}}',
                'site'       => '{{site.name}}',
                'message'    => '{{alert.message}}',
            ]),
        ];
    }

    private function buildAlertTemplateBody(int $urlActionId): array
    {
        return [
            'name'                   => 'PSA Auto-Ticket',
            'is_active'              => true,
            'action_type'            => 'rest',
            'action_rest'            => $urlActionId,
            'resolved_action_type'   => 'rest',
            'resolved_action_rest'   => $urlActionId,
            'agent_script_actions'   => true,
            'check_script_actions'   => true,
            'task_script_actions'    => true,
        ];
    }

    /**
     * Map a TacticalClientException to a human-readable message.
     * G2: 403 → actionable error (not a generic 500).
     */
    private function mapException(TacticalClientException $e): string
    {
        if ($e->statusCode() === 403) {
            return "403 Forbidden: Your Tactical API key's role lacks permission to provision alert→ticket. "
                . "Grant 'Run URL Actions', 'Manage Alert Templates', and Core Settings write access "
                . "to the API key's role in Tactical, then try again. "
                . "Or configure URL Actions and Alert Templates manually in Tactical Settings.";
        }

        // FIX 1 (security): do NOT use $e->getMessage() here — the Guzzle
        // BodySummarizer can embed the response body in it, which may contain
        // rest_headers with the X-Webhook-Key. Use the HTTP status code only.
        $status = $e->statusCode() ?? 'unknown';

        return "Tactical provisioning failed (HTTP {$status}).";
    }

    /**
     * Resolve a URLAction id by name after a POST (Tactical's POST core/urlaction/
     * returns the scalar "ok", not an object — live-verified 2026-06-17).
     *
     * Tactical allows duplicate names, so if multiple actions share the name,
     * we take the one with the highest id — it is the one we just created.
     *
     * Throws TacticalClientException if no matching name is found in the list.
     */
    private function findUrlActionIdByName(string $name): int
    {
        $actions = $this->client->getUrlActions();
        $bestId = null;

        foreach ($actions as $a) {
            if (($a['name'] ?? '') === $name && is_int($a['id'] ?? null) && $a['id'] > 0) {
                if ($bestId === null || $a['id'] > $bestId) {
                    $bestId = $a['id'];
                }
            }
        }

        if ($bestId === null) {
            throw new TacticalClientException(
                "Tactical URLAction '{$name}' was created (POST returned ok) but could not be found by name in GET core/urlaction/."
            );
        }

        return $bestId;
    }

    /**
     * Resolve an AlertTemplate id by name after a POST (Tactical's POST
     * alerts/templates/ returns the scalar "ok", not an object — live-verified
     * 2026-06-17).
     *
     * Tactical allows duplicate names, so if multiple templates share the name,
     * we take the one with the highest id — it is the one we just created.
     *
     * Throws TacticalClientException if no matching name is found in the list.
     */
    private function findAlertTemplateIdByName(string $name): int
    {
        $templates = $this->client->getAlertTemplates();
        $bestId = null;

        foreach ($templates as $t) {
            if (($t['name'] ?? '') === $name && is_int($t['id'] ?? null) && $t['id'] > 0) {
                if ($bestId === null || $t['id'] > $bestId) {
                    $bestId = $t['id'];
                }
            }
        }

        if ($bestId === null) {
            throw new TacticalClientException(
                "Tactical AlertTemplate '{$name}' was created (POST returned ok) but could not be found by name in GET alerts/templates/."
            );
        }

        return $bestId;
    }

    /**
     * Write a secret-free audit entry to Settings (G2).
     * Stores a JSON blob keyed tactical_provision_audit.
     * Does NOT include the webhook key, rest_headers, or any secret.
     */
    private function writeAudit(
        int $actorId,
        bool $success,
        ?int $urlActionId,
        ?int $alertTemplateId,
        ?string $errorMessage,
    ): void {
        $audit = [
            'actor_id'          => $actorId,
            'provisioned_at'    => now()->toIso8601String(),
            'success'           => $success,
            'url_action_id'     => $urlActionId,
            'alert_template_id' => $alertTemplateId,
        ];

        if ($errorMessage !== null) {
            $audit['error'] = $errorMessage;
        }

        // Explicitly confirm no secret fields are present (defence-in-depth)
        unset($audit['webhook_key'], $audit['rest_headers'], $audit['rest_body']);

        Setting::setValue('tactical_provision_audit', json_encode($audit));
    }
}
