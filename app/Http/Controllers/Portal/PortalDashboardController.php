<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Sku;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PortalDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $person = $request->attributes->get('portal_person');
        $clientId = $request->attributes->get('portal_client_id');

        $ticketQuery = Ticket::portalVisible()->where('client_id', $clientId);
        if (! $person->company_wide_access) {
            $ticketQuery->where('contact_id', $person->id);
        }

        $openTickets = (clone $ticketQuery)->open()->count();

        $unpaidTotal = Invoice::where('client_id', $clientId)
            ->whereIn('status', [InvoiceStatus::Posted, InvoiceStatus::Synced])
            ->sum('total');

        $recentTickets = (clone $ticketQuery)
            ->with('categoryNode.parent.parent')
            ->latest('updated_at')
            ->limit(5)
            ->get();

        $unpaidInvoices = Invoice::where('client_id', $clientId)
            ->whereIn('status', [InvoiceStatus::Posted, InvoiceStatus::Synced])
            // #1173: each row's "partially paid" note reads the last
            // QBO-sourced status change.
            ->with('latestQboStatusChange')
            ->orderByDesc('due_date')
            ->limit(5)
            ->get();

        // Prepay balance from active contracts with prepay (hours-based only for portal display)
        $prepayContracts = Contract::where('client_id', $clientId)
            ->active()
            ->whereNotNull('prepay_balance')
            ->where(function ($q) {
                $q->where('prepay_as_amount', false)->orWhereNull('prepay_as_amount');
            })
            ->get(['id', 'name', 'prepay_balance']);

        $totalPrepayHours = $prepayContracts->sum('prepay_balance');

        // Contracts configured for portal prepaid purchases (the same filter
        // the purchase flow's selectContract() uses).
        $purchasableContracts = Contract::where('client_id', $clientId)
            ->active()
            ->whereNotNull('portal_prepay_sku_id')
            ->where(fn ($q) => $q->where('prepay_as_amount', false)->orWhereNull('prepay_as_amount'))
            ->with('portalPrepaySku')
            ->orderBy('name')
            ->get();

        $hasPurchasableContracts = $purchasableContracts->isNotEmpty();
        $prepayPromos = $this->prepayPromos($purchasableContracts);

        // #864: install tokens expire, and PortalInstallService::findByToken()
        // rejects an expired one with the same "not valid" copy an invalid
        // token gets. Apply the same predicate here or the dashboard keeps
        // advertising a link that dead-ends the moment the TTL lapses. A NULL
        // expiry is the deliberate per-row "no expiry" exception.
        $installToken = \App\Models\Client::where('id', $clientId)
            ->where(fn ($q) => $q->whereNull('portal_install_token_expires_at')
                ->orWhere('portal_install_token_expires_at', '>', now()))
            ->value('portal_install_token');

        return view('portal.dashboard', compact(
            'person',
            'openTickets',
            'unpaidTotal',
            'recentTickets',
            'unpaidInvoices',
            'prepayContracts',
            'totalPrepayHours',
            'hasPurchasableContracts',
            'prepayPromos',
            'clientId',
            'installToken',
        ));
    }

    /**
     * One line per portal-prepay SKU whose promo is in force right now, for
     * the Support Hours card. "In force" is Sku::portalPromoInForce(), the
     * same inclusive window check Sku::portalPrepayUnitPrice() charges by, read
     * at one instant, so the card never advertises a price the purchase would
     * not charge. Hours and prices come from the SKU. An inactive SKU is left
     * out: its purchase is refused.
     *
     * @param  Collection<int, Contract>  $contracts
     * @return list<array{hours: float, promo: float, normal: float, ends_at: CarbonInterface, url: string}>
     */
    private function prepayPromos(Collection $contracts): array
    {
        $now = now();
        $promos = [];

        foreach ($contracts->groupBy('portal_prepay_sku_id') as $group) {
            $sku = $group->first()->portalPrepaySku;

            if ($sku === null || ! $sku->is_active || ! $sku->portalPromoInForce($now)) {
                continue;
            }

            $promos[] = [
                'hours' => round($sku->prepaid_time_minutes / 60, 1),
                'promo' => $sku->portalPrepayUnitPrice($now),
                'normal' => (float) $sku->unit_price,
                'ends_at' => $sku->portal_promo_ends_at->copy()->setTimezone(Sku::PROMO_TIMEZONE),
                // One contract on this SKU: straight to its purchase page.
                'url' => $group->count() === 1
                    ? route('portal.prepaid.form', $group->first())
                    : route('portal.prepaid.select'),
            ];
        }

        return $promos;
    }
}
