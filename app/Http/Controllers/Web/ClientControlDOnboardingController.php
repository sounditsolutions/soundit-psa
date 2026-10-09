<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\Ticket;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Services\ControlD\ControlDOnboardingStaged;
use App\Services\ControlD\ControlDProvisioning;
use App\Services\Mcp\StaffControlDOnboardingToolExecutor;
use App\Support\ControlDConfig;
use Illuminate\Http\Request;

/**
 * The client-page "Onboard to Control D" button (B4). Admin-only (RequireAdmin on
 * the route). It stages the SAME cockpit proposal the `controld_onboard_client`
 * verb stages — it never calls the onboarding services itself — so the second-Admin
 * approval, the audit row and the one-step-per-proposal rule are identical whether
 * the trigger was a person or an MCP token. The route is inert (404) unless
 * ControlDConfig::isOnboardingActive(), matching the button's own render guard.
 */
class ClientControlDOnboardingController extends Controller
{
    public function stage(Request $request, Client $client, StaffControlDOnboardingToolExecutor $executor)
    {
        abort_unless(ControlDConfig::isEnabled() && ControlDConfig::isConfigured() && ControlDConfig::isOnboardingActive(), 404);

        $validated = $request->validate([
            'ticket_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $ticket = Ticket::find((int) $validated['ticket_id']);
        if (! $ticket || (int) $ticket->client_id !== (int) $client->id) {
            return redirect()->route('clients.show', $client)->withErrors(['ticket_id' => 'Pick one of this client\'s tickets to hold the onboarding proposal on.']);
        }

        $result = $executor->stageForClient($client, $ticket, $validated['reason'], $request->user());

        if (isset($result['error'])) {
            return redirect()->route('clients.show', $client)->withErrors(['controld_onboarding' => $result['error']]);
        }

        $step = $result['step'] ?? '';
        $message = ($result['idempotent'] ?? false)
            ? ($result['message'] ?? 'Already staged.')
            : "Control D onboarding step '{$step}' staged for cockpit approval by a second Admin (run #{$result['run_id']}).";

        return redirect()->route('clients.show', $client)->with('success', $message);
    }

    /**
     * Admin-only (RequireAdmin on the route), audited release of a NEVER-ADMITTED
     * onboarding intent of THIS client, so its per-client lock can be cleared. B3's
     * release() does the guarded UPDATE and writes the audit row; it refuses posted,
     * uncertain, bound, rejected and released intents and changes nothing for them.
     * Makes no vendor call. The ownership check here is repeated in release()'s own
     * UPDATE predicate (client_id = this client), so the service is scoped on its own.
     * Inert (404) unless ControlDConfig::isOnboardingActive(), the same gate as stage().
     */
    public function release(Request $request, Client $client, string $intent)
    {
        abort_unless(ControlDConfig::isEnabled() && ControlDConfig::isConfigured() && ControlDConfig::isOnboardingActive(), 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $row = ControlDOnboardingIntent::find($intent);
        if ($row === null || (int) $row->client_id !== (int) $client->id) {
            abort(404);
        }
        $service = app()->bound(ControlDOnboardingStaged::class) ? app(ControlDOnboardingStaged::class) : (function (): ControlDOnboardingStaged {
            $vendor = new ControlDClient(['api_key' => ControlDConfig::get('api_key')]);

            return new ControlDOnboardingStaged($vendor, new ControlDProvisioning($vendor));
        })();
        try {
            $service->release($request->user(), (int) $client->id, $row->id, $validated['reason']);
        } catch (ControlDClientException $e) {
            return redirect()->route('clients.show', $client)->withErrors(['controld_onboarding' => $e->getMessage()]);
        }

        return redirect()->route('clients.show', $client)->with('success', "Control D onboarding intent {$row->id} released; this client can be staged again.");
    }
}
