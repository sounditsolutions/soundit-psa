<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProfitabilityService
{
    /**
     * Profitability for a single contract within a date range.
     */
    public function contractProfitability(Contract $contract, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = Invoice::where('contract_id', $contract->id)
            ->whereNull('deleted_at')
            ->whereNotNull('subtotal');

        if ($from) {
            $query->where('invoice_date', '>=', $from);
        }
        if ($to) {
            $query->where('invoice_date', '<=', $to);
        }

        $totals = $query->selectRaw('
            COALESCE(SUM(subtotal), 0) as revenue,
            COALESCE(SUM(total_cost), 0) as cost
        ')->first();

        $revenue = (float) $totals->revenue;
        $cost = (float) $totals->cost;
        $margin = $revenue - $cost;
        $marginPct = $revenue > 0 ? round(($margin / $revenue) * 100, 1) : null;

        // Breakdown by SKU
        $bySku = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->leftJoin('skus', 'skus.id', '=', 'invoice_lines.sku_id')
            ->where('invoices.contract_id', $contract->id)
            ->whereNull('invoices.deleted_at')
            ->when($from, fn ($q) => $q->where('invoices.invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('invoices.invoice_date', '<=', $to))
            ->groupBy('invoice_lines.sku_id', 'skus.name', 'skus.sku_code', 'invoice_lines.description')
            ->selectRaw('
                invoice_lines.sku_id,
                COALESCE(skus.name, invoice_lines.description) as sku_name,
                skus.sku_code,
                COALESCE(SUM(invoice_lines.amount), 0) as revenue,
                COALESCE(SUM(invoice_lines.cost_amount), 0) as cost
            ')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'sku_name' => $row->sku_name,
                'sku_code' => $row->sku_code,
                'revenue' => (float) $row->revenue,
                'cost' => (float) $row->cost,
                'margin' => (float) $row->revenue - (float) $row->cost,
            ])
            ->all();

        return compact('revenue', 'cost', 'margin', 'marginPct', 'bySku');
    }

    /**
     * Monthly revenue/cost/margin trend for a contract over the last N months.
     */
    public function contractMonthlyTrend(Contract $contract, int $months = 12): array
    {
        $since = now()->subMonths($months)->startOfMonth();

        return DB::table('invoices')
            ->where('contract_id', $contract->id)
            ->whereNull('deleted_at')
            ->where('invoice_date', '>=', $since)
            ->selectRaw("
                DATE_FORMAT(invoice_date, '%Y-%m') as month,
                COALESCE(SUM(subtotal), 0) as revenue,
                COALESCE(SUM(total_cost), 0) as cost
            ")
            ->groupByRaw("DATE_FORMAT(invoice_date, '%Y-%m')")
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'revenue' => (float) $row->revenue,
                'cost' => (float) $row->cost,
                'margin' => (float) $row->revenue - (float) $row->cost,
            ])
            ->all();
    }

    /**
     * Profitability for a client (aggregated across all contracts).
     */
    public function clientProfitability(Client $client, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = Invoice::where('client_id', $client->id)
            ->whereNull('deleted_at')
            ->whereNotNull('subtotal');

        if ($from) {
            $query->where('invoice_date', '>=', $from);
        }
        if ($to) {
            $query->where('invoice_date', '<=', $to);
        }

        $totals = $query->selectRaw('
            COALESCE(SUM(subtotal), 0) as revenue,
            COALESCE(SUM(total_cost), 0) as cost
        ')->first();

        $revenue = (float) $totals->revenue;
        $cost = (float) $totals->cost;
        $margin = $revenue - $cost;
        $marginPct = $revenue > 0 ? round(($margin / $revenue) * 100, 1) : null;

        // Breakdown by contract. This joins `contracts`, which also carries
        // `client_id` and `deleted_at`, so every invoice column must be
        // qualified or the filters are ambiguous (MariaDB 1052 / SQLite).
        $byContract = Invoice::where('invoices.client_id', $client->id)
            ->whereNull('invoices.deleted_at')
            ->whereNotNull('invoices.contract_id')
            ->when($from, fn ($q) => $q->where('invoices.invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('invoices.invoice_date', '<=', $to))
            ->join('contracts', 'contracts.id', '=', 'invoices.contract_id')
            ->groupBy('invoices.contract_id', 'contracts.name')
            ->selectRaw('
                invoices.contract_id,
                contracts.name as contract_name,
                COALESCE(SUM(invoices.subtotal), 0) as revenue,
                COALESCE(SUM(invoices.total_cost), 0) as cost
            ')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'contract_id' => $row->contract_id,
                'contract_name' => $row->contract_name,
                'revenue' => (float) $row->revenue,
                'cost' => (float) $row->cost,
                'margin' => (float) $row->revenue - (float) $row->cost,
            ])
            ->all();

        return compact('revenue', 'cost', 'margin', 'marginPct', 'byContract');
    }

    /**
     * Business-wide profitability with breakdown by client.
     */
    public function businessProfitability(?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = Invoice::whereNull('deleted_at')
            ->whereNull('halo_id') // PSA-generated only
            ->whereNotNull('subtotal');

        if ($from) {
            $query->where('invoice_date', '>=', $from);
        }
        if ($to) {
            $query->where('invoice_date', '<=', $to);
        }

        $totals = $query->selectRaw('
            COALESCE(SUM(subtotal), 0) as revenue,
            COALESCE(SUM(total_cost), 0) as cost,
            COUNT(*) as invoice_count
        ')->first();

        $revenue = (float) $totals->revenue;
        $cost = (float) $totals->cost;
        $margin = $revenue - $cost;
        $marginPct = $revenue > 0 ? round(($margin / $revenue) * 100, 1) : null;
        $invoiceCount = (int) $totals->invoice_count;

        // Breakdown by client
        $byClient = Invoice::whereNull('invoices.deleted_at')
            ->whereNull('invoices.halo_id')
            ->when($from, fn ($q) => $q->where('invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('invoice_date', '<=', $to))
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->groupBy('invoices.client_id', 'clients.name')
            ->selectRaw('
                invoices.client_id,
                clients.name as client_name,
                COALESCE(SUM(invoices.subtotal), 0) as revenue,
                COALESCE(SUM(invoices.total_cost), 0) as cost,
                COUNT(*) as invoice_count
            ')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'client_id' => $row->client_id,
                'client_name' => $row->client_name,
                'revenue' => (float) $row->revenue,
                'cost' => (float) $row->cost,
                'margin' => (float) $row->revenue - (float) $row->cost,
                'margin_pct' => (float) $row->revenue > 0
                    ? round(((float) $row->revenue - (float) $row->cost) / (float) $row->revenue * 100, 1)
                    : null,
                'invoice_count' => (int) $row->invoice_count,
            ])
            ->all();

        return compact('revenue', 'cost', 'margin', 'marginPct', 'invoiceCount', 'byClient');
    }
}
