<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Services\EmailService;
use App\Support\PortalConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class PortalAuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('portal')->check()) {
            return redirect()->route('portal.dashboard');
        }

        return view('portal.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $remember = $request->boolean('remember');

        if (Auth::guard('portal')->attempt(
            array_merge($credentials, ['portal_enabled' => true, 'is_active' => true]),
            $remember,
        )) {
            // Defense-in-depth: the portal user provider already excludes
            // prospect-stage contacts, but never hand out a session to anyone
            // who fails the stage gate even if the provider were misconfigured.
            if (! Auth::guard('portal')->user()->canAccessPortal()) {
                Auth::guard('portal')->logout();

                return back()->withErrors([
                    'email' => 'These credentials do not match our records.',
                ])->onlyInput('email');
            }

            $request->session()->regenerate();

            Auth::guard('portal')->user()->update([
                'portal_last_login_at' => now(),
            ]);

            return redirect()->intended(route('portal.dashboard'));
        }

        return back()->withErrors([
            'email' => 'These credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        // If impersonating, stop impersonation instead of full logout
        if (session('portal_impersonator_id')) {
            return $this->stopImpersonating($request);
        }

        Auth::guard('portal')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    public function showForgotPassword(): View
    {
        return view('portal.auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            Password::broker('portal')->sendResetLink(
                $request->only('email')
            );
        } catch (\Throwable $e) {
            // Swallow to preserve anti-enumeration (don't reveal whether the email exists)
            \Illuminate\Support\Facades\Log::warning('[Portal] Password reset email failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Always show success message (prevent email enumeration)
        return back()->with('status', 'If an account exists with that email, a password reset link has been sent.');
    }

    public function showResetForm(Request $request, string $token): View
    {
        return view('portal.auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker('portal')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($person, string $password) {
                // Defense-in-depth: the portal broker resolves people through
                // PortalUserProvider (Active-stage only), but never set a password
                // or grant a session for a contact that fails the stage gate.
                if ($person->client?->stage !== \App\Enums\ClientStage::Active) {
                    return;
                }

                $person->forceFill([
                    'password' => $password,
                ])->save();

                Auth::guard('portal')->login($person);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('portal.dashboard')
                ->with('success', 'Your password has been reset.');
        }

        return back()->withErrors(['email' => [__($status)]]);
    }

    public function showRequestAccess(): View
    {
        return view('portal.auth.request-access');
    }

    public function sendAccessLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = $request->input('email');

        // Find eligible person: active, has email, not already portal-enabled,
        // and belonging to an Active-stage client (prospects are never eligible).
        $person = Person::where('email', $email)
            ->where('is_active', true)
            ->where('portal_enabled', false)
            ->whereHas('client', fn ($q) => $q->where('stage', \App\Enums\ClientStage::Active))
            ->first();

        if ($person) {
            // Check for duplicate portal-enabled email
            $duplicate = Person::where('email', $email)
                ->where('portal_enabled', true)
                ->where('id', '!=', $person->id)
                ->exists();

            if (! $duplicate) {
                $url = URL::temporarySignedRoute(
                    'portal.verify-access',
                    now()->addMinutes(60),
                    ['person' => $person->id],
                );

                $companyName = PortalConfig::companyName();
                $body = "You requested access to the {$companyName} client portal.\n\n"
                    ."Click the link below to verify your email and set your password:\n{$url}\n\n"
                    ."This link will expire in 60 minutes.\n\n"
                    .'If you did not request this, you can safely ignore this email.';

                try {
                    app(EmailService::class)->sendNew(
                        $person->email,
                        "Verify your {$companyName} Portal access",
                        $body,
                        $person->full_name,
                    );
                } catch (\Throwable $e) {
                    Log::warning('[Portal] Access verification email failed', [
                        'email' => $email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Always show same message (anti-enumeration)
        return back()->with('status', 'If an account exists with that email, a verification link has been sent.');
    }

    public function verifyAccess(Request $request, int $person): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()->route('portal.login')
                ->with('error', 'This verification link is invalid or has expired.');
        }

        $person = Person::where('id', $person)
            ->where('is_active', true)
            ->where('portal_enabled', false)
            ->whereHas('client', fn ($q) => $q->where('stage', \App\Enums\ClientStage::Active))
            ->first();

        if (! $person) {
            return redirect()->route('portal.login')
                ->with('error', 'This verification link is invalid or has expired.');
        }

        // Enable portal access
        $person->update(['portal_enabled' => true]);

        // Generate password reset token and redirect to set-password form
        $token = Password::broker('portal')->createToken($person);

        return redirect()->route('portal.password.reset', [
            'token' => $token,
            'email' => $person->email,
        ]);
    }

    public function stopImpersonating(Request $request): RedirectResponse
    {
        $returnUrl = session('portal_impersonator_return_url', '/');

        Auth::guard('portal')->logout();

        // Only forget impersonation keys, don't invalidate the whole session
        // (the staff user's web guard session is still active)
        $request->session()->forget(['portal_impersonator_id', 'portal_impersonator_return_url']);

        return redirect($returnUrl);
    }
}
