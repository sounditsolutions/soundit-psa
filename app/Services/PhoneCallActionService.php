<?php

namespace App\Services;

use App\Enums\CallDirection;
use App\Enums\PhoneDirectoryListType;
use App\Enums\TranscriptionStatus;
use App\Models\PhoneCall;
use App\Models\PhoneCallActionProposal;
use App\Models\PhoneDirectoryEntry;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\TechnicianConfig;
use App\Support\TranscriptionConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The four agent-side call-log writes Charlie asked for, over the SAME service
 * methods the staff web actions use (card 6aac3226dfebbc36fd7ff4f9). No new
 * domain logic lives here: PhoneCallService::setBillable / markFollowedUp do the
 * work, PhoneDirectoryEntry is written exactly as
 * PhoneDirectoryController::addFromCall writes it, and the re-transcribe path is
 * byte-for-byte CallController::transcribe's status flip + detached command.
 *
 * TWO OF THE FOUR ARE NOT ORDINARY WRITES, and they follow the precedent
 * PhoneCallResolutionService set rather than a new one:
 *
 *  - set_call_billable RE-RUNS PrepayService::debitFromPhoneCall (through
 *    setBillable), so it moves prepay hours on a client contract. It is money.
 *  - block_caller is CLIENT-VISIBLE IN EFFECT: the PHLO reads
 *    PhoneDirectoryEntry and hangs up on a blocked number
 *    (PlivoWebhookController::resolveCaller), so one agent write silences a real
 *    phone line. allow_caller is its inverse and rings a number through.
 *
 * All three are therefore DEFAULT-UNGRANTED including legacy full-surface
 * tokens; a bare or `:staged` grant HOLDS for cockpit approval and only an
 * explicit `:immediate` grant executes now; and a held proposal REVALIDATES at
 * approval — the call still exists, still carries the ticket link the prepay
 * contract resolves through, the number still parses, and the directory has not
 * changed underneath. A stale proposal is consumed as stale, never applied blind.
 *
 * mark_call_followed_up and retry_call_transcription are lower-consequence and
 * take the ordinary granted-immediate intake-manage shape, the same one
 * link_call_to_ticket and create_ticket_from_call already use for the same
 * records (both of those also write phone_calls rows and touch billability
 * through linkCallToTicket). They are still default-ungranted: membership in the
 * intake-manage family confers nothing without the operator's per-tool grant.
 *
 * followed_up_at is the technician's resolution marker that link_call_to_ticket
 * and create_ticket_from_call refuse to override. mark_call_followed_up SETS it
 * and never clears it, so it cannot be used to launder a call past those two
 * refusals in the other direction; a call already followed up is reported as
 * unchanged rather than re-stamped with a new actor.
 */
class PhoneCallActionService
{
    public const ACTION_BILLABLE = 'set_call_billable';

    public const ACTION_BLOCK = 'block_caller';

    public const ACTION_ALLOW = 'allow_caller';

    /** Capabilities served by the held-proposal lane. */
    public const HELD_ACTIONS = [self::ACTION_BILLABLE, self::ACTION_BLOCK, self::ACTION_ALLOW];

    private const REASON_MAX = 800;

