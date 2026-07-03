<?php

namespace App\Services\Mcp;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Enums\WhoType;
use App\Helpers\MarkdownRenderer;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\EmailService;
use App\Services\Technician\TechnicianActionGate;
use App\Services\Technician\TechnicianDisclosure;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StaffPsaActionToolExecutor
{
    private const DIRECT_DEDUP_HOURS = 24;

    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianDisclosure $disclosure,
        private readonly EmailService $email,
    ) {}

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel): array
    {
        return match ($name) {
            'send_email' => $this->sendEmail($arguments, $clientId, $actorLabel),
            'write_public_note' => $this->writePublicNote($arguments, $clientId, $actorLabel),
            'stage_email' => $this->stageTicketAction('stage_email', $arguments, $clientId, $actorLabel, requiresContactEmail: true),
            'stage_public_note' => $this->stageTicketAction('stage_public_note', $arguments, $clientId, $actorLabel, requiresContactEmail: false),
            'propose_merge' => $this->proposeMerge($arguments, $clientId, $actorLabel),
            default => ['error' => "Unknown PSA action tool: {$name}"],
        };
    }

    /** @return array<string, mixed> */
    private function sendEmail(array $arguments, int $clientId, string $actorLabel): array
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

        $to = $ticket->contact?->email;
        if (! is_string($to) || trim($to) === '') {
            return ['error' => 'Ticket has no contact email'];
        }

        $contentHash = $this->contentHash('send_email', $ticket->id, $body);
        if ($this->alreadyExecuted('send_email', $ticket->id, $contentHash)) {
            return $this->idempotentResult('send_email', $ticket);
        }

        if ($this->rateLimited('send_email', $ticket->id, $this->cooldownSeconds('mcp_direct_send_email_cooldown_seconds', 300))) {
            return ['error' => 'send_email rate limit: direct email already sent for this ticket recently'];
        }

        $note = DB::transaction(function () use ($ticket, $body, $actorLabel, $contentHash, $reason): TicketNote {
            $note = $this->createAiNote($ticket, $this->disclosedBody($body), NoteType::Reply);
            $ticket->forceFill(['responded_at' => $ticket->responded_at ?? now()])->save();
            $this->auditDirectExecution('send_email', $ticket, $actorLabel, $contentHash, 'Direct MCP email sent: '.$reason);

            return $note;
        });

        try {
            $email = $this->email->sendTicketReplyNote($ticket, $note, $to, []);
            if ($email !== null) {
                $note->update(['email_id' => $email->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('[MCP] Direct send_email email delivery failed after audited note write', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
            $email = null;
        }

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'note_id' => $note->id,
            'email_id' => $email?->id,
            'message' => 'Email sent to ticket contact.',
        ];
    }

    /** @return array<string, mixed> */
    private function writePublicNote(array $arguments, int $clientId, string $actorLabel): array
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

        $note = DB::transaction(function () use ($ticket, $body, $actorLabel, $contentHash, $reason): TicketNote {
            $note = $this->createAiNote($ticket, $this->disclosedBody($body), NoteType::Note);
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
    private function stageTicketAction(string $actionType, array $arguments, int $clientId, string $actorLabel, bool $requiresContactEmail): array
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

        if ($requiresContactEmail && trim((string) $ticket->contact?->email) === '') {
            return ['error' => 'Ticket has no contact email'];
        }

        $contentHash = $this->contentHash($actionType, $ticket->id, $body);
        $meta = [
            'reasons' => [$reason],
            'drafted_by' => $actorLabel,
            'contact_email' => $ticket->contact?->email,
            'contact_name' => $ticket->contact?->fullName,
        ];

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
            summary: "MCP staged {$actionType} for ticket #{$ticket->id}: {$reason}",
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

        $ticket = Ticket::with('contact')->find($ticketId);
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

    private function disclosedBody(string $body): string
    {
        $disclosed = $this->disclosure->withDisclosure($body, TechnicianConfig::aiActorName());
        $this->disclosure->assertPresent($disclosed);

        return $disclosed;
    }

    private function createAiNote(Ticket $ticket, string $body, NoteType $type): TicketNote
    {
        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => TechnicianConfig::requiredAiActorUserId(),
            'author_name' => TechnicianConfig::aiActorName(),
            'who_type' => WhoType::Agent,
            'ai_authored' => true,
            'body' => $body,
            'body_html' => MarkdownRenderer::render($body),
            'note_type' => $type,
            'is_private' => false,
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

    private function rateLimited(string $actionType, int $ticketId, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->where('action_type', $actionType)
            ->where('ticket_id', $ticketId)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->exists();
    }

    private function cooldownSeconds(string $settingKey, int $default): int
    {
        $value = Setting::getValue($settingKey, (string) $default);

        return max(0, (int) $value);
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

    private function auditDirectExecution(string $actionType, Ticket $ticket, string $actorLabel, string $contentHash, string $summary): void
    {
        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::requiredAiActorUserId(),
            'approver_user_id' => null,
            'actor_label' => $actorLabel,
            'action_type' => $actionType,
            'tier' => TechnicianTier::Approve->value,
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'run_id' => null,
            'content_hash' => $contentHash,
            'summary' => mb_substr($summary, 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
