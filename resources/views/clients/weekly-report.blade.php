@extends('layouts.app')

@section('title', 'Weekly Report — ' . $client->name)

@section('content')
@php
    $start = $report['week_start'];
    $end = $report['week_end'];
    $data = $report['data'];
    $prevWeek = $start->copy()->subWeek()->toDateString();
    $nextWeek = $start->copy()->addWeek()->toDateString();
    $atCurrentWeek = $start->greaterThanOrEqualTo(now()->startOfWeek());
@endphp

<div class="mb-3">
    <a href="{{ route('clients.show', $client) }}" class="text-decoration-none">
        <i class="bi bi-arrow-left me-1"></i>Back to {{ $client->name }}
    </a>
</div>

@if (session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
@if (session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-1"></i>{{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">
            <i class="bi bi-file-earmark-bar-graph me-2"></i>Weekly Service Report
        </h1>
        <div class="text-muted">
            {{ $client->name }} · {{ $start->format('M j') }} – {{ $end->format('M j, Y') }}
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <div class="btn-group">
            <a href="{{ route('clients.weekly-report', ['client' => $client, 'week' => $prevWeek]) }}"
               class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-chevron-left"></i> Prev
            </a>
            <a href="{{ route('clients.weekly-report', ['client' => $client, 'week' => $nextWeek]) }}"
               class="btn btn-outline-secondary btn-sm {{ $atCurrentWeek ? 'disabled' : '' }}"
               @if ($atCurrentWeek) aria-disabled="true" tabindex="-1" @endif>
                Next <i class="bi bi-chevron-right"></i>
            </a>
        </div>
        <form method="POST" action="{{ route('clients.weekly-report.email', $client) }}"
              onsubmit="this.querySelector('button').disabled = true;">
            @csrf
            <input type="hidden" name="week" value="{{ $start->toDateString() }}">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-envelope me-1"></i>Email to primary contact
            </button>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body report-body">
                {!! \App\Helpers\MarkdownRenderer::render($report['markdown']) !!}
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-speedometer2"></i><span>At a glance</span>
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-7 fw-normal text-muted">Resolved this week</dt>
                    <dd class="col-5 text-end fw-semibold">{{ $data['closed_count'] }}</dd>
                    <dt class="col-7 fw-normal text-muted">Opened this week</dt>
                    <dd class="col-5 text-end fw-semibold">{{ $data['opened_count'] }}</dd>
                    <dt class="col-7 fw-normal text-muted">Currently open</dt>
                    <dd class="col-5 text-end fw-semibold">{{ $data['currently_open'] }}</dd>
                    <dt class="col-7 fw-normal text-muted">Avg first response</dt>
                    <dd class="col-5 text-end fw-semibold">{{ \App\Services\ClientReportService::humanizeMinutes($data['avg_response_mins']) }}</dd>
                    <dt class="col-7 fw-normal text-muted">Avg resolution</dt>
                    <dd class="col-5 text-end fw-semibold">{{ \App\Services\ClientReportService::humanizeMinutes($data['avg_resolution_mins']) }}</dd>
                    @if ($data['sla_tracked'] > 0)
                        <dt class="col-7 fw-normal text-muted">Resolution SLA met</dt>
                        <dd class="col-5 text-end fw-semibold">{{ $data['sla_met'] }}/{{ $data['sla_tracked'] }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-markdown me-1"></i>Markdown source</span>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copyReportMarkdown(this)">
                    <i class="bi bi-clipboard me-1"></i>Copy
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">Copy the raw Markdown to paste into a QBR deck or document.</p>
                <textarea id="report-markdown" class="form-control font-monospace small" rows="12" readonly>{{ $report['markdown'] }}</textarea>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function copyReportMarkdown(btn) {
    const ta = document.getElementById('report-markdown');
    if (!ta) return;
    const done = () => {
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied';
        setTimeout(() => { btn.innerHTML = original; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ta.value).then(done).catch(() => { ta.select(); document.execCommand('copy'); done(); });
    } else {
        ta.select();
        document.execCommand('copy');
        done();
    }
}
</script>
@endpush

@push('styles')
<style>
.report-body > *:first-child { margin-top: 0; }
.report-body h1 { font-size: 1.5rem; }
.report-body h2 { font-size: 1.2rem; margin-top: 1.5rem; }
.report-body h3 { font-size: 1.05rem; margin-top: 1.15rem; }
.report-body table { width: 100%; margin-bottom: 1rem; border-collapse: collapse; }
.report-body th, .report-body td { border: 1px solid var(--bs-border-color, #dee2e6); padding: .4rem .6rem; font-size: .875rem; vertical-align: top; }
.report-body thead th { background: var(--bs-tertiary-bg, #f8f9fa); text-align: left; }
.report-body hr { margin: 1.5rem 0; opacity: .15; }
</style>
@endpush
