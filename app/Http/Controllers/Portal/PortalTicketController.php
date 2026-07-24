<?php

namespace App\Http\Controllers\Portal;

use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\AttachmentService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalTicketController extends Controller
{
    public function __construct(
        private readonly TicketService $ticketService,
    ) {}

    public function index(Request $request): View
    {
        $person = $request->attributes->get('portal_person');
        $clientId = $request->attributes->get('portal_client_id');

        $query = Ticket::where('client_id', $clientId);
        if (! $person->company_wide_access) {
            $query->where('contact_id', $person->id);
        }

        $tab = $request->query('tab', 'open');
        if ($tab === 'open') {
            $query->open();
        } elseif ($tab === 'closed') {
            $query->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed]);
        }
        // 'all' = no status filter

        if ($search = $request->query('search')) {
            $query->where('subject', 'like', '%'.$search.'%');
        }

        $tickets = $query->with('categoryNode.parent.parent')
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        return view('portal.tickets.index', compact('tickets', 'tab', 'search'));
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $person = $request->attributes->get('portal_person');
        $clientId = $request->attributes->get('portal_client_id');

        $this->authorizePortalAccess($ticket, $clientId, $person);

        $notes = $ticket->notes()
            ->portalVisible()
            ->with(['attachments', 'email'])
            ->orderBy('noted_at')
            ->get();

        $publicCalls = $ticket->phoneCalls()
            ->where('summary_is_public', true)
            ->whereNotNull('call_summary')
            ->orderBy('started_at')
            ->get();

        $ticket->loadMissing('contract', 'assignee', 'attachments');

        return view('portal.tickets.show', compact('ticket', 'notes', 'publicCalls'));
    }

    public function create(Request $request): View
    {
        return view('portal.tickets.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $person = $request->attributes->get('portal_person');
        $clientId = $request->attributes->get('portal_client_id');

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'urgent' => ['nullable', 'boolean'],
        ]);

        $ticket = $this->ticketService->createTicket([
            'client_id' => $clientId,
            'contact_id' => $person->id,
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'source' => TicketSource::Portal,
            'type' => TicketType::ServiceRequest,
            'priority' => $request->boolean('urgent') ? TicketPriority::P2 : TicketPriority::P3,
            'status' => TicketStatus::New,
            'opened_at' => now(),
        ], null);

        return redirect()->route('portal.tickets.show', $ticket)
            ->with('success', 'Ticket created successfully.');
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $person = $request->attributes->get('portal_person');
        $clientId = $request->attributes->get('portal_client_id');

        $this->authorizePortalAccess($ticket, $clientId, $person);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $this->ticketService->addPortalReply($ticket, $person, $validated['body']);

        return redirect()->route('portal.tickets.show', $ticket)
            ->with('success', 'Reply added.');
    }

    public function confirmResolved(Request $request, Ticket $ticket): RedirectResponse
    {
        $person = $request->attributes->get('portal_person');
        $clientId = $request->attributes->get('portal_client_id');

        $this->authorizePortalAccess($ticket, $clientId, $person);

        if ($ticket->status !== TicketStatus::Resolved) {
            return back()->with('error', 'This ticket is not in a resolved state.');
        }

        $ticket->update([
            'status' => TicketStatus::Closed,
            'closed_at' => now(),
        ]);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_name' => 'System',
            'who_type' => WhoType::System,
            'body' => "Confirmed resolved by {$person->full_name} via portal.",
            'note_type' => NoteType::StatusChange,
            'is_private' => true,
            'status_from' => TicketStatus::Resolved,
            'status_to' => TicketStatus::Closed,
            'noted_at' => now(),
        ]);

        return redirect()->route('portal.tickets.show', $ticket)
            ->with('success', 'Ticket confirmed as resolved. Thank you!');
    }

    public function reopen(Request $request, Ticket $ticket): RedirectResponse
    {
        $person = $request->attributes->get('portal_person');
        $clientId = $request->attributes->get('portal_client_id');

        $this->authorizePortalAccess($ticket, $clientId, $person);

        if ($ticket->status !== TicketStatus::Resolved) {
            return back()->with('error', 'This ticket is not in a resolved state.');
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $ticket->update([
            'status' => TicketStatus::InProgress,
            'resolved_at' => null,
        ]);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_name' => 'System',
            'who_type' => WhoType::System,
            'body' => "Reopened by {$person->full_name} via portal: still an issue.",
            'note_type' => NoteType::StatusChange,
            'is_private' => true,
            'status_from' => TicketStatus::Resolved,
            'status_to' => TicketStatus::InProgress,
            'noted_at' => now(),
        ]);

        // Also add their explanation as a public reply
        $this->ticketService->addPortalReply($ticket->fresh(), $person, $validated['body']);

        return redirect()->route('portal.tickets.show', $ticket)
            ->with('success', 'Ticket reopened. Our team will follow up.');
    }

    public function uploadAttachment(Request $request, Ticket $ticket, AttachmentService $attachmentService): JsonResponse
    {
        $clientId = $request->attributes->get('portal_client_id');
        if ($ticket->client_id !== $clientId) {
            abort(403);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimetypes:image/png,image/jpeg,image/gif,image/webp'],
            'note_id' => ['nullable', 'integer'],
        ]);

        $attachment = $attachmentService->storeUpload($request->file('file'));

        $noteId = $request->input('note_id');
        if ($noteId && $ticket->notes()->where('id', $noteId)->exists()) {
            $attachmentService->linkTo($attachment, 'App\\Models\\TicketNote', $noteId);
        } else {
            $attachmentService->linkTo($attachment, 'App\\Models\\Ticket', $ticket->id);
        }

        return response()->json([
            'url' => route('portal.attachments.show', [$attachment->id, $attachment->filename]),
            'markdown' => "![{$attachment->original_filename}](".route('portal.attachments.show', [$attachment->id, $attachment->filename]).')',
            'id' => $attachment->id,
        ]);
    }

    private function authorizePortalAccess(Ticket $ticket, int $clientId, $person): void
    {
        if ($ticket->client_id !== $clientId) {
            abort(403);
        }

        if (! $person->company_wide_access && $ticket->contact_id !== $person->id) {
            abort(403);
        }
    }
}
