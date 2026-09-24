<?php

namespace App\Services\Mcp;

use App\Models\AssistantConversation;
use App\Models\Email;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Support\TimelineCursor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** One bounded projection for staff HTML and MCP; source objects never enter the MCP response. */
final class TicketTimeline
{
    public const TYPES = ['note', 'call', 'email', 'ai_chat', 'tool'];

    public function page(Ticket $ticket, array $input = [], bool $models = false): array
    {
        if (! $models && $ticket->isUnverifiedContactIntake()) {
            throw new \DomainException('Unverified contact intake.');
        }

        $limit = $input['limit'] ?? 20;
        if (! is_int($limit) || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('limit must be an integer from 1 to 50');
        }
        $types = $input['types'] ?? self::TYPES;
        if (! is_array($types) || ! array_is_list($types) || $types === []
            || count($types) > 5 || array_filter($types, fn ($t) => ! is_string($t) || ! in_array($t, self::TYPES, true))) {
            throw new InvalidArgumentException('types must be a nonempty list of note, call, email, ai_chat, tool');
        }
        $types = array_values(array_unique($types));
        sort($types);
        $scope = 'timeline:'.$ticket->id.':'.$ticket->client_id.':'.implode(',', $types);
        $queries = [];
        if (in_array('note', $types, true)) {
            $queries[] = $this->noteQuery($models)->where('ticket_id', $ticket->id)
                ->selectRaw("id, COALESCE(noted_at, created_at, '1970-01-01 00:00:00') as at, 'note' as source")->toBase();
        }
        if (in_array('call', $types, true)) {
            $queries[] = PhoneCall::query()->where('ticket_id', $ticket->id)->where($this->clientFence($ticket))
                ->selectRaw("id, COALESCE(started_at, created_at, '1970-01-01 00:00:00') as at, 'call' as source")->toBase();
        }
        if (in_array('email', $types, true)) {
            $emails = Email::query()->where('ticket_id', $ticket->id)->where($this->clientFence($ticket));
            if (in_array('note', $types, true)) {
                // EmailService::linkEmailToTicket mirrors a ticket-linked email into a ticket
                // note (email_id set, noted_at = received_at), so carrying both sources would
                // show one message twice and spend two slots of the same page limit. The richer
                // note wins; the bare envelope row survives only when notes are filtered out of
                // this request. Only mirrors the note source actually returns count as a
                // duplicate, so a trashed mirror still suppresses the email on the HTML page
                // (which renders the deleted-note placeholder) but not in the MCP projection.
                $emails->whereNotExists(function ($n) use ($ticket, $models): void {
                    $n->selectRaw('1')->from('ticket_notes')
                        ->whereColumn('ticket_notes.email_id', 'emails.id')
                        ->where('ticket_notes.ticket_id', $ticket->id)
                        ->when(! $models, fn ($q) => $q->whereNull('ticket_notes.deleted_at'));
                });
            }
            $queries[] = $emails->selectRaw("id, COALESCE(received_at, created_at, '1970-01-01 00:00:00') as at, 'email' as source")->toBase();
        }
        if (in_array('ai_chat', $types, true)) {
            $queries[] = AssistantConversation::query()->where('context_type', 'ticket')->where('context_id', $ticket->id)
                ->selectRaw("id, COALESCE(created_at, '1970-01-01 00:00:00') as at, 'ai_chat' as source")->toBase();
        }
        $activity = app(TicketToolActivity::class);
        if (in_array('tool', $types, true)) {
            $queries[] = $activity->query($ticket)->selectRaw("id, COALESCE(created_at, '1970-01-01 00:00:00') as at, CASE WHEN source = 'call' THEN 'tool_call' ELSE 'tool_action' END as source");
        }
        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }
        $query = DB::query()->fromSub($union, 'timeline');
        $after = TimelineCursor::apply($query, $input, $scope);
        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        if ($after) {
            $rows = $rows->reverse()->values();
        }
        $items = $rows->map(function ($row) use ($ticket, $activity, $models): array {
            $kind = str_starts_with($row->source, 'tool_') ? 'tool' : $row->source;
            $entry = ['id' => $row->source.':'.$row->id, 'kind' => $kind, 'at' => $row->at,
                'actor' => 'System', 'summary' => ''];
            $model = null;
            if ($kind === 'tool') {
                $raw = $activity->query($ticket)->where('id', $row->id)
                    ->where('source', $row->source === 'tool_call' ? 'call' : 'action')->first();
                if ($raw) {
                    $safe = $activity->present($raw);
                    $entry = array_merge($entry, array_intersect_key($safe, array_flip(['actor', 'summary', 'tool', 'result_redacted'])));
                    $entry['state'] = $safe['state'] === 'failure' ? 'failed' : $safe['state'];
                } else {
                    $entry['state'] = 'pending';
                    $entry['summary'] = 'Activity no longer available; execution not confirmed.';
                }
            } elseif ($kind === 'note') {
                $model = $this->noteQuery($models)->with('author', 'attachments', 'contract', 'email')->where('ticket_id', $ticket->id)->find($row->id);
                $entry['actor'] = $model?->author?->name ?? $model?->author_name ?? 'System';
                $entry['summary'] = $this->text($model?->body);
            } elseif ($kind === 'call') {
                $model = PhoneCall::with('answeredBy', 'person')->where('ticket_id', $ticket->id)->where($this->clientFence($ticket))->find($row->id);
                $entry['actor'] = $model?->answeredBy?->name ?? 'Phone';
                $entry['summary'] = $this->text($model?->call_summary ?? $model?->notes);
            } elseif ($kind === 'email') {
                $model = Email::where('ticket_id', $ticket->id)->where($this->clientFence($ticket))->find($row->id);
                $entry['actor'] = $model?->from_name ?? 'Email';
                $entry['summary'] = $this->text(($model?->subject ?? '').' — '.($model?->body_preview ?? ''));
                $entry['direction'] = $model?->direction?->value;
                $entry['email_id'] = (int) $row->id;
            } else {
                $model = AssistantConversation::with('user')->where('context_type', 'ticket')->where('context_id', $ticket->id)->find($row->id);
                if ($models && $model) {
                    $model->load(['messages' => fn ($q) => $q->whereIn('role', ['user', 'assistant'])]);
                }
                $entry['actor'] = $model?->user?->name ?? 'Assistant';
                // No assistant message/tool payloads in the API projection.
                $entry['summary'] = $this->text($model?->title ?? 'AI conversation');
            }
            if ($models) {
                $entry['model'] = $model;
            }

            return $entry;
        })->all();

