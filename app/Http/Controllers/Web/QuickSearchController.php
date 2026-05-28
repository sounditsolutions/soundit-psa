<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Support\RecentItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuickSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $nav = $this->getNavItems();
        $recent = RecentItems::get(auth()->id())->map(fn ($item) => [
            'label' => $item->label,
            'url' => $item->url,
            'type' => $item->item_type,
            'icon' => RecentItems::iconFor($item->item_type),
        ])->values();

        $results = [];
        $q = trim($request->input('q', ''));

        if (mb_strlen($q) >= 2) {
            $like = '%' . $q . '%';

            $clients = Client::where('name', 'like', $like)
                ->orderBy('name')
                ->limit(5)
                ->get(['id', 'name'])
                ->map(fn ($c) => [
                    'label' => $c->name,
                    'url' => route('clients.show', $c),
                    'type' => 'client',
                    'icon' => 'bi-building',
                ]);

            $tickets = Ticket::where(function ($q2) use ($q, $like) {
                    $q2->where('subject', 'like', $like);
                    if (is_numeric($q)) {
                        $q2->orWhere('id', $q);
                    }
                })
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get(['id', 'subject'])
                ->map(fn ($t) => [
                    'label' => $t->display_id . ' ' . \Illuminate\Support\Str::limit($t->subject, 40),
                    'url' => route('tickets.show', $t),
                    'type' => 'ticket',
                    'icon' => 'bi-ticket-perforated',
                ]);

            $people = Person::where(function ($q2) use ($like) {
                    $q2->where('first_name', 'like', $like)
                       ->orWhere('last_name', 'like', $like)
                       ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                })
                ->orderBy('first_name')
                ->limit(5)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($p) => [
                    'label' => $p->fullName,
                    'url' => route('people.show', $p),
                    'type' => 'person',
                    'icon' => 'bi-person',
                ]);

            $assets = Asset::where(function ($q2) use ($like) {
                    $q2->where('hostname', 'like', $like)
                       ->orWhere('name', 'like', $like);
                })
                ->orderBy('hostname')
                ->limit(5)
                ->get(['id', 'hostname', 'name'])
                ->map(fn ($a) => [
                    'label' => $a->hostname ?: $a->name,
                    'url' => route('assets.show', $a),
                    'type' => 'asset',
                    'icon' => 'bi-pc-display',
                ]);

            $results = $clients->concat($tickets)->concat($people)->concat($assets)->values();
        }

        return response()->json([
            'nav' => $nav,
            'recent' => $recent,
            'results' => $results,
        ]);
    }

    private function getNavItems(): array
    {
        return [
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bi-speedometer2', 'keywords' => 'home overview'],
            ['label' => 'Clients', 'url' => route('clients.index'), 'icon' => 'bi-building', 'keywords' => 'companies accounts'],
            ['label' => 'People', 'url' => route('people.index'), 'icon' => 'bi-person', 'keywords' => 'contacts users'],
            ['label' => 'Assets', 'url' => route('assets.index'), 'icon' => 'bi-pc-display', 'keywords' => 'devices computers workstations servers'],
            ['label' => 'Tickets', 'url' => route('tickets.index'), 'icon' => 'bi-ticket-perforated', 'keywords' => 'service desk issues requests'],
            ['label' => 'New Ticket', 'url' => route('tickets.create'), 'icon' => 'bi-plus-circle', 'keywords' => 'create ticket'],
            ['label' => 'Calls', 'url' => route('calls.index'), 'icon' => 'bi-telephone', 'keywords' => 'phone log'],
            ['label' => 'Emails', 'url' => route('emails.index'), 'icon' => 'bi-envelope', 'keywords' => 'inbox messages'],
            ['label' => 'Compose Email', 'url' => route('emails.compose'), 'icon' => 'bi-pencil-square', 'keywords' => 'send write email'],
            ['label' => 'Invoices', 'url' => route('invoices.index'), 'icon' => 'bi-receipt', 'keywords' => 'billing payments'],
            ['label' => 'Contracts', 'url' => route('contracts.index-all'), 'icon' => 'bi-file-earmark-text', 'keywords' => 'agreements services'],
            ['label' => 'Recurring Profiles', 'url' => route('profiles.index'), 'icon' => 'bi-arrow-repeat', 'keywords' => 'billing recurring'],
            ['label' => 'Prepay', 'url' => route('prepay.index'), 'icon' => 'bi-wallet2', 'keywords' => 'prepaid hours balance'],
            ['label' => 'SKUs', 'url' => route('skus.index'), 'icon' => 'bi-box', 'keywords' => 'products catalog pricing'],
            ['label' => 'Licenses', 'url' => route('licenses.index'), 'icon' => 'bi-key', 'keywords' => 'software subscriptions'],
            ['label' => 'License Types', 'url' => route('license-types.index'), 'icon' => 'bi-key', 'keywords' => 'software vendors'],
            ['label' => 'Profitability', 'url' => route('profitability.index'), 'icon' => 'bi-graph-up', 'keywords' => 'margin revenue cost'],
            ['label' => 'General Settings', 'url' => route('settings.general'), 'icon' => 'bi-sliders', 'keywords' => 'configuration preferences'],
            ['label' => 'Staff', 'url' => route('settings.staff.index'), 'icon' => 'bi-people', 'keywords' => 'team members users'],
            ['label' => 'Integrations', 'url' => route('settings.integrations'), 'icon' => 'bi-plug', 'keywords' => 'mesh cipp ninja stripe qbo'],
            ['label' => 'Preferences', 'url' => route('preferences.edit'), 'icon' => 'bi-gear', 'keywords' => 'my settings profile'],
            ['label' => 'About', 'url' => route('about'), 'icon' => 'bi-info-circle', 'keywords' => 'version updates'],
        ];
    }
}
