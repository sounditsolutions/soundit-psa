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
    private const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'live.com',
        'icloud.com', 'aol.com', 'protonmail.com', 'me.com', 'msn.com',
    ];

    public function __construct(
        private readonly EmailService $emailService,
        private readonly TicketService $ticketService,
        private readonly PersonService $personService,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'is_read', 'date_from', 'date_to', 'client_id', 'no_client', 'preset', 'direction']);

        // Default to needs_attention preset when no query params
        if (! $request->hasAny(['preset', 'search', 'is_read', 'date_from', 'date_to', 'client_id', 'no_client', 'direction'])) {
            $filters['preset'] = 'needs_attention';
        }

        $emails = $this->emailService->getEmailList($filters);

        $needsAttentionCount = Email::needsAttention()->count();
        $noClientCount = Email::inbound()->noClient()->notDismissed()->noTicket()->count();

        return view('emails.index', [
            'emails' => $emails,
            'filters' => $filters,
            'needsAttentionCount' => $needsAttentionCount,
            'noClientCount' => $noClientCount,
            'clients' => Client::operational()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function dismiss(Email $email)
    {
        $this->emailService->dismissEmail($email, auth()->id());

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->back()->with('success', 'Email dismissed.');
    }

    public function undismiss(Email $email)
    {
        $this->emailService->undismissEmail($email);

        return redirect()->back()->with('success', 'Email restored to attention queue.');
    }

    public function emailBulkAction(Request $request)
    {
        $request->validate([
            'action' => ['required', 'string', 'in:dismiss,link_ticket'],
            'email_ids' => ['required', 'array', 'min:1'],
            'email_ids.*' => ['required', 'integer', 'exists:emails,id'],
            'ticket_id' => ['required_if:action,link_ticket', 'nullable', 'integer', 'exists:tickets,id'],
        ]);

        $emailIds = $request->input('email_ids');
        $action = $request->input('action');

        if ($action === 'dismiss') {
            $affected = $this->emailService->bulkDismiss($emailIds, auth()->id());
            $message = "{$affected} email(s) dismissed.";
        } else {
            $affected = $this->emailService->bulkLinkToTicket($emailIds, (int) $request->input('ticket_id'));
            $ticket = Ticket::find($request->input('ticket_id'));
            $message = "{$affected} email(s) linked to {$ticket->display_id}.";
        }

        return redirect()->route('emails.index')
            ->with('success', $message);
    }

    public function show(Email $email)
    {
        $email->load(['client', 'person', 'user', 'ticket']);

        // Mark as read
        if (! $email->is_read) {
            $email->update(['is_read' => true]);
        }

        // Fetch open local tickets for this client (for manual link list)
        $openTickets = collect();
        if ($email->client_id && ! $email->ticket_id) {
            $openTickets = Ticket::where('client_id', $email->client_id)
                ->open()
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get(['id', 'subject', 'status', 'priority']);
        }

        // Client list + domain-based suggestion for unresolved senders
        $clients = collect();
        $suggestedClientId = null;
        if (! $email->client_id) {
            $clients = Client::operational()->orderBy('name')->get(['id', 'name']);
            $domain = Str::after($email->from_address, '@');
            if ($domain && ! in_array(strtolower($domain), self::FREE_EMAIL_DOMAINS)) {
                $suggestedClientId = Person::whereEmailDomain($domain)
                    ->whereNotNull('client_id')
                    ->value('client_id');
                if (! $suggestedClientId) {
                    $suggestedClientId = Client::operational()
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
            'clients' => Client::operational()->orderBy('name')->get(['id', 'name']),
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

        return redirect()->route('emails.show', $email)
            ->with('success', 'Email linked to ticket '.$ticket->display_id.'.');
    }

    public function unlinkTicket(Email $email)
    {
        if ($email->ticket_id === null) {
            return redirect()->route('emails.show', $email)
                ->with('error', 'This email is not linked to any ticket.');
        }

        $email->update(['ticket_id' => null]);

        return redirect()->route('emails.show', $email)
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

        $validated['source'] = TicketSource::Email->value;

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
            $email->ticket->update([
                'client_id' => $newClientId,
                'contact_id' => $newContactId,
            ]);
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

        $count = Email::where('from_address', $email->from_address)
            ->whereNull('client_id')
            ->update(['client_id' => $request->client_id]);

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
