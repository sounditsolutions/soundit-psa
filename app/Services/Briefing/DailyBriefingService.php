<?php

namespace App\Services\Briefing;

use App\Enums\NotificationEventType;
use App\Enums\UserRole;
use App\Models\DailyBriefing;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailService;
use App\Support\AppTimezone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the daily technician briefing: enumerate recipients, skip anyone
 * already briefed today (idempotency), assemble each one's content, email it, and
 * record an audit row. Sending is inline and fail-soft per technician — mirroring
 * {@see \App\Console\Commands\TechnicianDigest} rather than the per-event queued
 * fan-out — so one technician's failure never aborts the batch.
 */
class DailyBriefingService
{
    public function __construct(
        private readonly BriefingAssembler $assembler,
        private readonly EmailService $emailService,
    ) {}

    /**
     * @return array{candidates:int, sent:int, skipped_already:int, skipped_empty:int, skipped_optout:int, failed:int, previews:array<int,string>}
     */
    public function run(bool $dryRun = false, ?int $onlyUserId = null): array
    {
        $stats = [
            'candidates' => 0,
            'sent' => 0,
            'skipped_already' => 0,
            'skipped_empty' => 0,
            'skipped_optout' => 0,
            'failed' => 0,
            'previews' => [],
        ];

        // Can't email without an outbound mailbox — skip the whole run (mirrors the
        // graph_mailbox guard in SendTicketNotification) rather than failing per user.
        if (! $dryRun && ! Setting::getValue('graph_mailbox')) {
            Log::warning('[Briefing] No graph_mailbox configured; skipping run.');

            return $stats;
        }

        // The idempotency key is the operator-local date, so "today" lines up with
        // the technician's working day rather than a UTC rollover.
        $briefingDate = now()->setTimezone(AppTimezone::get())->toDateString();

        foreach ($this->recipients($onlyUserId) as $technician) {
            $stats['candidates']++;

            if (! $technician->wantsNotification(NotificationEventType::DailyBriefing)) {
                $stats['skipped_optout']++;

                continue;
            }

            // Cheap pre-check: skip anyone already briefed today (also avoids a
            // wasted AI call). The unique index is the integrity backstop.
            // whereDate() compares only the date part — robust whether the DATE
            // column stores '2026-07-09' (MariaDB) or '2026-07-09 00:00:00' (SQLite).
            if (DailyBriefing::where('user_id', $technician->id)->whereDate('briefing_date', $briefingDate)->exists()) {
                $stats['skipped_already']++;

                continue;
            }

            try {
                $content = $this->assembler->assemble($technician);

                if ($content->isEmpty) {
                    // Nothing to report — don't email, don't record (a re-run stays
                    // cheap and will pick them up if their day changes).
                    $stats['skipped_empty']++;

                    continue;
                }

                if ($dryRun) {
                    $stats['previews'][] = sprintf(
                        '%s <%s>: %d open, %d SLA-risk, %d alerts, %d voicemails%s',
                        $technician->name,
                        $technician->email,
                        $content->openTicketCount,
                        $content->slaRiskCount,
                        $content->alertCount,
                        $content->voicemailCount,
                        $content->aiSuggestionsIncluded ? ' (+AI)' : '',
                    );

                    continue;
                }

                $this->emailService->sendNew(
                    $technician->email,
                    $content->subject,
                    $content->body,
                    $technician->name,
                    null,
                    $technician->id,
                );

                DailyBriefing::create([
                    'user_id' => $technician->id,
                    'briefing_date' => $briefingDate,
                    'sent_at' => now(),
                    'open_ticket_count' => $content->openTicketCount,
                    'alert_count' => $content->alertCount,
                    'voicemail_count' => $content->voicemailCount,
                    'sla_risk_count' => $content->slaRiskCount,
                    'ai_suggestions_included' => $content->aiSuggestionsIncluded,
                ]);

                $stats['sent']++;

                Log::info('[Briefing] Sent', [
                    'user_id' => $technician->id,
                    'open' => $content->openTicketCount,
                    'sla_risk' => $content->slaRiskCount,
                ]);
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::error('[Briefing] Failed for technician', [
                    'user_id' => $technician->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Active internal technicians with an email address. "Technician" = the
     * service-delivery roles (Tech + Admin); Billing and Contractor roles and
     * contractor-flagged users are excluded. Admins are included because every
     * user defaults to the Admin role until the authorization epic assigns finer
     * roles — a strict role=tech filter would brief nobody on most deployments.
     *
     * @return Collection<int, User>
     */
    private function recipients(?int $onlyUserId): Collection
    {
        $query = User::query()
            ->where('is_active', true)
            ->where('is_contractor', false)
            ->whereNotNull('email')
            ->whereIn('role', [UserRole::Admin->value, UserRole::Tech->value])
            ->orderBy('id');

        if ($onlyUserId !== null) {
            $query->where('id', $onlyUserId);
        }

        return $query->get();
    }
}
