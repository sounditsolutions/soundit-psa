{{--
    Client Billing tab (card iNjqGETC), READ-ONLY: no control here moves money,
    sends or voids. Data comes from App\Services\ClientBillingSummary.
    Mockups: design/mockups/billing/billing-tab{,-empty}.html.
    Expects: $billing, $client
--}}
@php
    $inv = $billing['invoices'];
    $con = $billing['contracts'];
    $pre = $billing['prepay'];
    $bp = $billing['benjipays'];
    $tz = $billing['tz'];
    $usd = fn ($v) => '$'.number_format((float) $v, 2);
@endphp
<div data-billing-tab>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
        <div class="card card-static h-100 billing-kpi billing-kpi-signal" data-kpi="outstanding">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-semibold">Outstanding</div>
                <div class="fs-4 fw-bold" data-figure="outstanding">{{ $usd($inv['outstanding']) }}</div>
                <div class="small text-muted">
                    @if($inv['open_count'] === 0)
                        No open invoices
                    @else
                        {{ $inv['open_count'] }} open {{ \Illuminate\Support\Str::plural('invoice', $inv['open_count']) }}@if($inv['partial_count'] > 0); includes {{ $inv['partial_count'] }} part-paid {{ \Illuminate\Support\Str::plural('invoice', $inv['partial_count']) }} (QBO as of {{ $inv['partial_as_of']->copy()->setTimezone($tz)->format('M j, H:i') }})@endif
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-static h-100 billing-kpi" data-kpi="overdue">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-semibold">Overdue</div>
                <div class="fs-4 fw-bold" data-figure="overdue">{{ $usd($inv['overdue']) }}</div>
                @if($inv['overdue_count'] > 0)
                    <div class="small text-muted">{{ $inv['overdue_count'] }} {{ \Illuminate\Support\Str::plural('invoice', $inv['overdue_count']) }} past due</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-static h-100 billing-kpi" data-kpi="monthly">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-semibold">Monthly recurring</div>
                <div class="fs-4 fw-bold" data-figure="monthly">{{ $usd($con['monthly_total']) }}</div>
                <div class="small text-muted">
                    @if(count($con['rows']) === 0)
                        No active contracts
                    @else
                        {{ $con['recurring_count'] }} {{ \Illuminate\Support\Str::plural('active contract', $con['recurring_count']) }} with recurring billing
                        @if($con['unpriced_count'] > 0)
                            ; {{ $con['unpriced_count'] }} could not be priced and {{ $con['unpriced_count'] === 1 ? 'is' : 'are' }} not included
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-static h-100 billing-kpi" data-kpi="prepay">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-semibold">Prepay balance</div>
                @if($pre['contracts']->isEmpty())
                    <div class="fs-4 fw-bold text-muted">&mdash;</div>
                    <div class="small text-muted">No prepay contract</div>
                @else
                    @foreach($pre['contracts'] as $pc)
                        <div class="fs-4 fw-bold">{{ $pc->prepay_balance_formatted }}</div>
                        <div class="small text-muted">{{ $pc->prepay_as_amount ? 'Amount prepay' : 'Hours prepay' }}, contract {{ $pc->name }}</div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Invoices --}}
