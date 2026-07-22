<?php

namespace App\Services\Triage;

use App\Enums\TicketCategoryChangeSource;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Asset;
use App\Models\Person;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketCategoryChangeLog;
use App\Models\TicketNote;
use App\Services\Cipp\HandlesCippTools;
use App\Services\Comet\CometClient;
use App\Services\Comet\CometJobService;
use App\Services\ControlD\ControlDAnalyticsClient;
use App\Services\ControlD\ControlDClient;
use App\Services\Level\LevelClient;
use App\Services\Mesh\MeshClient;
use App\Services\Ninja\NinjaClient;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalFieldMap;
use App\Services\Wiki\HandlesWikiTools;
use App\Support\ControlDConfig;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Executes tool calls from the technical triage agentic loop.
 * All queries are scoped to the ticket's client_id for data isolation.
 */
class TriageToolExecutor
{
    use HandlesCippTools;
    use HandlesWikiTools;

    private Ticket $ticket;

    // Read by HandlesWikiTools; always set from the ticket (triage wiki scope is never global-only).
    protected int $clientId;

    public function __construct(Ticket $ticket)
    {
        if (! $ticket->client_id) {
            throw new \RuntimeException("Cannot execute triage tools — ticket {$ticket->id} has no client");
        }

        $this->ticket = $ticket;
        $this->clientId = $ticket->client_id;
    }

    /**
     * Execute a tool call. Returns the result as a string or array.
     */
    public function execute(string $toolName, array $input): mixed
    {
        Log::debug('[Triage] Tool call', [
            'tool' => $toolName,
            'ticket_id' => $this->ticket->id,
            'input' => $input,
        ]);

        return match ($toolName) {
            // PSA tools
            'search_tickets' => $this->searchTickets($input),
            'get_ticket_notes' => $this->getTicketNotes($input),
            'list_client_tickets' => $this->listClientTickets($input), // agent-only (readTools + READ_TOOLS)
            'list_client_calls' => $this->listClientCalls($input),     // agent-only (readTools + READ_TOOLS)
            'get_client_security_posture' => $this->getClientSecurityPosture(), // agent-only (readTools + READ_TOOLS)
            'set_ticket_priority' => $this->setTicketPriority($input),
            'set_ticket_status' => $this->setTicketStatus($input),
            'set_ticket_category' => $this->setTicketCategory($input),
            'set_ticket_keywords' => $this->setTicketKeywords($input),

            // NinjaRMM tools
            'ninja_search_devices' => $this->ninjaSearchDevices($input),
            'ninja_get_device' => $this->ninjaGetDevice($input),
            'ninja_get_device_volumes' => $this->ninjaGetDeviceSub($input, 'volumes'),
            'ninja_get_device_alerts' => $this->ninjaGetDeviceSub($input, 'alerts'),
            'ninja_get_device_os_patches' => $this->ninjaGetDeviceSub($input, 'os-patches'),
            'ninja_get_device_software' => $this->ninjaGetDeviceSub($input, 'software'),
            'ninja_get_device_processors' => $this->ninjaGetDeviceSub($input, 'processors'),
            'ninja_get_device_disk_drives' => $this->ninjaGetDeviceSub($input, 'disks'),
            'ninja_get_device_network_interfaces' => $this->ninjaGetDeviceSub($input, 'network-interfaces'),
            'ninja_get_device_windows_services' => $this->ninjaGetDeviceSub($input, 'windows-services'),
            'ninja_get_device_last_user' => $this->ninjaGetDeviceSub($input, 'last-logged-on-user'),

            // Level RMM tools
            'level_get_device' => $this->levelGetDevice($input),

            // Mesh tools
            'mesh_search_email_logs' => $this->meshSearchLogs($input),
            'mesh_get_email_events' => $this->meshGetEvents($input),

            // CIPP tools (dispatch + bodies shared via HandlesCippTools)
            'cipp_list_users' => $this->cippQuery('cipp_list_users', $input, 'api/ListUsers'),
            'cipp_list_mailboxes' => $this->cippQuery('cipp_list_mailboxes', $input, 'api/ListMailboxes'),
            'cipp_list_licenses' => $this->cippQuery('cipp_list_licenses', $input, 'api/ListLicenses'),
            'cipp_list_devices' => $this->cippQuery('cipp_list_devices', $input, 'api/ListDevices'),
            'cipp_list_sign_ins' => $this->cippListSignIns($input),
            'cipp_list_groups' => $this->cippQuery('cipp_list_groups', $input, 'api/ListGroups'),
            'cipp_list_user_groups' => $this->cippQueryWithUser('cipp_list_user_groups', $input, 'api/ListUserGroups'),
            'cipp_list_mailbox_permissions' => $this->cippQueryWithUser('cipp_list_mailbox_permissions', $input, 'api/ListmailboxPermissions'),
            // ListUserMailboxRules, NOT ListMailboxRules: the latter takes no user
            // parameter and returns EVERY mailbox's rules in the tenant (psa-7lgo.1).
            'cipp_list_mailbox_rules' => $this->cippQueryWithUser('cipp_list_mailbox_rules', $input, 'api/ListUserMailboxRules', 'UserID'),
            // The tenant-wide sweep (psa-4k6m) — deliberately the endpoint the line above
            // refuses, because here that IS the question: hunt BEC forwarding rules across
            // every mailbox at once. Unlike its per-mailbox sibling this does not reach
            // Exchange: it reads CIPP's hourly cache and can answer "still loading" with an
            // empty list, which CippQueueGuard turns into an error rather than a false
            // all-clear. See CippToolContract::shapeTenantMailboxRules().
            'cipp_list_tenant_mailbox_rules' => $this->cippQuery('cipp_list_tenant_mailbox_rules', $input, 'api/ListMailboxRules'),
            'cipp_list_defender_state' => $this->cippQuery('cipp_list_defender_state', $input, 'api/ListDefenderState'),
            'cipp_list_conditional_access_policies' => $this->cippQuery('cipp_list_conditional_access_policies', $input, 'api/ListConditionalAccessPolicies'),
            'cipp_list_user_conditional_access' => $this->cippQueryWithUser('cipp_list_user_conditional_access', $input, 'api/ListUserConditionalAccessPolicies'),
            'cipp_list_audit_logs' => $this->cippListAuditLogs($input),
            'cipp_list_message_trace' => $this->cippListMessageTrace($input),
            'cipp_list_mail_quarantine' => $this->cippListMailQuarantine($input),
            'cipp_list_user_mfa_methods' => $this->cippListUserMfaMethods($input),
            'cipp_list_oauth_apps' => $this->cippListOauthApps($input),

            // Control D tools
            'controld_get_devices' => $this->controldGetDevices(),
            'controld_dns_queries' => $this->controldDnsQueries($input),

            // Zorus tools
            'zorus_get_endpoints' => $this->zorusGetEndpoints(),

            // Tactical RMM tools
            'tactical_get_device' => $this->tacticalGetDevice($input),
            'tactical_get_device_checks' => $this->tacticalGetDeviceChecks($input),
            'tactical_get_device_network' => $this->tacticalGetDeviceNetwork($input),
            'tactical_get_device_software' => $this->tacticalGetDeviceSoftware($input),
            'tactical_get_device_services' => $this->tacticalGetDeviceServices($input),
            'tactical_get_device_disks' => $this->tacticalGetDeviceDisks($input),
            'tactical_run_diagnostic' => $this->tacticalRunDiagnostic($input),

            // Comet Backup tools
            'comet_get_backup_status' => $this->executeCometGetBackupStatus($input),
            'comet_get_backup_jobs' => $this->executeCometGetBackupJobs($input),

            // DNS tools (always available, no client scope required)
            'dns_lookup' => \App\Services\Dns\DnsToolkit::lookup($input['hostname'] ?? '', $input['type'] ?? ''),
            'dns_email_health' => \App\Services\Dns\DnsToolkit::emailHealth($input['domain'] ?? ''),

            // Wiki retrieval tools (client-scoped + global; spec §6)
            'wiki_list_pages' => $this->wikiListPages(),
            'wiki_search' => $this->wikiSearch($input),
            'wiki_get_page' => $this->wikiGetPage($input),

            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    // ── PSA Tools ──

    private function searchTickets(array $input): array
    {
        $query = $input['query'] ?? '';
        $limit = min($input['limit'] ?? 10, 20);

        // CLIENT-SCOPED: only search tickets for this client
        $tickets = Ticket::where('client_id', $this->clientId)
            ->search($query)
            ->where('id', '!=', $this->ticket->id) // Exclude current ticket
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'subject', 'status', 'priority', 'resolution', 'created_at', 'resolved_at']);

        return $tickets->map(fn (Ticket $t) => [
            'id' => $t->id,
            'display_id' => $t->display_id,
            'subject' => $t->subject,
            'status' => $t->status->value,
            'priority' => $t->priority->value,
            'resolution' => $t->resolution ? mb_substr($t->resolution, 0, 500) : null,
            'created_at' => $t->created_at?->toDateTimeString(),
            'resolved_at' => $t->resolved_at?->toDateTimeString(),
        ])->toArray();
    }

