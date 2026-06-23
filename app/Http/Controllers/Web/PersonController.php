<?php

namespace App\Http\Controllers\Web;

use App\Enums\PersonType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Http\Requests\PersonStoreRequest;
use App\Http\Requests\PersonUpdateRequest;
use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityStreamService;
use App\Services\PersonService;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PersonController extends Controller
{
    public function __construct(
        private readonly PersonService $personService,
        private readonly ActivityStreamService $activityStream,
    ) {}

    public function index(Request $request)
    {
        $query = Person::active()
            ->with('client')
            ->search($request->query('search'));

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->query('client_id'));
        }

        if ($request->filled('person_type')) {
            $query->where('person_type', $request->query('person_type'));
        }

        $people = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(50)
            ->withQueryString();

        $clients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('people.index', [
            'people' => $people,
            'clients' => $clients,
            'search' => $request->query('search'),
            'clientId' => $request->query('client_id'),
            'personType' => $request->query('person_type'),
        ]);
    }

    public function create(Request $request)
    {
        $clients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('people.create', [
            'clients' => $clients,
            'selectedClientId' => $request->query('client_id'),
        ]);
    }

    public function store(PersonStoreRequest $request)
    {
        $person = $this->personService->createPerson($request->validated());

        return redirect()->route('people.show', $person)
            ->with('success', 'Contact created successfully.');
    }

    public function show(Person $person)
    {
        $person->load('client', 'additionalEmailAddresses', 'assets');

        $recentTickets = $person->tickets()
            ->with('client')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        \App\Support\RecentItems::track(auth()->id(), 'person', $person->id, $person->fullName, route('people.show', $person));

        return view('people.show', [
            'person' => $person,
            'recentTickets' => $recentTickets,
            'mergeCandidates' => $this->mergeCandidatesFor($person),
        ]);
    }

    /**
     * Other contacts in the same client that could be merged INTO this one.
     * Includes inactive contacts — CIPP-deactivated duplicates are a prime merge target.
     */
    private function mergeCandidatesFor(Person $person)
    {
        if (! $person->client_id) {
            return collect();
        }

        return Person::where('client_id', $person->client_id)
            ->where('id', '!=', $person->id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'is_active', 'portal_enabled']);
    }

    public function tickets(Request $request, Person $person)
    {
        $filters = [
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'type' => $request->query('type'),
            'source' => $request->query('source'),
            'client_id' => $person->client_id ? (string) $person->client_id : null,
            'contact_id' => (string) $person->id,
            'assignee_id' => $request->query('assignee_id', 'all'),
            'search' => $request->query('search'),
            'show_closed' => $request->boolean('show_closed'),
            'overdue' => $request->boolean('overdue'),
            'sort' => $request->query('sort', 'priority'),
            'direction' => $request->query('direction', 'asc'),
        ];

        $ticketService = app(TicketService::class);
        $tickets = $ticketService->getTicketList($filters);
        $unassignedCount = Ticket::open()->where('contact_id', $person->id)->whereNull('assignee_id')->count();

        // Load same data as show()
        $person->load(['client', 'additionalEmailAddresses']);

        $recentTickets = collect(); // Not needed in tickets tab mode

        return view('people.show', [
            'person' => $person,
            'recentTickets' => $recentTickets,
            'mergeCandidates' => $this->mergeCandidatesFor($person),
            'activeTab' => 'tickets',
            'tickets' => $tickets,
            'ticketFilters' => $filters,
            'ticketUsers' => User::active()->orderBy('name')->get(['id', 'name']),
            'ticketClients' => Client::operational()->orderBy('name')->get(['id', 'name']),
            'ticketStatuses' => TicketStatus::cases(),
            'ticketPriorities' => TicketPriority::cases(),
            'ticketTypes' => TicketType::cases(),
            'ticketSources' => TicketSource::cases(),
            'unassignedCount' => $unassignedCount,
        ]);
    }

    public function edit(Person $person)
    {
        $person->load('additionalEmailAddresses');
        $clients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('people.edit', [
            'person' => $person,
            'clients' => $clients,
        ]);
    }

    public function update(PersonUpdateRequest $request, Person $person)
    {
        $this->personService->updatePerson($person, $request->validated());

        return redirect()->route('people.show', $person)
            ->with('success', 'Contact updated successfully.');
    }

    public function merge(Request $request, Person $person)
    {
        // Laravel's bare `exists` rule ignores the SoftDeletes scope, so scope the
        // candidate to the same client and non-deleted rows. The service enforces
        // the self-merge and cross-client guards as the inner layer (defense in depth).
        $validated = $request->validate([
            'duplicate_id' => [
                'required',
                'integer',
                Rule::exists('people', 'id')->where(fn ($q) => $q
                    ->where('client_id', $person->client_id)
                    ->whereNull('deleted_at')),
            ],
        ]);

        // findOrFail re-applies the SoftDeletes scope → clean 404 on a tampered id
        $duplicate = Person::where('client_id', $person->client_id)->findOrFail($validated['duplicate_id']);

        try {
            $summary = $this->personService->mergePeople($person, $duplicate, auth()->id());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return redirect()->route('people.show', $person)->with('error', $e->getMessage());
        }

        $parts = [];
        foreach ([
            ['tickets', 'ticket', 'tickets'],
            ['calls', 'call', 'calls'],
            ['emails', 'email', 'emails'],
            ['contracts', 'contract', 'contracts'],
            ['assets', 'device', 'devices'],
            ['email_addresses', 'email address', 'email addresses'],
        ] as [$key, $one, $many]) {
            if (! empty($summary[$key])) {
                $parts[] = $summary[$key].' '.($summary[$key] === 1 ? $one : $many);
            }
        }
        $movedText = $parts ? ' Moved '.implode(', ', $parts).'.' : '';
        $message = "Merged {$duplicate->fullName} into {$person->fullName}.{$movedText}";

        if (! empty($summary['portal_login_email_changed'])) {
            $message .= " Portal sign-in for the merged contact now uses {$person->email} — let them know.";
        }

        return redirect()->route('people.show', $person)->with('success', $message);
    }

    public function bulkUpdateType(Request $request)
    {
        $validated = $request->validate([
            'person_ids' => ['required', 'array', 'min:1'],
            'person_ids.*' => ['required', 'integer', 'exists:people,id'],
            'person_type' => ['required', 'string', Rule::in(array_column(PersonType::cases(), 'value'))],
        ]);

        $people = Person::whereIn('id', $validated['person_ids'])->get();

        foreach ($people as $person) {
            $person->update(['person_type' => $validated['person_type']]);
        }

        $type = PersonType::from($validated['person_type']);

        return redirect()->back()
            ->with('success', count($validated['person_ids'])." contact(s) set to {$type->label()}.");
    }

    public function activity(Request $request, Person $person)
    {
        $request->validate([
            'before' => 'nullable|date',
            'types' => 'nullable|string',
        ]);

        $types = $request->filled('types')
            ? array_filter(explode(',', $request->input('types')))
            : [];

        $before = $request->filled('before')
            ? Carbon::parse($request->input('before'))
            : null;

        $stream = $this->activityStream->getPersonStream($person->id, $before, 30, $types);

        return view('dashboard._activity-stream', [
            'stream' => $stream,
            'showClient' => false,
        ]);
    }

    public function destroy(Person $person)
    {
        try {
            $this->personService->deletePerson($person);
        } catch (\RuntimeException $e) {
            return redirect()->route('people.show', $person)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('people.index')
            ->with('success', 'Contact deleted successfully.');
    }
}
