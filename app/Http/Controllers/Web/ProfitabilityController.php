<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Services\ProfitabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ProfitabilityController extends Controller
{
    public function __construct(
        private readonly ProfitabilityService $profitabilityService,
    ) {}

    public function index(Request $request)
    {
        [$from, $to] = $this->parseDateRange($request);

        $data = $this->profitabilityService->businessProfitability($from, $to);

        return view('profitability.index', [
            'data' => $data,
            'from' => $from?->format('Y-m-d'),
            'to' => $to?->format('Y-m-d'),
        ]);
    }

    public function client(Request $request, Client $client)
    {
        [$from, $to] = $this->parseDateRange($request);

        $data = $this->profitabilityService->clientProfitability($client, $from, $to);

        return view('profitability.client', [
            'client' => $client,
            'data' => $data,
            'from' => $from?->format('Y-m-d'),
            'to' => $to?->format('Y-m-d'),
        ]);
    }

    public function contract(Request $request, Contract $contract)
    {
        $contract->load('client');
        [$from, $to] = $this->parseDateRange($request);

        $data = $this->profitabilityService->contractProfitability($contract, $from, $to);
        $trend = $this->profitabilityService->contractMonthlyTrend($contract);

        return view('profitability.contract', [
            'contract' => $contract,
            'data' => $data,
            'trend' => $trend,
            'from' => $from?->format('Y-m-d'),
            'to' => $to?->format('Y-m-d'),
        ]);
    }

    private function parseDateRange(Request $request): array
    {
        try {
            $from = $request->query('from') ? Carbon::parse($request->query('from')) : null;
        } catch (\Throwable) {
            $from = null;
        }

        try {
            $to = $request->query('to') ? Carbon::parse($request->query('to')) : null;
        } catch (\Throwable) {
            $to = null;
        }

        return [$from, $to];
    }
}
