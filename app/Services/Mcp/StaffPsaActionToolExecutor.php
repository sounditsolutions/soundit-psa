<?php

namespace App\Services\Mcp;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
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
use App\Models\TicketNote;
use App\Models\User;
use App\Services\AssetService;
use App\Services\Assistant\AssistantTicketCreator;
use App\Services\ClientService;
use App\Services\EmailService;
use App\Services\PersonService;
use App\Services\PhoneCallService;
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
        private readonly ClientService $clientService,
        private readonly PersonService $personService,
        private readonly AssetService $assetService,
        private readonly PhoneCallService $phoneCallService,
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

        $unexpected = array_values(array_diff(array_keys($arguments), ['contact_id']));
        if ($unexpected !== []) {
            return ['error' => 'set_primary_contact accepts only contact_id.'];
        }

        $updated = $this->personService->updatePerson($person, ['is_primary' => true]);

        $this->auditEntityExecution(
            'set_primary_contact',
            'person',
            (int) $updated->id,
            (int) $updated->client_id,
            $actorLabel,
            $this->mutationContentHash('set_primary_contact', (int) $updated->id, ['is_primary' => true]),
            'Contact set as primary: '.$this->contactDisplayName($updated).'.',
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

        $this->email->linkEmailToTicket($email, $ticket);

        $this->auditEntityExecution(
            'link_email_to_ticket',
            'email',
            (int) $email->id,
            (int) $ticket->client_id,
            $actorLabel,
            $this->mutationContentHash('link_email_to_ticket', (int) $email->id, ['ticket_id' => $ticket->id], $reason),
            'Email #'.$email->id.' linked to ticket #'.$ticket->id.': '.$reason,
            TechnicianConfig::requiredAiActorUserId(),
        );

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

        $ticket = $this->email->autoCreateTicketFromEmail($email);

        $this->auditEntityExecution(
            'create_ticket_from_email',
            'email',
            (int) $email->id,
            (int) $ticket->client_id,
            $actorLabel,
            $this->mutationContentHash('create_ticket_from_email', (int) $email->id, ['ticket_id' => $ticket->id], $reason),
            'Email #'.$email->id.' created ticket #'.$ticket->id.': '.$reason,
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'email_id' => $email->id,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'message' => 'Ticket created from email.',
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

        $this->phoneCallService->linkCallToTicketWithNote($call, $ticket->id, "Linked via MCP: {$reason}");

        $this->auditEntityExecution(
            'link_call_to_ticket',
            'phone_call',
            (int) $call->id,
            (int) $ticket->client_id,
            $actorLabel,
            $this->mutationContentHash('link_call_to_ticket', (int) $call->id, ['ticket_id' => $ticket->id], $reason),
            'Phone call #'.$call->id.' linked to ticket #'.$ticket->id.': '.$reason,
            TechnicianConfig::requiredAiActorUserId(),
        );

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

        $ticket = $this->phoneCallService->createTicketFromCall($call);

        $this->auditEntityExecution(
            'create_ticket_from_call',
            'phone_call',
            (int) $call->id,
            (int) $ticket->client_id,
            $actorLabel,
            $this->mutationContentHash('create_ticket_from_call', (int) $call->id, ['ticket_id' => $ticket->id], $reason),
            'Phone call #'.$call->id.' created ticket #'.$ticket->id.': '.$reason,
            TechnicianConfig::requiredAiActorUserId(),
        );

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
        $this->recordActionLog($actionType, $ticket->id, (int) $ticket->client_id, $actorLabel, $contentHash, $summary, $actorId);
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

    private function recordActionLog(string $actionType, ?int $ticketId, ?int $clientId, string $actorLabel, string $contentHash, string $summary, ?int $actorId): void
    {
        TechnicianActionLog::create([
            'actor_id' => $actorId ?? TechnicianConfig::requiredAiActorUserId(),
            'approver_user_id' => null,
            'actor_label' => $actorLabel,
            'action_type' => $actionType,
            'tier' => TechnicianTier::Approve->value,
            'result_status' => 'executed',
            'ticket_id' => $ticketId,
            'client_id' => $clientId,
            'run_id' => null,
            'content_hash' => $contentHash,
            'summary' => mb_substr($summary, 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
