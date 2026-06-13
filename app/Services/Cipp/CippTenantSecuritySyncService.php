<?php

namespace App\Services\Cipp;

use App\Models\Client;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

/**
 * Snapshot per-tenant mail security configuration (transport rules, Safe
 * Links policy, Safe Attachments filters) onto the Client record so staff
 * and the AI triage agent see it as ambient context without a tool call.
 *
 * Each fetch is independent — one endpoint failing doesn't block the others.
 * Failures leave the previous snapshot in place; we don't null out on error.
 */
class CippTenantSecuritySyncService
{
    public function __construct(
        private readonly CippClient $client,
    ) {}

    public function syncAll(): SyncResult
    {
        $clients = Client::whereNotNull('cipp_tenant_domain')
            ->where('is_active', true)
            ->get();

        $result = new SyncResult;

        foreach ($clients as $client) {
            try {
                $this->syncForClient($client, $result);
            } catch (\Throwable $e) {
                Log::error("[CippTenantSecurity] Failed for {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
            }
        }

        return $result;
    }

    public function syncForClient(Client $client, SyncResult $result): void
    {
        $tenantDomain = $client->cipp_tenant_domain;
        $updates = ['cipp_mail_security_synced_at' => now()];
        $anySuccess = false;

        try {
            $rules = $this->client->listTransportRules($tenantDomain);
            if (is_array($rules)) {
                $updates['cipp_transport_rules'] = $rules;
                $anySuccess = true;
            }
        } catch (\Throwable $e) {
            Log::warning("[CippTenantSecurity] Transport rules failed for {$client->name}: {$e->getMessage()}");
            $result->recordError("Transport rules for {$client->name}: {$e->getMessage()}");
        }

        try {
            $safeLinks = $this->client->listSafeLinksPolicy($tenantDomain);
            if (is_array($safeLinks)) {
                $updates['cipp_safe_links_policy'] = $safeLinks;
                $anySuccess = true;
            }
        } catch (\Throwable $e) {
            Log::warning("[CippTenantSecurity] Safe Links failed for {$client->name}: {$e->getMessage()}");
            $result->recordError("Safe Links for {$client->name}: {$e->getMessage()}");
        }

        try {
            $safeAttachments = $this->client->listSafeAttachmentsFilters($tenantDomain);
            if (is_array($safeAttachments)) {
                $updates['cipp_safe_attachments_filters'] = $safeAttachments;
                $anySuccess = true;
            }
        } catch (\Throwable $e) {
            Log::warning("[CippTenantSecurity] Safe Attachments failed for {$client->name}: {$e->getMessage()}");
            $result->recordError("Safe Attachments for {$client->name}: {$e->getMessage()}");
        }

        try {
            $caPolicies = $this->client->listConditionalAccessPolicies($tenantDomain);
            if (is_array($caPolicies)) {
                $updates['cipp_conditional_access_policies'] = $caPolicies;
                $anySuccess = true;
            }
        } catch (\Throwable $e) {
            Log::warning("[CippTenantSecurity] Conditional Access failed for {$client->name}: {$e->getMessage()}");
            $result->recordError("Conditional Access for {$client->name}: {$e->getMessage()}");
        }

        try {
            $compliance = $this->client->listCompliancePolicies($tenantDomain);
            if (is_array($compliance)) {
                $updates['cipp_compliance_policies'] = $compliance;
                $anySuccess = true;
            }
        } catch (\Throwable $e) {
            Log::warning("[CippTenantSecurity] Compliance policies failed for {$client->name}: {$e->getMessage()}");
            $result->recordError("Compliance policies for {$client->name}: {$e->getMessage()}");
        }

        if ($anySuccess) {
            $client->update($updates);
            $result->updated++;
        }

        // Wiki Phase 2: deterministic environment facts from this sync (never breaks the sync).
        // Runs even when all CIPP calls failed: it snapshots the currently-stored fields, which is intentional.
        app(\App\Services\Wiki\SyncFactWriter::class)->safeWriteM365Facts($client);
    }
}
