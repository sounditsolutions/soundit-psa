<?php

namespace App\Services\Huntress;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Ticket;
use App\Services\AlertService;
use App\Services\T2T\T2TFieldMapper;
use App\Services\TicketService;
use App\Support\HuntressConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HuntressService
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly AlertService $alertService,
    ) {}

    /**
     * Create a ticket from CW-format data sent by Huntress incident webhooks.
     *
     * Huntress titles follow: "(CRITICAL|HIGH|LOW) - Incident on $agent ($org)"
     * Client resolution: huntress_organization_id mapping is primary.
     * Dedup: client_id + subject hash within 15-min window.
     */
    public function createTicketFromCw(array $data): array
    {
        $rawSubject = $data['summary'] ?? 'Huntress Incident Report';
        $subject = $this->sanitizeString($rawSubject, 255);
        $description = $this->sanitizeString($data['initialDescription'] ?? '', 65535);

        // Parse severity from title for priority mapping
        $parsed = $this->parseIncidentTitle($rawSubject);
        $priority = HuntressFieldMapper::severityToTicketPriority($parsed['severity']);

        // Resolve client via huntress_organization_id mapping
        $client = $this->resolveClient($data, $parsed['organization']);

        // Silently drop incidents for unmapped organizations
        if (! $client) {
            Log::info('[Huntress CW] Dropping incident for unmapped organization', [
                'org_name' => $parsed['organization'],
                'subject' => $subject,
            ]);

            return [
                'id' => 0,
                'summary' => $subject,
                '_info' => ['notes' => 'Dropped — organization not mapped to a client'],
            ];
        }

        // Dedup: extract incident report ID from body URL, fall back to subject hash
        $incidentReportUrl = null;
        if (preg_match('#(https://\S+huntress\.io/org/\d+/infection_reports/\d+)#', $description, $m)) {
            $incidentReportUrl = $m[1];
        }

        // Capture an escalation id if the payload carries an escalations URL. This makes an
        // escalation ticket reconcilable by EXACT id (HuntressEscalationReconcileService's id
        // fast path) rather than the weaker org+subject correspondence. Most escalation
        // payloads (e.g. account-level "Failed to Deliver") carry none — a no-op then.
        $escalationId = null;
        if (preg_match('#escalations/(\d+)#', $description, $m)) {
            $escalationId = (int) $m[1];
        }

        $duplicate = null;
        if ($incidentReportUrl) {
            $duplicate = Ticket::where('source', TicketSource::Huntress->value)
                ->where('description', 'like', '%'.$incidentReportUrl.'%')
                ->first();
        } else {
            // Fallback: same client + subject hash within 15-min window
            $subjectHash = md5($subject);
            $duplicate = Ticket::where('source', TicketSource::Huntress->value)
                ->where('client_id', $client->id)
                ->whereRaw('MD5(subject) = ?', [$subjectHash])
                ->where('created_at', '>=', now()->subMinutes(15))
                ->first();
        }

        if ($duplicate) {
            $duplicate->load(['client', 'contact']);
            Log::info('[Huntress CW] Duplicate incident detected, returning existing', [
                'ticket_id' => $duplicate->id,
            ]);

            return T2TFieldMapper::ticketToCwFormat($duplicate);
        }

        // source_alert_id for the unified Alert — use incident report URL if extracted, else synthesize
        $alertSourceId = $incidentReportUrl ?? md5($subject.($client?->id ?? ''));
        $parsedSeverity = $parsed['severity'];

        // Link asset by hostname match if available
        $assetId = null;
        if ($parsed['agent'] && $client) {
            $asset = Asset::where('client_id', $client->id)
                ->where('hostname', $parsed['agent'])
                ->active()
                ->first();

            if ($asset) {
                $assetId = $asset->id;
            } else {
                Log::info('[Huntress CW] No asset match for hostname', [
                    'hostname' => $parsed['agent'],
                    'client_id' => $client->id,
                ]);
            }
        }

        $systemUserId = HuntressConfig::systemUserId();

        if (! $systemUserId) {
            throw new \RuntimeException('Huntress system user not configured and no users exist in the system');
        }

        $ticketData = [
            'subject' => $subject,
            'description' => $description,
            'client_id' => $client?->id,
            'priority' => $priority->value,
            'type' => TicketType::Incident->value,
            'source' => TicketSource::Huntress->value,
        ];

        $ticket = DB::transaction(function () use ($ticketData, $assetId, $systemUserId) {
            $ticket = $this->ticketService->createTicket($ticketData, $systemUserId);

            // Attach asset if found
            if ($assetId) {
                $ticket->assets()->attach($assetId, ['is_primary' => true]);
            }

            // Audit note
            $this->ticketService->addNote(
                $ticket,
                'Submitted via Huntress incident report.',
                NoteType::StatusChange,
                true,
                $systemUserId,
            );

            return $ticket;
        });

        $ticket->load(['client', 'contact']);

        // Create unified Alert in Ticketed status — CW compat requires ticket, alert provides monitoring visibility
        $assetForAlert = $assetId ? Asset::find($assetId) : null;
        $hostname = $assetForAlert?->hostname ?? $parsed['agent'] ?? 'Unknown';
        $unifiedSeverity = AlertSeverity::fromVendor(AlertSource::Huntress, $parsedSeverity);

        $alert = $this->alertService->upsert(
            AlertSource::Huntress,
            $alertSourceId,
            [
                'asset_id' => $assetId,
                'client_id' => $client?->id,
                'severity' => $unifiedSeverity,
                'title' => mb_substr($subject, 0, 255),
                'message' => mb_substr($description, 0, 65535),
                'hostname' => $hostname,
                'fired_at' => now(),
                'metadata' => [
                    'incident_report_url' => $incidentReportUrl,
                    'escalation_id' => $escalationId,
                    'organization' => $parsed['organization'],
                    'agent' => $parsed['agent'],
                    'vendor_severity' => $parsedSeverity,
                ],
            ],
        );

        // Mark alert as Ticketed immediately since CW compat always creates a ticket
        $alert->update([
            'status' => AlertStatus::Ticketed,
            'ticket_id' => $ticket->id,
        ]);

        Log::info('[Huntress CW] Created ticket and alert', [
            'ticket_id' => $ticket->id,
            'alert_id' => $alert->id,
            'client_id' => $client?->id,
            'priority' => $priority->value,
            'source' => 'huntress',
        ]);

        return T2TFieldMapper::ticketToCwFormat($ticket);
    }

    /**
     * Update a ticket from CW-format PATCH data sent by Huntress.
     *
     * Post-remediation maps to Resolved (not Closed) — require human verification.
     * Only allows modification of Huntress-sourced tickets.
     */
    public function updateTicketFromCw(Ticket $ticket, array $data): array
    {
        if ($ticket->source !== TicketSource::Huntress) {
            throw new \InvalidArgumentException('Cannot modify non-Huntress ticket');
        }

        $systemUserId = HuntressConfig::systemUserId();

        if (! $systemUserId) {
            throw new \RuntimeException('Huntress system user not configured and no users exist in the system');
        }

        // Handle JSON Patch format: [{op, path, value}, ...]
        if (isset($data[0]['op'])) {
            foreach ($data as $op) {
                $this->applyPatchOperation($ticket, $op, $systemUserId);
            }
        } else {
            // Flat update format
            if (isset($data['status']['id'])) {
                $cwStatus = T2TFieldMapper::cwStatusToTicketStatus((int) $data['status']['id']);
                // Map Closed → Resolved for human verification
                $newStatus = ($cwStatus === TicketStatus::Closed) ? TicketStatus::Resolved : $cwStatus;

                if ($ticket->status !== $newStatus) {
                    $this->ticketService->changeStatus(
                        $ticket, $newStatus, $systemUserId, 'Updated via Huntress incident webhook'
                    );
                    $ticket->refresh();
                }
            }

            if (isset($data['priority']['id'])) {
                $newPriority = T2TFieldMapper::cwPriorityToTicketPriority((int) $data['priority']['id']);
                $this->ticketService->updateTicket($ticket, [
                    'priority' => $newPriority->value,
                    'priority_order' => $newPriority->sortOrder(),
                ]);
                $ticket->refresh();
            }
        }

        $ticket->load(['client', 'contact']);

        // If the ticket was moved to Resolved, also resolve the linked alert
        if ($ticket->status === TicketStatus::Resolved) {
            $linkedAlert = Alert::where('source', AlertSource::Huntress)
                ->where('ticket_id', $ticket->id)
                ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
                ->first();

            if ($linkedAlert) {
                $this->alertService->resolve(
                    $linkedAlert,
                    'Resolved via Huntress post-remediation webhook.',
                );
            }
        }

        return T2TFieldMapper::ticketToCwFormat($ticket);
    }

    /**
     * Parse Huntress incident title to extract severity, agent hostname, and org name.
     *
     * Old format: "[Huntress Detection] CRITICAL - ISOLATED - Incident on $agent ($org)"
     * New format: "Huntress {Product} {Severity} {Category} | Incident on $agent ($org)"
     *   e.g.: "Huntress EDR Critical Incident Report | Incident on DESKTOP-ARL0EQ1 (Infinite Improbability)"
     * Falls back gracefully if parse fails.
     */
    private function parseIncidentTitle(string $title): array
    {
        $default = [
            'severity' => 'HIGH', // Default to P2 if parse fails
            'agent' => null,
            'organization' => null,
        ];

        // Match severity + "Incident on agent (org)" — works for both old and new formats
        if (preg_match('/(CRITICAL|HIGH|LOW).*?Incident\s+on\s+(.+?)\s*\((.+?)\)\s*$/i', $title, $matches)) {
            return [
                'severity' => strtoupper($matches[1]),
                'agent' => $this->sanitizeString($matches[2], 255),
                'organization' => $this->sanitizeString($matches[3], 255),
            ];
        }

        // Fallback: just severity anywhere in title (handles escalations and other categories)
        if (preg_match('/(CRITICAL|HIGH|LOW)/i', $title, $matches)) {
            $default['severity'] = strtoupper($matches[1]);
        }

        Log::warning('[Huntress CW] Could not fully parse incident title, using defaults', [
            'title' => mb_substr($title, 0, 200),
        ]);

        return $default;
    }

    /**
     * Resolve client from Huntress CW payload.
     *
     * Priority: huntress_organization_id mapping → org name exact match → null.
     */
    private function resolveClient(array $data, ?string $orgName): ?Client
    {
        // Try CW company ID first (our internal client ID)
        $companyId = $data['company']['id'] ?? null;
        if ($companyId && $companyId > 0) {
            $client = Client::where('id', $companyId)->operational()->first();
            if ($client) {
                return $client;
            }
        }

        // Try org name match against huntress_organization_id-mapped clients
        if ($orgName) {
            $client = Client::where('name', $orgName)->operational()->first();
            if ($client) {
                Log::info('[Huntress CW] Client resolved by org name match', [
                    'org_name' => $orgName,
                    'client_id' => $client->id,
                ]);

                return $client;
            }
        }

        Log::warning('[Huntress CW] Could not resolve client — ticket will be created without client', [
            'company_id' => $companyId,
            'org_name' => $orgName,
        ]);

        return null;
    }

    private function applyPatchOperation(Ticket $ticket, array $op, int $systemUserId): void
    {
        $path = $op['path'] ?? '';
        $value = $op['value'] ?? null;

        // Extract status ID from either CW format:
        // Standard: path="status/id", value=5
        // Huntress: path="status", value={"id":"5","name":"Resolved"}
        $statusId = null;
        if ($op['op'] === 'replace' && $path === 'status/id' && $value) {
            $statusId = (int) $value;
        } elseif ($op['op'] === 'replace' && $path === 'status' && is_array($value) && isset($value['id'])) {
            $statusId = (int) $value['id'];
        }

        if ($statusId) {
            $cwStatus = T2TFieldMapper::cwStatusToTicketStatus($statusId);
            // Map Closed → Resolved for human verification
            $newStatus = ($cwStatus === TicketStatus::Closed) ? TicketStatus::Resolved : $cwStatus;

            if ($ticket->status !== $newStatus) {
                $this->ticketService->changeStatus(
                    $ticket, $newStatus, $systemUserId, 'Updated via Huntress incident webhook'
                );
                $ticket->refresh();
            }
        }

        // Extract priority ID from either format
        $priorityId = null;
        if ($op['op'] === 'replace' && $path === 'priority/id' && $value) {
            $priorityId = (int) $value;
        } elseif ($op['op'] === 'replace' && $path === 'priority' && is_array($value) && isset($value['id'])) {
            $priorityId = (int) $value['id'];
        }

        if ($priorityId) {
            $newPriority = T2TFieldMapper::cwPriorityToTicketPriority($priorityId);
            $this->ticketService->updateTicket($ticket, [
                'priority' => $newPriority->value,
                'priority_order' => $newPriority->sortOrder(),
            ]);
            $ticket->refresh();
        }
    }

    /**
     * Sanitize a string: trim, strip control characters, enforce length limit.
     */
    private function sanitizeString(string $value, int $maxLength): string
    {
        // Strip control characters (except newlines and tabs)
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        return mb_substr(trim($clean), 0, $maxLength);
    }
}
