<?php

namespace App\Services\Triage;

use App\Enums\NoteType;
use App\Enums\TicketStatus;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\TriageRun;
use App\Services\Ai\AiClient;
use App\Services\PrepayService;
use App\Support\AiConfig;
use App\Support\TriageConfig;
use Illuminate\Support\Facades\Log;

class TriagePipeline
{
    private AiClient $ai;
    private bool $isManual = false;
    private array $stagesCompleted = [];
    private array $stageResults = [];
    private array $errors = [];

    public function __construct(
        private readonly \App\Services\TicketService $ticketService,
    ) {}

    /**
     * Run the triage pipeline on a ticket.
     */
    public function run(Ticket $ticket, string $mode = 'triage', ?int $triggeredByUserId = null): TriageRun
    {
        $this->isManual = $triggeredByUserId !== null;
        $triggeredBy = $this->isManual ? 'manual' : 'auto';
        if ($mode === 'review') {
            $triggeredBy = $this->isManual ? 'manual' : 'cron';
        }

        $run = TriageRun::create([
            'ticket_id' => $ticket->id,
            'mode' => $mode,
            'status' => 'running',
            'triggered_by' => $triggeredBy,
            'triggered_by_user_id' => $triggeredByUserId,
            'started_at' => now(),
            'stages_completed' => [],
            'stage_results' => [],
            'errors' => [],
        ]);

        // Check daily token ceiling before proceeding
        if (! $this->withinDailyTokenLimit()) {
            Log::warning('[Triage] Daily token limit exceeded, skipping pipeline', [
                'ticket_id' => $ticket->id,
            ]);
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'errors' => [['stage' => 'pre_check', 'message' => 'Daily token limit exceeded']],
            ]);

