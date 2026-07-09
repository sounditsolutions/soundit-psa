@extends('layouts.app')

@section('title', 'AI Tooling Gaps')

@php
    use App\Enums\ToolingGapClassification;
    use App\Enums\ToolingGapSource;
    use App\Enums\ToolingGapStatus;

    $classBadge = fn (ToolingGapClassification $c) => match ($c) {
        ToolingGapClassification::ToolMissing => 'bg-warning text-dark',
        ToolingGapClassification::ToolUnused => 'bg-info text-dark',
        ToolingGapClassification::ToolBroken => 'bg-danger',
    };
    $sourceBadge = fn (ToolingGapSource $s) => match ($s) {
        ToolingGapSource::Agent => 'bg-primary',
        ToolingGapSource::Correction => 'bg-secondary',
    };
    $statusBadge = fn (ToolingGapStatus $s) => match ($s) {
        ToolingGapStatus::Open => 'bg-warning text-dark',
        ToolingGapStatus::Triaged => 'bg-info text-dark',
        ToolingGapStatus::Resolved => 'bg-success',
        ToolingGapStatus::WontFix => 'bg-secondary',
    };
@endphp

@section('content')
<div class="row justify-content-center">
    <div class="col-xxl-11">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">AI Tooling Gaps</h2>
            <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Integrations
            </a>
        </div>

        <p class="text-muted mb-3">
            Feedback the AI agents leave for the team when they hit a wall — a tool they lacked, data they
            couldn't retrieve, or an existing tool that misbehaved (via <code>request_tool</code>), plus gaps
            surfaced by operator corrections. Triage them here: the abstract capability or symptom is safe to
            act on; the private evidence links back to the originating ticket.
        </p>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        {{-- Status filter chips --}}
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="{{ route('settings.tooling-gaps.index', ['status' => 'all']) }}"
               class="btn btn-sm {{ $showAll ? 'btn-primary' : 'btn-outline-secondary' }}">
                All <span class="badge bg-light text-dark ms-1">{{ $totalCount }}</span>
            </a>
            @foreach($statuses as $status)
                <a href="{{ route('settings.tooling-gaps.index', ['status' => $status->value]) }}"
                   class="btn btn-sm {{ (! $showAll && $activeStatus === $status) ? 'btn-primary' : 'btn-outline-secondary' }}">
                    {{ $status->label() }}
                    <span class="badge bg-light text-dark ms-1">{{ $counts[$status->value] ?? 0 }}</span>
                </a>
            @endforeach
        </div>

        <div class="card card-static shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 90px;">Ticket</th>
                            <th>Capability gap / symptom</th>
                            <th>Classification</th>
                            <th class="d-none d-lg-table-cell">Source</th>
                            <th class="d-none d-md-table-cell" style="width: 120px;">Reported</th>
                            <th class="d-none d-xl-table-cell">Evidence</th>
                            <th style="min-width: 210px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($gaps as $gap)
                            <tr>
                                <td>
                                    @if($gap->ticket)
                                        <a href="{{ route('tickets.show', $gap->ticket_id) }}">
                                            {{ $gap->ticket->display_id ?? '#'.$gap->ticket_id }}
                                        </a>
                                    @elseif($gap->ticket_id)
                                        <span class="text-muted">#{{ $gap->ticket_id }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($gap->tool_name)
                                        <span class="badge bg-light text-dark border me-1"><code>{{ $gap->tool_name }}</code></span>
                                    @endif
                                    {{ $gap->capability_gap }}
                                    @if($gap->agent_note)
                                        <div class="small text-muted mt-1">
                                            <i class="bi bi-chat-left-text me-1"></i>{{ $gap->agent_note }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $classBadge($gap->classification) }}">{{ $gap->classification->label() }}</span>
                                </td>
                                <td class="d-none d-lg-table-cell">
                                    <span class="badge {{ $sourceBadge($gap->source) }}">{{ $gap->source->label() }}</span>
                                </td>
                                <td class="d-none d-md-table-cell small text-muted">
                                    <span title="{{ $gap->created_at->toAppTz()->format('M j, Y g:i A') }}">
                                        {{ $gap->created_at->diffForHumans() }}
                                    </span>
                                </td>
                                <td class="d-none d-xl-table-cell small text-muted" style="max-width: 260px;">
                                    @if($gap->evidence)
                                        <span title="{{ $gap->evidence }}">{{ Str::limit($gap->evidence, 90) }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('settings.tooling-gaps.update', $gap) }}"
                                          class="d-flex gap-1">
                                        @csrf
                                        @method('PATCH')
                                        <select name="status" class="form-select form-select-sm" style="max-width: 140px;">
                                            @foreach($statuses as $status)
                                                <option value="{{ $status->value }}" {{ $gap->status === $status ? 'selected' : '' }}>
                                                    {{ $status->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="bi bi-check2-circle me-1"></i>No tooling gaps{{ $showAll ? '' : ' with this status' }}.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($gaps->hasPages())
            <div class="mt-3">
                {{ $gaps->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
