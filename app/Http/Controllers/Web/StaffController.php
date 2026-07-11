<?php

namespace App\Http\Controllers\Web;

use App\Enums\NotificationEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffStoreRequest;
use App\Http\Requests\StaffUpdateRequest;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index()
    {
        $users = User::orderBy('name')->get();

        return view('settings.staff.index', ['users' => $users]);
    }

    public function create()
    {
        return view('settings.staff.create');
    }

    public function store(StaffStoreRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = true;
        $data['password'] = '';

        $user = User::create($data);

        return redirect()->route('settings.staff.index')
            ->with('success', "Staff member \"{$user->name}\" created.");
    }

    public function edit(User $user)
    {
        return view('settings.staff.edit', [
            'user' => $user,
            'notificationTypes' => NotificationEventType::cases(),
        ]);
    }

    public function update(StaffUpdateRequest $request, User $user)
    {
        $user->update([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'is_active' => $request->boolean('is_active', $user->is_active),
            'is_contractor' => $request->boolean('is_contractor'),
            'role' => $request->validated('role'),
        ]);

        return redirect()->route('settings.staff.index')
            ->with('success', "Staff member \"{$user->name}\" updated.");
    }

    public function updateAvatar(Request $request, User $user, AvatarService $avatarService)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,gif,webp|max:2048',
        ]);

        $avatarService->uploadAvatar($user, $request->file('avatar'));

        return redirect()->route('settings.staff.edit', $user)
            ->with('success', 'Profile picture updated.');
    }

    public function destroyAvatar(User $user, AvatarService $avatarService)
    {
        $avatarService->deleteAvatar($user);

        return redirect()->route('settings.staff.edit', $user)
            ->with('success', 'Profile picture removed.');
    }

    public function updateNotifications(Request $request, User $user)
    {
        $prefs = [];

        foreach (NotificationEventType::cases() as $type) {
            $prefs[$type->value] = $request->boolean("notify_{$type->value}");
        }

        $user->update(['notification_preferences' => $prefs]);

        return redirect()->route('settings.staff.edit', $user)
            ->with('success', 'Notification preferences updated.');
    }

    public function toggleActive(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('settings.staff.index')
                ->with('error', 'You cannot deactivate your own account.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';

        return redirect()->route('settings.staff.index')
            ->with('success', "\"{$user->name}\" has been {$status}.");
    }
}
