<?php

namespace App\Http\Controllers\Web;

use App\Enums\CallStatus;
use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\TranscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityStreamService;
use App\Services\Ai\AiClient;
use App\Services\PhoneCallService;
use App\Services\TicketService;
use App\Support\AiConfig;
use App\Support\TranscriptionConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CallController extends Controller
{
    public function __construct(
        private readonly PhoneCallService $phoneCallService,
        private readonly TicketService $ticketService,
        private readonly ActivityStreamService $activityStream,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->only(['status', 'date_from', 'date_to', 'search']);

        // Default to today if no date filter
        if (empty($filters['date_from']) && empty($filters['date_to']) && empty($filters['search']) && empty($filters['status'])) {
            $filters['date_from'] = today()->toDateString();
        }

        $calls = $this->phoneCallService->getRecentCalls(100, $filters);

        $missedCount = PhoneCall::unfollowedUp()->count();

        return view('calls.index', [
            'calls' => $calls,
            'filters' => $filters,
            'missedCount' => $missedCount,
        ]);
    }

    public function show(PhoneCall $call)
    {
        $call->load(['answeredBy', 'client', 'person', 'ticket']);

        // Fetch recent tickets for this client (open + recently closed)
        $recentTickets = collect();
        if ($call->client_id) {
            $recentTickets = Ticket::where('client_id', $call->client_id)
                ->where(function ($q) {
                    $q->open()
                        ->orWhere(function ($q2) {
                            $q2->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed])
                                ->where('updated_at', '>=', now()->subDays(7));
                        });
                })
                ->orderByDesc('updated_at')
                ->limit(20)
                ->get(['id', 'halo_id', 'subject', 'status', 'opened_at', 'updated_at']);
        }

        $candidates = $this->phoneCallService->getCandidateCallers($call);
        $clients = Client::operational()->orderBy('name')->get(['id', 'name']);

        // Previous calls from/to the same number (useful when caller is unresolved)
        $callHistory = $this->getCallHistory($call);

        return view('calls.show', [
            'call' => $call,
            'recentTickets' => $recentTickets,
            'candidates' => $candidates,
            'clients' => $clients,
            'callHistory' => $callHistory,
        ]);
    }

    /**
     * API endpoint: get the latest ringing/in-progress call for softphone context.
     */
    public function latest()
    {
        $call = PhoneCall::whereIn('status', [CallStatus::Ringing, CallStatus::InProgress])
            ->where('started_at', '>=', now()->subHour())
            ->orderByDesc('started_at')
            ->first();

        if (! $call) {
            return response()->json(['call' => null]);
        }

        $call->load(['client', 'person']);

        $recentTickets = [];
        if ($call->client_id) {
            $recentTickets = Ticket::where('client_id', $call->client_id)
                ->where(function ($q) {
                    $q->open()
                        ->orWhere(function ($q2) {
                            $q2->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed])
                                ->where('updated_at', '>=', now()->subDays(7));
                        });
                })
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get(['id', 'halo_id', 'subject', 'status', 'opened_at', 'updated_at'])
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'display_id' => $t->display_id,
                    'subject' => $t->subject,
                    'status' => $t->status->label(),
                    'status_badge' => $t->status->badgeClass(),
                    'url' => route('tickets.show', $t),
                ])
                ->values()
                ->all();
        }

        $activity = [];
        if ($call->person_id) {
            $activity = $this->activityStream
                ->getPersonStream($call->person_id, limit: 10)
                ->map(fn ($item) => $this->serializeActivityItem($item))
                ->all();
        }

        // Call history from/to same number (useful when unresolved)
        $callHistory = [];
        if (! $call->client_id) {
            $callHistory = $this->getCallHistory($call)
                ->map(fn (PhoneCall $c) => [
                    'id' => $c->id,
                    'direction' => $c->direction->value,
                    'status' => $c->status->label(),
                    'client_name' => $c->client?->name,
                    'person_name' => $c->person?->fullName,
                    'answered_by' => $c->answeredBy?->name,
                    'duration' => $c->duration,
                    'started_at' => $c->started_at?->diffForHumans(),
                    'call_url' => route('calls.show', $c),
                    'ticket_id' => $c->ticket?->display_id,
                    'ticket_url' => $c->ticket ? route('tickets.show', $c->ticket) : null,
                ])
                ->values()
                ->all();
        }

        return response()->json([
            'call' => [
                'id' => $call->id,
                'from_number' => $call->from_number,
                'from_formatted' => \App\Support\PhoneNumber::format($call->from_number),
                'client_name' => $call->client?->name ?? $call->halo_client_name,
                'person_name' => $call->person?->fullName,
                'status' => $call->status->value,
                'call_url' => route('calls.show', $call),
                'client_url' => $call->client ? route('clients.show', $call->client) : null,
                'person_url' => $call->person ? route('people.show', $call->person) : null,
                'recent_tickets' => $recentTickets,
                'activity' => $activity,
                'call_history' => $callHistory,
            ],
        ]);
    }

    private function serializeActivityItem(\App\Support\ActivityItem $item): array
    {
        $model = $item->model;
        $data = [
            'type' => $item->type,
            'url' => $item->url,
            'time' => $item->timestamp->diffForHumans(short: true),
        ];

        if ($item->type === 'ticket') {
            $data['icon'] = $model->note_type->icon();
            $data['who'] = $model->author?->name ?? $model->author_name ?? '';
            $data['label'] = $model->note_type->label();
            $data['ticket_id'] = $model->ticket?->display_id;
            $data['preview'] = \Illuminate\Support\Str::limit(strip_tags($model->body ?? ''), 80);
            $data['body'] = \Illuminate\Support\Str::limit(strip_tags($model->body ?? ''), 500);
        } elseif ($item->type === 'call') {
            $data['icon'] = $model->direction?->value === 'outbound' ? 'bi-telephone-outbound' : 'bi-telephone-inbound';
            $data['label'] = $model->status->label();
            $data['badge'] = $model->status->badgeClass();
            $data['preview'] = \Illuminate\Support\Str::limit($model->call_summary ?? '', 80);
            $data['body'] = \Illuminate\Support\Str::limit($model->call_summary ?? '', 500);
        } elseif ($item->type === 'email') {
            $data['icon'] = 'bi-envelope';
            $data['who'] = $model->senderDisplay();
            $data['label'] = $model->direction?->label() ?? '';
            $data['ticket_id'] = $model->ticket?->display_id;
            $data['preview'] = \Illuminate\Support\Str::limit($model->subject ?? '', 80);
            $data['body'] = \Illuminate\Support\Str::limit($model->body_preview ?? strip_tags($model->body_text ?? $model->subject ?? ''), 500);
        }

        return $data;
    }

    /**
     * Get previous calls from/to the same phone number, excluding the current call.
     */
    private function getCallHistory(PhoneCall $call, int $limit = 10): \Illuminate\Support\Collection
    {
        $number = $call->direction === \App\Enums\CallDirection::Inbound
            ? $call->from_number
            : $call->to_number;

        if (! $number) {
            return collect();
        }

        return PhoneCall::where('id', '!=', $call->id)
            ->where(fn ($q) => $q->where('from_number', $number)->orWhere('to_number', $number))
            ->with(['client:id,name', 'person', 'answeredBy:id,name', 'ticket:id,halo_id'])
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();
    }

    public function createTicket(PhoneCall $call)
    {
        if ($call->ticket_id !== null) {
            return redirect()->route('tickets.show', $call->ticket_id)
                ->with('info', 'This call is already linked to a ticket.');
        }

        $call->load(['client', 'person', 'answeredBy']);

        $suggestions = $this->buildTicketSuggestions($call);

        return view('calls.create-ticket', [
            'call' => $call,
            'defaultSubject' => $suggestions['subject'],
            'defaultDescription' => $suggestions['description'],
            'defaultType' => $suggestions['type'],
            'defaultPriority' => $suggestions['priority'],
            'defaultAssetId' => $suggestions['asset_id'],
            'defaultCategory' => $suggestions['category'],
            'defaultSubcategory' => $suggestions['subcategory'],
            'clients' => Client::operational()->orderBy('name')->get(['id', 'name']),
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'types' => TicketType::cases(),
            'priorities' => TicketPriority::cases(),
            'categories' => config('tickets.categories', []),
        ]);
    }

    public function storeTicket(Request $request, PhoneCall $call): RedirectResponse
    {
        $categoryKeys = array_keys(config('tickets.categories', []));

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'client_id' => ['required', 'exists:clients,id'],
            'contact_id' => ['nullable', 'exists:people,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'type' => ['required', Rule::enum(TicketType::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'category' => ['nullable', 'string', 'max:100', Rule::in($categoryKeys)],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
        ]);

        $validated['source'] = TicketSource::Phone->value;

        $ticket = DB::transaction(function () use ($validated, $call) {
            // Re-fetch with lock to prevent double-submit race condition
            $call = PhoneCall::lockForUpdate()->findOrFail($call->id);

            if ($call->ticket_id !== null) {
                return null; // Already linked — handled after transaction
            }

            $ticket = $this->ticketService->createTicket($validated, auth()->id());

            $call->update(['ticket_id' => $ticket->id]);

            if ($call->needsFollowUp()) {
                $this->phoneCallService->markFollowedUp($call, auth()->id());
            }

            return $ticket;
        });

        if ($ticket === null) {
            $call->refresh();

            return redirect()->route('tickets.show', $call->ticket_id)
                ->with('info', 'This call is already linked to a ticket.');
        }

        return redirect()->route('tickets.show', $ticket)
            ->with('success', "Ticket {$ticket->display_id} created from call.");
    }

    public function updateNotes(Request $request, PhoneCall $call): RedirectResponse
    {
        $call->update([
            'notes' => $request->input('notes'),
            'summary_is_public' => $request->boolean('summary_is_public'),
        ]);

        // Handle billability change if provided
        if ($request->has('is_billable') && $call->ticket_id) {
            $newBillable = $request->boolean('is_billable');
            if ($call->is_billable !== $newBillable) {
                $this->phoneCallService->setBillable($call, $newBillable);
            }
        }

        return redirect()->back()->with('success', 'Call updated.');
    }

    public function linkTicket(Request $request, PhoneCall $call)
    {
        $validated = $request->validate([
            'ticket_id' => 'required|integer|exists:tickets,id',
        ]);

        $this->phoneCallService->linkCallToTicket($call, $validated['ticket_id']);

        $ticket = Ticket::find($validated['ticket_id']);

        // Also mark as followed up if it was a missed call
        if ($call->needsFollowUp()) {
            $this->phoneCallService->markFollowedUp($call, auth()->id());
        }

        return redirect()->route('calls.show', $call)
            ->with('success', 'Call linked to ticket '.($ticket?->display_id ?? $validated['ticket_id']));
    }

    public function unlinkTicket(PhoneCall $call): RedirectResponse
    {
        $this->phoneCallService->unlinkCallFromTicket($call);

        return redirect()->route('calls.show', $call)
            ->with('success', 'Call unlinked from ticket.');
    }

    public function toggleBillable(PhoneCall $call): RedirectResponse
    {
        if (! $call->ticket_id) {
            return redirect()->route('calls.show', $call)
                ->with('error', 'Call must be linked to a ticket to toggle billability.');
        }

        $this->phoneCallService->setBillable($call, ! $call->is_billable);

        $label = $call->is_billable ? 'billable' : 'non-billable';

        return redirect()->back()
            ->with('success', "Call marked as {$label}.");
    }

    public function markFollowedUp(PhoneCall $call)
    {
        $this->phoneCallService->markFollowedUp($call, auth()->id());

        return redirect()->back()
            ->with('success', 'Call marked as followed up.');
    }

    public function transcribe(PhoneCall $call): RedirectResponse
    {
        if (! $call->recording_url) {
            return redirect()->back()->with('error', 'No recording available for this call.');
        }

        if (! TranscriptionConfig::isConfigured()) {
            return redirect()->back()->with('error', 'Transcription is not configured. Add an OpenAI API key in Settings > Integrations.');
        }

        if ($call->transcription_status === TranscriptionStatus::Processing) {
            return redirect()->back()->with('info', 'Transcription is already in progress.');
        }

        $call->update(['transcription_status' => TranscriptionStatus::Pending]);

        // Spawn detached process — no timeout constraints for long recordings
        $cmd = sprintf('php %s calls:transcribe %d > /dev/null 2>&1 &', base_path('artisan'), $call->id);
        Process::run($cmd);

        return redirect()->back()->with('success', 'Transcription started — results will appear shortly.');
    }

    public function updatePerson(Request $request, PhoneCall $call): RedirectResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'exists:people,id'],
        ]);

        $person = Person::with('client')->findOrFail($validated['person_id']);

        $call->person_id = $person->id;
        $call->client_id = $person->client_id;
        $call->person_confirmed = true;
        $call->save();

        return redirect()->back()->with('success', "Caller updated to {$person->fullName}.");
    }

    public function recording(PhoneCall $call)
    {
        if ($call->recording_disk_path && Storage::disk('local')->exists($call->recording_disk_path)) {
            return response()->file(
                Storage::disk('local')->path($call->recording_disk_path),
                ['Content-Type' => 'audio/mpeg']
            );
        }

        // Fallback: redirect to Plivo CDN (works within 30-day retention)
        if ($call->recording_url) {
            return redirect($call->recording_url);
        }

        abort(404, 'No recording available.');
    }

    public function appendTranscriptionToTicket(PhoneCall $call): RedirectResponse
    {
        if (! $call->call_summary) {
            return redirect()->back()->with('error', 'No transcription summary available.');
        }

        if (! $call->ticket_id) {
            return redirect()->back()->with('error', 'This call is not linked to a ticket.');
        }

        $call->load('ticket');

        $content = "## Call Summary\n".$call->call_summary;
        if ($call->next_steps) {
            $content .= "\n\n## Next Steps\n".$call->next_steps;
        }

        $this->ticketService->addNote(
            $call->ticket,
            $content,
            NoteType::PhoneCall,
            true,
            auth()->id(),
        );

        return redirect()->back()->with('success', 'Transcription summary added to ticket.');
    }

    /**
     * Build a structured set of ticket-form pre-fills (subject, description,
     * type, priority, asset, category/subcategory) for the "create ticket
     * from call" form. Uses AI to classify when call has a transcript or
     * AI summary; falls back to safe defaults otherwise.
     *
     * @return array{subject:string,description:?string,type:string,priority:string,asset_id:?int,category:?string,subcategory:?string}
     */
    private function buildTicketSuggestions(PhoneCall $call): array
    {
        $description = $call->call_summary ?? $call->notes;
        $defaults = [
            'subject' => $this->legacySubject($call),
            'description' => $description,
            'type' => 'incident',
            'priority' => 'p3',
            'asset_id' => null,
            'category' => null,
            'subcategory' => null,
        ];

        // Source material for the AI prompt
        $source = trim((string) ($call->call_summary ?? ''));
        if ($source === '' && ! empty($call->transcription)) {
            $source = trim(mb_substr((string) $call->transcription, 0, 4000));
        }

        if ($source === '' || ! AiConfig::isConfigured()) {
            return $defaults;
        }

        $categories = config('tickets.categories', []);
        $assets = $call->client_id
            ? Asset::where('client_id', $call->client_id)
                ->active()
                ->get(['id', 'hostname', 'name'])
            : collect();

        $assetMenu = $assets->map(fn ($a) => ($a->hostname ?: $a->name))->filter()->values()->all();

        $system = 'You are pre-filling fields on a helpdesk ticket form from a phone call. '
            .'Respond with JSON only — no prose, no markdown fences. '
            ."Schema: {subject, type, priority, category, subcategory, asset}.\n\n"
            ."Field rules:\n"
            ."- subject: 6-10 word descriptive summary, no caller name, no period.\n"
            .'- type: one of '.json_encode(array_map(fn ($t) => $t->value, TicketType::cases())).".\n"
            ."- priority: one of [p1,p2,p3,p4]. p1=critical/outage, p2=major impact/urgent, p3=normal, p4=minor.\n"
            ."- category + subcategory: pick from menu below, or null for either. Subcategory must belong to the chosen category.\n"
            ."- asset: pick the exact hostname from the asset menu if the call clearly references one device, else null.\n\n"
            .'Categories menu: '.json_encode($categories)."\n"
            .'Asset menu for this client: '.json_encode($assetMenu);

        try {
            $result = app(AiClient::class)->completeJson($system, "Call content:\n{$source}", 400);
        } catch (\Throwable $e) {
            Log::warning('[CallController] AI ticket suggestion failed', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);

            return $defaults;
        }

        $callerSuffix = $this->buildCallerSuffix($call);

        // Subject — validate, trim, append caller suffix
        if (is_string($result['subject'] ?? null) && trim($result['subject']) !== '') {
            $subject = trim($result['subject'], "\"' \t\n\r.");
            $defaults['subject'] = mb_substr(
                $callerSuffix ? "{$subject} — {$callerSuffix}" : $subject,
                0, 255
            );
        }

        // Type
        $validTypes = array_map(fn ($t) => $t->value, TicketType::cases());
        if (is_string($result['type'] ?? null) && in_array($result['type'], $validTypes, true)) {
            $defaults['type'] = $result['type'];
        }

        // Priority
        $validPriorities = ['p1', 'p2', 'p3', 'p4'];
        if (is_string($result['priority'] ?? null) && in_array($result['priority'], $validPriorities, true)) {
            $defaults['priority'] = $result['priority'];
        }

        // Category + Subcategory (subcategory only if it matches the chosen category)
        $cat = $result['category'] ?? null;
        if (is_string($cat) && array_key_exists($cat, $categories)) {
            $defaults['category'] = $cat;
            $sub = $result['subcategory'] ?? null;
            if (is_string($sub) && in_array($sub, $categories[$cat], true)) {
                $defaults['subcategory'] = $sub;
            }
        }

        // Asset — match hostname back to ID
        $assetHint = $result['asset'] ?? null;
        if (is_string($assetHint) && $assetHint !== '') {
            $match = $assets->first(fn ($a) => strcasecmp($a->hostname ?? '', $assetHint) === 0
                || strcasecmp($a->name ?? '', $assetHint) === 0
            );
            if ($match) {
                $defaults['asset_id'] = $match->id;
            }
        }

        return $defaults;
    }

    private function legacySubject(PhoneCall $call): string
    {
        if ($call->client && $call->person) {
            return "{$call->client->name} - {$call->person->fullName} (inbound call)";
        }
        if ($call->client) {
            return "{$call->client->name} (inbound call)";
        }

        return 'Call from '.\App\Support\PhoneNumber::format($call->from_number);
    }

    private function buildCallerSuffix(PhoneCall $call): string
    {
        if ($call->person && $call->client) {
            return "{$call->person->fullName} ({$call->client->name})";
        }
        if ($call->client) {
            return $call->client->name;
        }
        if ($call->person) {
            return $call->person->fullName;
        }

        return '';
    }
}
