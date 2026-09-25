<?php

namespace App\Services\Technician\Scheduled;

use App\Enums\NoteType;
use App\Models\Ticket;
use App\Models\TicketNote;
use Illuminate\Support\Facades\DB;

final class ScheduledOutbox
{
    public function deliver(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $item = DB::table('scheduled_note_outbox')->where('id', $id)->lockForUpdate()->first();
            if (! $item || $item->note_id) {
                return $item !== null;
            }
            $row = DB::table('scheduled_authorizations')->find($item->authorization_id);
            if (! $row || ! Ticket::automationVisible()->find($row->ticket_id)) {
                DB::table('scheduled_note_outbox')->where('id', $id)->update(['delivery_error' => 'ticket_missing']);

                return false;
            }
            // No raw payload, identity, human text, exception or vendor response is rendered.
            $event = in_array($item->event, ['scheduled', 'waiting', 'cancelled', 'expired', 'blocked', 'submitted', 'completed', 'failed', 'uncertain', 'abandoned_no_send'], true) ? $item->event : 'unknown';
            // A token-approved row (immediate lane) has no approver. "Approver #." would
            // read as a missing record; the ticket note says plainly that a token queued it
            // under its own standing permission, which is the fact a technician reading the
            // ticket needs. The token id is not rendered — it is not ticket-facing data.
            $authority = $row->approver_user_id === null
                ? 'Queued by an MCP token under its own standing permission; no human approver.'
                : "Approver #{$row->approver_user_id}.";
            $body = "Scheduled approval #{$row->id}, run #{$row->run_id}: {$event}. {$authority}";
            $reason = in_array($item->reason, ['window_closed', 'attempt_limit', 'operator_cancelled', 'adapter_unavailable', 'preflight_refused', 'authorization_changed', 'intent_outcome_unknown', 'vendor_receipt', 'no_vendor_request', 'quiesced_no_send', 'offline', 'read_unavailable', 'cooldown', 'kill_switch', 'clock_unhealthy'], true) ? $item->reason : null;
            if ($reason !== null) {
                $body .= " Reason: {$reason}.";
            }
            if ($event === 'scheduled') {
                $body .= " Window [{$row->not_before}, {$row->expires_at}) UTC; display zone {$row->display_timezone}.";
            }
            if (in_array($event, ['uncertain', 'submitted'], true)) {
                $body .= ' Do not retry. Staff reconciliation required; cancellation cannot prove prevention.';
            }
            if (in_array($event, ['cancelled', 'expired', 'blocked'], true)) {
                $body .= ' No dispatch intent was issued.';
            }
            $note = TicketNote::create([
                'ticket_id' => $row->ticket_id, 'author_name' => 'Scheduled approval system',
                'body' => $body, 'note_type' => NoteType::System, 'is_private' => true,
                'ai_authored' => false, 'is_billable' => false, 'noted_at' => now(),
            ]);
            // Note insert and acknowledgement are one local transaction. No email method is called.
            DB::table('scheduled_note_outbox')->where('id', $id)->update(['note_id' => $note->id, 'delivery_error' => null]);

            return true;
        }, 3);
    }

    public function purge(): int
    {
        $now = app(ScheduledClock::class)->now();

        return DB::table('scheduled_authorizations')->whereIn('state', ['cancelled', 'expired', 'blocked', 'completed', 'failed', 'uncertain', 'abandoned_no_send'])
            ->whereNotNull('finished_at')->where('finished_at', '<=', $now->subDays(30))->whereNotNull('ciphertext')
            ->update(['ciphertext' => null, 'purged_at' => $now]);
    }
}
