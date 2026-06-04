<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeClientException;
use App\Services\Stripe\StripeSyncService;
use App\Support\StripeConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StripeCustomerController extends Controller
{
    public function index()
    {
        if (! StripeConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Stripe is not configured. Add your API key first.');
        }

        try {
            $client = new StripeClient(['secret_key' => StripeConfig::get('secret_key')]);
            $service = new StripeSyncService($client);
            $customers = $service->fetchStripeCustomers();
        } catch (StripeClientException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not connect to Stripe: {$e->getMessage()}");
        }

        $mappedClients = Client::whereNotNull('stripe_customer_id')
            ->get(['id', 'name', 'stripe_customer_id'])
            ->keyBy('stripe_customer_id');

        $allClients = Client::active()->orderBy('name')->get(['id', 'name']);

        return view('settings.stripe-customers', [
            'customers' => $customers,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        DB::transaction(function () use ($mappings) {
            Client::whereNotNull('stripe_customer_id')->update(['stripe_customer_id' => null]);

            foreach ($mappings as $stripeId => $clientId) {
                if ($clientId) {
                    Client::where('id', $clientId)->update(['stripe_customer_id' => $stripeId]);
                }
            }
        });

        $mapped = collect($mappings)->filter()->count();

        return redirect()->route('settings.stripe-customers.index')
            ->with('success', "Saved {$mapped} Stripe customer mapping(s).");
    }

    public function autoMatch()
    {
        if (! StripeConfig::isConfigured()) {
            return redirect()->route('settings.stripe-customers.index')
                ->with('error', 'Stripe is not configured.');
        }

        $client = new StripeClient(['secret_key' => StripeConfig::get('secret_key')]);
        $service = new StripeSyncService($client);
        $result = $service->autoMatchClients();

        $msg = count($result['matched']).' matched, '
            .count($result['unmatched']).' unmatched, '
            .count($result['ambiguous']).' ambiguous.';

        return redirect()->route('settings.stripe-customers.index')
            ->with('success', "Auto-match complete: {$msg}");
    }
}
