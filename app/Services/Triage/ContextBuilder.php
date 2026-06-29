<?php

namespace App\Services\Triage;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Enums\WikiPageKind;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\WikiPage;
use App\Services\AttachmentService;
use App\Services\Comet\CometClient;
use App\Services\Comet\CometJobService;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Support\CippConfig;
use App\Support\CometConfig;
use App\Support\ControlDConfig;
use App\Support\MeshConfig;
use App\Support\TacticalConfig;
use App\Support\WikiConfig;
use App\Support\ZorusConfig;
use Illuminate\Support\Facades\Log;

/**
 * Builds formatted context strings for AI prompts from local DB data.
 * Enforces truncation limits to keep token usage predictable.
 */
class ContextBuilder
{
    private const MAX_TICKET_BODY = 5_000;

    private const MAX_NOTES = 10;

    private const MAX_NOTE_LENGTH = 2_000;

    private const MAX_CONTRACT_LENGTH = 1_000;

    private const MAX_ASSET_LENGTH = 700;

    private const MAX_SITE_NOTES_LENGTH = 3_000;

    private const MIN_OVERVIEW_CHARS = 200; // below this, keep human site_notes (don't displace curated text)

    private const MAX_DOC_SUMMARY_LENGTH = 2_000;

    private const MAX_DOC_SUMMARIES = 3;

    private const MAX_AI_IMAGES = 10;

    /**
     * Build full context for a ticket, suitable for AI system prompt injection.
     *
     * @param  bool  $skipNotes  Skip the notes section (useful when a separate conversation context is provided)
     * @param  bool  $includeClientSituation  Opt in to the fenced "Client Situation" digest (agent only). Appended
     *                                        AFTER $skipNotes so the positional LessonCapture caller never shifts;
     *                                        the situationContextEnabled() gate is re-checked below, so the section
     *                                        stays byte-absent unless BOTH the caller opts in AND the flag is on.
     */
    public static function buildForTicket(Ticket $ticket, bool $skipNotes = false, bool $includeClientSituation = false): string
    {
        $eagerLoads = [
            'client',
            'contact',
            'assets',
            'contract',
            'assignee',
        ];

        if (! $skipNotes) {
            $eagerLoads['notes'] = fn ($q) => $q->orderByDesc('noted_at')->limit(self::MAX_NOTES);
            $eagerLoads[] = 'notes.author';
            $eagerLoads[] = 'notes.email';
        }

        $ticket->loadMissing($eagerLoads);

        $sections = [];

        // Ticket info
        $sections[] = self::buildTicketSection($ticket);

        // Client info
        if ($ticket->client) {
            $sections[] = self::buildClientSection($ticket);
        }

        // Integration availability — tells AI which vendor tools are usable for this client
        $integrations = self::buildIntegrationAvailabilitySection($ticket);
        if ($integrations) {
            $sections[] = $integrations;
        }

        // Client site notes (environment documentation, AI-visible)
        $siteNotes = self::buildSiteNotesSection($ticket);
        if ($siteNotes) {
            $sections[] = $siteNotes;
        }

        // Contact info
        if ($ticket->contact) {
            $sections[] = self::buildContactSection($ticket);
        }

        // Contract info
        $sections[] = self::buildContractSection($ticket);

        // Asset info
        if ($ticket->assets->isNotEmpty()) {
            $sections[] = self::buildAssetSection($ticket);
        }

        // Recent notes (unless caller provides separate conversation context)
        if (! $skipNotes && $ticket->notes->isNotEmpty()) {
            $sections[] = self::buildNotesSection($ticket);
        }

        // Phone call transcription (read directly from phone_calls table)
        $phoneCallSection = self::buildPhoneCallSection($ticket);
        if ($phoneCallSection) {
            $sections[] = $phoneCallSection;
        }

        // Operator corrections — TRUSTED guidance injected OUTSIDE any untrusted fence
        $operatorDirective = self::recentCorrectionsSection($ticket);
        if ($operatorDirective) {
            $sections[] = $operatorDirective;
        }

        // Client Situation — opt-in (agent only), fenced UNTRUSTED reference data. The gate
        // is re-checked HERE so the section is byte-absent when the flag is off OR the caller
        // didn't opt in; build() returns '' when empty → array_filter drops it.
        if ($includeClientSituation && \App\Support\AgentConfig::situationContextEnabled() && $ticket->client_id) {
            $sections[] = app(\App\Services\Triage\ClientSituationContextBuilder::class)->build($ticket);
        }

        $context = implode("\n\n", array_filter($sections));

        Log::debug('[Triage] Context built', [
            'ticket_id' => $ticket->id,
            'context_length' => strlen($context),
            'skip_notes' => $skipNotes,
        ]);

        return $context;
    }