        return ['items' => $items, 'states' => str_replace('failure =', 'failed =', TicketToolActivity::STATES),
            'coverage' => 'Ticket-associated records only. Tool outputs and arguments withheld; absence is not proof of no activity.']
            + TimelineCursor::metadata($rows, $limit, $more, $scope, $after, isset($input['before']) || isset($input['after']), $input['after'] ?? $input['before'] ?? null);
    }

    /**
     * The staff page composed its notes from Ticket::notes(), which is
     * hasMany(...)->withTrashed(), and show.blade.php renders a soft-deleted note as a
     * "Note deleted" placeholder. A raw TicketNote::query() applies the SoftDeletes
     * scope, which would silently drop those audit placeholders, so the HTML
     * projection keeps trashed rows. The MCP projection keeps the default scope:
     * deleted note bodies are not a read that surface ever served.
     */
    private function noteQuery(bool $models): \Illuminate\Database\Eloquent\Builder
    {
        return $models ? TicketNote::withTrashed() : TicketNote::automationVisible();
    }

    /**
     * A ticket-linked call or email may carry no client of its own (an intake
     * ticket whose client_id is still NULL, or a Plivo row linked before caller
     * resolution). Equality alone is never true for NULL, which silently dropped
     * rows the ticket_id relation used to show, so keep the cross-client fence
     * but let an unresolved client through.
     *
     * The ticket itself may equally be unlinked. orWhere('client_id', null)
     * compiles to orWhereNull, so the group would degenerate to
     * (client_id IS NULL OR client_id IS NULL) and drop every ticket-linked row
     * whose own client was since resolved. With no ticket client there is no
     * cross-client boundary to draw, so the ticket_id relation stands alone.
     */
    private function clientFence(Ticket $ticket): \Closure
    {
        return $ticket->client_id === null
            ? fn ($q) => $q
            : fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $ticket->client_id);
    }

    private function text(?string $text): string
    {
        // Not strip_tags(): it also discards everything from a bare '<' to the
        // next '>' or to end of string, so a note reading "latency <500ms before,
        // >2s after" loses real content with no truncation signal. Remove markup only.
        $plain = preg_replace('#<(?:/?[a-zA-Z][^>]*|!--.*?--|![^>]*)>#s', '', $text ?? '');

        return mb_substr($plain ?? strip_tags($text ?? ''), 0, 4000);
    }
}