    /**
     * Dispatch one call-log action. $staged=true holds a proposal; false executes
     * now (the controller's mode gate has already downgraded a non-immediate
     * grant to staged before we get here).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(string $action, array $arguments, string $actor, bool $staged): array
    {
        if (TechnicianConfig::killSwitchEngaged()) {
            return ['error' => 'Technician kill-switch engaged.'];
        }

        $payload = $this->validatedPayload($action, $arguments);
        if (isset($payload['error'])) {
            return $payload;
        }

        return DB::transaction(function () use ($action, $payload, $actor, $staged): array {
            try {
                $call = $this->validatedCall($action, $payload);
            } catch (\DomainException $e) {
                return ['error' => $e->getMessage()];
            }

            if (! in_array($action, self::HELD_ACTIONS, true)) {
                return $this->apply($action, $call, $payload, $actor);
            }

            if (! $staged) {
                return $this->apply($action, $call, $payload, $actor);
            }

            $payload['snapshot'] = $this->snapshot($action, $call);
            $hash = $this->hash($action, $payload);
            $proposal = PhoneCallActionProposal::where('content_hash', $hash)->where('state', 'pending')
                ->where('action_type', $action)->where('drafted_by', $actor)->first();
            if (! $proposal) {
                $proposal = PhoneCallActionProposal::create([
                    'phone_call_id' => $call->id,
                    'action_type' => $action,
                    'client_id' => $call->client_id,
                    'payload' => $payload,
                    'content_hash' => $hash,
                    'state' => 'pending',
                    'drafted_by' => $actor,
                ]);
                $this->audit($action, $call, $payload, $actor, 'awaiting_approval');
            }

            return [
                'success' => true,
                'staged' => true,
                'proposal_id' => $proposal->id,
                'phone_call_id' => $call->id,
                'ticket_id' => $call->ticket_id,
                'message' => $this->heldMessage($action),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function approve(int $id, User $approver): array
    {
        if (! $this->canApprove($approver)) {
            return ['error' => 'Active administrator or technician permission required.'];
        }
        if (TechnicianConfig::killSwitchEngaged()) {
            return ['error' => 'Technician kill-switch engaged.'];
        }

        return DB::transaction(function () use ($id, $approver): array {
            $proposal = PhoneCallActionProposal::whereKey($id)->lockForUpdate()->first();
            if (! $proposal || $proposal->state !== 'pending') {
                return ['error' => 'Proposal already handled or not found.'];
            }
            $action = (string) $proposal->action_type;
            try {
                $payload = $proposal->payload;
                if (! is_array($payload) || ! hash_equals($proposal->content_hash, $this->hash($action, $payload))
                    || ($payload['phone_call_id'] ?? null) !== $proposal->phone_call_id) {
                    throw new \DomainException('Proposal binding changed; re-stage.');
                }
                // The full precondition set runs again: the call still exists, the
                // ticket link the prepay contract resolves through is still there,
                // the number still parses, the directory has not moved.
                $call = $this->validatedCall($action, $payload);
                if (! $this->snapshotMatches($action, $payload['snapshot'] ?? null, $call)) {
                    throw new \DomainException($this->staleMessage($action));
                }
            } catch (\DomainException $e) {
                $proposal->update(['state' => 'stale', 'handled_at' => now(), 'approved_by' => $approver->id]);

                return ['error' => $e->getMessage()];
            }
            $result = $this->apply($action, $call, $payload, (string) $proposal->drafted_by, $approver->id);
            $proposal->update(['state' => 'done', 'handled_at' => now(), 'approved_by' => $approver->id]);

            return $result + ['proposal_id' => $proposal->id];
        });
    }

    /** @return array<string, mixed> */
    public function deny(int $id, User $approver): array
    {
        if (! $this->canApprove($approver)) {
            return ['error' => 'Active administrator or technician permission required.'];
        }
        $changed = PhoneCallActionProposal::whereKey($id)->where('state', 'pending')->update([
            'state' => 'denied', 'handled_at' => now(), 'approved_by' => $approver->id,
        ]);

        return $changed ? ['success' => true, 'message' => 'Call action denied.'] : ['error' => 'Proposal already handled or not found.'];
    }

    public function canApprove(User $user): bool
    {
        $current = User::find($user->id);

        return $current && $current->is_active && ($current->isAdmin() || $current->isTech());
    }

