<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Services\Prospect\ProspectIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProspectController extends Controller
{
    public function __construct(
        private readonly ProspectIntakeService $intake,
    ) {}

    /**
     * JSON search over all active clients (including prospects) for the
     * search-first capture control on call pages.
     *
     * GET /api/clients/search-all?q=...  →  [{id, name, stage}, ...]
     */
    public function search(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');

        if (strlen($term) < 2) {
            return response()->json([]);
        }

        $clients = Client::active()
            ->search($term)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'stage']);

        return response()->json($clients);
    }

    /**
     * Provision a new prospect client+person+ticket from a phone call.
     *
     * Confirm-dedup flow: if `matchByNumber` finds an existing client and
     * `confirm_new` is NOT set, redirect back with a warning surfacing the
     * existing match rather than blindly creating a duplicate.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone_call_id' => ['required', 'exists:phone_calls,id'],
            'name' => ['required', 'string', 'max:255'],
            'confirm_new' => ['nullable', 'string'],
        ]);

        /** @var PhoneCall $call */
        $call = PhoneCall::findOrFail($validated['phone_call_id']);

        // Confirm-dedup: if a client already owns this phone number, surface it
        // instead of creating a duplicate — unless staff explicitly confirmed.
        if (! ($validated['confirm_new'] ?? null)) {
            $existing = $this->intake->matchByNumber((string) $call->from_number);

            if ($existing !== null) {
                return redirect()
                    ->route('calls.show', $call)
                    ->withInput()
                    ->with('dedup_client_id', $existing->id)
                    ->with('dedup_client_name', $existing->name)
                    ->with('error', "This number is already on {$existing->name} — attach to that client instead?");
            }
        }

        $result = $this->intake->provisionFromCall($call, $validated['name']);

        $client = $result['client'];
        $ticket = $result['ticket'];

        // Link the call to the new prospect client + ticket
        $call->client_id = $client->id;
        $call->person_id = $result['person']->id;
        $call->ticket_id = $ticket->id;
        $call->save();

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('success', "Prospect \"{$client->name}\" created and ticket {$ticket->display_id} opened.");
    }
}
