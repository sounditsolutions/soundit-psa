<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Services\EmailService;
use App\Support\PortalConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PortalManagementController extends Controller
{
    public function index(Client $client): View
    {
        $contacts = $client->people()
            ->whereNotNull('email')
            ->orderBy('first_name')
            ->get();

        $graphConfigured = ! empty(Setting::getValue('graph_mailbox'));

        return view('clients.portal', compact('client', 'contacts', 'graphConfigured'));
    }

    public function invite(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
        ]);

        $person = Person::findOrFail($validated['person_id']);

        if ($person->client_id !== $client->id) {
            abort(403);
        }

        if (! $person->email) {
            return back()->with('error', 'This contact has no email address.');
        }

        // Check for duplicate portal-enabled email
        $duplicate = Person::where('email', $person->email)
            ->where('portal_enabled', true)
            ->where('id', '!=', $person->id)
            ->exists();

        if ($duplicate) {
            return back()->with('error', 'Another portal user already exists with this email address.');
        }

        $person->update(['portal_enabled' => true]);

        // Generate password reset token and send welcome email
        $token = Password::broker('portal')->createToken($person);

        $url = url(route('portal.password.reset', ['token' => $token, 'email' => $person->email], false));
        $companyName = PortalConfig::companyName();

        $body = "You've been invited to the {$companyName} client portal.\n\n"
            ."Click the link below to set your password and get started:\n{$url}\n\n"
            ."This link will expire in 60 minutes.\n\n"
            ."Once you've set your password, you can log in at: ".url('/portal/login');

        try {
            app(EmailService::class)->sendNew(
                $person->email,
                "Welcome to the {$companyName} Portal",
                $body,
                $person->full_name,
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Portal enabled, but the invite email failed to send: '.$e->getMessage());
        }

        return back()->with('success', "Portal invite sent to {$person->full_name}.");
    }

    public function toggle(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
        ]);

        $person = Person::findOrFail($validated['person_id']);

        if ($person->client_id !== $client->id) {
            abort(403);
        }

        $person->update(['portal_enabled' => ! $person->portal_enabled]);

        $status = $person->portal_enabled ? 'enabled' : 'disabled';

        return back()->with('success', "Portal access {$status} for {$person->full_name}.");
    }

    public function toggleAccess(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
        ]);

        $person = Person::findOrFail($validated['person_id']);

        if ($person->client_id !== $client->id) {
            abort(403);
        }

        $person->update(['company_wide_access' => ! $person->company_wide_access]);

        $level = $person->company_wide_access ? 'company-wide' : 'own tickets only';

        return back()->with('success', "Access level set to {$level} for {$person->full_name}.");
    }

    public function resetPassword(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
        ]);

        $person = Person::findOrFail($validated['person_id']);

        if ($person->client_id !== $client->id || ! $person->portal_enabled) {
            abort(403);
        }

        try {
            Password::broker('portal')->sendResetLink(['email' => $person->email]);
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to send reset email: '.$e->getMessage());
        }

        return back()->with('success', "Password reset link sent to {$person->full_name}.");
    }

    public function impersonate(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
        ]);

        $person = Person::findOrFail($validated['person_id']);

        if ($person->client_id !== $client->id || ! $person->portal_enabled) {
            abort(403);
        }

        // Store the staff user's ID so we can show the banner and return them
        $staffUser = $request->user();
        session(['portal_impersonator_id' => $staffUser->id]);
        session(['portal_impersonator_return_url' => route('clients.portal', $client)]);

        // Log into the portal guard as this person
        Auth::guard('portal')->login($person);

        return redirect()->route('portal.dashboard');
    }
}