    /**
     * Closed argument set per action, validated before any row is read. A key
     * this method does not name never reaches the executor.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function validatedPayload(string $action, array $arguments): array
    {
        $allowed = match ($action) {
            self::ACTION_BILLABLE => ['phone_call_id', 'billable', 'reason'],
            self::ACTION_ALLOW => ['phone_call_id', 'label', 'reason'],
            default => ['phone_call_id', 'reason'],
        };
        if (array_diff(array_keys($arguments), $allowed) !== []) {
            return ['error' => $action.' accepts only: '.implode(', ', $allowed).'.'];
        }

        $callId = $arguments['phone_call_id'] ?? null;
        $reason = $arguments['reason'] ?? null;
        if (! is_int($callId) || $callId < 1 || ! is_string($reason)
            || trim($reason) === '' || mb_strlen($reason) > self::REASON_MAX) {
            return ['error' => 'A positive integer phone_call_id and a reason (1–'.self::REASON_MAX.' characters) are required.'];
        }

        $payload = ['phone_call_id' => $callId, 'reason' => trim($reason)];

        if ($action === self::ACTION_BILLABLE) {
            // Explicit desired state, never a toggle: an agent that read a stale
            // billability and sent "flip it" would move prepay the wrong way.
            if (! is_bool($arguments['billable'] ?? null)) {
                return ['error' => 'billable must be an explicit boolean (the desired state, not a toggle).'];
            }
            $payload['billable'] = $arguments['billable'];
        }

        if ($action === self::ACTION_ALLOW) {
            $label = $arguments['label'] ?? null;
            if ($label !== null && (! is_string($label) || mb_strlen($label) > 120)) {
                return ['error' => 'label must be a string of at most 120 characters, or omitted.'];
            }
            $label = is_string($label) ? trim($label) : null;
            $payload['label'] = ($label === null || $label === '') ? null : $label;
        }

        return $payload;
    }

    /**
     * Every service-level refusal the web path already makes, made here in the
     * same words, at staging time AND again at approval:
     *  - no ticket link  -> set_call_billable refuses (the prepay contract is
     *    resolved through the ticket; PhoneCallService::setBillable would write
     *    is_billable and debit nothing).
     *  - an OUTBOUND call -> block/allow refuse. On an outbound call
     *    from_number holds the number WE dialled, so listing it would silence
     *    (or allow-list) the wrong party; the staff call page offers these two
     *    buttons for inbound calls only (calls/show.blade.php).
     *  - unparseable from_number -> block/allow refuse.
     *  - an existing directory entry -> block/allow report it rather than
     *    silently moving a number between lists.
     *  - Processing -> retry_call_transcription refuses.
     */
    private function validatedCall(string $action, array $payload): PhoneCall
    {
        $call = PhoneCall::whereKey($payload['phone_call_id'])->lockForUpdate()->first();
        if (! $call) {
            throw new \DomainException('Phone call not found.');
        }

        if ($action === self::ACTION_BILLABLE && $call->ticket_id === null) {
            throw new \DomainException('Call must be linked to a ticket to set billability; link it with link_call_to_ticket first.');
        }

        if (in_array($action, [self::ACTION_BLOCK, self::ACTION_ALLOW], true)) {
            // Direction guard, the same one the staff call page applies to these
            // two buttons: for an OUTBOUND call from_number is the number this
            // PSA dialled, so the row would list the wrong party's line.
            if ($call->direction?->value !== CallDirection::Inbound->value) {
                throw new \DomainException('Block and allow apply to the caller of an inbound call only; this call is not inbound, and its stored caller number is the number this PSA dialled. Do not retry with this call id; manage such a number in the phone directory instead.');
            }
            $normalized = PhoneNumber::normalize($call->from_number);
            if (! $normalized) {
                throw new \DomainException('Could not parse the caller number.');
            }
            $existing = PhoneDirectoryEntry::where('phone_number', $normalized)->lockForUpdate()->first();
            if ($existing) {
                // allow_caller must never silently un-block a number this agent
                // did not block: the entry is REPORTED with its current list and
                // no row is rewritten, exactly as the web path does.
                throw new \DomainException('This number is already on the '.$existing->list_type->label()
                    .' list (directory entry #'.$existing->id.'); it was not changed. Remove the entry in the phone directory first if the list is wrong.');
            }
        }

        if ($action === 'retry_call_transcription') {
            if (! $call->recording_url) {
                throw new \DomainException('No recording available for this call.');
            }
            if (! TranscriptionConfig::isConfigured()) {
                throw new \DomainException('Transcription is not configured on this instance (no OpenAI API key in Settings > Integrations).');
            }
            if ($call->transcription_status === TranscriptionStatus::Processing) {
                throw new \DomainException('Transcription is already in progress for this call.');
            }
        }

        return $call;
    }

