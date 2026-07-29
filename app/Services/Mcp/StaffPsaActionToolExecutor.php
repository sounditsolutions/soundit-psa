<?php

namespace App\Services\Mcp;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Helpers\MarkdownRenderer;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Agent\CloseAutoEligibility;
use App\Services\AssetService;
use App\Services\Assistant\AssistantTicketCreator;
use App\Services\ClientService;
use App\Services\Email\EmailRecipientResolver;
use App\Services\Email\EmailSendOutcome;
use App\Services\Email\EmailSendStatus;
use App\Services\Email\RecipientContext;
use App\Services\Email\RecipientValidationException;
use App\Services\Email\ResolvedRecipients;
use App\Services\EmailService;
use App\Services\PersonService;
use App\Services\PhoneCallService;
use App\Services\Technician\TechnicianActionGate;
use App\Services\Technician\TechnicianDisclosure;
use App\Services\TicketService;
use App\Support\EmailRedactor;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StaffPsaActionToolExecutor
{
    private const DIRECT_DEDUP_HOURS = 24;

    /**
     * The result_status vocabulary of the send_email audit trail.
     *
     * technician_action_logs is APPEND-ONLY (TechnicianActionLog's updating/deleting guards
     * plus MariaDB triggers), so a send's state is the LATEST row for
     * (action_type, ticket_id, content_hash) — never a row that was updated or deleted:
     *   reserved    — claimed before the network call; the delivery outcome is not yet known
     *   executed    — the send demonstrably reached the client (the ONLY success status)
     *   unconfirmed — it may have (or did) reach the client but is not fully recorded
     *   voided      — it provably transmitted nothing, so the legitimate retry is open again
     * 'executed' keeps one meaning for every consumer (dedup, digests, insight readouts): a
     * client-facing action that demonstrably happened. A mere attempt must never borrow it,
     * and an attempt must never be erased from the trail (psa-330).
     */
    private const RESULT_EXECUTED = 'executed';

    private const RESULT_RESERVED = 'reserved';

    private const RESULT_UNCONFIRMED = 'unconfirmed';

    private const RESULT_VOIDED = 'voided';

    /**
     * Actionable recovery copy for a rejected category_id (so-0ftg, psa-bk13g UX
     * fix). An autonomous agent (Chet) cannot self-correct from the bare Laravel
     * "selected category id is invalid" line, so we name (1) the active-node
     * requirement, (2) how to enumerate valid ids — list_ticket_categories WITHOUT
     * include_inactive (see StaffPsaTaxonomyToolExecutor::definitions(), the tool
     * and param names verified there), and (3) that null clears the assignment.
     */
    private const CATEGORY_ID_RECOVERY_COPY = 'category_id must reference an ACTIVE ticket taxonomy node; retired or unknown ids are rejected. Call the list_ticket_categories tool without include_inactive to list the valid active node ids, then retry update_ticket with one of them. Pass null to clear the ticket category.';

    /**
     * The create_ticket counterpart of CATEGORY_ID_RECOVERY_COPY (so-0ftg,
     * psa-begf3.2). Same guidance, reworded for the create surface: retry
     * create_ticket, and the escape hatch is to omit the field / pass null
     * (there is nothing to "clear" on a brand-new ticket).
     */
    private const CREATE_CATEGORY_ID_RECOVERY_COPY = 'category_id must reference an ACTIVE ticket taxonomy node; retired or unknown ids are rejected. Call the list_ticket_categories tool without include_inactive to list the valid active node ids, then retry create_ticket with one of them. Omit category_id (or pass null) to create the ticket uncategorized.';

    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianDisclosure $disclosure,
        private readonly EmailService $email,
        private readonly AssistantTicketCreator $ticketCreator,
        private readonly TicketService $ticketService,
        private readonly ClientService $clientService,
        private readonly PersonService $personService,
        private readonly AssetService $assetService,
        private readonly PhoneCallService $phoneCallService,
        private readonly EmailRecipientResolver $recipients,
    ) {}

    /** @return array<string, mixed> */
    /**
     * $actorLabel is the prefixed audit label (McpStaffToken::actorLabel()); $tokenLabel
     * is the caller's BARE McpToken.label. They are NOT interchangeable: only the bare
     * one resolves a Teams persona for the client-facing tagline (psa-u51h). Handlers
     * that emit no client-facing text take $actorLabel alone, as before.
     */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel, ?string $tokenLabel = null): array
    {
        return match ($name) {
            'create_ticket' => $this->createTicket($arguments, $clientId, $actorLabel),
            'send_email' => $this->sendEmail($arguments, $clientId, $actorLabel, $tokenLabel),
            'write_public_note' => $this->writePublicNote($arguments, $clientId, $actorLabel, $tokenLabel),
            'stage_email' => $this->stageTicketAction('stage_email', $arguments, $clientId, $actorLabel, sendsEmail: true, tokenLabel: $tokenLabel),
            'stage_public_note' => $this->stageTicketAction('stage_public_note', $arguments, $clientId, $actorLabel, sendsEmail: false, tokenLabel: $tokenLabel),
            'propose_merge' => $this->proposeMerge($arguments, $clientId, $actorLabel),
            'update_ticket' => $this->updateTicket($arguments, $clientId, $actorLabel),
            'set_ticket_status' => $this->setTicketStatus($arguments, $clientId, $actorLabel),
            'close_ticket' => $this->closeTicket($arguments, $clientId, $actorLabel),
            'stage_close_ticket' => $this->stageClose($arguments, $clientId, $actorLabel),
            'assign_ticket' => $this->assignTicket($arguments, $clientId, $actorLabel),
            'assign_asset' => $this->assignAsset($arguments, $clientId, $actorLabel),
            'unassign_asset' => $this->unassignAsset($arguments, $clientId, $actorLabel),
            'set_ticket_contact' => $this->setTicketContact($arguments, $clientId, $actorLabel),
            'move_ticket_to_client' => $this->moveTicketToClient($arguments, $clientId, $actorLabel),
            'create_client' => $this->createClient($arguments, $actorLabel),
            'update_client' => $this->updateClient($arguments, $clientId, $actorLabel),
            'update_client_site_notes' => $this->updateClientSiteNotes($arguments, $clientId, $actorLabel),
            'delete_client' => $this->deleteClient($arguments, $clientId, $actorLabel),
            'create_contact' => $this->createContact($arguments, $clientId, $actorLabel),
            'update_contact' => $this->updateContact($arguments, $actorLabel),
            'set_primary_contact' => $this->setPrimaryContact($arguments, $actorLabel),
            'move_contact_to_client' => $this->moveContactToClient($arguments, $actorLabel),
            'delete_contact' => $this->deleteContact($arguments, $actorLabel),
            'create_asset' => $this->createAsset($arguments, $clientId, $actorLabel),
            'update_asset' => $this->updateAsset($arguments, $actorLabel),
            'retire_asset' => $this->retireAsset($arguments, $actorLabel),
            'restore_asset' => $this->restoreAsset($arguments, $actorLabel),
            'link_asset_user' => $this->linkAssetUser($arguments, $actorLabel),
            'unlink_asset_user' => $this->unlinkAssetUser($arguments, $actorLabel),
            'set_primary_asset_user' => $this->setPrimaryAssetUser($arguments, $actorLabel),
            'link_email_to_ticket' => $this->linkEmailToTicket($arguments, $actorLabel),
            'create_ticket_from_email' => $this->createTicketFromEmail($arguments, $actorLabel),
            'dismiss_email_item' => $this->dismissEmailItem($arguments, $actorLabel),
            'link_call_to_ticket' => $this->linkCallToTicket($arguments, $actorLabel),
            'create_ticket_from_call' => $this->createTicketFromCall($arguments, $actorLabel),
            default => ['error' => "Unknown PSA action tool: {$name}"],
        };
    }

    /** @return array<string, mixed> */
    private function createTicket(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        // ITIL taxonomy node (so-0ftg, psa-begf3.2): validate before building the
        // payload so a bad node fails fast with actionable recovery copy.
        $categoryId = $this->validateCreateCategoryId($arguments);
        if (is_array($categoryId)) {
            return $categoryId;
        }

        try {
            $payload = $this->ticketCreator->payload($clientId, $arguments);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        // A chosen node rides into TicketService::createTicket (category_id is
        // fillable); TicketObserver stamps category_source=System + logs it. The
        // dedup content hash intentionally ignores category_id (it is not part of
        // ticket identity), so it stays computed on subject/description/client.
        if ($categoryId !== null) {
            $payload['category_id'] = $categoryId;
        }

        $contentHash = $this->ticketCreator->contentHashFromPayload($payload);
        $existing = $this->alreadyCreatedTicketLog($clientId, $contentHash);
        if ($existing !== null) {
            return $this->idempotentCreateTicketResult($existing);
        }

        $ticket = DB::transaction(function () use ($payload, $actorLabel, $contentHash, $reason): Ticket {
            $actorId = TechnicianConfig::requiredAiActorUserId();
            $ticket = $this->ticketCreator->createFromPayload($payload, $actorId);
            $this->auditDirectExecution(
                'create_ticket',
                $ticket,
                $actorLabel,
                $contentHash,
                'Direct MCP ticket created: '.$reason,
                $actorId,
            );

            return $ticket;
        });

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'display_id' => $ticket->display_id,
            'url' => route('tickets.show', $ticket),
            'message' => 'Ticket created.',
        ];
    }

    /** @return array<string, mixed> */
    private function updateTicket(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        $reason = $this->optionalString($arguments, 'reason');
        $validated = $this->validateTicketUpdatePayload($arguments);
        if (is_array($validated) && isset($validated['error'])) {
            return $validated;
        }
        if ($validated === []) {
            return ['error' => 'update_ticket requires at least one editable field'];
        }

        $before = [
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'priority' => $ticket->priority?->value,
            'type' => $ticket->type?->value,
        ];

        $updated = DB::transaction(function () use ($ticket, $validated, $actorLabel, $reason, $before): Ticket {
            $updated = $this->ticketService->updateTicket($ticket, $validated);
            $after = [
                'subject' => $updated->subject,
                'description' => $updated->description,
                'priority' => $updated->priority?->value,
                'type' => $updated->type?->value,
            ];
            $diff = $this->fieldDiff($before, $after);
            $summary = 'Ticket updated'.($reason ? ': '.$reason : '.');
            if ($diff !== []) {
                $summary .= ' Changes: '.$this->stringifyDiff($diff).'.';
            }

            $this->auditDirectExecution(
                'update_ticket',
                $updated,
                $actorLabel,
                $this->mutationContentHash('update_ticket', $updated->id, $validated, $reason),
                $summary,
                TechnicianConfig::requiredAiActorUserId(),
            );

            return $updated;
        });

        return [
            'success' => true,
            'ticket_id' => $updated->id,
            'ticket_display_id' => $updated->display_id,
            'message' => 'Ticket updated.',
        ];
    }

    /** @return array<string, mixed> */
    private function setTicketStatus(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        $status = $this->ticketStatusFrom($arguments['status'] ?? null);
        if ($status === null) {
            return ['error' => 'status is required'];
        }

        // psa-d9ayt: set_ticket_status handles NON-terminal transitions only. Resolving or
        // closing routes through close_ticket, which carries the resolution summary and the
        // full auto-close safety envelope — a terminal transition must never slip through the
        // general status changer. Use the isTerminal() predicate, never a hardcoded list.
        if ($status->isTerminal()) {
            return ['error' => 'Resolving or closing a ticket must go through close_ticket.'];
        }

        $reason = $this->optionalString($arguments, 'reason');
        $note = $this->optionalString($arguments, 'note');

        try {
            $updated = $this->ticketService->changeStatus(
                $ticket,
                $status,
                TechnicianConfig::requiredAiActorUserId(),
                $note,
                null,
            );
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        $summary = "Status changed to {$status->label()}".($reason ? ': '.$reason : '.');
        $this->auditDirectExecution(
            'set_ticket_status',
            $updated,
            $actorLabel,
            $this->mutationContentHash('set_ticket_status', $updated->id, [
                'status' => $status->value,
                'note' => $note,
                'reason' => $reason,
            ]),
            $summary,
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'ticket_id' => $updated->id,
            'ticket_display_id' => $updated->display_id,
            'status' => $updated->status->value,
            'message' => "Status changed to {$status->label()}.",
        ];
    }

    /**
     * close_ticket (psa-d9ayt) — the ONLY sanctioned terminal transition. The psa-y4ft
     * auto-close safety envelope moved here wholesale from set_ticket_status: ->Closed is
     * eligibility-gated (a ticket still awaiting us, with recent client activity, or already
     * closed is refused); the already-in-state + pending-held-close dedup covers BOTH terminal
     * transitions; ->Resolved is a legitimate everyday action and is not eligibility-gated.
     * resolution_summary is written as BOTH the closing note and the ticket resolution — no
     * silent closes. An executed ->Closed records a Done direct_close run so the cockpit reopen
     * lane can offer one-click Reopen.
     *
     * @return array<string, mixed>
     */
    private function closeTicket(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        // status defaults to closed; only a terminal status may pass. Validate with the
        // enum predicate, never a hardcoded ['resolved','closed'] list (a second definition
        // would drift from TicketStatus).
        $status = $this->closeTargetStatus($arguments['status'] ?? null);
        if ($status === null) {
            return ['error' => 'status must be resolved or closed.'];
        }

        $resolutionSummary = $this->requiredString($arguments, 'resolution_summary');
        if ($resolutionSummary === null) {
            return ['error' => 'resolution_summary is required — a ticket is never closed silently.'];
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        // Reject a supplied-but-invalid confidence BEFORE the ticket is touched (a bad value
        // must not silently become a band bypass — nor close the ticket off it).
        $confidence = $this->resolveCloseConfidence($arguments);
        if (is_array($confidence)) {
            return $confidence;
        }

        // Eligibility gates ->Closed ONLY (resolving an active ticket is legitimate).
        if ($status === TicketStatus::Closed && ! CloseAutoEligibility::eligible($ticket)) {
            return ['error' => $this->directCloseIneligibleReason($ticket)];
        }

        // Already-in-state + pending-held-close dedup: both terminal transitions.
        if ($ticket->status === $status) {
            return ['error' => "Ticket #{$ticket->id} is already {$status->label()} — leaving it as-is."];
        }

        if ($this->hasPendingProposedClose($ticket)) {
            return ['error' => "A close is already proposed for ticket #{$ticket->id} and awaiting approval — not re-actioning it via close_ticket."];
        }

        try {
            $updated = $this->ticketService->changeStatus(
                $ticket,
                $status,
                TechnicianConfig::requiredAiActorUserId(),
                $resolutionSummary,
                $resolutionSummary,
            );
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        $summary = "Ticket {$status->label()} via close_ticket: {$reason}";
        $this->auditDirectExecution(
            'close_ticket',
            $updated,
            $actorLabel,
            $this->mutationContentHash('close_ticket', $updated->id, [
                'status' => $status->value,
                'resolution_summary' => $resolutionSummary,
                'reason' => $reason,
                'confidence' => $confidence,
            ]),
            $summary,
            TechnicianConfig::requiredAiActorUserId(),
        );

        if ($status === TicketStatus::Closed) {
            $this->recordDirectCloseUndoCard($updated, $reason, $actorLabel);
        }

        return [
            'success' => true,
            'ticket_id' => $updated->id,
            'ticket_display_id' => $updated->display_id,
            'status' => $updated->status->value,
            'message' => "Ticket {$status->label()}.",
        ];
    }

    /**
     * Staged twin of close_ticket. Records a HELD propose_close proposal routed through the
     * existing cockpit approval machinery (TechnicianApprovalService::approveClose), so the
     * approval UI, the close dedup, and the CloseBandEvaluator calibration all work unchanged.
     * Held-only: the gate is dispatched with a null confidence so it never auto-fires, while the
     * confidence enum is mapped to a representative float and recorded on the run (where the
     * band reads it). Omitted confidence records null — the band bypasses it.
     *
     * @return array<string, mixed>
     */
    private function stageClose(array $arguments, int $clientId, string $actorLabel): array
    {
        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        $status = $this->closeTargetStatus($arguments['status'] ?? null);
        if ($status === null) {
            return ['error' => 'status must be resolved or closed.'];
        }

        $resolutionSummary = $this->requiredString($arguments, 'resolution_summary');
        if ($resolutionSummary === null) {
            return ['error' => 'resolution_summary is required — a ticket is never closed silently.'];
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        // Reject a supplied-but-invalid confidence before any run is created (a bad value must
        // not silently become a band bypass on the held run the operator later approves).
        $confidence = $this->resolveCloseConfidence($arguments);
        if (is_array($confidence)) {
            return $confidence;
        }

        if ($ticket->status === TicketStatus::Closed) {
            return ['error' => "Ticket #{$ticket->id} is already closed — nothing to propose."];
        }

        // Ticket-level dedup: never stack a second held close on top of a pending one.
        if ($this->hasPendingProposedClose($ticket)) {
            return [
                'success' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'message' => "A close is already proposed for ticket #{$ticket->id}; awaiting approval.",
            ];
        }

        // action_type is propose_close (NOT stage_close_ticket) so approval flows through the
        // existing approveClose lane. The stored content_hash is the firstOrCreate DEDUP key and
        // binds the TARGET too: propose-resolved and propose-closed on the same summary are
        // genuinely different proposals and must not collide on one run. (This is the dedup key
        // ONLY — approveClose recomputes its own target-bound grant hash at approval time; the
        // two are never compared, so the formulas need not agree.) Held-only: run.confidence
        // carries the mapped float for calibration while the gate is dispatched with null so the
        // auto band never fires.
        $hash = hash('sha256', 'propose_close:'.$ticket->id.':'.$status->value.':'.$resolutionSummary);
        $meta = [
            'confidence' => $confidence,
            'close_status' => $status->value,
            'drafted_by' => $actorLabel,
        ];

        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => 'propose_close',
                'content_hash' => $hash,
            ],
            [
                'client_id' => $ticket->client_id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $resolutionSummary,
                'proposed_meta' => $meta,
                'confidence' => $confidence,
                'tokens_used' => 0,
            ],
        );

        if (! $run->wasRecentlyCreated) {
            if ($run->state === TechnicianRunState::AwaitingApproval) {
                return [
                    'success' => true,
                    'ticket_id' => $ticket->id,
                    'ticket_display_id' => $ticket->display_id,
                    'run_id' => $run->id,
                    'message' => 'Already proposed closing this ticket; awaiting approval.',
                ];
            }

            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $resolutionSummary,
                'proposed_meta' => $meta,
                'confidence' => $confidence,
                'tokens_used' => 0,
            ]);
        }

        $this->gate->dispatch(
            actionType: 'propose_close',
            ticketId: $ticket->id,
            clientId: $ticket->client_id,
            contentHash: $hash,
            summary: "MCP proposed closing ticket #{$ticket->id}: {$reason}",
            runId: $run->id,
            executor: static function (): void {
                throw new \LogicException('Held-only staged close path must not execute directly.');
            },
            confidence: null,
        );

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Close proposed for cockpit approval.',
        ];
    }

    /**
     * Resolve the close_ticket target status: defaults to Closed when omitted, and only a
     * TERMINAL status may pass (validated with TicketStatus::isTerminal(), never a hardcoded
     * list). Returns null for any non-terminal or unrecognized value.
     */
    private function closeTargetStatus(mixed $value): ?TicketStatus
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return TicketStatus::Closed;
        }

        $status = $this->ticketStatusFrom($value);
        if ($status === null || ! $status->isTerminal()) {
            return null;
        }

        return $status;
    }

    /**
     * Map a close_ticket confidence value to a representative float in the band the
     * CloseBandEvaluator reads. DORMANT — this only records the value; it never enables an
     * automatic close. STRICT exact membership of the published schema enum [high, medium, low]
     * (McpToolRegistry) — no lowercasing or trimming: a published schema is an execution
     * boundary, so "HIGH", " high ", a non-string, or null are OUT of the enum and return the
     * null sentinel. That sentinel means "not a valid enum member", NOT "bypass":
     * resolveCloseConfidence() turns it into a REJECTED call for a SUPPLIED value; only an
     * OMITTED key bypasses the band (architecture psa-d9ayt R3).
     */
    private function closeConfidenceFloat(mixed $value): ?float
    {
        // match() compares with === , so only the exact enum strings map to a band; every other
        // value (wrong case, padded, non-string, null) falls through to the null sentinel.
        return match ($value) {
            'high' => 0.95,
            'medium' => 0.75,
            'low' => 0.55,
            default => null,
        };
    }

    /**
     * Resolve the close_ticket confidence argument, distinguishing OMITTED (a legitimate band
     * bypass → null) from SUPPLIED-BUT-INVALID (a caller bug → error). A malformed confidence
     * must never silently collapse to null: that would turn bad input into the exact value that
     * bypasses the auto-close calibration band (architecture REVISE, psa-d9ayt). Returns a
     * float|null on success, or an ['error' => string] array to reject before any mutation.
     *
     * @param  array<string, mixed>  $arguments
     * @return float|null|array<string, string>
     */
    private function resolveCloseConfidence(array $arguments): float|null|array
    {
        // OMITTING confidence (key absent) is the sanctioned band bypass (owner policy).
        // A SUPPLIED value — INCLUDING an explicit null — is an input that must match the
        // published enum [high, medium, low]; anything else (null, a float string, garbage)
        // is REJECTED outright, never silently coerced to the bypass. A supplied confidence:null
        // is NOT in the non-null enum, so it must not fail open past the enum/calibration band
        // (security + architecture psa-d9ayt R2). Reject the call over guessing a band.
        if (! array_key_exists('confidence', $arguments)) {
            return null; // omitted → band bypass
        }

        $float = $this->closeConfidenceFloat($arguments['confidence']);
        if ($float === null) {
            return ['error' => 'confidence must be one of: high, medium, low — or omit it entirely to bypass the calibration band. A supplied null is not valid.'];
        }

        return $float;
    }

    /**
     * psa-y4ft.1: an autonomous DIRECT close must be as trivially reversible as an
     * operator-approved held close. Record the executed close as a Done direct_close
     * run — the cockpit's "Closed directly by the agent" lane reads these and offers
     * one-click Reopen (TechnicianCockpitController::reopenDirectClose). Anchored on
     * the close's status-change note id: one run per physical close event, so a
     * re-close after a human reopen gets a fresh card instead of colliding with the
     * reversed (Denied) run, which keeps its veto signal for calibration.
     */
    private function recordDirectCloseUndoCard(Ticket $ticket, string $reason, string $actorLabel): void
    {
        $statusNoteId = TicketNote::query()
            ->where('ticket_id', $ticket->id)
            ->where('note_type', NoteType::StatusChange->value)
            ->where('status_to', TicketStatus::Closed->value)
            ->where('author_id', TechnicianConfig::requiredAiActorUserId())
            ->latest('id')
            ->value('id');

        // changeStatus always writes the close note; without it there is nothing
        // safe to anchor a reopen on — skip the card rather than fail a close that
        // already executed.
        if ($statusNoteId === null) {
            return;
        }

        TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => 'direct_close',
                'content_hash' => hash('sha256', 'direct_close:'.$ticket->id.':'.$statusNoteId),
            ],
            [
                'client_id' => $ticket->client_id,
                'state' => TechnicianRunState::Done,
                'proposed_content' => $reason,
                'proposed_meta' => [
                    'status_note_id' => (int) $statusNoteId,
                    'drafted_by' => $actorLabel,
                ],
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );
    }

    /**
     * A specific, learnable reason a DIRECT ->Closed was refused, mirroring the
     * facts CloseAutoEligibility fails closed on so Chet reads it and moves on
     * (Charlie: "return a failure so the agent knows") instead of retrying blind.
     */
    private function directCloseIneligibleReason(Ticket $ticket): string
    {
        return match (true) {
            $ticket->status === TicketStatus::Closed => "Ticket #{$ticket->id} is already closed — nothing to do.",
            in_array($ticket->status, [TicketStatus::New, TicketStatus::InProgress], true) => "Cannot close ticket #{$ticket->id}: it is still awaiting us ({$ticket->status->label()}). Resolve it first, or leave it open.",
            $ticket->status === TicketStatus::PendingThirdParty => "Cannot close ticket #{$ticket->id}: it is pending a third party (vendor-blocked, not abandoned). Leave it open.",
            default => "Cannot close ticket #{$ticket->id}: there is recent client activity — leaving it open so we don't close over a live reply.",
        };
    }

    /**
     * True iff a close is already PENDING (awaiting_approval) for this ticket via the
     * held propose_close path. The direct set_ticket_status close/resolve must defer
     * to it rather than route around the review — the TICKET-level dedup mirror of the
     * propose_close guard (psa-y4ft #177), keyed on the ticket, not the reason text.
     * A terminal prior proposal (denied/superseded/done) does not block.
     */
    private function hasPendingProposedClose(Ticket $ticket): bool
    {
        return TechnicianRun::query()
            ->where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->exists();
    }

    /** @return array<string, mixed> */
    private function assignTicket(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        $reason = $this->optionalString($arguments, 'reason');
        $userId = $this->nullableUserId($arguments['user_id'] ?? null);
        if (($arguments['user_id'] ?? null) !== null && $userId === null) {
            return ['error' => 'user_id must be a positive integer or null'];
        }

        if ($userId !== null && ! User::whereKey($userId)->exists()) {
            return ['error' => 'User not found'];
        }

        $updated = $this->ticketService->assignTicket($ticket, $userId, TechnicianConfig::requiredAiActorUserId());

        $summary = $userId === null
            ? 'Ticket unassigned'.($reason ? ': '.$reason : '.')
            : 'Ticket assigned to user #'.$userId.($reason ? ': '.$reason : '.');

        $this->auditDirectExecution(
            'assign_ticket',
            $updated,
            $actorLabel,
            $this->mutationContentHash('assign_ticket', $updated->id, ['user_id' => $userId, 'reason' => $reason]),
            $summary,
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'ticket_id' => $updated->id,
            'ticket_display_id' => $updated->display_id,
            'assignee_id' => $updated->assignee_id,
            'message' => $userId === null ? 'Ticket unassigned.' : 'Ticket assigned.',
        ];
    }

    /** @return array<string, mixed> */
    private function assignAsset(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        $assetId = $this->positiveInteger($arguments['asset_id'] ?? null);
        if ($assetId === null) {
            return ['error' => 'asset_id is required'];
        }

        $asset = Asset::find($assetId);
        if (! $asset) {
            return ['error' => 'Asset not found'];
        }

        if ((int) $asset->client_id !== (int) $ticket->client_id) {
            return ['error' => 'Asset does not belong to this client; different client boundary enforced.'];
        }

        $isPrimary = (bool) ($arguments['is_primary'] ?? false);
        $reason = $this->optionalString($arguments, 'reason');

        $ticket->assets()->syncWithoutDetaching([
            $asset->id => ['is_primary' => $isPrimary],
        ]);

        $this->auditDirectExecution(
            'assign_asset',
            $ticket,
            $actorLabel,
            $this->mutationContentHash('assign_asset', $ticket->id, ['asset_id' => $asset->id, 'is_primary' => $isPrimary, 'reason' => $reason]),
            'Asset '.$asset->id.' linked to ticket'.($reason ? ': '.$reason : '.'),
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'asset_id' => $asset->id,
            'is_primary' => $isPrimary,
            'message' => 'Asset linked.',
        ];
    }

    /** @return array<string, mixed> */
    private function unassignAsset(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        $assetId = $this->positiveInteger($arguments['asset_id'] ?? null);
        if ($assetId === null) {
            return ['error' => 'asset_id is required'];
        }

        $asset = Asset::find($assetId);
        if (! $asset) {
            return ['error' => 'Asset not found'];
        }

        if ((int) $asset->client_id !== (int) $ticket->client_id) {
            return ['error' => 'Asset does not belong to this client; different client boundary enforced.'];
        }

        $reason = $this->optionalString($arguments, 'reason');
        $ticket->assets()->detach($asset->id);

        $this->auditDirectExecution(
            'unassign_asset',
            $ticket,
            $actorLabel,
            $this->mutationContentHash('unassign_asset', $ticket->id, ['asset_id' => $asset->id, 'reason' => $reason]),
            'Asset '.$asset->id.' unlinked from ticket'.($reason ? ': '.$reason : '.'),
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'asset_id' => $asset->id,
            'message' => 'Asset unlinked.',
        ];
    }

    /** @return array<string, mixed> */
    private function setTicketContact(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        $contactId = $this->positiveInteger($arguments['contact_id'] ?? null);
        if ($contactId === null) {
            return ['error' => 'contact_id is required'];
        }

        $contact = Person::find($contactId);
        if (! $contact) {
            return ['error' => 'Contact not found'];
        }

        if ((int) $contact->client_id !== (int) $ticket->client_id) {
            return ['error' => 'Contact does not belong to this client; different client boundary enforced.'];
        }

        // psa-eu5la R2: the write choke point where routing harm actually lands. Refuse a
        // DEACTIVATED contact by default; allow_inactive_contact=true is the audited
        // opt-in for legitimate historical (offboarding/billing/audit) references.
        if ($guard = $this->guardInactiveContact($contact, $arguments)) {
            return $guard;
        }

        $reason = $this->optionalString($arguments, 'reason');
        $before = $ticket->contact_id;
        $ticket->update(['contact_id' => $contact->id]);

        $this->auditDirectExecution(
            'set_ticket_contact',
            $ticket,
            $actorLabel,
            $this->mutationContentHash('set_ticket_contact', $ticket->id, ['contact_id' => $contact->id, 'reason' => $reason]),
            'Contact changed from '.($before ?? 'none').' to '.$contact->id.($contact->is_active ? '' : ' [inactive contact — allow_inactive_contact opt-in]').($reason ? ': '.$reason : '.'),
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'contact_id' => $contact->id,
            'message' => 'Contact updated.',
        ];
    }

    /** @return array<string, mixed> */
    private function moveTicketToClient(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        $newClientId = $this->positiveInteger($arguments['new_client_id'] ?? null);
        if ($newClientId === null) {
            return ['error' => 'new_client_id is required'];
        }

        $confirm = $this->optionalString($arguments, 'confirm_client_name');
        $newClient = Client::find($newClientId);
        if (! $newClient) {
            return ['error' => 'Client not found'];
        }

        if (! $this->confirmClientMatches($newClient, $confirm)) {
            return ['error' => 'The typed confirm_client_name does not match the target client. Ticket move cancelled.'];
        }

        $newContactId = $this->positiveInteger($arguments['new_contact_id'] ?? null);
        if (($arguments['new_contact_id'] ?? null) !== null && $newContactId === null) {
            return ['error' => 'new_contact_id must be a positive integer or null'];
        }

        // psa-eu5la R2 / psa-iahn6: don't move a ticket onto a DEACTIVATED new contact by
        // default (moveToClient revalidates client ownership; this adds the active guard).
        // allow_inactive_contact=true is the audited opt-in for a deliberate historical move.
        $newContact = $newContactId !== null ? Person::find($newContactId) : null;
        if ($newContact !== null && ($guard = $this->guardInactiveContact($newContact, $arguments))) {
            return $guard;
        }

        $reason = $this->optionalString($arguments, 'reason');
        $detachedAssets = $ticket->assets()->where('assets.client_id', $ticket->client_id)->pluck('assets.id')->all();

        try {
            $this->ticketService->moveToClient(
                $ticket,
                $newClient->id,
                $newContactId,
                TechnicianConfig::requiredAiActorUserId(),
            );
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        $moved = $ticket->fresh(['assets', 'contact']);
        $this->auditDirectExecution(
            'move_ticket_to_client',
            $moved,
            $actorLabel,
            $this->mutationContentHash('move_ticket_to_client', $moved->id, [
                'new_client_id' => $newClient->id,
                'new_contact_id' => $newContactId,
                'reason' => $reason,
            ]),
            'Ticket moved to client #'.$newClient->id.'. Detached assets: '.count($detachedAssets).($newContact && ! $newContact->is_active ? ' [inactive contact — allow_inactive_contact opt-in]' : '').($reason ? ': '.$reason : '.'),
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'ticket_id' => $moved->id,
            'ticket_display_id' => $moved->display_id,
            'client_id' => $moved->client_id,
            'contact_id' => $moved->contact_id,
            'detached_asset_ids' => array_values($detachedAssets),
            'message' => 'Ticket moved.',
        ];
    }

    /**
     * create_client — global write. Creates a new PSA client from the same
     * fields the web create form accepts (minus site_notes/credentials/stage
     * and integration IDs). No client scope: a new client has no parent.
     *
     * @return array<string, mixed>
     */
    private function createClient(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $validated = $this->validateClientPayload($arguments, isCreate: true);
        if (isset($validated['error'])) {
            return $validated;
        }

        // Pre-creation payload hash (NOT the old post-creation hash that baked in
        // the new client id and could never match a prior row). Honest refusal on
        // a recent identical create — a client create is not a replayable idempotent op.
        $contentHash = $this->createClientContentHash($validated);
        if ($this->duplicateCreateClientRecently($contentHash)) {
            return ['error' => 'A client with identical details was already created recently. Change at least one field, or use find_clients to check for an existing match, before retrying.'];
        }

        // Create + audit atomically: the audit row is now the dedup guard's only
        // memory, so a create that isn't recorded would let an identical retry slip
        // through. Mirrors create_ticket's transactional create+audit.
        $client = DB::transaction(function () use ($validated, $actorLabel, $contentHash): Client {
            $client = $this->clientService->createClient($validated);

            $this->auditEntityExecution(
                'create_client',
                'client',
                (int) $client->id,
                (int) $client->id,
                $actorLabel,
                $contentHash,
                'Client created: '.$client->name.'.',
                TechnicianConfig::requiredAiActorUserId(),
            );

            return $client;
        });

        return [
            'success' => true,
            'client_id' => $client->id,
            'name' => $client->name,
            'message' => 'Client created.',
        ];
    }

    /**
     * update_client — entity-scoped. client_id is the target (derived server-side
     * by the controller). Site notes and credentials are handled by their own
     * tools and are rejected here.
     *
     * @return array<string, mixed>
     */
    private function updateClient(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $client = Client::find($clientId);
        if (! $client) {
            return ['error' => 'Client not found'];
        }

        $validated = $this->validateClientPayload($arguments, isCreate: false);
        if (isset($validated['error'])) {
            return $validated;
        }

        if (array_key_exists('reseller_id', $validated) && (int) $validated['reseller_id'] === (int) $client->id) {
            return ['error' => 'A client cannot be its own reseller.'];
        }

        $updated = $this->clientService->updateClient($client, $validated);

        $this->auditEntityExecution(
            'update_client',
            'client',
            (int) $updated->id,
            (int) $updated->id,
            $actorLabel,
            $this->mutationContentHash('update_client', (int) $updated->id, $validated),
            'Client updated ('.implode(', ', array_keys($validated)).').',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'client_id' => $updated->id,
            'name' => $updated->name,
            'message' => 'Client updated.',
        ];
    }

    /**
     * update_client_site_notes — entity-scoped. Passes expected_updated_at
     * through to the optimistic-concurrency guard; a stale write surfaces the
     * service RuntimeException as a clean tool error.
     *
     * @return array<string, mixed>
     */
    private function updateClientSiteNotes(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $client = Client::find($clientId);
        if (! $client) {
            return ['error' => 'Client not found'];
        }

        $unexpected = array_values(array_diff(array_keys($arguments), ['site_notes', 'expected_updated_at']));
        if ($unexpected !== []) {
            return ['error' => 'update_client_site_notes accepts only site_notes and expected_updated_at.'];
        }

        if (! array_key_exists('site_notes', $arguments)) {
            return ['error' => 'site_notes is required.'];
        }

        $siteNotes = $arguments['site_notes'];
        if ($siteNotes !== null && ! is_string($siteNotes)) {
            return ['error' => 'site_notes must be a string or null.'];
        }

        $expectedUpdatedAt = $this->optionalString($arguments, 'expected_updated_at');
        if ($expectedUpdatedAt !== null
            && Validator::make(['expected_updated_at' => $expectedUpdatedAt], ['expected_updated_at' => ['date']])->fails()) {
            return ['error' => 'expected_updated_at must be a valid ISO-8601 timestamp.'];
        }

        try {
            $this->clientService->updateSiteNotes($client, $siteNotes, $expectedUpdatedAt, TechnicianConfig::requiredAiActorUserId());
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $length = is_string($siteNotes) ? mb_strlen($siteNotes) : 0;
        $this->auditEntityExecution(
            'update_client_site_notes',
            'client',
            (int) $client->id,
            (int) $client->id,
            $actorLabel,
            $this->mutationContentHash('update_client_site_notes', (int) $client->id, ['length' => $length], $expectedUpdatedAt),
            'Client site notes updated ('.$length.' chars).',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'client_id' => $client->id,
            'message' => 'Client site notes updated.',
        ];
    }

    /**
     * delete_client — entity-scoped, typed-confirm. Requires the exact client
     * name; ClientService::deleteClient blocks on open tickets / active
     * contracts / unpaid invoices (surfaced as clean tool errors) and soft-deletes.
     *
     * @return array<string, mixed>
     */
    private function deleteClient(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $client = Client::find($clientId);
        if (! $client) {
            return ['error' => 'Client not found'];
        }

        $unexpected = array_values(array_diff(array_keys($arguments), ['confirm_client_name', 'reason']));
        if ($unexpected !== []) {
            return ['error' => 'delete_client accepts only confirm_client_name and reason.'];
        }

        $confirm = $this->optionalString($arguments, 'confirm_client_name');
        if (! $this->confirmClientMatches($client, $confirm)) {
            return ['error' => 'The typed confirm_client_name does not match the target client. Deletion cancelled.'];
        }

        $reason = $this->optionalString($arguments, 'reason');

        try {
            $this->clientService->deleteClient($client);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $this->auditEntityExecution(
            'delete_client',
            'client',
            (int) $client->id,
            (int) $client->id,
            $actorLabel,
            $this->mutationContentHash('delete_client', (int) $client->id, ['name' => $client->name], $reason),
            'Client soft-deleted: '.$client->name.($reason ? ' — '.$reason : '').'.',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'client_id' => $client->id,
            'message' => 'Client deleted.',
        ];
    }

    /**
     * Allowlist + validate a client create/update payload against the same
     * rules as ClientStoreRequest / ClientUpdateRequest, minus site_notes,
     * credentials, stage, and all integration IDs (never client-editable via MCP).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed> Validated data, or ['error' => string].
     */
    private function validateClientPayload(array $arguments, bool $isCreate): array
    {
        $allowed = ['name', 'notes', 'phone', 'email', 'website', 'address_line1', 'address_line2', 'city', 'state', 'postcode', 'is_active', 'primary_tech_id', 'reseller_id'];
        $unexpected = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unexpected !== []) {
            return ['error' => 'This tool accepts only: '.implode(', ', $allowed).'.'];
        }

        $validator = Validator::make($arguments, [
            'name' => $isCreate ? ['required', 'string', 'max:255'] : ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:50'],
            'postcode' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'primary_tech_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'reseller_id' => ['sometimes', 'nullable', 'integer', 'exists:clients,id,deleted_at,NULL'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->first()];
        }

        $validated = $validator->validated();

        if (! $isCreate && $validated === []) {
            return ['error' => 'update_client requires at least one field to change.'];
        }

        return $validated;
    }

    /**
     * create_contact — parent-scoped. client_id is the required parent (supplied
     * by the controller). Wraps PersonService::createPerson (primary-demotion +
     * additional-email sync happen in the service).
     *
     * @return array<string, mixed>
     */
    private function createContact(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $validated = $this->validatePersonPayload($arguments, isCreate: true);
        if (isset($validated['error'])) {
            return $validated;
        }

        $validated['client_id'] = $clientId;
        $person = $this->personService->createPerson($validated);

        $this->auditEntityExecution(
            'create_contact',
            'person',
            (int) $person->id,
            (int) $person->client_id,
            $actorLabel,
            $this->mutationContentHash('create_contact', (int) $person->id, $validated),
            'Contact created: '.$this->contactDisplayName($person).' (client #'.$person->client_id.').',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'contact_id' => $person->id,
            'client_id' => $person->client_id,
            'name' => trim((string) $person->full_name),
            'message' => 'Contact created.',
        ];
    }

    /**
     * update_contact — contact-scoped. The controller derives client scope from
     * contact_id and forbids a stray client_id. Wraps PersonService::updatePerson.
     *
     * @return array<string, mixed>
     */
    private function updateContact(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $person = $this->personForContact($arguments);
        if (is_array($person)) {
            return $person;
        }

        $fields = $arguments;
        unset($fields['contact_id']);
        $validated = $this->validatePersonPayload($fields, isCreate: false);
        if (isset($validated['error'])) {
            return $validated;
        }

        $updated = $this->personService->updatePerson($person, $validated);

        $this->auditEntityExecution(
            'update_contact',
            'person',
            (int) $updated->id,
            (int) $updated->client_id,
            $actorLabel,
            $this->mutationContentHash('update_contact', (int) $updated->id, $validated),
            'Contact updated ('.implode(', ', array_keys($validated)).').',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'contact_id' => $updated->id,
            'client_id' => $updated->client_id,
            'name' => trim((string) $updated->full_name),
            'message' => 'Contact updated.',
        ];
    }

    /**
     * set_primary_contact — contact-scoped convenience. Promotes the contact to
     * primary; the service demotes the prior primary for that client.
     *
     * @return array<string, mixed>
     */
    private function setPrimaryContact(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $person = $this->personForContact($arguments);
        if (is_array($person)) {
            return $person;
        }

        $unexpected = array_values(array_diff(array_keys($arguments), ['contact_id', 'allow_inactive_contact']));
        if ($unexpected !== []) {
            return ['error' => 'set_primary_contact accepts only contact_id and allow_inactive_contact.'];
        }

        // psa-eu5la R2 / psa-iahn6: promoting a DEACTIVATED person to primary is refused
        // by default; the audited allow_inactive_contact opt-in permits it deliberately.
        if ($guard = $this->guardInactiveContact($person, $arguments)) {
            return $guard;
        }

        $updated = $this->personService->updatePerson($person, ['is_primary' => true]);

        $this->auditEntityExecution(
            'set_primary_contact',
            'person',
            (int) $updated->id,
            (int) $updated->client_id,
            $actorLabel,
            $this->mutationContentHash('set_primary_contact', (int) $updated->id, ['is_primary' => true]),
            'Contact set as primary: '.$this->contactDisplayName($updated).($updated->is_active ? '' : ' [inactive contact — allow_inactive_contact opt-in]').'.',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'contact_id' => $updated->id,
            'client_id' => $updated->client_id,
            'message' => 'Primary contact set.',
        ];
    }

    /**
     * move_contact_to_client — contact-scoped, typed-confirm. Reparents the
     * contact to the target client (via updatePerson client_id change).
     *
     * @return array<string, mixed>
     */
    private function moveContactToClient(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $person = $this->personForContact($arguments);
        if (is_array($person)) {
            return $person;
        }

        $unexpected = array_values(array_diff(array_keys($arguments), ['contact_id', 'new_client_id', 'confirm_client_name', 'reason']));
        if ($unexpected !== []) {
            return ['error' => 'move_contact_to_client accepts only contact_id, new_client_id, confirm_client_name, and reason.'];
        }

        $newClientId = $this->positiveInteger($arguments['new_client_id'] ?? null);
        if ($newClientId === null) {
            return ['error' => 'new_client_id is required'];
        }

        $newClient = Client::find($newClientId);
        if (! $newClient) {
            return ['error' => 'Client not found'];
        }

        $confirm = $this->optionalString($arguments, 'confirm_client_name');
        if (! $this->confirmClientMatches($newClient, $confirm)) {
            return ['error' => 'The typed confirm_client_name does not match the target client. Contact move cancelled.'];
        }

        $reason = $this->optionalString($arguments, 'reason');
        $oldClientId = (int) $person->client_id;

        // A same-client "move" is a no-op — reject it so we never report detach
        // counts for a move that didn't change client_id (updatePerson would skip
        // the pivot reconcile since client_id is unchanged).
        if ($newClient->id === $oldClientId) {
            return ['error' => 'Contact already belongs to that client; nothing to move.'];
        }

        // Count the links that will become cross-client BEFORE updatePerson detaches them.
        $pivots = $this->personService->crossClientPivotCounts($person, $newClient->id);

        // Reparent as a non-primary in the target client — a moved contact must
        // not silently become a second primary there (the target keeps its own).
        $updated = $this->personService->updatePerson($person, ['client_id' => $newClient->id, 'is_primary' => false]);

        $this->auditEntityExecution(
            'move_contact_to_client',
            'person',
            (int) $updated->id,
            (int) $updated->client_id,
            $actorLabel,
            $this->mutationContentHash('move_contact_to_client', (int) $updated->id, ['new_client_id' => $newClient->id], $reason),
            'Contact '.$this->contactDisplayName($updated).' moved from client #'.$oldClientId.' to #'.$newClient->id
                .($pivots['contracts'] + $pivots['assets'] > 0 ? ' (detached '.$pivots['contracts'].' contract, '.$pivots['assets'].' device link(s))' : '')
                .($reason ? ' — '.$reason : '').'.',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'contact_id' => $updated->id,
            'client_id' => $updated->client_id,
            'contracts_detached' => $pivots['contracts'],
            'assets_detached' => $pivots['assets'],
            'message' => 'Contact moved.'.($pivots['contracts'] + $pivots['assets'] > 0
                ? ' Detached '.$pivots['contracts'].' contract and '.$pivots['assets'].' device link(s) that pointed at a different client.'
                : ''),
        ];
    }

    /**
     * delete_contact — contact-scoped, typed-confirm on the full name.
     * PersonService::deletePerson blocks on open tickets (surfaced as a clean
     * error) and soft-deletes.
     *
     * @return array<string, mixed>
     */
    private function deleteContact(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $person = $this->personForContact($arguments);
        if (is_array($person)) {
            return $person;
        }

        $unexpected = array_values(array_diff(array_keys($arguments), ['contact_id', 'confirm_contact_name', 'reason']));
        if ($unexpected !== []) {
            return ['error' => 'delete_contact accepts only contact_id, confirm_contact_name, and reason.'];
        }

        $confirm = $this->optionalString($arguments, 'confirm_contact_name');
        if (! $this->confirmContactMatches($person, $confirm)) {
            return ['error' => 'The typed confirm_contact_name does not match the target contact. Deletion cancelled.'];
        }

        $reason = $this->optionalString($arguments, 'reason');
        $clientId = (int) $person->client_id;
        $display = $this->contactDisplayName($person);

        try {
            $this->personService->deletePerson($person);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $this->auditEntityExecution(
            'delete_contact',
            'person',
            (int) $person->id,
            $clientId,
            $actorLabel,
            $this->mutationContentHash('delete_contact', (int) $person->id, ['name' => $person->full_name], $reason),
            'Contact soft-deleted: '.$display.($reason ? ' — '.$reason : '').'.',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'contact_id' => $person->id,
            'client_id' => $clientId,
            'message' => 'Contact deleted.',
        ];
    }

    /** @return Person|array<string, string> */
    /**
     * Strict opt-in for referencing a DEACTIVATED contact on a write (psa-eu5la R2).
     * A safety opt-in must require a REAL boolean — "yes"/"1"/1 must not permit routing
     * to an offboarded contact — so only a literal boolean true opts in.
     *
     * @param  array<string, mixed>  $arguments
     */
    private static function allowsInactiveContact(array $arguments): bool
    {
        return ($arguments['allow_inactive_contact'] ?? false) === true;
    }

    /**
     * The uniform active-contact write contract (psa-eu5la R2 / psa-iahn6): refuse a
     * deactivated/offboarded contact on any contact-write UNLESS the caller passes an
     * explicit, audited allow_inactive_contact=true — offboarding/billing/audit
     * legitimately reference a historical contact. Returns an error array to short-
     * circuit, or null to proceed.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function guardInactiveContact(Person $contact, array $arguments): ?array
    {
        if ($contact->is_active || self::allowsInactiveContact($arguments)) {
            return null;
        }

        return ['error' => 'Contact '.$contact->id.' is deactivated/offboarded; routing to a deactivated contact is refused by default. Pass allow_inactive_contact=true to deliberately reference this historical contact (e.g. offboarding, billing, or audit).'];
    }

    private function personForContact(array $arguments): Person|array
    {
        $contactId = $this->positiveInteger($arguments['contact_id'] ?? null);
        if ($contactId === null) {
            return ['error' => 'contact_id is required'];
        }

        $person = Person::find($contactId);
        if (! $person) {
            return ['error' => 'Contact not found'];
        }

        return $person;
    }

    private function confirmContactMatches(Person $person, ?string $typed): bool
    {
        if ($typed === null) {
            return false;
        }

        return strcasecmp(trim($typed), trim((string) $person->full_name)) === 0;
    }

    private function contactDisplayName(Person $person): string
    {
        $name = trim((string) $person->full_name);

        return $name !== '' ? $name : 'contact #'.$person->id;
    }

    /**
     * Allowlist + validate a contact create/update payload against the same rules
     * as PersonStore/UpdateRequest. The Person model leaves portal_enabled /
     * password / company_wide_access / cipp_* / mailbox_* mass-assignable, so
     * THIS allowlist is the security boundary — it also excludes department /
     * office_location (CIPP-sync-only, absent from the FormRequests).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed> Validated data, or ['error' => string].
     */
    private function validatePersonPayload(array $arguments, bool $isCreate): array
    {
        $allowed = ['first_name', 'last_name', 'email', 'phone', 'mobile', 'job_title', 'notes', 'person_type', 'is_primary', 'is_active', 'additional_emails'];
        $unexpected = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unexpected !== []) {
            return ['error' => 'This tool accepts only: '.implode(', ', $allowed).'.'];
        }

        $validator = Validator::make($arguments, [
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:20'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'person_type' => ['sometimes', 'required', 'string', Rule::in(array_column(\App\Enums\PersonType::cases(), 'value'))],
            'is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'additional_emails' => ['sometimes', 'nullable', 'array', 'max:10'],
            'additional_emails.*.email' => ['required', 'email', 'max:255'],
            'additional_emails.*.label' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->first()];
        }

        $validated = $validator->validated();

        if (! $isCreate && $validated === []) {
            return ['error' => 'update_contact requires at least one field to change.'];
        }

        return $validated;
    }

    /**
     * create_asset — parent-scoped. client_id is the required parent (supplied
     * by the controller). Wraps AssetService::createAsset.
     *
     * @return array<string, mixed>
     */
    private function createAsset(array $arguments, int $clientId, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $validated = $this->validateAssetPayload($arguments, isCreate: true);
        if (isset($validated['error'])) {
            return $validated;
        }

        $validated['client_id'] = $clientId;
        $asset = $this->assetService->createAsset($validated);

        $this->auditEntityExecution(
            'create_asset',
            'asset',
            (int) $asset->id,
            $asset->client_id,
            $actorLabel,
            $this->mutationContentHash('create_asset', (int) $asset->id, $validated),
            'Asset created: '.$asset->name.' (client #'.$asset->client_id.').',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'asset_id' => $asset->id,
            'client_id' => $asset->client_id,
            'name' => $asset->name,
            'message' => 'Asset created.',
        ];
    }

    /**
     * update_asset — asset-scoped. The controller derives scope from asset_id
     * and forbids a stray client_id. Wraps AssetService::updateAsset. Only the
     * manual AssetUpdateRequest fields are accepted (never vendor/RMM fields).
     *
     * @return array<string, mixed>
     */
    private function updateAsset(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $asset = $this->assetForId($arguments);
        if (is_array($asset)) {
            return $asset;
        }

        $fields = $arguments;
        unset($fields['asset_id']);
        $validated = $this->validateAssetPayload($fields, isCreate: false);
        if (isset($validated['error'])) {
            return $validated;
        }

        $updated = $this->assetService->updateAsset($asset, $validated);

        $this->auditEntityExecution(
            'update_asset',
            'asset',
            (int) $updated->id,
            $updated->client_id,
            $actorLabel,
            $this->mutationContentHash('update_asset', (int) $updated->id, $validated),
            'Asset updated ('.implode(', ', array_keys($validated)).').',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'asset_id' => $updated->id,
            'client_id' => $updated->client_id,
            'name' => $updated->name,
            'message' => 'Asset updated.',
        ];
    }

    /**
     * retire_asset — asset-scoped, typed-confirm on the asset name.
     * AssetService::deleteAsset blocks on open tickets (surfaced as a clean
     * error) and is the only sanctioned soft-delete path.
     *
     * @return array<string, mixed>
     */
    private function retireAsset(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $asset = $this->assetForId($arguments);
        if (is_array($asset)) {
            return $asset;
        }

        $unexpected = array_values(array_diff(array_keys($arguments), ['asset_id', 'confirm_asset_name', 'reason']));
        if ($unexpected !== []) {
            return ['error' => 'retire_asset accepts only asset_id, confirm_asset_name, and reason.'];
        }

        $confirm = $this->optionalString($arguments, 'confirm_asset_name');
        if (! $this->confirmAssetMatches($asset, $confirm)) {
            return ['error' => 'The typed confirm_asset_name does not match the target asset. Retire cancelled.'];
        }

        $reason = $this->optionalString($arguments, 'reason');
        $clientId = $asset->client_id;
        $name = (string) $asset->name;

        try {
            $this->assetService->deleteAsset($asset);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $this->auditEntityExecution(
            'retire_asset',
            'asset',
            (int) $asset->id,
            $clientId,
            $actorLabel,
            $this->mutationContentHash('retire_asset', (int) $asset->id, ['name' => $name], $reason),
            'Asset retired: '.$name.($reason ? ' — '.$reason : '').'.',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'asset_id' => $asset->id,
            'client_id' => $clientId,
            'message' => 'Asset retired.',
        ];
    }

    /**
     * restore_asset — asset-scoped (operates on a soft-deleted asset). Mirrors
     * AssetController::restore: withTrashed find, restore, reactivate.
     *
     * @return array<string, mixed>
     */
    private function restoreAsset(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $unexpected = array_values(array_diff(array_keys($arguments), ['asset_id']));
        if ($unexpected !== []) {
            return ['error' => 'restore_asset accepts only asset_id.'];
        }

        $assetId = $this->positiveInteger($arguments['asset_id'] ?? null);
        if ($assetId === null) {
            return ['error' => 'asset_id is required'];
        }

        $asset = Asset::withTrashed()->find($assetId);
        if (! $asset) {
            return ['error' => 'Asset not found'];
        }
        if (! $asset->trashed()) {
            return ['error' => 'Asset is not retired; nothing to restore.'];
        }

        $asset->restore();
        $asset->is_active = true;
        $asset->save();

        $this->auditEntityExecution(
            'restore_asset',
            'asset',
            (int) $asset->id,
            $asset->client_id,
            $actorLabel,
            $this->mutationContentHash('restore_asset', (int) $asset->id, ['restored' => true]),
            'Asset restored: '.$asset->name.'.',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'asset_id' => $asset->id,
            'client_id' => $asset->client_id,
            'message' => 'Asset restored.',
        ];
    }

    /**
     * link_asset_user — asset-scoped. Mirrors AssetController::addUser: enforces
     * person.client_id === asset.client_id, dedups, attaches a manual pivot.
     *
     * @return array<string, mixed>
     */
    private function linkAssetUser(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $asset = $this->assetForId($arguments);
        if (is_array($asset)) {
            return $asset;
        }

        $unexpected = array_values(array_diff(array_keys($arguments), ['asset_id', 'person_id']));
        if ($unexpected !== []) {
            return ['error' => 'link_asset_user accepts only asset_id and person_id.'];
        }

        $personId = $this->positiveInteger($arguments['person_id'] ?? null);
        if ($personId === null) {
            return ['error' => 'person_id is required'];
        }

        // Cross-client guard: the person must belong to the asset's client.
        $person = Person::where('id', $personId)->where('client_id', $asset->client_id)->first();
        if (! $person) {
            return ['error' => 'Person not found or does not belong to the asset client.'];
        }

        // Idempotent if already linked.
        if ($asset->users()->where('person_id', $personId)->exists()) {
            return [
                'success' => true,
                'idempotent' => true,
                'asset_id' => $asset->id,
                'person_id' => $personId,
                'message' => 'Person already linked to this asset.',
            ];
        }

        $asset->users()->attach($personId, ['is_primary' => false, 'assignment_source' => 'manual', 'last_seen_at' => null]);

        $this->auditEntityExecution(
            'link_asset_user',
            'asset',
            (int) $asset->id,
            $asset->client_id,
            $actorLabel,
            $this->mutationContentHash('link_asset_user', (int) $asset->id, ['person_id' => $personId]),
            'Linked person #'.$personId.' to asset '.$asset->name.'.',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'asset_id' => $asset->id,
            'person_id' => $personId,
            'message' => 'Person linked to asset.',
        ];
    }

    /**
     * unlink_asset_user — asset-scoped. Mirrors AssetController::removeUser
     * (pivot detach); idempotent when the person is not linked.
     *
     * @return array<string, mixed>
     */
    private function unlinkAssetUser(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $asset = $this->assetForId($arguments);
        if (is_array($asset)) {
            return $asset;
        }

        $unexpected = array_values(array_diff(array_keys($arguments), ['asset_id', 'person_id']));
        if ($unexpected !== []) {
            return ['error' => 'unlink_asset_user accepts only asset_id and person_id.'];
        }

        $personId = $this->positiveInteger($arguments['person_id'] ?? null);
        if ($personId === null) {
            return ['error' => 'person_id is required'];
        }

        if (! $asset->users()->where('person_id', $personId)->exists()) {
            return [
                'success' => true,
                'idempotent' => true,
                'asset_id' => $asset->id,
                'person_id' => $personId,
                'message' => 'Person was not linked to this asset.',
            ];
        }

        $asset->users()->detach($personId);

        $this->auditEntityExecution(
            'unlink_asset_user',
            'asset',
            (int) $asset->id,
            $asset->client_id,
            $actorLabel,
            $this->mutationContentHash('unlink_asset_user', (int) $asset->id, ['person_id' => $personId]),
            'Unlinked person #'.$personId.' from asset '.$asset->name.'.',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'asset_id' => $asset->id,
            'person_id' => $personId,
            'message' => 'Person unlinked from asset.',
        ];
    }

    /**
     * set_primary_asset_user — asset-scoped. Mirrors AssetController::setPrimaryUser
     * (demote-then-promote), hardened to require the person be linked first
     * (the controller silently no-ops otherwise).
     *
     * @return array<string, mixed>
     */
    private function setPrimaryAssetUser(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $asset = $this->assetForId($arguments);
        if (is_array($asset)) {
            return $asset;
        }

        $unexpected = array_values(array_diff(array_keys($arguments), ['asset_id', 'person_id']));
        if ($unexpected !== []) {
            return ['error' => 'set_primary_asset_user accepts only asset_id and person_id.'];
        }

        $personId = $this->positiveInteger($arguments['person_id'] ?? null);
        if ($personId === null) {
            return ['error' => 'person_id is required'];
        }

        if (! $asset->users()->where('person_id', $personId)->exists()) {
            return ['error' => 'That person is not linked to this asset; link them first.'];
        }

        DB::table('asset_person')->where('asset_id', $asset->id)->where('is_primary', true)->update(['is_primary' => false]);
        DB::table('asset_person')->where('asset_id', $asset->id)->where('person_id', $personId)->update(['is_primary' => true]);

        $this->auditEntityExecution(
            'set_primary_asset_user',
            'asset',
            (int) $asset->id,
            $asset->client_id,
            $actorLabel,
            $this->mutationContentHash('set_primary_asset_user', (int) $asset->id, ['person_id' => $personId]),
            'Set person #'.$personId.' as primary user of asset '.$asset->name.'.',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'asset_id' => $asset->id,
            'person_id' => $personId,
            'message' => 'Primary asset user set.',
        ];
    }

    /**
     * link_email_to_ticket — intake MANAGE verb. Thin reuse of
     * EmailService::linkEmailToTicket; no reimplementation. The audited
     * summary is built from ids + reason only — never $email->body_text.
     *
     * @return array<string, mixed>
     */
    private function linkEmailToTicket(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        $email = Email::find((int) ($arguments['email_id'] ?? 0));
        if (! $email) {
            return ['error' => 'Email item not found'];
        }

        $ticket = Ticket::find((int) ($arguments['ticket_id'] ?? 0));
        if (! $ticket) {
            return ['error' => 'Ticket not found'];
        }

        // Mutation + audit atomic (the ticket's client_id is nullable — pass it through
        // as ?int, never (int)-cast it to 0 which would violate the audit FK).
        DB::transaction(function () use ($email, $ticket, $actorLabel, $reason): void {
            $this->email->linkEmailToTicket($email, $ticket);
            $this->auditEntityExecution(
                'link_email_to_ticket',
                'email',
                (int) $email->id,
                $ticket->client_id,
                $actorLabel,
                $this->mutationContentHash('link_email_to_ticket', (int) $email->id, ['ticket_id' => $ticket->id], $reason),
                'Email #'.$email->id.' linked to ticket #'.$ticket->id.': '.$reason,
                TechnicianConfig::requiredAiActorUserId(),
            );
        });

        return [
            'success' => true,
            'email_id' => $email->id,
            'ticket_id' => $ticket->id,
            'message' => 'Email linked to ticket.',
        ];
    }

    /**
     * create_ticket_from_email — intake MANAGE verb. Thin reuse of
     * EmailService::autoCreateTicketFromEmail; no reimplementation. Guards
     * client_id !== null (the native method has no such guard — it assumes
     * the caller already resolved the sender). Audited summary is ids +
     * reason only — never $email->body_text.
     *
     * @return array<string, mixed>
     */
    private function createTicketFromEmail(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        $email = Email::find((int) ($arguments['email_id'] ?? 0));
        if (! $email) {
            return ['error' => 'Email item not found'];
        }

        if ($email->client_id === null) {
            return ['error' => 'Email has no resolved client; resolve the sender to a client before creating a ticket.'];
        }

        $ticket = DB::transaction(function () use ($email, $actorLabel, $reason): Ticket {
            $ticket = $this->email->autoCreateTicketFromEmail($email);
            $this->auditEntityExecution(
                'create_ticket_from_email',
                'email',
                (int) $email->id,
                $ticket->client_id,
                $actorLabel,
                $this->mutationContentHash('create_ticket_from_email', (int) $email->id, ['ticket_id' => $ticket->id], $reason),
                'Email #'.$email->id.' created/linked ticket #'.$ticket->id.': '.$reason,
                TechnicianConfig::requiredAiActorUserId(),
            );

            return $ticket;
        });

        return [
            'success' => true,
            'email_id' => $email->id,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'message' => 'Ticket created or linked from email.',
        ];
    }

    /**
     * dismiss_email_item — intake MANAGE verb. Thin reuse of
     * EmailService::dismissEmail; no reimplementation. Audited summary is id
     * + reason only — never $email->body_text.
     *
     * @return array<string, mixed>
     */
    private function dismissEmailItem(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        $email = Email::find((int) ($arguments['email_id'] ?? 0));
        if (! $email) {
            return ['error' => 'Email item not found'];
        }

        $actorId = TechnicianConfig::requiredAiActorUserId();
        DB::transaction(function () use ($email, $actorLabel, $reason, $actorId): void {
            $this->email->dismissEmail($email, $actorId);
            $this->auditEntityExecution(
                'dismiss_email_item',
                'email',
                (int) $email->id,
                $email->client_id,
                $actorLabel,
                $this->mutationContentHash('dismiss_email_item', (int) $email->id, [], $reason),
                'Email #'.$email->id.' dismissed: '.$reason,
                $actorId,
            );
        });

        return [
            'success' => true,
            'email_id' => $email->id,
            'message' => 'Email dismissed.',
        ];
    }

    /**
     * link_call_to_ticket — intake MANAGE verb. Thin reuse of
     * PhoneCallService::linkCallToTicketWithNote; no reimplementation. The
     * audited summary is built from ids + reason only — never
     * $call->transcription.
     *
     * @return array<string, mixed>
     */
    private function linkCallToTicket(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        $call = PhoneCall::find((int) ($arguments['phone_call_id'] ?? 0));
        if (! $call) {
            return ['error' => 'Phone call not found'];
        }

        $ticket = Ticket::find((int) ($arguments['ticket_id'] ?? 0));
        if (! $ticket) {
            return ['error' => 'Ticket not found'];
        }

        // A call a technician already followed up on (in particular, marked spam)
        // is resolved — attaching it to a ticket silently undoes that decision.
        // Same override-invariant as create_ticket_from_call (psa-mgok).
        if ($call->isFollowedUp()) {
            return ['error' => 'Phone call was already followed up on by a technician (followed_up_at is set) — it may have been dismissed as spam. Refusing to link it to a ticket; clear the follow-up state first if this is a genuine request.'];
        }

        DB::transaction(function () use ($call, $ticket, $actorLabel, $reason): void {
            $this->phoneCallService->linkCallToTicketWithNote($call, $ticket->id, "Linked via MCP: {$reason}");
            $this->auditEntityExecution(
                'link_call_to_ticket',
                'phone_call',
                (int) $call->id,
                $ticket->client_id,
                $actorLabel,
                $this->mutationContentHash('link_call_to_ticket', (int) $call->id, ['ticket_id' => $ticket->id], $reason),
                'Phone call #'.$call->id.' linked to ticket #'.$ticket->id.': '.$reason,
                TechnicianConfig::requiredAiActorUserId(),
            );
        });

        return [
            'success' => true,
            'phone_call_id' => $call->id,
            'ticket_id' => $ticket->id,
            'message' => 'Phone call linked to ticket.',
        ];
    }

    /**
     * create_ticket_from_call — intake MANAGE verb. Thin reuse of
     * PhoneCallService::createTicketFromCall (which itself calls
     * linkCallToTicketWithNote internally); no reimplementation. Guards
     * client_id !== null — the native method's docblock states this as a
     * precondition the caller must already satisfy, it does not check it
     * itself. Audited summary is ids + reason only — never
     * $call->transcription.
     *
     * @return array<string, mixed>
     */
    private function createTicketFromCall(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        $call = PhoneCall::find((int) ($arguments['phone_call_id'] ?? 0));
        if (! $call) {
            return ['error' => 'Phone call not found'];
        }

        if ($call->client_id === null) {
            return ['error' => 'Phone call has no resolved client; resolve the caller to a client before creating a ticket.'];
        }

        // followed_up_at is a technician's resolution marker — set when a call
        // is handled and, in particular, when it is marked spam (which also
        // blocks the caller). Manufacturing a ticket from such a call silently
        // undoes that dismissal, so refuse rather than override a human's call.
        if ($call->isFollowedUp()) {
            return ['error' => 'Phone call was already followed up on by a technician (followed_up_at is set) — it may have been dismissed as spam. Refusing to create a ticket; clear the follow-up state first if this is a genuine new request.'];
        }

        $ticket = DB::transaction(function () use ($call, $actorLabel, $reason): Ticket {
            $ticket = $this->phoneCallService->createTicketFromCall($call);
            $this->auditEntityExecution(
                'create_ticket_from_call',
                'phone_call',
                (int) $call->id,
                $ticket->client_id,
                $actorLabel,
                $this->mutationContentHash('create_ticket_from_call', (int) $call->id, ['ticket_id' => $ticket->id], $reason),
                'Phone call #'.$call->id.' created ticket #'.$ticket->id.': '.$reason,
                TechnicianConfig::requiredAiActorUserId(),
            );

            return $ticket;
        });

        return [
            'success' => true,
            'phone_call_id' => $call->id,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'message' => 'Ticket created from phone call.',
        ];
    }

    /** @return Asset|array<string, string> */
    private function assetForId(array $arguments): Asset|array
    {
        $assetId = $this->positiveInteger($arguments['asset_id'] ?? null);
        if ($assetId === null) {
            return ['error' => 'asset_id is required'];
        }

        $asset = Asset::find($assetId);
        if (! $asset) {
            return ['error' => 'Asset not found'];
        }

        return $asset;
    }

    private function confirmAssetMatches(Asset $asset, ?string $typed): bool
    {
        if ($typed === null) {
            return false;
        }

        return strcasecmp(trim($typed), trim((string) $asset->name)) === 0;
    }

    /**
     * Allowlist + validate an asset create/update payload against the same rules
     * as AssetStore/UpdateRequest. The Asset model's $fillable carries ~60
     * vendor/sync fields (ninja/level/controld/zorus/m365/comet/servosity/
     * screenconnect/tactical, rmm_online, …), so THIS allowlist is the boundary.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed> Validated data, or ['error' => string].
     */
    private function validateAssetPayload(array $arguments, bool $isCreate): array
    {
        $allowed = ['name', 'notes', 'asset_type', 'serial_number', 'hostname', 'os', 'ip_address', 'is_active'];
        $unexpected = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unexpected !== []) {
            return ['error' => 'This tool accepts only: '.implode(', ', $allowed).'.'];
        }

        $validator = Validator::make($arguments, [
            'name' => $isCreate ? ['required', 'string', 'max:255'] : ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'asset_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hostname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'os' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->first()];
        }

        $validated = $validator->validated();

        if (! $isCreate && $validated === []) {
            return ['error' => 'update_asset requires at least one field to change.'];
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private function sendEmail(array $arguments, int $clientId, string $actorLabel, ?string $tokenLabel = null): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        $body = $this->requiredString($arguments, 'body');
        if ($body === null) {
            return ['error' => 'body is required'];
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        try {
            $resolved = $this->recipients->resolve(
                $ticket,
                (array) ($arguments['to'] ?? []),
                (array) ($arguments['cc'] ?? []),
                RecipientContext::Direct,
                TechnicianConfig::allowArbitraryEmailRecipients(),
                TechnicianConfig::directEmailNewRecipients(),
            );
        } catch (RecipientValidationException $e) {
            return ['error' => $e->getMessage()];
        }

        // psa-kt82: recipients are now variable, so the idempotency key includes the
        // resolved To/CC set — the same body to a different audience is a new send.
        $contentHash = $this->contentHash('send_email', $ticket->id, $resolved->hashPayload($body));

        // psa-330: attempt delivery BEFORE persisting any client-facing receipt. The reply
        // note and responded_at are written only for an outcome that actually reached the
        // client, so a failed send leaves no artifact that reads as delivered — not in the
        // return, not as a ticket note. A maybe-sent outcome (indeterminate delivery or
        // sent-but-unrecorded) never reports success and never invites a resend.
        // Built here so a disclosure/build failure fails before anything is claimed below.
        $disclosedBody = $this->disclosedBody($body, $tokenLabel);
        $outgoing = new TicketNote([
            'ticket_id' => $ticket->id,
            'author_id' => TechnicianConfig::requiredAiActorUserId(),
            'body' => $disclosedBody,
        ]);

        // Check AND claim atomically, BEFORE the network call. The claim is a RESERVATION
        // (result_status 'reserved', never 'executed'), and pre-claiming it is what makes the
        // no-double-send invariant hold:
        //  - it survives a failure of the post-send receipt write (that write is a separate
        //    transaction), so a delivered email can never end up with no dedup record;
        //  - it closes the concurrent-duplicate window, which would otherwise span the whole
        //    Graph round trip (including GraphClient's 429 back-off sleeps) instead of a
        //    single DB transaction. lockForUpdate on the ticket row serializes check-to-claim
        //    per ticket, so a second identical call sees the claim rather than passing the
        //    same check and sending again.
        // The reservation blocks that duplicate WITHOUT asserting anything about delivery:
        // only a demonstrably delivered send is ever finalized 'executed', so a second caller
        // can never be handed a success / "already executed" receipt for a send that is still
        // in flight, unconfirmed, or about to be voided (must-fix 1 — the psa-330 false
        // receipt, reintroduced on the duplicate-caller path).
        // A reservation is VOIDED only by an outcome that PROVES nothing was transmitted
        // (NotSent). A throwable escaping the send is not such a proof, so it is finalized
        // 'unconfirmed' and the send must be verified out-of-band, never silently repeated.
        $claim = DB::transaction(function () use ($ticket, $actorLabel, $contentHash): TechnicianActionLog|string {
            Ticket::whereKey($ticket->id)->lockForUpdate()->first();

            // The live claim at whatever stage it has reached — a delivered receipt, an
            // in-flight reservation, or an unconfirmed delivery. Only 'voided' reopens it.
            $live = $this->liveSendClaim('send_email', $ticket->id, $contentHash);
            if ($live !== null) {
                return (string) $live->result_status;
            }

            // A maybe-sent attempt counts against the flood guard exactly as a delivered one
            // does — it may already have reached the client. A voided reservation does not:
            // that would re-block the very retry the void just reopened.
            if ($this->rateLimited(
                'send_email',
                $ticket->id,
                $this->cooldownSeconds('mcp_direct_send_email_cooldown_seconds', 300),
                [self::RESULT_EXECUTED, self::RESULT_UNCONFIRMED],
            )) {
                return 'rate_limited';
            }

            return $this->recordSendReservation($ticket, $actorLabel, $contentHash);
        });

        if (is_string($claim)) {
            return $claim === 'rate_limited'
                ? ['error' => 'send_email rate limit: direct email already sent for this ticket recently']
                : $this->duplicateSendResult($claim, $ticket);
        }

        // must-fix 2: everything between the committed reservation and its terminal row runs
        // under ONE catch. An uncaught throw here — a pre-send DB failure inside
        // sendTicketReplyNoteChecked, a receipt-transaction failure, MarkdownRenderer on the
        // disclosed body — would otherwise strand an orphan reservation with no compensating
        // row and no ticket artifact, silently blocking the send for the whole dedup window.
        try {
            $outcome = $this->email->sendTicketReplyNoteChecked($ticket, $outgoing, $resolved->to, $resolved->cc);

            return match ($outcome->status) {
                EmailSendStatus::Sent => $this->recordSentEmail(
                    $ticket, $disclosedBody, $tokenLabel, $claim, $reason, $resolved, $outcome->email
                ),
                EmailSendStatus::NotSent => $this->reportNotSentEmail($ticket, $claim, $outcome),
                EmailSendStatus::Indeterminate,
                EmailSendStatus::SentUnrecorded => $this->recordUnconfirmedEmail(
                    $ticket, $disclosedBody, $tokenLabel, $claim, $reason, $resolved, $outcome
                ),
            };
        } catch (\Throwable $e) {
            return $this->reportUnfinishedSend($ticket, $disclosedBody, $tokenLabel, $claim, $reason, $resolved, $e);
        }
    }

    /**
     * Persist the receipt for a confirmed send: the Reply note (with its email_id) and
     * responded_at — exactly the pre-psa-330 artifacts, now gated on delivery actually
     * happening. The pre-send RESERVATION was already committed in its OWN transaction and is
     * finalized here by appending the terminal 'executed' row: if this transaction fails, the
     * reservation stands (and the caller's catch finalizes it 'unconfirmed'), so a delivered
     * email can never be left with no dedup record for a retry to double-send against — and
     * 'executed' is written here and only here, for a send Graph actually accepted.
     *
     * @return array<string, mixed>
     */
    private function recordSentEmail(Ticket $ticket, string $disclosedBody, ?string $tokenLabel, TechnicianActionLog $claim, string $reason, ResolvedRecipients $resolved, Email $email): array
    {
        $summary = 'Direct MCP email sent: '.EmailRedactor::redact($reason).' ['.$resolved->auditDescriptor().']';

        $note = DB::transaction(function () use ($ticket, $disclosedBody, $tokenLabel, $claim, $summary, $email): TicketNote {
            $note = $this->createAiNote($ticket, $disclosedBody, NoteType::Reply, $tokenLabel);
            $note->update(['email_id' => $email->id]);
            $ticket->forceFill(['responded_at' => $ticket->responded_at ?? now()])->save();
            // Append-only log: the reservation is finalized by a NEW terminal row, never by an
            // in-place update (which the model guards and DB triggers refuse outright).
            $this->recordSendStage(self::RESULT_EXECUTED, $claim, $summary);

            return $note;
        });

        return [
            'success' => true,
            'sent' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'note_id' => $note->id,
            'email_id' => $email->id,
            'message' => 'Email sent.',
        ];
    }

    /**
     * A NotSent outcome PROVES nothing was transmitted (a skip, a pre-dispatch failure, a
     * failure that never reached Graph, or a Graph rejection that queued nothing), so the
     * pre-send reservation is VOIDED — by APPENDING a 'voided' row, never by deleting the
     * claim. The log is append-only and an attempted client-facing send is audit-worthy in
     * its own right, so the attempt survives; voiding it is what reopens the legitimate retry
     * for a send that never happened. The failure itself is already logged by EmailService,
     * and no ticket artifact is written.
     *
     * @return array<string, mixed>
     */
    private function reportNotSentEmail(Ticket $ticket, TechnicianActionLog $claim, EmailSendOutcome $outcome): array
    {
        $this->recordSendStage(
            self::RESULT_VOIDED,
            $claim,
            'Direct MCP email NOT sent — reservation voided, retry open: '.EmailRedactor::redact((string) $outcome->reason),
        );

        return [
            'success' => false,
            'sent' => false,
            'retryable' => $outcome->retryable,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'error' => 'Email was NOT sent: '.$outcome->reason
                .($outcome->retryable ? ' (transient — retry is safe).' : ' (fix the cause before retrying — no message was transmitted).'),
        ];
    }

    /**
     * Record a maybe-sent outcome (indeterminate delivery or sent-but-unrecorded): the
     * client was, or may have been, emailed. The pre-send reservation is finalized
     * 'unconfirmed' — NEVER 'executed', so neither the audit trail nor a duplicate caller can
     * read this as a delivered send — which keeps a duplicate idempotency-blocked while still
     * telling that caller the truth, and a PRIVATE internal Note
     * (never a Reply, never portal-visible) flags it for manual verification. On
     * sent-but-unrecorded Graph accepted the send, so responded_at is stamped — the client
     * demonstrably was answered. The return never reads as success or as a delivered email
     * and never invites an auto-retry — that would be the double-send (psa-330 / #329).
     *
     * @return array<string, mixed>
     */
    private function recordUnconfirmedEmail(Ticket $ticket, string $disclosedBody, ?string $tokenLabel, TechnicianActionLog $claim, string $reason, ResolvedRecipients $resolved, EmailSendOutcome $outcome): array
    {
        $sentSurely = $outcome->status === EmailSendStatus::SentUnrecorded;
        $flag = $sentSurely
            ? 'DELIVERED BUT NOT RECORDED — verify the outbound email exists; do NOT resend.'
            : 'DELIVERY UNCONFIRMED — the send may or may not have reached the client; verify out-of-band before any resend.';
        $summary = 'Direct MCP email UNCONFIRMED ('.$outcome->status->value.'): '.EmailRedactor::redact($reason).' ['.$resolved->auditDescriptor().']';

        $note = DB::transaction(function () use ($ticket, $disclosedBody, $tokenLabel, $claim, $flag, $summary, $sentSurely): TicketNote {
            // PRIVATE (must-fix 1): this note carries internal doubt language AND a reply
            // body that may never have been delivered. createAiNote's default is_private=false
            // plus a non-system NoteType would make scopePortalVisible() serve both to the
            // client in the portal.
            $note = $this->createAiNote(
                $ticket,
                '[send_email — MANUAL VERIFICATION NEEDED] '.$flag."\n\nIntended reply body:\n".$disclosedBody,
                NoteType::Note,
                $tokenLabel,
                private: true,
            );

            // Sent-but-unrecorded: Graph accepted the send, so the client WAS emailed and the
            // ticket must read as responded to — otherwise first-response SLA reporting and the
            // responded_at-keyed senders (AutoAcknowledge / MaxHoldSender) treat an answered
            // client as never contacted and can fire a second, redundant outreach. An
            // indeterminate outcome proves nothing, so it claims no response.
            if ($sentSurely) {
                $ticket->forceFill(['responded_at' => $ticket->responded_at ?? now()])->save();
            }

            // Finalize the reservation as UNCONFIRMED — a new terminal row on an append-only
            // log, and deliberately not 'executed': delivery was never confirmed.
            $this->recordSendStage(self::RESULT_UNCONFIRMED, $claim, $summary);

            return $note;
        });

        // Must never read as success or as a delivered email to any consumer: success is
        // false, 'sent' is falsy unless Graph actually accepted the send, and the 'error' key
        // stops the MCP layer auditing this as a clean send. retryable stays false — a resend
        // may duplicate a message the client already has.
        return [
            'success' => false,
            'sent' => $sentSurely ? true : null,
            'delivery' => $sentSurely ? 'sent_not_recorded' : 'unknown',
            'recorded' => false,
            'retryable' => false,
            'requires_manual_verification' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'note_id' => $note->id,
            'error' => $flag.' Reason: '.$outcome->reason
                .' This send is idempotency-blocked; do NOT auto-retry — verify out-of-band first.',
        ];
    }

    /** @return array<string, mixed> */
    private function writePublicNote(array $arguments, int $clientId, string $actorLabel, ?string $tokenLabel = null): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        $body = $this->requiredString($arguments, 'body');
        if ($body === null) {
            return ['error' => 'body is required'];
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        $contentHash = $this->contentHash('write_public_note', $ticket->id, $body);
        if ($this->alreadyExecuted('write_public_note', $ticket->id, $contentHash)) {
            return $this->idempotentResult('write_public_note', $ticket);
        }

        if ($this->rateLimited('write_public_note', $ticket->id, $this->cooldownSeconds('mcp_direct_write_public_note_cooldown_seconds', 60))) {
            return ['error' => 'write_public_note rate limit: direct public note already written for this ticket recently'];
        }

        $note = DB::transaction(function () use ($ticket, $body, $actorLabel, $tokenLabel, $contentHash, $reason): TicketNote {
            $note = $this->createAiNote($ticket, $this->disclosedBody($body, $tokenLabel), NoteType::Note, $tokenLabel);
            $this->auditDirectExecution('write_public_note', $ticket, $actorLabel, $contentHash, 'Direct MCP public note written: '.$reason);

            return $note;
        });

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'note_id' => $note->id,
            'message' => 'Public note written.',
        ];
    }

    /** @return array<string, mixed> */
    private function stageTicketAction(string $actionType, array $arguments, int $clientId, string $actorLabel, bool $sendsEmail, ?string $tokenLabel = null): array
    {
        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        $body = $this->requiredString($arguments, 'body');
        if ($body === null) {
            return ['error' => 'body is required'];
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            return $ticket;
        }

        $contentHash = $this->contentHash($actionType, $ticket->id, $body);
        $meta = [
            'reasons' => [$reason],
            'drafted_by' => $actorLabel,
            // The BARE label too: this run's disclosure is written at APPROVAL time, by
            // which point the token is long out of scope. 'drafted_by' cannot serve — it
            // is the prefixed audit form and resolves no persona. Absent on runs staged
            // before psa-u51h, which degrade to the global actor name (psa-u51h part 2).
            'drafted_by_token' => $tokenLabel,
            'contact_email' => $ticket->contact?->email,
            'contact_name' => $ticket->contact?->fullName,
        ];

        // Reason is agent free text and may name an address — redact, matching the
        // direct path's summary (the descriptor below stays counts-only by design).
        $summary = "MCP staged {$actionType} for ticket #{$ticket->id}: ".EmailRedactor::redact($reason);

        // psa-w4e0: the email-sending staged action accepts proposed To/CC (person_ids
        // or addresses). Resolving here fails fast for the agent (bad person ref, bad
        // syntax, custom address while the staged knob is off — it also subsumes the
        // old "Ticket has no contact email" guard) and records the resolved set for
        // the approval card. Approval re-resolves from the operator's form (gate 3);
        // this preview is prefill + audit, never what actually sends.
        if ($sendsEmail) {
            try {
                $proposed = $this->recipients->resolve(
                    $ticket,
                    (array) ($arguments['to'] ?? []),
                    (array) ($arguments['cc'] ?? []),
                    RecipientContext::Staged,
                    TechnicianConfig::stagedSendsAllowArbitraryRecipients(),
                    TechnicianConfig::directEmailNewRecipients(),
                );
            } catch (RecipientValidationException $e) {
                return ['error' => $e->getMessage()];
            }

            // Same body to a different audience is a new proposal, not a replay (mirrors
            // the direct send_email idempotency key, psa-kt82, and the approval-time
            // grant/audit hash — all three share ResolvedRecipients::hashPayload).
            $contentHash = $this->contentHash($actionType, $ticket->id, $proposed->hashPayload($body));
            $meta['to'] = $proposed->to;
            $meta['cc'] = $proposed->cc;
            $meta['custom_recipients'] = $proposed->custom;
            $summary .= ' ['.$proposed->auditDescriptor().']';
        }

        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => $actionType,
                'content_hash' => $contentHash,
            ],
            [
                'client_id' => $ticket->client_id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $body,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );

        if (! $run->wasRecentlyCreated) {
            if ($run->state === TechnicianRunState::AwaitingApproval) {
                return [
                    'success' => true,
                    'ticket_id' => $ticket->id,
                    'ticket_display_id' => $ticket->display_id,
                    'run_id' => $run->id,
                    'message' => 'Already staged; awaiting approval.',
                ];
            }

            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $body,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ]);
        }

        TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', $actionType)
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->where('id', '!=', $run->id)
            ->get()
            ->each
            ->markSuperseded();

        $this->gate->dispatch(
            actionType: $actionType,
            ticketId: $ticket->id,
            clientId: $ticket->client_id,
            contentHash: $contentHash,
            summary: $summary,
            runId: $run->id,
            executor: static function () use ($actionType): void {
                throw new \LogicException("Held-only MCP {$actionType} path must not execute directly.");
            },
            confidence: null,
        );

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Staged for cockpit approval.',
        ];
    }

    /** @return array<string, mixed> */
    private function proposeMerge(array $arguments, int $clientId, string $actorLabel): array
    {
        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            return ['error' => 'reason is required'];
        }

        $primaryId = $this->positiveInteger($arguments['primary_ticket_id'] ?? null);
        $secondaryId = $this->positiveInteger($arguments['secondary_ticket_id'] ?? null);
        if ($primaryId === null || $secondaryId === null) {
            return ['error' => 'primary_ticket_id and secondary_ticket_id are required'];
        }

        $pair = $this->mergePairForClient($primaryId, $secondaryId, $clientId);
        if (isset($pair['error'])) {
            return $pair;
        }

        /** @var Ticket $primary */
        $primary = $pair['primary'];
        /** @var Ticket $secondary */
        $secondary = $pair['secondary'];
        $contentHash = hash('sha256', "propose_merge:{$primary->id}:{$secondary->id}:{$reason}");
        $meta = [
            'primary_ticket_id' => $primary->id,
            'secondary_ticket_id' => $secondary->id,
            'primary_display_id' => $primary->display_id,
            'secondary_display_id' => $secondary->display_id,
            'primary_subject' => $primary->subject,
            'secondary_subject' => $secondary->subject,
            'drafted_by' => $actorLabel,
        ];

        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $primary->id,
                'action_type' => 'propose_merge',
                'content_hash' => $contentHash,
            ],
            [
                'client_id' => $primary->client_id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $reason,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );

        if (! $run->wasRecentlyCreated) {
            if ($run->state === TechnicianRunState::AwaitingApproval) {
                return [
                    'success' => true,
                    'ticket_id' => $primary->id,
                    'ticket_display_id' => $primary->display_id,
                    'run_id' => $run->id,
                    'message' => 'Already proposed merge; awaiting approval.',
                ];
            }

            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $reason,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ]);
        }

        $this->gate->dispatch(
            actionType: 'propose_merge',
            ticketId: $primary->id,
            clientId: $primary->client_id,
            contentHash: $contentHash,
            summary: "MCP proposed merging ticket #{$secondary->id} into #{$primary->id}: {$reason}",
            runId: $run->id,
            executor: static function (): void {
                throw new \LogicException('Held-only MCP propose_merge path must not execute directly.');
            },
            confidence: null,
        );

        return [
            'success' => true,
            'ticket_id' => $primary->id,
            'ticket_display_id' => $primary->display_id,
            'run_id' => $run->id,
            'message' => 'Merge proposed for cockpit approval.',
        ];
    }

    /** @return array<string, string>|null */
    private function guardDirectAction(): ?array
    {
        if (TechnicianConfig::killSwitchEngaged()) {
            return ['error' => 'Technician kill-switch engaged; direct client-facing action refused'];
        }

        return null;
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

    /** @return Ticket|array<string, string> */
    private function ticketForClient(mixed $ticketIdValue, int $clientId): Ticket|array
    {
        $ticketId = $this->positiveInteger($ticketIdValue);
        if ($ticketId === null) {
            return ['error' => 'ticket_id is required'];
        }

        $ticket = Ticket::with(['contact', 'assets'])->find($ticketId);
        if (! $ticket || (int) $ticket->client_id !== $clientId) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }

        return $ticket;
    }

    /** @return array{primary: Ticket, secondary: Ticket}|array{error: string} */
    private function mergePairForClient(int $primaryId, int $secondaryId, int $clientId): array
    {
        if ($primaryId === $secondaryId) {
            return ['error' => 'Cannot merge a ticket into itself'];
        }

        $primary = Ticket::find($primaryId);
        $secondary = Ticket::find($secondaryId);
        if (! $primary || ! $secondary) {
            return ['error' => 'Ticket not found'];
        }

        if ((int) $primary->client_id !== $clientId || (int) $secondary->client_id !== $clientId) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }

        if ($primary->client_id !== $secondary->client_id) {
            return ['error' => 'Cannot merge tickets from different clients'];
        }

        if ($primary->parent_ticket_id || $secondary->parent_ticket_id) {
            return ['error' => 'One of these tickets has already been merged'];
        }

        if ($secondary->childTickets()->exists()) {
            return ['error' => 'Cannot merge a ticket that has merged tickets. Merge those first.'];
        }

        return ['primary' => $primary, 'secondary' => $secondary];
    }

    /** @return array<string, mixed>|null */
    private function validateTicketUpdatePayload(array $arguments): ?array
    {
        $allowed = ['ticket_id', 'subject', 'description', 'priority', 'type', 'category_id', 'reason'];
        $unexpected = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unexpected !== []) {
            return ['error' => 'update_ticket accepts only subject, description, priority, type, and category_id'];
        }

        $validator = Validator::make($arguments, [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'required', Rule::enum(\App\Enums\TicketPriority::class)],
            'type' => ['sometimes', 'required', Rule::enum(\App\Enums\TicketType::class)],
            // ITIL taxonomy node (so-0ftg) — mirrors the web form's rule: an
            // ACTIVE node only (retired/unknown rejected at the write). Nullable
            // clears it. category_source is stamped by TicketObserver (System on
            // this no-auth-web-user surface), never from tool input.
            'category_id' => ['sometimes', 'nullable', Rule::exists('ticket_categories', 'id')->where('is_active', true)],
            'reason' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            // A rejected category_id gets actionable recovery copy (the agent must
            // know it's active-node-only and how to enumerate valid ids), not the
            // opaque validator line. The active-node-only enforcement is unchanged.
            if ($validator->errors()->has('category_id')) {
                return ['error' => self::CATEGORY_ID_RECOVERY_COPY];
            }

            return ['error' => $validator->errors()->first()];
        }

        $validated = $validator->validated();
        unset($validated['reason']);

        return $validated;
    }

    /**
     * Validate an optional create_ticket category_id (so-0ftg, psa-begf3.2):
     * an ACTIVE taxonomy node, else the actionable recovery copy. Returns the
     * int node id, null when none was supplied (or an explicit null → the
     * ticket is created uncategorized), or an ['error' => ...] array to
     * short-circuit the tool call. category_source is stamped by TicketObserver
     * (System on this no-auth surface), never taken from tool input.
     *
     * @return int|array<string, mixed>|null
     */
    private function validateCreateCategoryId(array $arguments): int|array|null
    {
        if (! array_key_exists('category_id', $arguments) || $arguments['category_id'] === null) {
            return null;
        }

        $value = $arguments['category_id'];

        $isActiveNode = is_numeric($value) && TicketCategory::query()
            ->whereKey((int) $value)
            ->where('is_active', true)
            ->exists();

        if (! $isActiveNode) {
            return ['error' => self::CREATE_CATEGORY_ID_RECOVERY_COPY];
        }

        return (int) $value;
    }

    private function ticketStatusFrom(mixed $value): ?\App\Enums\TicketStatus
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return \App\Enums\TicketStatus::tryFrom(trim($value));
    }

    private function confirmClientMatches(Client $client, ?string $typed): bool
    {
        if ($typed === null) {
            return false;
        }

        return strcasecmp(trim($typed), (string) $client->name) === 0;
    }

    private function optionalString(array $arguments, string $key): ?string
    {
        if (! array_key_exists($key, $arguments) || ! is_scalar($arguments[$key])) {
            return null;
        }

        $value = trim((string) $arguments[$key]);

        return $value !== '' ? $value : null;
    }

    private function nullableUserId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->positiveInteger($value);
    }

    /** @param array<string, mixed>|string|null $payload */
    private function mutationContentHash(string $actionType, int $ticketId, mixed $payload, ?string $reason = null): string
    {
        return hash('sha256', json_encode([
            'action' => $actionType,
            'ticket_id' => $ticketId,
            'payload' => $payload,
            'reason' => $reason,
        ]));
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function fieldDiff(array $before, array $after): array
    {
        $diff = [];
        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $diff[$field] = ['before' => $before[$field] ?? null, 'after' => $value];
            }
        }

        return $diff;
    }

    /** @param array<string, array{before:mixed, after:mixed}> $diff */
    private function stringifyDiff(array $diff): string
    {
        $parts = [];
        foreach ($diff as $field => $change) {
            $parts[] = $field.': '.json_encode($change['before']).' -> '.json_encode($change['after']);
        }

        return implode('; ', $parts);
    }

    /**
     * $tokenLabel is the BARE McpToken.label of the AUTHENTICATED caller (never
     * anything from $input) — the persona is derived server-side from it, the same
     * trust boundary OperatorBridgeToolExecutor established. It is NOT $actorLabel,
     * which is the prefixed audit form and resolves no persona (psa-u51h).
     */
    private function disclosedBody(string $body, ?string $tokenLabel = null): string
    {
        $disclosed = $this->disclosure->withDisclosure($body, TechnicianConfig::actorNameForTokenLabel($tokenLabel));
        $this->disclosure->assertPresent($disclosed);

        return $disclosed;
    }

    /**
     * $private defaults to false (client-visible), the shape every pre-psa-330 caller
     * relies on. It must be passed true for any note carrying internal-only content:
     * a non-private note of a non-systemGenerated() type is served to the client by
     * TicketNote::scopePortalVisible().
     */
    private function createAiNote(Ticket $ticket, string $body, NoteType $type, ?string $tokenLabel = null, bool $private = false): TicketNote
    {
        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => TechnicianConfig::requiredAiActorUserId(),
            'author_name' => TechnicianConfig::actorNameForTokenLabel($tokenLabel),
            'who_type' => WhoType::Agent,
            'ai_authored' => true,
            'body' => $body,
            'body_html' => MarkdownRenderer::render($body),
            'note_type' => $type,
            'is_private' => $private,
            'noted_at' => now(),
        ]);

        $ticket->touch();

        return $note;
    }

    private function contentHash(string $actionType, int $ticketId, string $body): string
    {
        return hash('sha256', "{$actionType}:{$ticketId}:{$body}");
    }

    private function alreadyExecuted(string $actionType, int $ticketId, string $contentHash): bool
    {
        return TechnicianActionLog::query()
            ->where('action_type', $actionType)
            ->where('ticket_id', $ticketId)
            ->where('result_status', 'executed')
            ->where('content_hash', $contentHash)
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->exists();
    }

    private function alreadyCreatedTicketLog(int $clientId, string $contentHash): ?TechnicianActionLog
    {
        return TechnicianActionLog::query()
            ->where('action_type', 'create_ticket')
            ->where('client_id', $clientId)
            ->where('result_status', 'executed')
            ->where('content_hash', $contentHash)
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->latest('id')
            ->first();
    }

    /** @param  array<string, mixed>  $validated */
    private function createClientContentHash(array $validated): string
    {
        return hash('sha256', 'create_client:'.json_encode($validated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function duplicateCreateClientRecently(string $contentHash): bool
    {
        return TechnicianActionLog::query()
            ->where('action_type', 'create_client')
            ->where('result_status', 'executed')
            ->where('content_hash', $contentHash)
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->exists();
    }

    /**
     * @param  array<int, string>  $resultStatuses  Which recorded outcomes count as an attempt
     *                                              that already reached (or may have reached)
     *                                              the client. A voided reservation never does.
     */
    private function rateLimited(string $actionType, int $ticketId, int $cooldownSeconds, array $resultStatuses = [self::RESULT_EXECUTED]): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->where('action_type', $actionType)
            ->where('ticket_id', $ticketId)
            ->whereIn('result_status', $resultStatuses)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->exists();
    }

    private function cooldownSeconds(string $settingKey, int $default): int
    {
        $value = Setting::getValue($settingKey, (string) $default);

        return max(0, (int) $value);
    }

    /** @return array<string, mixed> */
    private function idempotentCreateTicketResult(TechnicianActionLog $log): array
    {
        $ticket = $log->ticket_id ? Ticket::find($log->ticket_id) : null;

        return [
            'success' => true,
            'idempotent' => true,
            'ticket_id' => $ticket?->id ?? $log->ticket_id,
            'ticket_display_id' => $ticket?->display_id,
            'display_id' => $ticket?->display_id,
            'url' => $ticket ? route('tickets.show', $ticket) : null,
            'message' => 'Already created identical create_ticket recently; no new ticket was created.',
        ];
    }

    /** @return array<string, mixed> */
    private function idempotentResult(string $actionType, Ticket $ticket): array
    {
        return [
            'success' => true,
            'idempotent' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'message' => "Already executed identical {$actionType} recently; no new client-facing output was produced.",
        ];
    }

    /**
     * The LIVE claim on this exact send, or null when the send is open again.
     *
     * The log is append-only, so state is the LATEST row for this key inside the dedup window
     * — never a mutated or deleted row — and only a 'voided' latest row reopens the send.
     * Callers MUST branch on the returned status: 'executed' is the only stage that may be
     * reported to a duplicate caller as a completed send (must-fix 1).
     */
    private function liveSendClaim(string $actionType, int $ticketId, string $contentHash): ?TechnicianActionLog
    {
        $latest = TechnicianActionLog::query()
            ->where('action_type', $actionType)
            ->where('ticket_id', $ticketId)
            ->where('content_hash', $contentHash)
            ->whereIn('result_status', [self::RESULT_EXECUTED, self::RESULT_RESERVED, self::RESULT_UNCONFIRMED, self::RESULT_VOIDED])
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->latest('id')
            ->first();

        return $latest?->result_status === self::RESULT_VOIDED ? null : $latest;
    }

    /**
     * The pre-send reservation row. Deliberately NOT 'executed': that status means a
     * client-facing action demonstrably happened, and every other consumer of the trail
     * (dedup, digests, insight readouts) reads it that way. An attempted send must not borrow
     * it (must-fix 3).
     */
    private function recordSendReservation(Ticket $ticket, string $actorLabel, string $contentHash): TechnicianActionLog
    {
        return $this->recordActionLog(
            'send_email',
            $ticket->id,
            (int) $ticket->client_id,
            $actorLabel,
            $contentHash,
            'Direct MCP email send RESERVED (pre-send claim; delivery outcome pending)',
            null,
            self::RESULT_RESERVED,
        );
    }

    /**
     * Append the terminal row that finalizes a reservation ('executed' / 'unconfirmed' /
     * 'voided'). The reservation row itself is left exactly as written: the log is
     * append-only, and the record of an attempted client-facing send is worth keeping, so it
     * is never updated in place and never hard-deleted (must-fix 3).
     */
    private function recordSendStage(string $resultStatus, TechnicianActionLog $claim, string $summary): TechnicianActionLog
    {
        return $this->recordActionLog(
            (string) $claim->action_type,
            $claim->ticket_id,
            $claim->client_id,
            (string) $claim->actor_label,
            (string) $claim->content_hash,
            $summary,
            $claim->actor_id,
            $resultStatus,
        );
    }

    /**
     * A duplicate call that hit a LIVE claim. Only a demonstrably delivered send ('executed')
     * may be answered with the idempotent success receipt: answering "Already executed
     * identical send_email" with success:true and no error key for a send that is still in
     * flight, unconfirmed, or about to be voided is exactly the false "email sent" receipt
     * psa-330 exists to eliminate, reintroduced on the second caller (must-fix 1). The
     * non-delivered stages therefore carry an 'error' key — so the MCP layer records an error
     * instead of auditing a clean send — never read as sent, and never invite a retry.
     *
     * @return array<string, mixed>
     */
    private function duplicateSendResult(string $status, Ticket $ticket): array
    {
        if ($status === self::RESULT_EXECUTED) {
            return $this->idempotentResult('send_email', $ticket);
        }

        $inFlight = $status === self::RESULT_RESERVED;

        return [
            'success' => false,
            'sent' => null,
            'delivery' => 'unknown',
            'recorded' => false,
            'retryable' => false,
            'requires_manual_verification' => true,
            'idempotency_blocked' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'error' => $inFlight
                ? 'An identical send_email for this ticket is IN FLIGHT and its delivery is UNCONFIRMED — this call transmitted nothing. Do NOT report the email as sent and do NOT retry; check the ticket for the recorded outcome first.'
                : 'An identical send_email for this ticket is recorded as DELIVERY UNCONFIRMED — it may already have reached the client, and this call transmitted nothing. Do NOT report the email as sent and do NOT resend; verify out-of-band first.',
        ];
    }

    /**
     * must-fix 2: a throw anywhere between the committed reservation and its terminal row (a
     * pre-send DB failure, a receipt-write failure, MarkdownRenderer on the disclosed body).
     * Nothing here PROVES the client was not emailed — the throw may have escaped after Graph
     * accepted the send — so the reservation is finalized UNCONFIRMED (never 'executed', never
     * voided), the ticket gets the same PRIVATE manual-verification note the indeterminate
     * path writes, and the caller is told plainly that nothing is confirmed. Both compensating
     * writes are independent and best-effort: neither may throw over the original failure.
     *
     * @return array<string, mixed>
     */
    private function reportUnfinishedSend(Ticket $ticket, string $disclosedBody, ?string $tokenLabel, TechnicianActionLog $claim, string $reason, ResolvedRecipients $resolved, \Throwable $e): array
    {
        Log::error('[MCP] Direct send_email failed between the pre-send claim and its outcome', [
            'ticket_id' => $ticket->id,
            'error' => $e->getMessage(),
        ]);

        try {
            $this->recordSendStage(
                self::RESULT_UNCONFIRMED,
                $claim,
                'Direct MCP email UNCONFIRMED (outcome unrecorded): '.EmailRedactor::redact($reason).' ['.$resolved->auditDescriptor().']',
            );
        } catch (\Throwable $logFailure) {
            Log::error('[MCP] Direct send_email could not finalize its reservation', [
                'ticket_id' => $ticket->id,
                'error' => $logFailure->getMessage(),
            ]);
        }

        $noteId = null;
        try {
            $noteId = $this->createAiNote(
                $ticket,
                '[send_email — MANUAL VERIFICATION NEEDED] DELIVERY UNCONFIRMED — the send failed while being recorded, so it may or may not have reached the client; verify out-of-band before any resend.'
                    ."\n\nIntended reply body:\n".$disclosedBody,
                NoteType::Note,
                $tokenLabel,
                private: true,
            )->id;
        } catch (\Throwable $noteFailure) {
            Log::error('[MCP] Direct send_email could not write its manual-verification note', [
                'ticket_id' => $ticket->id,
                'error' => $noteFailure->getMessage(),
            ]);
        }

        return [
            'success' => false,
            'sent' => null,
            'delivery' => 'unknown',
            'recorded' => false,
            'retryable' => false,
            'requires_manual_verification' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'note_id' => $noteId,
            'error' => 'DELIVERY UNCONFIRMED — the send failed while being recorded ('
                .EmailRedactor::redact($e->getMessage())
                .'), so it may or may not have reached the client. This send is idempotency-blocked;'
                .' do NOT auto-retry — verify out-of-band first.',
        ];
    }

    /** Returns the written log row (the send path finalizes its reservation from it, psa-330). */
    private function auditDirectExecution(string $actionType, Ticket $ticket, string $actorLabel, string $contentHash, string $summary, ?int $actorId = null): TechnicianActionLog
    {
        return $this->recordActionLog($actionType, $ticket->id, (int) $ticket->client_id, $actorLabel, $contentHash, $summary, $actorId);
    }

    /**
     * Append-only audit for a non-ticket PSA entity mutation (client, person,
     * asset). Mirrors auditDirectExecution but leaves ticket_id null and encodes
     * the entity type/id in the summary, since technician_action_logs has no
     * entity_type/entity_id columns (v1 — see psa-wsje).
     */
    private function auditEntityExecution(string $actionType, string $entityType, ?int $entityId, ?int $clientId, string $actorLabel, string $contentHash, string $summary, ?int $actorId = null): void
    {
        $tag = '['.$entityType.($entityId !== null ? '#'.$entityId : '').'] ';
        $this->recordActionLog($actionType, null, $clientId, $actorLabel, $contentHash, $tag.$summary, $actorId);
    }

    private function recordActionLog(string $actionType, ?int $ticketId, ?int $clientId, string $actorLabel, string $contentHash, string $summary, ?int $actorId, string $resultStatus = self::RESULT_EXECUTED): TechnicianActionLog
    {
        return TechnicianActionLog::create([
            'actor_id' => $actorId ?? TechnicianConfig::requiredAiActorUserId(),
            'approver_user_id' => null,
            'actor_label' => $actorLabel,
            'action_type' => $actionType,
            'tier' => TechnicianTier::Approve->value,
            'result_status' => $resultStatus,
            'ticket_id' => $ticketId,
            'client_id' => $clientId,
            'run_id' => null,
            'content_hash' => $contentHash,
            'summary' => mb_substr($summary, 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
