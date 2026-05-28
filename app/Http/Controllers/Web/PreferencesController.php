<?php

namespace App\Http\Controllers\Web;

use App\Enums\NotificationEventType;
use App\Http\Controllers\Controller;
use App\Models\SipEndpoint;
use App\Services\AvatarService;
use Illuminate\Http\Request;

class PreferencesController extends Controller
{
    public function edit()
    {
        $endpoint = SipEndpoint::where('user_id', auth()->id())->first();

        return view('preferences.edit', [
            'endpoint' => $endpoint,
            'notificationTypes' => NotificationEventType::cases(),
            'user' => auth()->user(),
        ]);
    }

    public function update(Request $request)
    {
        $existing = SipEndpoint::where('user_id', auth()->id())->first();

        $rules = [
            'label' => 'required|string|max:100',
            'sip_username' => 'required|string|max:100|regex:/^[a-zA-Z0-9_]+$/',
            'is_active' => 'boolean',
        ];

        // Password required for new endpoints, optional on update
        if ($existing) {
            $rules['sip_password'] = 'nullable|string|max:255';
        } else {
            $rules['sip_password'] = 'required|string|max:255';
        }

        $validated = $request->validate($rules, [
            'sip_username.regex' => 'Username may only contain letters, numbers, and underscores.',
            'sip_password.required' => 'Password is required for new endpoints.',
        ]);

        $sipUri = 'sip:' . $validated['sip_username'] . '@phone.plivo.com';

        $data = [
            'sip_uri' => $sipUri,
            'sip_username' => $validated['sip_username'],
            'label' => $validated['label'],
            'is_active' => $request->boolean('is_active'),
        ];

        // Only update password if provided
        if (!empty($validated['sip_password'])) {
            $data['sip_password'] = $validated['sip_password'];
        }

        if ($existing) {
            // Check uniqueness excluding own record
            $duplicate = SipEndpoint::where('sip_username', $validated['sip_username'])
                ->where('id', '!=', $existing->id)
                ->exists();

            if ($duplicate) {
                return back()->withErrors(['sip_username' => 'This username is already registered to another user.'])->withInput();
            }

            $existing->update($data);
        } else {
            $duplicate = SipEndpoint::where('sip_username', $validated['sip_username'])->exists();

            if ($duplicate) {
                return back()->withErrors(['sip_username' => 'This username is already registered to another user.'])->withInput();
            }

            $data['user_id'] = auth()->id();
            SipEndpoint::create($data);
        }

        return redirect()->route('preferences.edit')
            ->with('success', 'SIP endpoint saved.');
    }

    public function updateAvatar(Request $request, AvatarService $avatarService)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,gif,webp|max:2048',
        ]);

        $avatarService->uploadAvatar(auth()->user(), $request->file('avatar'));

        return redirect()->route('preferences.edit')
            ->with('success', 'Profile picture updated.');
    }

    public function destroyAvatar(AvatarService $avatarService)
    {
        $avatarService->deleteAvatar(auth()->user());

        return redirect()->route('preferences.edit')
            ->with('success', 'Profile picture removed.');
    }

    public function updateSignature(Request $request)
    {
        $request->validate([
            'email_signature' => 'nullable|string|max:2000',
        ]);

        auth()->user()->update([
            'email_signature' => $request->input('email_signature') ?: null,
        ]);

        return redirect()->route('preferences.edit')
            ->with('success', 'Email signature saved.');
    }

    public function updateNotifications(Request $request)
    {
        $user = auth()->user();
        $prefs = [];

        foreach (NotificationEventType::cases() as $type) {
            $prefs[$type->value] = $request->boolean("notify_{$type->value}");
        }

        $user->update(['notification_preferences' => $prefs]);

        return redirect()->route('preferences.edit')
            ->with('success', 'Notification preferences saved.');
    }
}