    /**
     * AGENT-ONLY situation drill-down: list THIS client's tickets by status with NO
     * keyword — the gap search_tickets (which requires a keyword) cannot close.
     *
     * Client scope is the ctor's $this->clientId (the ctor throws on a clientless ticket),
     * so the tool is implicitly client-bound: no client/ticket id is accepted and
     * cross-client reads are impossible. The current ticket is excluded; limit is hard-capped
     * at 20. Every free-text field passes through the shared ClientSituationContextBuilder::
     * scrub() (homoglyph-fold → strip_tags → withhold credential/injection hits → cap), the
     * same control the situation digest uses. status=closed additionally returns the
     * (scrubbed) resolution for verbatim fix-reuse. Any failure returns a generic error —
     * never $e->getMessage() — so no internal detail leaks to the model.
     */
    private function listClientTickets(array $input): array
    {
        try {
            $status = $input['status'] ?? 'open';
            $limit = max(1, min((int) ($input['limit'] ?? 20), 20));

            $query = Ticket::where('client_id', $this->clientId)
                ->where('id', '!=', $this->ticket->id);

            // Status map (there is intentionally NO scopePending): open() already includes
            // the pending statuses, so 'pending' narrows via an explicit whereIn.
            $query = match ($status) {
                'pending' => $query->whereIn('status', [TicketStatus::PendingClient, TicketStatus::PendingThirdParty]),
                'closed' => $query->closed(),
                'all' => $query,
                default => $query->open(), // 'open' and any unexpected value → the safe default
            };

            $tickets = $query->orderByDesc('opened_at')
                ->limit($limit)
                ->get(['id', 'halo_id', 'subject', 'status', 'priority', 'opened_at', 'resolution']);

            $includeResolution = $status === 'closed';

            return $tickets->map(function (Ticket $t) use ($includeResolution): array {
                $row = [
                    'id' => $t->id,
                    'display_id' => $t->display_id,
                    'subject' => ClientSituationContextBuilder::scrub($t->subject, 120),
                    'status' => $t->status->value,
                    'priority' => $t->priority->value,
                    'opened_at' => $t->opened_at?->toIso8601String(),
                ];

                if ($includeResolution) {
                    $row['resolution'] = ClientSituationContextBuilder::scrub($t->resolution, 600);
                }

                return $row;
            })->toArray();
        } catch (\Throwable) {
            return ['error' => 'lookup failed'];
        }
    }

