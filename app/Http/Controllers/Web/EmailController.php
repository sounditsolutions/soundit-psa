<?php

namespace App\Http\Controllers\Web;

use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Graph\GraphClientException;
use App\Services\PersonService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailController extends Controller
{
    private const FREE_EMAIL_DOMAINS = \App\Support\FreeEmailDomains::ALL;

    public function __construct(
        private readonly EmailService $emailService,
        private readonly TicketService $ticketService,
        private readonly PersonService $personService,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'is_read', 'date_from', 'date_to', 'client_id', 'no_client', 'preset', 'direction']);

        // Default to the Inbox folder when no query params
        if (! $request->hasAny(['preset', 'search', 'is_read', 'date_from', 'date_to', 'client_id', 'no_client', 'direction'])) {
            $filters['preset'] = 'inbox';
        }

        $emails = $this->emailService->getEmailList($filters);
        $folderCounts = $this->emailService->folderCounts();

        return view('emails.index', [
            'emails' => $emails,
            'filters' => $filters,
            'folder' => $this->emailService->resolveFolder($filters['preset'] ?? null),
            'folderCounts' => $folderCounts,
            'defaultWindow' => $this->emailService->appliesDefaultWindow($filters),
            'needsAttentionCount' => $folderCounts['inbox'],
            'noClientCount' => $folderCounts['no_client'],
            // active(), not operational(): email intake filters must surface prospect conversations.
            'clients' => Client::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function dismiss(Email $email)
    {
        $this->emailService->dismissEmail($email, auth()->id());
        $this->emailService->forgetFolderCounts();

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->back()->with('success', 'Email archived.');
    }

    public function undismiss(Email $email)
    {
        $this->emailService->undismissEmail($email);
        $this->emailService->forgetFolderCounts();

        return redirect()->back()->with('success', 'Email moved out of Archive.');
    }

    public function emailBulkAction(Request $request)
    {
        $request->validate([
            'action' => ['required', 'string', 'in:dismiss,link_ticket,mark_read,mark_unread'],
            'email_ids' => ['required', 'array', 'min:1'],
            'email_ids.*' => ['required', 'integer', 'exists:emails,id'],
            'ticket_id' => ['required_if:action,link_ticket', 'nullable', 'integer', 'exists:tickets,id'],
        ]);

        $emailIds = $request->input('email_ids');
        $action = $request->input('action');

        if ($action === 'dismiss') {
            $affected = $this->emailService->bulkDismiss($emailIds, auth()->id());
            $message = "{$affected} email(s) archived.";
        } elseif ($action === 'mark_read' || $action === 'mark_unread') {
            $affected = $this->emailService->bulkSetRead($emailIds, $action === 'mark_read');
            $message = "{$affected} email(s) marked ".($action === 'mark_read' ? 'read.' : 'unread.');
        } else {
            $affected = $this->emailService->bulkLinkToTicket($emailIds, (int) $request->input('ticket_id'));
            $ticket = Ticket::find($request->input('ticket_id'));
            $message = "{$affected} email(s) linked to {$ticket->display_id}.";
        }

        $this->emailService->forgetFolderCounts();

        // Return to the folder the action was taken from.
        $back = $this->paneReturnTo($request);

        return ($back ? redirect($back) : redirect()->route('emails.index'))
            ->with('success', $message);
    }

    public function show(Request $request, Email $email)
    {
        $email->load(['client', 'person', 'user', 'ticket', 'ticket.categoryNode.parent.parent']);

        // Mark as read
        if (! $email->is_read) {
            $email->update(['is_read' => true]);
        }

        // Reading pane (/emails at desktop width): the same email, the same read
        // marking above, rendered as a fragment without the layout.
        if ($request->boolean('pane')) {
            return view('emails._pane', [
                'email' => $email,
                'returnTo' => $this->paneReturnTo($request) ?? route('emails.index', [], false),
            ]);
        }

        // Fetch open local tickets for this client (for manual link list)
        $openTickets = collect();
        if ($email->client_id && ! $email->ticket_id) {
            $openTickets = Ticket::where('client_id', $email->client_id)
                ->open()
                ->orderByDesc('updated_at')
                ->with('categoryNode.parent.parent')
                ->limit(10)
                // category_id MUST be selected or the categoryNode belongsTo eager-load
                // silently no-matches (the FK is the join key). psa-717bn.9.
                ->get(['id', 'subject', 'status', 'priority', 'category_id']);
        }

        // Client list + domain-based suggestion for unresolved senders
        $clients = collect();
        $suggestedClientId = null;
        if (! $email->client_id) {
            // active(), not operational(): unresolved sender routing can assign email to a prospect.
            $clients = Client::active()->orderBy('name')->get(['id', 'name']);
            $domain = Str::after($email->from_address, '@');
            if ($domain && ! in_array(strtolower($domain), self::FREE_EMAIL_DOMAINS)) {
                $suggestedClientId = Person::whereEmailDomain($domain)
                    ->whereNotNull('client_id')
                    ->value('client_id');
                if (! $suggestedClientId) {
                    $suggestedClientId = Client::active()
                        ->where('website', 'like', '%'.$domain.'%')
                        ->value('id');
                }
            }
        }

        return view('emails.show', [
            'email' => $email,
            'openTickets' => $openTickets,
            'clients' => $clients,
            'suggestedClientId' => $suggestedClientId,
        ]);
    }

    /**
     * The /emails folder URL an action returns to. Only a relative /emails path
     * is accepted, so return_to can never send the browser off-site.
     */
    private function paneReturnTo(Request $request): ?string
    {
        $back = (string) $request->input('return_to', '');

        return preg_match('#^/emails(?:[/?]|$)#', $back) === 1 ? $back : null;
    }

    public function reply(Request $request, Email $email)
    {
        $request->validate([
            'body' => ['required', 'string'],
            'cc' => ['nullable', 'string'],
        ]);

        $cc = $request->input('cc')
            ? array_filter(array_map('trim', explode(',', $request->input('cc'))))
            : null;

        try {
            $this->emailService->sendReply($email, $request->input('body'), $cc);
        } catch (GraphClientException $e) {
            return redirect()->route('emails.show', $email)
                ->with('error', 'Failed to send reply: '.$e->getMessage())
                ->withInput();
        }

        return redirect()->route('emails.show', $email)
            ->with('success', 'Reply sent.');
    }

    public function compose(Request $request)
    {
        return view('emails.compose', [
            'to' => $request->query('to', ''),
            'toName' => $request->query('to_name', ''),
            'subject' => $request->query('subject', ''),
            'clientId' => $request->query('client_id'),
        ]);
    }

    public function send(Request $request)
    {
        $request->validate([
            'to' => ['required', 'email'],
            'to_name' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'cc' => ['nullable', 'string'],
        ]);

        $cc = $request->input('cc')
            ? array_filter(array_map('trim', explode(',', $request->input('cc'))))
            : null;

        try {
            $this->emailService->sendNew(
                $request->input('to'),
                $request->input('subject'),
                $request->input('body'),
                $request->input('to_name'),
                $cc,
                auth()->id(),
            );
        } catch (GraphClientException $e) {
            return redirect()->route('emails.compose')
                ->with('error', 'Failed to send email: '.$e->getMessage())
                ->withInput();
        }

        return redirect()->route('emails.index')
            ->with('success', 'Email sent.');
    }

    public function createTicket(Email $email)
    {
        if ($email->ticket_id !== null) {
            return redirect()->route('tickets.show', $email->ticket_id)
                ->with('info', 'This email is already linked to a ticket.');
        }

        $email->load(['client', 'person']);

        return view('emails.create-ticket', [
            'email' => $email,
            // active(), not operational(): email-created tickets can be prospect intake.
            'clients' => Client::active()->orderBy('name')->get(['id', 'name']),
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'types' => TicketType::cases(),
            'priorities' => TicketPriority::cases(),
            'categories' => config('tickets.categories', []),
        ]);
    }

    public function linkTicket(Request $request, Email $email)
    {
        if ($email->ticket_id !== null) {
            return redirect()->route('emails.show', $email)
                ->with('error', 'This email is already linked to a ticket.');
        }

        $validated = $request->validate([
            'ticket_id' => 'required|integer|exists:tickets,id',
        ]);

        $ticket = Ticket::findOrFail($validated['ticket_id']);
        $this->emailService->linkEmailToTicket($email, $ticket);
        $this->emailService->forgetFolderCounts();

        // From the reading pane: back to the folder; otherwise the show page as before.
        $back = $this->paneReturnTo($request);

        return ($back ? redirect($back) : redirect()->route('emails.show', $email))
            ->with('success', 'Email linked to ticket '.$ticket->display_id.'.');
    }

    public function unlinkTicket(Request $request, Email $email)
    {
        if ($email->ticket_id === null) {
            return redirect()->route('emails.show', $email)
                ->with('error', 'This email is not linked to any ticket.');
        }

        $email->update(['ticket_id' => null]);
        $this->emailService->forgetFolderCounts();

        $back = $this->paneReturnTo($request);

        return ($back ? redirect($back) : redirect()->route('emails.show', $email))
            ->with('success', 'Email unlinked from ticket.');
    }

    public function storeTicket(Request $request, Email $email)
    {
        if ($email->ticket_id !== null) {
            return redirect()->route('tickets.show', $email->ticket_id)
                ->with('error', 'This email is already linked to a ticket.');
        }

        $categoryKeys = array_keys(config('tickets.categories', []));

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'client_id' => ['required', 'exists:clients,id'],
            'contact_id' => ['nullable', 'exists:people,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'type' => ['required', \Illuminate\Validation\Rule::enum(TicketType::class)],
            'priority' => ['required', \Illuminate\Validation\Rule::enum(TicketPriority::class)],
            'category' => ['nullable', 'string', 'max:100', \Illuminate\Validation\Rule::in($categoryKeys)],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
        ]);

        $validated += $request->validate([
            'contract_id' => \App\Http\Requests\TicketStoreRequest::contractRules($request->input('client_id')),
        ]);

        $validated['source'] = TicketSource::Email->value;

        try {
            $ticket = DB::transaction(function () use ($validated, $email) {
                $ticket = $this->ticketService->createTicket($validated, auth()->id());

                // Create initial note from email body, linked to the email
                $bodyText = $email->body_text ?? strip_tags($email->body_html ?? $email->body_preview ?? '');
                if (trim($bodyText)) {
                    $this->ticketService->addNote(
                        $ticket,
                        $bodyText,
                        NoteType::Reply,
                        false, // is_private = false → triggers responded_at
                        auth()->id(),
                        null, // timeMinutes
                        $email->id, // emailId
                    );
                }

                // Link email to ticket
                $email->update(['ticket_id' => $ticket->id]);

                return $ticket;
            });
        } catch (\App\Services\ContractNotAllowedException $e) {
            return back()->withInput()->withErrors(['contract_id' => 'The selected contract is not an active contract of this client.']);
        }

        return redirect()->route('tickets.show', $ticket)
            ->with('success', "Ticket {$ticket->display_id} created from email.");
    }

    public function reassignClient(Request $request, Email $email)
    {
        $request->validate([
            'client_id' => ['nullable', 'exists:clients,id'],
            'contact_id' => ['nullable', 'exists:people,id'],
        ]);

        $newClientId = $request->input('client_id') ?: null;
        $newContactId = $request->input('contact_id') ?: null;

        // Verify contact belongs to the new client
        if ($newContactId && $newClientId) {
            if (! Person::where('id', $newContactId)->where('client_id', $newClientId)->exists()) {
                $newContactId = null;
            }
        }

        // Auto-resolve contact from sender email if not explicitly provided
        if ($newClientId && ! $newContactId) {
            $newContactId = Person::where('client_id', $newClientId)
                ->whereEmailMatch($email->from_address)
                ->value('id');
        }

        $email->update([
            'client_id' => $newClientId,
            'person_id' => $newContactId,
        ]);

        // Optionally cascade to linked ticket
        if ($request->boolean('update_ticket') && $email->ticket_id) {
            if ($newClientId) {
                $this->ticketService->moveToClient(
                    $email->ticket,
                    (int) $newClientId,
                    $newContactId ? (int) $newContactId : null,
                    (int) auth()->id(),
                );
            } else {
                $email->ticket->update([
                    'client_id' => null,
                    'contact_id' => null,
                ]);
            }
        }

        if (! $newClientId) {
            return back()->with('success', 'Client assignment cleared.');
        }

        $clientName = Client::find($newClientId)?->name ?? 'Unknown';

        return back()->with('success', "Email reassigned to {$clientName}.");
    }

    public function linkClient(Request $request, Email $email)
    {
        $request->validate(['client_id' => 'required|exists:clients,id']);

        if ($email->client_id) {
            return back()->with('error', 'This email already has a client linked.');
        }

        try {
            $count = count(app(EmailService::class)->linkSenderClient($email, (int) $request->client_id));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Client linked to {$count} email(s) from this sender.");
    }

    public function createContact(Request $request, Email $email)
    {
        $validated = $request->validate([
            'client_id' => 'sometimes|required|exists:clients,id',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'required|email|max:255',
        ]);

        $clientId = $email->client_id ?? $validated['client_id'] ?? null;
        if (! $clientId) {
            return back()->with('error', 'A client must be selected.');
        }

        $person = DB::transaction(function () use ($validated, $email, $clientId) {
            $existing = Person::where('client_id', $clientId)
                ->whereEmailMatch($validated['email'])
                ->first();

            $person = $existing ?? $this->personService->createPerson([
                'client_id' => $clientId,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'is_active' => true,
            ]);

            // Backfill: link all unresolved emails from this address
            Email::where('from_address', $email->from_address)
                ->whereNull('person_id')
                ->update(['person_id' => $person->id, 'client_id' => $clientId]);
            DB::afterCommit(fn () => $this->emailService->forgetFolderCounts());

            return $person;
        });

        $label = $person->wasRecentlyCreated
            ? "Contact {$person->fullName} created and linked."
            : "Existing contact {$person->fullName} linked.";

        return back()->with('success', $label);
    }

    /**
     * Link an email to an existing contact, optionally saving the sender
     * address as an additional email on that person for future matching.
     */
    public function linkContact(Request $request, Email $email)
    {
        $request->validate([
            'contact_id' => ['required', 'exists:people,id'],
            'save_email' => ['nullable', 'boolean'],
        ]);

        $person = Person::findOrFail($request->contact_id);

        // Verify the contact belongs to the email's client
        if ($email->client_id && $person->client_id !== $email->client_id) {
            return back()->with('error', 'That contact does not belong to this client.');
        }

        DB::transaction(function () use ($email, $person, $request) {
            // Link this email (and all unresolved emails from same address) to the contact
            Email::where('from_address', $email->from_address)
                ->where(function ($q) use ($person) {
                    $q->whereNull('person_id')
                        ->orWhere('client_id', $person->client_id);
                })
                ->update([
                    'person_id' => $person->id,
                    'client_id' => $person->client_id,
                ]);
            DB::afterCommit(fn () => $this->emailService->forgetFolderCounts());

            // Optionally save sender address as an additional email for future matching
            if ($request->boolean('save_email')) {
                $senderEmail = mb_strtolower(trim($email->from_address));
                if ($senderEmail && mb_strtolower(trim($person->email ?? '')) !== $senderEmail) {
                    \App\Models\PersonEmail::firstOrCreate(
                        ['person_id' => $person->id, 'email' => $senderEmail],
                        ['is_primary' => false, 'label' => null, 'source' => 'email_link'],
                    );
                }
            }
        });

        $saveMsg = $request->boolean('save_email') ? ' Email address saved on contact for future matching.' : '';

        return back()->with('success', "Linked to {$person->fullName}.{$saveMsg}");
    }
}
