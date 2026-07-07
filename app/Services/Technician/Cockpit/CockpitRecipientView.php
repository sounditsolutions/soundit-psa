<?php

namespace App\Services\Technician\Cockpit;

use App\Models\TechnicianRun;
use App\Services\Email\EmailRecipientResolver;

/**
 * Builds the cockpit approval card's recipient block data (psa-kt82 PR B, gate 4):
 * the fully RESOLVED default To, the reply-all CC set, and the pickable candidate
 * addresses (ticket contact, client contacts, thread participants) — all real
 * addresses as they would send, never labels or references. Read-only.
 */
class CockpitRecipientView
{
    /** Only the email-sending reply cards carry a recipient block. */
    private const EMAIL_ACTIONS = ['send_reply', 'stage_email', 'propose_resolution'];

    public function __construct(private readonly EmailRecipientResolver $resolver) {}

    /**
     * @return array{
     *   to: array{email: ?string, name: ?string},
     *   reply_all: array<int,string>,
     *   candidates: array<int,array{email:string,name:?string,source:string}>
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
        $replyAll = $this->resolver->replyAll($ticket);

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
        ];
    }
}
