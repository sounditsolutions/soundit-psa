<?php

namespace App\Services\Technician\Cockpit;

use App\Models\TechnicianRun;
use App\Services\Email\EmailRecipientResolver;
use App\Support\TechnicianConfig;

/**
 * Builds the cockpit approval card's recipient block data (psa-kt82 PR B, gate 4):
 * the fully RESOLVED default To, the reply-all CC set, and the pickable candidate
 * addresses (ticket contact, client contacts, thread participants) — all real
 * addresses as they would send, never labels or references. Read-only.
 *
 * psa-w4e0 adds the staged proposal: a stage_email run may carry an agent-proposed
 * To/CC set in proposed_meta (resolved at stage time). It prefills the card so the
 * approver reviews exactly what was staged, and the flat candidate list powers the
 * live "outside known contacts" highlight for both proposed and operator-typed
 * custom addresses.
 */
class CockpitRecipientView
{
    /** Only the email-sending reply cards carry a recipient block. */
    private const EMAIL_ACTIONS = ['send_reply', 'stage_email', 'propose_resolution'];

    /** Per-instance memo — Setting reads are DB queries and for() runs once per card. */
    private ?bool $arbitraryAllowed = null;

    public function __construct(private readonly EmailRecipientResolver $resolver) {}

    /**
     * @return array{
     *   to: array{email: ?string, name: ?string},
     *   reply_all: array<int,string>,
     *   candidates: array<int,array{email:string,name:?string,source:string}>,
     *   candidate_emails: array<int,string>,
     *   proposed: array{to: string, cc: array<int,string>}|null,
     *   arbitrary_allowed: bool
     * }|null
     */
    public function for(TechnicianRun $run): ?array
    {
        if (! in_array($run->action_type, self::EMAIL_ACTIONS, true)) {
            return null;
        }

        $ticket = $run->ticket;
        if (! $ticket) {
            return null;
        }

        $candidates = $this->resolver->candidates($ticket);
        $replyAll = $this->resolver->replyAll($ticket, $candidates); // reuse candidates — no double query

        $rows = [];
        if ($candidates->contactEmail) {
            $rows[$candidates->contactEmail] = ['email' => $candidates->contactEmail, 'name' => $candidates->contactName, 'source' => 'contact'];
        }
        foreach ($candidates->clientContacts as $c) {
            $rows[$c['email']] ??= ['email' => $c['email'], 'name' => $c['name'], 'source' => 'client'];
        }
        foreach ($candidates->threadParticipants as $p) {
            $rows[$p['email']] ??= ['email' => $p['email'], 'name' => $p['name'], 'source' => 'thread'];
        }

        return [
            'to' => ['email' => $candidates->contactEmail, 'name' => $candidates->contactName],
            'reply_all' => $replyAll['cc'],
            'candidates' => array_values($rows),
            'proposed' => $this->proposedRecipients($run),
            'arbitrary_allowed' => $this->arbitraryAllowed ??= TechnicianConfig::stagedSendsAllowArbitraryRecipients(),
        ];
    }

    /**
     * The stage-time resolved To/CC a stage_email run proposed, if any. Meta is
     * tolerated loosely (older runs have no recipient keys) — anything malformed
     * collapses to null and the card falls back to the contact default.
     *
     * @return array{to: string, cc: array<int,string>}|null
     */
    private function proposedRecipients(TechnicianRun $run): ?array
    {
        if ($run->action_type !== 'stage_email') {
            return null;
        }

        $meta = $run->proposed_meta ?? [];
        $to = $meta['to'] ?? null;
        if (! is_string($to) || trim($to) === '') {
            return null;
        }

        $cc = [];
        foreach ((array) ($meta['cc'] ?? []) as $addr) {
            if (is_string($addr) && trim($addr) !== '') {
                $cc[] = strtolower(trim($addr));
            }
        }

        return ['to' => strtolower(trim($to)), 'cc' => $cc];
    }
}