    /**
     * AGENT-ONLY situation drill-down: list THIS client's recent phone calls with
     * summaries + sentiment — no keyword needed, scoped to $this->clientId.
     *
     * Data-minimisation boundary: the column allowlist EXCLUDES the three raw
     * transcript columns (transcription, transcription_summary, cleaned_transcript),
     * matching the recentCalls() allowlist in ClientSituationContextBuilder. Transcripts
     * stay on the existing per-ticket call tool only.
     *
     * All free-text (call_summary, next_steps) passes through ClientSituationContextBuilder::
     * scrub(). Nullable enums (charge_classification) and nullable int (sentiment_score)
     * are null-guarded. Hard-cap: ≤ 20 calls. Generic error only.
     */
    private function listClientCalls(array $input): array
    {
        try {
            $limit = max(1, min((int) ($input['limit'] ?? 10), 20));

            $calls = \App\Models\PhoneCall::forClient($this->clientId)
                ->recent($limit)
                ->get(['id', 'direction', 'started_at', 'call_summary', 'next_steps', 'charge_classification', 'sentiment_score']);

            return $calls->map(fn (\App\Models\PhoneCall $call): array => [
                'id' => $call->id,
                'direction' => $call->direction?->value,
                'date' => $call->started_at?->toDateString(),
                'summary' => ClientSituationContextBuilder::scrub($call->call_summary, 400),
                'next_steps' => ClientSituationContextBuilder::scrub($call->next_steps, 400),
                'charge' => $call->charge_classification?->value,
                'sentiment' => $call->sentiment_score,
            ])->toArray();
        } catch (\Throwable) {
            return ['error' => 'lookup failed'];
        }
    }

    /**
     * AGENT-ONLY situation drill-down: the full, client-scoped M365/security
     * picture — the depth behind the digest header's BEC/MFA key-indicator flags.
     * Used for security-relevant tickets. Scoped to $this->clientId; no id args.
     *
     * MED-1 (load-bearing): external mail-forwards are rendered DOMAIN ONLY. The
     * raw mailbox_forwarding_smtp is NEVER surfaced — a full email is both PII and
     * an attacker-settable value that would survive scrub()/the fence, so only the
     * extracted @-domain is returned. hasExternalForward()'s own-domain compare
     * can't be expressed in SQL, so the (rare) forwarders are loaded and filtered
     * in PHP — ->limit(50) bounds that work.
     *
     * mail_security comes straight from Client::securitySnapshot(), which returns
     * null until a CIPP sync has run (null-guarded — never fabricated). Every
     * contact name passes through the shared ClientSituationContextBuilder::scrub().
     * Generic error only.
     */
    private function getClientSecurityPosture(): array
    {
        try {
            $externalForwards = Person::where('client_id', $this->clientId)
                ->whereNotNull('mailbox_forwarding_smtp')
                ->limit(50) // bound the in-PHP own-domain filter (security CO)
                ->get()
                ->filter(fn (Person $p): bool => $p->hasExternalForward())
                ->map(fn (Person $p): array => [
                    'contact' => ClientSituationContextBuilder::scrub($p->full_name, 120),
                    // DOMAIN ONLY — never the raw mailbox_forwarding_smtp (MED-1).
                    // Through scrub() for field-level parity with every other tool field.
                    'forward_domain' => ClientSituationContextBuilder::scrub(mb_strtolower(substr(strrchr((string) $p->mailbox_forwarding_smtp, '@') ?: '', 1)), 120),
                ])
                ->values()
                ->all();

            return [
                'mail_security' => $this->ticket->client?->securitySnapshot(), // null until a CIPP sync runs
                'mfa_gaps' => $this->securityContactGroup(
                    Person::where('client_id', $this->clientId)->where('mfa_enabled', false)
                ),
                'external_forwards' => $externalForwards,
                'inactive_accounts' => $this->securityContactGroup(
                    Person::where('client_id', $this->clientId)->where('cipp_inactive', true)
                ),
                'open_device_alerts' => Asset::where('client_id', $this->clientId)
                    ->withCount('activeAlerts')
                    ->get()
                    ->sum('active_alerts_count'),
            ];
        } catch (\Throwable) {
            return ['error' => 'lookup failed'];
        }
    }

    /**
     * Shared {count, contacts[]} shape for a client-scoped Person gap query: the
     * FULL count plus up to 20 scrubbed contact names (every name through scrub()).
     */
    private function securityContactGroup(\Illuminate\Database\Eloquent\Builder $query): array
    {
        return [
            'count' => (clone $query)->count(),
            'contacts' => (clone $query)
                ->limit(20)
                ->get(['first_name', 'last_name'])
                ->map(fn (Person $p): string => ClientSituationContextBuilder::scrub($p->full_name, 120))
                ->values()
                ->all(),
        ];
    }

    private function getTicketNotes(array $input): array
    {
        $ticketId = $input['ticket_id'] ?? null;
        if (! $ticketId || (! is_int($ticketId) && ! is_string($ticketId))) {
            return ['error' => 'ticket_id is required'];
        }

        // CLIENT-SCOPED: verify the ticket belongs to this client. Resolve
        // display ids too ("#8351" / bare synced number) — callers echo back
        // the number they see, which diverges from the internal id on
        // externally-synced tickets (psa-gq0f).
        $ticket = Ticket::resolveReference($ticketId, $this->clientId);

        if (! $ticket) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }

        $notes = TicketNote::where('ticket_id', $ticket->id)
            ->orderBy('noted_at')
            ->limit(20)
            ->get();

