<?php

namespace App\Services\Mcp;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Enums\WhoType;
use App\Helpers\MarkdownRenderer;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Assistant\AssistantTicketCreator;
use App\Services\EmailService;
use App\Services\Technician\TechnicianActionGate;
use App\Services\Technician\TechnicianDisclosure;
use App\Services\TicketService;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StaffPsaActionToolExecutor
{
    private const DIRECT_DEDUP_HOURS = 24;

    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianDisclosure $disclosure,
        private readonly EmailService $email,
        private readonly AssistantTicketCreator $ticketCreator,
        private readonly TicketService $ticketService,
    ) {}

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel): array
    {
        return match ($name) {
            'create_ticket' => $this->createTicket($arguments, $clientId, $actorLabel),
            'send_email' => $this->sendEmail($arguments, $clientId, $actorLabel),
            'write_public_note' => $this->writePublicNote($arguments, $clientId, $actorLabel),
            'stage_email' => $this->stageTicketAction('stage_email', $arguments, $clientId, $actorLabel, requiresContactEmail: true),
            'stage_public_note' => $this->stageTicketAction('stage_public_note', $arguments, $clientId, $actorLabel, requiresContactEmail: false),
            'propose_merge' => $this->proposeMerge($arguments, $clientId, $actorLabel),
            'update_ticket' => $this->updateTicket($arguments, $clientId, $actorLabel),
            'set_ticket_status' => $this->setTicketStatus($arguments, $clientId, $actorLabel),
            'assign_ticket' => $this->assignTicket($arguments, $clientId, $actorLabel),
            'assign_asset' => $this->assignAsset($arguments, $clientId, $actorLabel),
            'unassign_asset' => $this->unassignAsset($arguments, $clientId, $actorLabel),
            'set_ticket_contact' => $this->setTicketContact($arguments, $clientId, $actorLabel),
            'move_ticket_to_client' => $this->moveTicketToClient($arguments, $clientId, $actorLabel),
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

        try {
            $payload = $this->ticketCreator->payload($clientId, $arguments);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
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

        $reason = $this->optionalString($arguments, 'reason');
        $note = $this->optionalString($arguments, 'note');
        $resolution = $this->optionalString($arguments, 'resolution');

        if ($status->isTerminal()) {
            if ($reason === null) {
                return ['error' => 'reason is required'];
            }

            $confirm = $this->optionalString($arguments, 'confirm_status');
            if (! $this->confirmStatusMatches($status, $confirm)) {
                return ['error' => 'The typed confirm_status does not match the requested ticket status. Ticket status change cancelled.'];
            }
        }

        try {
            $updated = $this->ticketService->changeStatus(
                $ticket,
                $status,
                TechnicianConfig::requiredAiActorUserId(),
                $note,
                $resolution,
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
                'resolution' => $resolution,
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

        $reason = $this->optionalString($arguments, 'reason');
        $before = $ticket->contact_id;
        $ticket->update(['contact_id' => $contact->id]);

        $this->auditDirectExecution(
            'set_ticket_contact',
            $ticket,
            $actorLabel,
            $this->mutationContentHash('set_ticket_contact', $ticket->id, ['contact_id' => $contact->id, 'reason' => $reason]),
            'Contact changed from '.($before ?? 'none').' to '.$contact->id.($reason ? ': '.$reason : '.'),
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
            'Ticket moved to client #'.$newClient->id.'. Detached assets: '.count($detachedAssets).($reason ? ': '.$reason : '.'),
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
        $allowed = ['ticket_id', 'subject', 'description', 'priority', 'type', 'reason'];
        $unexpected = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unexpected !== []) {
            return ['error' => 'update_ticket accepts only subject, description, priority, and type'];
        }

        $validator = Validator::make($arguments, [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'required', Rule::enum(\App\Enums\TicketPriority::class)],
            'type' => ['sometimes', 'required', Rule::enum(\App\Enums\TicketType::class)],
            'reason' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->first()];
        }

        $validated = $validator->validated();
        unset($validated['reason']);

        return $validated;
    }

    private function ticketStatusFrom(mixed $value): ?\App\Enums\TicketStatus
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return \App\Enums\TicketStatus::tryFrom(trim($value));
    }

    private function confirmStatusMatches(\App\Enums\TicketStatus $status, ?string $typed): bool
    {
        if ($typed === null) {
            return false;
        }

        $typed = mb_strtolower(trim($typed));

        return $typed === mb_strtolower($status->value) || $typed === mb_strtolower($status->label());
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

    private function auditDirectExecution(string $actionType, Ticket $ticket, string $actorLabel, string $contentHash, string $summary, ?int $actorId = null): void
    {
        TechnicianActionLog::create([
            'actor_id' => $actorId ?? TechnicianConfig::requiredAiActorUserId(),
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