<div class="card shadow-sm card-static mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt me-2"></i>Invoices</span>
        @if($inv['total_count'] > 0)
            <span class="small">@if($inv['open_count'] > 0)Open first, then the newest {{ $inv['other_shown'] }}@else Newest {{ $inv['other_shown'] }}@endif of {{ $inv['total_count'] }}</span>
        @endif
    </div>
    @if($inv['total_count'] === 0)
        <div class="card-body text-muted">No invoices for this client yet.</div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Due</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($inv['rows'] as $row)
                        @php($i = $row['invoice'])
                        <tr data-invoice-row="{{ $i->id }}">
                            <td><a href="{{ route('invoices.show', $i) }}">{{ $i->display_number }}</a></td>
                            <td>{{ $i->invoice_date?->format('M j, Y') ?? '—' }}</td>
                            <td>{{ $i->due_date?->format('M j, Y') ?? '—' }}</td>
                            <td class="text-end">{{ $usd($i->display_total) }}</td>
                            <td class="text-end">
                                @if($row['balance'] === null)
                                    <span class="text-muted">—</span>
                                @elseif(! $row['open'])
                                    <span class="text-muted">{{ $usd($row['balance']) }}</span>
                                @else
                                    {{ $usd($row['balance']) }}
                                    @if($row['partial_as_of'])
                                        <span class="small text-muted">QBO, {{ $row['partial_as_of']->copy()->setTimezone($tz)->format('M j') }}</span>
                                    @endif
                                @endif
                            </td>
                            <td>
                                @if($row['overdue'])
                                    <span class="badge bg-danger">Overdue</span>
                                @endif
                                @if($row['partial_as_of'])
                                    {{-- Display-only label; the invoice status is unchanged. --}}
                                    <span class="badge bg-warning text-dark">Part-paid</span>
                                @endif
                                @if(! $row['overdue'] && ! $row['partial_as_of'])
                                    <span class="badge {{ $i->status->badgeClass() }}">{{ $i->status->label() }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-body">
            <a href="{{ route('invoices.index', ['client_id' => $client->id]) }}">View all {{ $inv['total_count'] }} {{ \Illuminate\Support\Str::plural('invoice', $inv['total_count']) }}</a>
            <span class="small text-muted">(opens Invoices filtered to this client)</span>
            <p class="small text-muted mb-0 mt-2">Outstanding counts posted and synced invoices only. Drafts, paid and voided invoices are excluded. A balance below the total is the last QuickBooks figure and is shown with its date.</p>
        </div>
    @endif
</div>

<div class="row g-3 mb-3">
    {{-- Contracts and recurring --}}
    <div class="col-lg-6">
        <div class="card shadow-sm card-static h-100">
            <div class="card-header"><i class="bi bi-file-earmark-text me-2"></i>Contracts and recurring</div>
            @if(count($con['rows']) === 0)
                <div class="card-body text-muted">No active contracts.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="thead-brand">
                            <tr><th>Contract</th><th>Type</th><th class="text-end">Monthly</th><th>Next invoice</th></tr>
                        </thead>
                        <tbody>
                            @foreach($con['rows'] as $row)
                                @php($c = $row['contract'])
                                <tr>
                                    <td><a href="{{ route('contracts.show', $c) }}">{{ $c->name }}</a></td>
                                    <td>{{ $c->type?->label() ?? '—' }}</td>
                                    <td class="text-end">
                                        @if($row['failed'])
                                            <span class="text-muted">Could not be priced</span>
                                        @elseif($row['monthly'] === null)
                                            <span class="text-muted">—</span>
                                        @else
                                            {{ $usd($row['monthly']) }}
                                        @endif
                                    </td>
                                    <td>
                                        @if($row['next_invoice'])
                                            {{ $row['next_invoice']->format('M j, Y') }}
                                        @elseif($c->has_prepay)
                                            <span class="text-muted">On deposit</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- Prepay --}}
    <div class="col-lg-6">
        <div class="card shadow-sm card-static h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-hourglass-split me-2"></i>Prepay</span>
                @if($pre['contracts']->count() === 1)
                    <span class="small">Balance {{ $pre['contracts']->first()->prepay_balance_formatted }}</span>
                @endif
            </div>
            @if($pre['contracts']->isEmpty())
                <div class="card-body text-muted">This client has no prepay contract.</div>
            @else
                @if($pre['transactions']->isEmpty())
                    <div class="card-body text-muted">No prepay entries yet.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="thead-brand">
                                <tr>
                                    <th>Date</th><th>Entry</th>
                                    @if($pre['contracts']->count() > 1)<th>Contract</th>@endif
                                    <th class="text-end">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($pre['transactions'] as $txn)
                                    <tr>
                                        <td>{{ $txn->date?->format('M j, Y') ?? '—' }}</td>
                                        <td>{{ $txn->description ?? $txn->source?->label() ?? '—' }}</td>
                                        @if($pre['contracts']->count() > 1)<td>{{ $txn->contract?->name }}</td>@endif
                                        <td class="text-end">{{ $txn->formatValue((bool) $txn->contract?->prepay_as_amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <div class="card-body">
                    @foreach($pre['contracts'] as $pc)
                        <a href="{{ route('contracts.show', $pc) }}#prepay-history">View prepay ledger{{ $pre['contracts']->count() > 1 ? ' ('.$pc->name.')' : '' }}</a>@if(! $loop->last)<br>@endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

{{-- BenjiPays: hidden when not configured or the client has no QuickBooks customer id --}}
@if($bp !== null)
<div class="card shadow-sm card-static mb-3" data-section="benjipays">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-credit-card me-2"></i>BenjiPays</span>
        @if($bp['ok'])
            <span class="small">Read-only, cached {{ \Carbon\Carbon::parse($bp['read_at'])->setTimezone($tz)->format('H:i') }}</span>
        @else
            <span class="badge bg-secondary">Unavailable</span>
        @endif
    </div>
    @if(! $bp['ok'])
        <div class="card-body text-muted">BenjiPays could not be read just now, so payment methods and payments are not shown. The rest of this page is unaffected. Try again later.</div>
    @else
        <div class="row g-0">
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <thead class="thead-brand"><tr><th>Payment method</th><th>Autopay</th></tr></thead>
                    <tbody>
                        @forelse($bp['methods'] as $m)
                            <tr>
                                <td>
                                    @if($m['method_type'] === 'bank')
                                        Bank account
                                    @else
                                        {{ $m['brand'] ?? 'Card' }}
                                    @endif
                                    @if($m['last4']) ending {{ $m['last4'] }}@endif
                                </td>
                                <td>
                                    @if($m['autopay_enabled'])
                                        <span class="badge bg-success">On</span>
                                    @else
                                        <span class="badge bg-secondary">Off</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-muted">No saved payment methods.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <thead class="thead-brand"><tr><th>Payment</th><th>Invoice</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        @forelse($bp['payments'] as $p)
                            <tr>
                                <td>{{ $p['date'] ? \Carbon\Carbon::parse($p['date'])->setTimezone($tz)->format('M j, Y') : '—' }}</td>
                                <td>{{ $p['invoice_number'] ?? '—' }}</td>
                                <td class="text-end">{{ $p['amount'] !== null ? $usd($p['amount']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted">No payments recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endif
<span data-billing-tab-end hidden></span>
</div>

@push('styles')
<style>
    /* Signal Gold marks Outstanding only (DESIGN.md Signal Gold Rule). */
    .billing-kpi-signal { border-left: 4px solid var(--accent, #fed136); }
</style>
@endpush