        return $notes->map(fn (TicketNote $n) => [
            'type' => $n->note_type?->value,
            'author' => $n->author?->name ?? $n->author_name ?? 'System',
            'body' => mb_substr(strip_tags($n->body ?? ''), 0, 1000),
            'date' => $n->noted_at?->toDateTimeString(),
            'is_private' => $n->is_private,
        ])->toArray();
    }

    private function setTicketPriority(array $input): array
    {
        $level = $input['priority'] ?? null;
        if (! $level || ! in_array($level, [1, 2, 3, 4])) {
            return ['error' => 'priority must be 1, 2, 3, or 4'];
        }

        $priorityMap = [1 => 'p1', 2 => 'p2', 3 => 'p3', 4 => 'p4'];
        $priority = TicketPriority::from($priorityMap[$level]);

        $this->ticket->update([
            'priority' => $priority,
            'priority_order' => $priority->sortOrder(),
        ]);

        Log::info('[Triage] AI set ticket priority', [
            'ticket_id' => $this->ticket->id,
            'priority' => $priority->value,
        ]);

        return ['success' => true, 'priority' => $priority->value];
    }

    private function setTicketStatus(array $input): array
    {
        $statusValue = $input['status'] ?? null;
        $status = TicketStatus::tryFrom($statusValue);

        if (! $status) {
            return ['error' => "Invalid status: {$statusValue}"];
        }

        $allowed = $this->ticket->status->allowedTransitions();
        if (! in_array($status, $allowed, true)) {
            return ['error' => "Cannot transition from {$this->ticket->status->label()} to {$status->label()}"];
        }

        $oldStatus = $this->ticket->status;
        $this->ticket->status = $status;

        if ($status === TicketStatus::Resolved) {
            $this->ticket->resolved_at = now();
        }
        if ($status === TicketStatus::Closed) {
            $this->ticket->closed_at = now();
            if (! $this->ticket->resolved_at) {
                $this->ticket->resolved_at = now();
            }
        }

        $this->ticket->save();

        Log::info('[Triage] AI set ticket status', [
            'ticket_id' => $this->ticket->id,
            'from' => $oldStatus->value,
            'to' => $status->value,
        ]);

        return ['success' => true, 'status' => $status->value];
    }

    private function setTicketCategory(array $input): array
    {
        $category = $input['category'] ?? null;
        $subcategory = $input['subcategory'] ?? null;

        if (! $category) {
            return ['error' => 'category is required'];
        }

        // Validate category exists in config
        $validCategories = config('tickets.categories', []);
        if (! array_key_exists($category, $validCategories)) {
            return ['error' => "Invalid category: {$category}. Valid: ".implode(', ', array_keys($validCategories))];
        }

        // Validate subcategory if provided
        if ($subcategory && ! in_array($subcategory, $validCategories[$category])) {
            return ['error' => "Invalid subcategory '{$subcategory}' for {$category}. Valid: ".implode(', ', $validCategories[$category])];
        }

        $updates = ['category' => $category];
        if ($subcategory) {
            $updates['subcategory'] = $subcategory;
        }

        $this->ticket->update($updates);

        // so-0ftg Part 4: the same classification read also coarsely places the
        // ticket on the SOP taxonomy — mapping, not a second classifier.
        $taxonomy = $this->applyTaxonomyMapping($category, $subcategory);

        Log::info('[Triage] AI set ticket category', [
            'ticket_id' => $this->ticket->id,
            'category' => $category,
            'subcategory' => $subcategory,
            'taxonomy_status' => $taxonomy['status'],
            'taxonomy_path' => $taxonomy['path'],
        ]);

        return [
            'success' => true,
            'category' => $category,
            'subcategory' => $subcategory,
            'taxonomy' => $taxonomy,
        ];
    }

    /**
     * Coarse triage->taxonomy mapping (so-0ftg Part 4). Reuses the legacy
     * pair the loop just wrote: resolves it through TaxonomyNodeMapper and
     * points tickets.category_id at the mapped node so the SOP surfaces on
     * the next get_ticket_detail. Four hard rules:
     *
     *  - Ownership is decided from the LOCKED ROW ALONE: the read-decide-write
     *    runs in one transaction holding a row lock on the ticket, and the
     *    owner is read from tickets.category_source — stamped by
     *    TicketObserver::updating() in the same UPDATE statement as every
     *    category_id change, so value and owner are atomic under the lock.
     *    Two prior defects define this shape: the executor's cached model let
     *    triage clobber a category a person assigned mid-loop (psa-p4zdp),
     *    and deciding from ticket_category_change_logs left a window where a
     *    human's committed UPDATE was visible while their Staff log row —
     *    inserted AFTER the update by TicketObserver::updated() — was not,
     *    so the stale log misattributed ownership (psa-trjwf re-review). The
     *    log is audit-only here.
     *  - A node a human chose is never overwritten OR cleared, and a node a
     *    human CLEARED stays cleared — triage only writes when the row says
     *    triage owns the current value, or when category_id is unset and no
     *    human owns the clear. Unknown ownership (null stamp with a value
     *    present: pre-feature data, direct DB writes) counts as human-owned.
     *  - An unmapped pair degrades to a GAP (null), including clearing a
     *    stale triage-owned node after a reclassification — never a guess.
     *  - Every write goes through runAsTriage() so the observer stamps the
     *    column and the change log with Triage attribution (that log is
     *    Phase 1's refinement data), and lands on the freshly-read model so
     *    the log's previous_* snapshots reflect what the database actually
     *    held.
     *
     * @return array{status: string, category_id: int|null, path: string|null, note: string|null}
     */
    private function applyTaxonomyMapping(string $category, ?string $subcategory): array
    {
        return DB::transaction(function () use ($category, $subcategory): array {
            // Fresh row under FOR UPDATE — $this->ticket may predate concurrent
            // writes. withTrashed() mirrors Eloquent's own save path (which
            // bypasses global scopes), so a soft-deleted ticket behaves the
            // same as every other category_id write against it.
            $fresh = Ticket::withTrashed()->whereKey($this->ticket->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return [
                    'status' => 'gap',
                    'category_id' => null,
                    'path' => null,
                    'note' => 'The ticket row no longer exists; no taxonomy category was written.',
                ];
            }

            $currentId = $fresh->category_id;
            $ownerSource = $fresh->category_source;

            // Human-owned: a present node triage did not write (Staff, System,
            // or an unstamped pre-feature value), or a null a person explicitly
            // chose (their clear stamped the row Staff). Read from the locked
            // row only — never from the change log, whose latest row can trail
            // the update it describes.
            $humanOwned = ($currentId !== null && $ownerSource !== TicketCategoryChangeSource::Triage)
                || ($currentId === null && $ownerSource === TicketCategoryChangeSource::Staff);

            if ($humanOwned) {
                $current = $currentId !== null ? TicketCategory::find($currentId) : null;

                return [
                    'status' => 'kept_existing',
                    'category_id' => $currentId,
                    'path' => $current?->pathString(),
                    'note' => $currentId !== null
                        ? 'A person already assigned this ticket\'s taxonomy category; triage does not overwrite it.'
                        : 'A person cleared this ticket\'s taxonomy category; triage does not reassign it.',
                ];
            }

            $node = TaxonomyNodeMapper::resolve($category, $subcategory);

            if ($node === null) {
                if ($currentId !== null) {
                    TicketCategoryChangeLog::runAsTriage(fn () => $fresh->update(['category_id' => null]));

                    return [
                        'status' => 'gap',
                        'category_id' => null,
                        'path' => null,
                        'note' => 'This classification pair has no confident taxonomy mapping; the previous triage-assigned category was cleared.',
                    ];
                }

                return [
                    'status' => 'gap',
                    'category_id' => null,
                    'path' => null,
                    'note' => 'This classification pair has no confident taxonomy mapping yet — the ticket stays a visible coverage gap.',
                ];
            }

            if ($node->id !== $currentId) {
                TicketCategoryChangeLog::runAsTriage(fn () => $fresh->update(['category_id' => $node->id]));
            }

            return [
                'status' => 'mapped',
                'category_id' => $node->id,
                'path' => $node->pathString(),
                'note' => null,
            ];
        });
    }

    private function setTicketKeywords(array $input): array
    {
        $keywords = $input['keywords'] ?? null;

        if (! is_array($keywords) || empty($keywords)) {
            return ['error' => 'keywords must be a non-empty array'];
        }

        // Normalize: lowercase, strip punctuation, drop very short tokens, dedupe.
        $normalized = [];
        foreach ($keywords as $kw) {
            if (! is_string($kw)) {
                continue;
            }
            $clean = mb_strtolower(trim($kw), 'UTF-8');
            $clean = preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $clean) ?? '';
            $clean = trim((string) $clean);
            if ($clean === '' || mb_strlen($clean) < 2) {
                continue;
            }
            $normalized[$clean] = true;
        }

        if (empty($normalized)) {
            return ['error' => 'no usable keywords after normalization'];
        }

        $stored = implode(' ', array_keys($normalized));
        $this->ticket->update(['search_keywords' => $stored]);

        Log::info('[Triage] AI set ticket keywords', [
            'ticket_id' => $this->ticket->id,
            'keyword_count' => count($normalized),
        ]);

        return ['success' => true, 'keywords' => array_keys($normalized)];
    }

    // ── NinjaRMM Tools ──

    private function ninjaSearchDevices(array $input): array
    {
        $query = $input['query'] ?? '';
        $limit = min($input['limit'] ?? 20, 50);

        if (! $query) {
            return ['error' => 'query is required'];
        }

        // CLIENT-SCOPED: check org mapping before making API call
        $ninjaOrgId = $this->ticket->client?->ninja_org_id;
        if (! $ninjaOrgId) {
            return ['error' => 'Client has no NinjaRMM organization mapping'];
        }

        try {
            $results = app(NinjaClient::class)->get('/v2/devices/search', ['q' => $query, 'limit' => $limit]);
        } catch (\Throwable $e) {
            Log::warning('[Triage] NinjaRMM search failed', ['error' => $e->getMessage()]);

            return ['error' => 'NinjaRMM search failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Search endpoint wraps results: {"query": "...", "devices": [...]}
        $deviceList = $results['devices'] ?? $results;

        $devices = array_values(array_filter($deviceList, function ($device) use ($ninjaOrgId) {
            return ($device['organizationId'] ?? null) == $ninjaOrgId;
        }));

        return array_slice($devices, 0, $limit);
    }

    private function ninjaGetDevice(array $input): array
    {
        $deviceId = $input['device_id'] ?? null;
        if (! $deviceId) {
            return ['error' => 'device_id is required'];
        }

        // CLIENT-SCOPED: verify device belongs to this client's org
        if (! $this->verifyNinjaDeviceScope($deviceId)) {
            return ['error' => 'Device not found or belongs to a different client'];
        }

        try {
            return app(NinjaClient::class)->getDeviceDetail($deviceId);
        } catch (\Throwable $e) {
            Log::warning('[Triage] NinjaRMM device query failed', ['device_id' => $deviceId, 'error' => $e->getMessage()]);

            return ['error' => 'NinjaRMM query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function ninjaGetDeviceSub(array $input, string $subResource): array
    {
        $deviceId = $input['device_id'] ?? null;
        if (! $deviceId) {
            return ['error' => 'device_id is required'];
        }

        if (! $this->verifyNinjaDeviceScope($deviceId)) {
            return ['error' => 'Device not found or belongs to a different client'];
        }

        try {
            return app(NinjaClient::class)->get("/v2/device/{$deviceId}/{$subResource}");
        } catch (\Throwable $e) {
            Log::warning('[Triage] NinjaRMM sub-resource query failed', ['device_id' => $deviceId, 'sub' => $subResource, 'error' => $e->getMessage()]);

            return ['error' => 'NinjaRMM query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * Verify a NinjaRMM device belongs to this client by checking local assets.
     */
    private function verifyNinjaDeviceScope(int $ninjaDeviceId): bool
    {
        return \App\Models\Asset::where('client_id', $this->clientId)
            ->where('ninja_id', $ninjaDeviceId)
            ->exists();
    }

    // ── Level RMM Tools ──

    private function levelGetDevice(array $input): array
    {
        $deviceId = $input['device_id'] ?? null;
        if (! $deviceId) {
            return ['error' => 'device_id is required'];
        }

        // CLIENT-SCOPED: verify device belongs to this client
        if (! $this->verifyLevelDeviceScope($deviceId)) {
            return ['error' => 'Device not found or belongs to a different client'];
        }

        try {
            return app(LevelClient::class)->getDevice($deviceId);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Level device query failed', ['device_id' => $deviceId, 'error' => $e->getMessage()]);

            return ['error' => 'Level query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * Verify a Level RMM device belongs to this client by checking local assets.
     */
    private function verifyLevelDeviceScope(string $levelDeviceId): bool
    {
        return \App\Models\Asset::where('client_id', $this->clientId)
            ->where('level_id', $levelDeviceId)
            ->exists();
    }

    // ── Mesh Tools ──

    private function meshSearchLogs(array $input): array
    {
        $size = min($input['size'] ?? 20, 50);

        $client = $this->ticket->client;
        $meshCustomerId = $client?->mesh_customer_id;

        if (! $meshCustomerId) {
            return ['error' => 'Client has no Mesh customer mapping'];
        }

        // Date range is required — Mesh returns empty without it
        $end = gmdate('Y-m-d\TH:i:s');
        $start = gmdate('Y-m-d\TH:i:s', strtotime('-7 days'));

        $params = [
            '_from' => 0,
            '_size' => $size,
            'start' => $start,
            'end' => $end,
        ];

        // Add optional filters
        foreach (['from', 'to', 'subject', 'status'] as $field) {
            if (! empty($input[$field])) {
                $params[$field] = $input[$field];
            }
        }

        try {
            $result = app(MeshClient::class)->get('api/emaillogs/', $params);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Mesh log search failed', ['error' => $e->getMessage()]);

            return ['error' => 'Mesh query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Filter to this client's customer ID (API returns all customers)
        $logs = $result['list'] ?? [];
        $logs = array_values(array_filter($logs, function ($entry) use ($meshCustomerId) {
            $ids = $entry['Customer-ID'] ?? [$entry['Customer Id'] ?? null];

            return in_array($meshCustomerId, (array) $ids);
        }));

        return [
            'total' => count($logs),
            'emails' => array_slice($logs, 0, $size),
        ];
    }

    private function meshGetEvents(array $input): array
    {
        $queueId = $input['queue_id'] ?? null;
        if (! $queueId) {
            return ['error' => 'queue_id is required'];
        }

        try {
            return app(MeshClient::class)->get('api/emaillogs/events', [
                'queue_id' => $queueId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Mesh events query failed', ['queue_id' => $queueId, 'error' => $e->getMessage()]);

            return ['error' => 'Mesh query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    // ── CIPP Tools ──
    //
    // The CIPP tool bodies live in the shared App\Services\Cipp\HandlesCippTools
    // trait. Triage sources the tenant filter from the ticket's client and tags its
    // CIPP failure logs [Triage]; it uses the trait's default (no-op) MCP relay.

    protected function cippTenantDomain(): ?string
    {
        return $this->ticket->client?->cipp_tenant_domain;
    }

    protected function cippLogPrefix(): string
    {
        return '[Triage]';
    }

    // ── Control D Tools ──

    private function controldGetDevices(): array
    {
        $orgId = $this->ticket->client?->controld_org_id;

        if (! $orgId) {
            return ['error' => 'Client has no Control D organization mapping'];
        }

        try {
            $client = new ControlDClient(['api_key' => ControlDConfig::get('api_key')]);
            $devices = $client->getDevices($orgId);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Control D device query failed', ['error' => $e->getMessage()]);

            return ['error' => 'Control D query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return array_map(fn ($d) => [
            'name' => $d['name'] ?? null,
            'status' => match ($d['status'] ?? null) {
                0 => 'pending',
                1 => 'active',
                2 => 'soft_disabled',
                3 => 'hard_disabled',
                default => 'unknown',
            },
            'profile' => $d['profile']['name'] ?? null,
            'agent_connected' => ($d['ctrld']['status'] ?? 0) === 1,
            'agent_version' => $d['ctrld']['version'] ?? null,
            'agent_last_seen' => isset($d['ctrld']['last_fetch'])
                ? Carbon::createFromTimestamp($d['ctrld']['last_fetch'])->toDateTimeString()
                : null,
        ], $devices);
    }

    private function controldDnsQueries(array $input): array
    {
        if (! ControlDConfig::isAnalyticsConfigured()) {
            return ['error' => 'Control D analytics is not configured'];
        }

        $orgId = $this->ticket->client?->controld_org_id;

        if (! $orgId) {
            return ['error' => 'Client has no Control D organization mapping'];
        }

        $deviceName = $input['device_name'] ?? null;

        if (! $deviceName) {
            return ['error' => 'device_name is required'];
        }

        // Resolve device name to endpointId via local asset record (client-scoped)
        $asset = Asset::where('client_id', $this->ticket->client_id)
            ->whereNotNull('controld_device_id')
            ->whereRaw('LOWER(hostname) = ?', [mb_strtolower($deviceName)])
            ->first();

        $endpointId = $asset?->controld_device_id;

        $hours = min(max((int) ($input['hours'] ?? 24), 1), 168);
        $endTime = now();
        $startTime = $endTime->copy()->subHours($hours);

        try {
            $analyticsClient = new ControlDAnalyticsClient(
                ControlDConfig::get('api_key'),
                ControlDConfig::get('stats_endpoint'),
            );

            $queries = $analyticsClient->getActivityLog(
                $orgId,
                $startTime->toIso8601ZuluString(),
                $endTime->toIso8601ZuluString(),
                $endpointId,
            );
        } catch (\Throwable $e) {
            Log::warning('[Triage] Control D analytics query failed', ['error' => $e->getMessage()]);

            return ['error' => 'Control D analytics query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return array_map(fn ($q) => [
            'domain' => $q['question'] ?? null,
            'action' => match ($q['action'] ?? null) {
                1 => 'allowed',
                0 => 'blocked',
                -1 => 'nxdomain',
                default => 'unknown',
            },
            'trigger' => $q['trigger'] ?? null,
            'timestamp' => $q['timestamp'] ?? null,
            'type' => $q['rrType'] ?? null,
        ], array_slice($queries, 0, 100));
    }

    // ── Zorus Tools ──

    private function zorusGetEndpoints(): array
    {
        $customerId = $this->ticket->client?->zorus_customer_id;

        if (! $customerId) {
            return ['error' => 'Client has no Zorus customer mapping'];
        }

        // Read from local assets table (not live API call)
        $assets = Asset::where('client_id', $this->clientId)
            ->whereNotNull('zorus_endpoint_id')
            ->orderBy('hostname')
            ->limit(100)
            ->get([
                'hostname', 'name', 'zorus_group_name', 'zorus_filtering_enabled',
                'zorus_cybersight_enabled', 'zorus_agent_version', 'zorus_agent_state',
                'zorus_last_seen_at',
            ]);

        return $assets->map(fn (Asset $a) => [
            'name' => $a->hostname ?? $a->name,
            'group' => $a->zorus_group_name,
            'filtering_enabled' => $a->zorus_filtering_enabled,
            'cybersight_enabled' => $a->zorus_cybersight_enabled,
            'agent_version' => $a->zorus_agent_version,
            'agent_state' => $a->zorus_agent_state,
            'last_seen' => $a->zorus_last_seen_at?->toDateTimeString(),
        ])->toArray();
    }

    // ── Tactical RMM Tools ──

    /**
     * Resolve a hostname to a Tactical agent, enforcing client scoping.
     */
    private function resolveTacticalAgent(string $hostname): ?array
    {
        $tacticalAsset = TacticalAsset::with('asset')
            ->whereHas('asset', fn (Builder $query) => $query->where('client_id', $this->clientId))
            ->whereRaw('LOWER(hostname) = ?', [mb_strtolower($hostname)])
            ->first();

        if (! $tacticalAsset || ! $tacticalAsset->asset) {
            return null;
        }

        return ['agent_id' => $tacticalAsset->agent_id, 'tactical_asset' => $tacticalAsset];
    }

    private function tacticalGetDevice(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical device query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Format uptime from boot_time (shared mapper — amendment E)
        $uptime = TacticalFieldMap::uptimeFromBootTime($agent['boot_time'] ?? null);

        // getAgent `checks` is a SUMMARY DICT ({total, passing, failing, …}) —
        // read failing/total off it directly (NOT the getAgentChecks list helper).
        $checksSummary = null;
        $checks = $agent['checks'] ?? null;
        if (is_array($checks) && isset($checks['total'])) {
            $failing = (int) ($checks['failing'] ?? 0);
            $total = (int) $checks['total'];
            $checksSummary = "{$failing} failing / {$total} total";
        }

        return [
            'hostname' => $agent['hostname'] ?? $hostname,
            'status' => $agent['status'] ?? null,
            'os' => $agent['operating_system'] ?? null,
            'cpu' => $agent['cpu_model'] ?? null,
            'ram_gb' => TacticalFieldMap::ramGb($agent['total_ram'] ?? null),
            'make_model' => $agent['make_model'] ?? null,
            'public_ip' => $agent['public_ip'] ?? null,
            'local_ips' => $agent['local_ips'] ?? null,
            // Tactical's "None" (and empty) means no user is logged in — surface
            // null to the AI, not the literal sentinel string.
            'logged_in_user' => in_array($agent['logged_in_username'] ?? null, [null, '', 'None'], true)
                ? null
                : $agent['logged_in_username'],
            'needs_reboot' => $agent['needs_reboot'] ?? false,
            'uptime' => $uptime,
            'checks_summary' => $checksSummary,
        ];
    }

    private function tacticalGetDeviceChecks(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $checks = app(TacticalClient::class)->getAgentChecks($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical checks query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return array_map(fn ($c) => [
            'name' => $c['name'] ?? $c['readable_desc'] ?? 'Unknown',
            'status' => $c['check_result']['status'] ?? $c['status'] ?? 'unknown',
            'retcode' => $c['check_result']['retcode'] ?? null,
            'stdout' => mb_substr($c['check_result']['stdout'] ?? '', 0, 500),
        ], array_slice($checks, 0, 50));
    }

    private function tacticalGetDeviceNetwork(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical network query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Shared IP-enabled-adapter mapping (the storage/network asset panels reuse
        // the same TacticalFieldMap mapper — one shape, two consumers).
        return TacticalFieldMap::mapNetwork($agent);
    }

    private function tacticalGetDeviceSoftware(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $payload = app(TacticalClient::class)->getSoftware($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical software query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // The inventory rows arrive wrapped as {id, agent, software: [...]}.
        $software = TacticalFieldMap::softwareRows($payload);

        // Sort alphabetically, limit to 50
        usort($software, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        return array_map(fn ($s) => [
            'name' => $s['name'] ?? 'Unknown',
            'version' => $s['version'] ?? null,
            'publisher' => $s['publisher'] ?? null,
        ], array_slice($software, 0, 50));
    }

    private function tacticalGetDeviceServices(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical services query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $services = collect($agent['services'] ?? []);
        $filter = $input['filter'] ?? null;

        if ($filter) {
            $filterLower = strtolower($filter);
            $services = $services->filter(function ($s) use ($filterLower) {
                if ($filterLower === 'running') {
                    return strtolower($s['status'] ?? '') === 'running';
                }
                if ($filterLower === 'stopped') {
                    return strtolower($s['status'] ?? '') === 'stopped';
                }

                // Search by name
                return str_contains(strtolower($s['display_name'] ?? $s['name'] ?? ''), $filterLower)
                    || str_contains(strtolower($s['name'] ?? ''), $filterLower);
            });
        }

        return $services->take(50)->map(fn ($s) => [
            'name' => $s['name'] ?? null,
            'display_name' => $s['display_name'] ?? null,
            'status' => $s['status'] ?? null,
            'start_type' => $s['start_type'] ?? null,
        ])->values()->toArray();
    }

    private function tacticalGetDeviceDisks(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical disks query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Logical volumes — getAgent `disks` total/used/free are FORMATTED STRINGS
        // ("X.Y GB"/TB/MB) and percent is an INT (source v1.5.0 + live VM 105), so
        // parse the strings to GB; do NOT byte-divide.
        $disks = $agent['disks'] ?? [];
        $volumes = TacticalFieldMap::mapDiskVolumes(is_array($disks) ? $disks : [], includeFilesystemType: true);

        // Physical disks
        $physicalDisks = collect($agent['physical_disks'] ?? [])->take(10)->map(fn ($d) => [
            'model' => $d['caption'] ?? $d['model'] ?? null,
            'size_gb' => isset($d['size']) ? round($d['size'] / 1073741824, 1) : null,
            'interface' => $d['interface_type'] ?? null,
            'status' => $d['status'] ?? null,
        ])->toArray();

        // WMI disk detail if available
        $wmiDisks = collect($agent['wmi_detail']['disk'] ?? [])->take(10)->map(fn ($d) => [
            'caption' => $d['Caption'] ?? null,
            'size_gb' => isset($d['Size']) ? round($d['Size'] / 1073741824, 1) : null,
            'free_gb' => isset($d['FreeSpace']) ? round($d['FreeSpace'] / 1073741824, 1) : null,
        ])->toArray();

        return [
            'volumes' => $volumes,
            'physical_disks' => $physicalDisks,
            'wmi_disk' => $wmiDisks,
        ];
    }

    // ── Comet Backup Tools ──

    private function resolveCometAsset(string $hostname): ?\App\Models\Asset
    {
        return \App\Models\Asset::where('client_id', $this->clientId)
            ->whereNotNull('comet_device_id')
            ->whereRaw('LOWER(hostname) = ?', [strtolower($hostname)])
            ->first();
    }

    private function executeCometGetBackupStatus(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $asset = $this->resolveCometAsset($hostname);
        if (! $asset) {
            return ['error' => "No Comet-linked asset found for hostname '{$hostname}' in this client"];
        }

        $client = new CometClient;
        $jobService = new CometJobService($client);
        $jobData = $jobService->getRecentJobs($asset, 30);

        $daysSinceBackup = null;
        if ($jobData['last_success']) {
            // Sign-safe (psa-lqlu): $past->diffInDays(now()) is positive; the now()-first form
            // is NEGATIVE in Carbon 3 and reported a negative "days since backup" to the agent.
            $daysSinceBackup = (int) \Carbon\Carbon::parse($jobData['last_success']['started'])->diffInDays(now());
        }

        return [
            'hostname' => $asset->hostname,
            'comet_username' => $asset->comet_username,
            'cloud_storage_bytes' => $asset->backup_cloud_bytes,
            'local_storage_bytes' => $asset->backup_local_bytes,
            'last_synced' => $asset->backup_synced_at?->toDateTimeString(),
            'last_success' => $jobData['last_success']['started'] ?? null,
            'last_failure' => $jobData['last_failure']['started'] ?? null,
            'days_since_last_success' => $daysSinceBackup,
        ];
    }

    private function executeCometGetBackupJobs(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $asset = $this->resolveCometAsset($hostname);
        if (! $asset) {
            return ['error' => "No Comet-linked asset found for hostname '{$hostname}' in this client"];
        }

        $days = $input['days'] ?? 7;
        $client = new CometClient;
        $jobService = new CometJobService($client);
        $jobData = $jobService->getRecentJobs($asset, $days);

        return [
            'hostname' => $asset->hostname,
            'job_count' => count($jobData['jobs']),
            'jobs' => array_slice($jobData['jobs'], 0, 20),
        ];
    }

    private function tacticalRunDiagnostic(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        $diagnostic = $input['diagnostic'] ?? null;

        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }
        if (! $diagnostic) {
            return ['error' => 'diagnostic is required'];
        }

        $diagnosticScripts = [
            'event_log_errors' => 201,
            'top_processes' => 202,
            'network_test' => 203,
            'windows_update_history' => 204,
            'printer_status' => 205,
            'startup_programs' => 206,
            'uptime_detail' => 207,
            'dns_config' => 208,
            'firewall_status' => 209,
            'disk_health' => 210,
        ];

        if (! isset($diagnosticScripts[$diagnostic])) {
            return ['error' => "Invalid diagnostic '{$diagnostic}'. Available: ".implode(', ', array_keys($diagnosticScripts))];
        }

        // Client-scoping is enforced here: resolveTacticalAgent() rejects a
        // hostname whose linked asset belongs to a different client.
        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        /** @var \App\Models\TacticalAsset $tacticalAsset */
        $tacticalAsset = $resolved['tactical_asset'];
        $asset = $tacticalAsset->asset;

        // The bus resolves the agent FROM a PSA Asset. A Tactical agent with no
        // linked PSA asset can't be dispatched through the bus; surface a clear
        // error rather than silently bypassing the audited pipeline.
        if (! $asset) {
            return ['error' => "Device '{$hostname}' is not linked to a PSA asset"];
        }

        // Dispatch through the audited bus as the AI actor (M1: no User; attribute
        // via actor_label='ai-triage', actor_id null). The bus catches/classifies
        // transport vs HTTP errors and audits the run.
        $result = app(\App\Services\Tactical\TacticalActionService::class)->dispatch(
            new \App\Services\Tactical\Actions\RunScriptAction,
            $asset,
            null,
            [
                'tactical_script_id' => $diagnosticScripts[$diagnostic],
                'args' => '',
                'timeout' => 30,
            ],
            actorLabel: 'ai-triage',
            ticketId: $this->ticket->id,
        );

        if (! $result->isOk()) {
            Log::warning('[Triage] Tactical diagnostic did not succeed', [
                'hostname' => $hostname,
                'diagnostic' => $diagnostic,
                'status' => $result->status,
            ]);

            return ['error' => 'Tactical diagnostic failed: '.mb_substr($result->message ?? $result->status, 0, 200)];
        }

        return [
            'diagnostic' => $diagnostic,
            'retcode' => $result->retcode,
            'stdout' => mb_substr($result->stdout ?? '', 0, 3000),
            // Carry stderr through (the bus result captures it): diagnostics like
            // disk_health/network_test write signal to stderr the AI reasons over.
            'stderr' => mb_substr($result->stderr ?? '', 0, 1000),
        ];
    }
}