    /**
     * What approval must find unchanged. Deliberately EXCLUDES updated_at for the
     * reason PhoneCallResolutionService documents: that column moves on any write
     * to phone_calls (a post-call vendor transcription update is the normal case),
     * and folding it in made valid proposals consume as stale. These are exactly
     * the fields validatedCall()/apply() rule on for this action.
     *
     * @return array<string, mixed>
     */
    private function snapshot(string $action, PhoneCall $call): array
    {
        if ($action === self::ACTION_BILLABLE) {
            // PrepayService::debitFromPhoneCall resolves the money target THROUGH
            // the ticket ($ticket->contract_id, else the active contract of
            // $ticket->client_id), so the ticket's client and contract are part of
            // what approval must find unchanged. Pinning ticket_id alone let a
            // ticket reassigned between staging and approval move prepay hours
            // against a contract nobody reviewed.
            //
            // ticket_contract_id is NOT sufficient on its own. The debit reads
            // the call's stamped contract, else ContractResolver's answer (the
            // client default or only active contract), and neither is named on
            // the ticket. Two different contracts both compare equal as "null",
            // and a prepay rollover between staging and approval (expire C1,
            // activate C2) is an ordinary billing event, not a contrivance. So we
            // additionally pin resolved_contract_id: the id PrepayService itself
            // would pick, read from the one producer of that answer so this
            // cannot drift from it.
            $ticket = $call->ticket_id === null ? null : Ticket::find($call->ticket_id);
            $resolved = app(PrepayService::class)->resolveContractForPhoneCall($call);

            return [
                'ticket_id' => $call->ticket_id,
                'ticket_client_id' => $ticket?->client_id === null ? null : (int) $ticket->client_id,
                'ticket_contract_id' => $ticket?->contract_id === null ? null : (int) $ticket->contract_id,
                'resolved_contract_id' => $resolved?->id === null ? null : (int) $resolved->id,
                'is_billable' => $call->is_billable === null ? null : (bool) $call->is_billable,
                'duration_seconds' => $call->effectiveDurationSeconds(),
            ];
        }

        return ['from_number' => PhoneNumber::normalize($call->from_number)];
    }

