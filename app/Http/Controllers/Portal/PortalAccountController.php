<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PortalAccountController extends Controller
{
    public function edit(Request $request): View
    {
        $person = $request->attributes->get('portal_person');

        return view('portal.account.edit', compact('person'));
    }

    public function update(Request $request): RedirectResponse
    {
        $person = $request->attributes->get('portal_person');

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile' => ['nullable', 'string', 'max:30'],
        ]);

        $person->update($validated);

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $person = $request->attributes->get('portal_person');

        $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($request->current_password, $person->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.']);
        }

        $person->forceFill(['password' => $request->password])->save();

        return back()->with('success', 'Password changed.');
    }
}