    /**
     * Build conversation context for reply drafting: public notes in chronological order
     * with sender labels, suitable for AI to understand the conversation flow.
     */
    public static function buildConversationContext(Ticket $ticket, int $limit = 20, bool $publicOnly = true): string
    {
        $query = $ticket->notes()
            ->with(['author', 'email'])
            ->orderBy('noted_at', 'asc')
            ->limit($limit);

        if ($publicOnly) {
            $query->where('is_private', false);
        }

        $notes = $query->get()->filter(
            fn ($note) => ! $note->note_type->isSystemGenerated()
                || $note->note_type === NoteType::AiTriage // Include triage research for richer drafts
        );

        if ($notes->isEmpty()) {
            return "## Conversation\nNo conversation history yet.";
        }

        $lines = ['## Conversation (oldest first)'];
        $totalChars = 0;
        $maxTotalChars = 30_000;
        $maxNoteChars = 3_000;

        foreach ($notes as $note) {
            $sender = match ($note->who_type) {
                WhoType::EndUser => 'CLIENT',
                default => 'TECHNICIAN',
            };

            $author = $note->author?->name ?? $note->author_name ?? 'Unknown';
            $date = $note->noted_at?->toDateTimeString() ?? $note->created_at->toDateTimeString();
            $type = $note->note_type?->label() ?? 'Note';

            $body = strip_tags($note->body ?? '');
            if (strlen($body) > $maxNoteChars) {
                $body = substr($body, 0, $maxNoteChars).' [TRUNCATED]';
            }

            $visibility = $note->is_private ? 'PRIVATE' : 'PUBLIC';
            $entry = "### [{$sender}] {$type} by {$author} ({$date}) [{$visibility}]\n";
            $entry .= self::formatEmailRecipients($note);
            if ($body) {
                $entry .= $body."\n";
            }

            // Enforce total character budget (truncate oldest notes first by stopping when over)
            if ($totalChars + strlen($entry) > $maxTotalChars) {
                $lines[] = "\n[Earlier notes truncated for length]";
                break;
            }

            $totalChars += strlen($entry);
            $lines[] = $entry;
        }

        // Append AI assistant conversations for this ticket
        $conversations = \App\Models\AssistantConversation::where('context_type', 'ticket')
            ->where('context_id', $ticket->id)
            ->with(['user:id,name', 'messages'])
            ->orderBy('created_at')
            ->get();

        foreach ($conversations as $conv) {
            if ($conv->messages->isEmpty()) {
                continue;
            }

            $techName = $conv->user?->name ?? 'Unknown';
            $date = $conv->created_at->toDateTimeString();
            $lines[] = "\n### [TECHNICIAN] AI Conversation by {$techName} ({$date}) [PRIVATE]";

            foreach ($conv->messages as $msg) {
                $role = $msg->role === 'user' ? $techName : 'AI Assistant';
                $body = strip_tags($msg->content ?? '');
                if (strlen($body) > $maxNoteChars) {
                    $body = substr($body, 0, $maxNoteChars).' [TRUNCATED]';
                }

                if ($totalChars + strlen($body) > $maxTotalChars) {
                    $lines[] = '[AI conversation truncated for length]';
                    break 2;
                }

                $totalChars += strlen($body);
                $lines[] = "**{$role}:** {$body}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build multimodal content array for AI (text + image blocks interleaved).
     * Returns an array of Anthropic content blocks.
     */
    public static function buildMultimodalContent(Ticket $ticket): array
    {
        $ticket->loadMissing([
            'attachments',
            'notes' => fn ($q) => $q->orderBy('noted_at', 'asc')->limit(self::MAX_NOTES),
            'notes.author',
            'notes.email',
            'notes.attachments',
        ]);

        $blocks = [];
        $imageCount = 0;
        $attachmentService = app(AttachmentService::class);

        // Ticket description + client context as text
        $descText = self::buildTicketSection($ticket);
        if ($ticket->client) {
            $descText .= "\n\n".self::buildClientSection($ticket);
        }
        $blocks[] = ['type' => 'text', 'text' => $descText];

        // Ticket description images
        foreach ($ticket->attachments->filter(fn ($a) => $a->isImage()) as $att) {
            if ($imageCount >= self::MAX_AI_IMAGES) {
                break;
            }
            $base64 = $attachmentService->resizeImageForAi($att);
            if ($base64) {
                $blocks[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $att->mime_type === 'image/gif' ? 'image/png' : $att->mime_type,
                        'data' => $base64,
                    ],
                ];
                $imageCount++;
            }
        }

        // Notes with interleaved images (chronological order)
        foreach ($ticket->notes as $note) {
            $author = $note->author?->name ?? $note->author_name ?? 'System';
            $date = $note->noted_at?->toDateTimeString() ?? $note->created_at->toDateTimeString();
            $type = $note->note_type?->label() ?? 'Note';
            $sender = $note->who_type === WhoType::EndUser ? 'CLIENT' : 'TECHNICIAN';

            $body = strip_tags($note->body ?? '');
            if (strlen($body) > self::MAX_NOTE_LENGTH) {
                $body = substr($body, 0, self::MAX_NOTE_LENGTH).' [TRUNCATED]';
            }

            $recipients = self::formatEmailRecipients($note);
            $noteText = "### [{$sender}] {$type} by {$author} ({$date})\n{$recipients}{$body}";
            $blocks[] = ['type' => 'text', 'text' => $noteText];

            // Note images
            if ($imageCount < self::MAX_AI_IMAGES) {
                foreach ($note->attachments->filter(fn ($a) => $a->isImage()) as $att) {
                    if ($imageCount >= self::MAX_AI_IMAGES) {
                        break;
                    }
                    $base64 = $attachmentService->resizeImageForAi($att);
                    if ($base64) {
                        $blocks[] = [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $att->mime_type === 'image/gif' ? 'image/png' : $att->mime_type,
                                'data' => $base64,
                            ],
                        ];
                        $imageCount++;
                    }
                }
            }
        }

        return $blocks;
    }

    private static function buildTicketSection(Ticket $ticket): string
    {
        $body = $ticket->description ?? '';
        if (strlen($body) > self::MAX_TICKET_BODY) {
            $body = substr($body, 0, self::MAX_TICKET_BODY)."\n[TRUNCATED]";
        }

        // Strip HTML for cleaner AI input
        $body = strip_tags($body);

        $lines = [
            '## Ticket',
            'ID: '.$ticket->display_id,
            'Subject: '.$ticket->subject,
            'Type: '.$ticket->type->value,
            'Status: '.$ticket->status->value,
            'Priority: '.$ticket->priority->value,
            'Source: '.$ticket->source->value,
        ];

        if ($ticket->category) {
            $lines[] = "Category: {$ticket->category}";
        }
        if ($ticket->assignee) {
            $lines[] = "Assigned to: {$ticket->assignee->name}";
        }
        if ($ticket->opened_at) {
            $lines[] = "Opened: {$ticket->opened_at->toDateTimeString()}";
        }
        if ($body) {
            $lines[] = "\nDescription:\n{$body}";
        }

        return implode("\n", $lines);
    }

    private static function buildClientSection(Ticket $ticket): string
    {
        $client = $ticket->client;
        $lines = [
            '## Client',
            "Name: {$client->name}",
        ];

        if ($client->email) {
            $lines[] = "Email: {$client->email}";
        }
        if ($client->phone) {
            $lines[] = "Phone: {$client->phone}";
        }
        if (! empty($client->notes) && trim($client->notes) !== '') {
            $lines[] = "Notes:\n".trim($client->notes);
        }

        $sec = $client->securitySnapshot();
        if ($sec) {
            $lines[] = sprintf(
                'Mail security: %d transport rule(s), Safe Links %s, Safe Attachments %s',
                $sec['transport_rule_count'],
                $sec['safe_links_active'] ? 'active' : 'NOT configured',
                $sec['safe_attachments_active'] ? 'active' : 'NOT configured',
            );
            $lines[] = sprintf(
                'Conditional Access: %d policies total, %d enabled',
                $sec['ca_policy_total'],
                $sec['ca_policy_enabled'],
            );
            $lines[] = sprintf(
                'Intune compliance policies: %d',
                $sec['compliance_policy_count'],
            );
        }

        return implode("\n", $lines);
    }

    private static function buildSiteNotesSection(Ticket $ticket): ?string
    {
        return $ticket->client ? self::clientEnvironmentSection($ticket->client) : null;
    }

    /**
     * Spec §4.6 always-injected client context. Prefers the composed wiki overview
     * (wiki on, overview composed, and substantial enough); otherwise falls back to
     * clients.site_notes. Returns null only when both are empty.
     */
    public static function clientEnvironmentSection(Client $client): ?string
    {
        $overview = WikiConfig::isEnabled() ? self::composedOverviewBody($client) : null;
        if ($overview !== null) {
            return "## Client Environment Overview\nAI-maintained from this client's wiki:\n".self::clip($overview);
        }

        $notes = $client->site_notes;
        if (! $notes || trim($notes) === '') {
            return null;
        }

        // Use raw markdown (not HTML) — preserves structural formatting for the AI
        return "## Client Site Notes\nEnvironment documentation maintained by technicians:\n".self::clip($notes);
    }

    /**
     * Whether this client's wiki overview has been AI-composed at least once
     * ("composed" = meta.composed_at set). The single source of truth for the
     * composed predicate, shared by composedOverviewBody() and the staff
     * site-notes card so the two can't drift. Note: this is the bare "composed?"
     * check — the substance floor that gates *injection* lives in
     * composedOverviewBody(), so the staff pointer shows whenever an overview is
     * composed, floor or not.
     */
    public static function hasComposedOverview(Client $client): bool
    {
        return self::isComposed(self::overviewPage($client));
    }

    /** A real, substantial, content-safe composed overview body (trimmed), or null. */
    private static function composedOverviewBody(Client $client): ?string
    {
        $page = self::overviewPage($client);
        if (! self::isComposed($page)) {
            return null;
        }
        $body = trim($page->body_md);
        if (strlen($body) < self::MIN_OVERVIEW_CHARS) {
            return null;
        }

        // Defend the always-injected surface (§6/§13): the composer scans what it
        // writes, but the overview body is human-editable afterward (the wiki Edit
        // page does not clear composed_at), so a hand-edited body could reach every
        // triage + Assistant prompt unscanned. Scan here regardless of provenance —
        // same posture WikiRetrieval::safeEnvelope applies to wiki_get_page. On a hit,
        // return null so injection falls back to site_notes.
        if (app(WikiRedactor::class)->scan($body) !== []) {
            return null;
        }

        return $body;
    }

    private static function overviewPage(Client $client): ?WikiPage
    {
        return WikiPage::active()->forClient($client->id)->where('kind', WikiPageKind::Overview->value)->first();
    }

    private static function isComposed(?WikiPage $page): bool
    {
        return $page !== null && ! empty($page->meta['composed_at']);
    }

    private static function clip(string $text): string
    {
        if (strlen($text) <= self::MAX_SITE_NOTES_LENGTH) {
            return $text;
        }
        // Truncate at last newline before limit to avoid cutting mid-line
        $cut = substr($text, 0, self::MAX_SITE_NOTES_LENGTH);
        $nl = strrpos($cut, "\n");
        if ($nl !== false && $nl > self::MAX_SITE_NOTES_LENGTH * 0.8) {
            $cut = substr($cut, 0, $nl);
        }

        return $cut."\n[TRUNCATED]";
    }

    private static function buildContactSection(Ticket $ticket): string
    {
        $contact = $ticket->contact;
        $lines = [
            '## Contact',
            "Name: {$contact->full_name}",
        ];

        if ($contact->email) {
            $lines[] = "Email: {$contact->email}";
        }

        $additionalEmails = $contact->additionalEmailAddresses()->limit(3)->pluck('email');
        if ($additionalEmails->isNotEmpty()) {
            $lines[] = 'Additional emails: '.$additionalEmails->implode(', ');
        }

        // M365 enrichment data
        if ($contact->mfa_enabled !== null) {
            $lines[] = 'MFA: '.($contact->mfa_enabled ? 'Enabled' : 'NOT REGISTERED');
        }
        if ($contact->m365_user_type === 'Guest') {
            $lines[] = 'M365 User Type: Guest';
        }
        if ($contact->department) {
            $lines[] = "Department: {$contact->department}";
        }
        if ($contact->hasExternalForward()) {
            $lines[] = "⚠ EXTERNAL FORWARD: {$contact->mailbox_forwarding_smtp} (BEC indicator — flag for review)";
        } elseif ($contact->mailbox_forwarding_smtp) {
            $lines[] = "Mailbox forward: {$contact->mailbox_forwarding_smtp}";
        }
        if ($contact->cipp_inactive) {
            $lastSeen = $contact->last_sign_in_at?->diffForHumans() ?? 'unknown';
            $lines[] = "Account flagged INACTIVE by CIPP (last sign-in: {$lastSeen})";
        }
        if (! empty($contact->notes) && trim($contact->notes) !== '') {
            $lines[] = "Notes:\n".trim($contact->notes);
        }

        return implode("\n", $lines);
    }

    private static function buildContractSection(Ticket $ticket): string
    {
        // Load all active contracts with completed document summaries (select only needed columns)
        $contracts = $ticket->client
            ? $ticket->client->contracts()->active()
                ->with(['documents' => fn ($q) => $q
                    ->where('summary_status', 'completed')
                    ->whereNotNull('ai_summary')
                    ->select('id', 'contract_id', 'original_filename', 'ai_summary', 'summary_status')
                    ->latest()
                    ->limit(self::MAX_DOC_SUMMARIES),
                ])
                ->get()
            : collect();

        if ($contracts->isEmpty()) {
            return "## Contracts\nNo active contracts found for this client.";
        }

        $lines = ['## Contracts'];

        foreach ($contracts as $contract) {
            $info = "- {$contract->name} (ID: {$contract->id})";
            $info .= ' | Type: '.$contract->type->value;
            $info .= ' | Status: '.$contract->status->value;
            $info .= ' | Billing: '.$contract->billing_source->value;

            if ($contract->has_prepay) {
                $info .= " | Prepay Balance: {$contract->prepay_balance_formatted}";
                if ($contract->prepay_as_amount) {
                    $info .= ' (dollars)';
                } else {
                    $info .= ' (hours)';
                }
            }

            if ($contract->start_date) {
                $info .= " | Started: {$contract->start_date->format('Y-m-d')}";
            }
            if ($contract->end_date) {
                $info .= " | Ends: {$contract->end_date->format('Y-m-d')}";
            }

            // Truncate if too long
            if (strlen($info) > self::MAX_CONTRACT_LENGTH) {
                $info = substr($info, 0, self::MAX_CONTRACT_LENGTH).'...';
            }

            $lines[] = $info;

            // Append AI document summaries (if any)
            foreach ($contract->documents as $doc) {
                $summary = $doc->ai_summary;
                if (strlen($summary) > self::MAX_DOC_SUMMARY_LENGTH) {
                    $cut = substr($summary, 0, self::MAX_DOC_SUMMARY_LENGTH);
                    $lastNewline = strrpos($cut, "\n");
                    if ($lastNewline !== false && $lastNewline > self::MAX_DOC_SUMMARY_LENGTH * 0.8) {
                        $cut = substr($cut, 0, $lastNewline);
                    }
                    $summary = $cut."\n[TRUNCATED]";
                }
                $lines[] = "  Contract Terms Summary ({$doc->original_filename}):";
                $lines[] = '  '.str_replace("\n", "\n  ", $summary);
            }
        }

        // Also include the ticket's directly linked contract if set
        if ($ticket->contract_id && ! $contracts->contains('id', $ticket->contract_id)) {
            $lines[] = "\nTicket linked to contract ID: {$ticket->contract_id}";
        }

        return implode("\n", $lines);
    }

    private static function buildAssetSection(Ticket $ticket): string
    {
        $lines = ['## Linked Assets'];

        foreach ($ticket->assets as $asset) {
            $assetLabel = $asset->hostname ?? $asset->name ?? 'Unknown';
            $info = "- {$assetLabel}";
            if ($asset->asset_type) {
                $info .= " | Type: {$asset->asset_type}";
            }
            if ($asset->os) {
                $info .= " | OS: {$asset->os}";
            }
            if ($asset->ip_address) {
                $info .= " | IP: {$asset->ip_address}";
            }
            $info .= ' | Status: '.$asset->statusBadge;
            if ($asset->last_boot_at) {
                $diff = $asset->last_boot_at->diff(now());
                $parts = [];
                if ($diff->days > 0) {
                    $parts[] = $diff->days.'d';
                }
                if ($diff->h > 0) {
                    $parts[] = $diff->h.'h';
                }
                if (empty($parts)) {
                    $parts[] = $diff->i.'m';
                }
                $info .= ' | Uptime: '.implode(' ', $parts);
            }
            if ($asset->needs_reboot) {
                $info .= ' | NEEDS REBOOT';
            }
            if ($asset->warranty_start) {
                $age = $asset->warranty_start->diffForHumans(now(), ['parts' => 1, 'short' => true]);
                $info .= " | System age: {$age} (warranty start: {$asset->warranty_start->format('Y-m-d')})";
            }
            if ($asset->warranty_end) {
                $expired = $asset->warranty_end->isPast();
                $info .= ' | Warranty: '.($expired ? 'EXPIRED '.$asset->warranty_end->format('Y-m-d') : 'until '.$asset->warranty_end->format('Y-m-d'));
            }
            if ($asset->ninja_id) {
                $info .= " | NinjaRMM ID: {$asset->ninja_id}";
            }
            if ($asset->controld_device_id) {
                $info .= " | DNS Profile: {$asset->controld_profile_name}";
                $agentLabel = $asset->controld_agent_status === 1 ? 'Connected' : 'Disconnected';
                $info .= " | DNS Agent: {$agentLabel}";
                if ($asset->controld_agent_version) {
                    $info .= " ({$asset->controld_agent_version})";
                }
            }
            if ($asset->zorus_endpoint_id) {
                $info .= ' | DNS Filtering: '.($asset->zorus_filtering_enabled ? 'Enabled' : 'Disabled').' (Zorus)';
                $info .= ' | CyberSight: '.($asset->zorus_cybersight_enabled ? 'Enabled' : 'Disabled');
            }
            if ($asset->m365_device_id) {
                $compliance = $asset->m365_is_compliant ? 'Compliant' : ($asset->m365_compliance_state ?? 'Unknown');
                $info .= " | Intune: {$compliance}";
                if ($asset->m365_defender_status) {
                    $info .= " | Defender: {$asset->m365_defender_status}";
                }
            }

            // Active alerts (unified — all sources)
            $activeAlerts = $asset->activeAlerts;
            if ($activeAlerts->count() > 0) {
                $info .= ' | Active Alerts: '.$activeAlerts->count();
                foreach ($activeAlerts->take(3) as $alert) {
                    $info .= "\n  - [{$alert->severity->label()}] {$alert->title}: ".mb_substr($alert->message ?? '', 0, 200);
                }
            }

            // Tactical RMM enrichment
            if ($asset->tactical_asset_id) {
                $tacticalAsset = $asset->tacticalAsset;
                if ($tacticalAsset) {
                    $info .= " | Tactical: {$tacticalAsset->status}";
                    if ($tacticalAsset->last_seen_at) {
                        $info .= ' (seen '.$tacticalAsset->last_seen_at->diffForHumans().')';
                    }
                    if ($tacticalAsset->make_model) {
                        $info .= " | Model: {$tacticalAsset->make_model}";
                    }
                    if ($tacticalAsset->cpu) {
                        $info .= " | CPU: {$tacticalAsset->cpu}";
                    }
                    if ($tacticalAsset->needs_reboot) {
                        $info .= ' | NEEDS REBOOT (Tactical)';
                    }
                    if ($tacticalAsset->has_patches_pending) {
                        $info .= ' | PATCHES PENDING';
                    }

                    // P5: token-budgeted, redacted, injection-fenced Tactical telemetry (replaces the
                    // un-timed inline live-check). Provider owns the bounded read + freshness contract.
                    $block = app(\App\Services\Tactical\TacticalContextProvider::class)->forAsset($asset);
                    if ($block !== null) {
                        $info .= "\n".$block->text;
                    }
                }
            }

            // Comet Backup
            if ($asset->comet_device_id && $asset->backup_synced_at) {
                $info .= "\n  Backup (Comet):";
                if ($asset->backup_cloud_bytes) {
                    $info .= "\n    Cloud storage: ".\App\Support\Format::bytes($asset->backup_cloud_bytes);
                }
                $info .= "\n    Last synced: ".$asset->backup_synced_at->diffForHumans();

                // Check last job status
                try {
                    $cometClient = new CometClient;
                    $jobService = new CometJobService($cometClient);
                    $jobData = $jobService->getRecentJobs($asset, 3);
                    if ($jobData['last_success']) {
                        $info .= "\n    Last successful backup: ".$jobData['last_success']['started'];
                    }
                    if ($jobData['last_failure'] && (! $jobData['last_success'] || $jobData['last_failure']['started'] > $jobData['last_success']['started'])) {
                        $info .= "\n    ⚠ Last backup FAILED: ".$jobData['last_failure']['started'];
                    }
                    // Sign-safe (psa-lqlu): now()->diffInDays($past) is NEGATIVE in Carbon 3,
                    // so the > 2 backup-staleness warning never fired. $past->diffInDays(now()) is positive.
                    $daysSinceBackup = $jobData['last_success']
                        ? (int) \Carbon\Carbon::parse($jobData['last_success']['started'])->diffInDays(now())
                        : null;
                    if ($daysSinceBackup !== null && $daysSinceBackup > 2) {
                        $info .= "\n    ⚠ No successful backup in {$daysSinceBackup} days";
                    }
                } catch (\Exception $e) {
                    // Silently fail — backup context is supplementary
                }
            }

            if (! empty($asset->notes) && trim($asset->notes) !== '') {
                $info .= "\n  Notes: ".trim($asset->notes);
            }

            if (strlen($info) > self::MAX_ASSET_LENGTH) {
                $info = substr($info, 0, self::MAX_ASSET_LENGTH).'...';
            }

            $lines[] = $info;
        }

        return implode("\n", $lines);
    }

    private static function buildNotesSection(Ticket $ticket): string
    {
        $lines = ['## Recent Notes (newest first)'];

        foreach ($ticket->notes as $note) {
            $author = $note->author?->name ?? $note->author_name ?? 'System';
            $date = $note->noted_at?->toDateTimeString() ?? $note->created_at->toDateTimeString();
            $type = $note->note_type?->label() ?? 'Note';

            $body = strip_tags($note->body ?? '');
            if (strlen($body) > self::MAX_NOTE_LENGTH) {
                $body = substr($body, 0, self::MAX_NOTE_LENGTH).' [TRUNCATED]';
            }

            $lines[] = "### {$type} by {$author} ({$date})";
            $recipients = self::formatEmailRecipients($note);
            if ($recipients) {
                $lines[] = $recipients;
            }
            if ($body) {
                $lines[] = $body;
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Build phone call transcription section from the PhoneCall record directly.
     * Phone call data lives on the phone_calls table, not in notes.
     */
    private static function buildPhoneCallSection(Ticket $ticket): ?string
    {
        $call = $ticket->phoneCalls()
            ->where('transcription_status', 'completed')
            ->whereNotNull('call_summary')
            ->latest('transcribed_at')
            ->first();

        if (! $call) {
            return null;
        }

        $content = "## Call Summary\n".$call->call_summary;
        if ($call->next_steps) {
            $content .= "\n\n## Next Steps\n".$call->next_steps;
        }

        $lines = ['## Phone Call'];
        $lines[] = "Direction: {$call->direction->value}";
        if ($call->duration) {
            $minutes = (int) floor($call->duration / 60);
            $lines[] = "Duration: {$minutes} min";
        }
        if ($call->sentiment_score) {
            $lines[] = "Sentiment: {$call->sentiment_score}/10";
        }

        if (strlen($content) > self::MAX_TICKET_BODY) {
            $content = substr($content, 0, self::MAX_TICKET_BODY)."\n[TRUNCATED]";
        }

        $lines[] = '';
        $lines[] = $content;

        return implode("\n", $lines);
    }

    /**
     * List which vendor integrations are active for this client's ticket.
     * Helps the AI know which tool categories are available without wasting
     * a tool call round-trip to discover a missing client mapping.
     */
    private static function buildIntegrationAvailabilitySection(Ticket $ticket): ?string
    {
        $client = $ticket->client;
        if (! $client) {
            return null;
        }

        $available = [];

        if ($client->mesh_customer_id && MeshConfig::isEnabled() && MeshConfig::isConfigured()) {
            $available[] = '- Mesh Email Security (use mesh_* tools for email delivery/quarantine issues)';
        }
        if ($client->ninja_org_id) {
            $available[] = '- NinjaRMM (use ninja_* tools for device diagnostics)';
        }
        if ($client->cipp_tenant_domain && CippConfig::isConfigured()) {
            $available[] = '- CIPP/M365 (use cipp_* tools for M365/Azure AD issues)';
        }
        if ($client->controld_org_id && ControlDConfig::isConfigured()) {
            $available[] = '- Control D (use controld_* tools for DNS security issues)';
        }
        if ($client->zorus_customer_id && ZorusConfig::isConfigured()) {
            $available[] = '- Zorus (use zorus_* tools for DNS filtering issues)';
        }
        if ($asset = $client->assets()->whereNotNull('tactical_asset_id')->exists()) {
            if (TacticalConfig::isConfigured()) {
                $available[] = '- Tactical RMM (use tactical_* tools for device diagnostics, checks, services, software)';
            }
        }
        if ($client->assets()->whereNotNull('comet_device_id')->exists() && CometConfig::isConfigured()) {
            $available[] = '- Comet Backup (use comet_get_backup_status, comet_get_backup_jobs for backup health)';
        }

        if (empty($available)) {
            return null;
        }

        return "## Available Integrations for This Client\n".implode("\n", $available);
    }

    /**
     * Build an OPERATOR DIRECTIVE section from the latest ticket_correction
     * AssistantConversations for this ticket. Returns '' when none exist.
     *
     * Trusted: injected OUTSIDE any untrusted fence so the model treats it as
     * authoritative guidance, not client-supplied data.
     */
    private static function recentCorrectionsSection(Ticket $ticket): string
    {
        $convIds = \App\Models\AssistantConversation::where('context_type', 'ticket_correction')
            ->where('context_id', $ticket->id)
            ->pluck('id');

        if ($convIds->isEmpty()) {
            return '';
        }

        // Latest 3 user-role messages across all correction conversations,
        // then reverse to chronological (oldest → newest) order.
        $messages = \App\Models\AssistantMessage::whereIn('conversation_id', $convIds)
            ->where('role', 'user')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get()
            ->sortBy('created_at')
            ->values();

        if ($messages->isEmpty()) {
            return '';
        }

        $concatenated = $messages->pluck('content')->implode("\n");

        // Operator display name from the latest correction conversation (reuse the ids
        // already fetched above — no second scan of the table).
        $latestConv = \App\Models\AssistantConversation::whereIn('id', $convIds)
            ->latest()
            ->first();

        $operator = $latestConv?->user_id ? \App\Models\User::find($latestConv->user_id) : null;
        $operatorName = $operator?->name ?? 'the operator';

        return (new \App\Services\Technician\PromptFence)->operatorDirective($operatorName, $concatenated);
    }

    /**
     * Format email recipients (From/To/Cc) for a note's linked email, if any.
     */
    private static function formatEmailRecipients(\App\Models\TicketNote $note): string
    {
        if (! $note->email) {
            return '';
        }

        $parts = [];
        if ($note->email->from_address) {
            $parts[] = 'From: '.($note->email->from_name ?: $note->email->from_address);
        }
        if ($note->email->to_recipients) {
            $parts[] = 'To: '.collect($note->email->to_recipients)->map(fn ($r) => $r['name'] ?? $r['address'])->join(', ');
        }
        if ($note->email->cc_recipients) {
            $parts[] = 'Cc: '.collect($note->email->cc_recipients)->map(fn ($r) => $r['name'] ?? $r['address'])->join(', ');
        }

        return $parts ? implode(' | ', $parts)."\n" : '';
    }
}
