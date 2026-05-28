<?php

namespace App\Http\Controllers\Web;

use App\Enums\PhoneDirectoryListType;
use App\Http\Controllers\Controller;
use App\Models\PhoneCall;
use App\Models\PhoneDirectoryEntry;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PhoneDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $tab = $request->input('tab', PhoneDirectoryListType::Blocked->value);
        $type = PhoneDirectoryListType::tryFrom($tab) ?? PhoneDirectoryListType::Blocked;
        $search = trim((string) $request->input('q', ''));

        $query = PhoneDirectoryEntry::with('addedBy')
            ->where('list_type', $type)
            ->orderByDesc('id');

        if ($search !== '') {
            $needle = PhoneNumber::normalize($search) ?: $search;
            $query->where(function ($q) use ($needle, $search) {
                $q->where('phone_number', 'like', "%{$needle}%")
                  ->orWhere('label', 'like', "%{$search}%");
            });
        }

        $counts = [
            PhoneDirectoryListType::Blocked->value => PhoneDirectoryEntry::where('list_type', PhoneDirectoryListType::Blocked)->count(),
            PhoneDirectoryListType::Allowed->value => PhoneDirectoryEntry::where('list_type', PhoneDirectoryListType::Allowed)->count(),
        ];

        return view('phone-directory.index', [
            'entries' => $query->paginate(50)->withQueryString(),
            'search' => $search,
            'activeType' => $type,
            'counts' => $counts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:30'],
            'list_type' => ['required', 'string', 'in:blocked,allowed'],
            'label' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $type = PhoneDirectoryListType::from($validated['list_type']);

        $normalized = PhoneNumber::normalize($validated['phone_number']);
        if (! $normalized) {
            return back()->withInput()->with('error', 'Could not parse that phone number.');
        }

        $existing = PhoneDirectoryEntry::where('phone_number', $normalized)->first();
        if ($existing) {
            $existingLabel = $existing->list_type->label();

            return back()->with('info', "{$normalized} is already on the {$existingLabel} list.");
        }

        PhoneDirectoryEntry::create([
            'phone_number' => $normalized,
            'list_type' => $type,
            'label' => $validated['label'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'added_by_user_id' => auth()->id(),
        ]);

        return back()->with('success', "{$normalized} added to the {$type->label()} list.");
    }

    public function destroy(PhoneDirectoryEntry $entry): RedirectResponse
    {
        $number = $entry->phone_number;
        $listLabel = $entry->list_type->label();
        $entry->delete();

        return back()->with('success', "{$number} removed from the {$listLabel} list.");
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $count = PhoneDirectoryEntry::whereIn('id', $validated['ids'])->delete();

        return back()->with('success', "Removed {$count} entr".($count === 1 ? 'y' : 'ies').'.');
    }

    /**
     * One-click block from the call detail page. Uses the call's from_number.
     */
    public function blockFromCall(PhoneCall $call): RedirectResponse
    {
        return $this->addFromCall($call, PhoneDirectoryListType::Blocked);
    }

    /**
     * One-click allow from the call detail page. Uses the call's from_number.
     */
    public function allowFromCall(Request $request, PhoneCall $call): RedirectResponse
    {
        $label = $request->input('label');

        return $this->addFromCall($call, PhoneDirectoryListType::Allowed, is_string($label) ? trim($label) : null);
    }

    private function addFromCall(PhoneCall $call, PhoneDirectoryListType $type, ?string $label = null): RedirectResponse
    {
        $normalized = PhoneNumber::normalize($call->from_number);
        if (! $normalized) {
            return back()->with('error', 'Could not parse the caller number.');
        }

        $existing = PhoneDirectoryEntry::where('phone_number', $normalized)->first();
        if ($existing) {
            $existingLabel = $existing->list_type->label();

            return back()->with('info', "This number is already on the {$existingLabel} list.");
        }

        PhoneDirectoryEntry::create([
            'phone_number' => $normalized,
            'list_type' => $type,
            'label' => $label !== '' ? $label : null,
            'reason' => "Added from call #{$call->id}",
            'added_by_user_id' => auth()->id(),
        ]);

        $message = $type === PhoneDirectoryListType::Blocked
            ? "{$normalized} added to the Blocked list. Future calls will be hung up by the IVR."
            : "{$normalized} added to the Allowed list. Future calls will ring through.";

        return back()->with('success', $message);
    }
}
