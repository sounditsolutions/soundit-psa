@extends('portal.layouts.app')

@section('title', 'Dashboard - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<h4 class="mb-4">Welcome, {{ $person->first_name }}</h4>

{{-- Stats cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card portal-stat-card p-3">
            <div class="stat-value">{{ $openTickets }}</div>
            <div class="stat-label">Open Tickets</div>
            <a href="{{ route('portal.tickets.index', ['tab' => 'open']) }}" class="stretched-link"></a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card portal-stat-card p-3">
            <div class="stat-value">${{ number_format($unpaidTotal, 2) }}</div>
            <div class="stat-label">Outstanding Balance</div>
            <a href="{{ route('portal.invoices.index') }}" class="stretched-link"></a>
        </div>
    </div>
</div>

{{-- Prepaid Time Balance --}}
@if($prepayContracts->isNotEmpty())
    <div class="card mb-4 border-start border-4 border-warning">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2 text-warning"></i>Support Hours</h5>
                @if($hasPurchasableContracts)
                    <a href="{{ route('portal.prepaid.select') }}" class="btn btn-accent">
                        <i class="bi bi-cart-plus me-1"></i>Top Up Hours
                    </a>
                @elseif(App\Support\PortalConfig::orderUrlForClient($clientId))
                    <a href="{{ App\Support\PortalConfig::orderUrlForClient($clientId) }}" target="_blank" class="btn btn-accent">
                        <i class="bi bi-cart-plus me-1"></i>Top Up Hours <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.7rem;"></i>
                    </a>
                @endif
            </div>
            @if($prepayContracts->count() === 1)
                @php $c = $prepayContracts->first(); @endphp
                <div class="d-flex align-items-baseline">
                    <span style="font-size: 2.5rem; font-weight: 700; line-height: 1;">{{ number_format($c->prepay_balance, 1) }}</span>
                    <span class="text-muted ms-1" style="font-size: 1.1rem;">hours remaining</span>
                </div>
                <div class="text-muted small mt-1">{{ $c->name }}</div>
            @else
                <div class="d-flex align-items-baseline mb-3">
                    <span style="font-size: 2.5rem; font-weight: 700; line-height: 1;">{{ number_format($totalPrepayHours, 1) }}</span>
                    <span class="text-muted ms-1" style="font-size: 1.1rem;">total hours remaining</span>
                </div>
                <div class="row g-2">
                    @foreach($prepayContracts as $c)
                        <div class="col-sm-6">
                            <div class="bg-light rounded p-2 px-3">
                                <div class="fw-semibold">{{ number_format($c->prepay_balance, 1) }}h</div>
                                <div class="text-muted small">{{ $c->name }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endif

@if(! empty($installToken))
    <div class="card mb-4">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <h5 class="mb-1"><i class="bi bi-laptop me-2"></i>Set up a new computer</h5>
                <p class="text-muted small mb-0">Install our management agent on a new Windows, Mac, or Linux device.</p>
            </div>
            <a href="{{ url('/setup/' . $installToken) }}" target="_blank" class="btn btn-primary">
                <i class="bi bi-download me-1"></i>Get the installer
            </a>
        </div>
    </div>
@endif

<div class="row g-4">
    {{-- Recent Tickets --}}
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Recent Tickets</h6>
                <a href="{{ route('portal.tickets.index') }}" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                @if($recentTickets->isEmpty())
                    <p class="text-muted p-3 mb-0">No tickets found.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                    <th>Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentTickets as $ticket)
                                    <tr class="cursor-pointer" onclick="window.location='{{ route('portal.tickets.show', $ticket) }}'">
                                        <td class="text-muted">{{ $ticket->id }}</td>
                                        <td>{{ Str::limit($ticket->subject, 60) }}</td>
                                        <td><span class="badge {{ $ticket->status->badgeClass() }}">{{ $ticket->status->label() }}</span></td>
                                        <td class="text-muted small">{{ $ticket->updated_at->toAppTz()->format('M j') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Unpaid Invoices --}}
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Unpaid Invoices</h6>
                <a href="{{ route('portal.invoices.index') }}" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                @if($unpaidInvoices->isEmpty())
                    <p class="text-muted p-3 mb-0">All caught up!</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Invoice</th>
                                    <th>Due</th>
                                    <th class="text-end">Total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($unpaidInvoices as $invoice)
                                    <tr>
                                        <td><a href="{{ route('portal.invoices.show', $invoice) }}">{{ $invoice->invoice_number ?: '#' . $invoice->id }}</a></td>
                                        <td class="text-muted small">{{ $invoice->due_date?->format('M j') ?? '—' }}</td>
                                        <td class="text-end">${{ number_format($invoice->total, 2) }}</td>
                                        <td class="text-end">
                                            @if($invoice->stripe_invoice_url)
                                                <a href="{{ $invoice->stripe_invoice_url }}" target="_blank" class="btn btn-sm btn-accent">Pay Online</a>
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
    </div>
</div>
@endsection
