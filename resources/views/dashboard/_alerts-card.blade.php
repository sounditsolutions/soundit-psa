{{-- Active Alerts dashboard card --}}
@php
    $alertSeverityCounts = \App\Models\Alert::open()
        ->selectRaw('severity, count(*) as count')
        ->groupBy('severity')
        ->pluck('count', 'severity');

    $recentAlerts = \App\Models\Alert::with(['asset', 'client', 'ticket'])
        ->open()
        ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'error' THEN 2 WHEN 'warning' THEN 3 ELSE 4 END")
        ->orderByDesc('fired_at')
        ->limit(10)
        ->get();

    $totalOpen = $alertSeverityCounts->sum();
@endphp

@if($totalOpen > 0)
<div class="card shadow-sm card-static mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-bell me-2"></i><strong>Active Alerts</strong>
            <span class="badge bg-danger ms-1">{{ $totalOpen }}</span>
        </span>
        <a href="{{ route('alerts.index') }}" class="btn btn-outline-primary btn-sm">View All</a>
    </div>
    {{-- Severity breakdown --}}
    <div class="d-flex flex-wrap gap-2 px-3 pt-3 pb-2">
        @foreach(['critical' => 'bg-danger', 'error' => 'bg-danger', 'warning' => 'bg-warning text-dark', 'info' => 'bg-info text-dark'] as $sev => $cls)
            @if(($alertSeverityCounts[$sev] ?? 0) > 0)
                <a href="{{ route('alerts.index', ['severity' => $sev]) }}" class="badge text-decoration-none {{ $cls }}" style="font-size: 0.8rem; padding: 0.3em 0.65em;">
                    {{ ucfirst($sev) }} {{ $alertSeverityCounts[$sev] }}
                </a>
            @endif
        @endforeach
    </div>
    @include('alerts._list', ['alerts' => $recentAlerts, 'showBulkActions' => false, 'showPagination' => false])
</div>
@endif