    private function snapshotMatches(string $action, mixed $stored, PhoneCall $call): bool
    {
        if (! is_array($stored)) {
            return false;
        }
        foreach ($this->snapshot($action, $call) as $key => $value) {
            if (! array_key_exists($key, $stored) || $stored[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Perform the action through the SAME service call the web path uses, then
     * write the audit row inside the same transaction.
     *
     * @return array<string, mixed>
     */
    private function apply(string $action, PhoneCall $call, array $payload, string $actor, ?int $approver = null): array
    {
        return match ($action) {
            self::ACTION_BILLABLE => $this->applyBillable($call, $payload, $actor, $approver),
            self::ACTION_BLOCK => $this->applyDirectory($call, $payload, PhoneDirectoryListType::Blocked, $actor, $approver),
            self::ACTION_ALLOW => $this->applyDirectory($call, $payload, PhoneDirectoryListType::Allowed, $actor, $approver),
            'mark_call_followed_up' => $this->applyFollowedUp($call, $payload, $actor),
            'retry_call_transcription' => $this->applyTranscription($call, $payload, $actor),
            default => ['error' => 'Unknown call action: '.$action],
        };
    }

    /** @return array<string, mixed> */
    private function applyBillable(PhoneCall $call, array $payload, string $actor, ?int $approver): array
    {
        $desired = (bool) $payload['billable'];
        $unchanged = $call->is_billable !== null && (bool) $call->is_billable === $desired;

        if (! $unchanged) {
            // The money move: setBillable re-runs PrepayService::debitFromPhoneCall,
            // which creates, adjusts or reverses the prepay debit for this call.
            app(PhoneCallService::class)->setBillable($call, $desired);
        } else {
            // An unchanged value is still a decision (#8459): mark it so triage leaves it
            // alone. Prepay is not re-run, since is_billable did not change.
            app(PhoneCallService::class)->markBillableExplicit($call);
        }

        $this->audit(self::ACTION_BILLABLE, $call, $payload, $actor, $unchanged ? 'no_op' : 'executed', $approver);

        return [
            'success' => true,
            'staged' => false,
            'unchanged' => $unchanged,
            'phone_call_id' => $call->id,
            'ticket_id' => $call->ticket_id,
            'is_billable' => (bool) $call->fresh()->is_billable,
            'message' => $unchanged
                ? 'Call was already marked '.($desired ? 'billable' : 'non-billable').'; prepay untouched.'
                : 'Call marked '.($desired ? 'billable' : 'non-billable').'; prepay re-evaluated for this call.',
        ];
    }

    /** @return array<string, mixed> */
    private function applyDirectory(PhoneCall $call, array $payload, PhoneDirectoryListType $type, string $actor, ?int $approver): array
    {
        // validatedCall() has already refused an unparseable number and an
        // existing entry, under the row lock, immediately above this call.
        $normalized = (string) PhoneNumber::normalize($call->from_number);
        $label = $payload['label'] ?? null;

        $entry = PhoneDirectoryEntry::create([
            'phone_number' => $normalized,
            'list_type' => $type,
            'label' => $label,
            'reason' => 'Added from call #'.$call->id,
            'added_by_user_id' => TechnicianConfig::requiredAiActorUserId(),
        ]);

        $action = $type === PhoneDirectoryListType::Blocked ? self::ACTION_BLOCK : self::ACTION_ALLOW;
        $this->audit($action, $call, $payload, $actor, 'executed', $approver);

        return [
            'success' => true,
            'staged' => false,
            'phone_call_id' => $call->id,
            'directory_entry_id' => $entry->id,
            'list_type' => $type->value,
            'message' => $type === PhoneDirectoryListType::Blocked
                ? 'Caller added to the Blocked list; future calls from this number will be hung up by the IVR.'
                : 'Caller added to the Allowed list; future calls from this number will ring through.',
        ];
    }

    /** @return array<string, mixed> */
    private function applyFollowedUp(PhoneCall $call, array $payload, string $actor): array
    {
        // Never re-stamp an existing follow-up: followed_up_at/by is a
        // technician's resolution marker and overwriting the actor would erase
        // who resolved it.
        $unchanged = $call->isFollowedUp();
        if (! $unchanged) {
            app(PhoneCallService::class)->markFollowedUp($call, TechnicianConfig::requiredAiActorUserId());
        }

        $this->audit('mark_call_followed_up', $call, $payload, $actor, $unchanged ? 'no_op' : 'executed');

        return [
            'success' => true,
            'unchanged' => $unchanged,
            'phone_call_id' => $call->id,
            'followed_up_at' => optional($call->fresh()->followed_up_at)->toIso8601String(),
            'message' => $unchanged
                ? 'Call was already marked followed up; the existing follow-up actor and timestamp were preserved.'
                : 'Call marked followed up.',
        ];
    }

    /** @return array<string, mixed> */
    private function applyTranscription(PhoneCall $call, array $payload, string $actor): array
    {
        // Byte-for-byte CallController::transcribe's dispatch: flip the status to
        // Pending and spawn the same detached artisan command. No transcription
        // logic is duplicated here.
        $call->update(['transcription_status' => TranscriptionStatus::Pending]);
        Process::run(sprintf('php %s calls:transcribe %d > /dev/null 2>&1 &', base_path('artisan'), $call->id));

        $this->audit('retry_call_transcription', $call, $payload, $actor, 'executed');

        return [
            'success' => true,
            'phone_call_id' => $call->id,
            'transcription_status' => TranscriptionStatus::Pending->value,
            'message' => 'Transcription re-queued; read the result back with get_phone_call.',
        ];
    }

    private function heldMessage(string $action): string
    {
        return match ($action) {
            self::ACTION_BILLABLE => 'Billability change held for cockpit approval; no prepay was moved.',
            self::ACTION_BLOCK => 'Caller block held for cockpit approval; the number is not blocked yet.',
            default => 'Caller allow-list entry held for cockpit approval; nothing was added yet.',
        };
    }

    private function staleMessage(string $action): string
    {
        return $action === self::ACTION_BILLABLE
            ? 'Call billability, its ticket link, that ticket\'s client, the prepay contract the debit resolves to, or the billed duration changed since this was proposed; re-stage.'
            : 'Caller number changed since this was proposed; re-stage.';
    }

    /**
     * The audit row. Ids, the desired state and the reason only — never
     * $call->transcription, and never the caller's number: a blocked-caller row
     * would otherwise put a phone number into the action log.
     */
    private function audit(string $action, PhoneCall $call, array $payload, string $actor, string $status, ?int $approver = null): void
    {
        $detail = match ($action) {
            self::ACTION_BILLABLE => ' billable='.(($payload['billable'] ?? false) ? 'true' : 'false'),
            self::ACTION_BLOCK => ' list=blocked',
            self::ACTION_ALLOW => ' list=allowed',
            default => '',
        };

        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::requiredAiActorUserId(),
            'actor_label' => $actor,
            'approver_user_id' => $approver,
            'action_type' => $action,
            'tier' => 'approve',
            'result_status' => $status,
            'ticket_id' => $call->ticket_id,
            'client_id' => $call->client_id,
            'content_hash' => $this->hash($action, $payload),
            'correlation_id' => (string) Str::uuid(),
            'summary' => mb_substr('Phone call #'.$call->id.$detail.': '.$payload['reason'], 0, 1000),
        ]);
    }

    private function hash(string $action, array $payload): string
    {
        return hash('sha256', json_encode(['action' => $action] + $payload, JSON_THROW_ON_ERROR));
    }
}