            return $run;
        }

        // Initialize AiClient with model override if configured
        $this->ai = new AiClient(
            modelOverride: TriageConfig::model() !== AiConfig::model() ? TriageConfig::model() : null,
        );

        $startTime = microtime(true);

        try {
            if ($mode === 'triage') {
                $this->runTriageMode($ticket);
            } elseif ($mode === 'review') {
                $this->runReviewMode($ticket);
            }

            $run->update([
                'status' => 'completed',
                'stages_completed' => $this->stagesCompleted,
                'stage_results' => $this->stageResults,
                'errors' => $this->errors,
                'completed_at' => now(),
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'ai_tokens_used' => [
                    'input_tokens' => $this->ai->cumulativeInputTokens(),
                    'output_tokens' => $this->ai->cumulativeOutputTokens(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Triage] Pipeline failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'status' => 'failed',
                'stages_completed' => $this->stagesCompleted,
                'stage_results' => $this->stageResults,
                'errors' => array_merge($this->errors, [
                    ['stage' => 'pipeline', 'message' => $e->getMessage()],
                ]),
                'completed_at' => now(),
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'ai_tokens_used' => [
                    'input_tokens' => $this->ai->cumulativeInputTokens(),
                    'output_tokens' => $this->ai->cumulativeOutputTokens(),
                ],
            ]);
        }

        return $run;
    }

    /**
     * Run the full triage pipeline for new tickets.
     */
    private function runTriageMode(Ticket $ticket): void
    {
        // Stage 0: Contact Resolution
        $this->runStage('contact_resolution', $ticket, function () use ($ticket) {
            return $this->runContactResolution($ticket);
        });

        // Stage 0.5: Junk Filter
        $this->runStage('junk_filter', $ticket, function () use ($ticket) {
            return $this->runJunkFilter($ticket);
        });

        // Check if junk filter closed the ticket
        $ticket->refresh();
        if ($ticket->status === TicketStatus::Closed) {
            Log::info('[Triage] Ticket closed by junk filter, stopping pipeline', ['ticket_id' => $ticket->id]);

            return;
        }

        // Stage 1: Triage Classification (requires AI)
        if (AiConfig::isConfigured()) {
            $this->runStage('classification', $ticket, function () use ($ticket) {
                return $this->runTriageClassification($ticket);
            });

            // After classification, reconcile billability on pre-existing items
            $this->reconcileBillabilityAfterClassification($ticket);
        } else {
            Log::info('[Triage] Skipping classification — AI not configured', ['ticket_id' => $ticket->id]);
        }

        // Stage 2c: Asset Auto-Assignment
        $this->runStage('asset_assignment', $ticket, function () use ($ticket) {
            return $this->runAssetAutoAssign($ticket);
        });

        // Stage 3: Technical Triage (requires AI)
        if (AiConfig::isConfigured()) {
            $this->runStage('technical_triage', $ticket, function () use ($ticket) {
                return $this->runTechnicalTriage($ticket);
            });
        } else {
            Log::info('[Triage] Skipping technical triage — AI not configured', ['ticket_id' => $ticket->id]);
        }
    }

    /**
     * Run review mode for existing tickets.
     */
    private function runReviewMode(Ticket $ticket): void
    {
        if (! AiConfig::isConfigured()) {
            Log::info('[Triage] Skipping review — AI not configured', ['ticket_id' => $ticket->id]);

            return;
        }

        // Stage 0: Contact Resolution (if still unlinked)
        if (! $ticket->contact_id) {
            $this->runStage('contact_resolution', $ticket, function () use ($ticket) {
                return $this->runContactResolution($ticket);
            });
        }

        // Stage 0.5: Junk Filter
        $this->runStage('junk_filter', $ticket, function () use ($ticket) {
            return $this->runJunkFilter($ticket);
        });

        // Stage 2c: Asset Auto-Assignment (if no assets linked)
        if ($ticket->assets()->count() === 0) {
            $this->runStage('asset_assignment', $ticket, function () use ($ticket) {
                return $this->runAssetAutoAssign($ticket);
            });
        }

        // Auto-assign if still unassigned
        if (! $ticket->assignee_id) {
            $this->autoAssignTicket($ticket);
        }

        // Review: Conversation Review
        $this->runStage('conversation_review', $ticket, function () use ($ticket) {
            return ConversationReviewer::review($ticket, $this->ai, $this->ticketService, $this->isManual);
        });
    }

    /**
     * Run a single pipeline stage with error handling and tracking.
     */
    private function runStage(string $stageName, Ticket $ticket, callable $handler): void
    {
        if (! TriageConfig::stageEnabled($stageName)) {
            Log::debug('[Triage] Stage disabled, skipping', [
                'stage' => $stageName,
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        try {
            Log::info("[Triage] Starting stage: {$stageName}", ['ticket_id' => $ticket->id]);

            $result = $handler();

            $this->stagesCompleted[] = $stageName;
            $this->stageResults[$stageName] = $result;

            Log::info("[Triage] Completed stage: {$stageName}", ['ticket_id' => $ticket->id]);
        } catch (\Throwable $e) {
            Log::error("[Triage] Stage failed: {$stageName}", [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            $this->errors[] = [
                'stage' => $stageName,
                'message' => $e->getMessage(),
            ];
        }
    }

    // ── Stage Implementations ──

    /**
     * Stage 0: Contact Resolution.
     * Auto-link ticket to correct client/contact via email, hostname, or AI extraction.
     */
    private function runContactResolution(Ticket $ticket): array
    {
        $resolution = ContactResolver::resolve($ticket, $this->ai ?? null);

        if (! $resolution) {
            return ['resolved' => false];
        }

        return $resolution->toArray();
    }

    /**
     * Stage 2c: Asset Auto-Assignment.
     * Link workstation to ticket via person assignment, NinjaRMM, or name matching.
     */
    private function runAssetAutoAssign(Ticket $ticket): array
    {
        $asset = AssetMatcher::match($ticket);

        if (! $asset) {
            return ['matched' => false];
        }

        return [
            'matched' => true,
            'asset_id' => $asset->id,
            'hostname' => $asset->hostname,
            'method' => 'auto_match',
        ];
    }

    /**
     * Stage 3: Technical Triage.
     * AI performs deep technical analysis with tool access.
     */
    private function runTechnicalTriage(Ticket $ticket): array
    {
        // Get classification from earlier stage if available
        $classificationData = $this->stageResults['classification'] ?? null;
        $classification = $classificationData && isset($classificationData['client_type'])
            ? TriageClassification::fromArray($classificationData)
            : null;

        return TechnicalTriager::analyze(
            $ticket,
            $this->ai,
            $classification,
            $this->ticketService,
        );
    }

    /**
     * Stage 1: Triage Classification.
     * AI classifies contract/billing situation.
     */
    private function runTriageClassification(Ticket $ticket): array
    {
        $classification = TriageClassifier::classify($ticket, $this->ai);

        return $classification->toArray();
    }

    /**
     * Stage 0.5: Junk Filter.
     * Deterministic pattern matching + optional AI confirmation.
     */
    private function runJunkFilter(Ticket $ticket): array
    {
        // Pre-checks: skip if ticket is likely legitimate
        if (JunkDetector::shouldSkip($ticket)) {
            Log::debug('[Triage] Junk filter skipped (pre-check)', ['ticket_id' => $ticket->id]);

            return ['skipped' => true, 'reason' => 'pre_check'];
        }

        $senderEmail = JunkDetector::resolveSenderEmail($ticket) ?? '';
        $subject = $ticket->subject ?? '';
        $body = $ticket->description ?? '';

        // Run deterministic classification
        $result = JunkDetector::classify($subject, $body, $senderEmail);

        if (! $result) {
            return ['is_junk' => false];
        }

        // High confidence: auto-close immediately
        if ($result->isHighConfidence()) {
            $this->closeAsJunk($ticket, $result);

            return $result->toArray();
        }

        // Medium confidence: AI confirmation required
        if (AiConfig::isConfigured()) {
            $confirmed = JunkDetector::aiConfirm($this->ai, $subject, $senderEmail, $body);

            if ($confirmed) {
                $this->closeAsJunk($ticket, $result);

                return array_merge($result->toArray(), ['ai_confirmed' => true]);
            }

            Log::info('[Triage] AI declined junk confirmation', [
                'ticket_id' => $ticket->id,
                'pattern' => $result->pattern,
            ]);

            return array_merge($result->toArray(), ['ai_confirmed' => false, 'action' => 'kept_open']);
        }

        // No AI available — leave medium-confidence items open
        Log::info('[Triage] Medium-confidence junk left open (AI not configured)', [
            'ticket_id' => $ticket->id,
            'pattern' => $result->pattern,
        ]);

        return array_merge($result->toArray(), ['action' => 'kept_open_no_ai']);
    }

    /**
     * Close a ticket identified as junk.
     */
    private function closeAsJunk(Ticket $ticket, JunkResult $result): void
    {
        $systemUserId = TriageConfig::systemUserId();

        if (! $systemUserId) {
            Log::warning('[Triage] Cannot close junk ticket — no system user configured', [
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        $note = sprintf(
            "Auto-closed: %s\nReason: %s\nConfidence: %s",
            ucfirst(str_replace('_', ' ', $result->pattern)),
            $result->reason,
            $result->confidence,
        );

        try {
            $this->ticketService->changeStatus(
                $ticket,
                TicketStatus::Closed,
                $systemUserId,
                $note,
            );

            // Add a triage-specific note for visibility
            $this->ticketService->addNote(
                $ticket,
                $note,
                NoteType::AiTriage,
                true, // private
                $systemUserId,
            );

            Log::info('[Triage] Ticket auto-closed as junk', [
                'ticket_id' => $ticket->id,
                'pattern' => $result->pattern,
                'confidence' => $result->confidence,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Triage] Failed to close junk ticket', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if today's total token usage is within the daily limit.
     */
    private function withinDailyTokenLimit(): bool
    {
        $limit = TriageConfig::dailyTokenLimit();

        $todayTokens = TriageRun::whereDate('created_at', today())
            ->whereNotNull('ai_tokens_used')
            ->get()
            ->sum(fn (TriageRun $run) => $run->totalTokensUsed());

        return $todayTokens < $limit;
    }

    /**
     * After triage classification, update billability on phone calls and notes
     * that were created before classification was available.
     */
    private function reconcileBillabilityAfterClassification(Ticket $ticket): void
    {
        $classification = $this->stageResults['classification'] ?? null;
        if (! $classification || ! isset($classification['work_covered_by_managed'])) {
            return;
        }

        $shouldBeBillable = ! $classification['work_covered_by_managed'];

        // Update phone calls with unset or mismatched billability
        $calls = PhoneCall::where('ticket_id', $ticket->id)
            ->where(fn ($q) => $q->whereNull('is_billable')->orWhere('is_billable', '!=', $shouldBeBillable))
            ->get();

        if ($calls->isNotEmpty()) {
            $prepayService = app(PrepayService::class);
            foreach ($calls as $call) {
                $call->is_billable = $shouldBeBillable;
                $call->save();
                $prepayService->debitFromPhoneCall($call);
            }

            Log::info('[Triage] Reconciled phone call billability after classification', [
                'ticket_id' => $ticket->id,
                'calls_updated' => $calls->count(),
                'billable' => $shouldBeBillable,
            ]);
        }

        // Update ticket notes that defaulted to billable=true before classification
        $notes = TicketNote::where('ticket_id', $ticket->id)
            ->where('is_billable', '!=', $shouldBeBillable)
            ->whereNotNull('time_minutes')
            ->where('time_minutes', '>', 0)
            ->get();

        if ($notes->isNotEmpty()) {
            $prepayService = $prepayService ?? app(PrepayService::class);
            foreach ($notes as $note) {
                $note->is_billable = $shouldBeBillable;
                $note->save();
                $prepayService->debitFromTicketNote($note);
            }

            Log::info('[Triage] Reconciled note billability after classification', [
                'ticket_id' => $ticket->id,
                'notes_updated' => $notes->count(),
                'billable' => $shouldBeBillable,
            ]);
        }
    }

    /**
     * Auto-assign ticket to the appropriate technician if unassigned.
     */
    private function autoAssignTicket(Ticket $ticket): void
    {
        $systemUserId = TriageConfig::systemUserId();
        if (! $systemUserId) {
            return;
        }

        $assigneeId = $ticket->client?->primary_tech_id ?? TriageConfig::defaultAssigneeId();

        if ($assigneeId) {
            $this->ticketService->assignTicket($ticket, $assigneeId, $systemUserId);

            Log::info('[Triage] Ticket auto-assigned', [
                'ticket_id' => $ticket->id,
                'assignee_id' => $assigneeId,
                'source' => $ticket->client?->primary_tech_id ? 'primary_tech' : 'default',
            ]);
        }
    }
}
