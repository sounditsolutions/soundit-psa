<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function redirectToMicrosoft()
    {
        if (!config('services.microsoft.tenant')) {
            return redirect()->route('login')->withErrors([
                'sso' => 'Microsoft SSO is not configured. Set MICROSOFT_TENANT_ID in the environment.',
            ]);
        }

        return Socialite::driver('microsoft')->redirect();
    }

    public function handleMicrosoftCallback()
    {
        try {
            $microsoftUser = Socialite::driver('microsoft')->user();
        } catch (\Exception $e) {
            return redirect()->route('login')->withErrors([
                'sso' => 'Microsoft authentication failed. Please try again.',
            ]);
        }

        // Defense-in-depth: verify the token's tenant matches our configured tenant
        $expectedTenant = config('services.microsoft.tenant');
        $actualTenant = $microsoftUser->getRaw()['tid'] ?? null;

        if ($expectedTenant && $actualTenant && $actualTenant !== $expectedTenant) {
            Log::warning('[Auth] Tenant ID mismatch', [
                'expected' => $expectedTenant,
                'actual' => $actualTenant,
                'email' => $microsoftUser->getEmail(),
            ]);
            return redirect()->route('login')->withErrors([
                'sso' => 'Your Microsoft account is not authorized for this application.',
            ]);
        }

        $user = User::updateOrCreate(
            ['email' => $microsoftUser->getEmail()],
            [
                'name'         => $microsoftUser->getName(),
                'microsoft_id' => $microsoftUser->getId(),
            ]
        );

        if (!$user->is_active) {
            Log::warning('[Auth] Inactive user attempted login', [
                'email' => $user->email,
                'user_id' => $user->id,
            ]);

            return redirect()->route('login')->withErrors([
                'sso' => 'Your account has been deactivated. Contact an administrator.',
            ]);
        }

        Auth::login($user, remember: true);

        // Fetch Entra ID profile photo in the background (after response is sent)
        if ($user->microsoft_id) {
            app()->terminating(function () use ($user) {
                app(AvatarService::class)->fetchEntraPhoto($user);
            });
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
