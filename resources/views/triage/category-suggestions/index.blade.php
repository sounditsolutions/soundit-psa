@extends('layouts.app')

@section('title', 'Category Suggestions')

@section('content')
<div class="row mb-3">
    <div class="col d-flex align-items-center justify-content-between">
        <h4 class="section-title mb-0">
            <i class="bi bi-robot me-1"></i>Category Suggestions
            <span class="text-muted fw-normal" style="font-size: 0.85rem;">({{ $pending->count() }} pending)</span>
        </h4>
    </div>
</div>

<p class="text-muted" style="max-width: 60rem;">
    Categories suggested by AI triage are held here for review. Approving a suggestion applies the
    category and subcategory to its ticket; rejecting leaves the ticket unchanged.
</p>

<div class="card mb-4">
    <div class="card-body p-0">
        @if($pending->isEmpty())
            <div class="text-center text-muted py-5">
                <i class="bi bi-check2-circle d-block mb-2" style="font-size: 2rem;"></i>
                No category suggestions are awaiting review.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Client</th>
                            <th>Current</th>
                            <th>Suggested</th>
                            <th>Suggested</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pending as $suggestion)
                            <tr>
                                <td>
                                    @if($suggestion->ticket)
                                        <a href="{{ route('tickets.show', $suggestion->ticket) }}" class="text-decoration-none">
                                            #{{ $suggestion->ticket_id }}
                                        </a>
                                        <div class="text-muted small text-truncate" style="max-width: 22rem;">
                                            {{ $suggestion->ticket->subject }}
                                        </div>
                                    @else
                                        <span class="text-muted">#{{ $suggestion->ticket_id }}</span>
                                    @endif
                                </td>
                                <td>{{ $suggestion->ticket?->client?->name ?? '—' }}</td>
                                <td>
                                    @if($suggestion->ticket?->category)
                                        <span class="text-muted">
                                            {{ $suggestion->ticket->category }}@if($suggestion->ticket->subcategory) / {{ $suggestion->ticket->subcategory }}@endif
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="fw-medium">{{ $suggestion->category }}</span>@if($suggestion->subcategory) <span class="text-muted">/ {{ $suggestion->subcategory }}</span>@endif
                                </td>
                                <td class="text-nowrap text-muted small">
                                    {{ $suggestion->created_at?->toAppTz()->format('M j, Y g:i A') }}
                                </td>
                                <td class="text-end text-nowrap">
                                    <form action="{{ route('triage.category-suggestions.approve', $suggestion) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="bi bi-check-lg"></i> Approve
                                        </button>
                                    </form>
                                    <form action="{{ route('triage.category-suggestions.reject', $suggestion) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-x-lg"></i> Reject
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if($recent->isNotEmpty())
    <h6 class="text-muted mb-2">Recently reviewed</h6>
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Suggested</th>
                            <th>Status</th>
                            <th>Reviewer</th>
                            <th>Reviewed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recent as $suggestion)
                            <tr>
                                <td>
                                    @if($suggestion->ticket)
                                        <a href="{{ route('tickets.show', $suggestion->ticket) }}" class="text-decoration-none">#{{ $suggestion->ticket_id }}</a>
                                    @else
                                        <span class="text-muted">#{{ $suggestion->ticket_id }}</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $suggestion->category }}@if($suggestion->subcategory) <span class="text-muted">/ {{ $suggestion->subcategory }}</span>@endif
                                </td>
                                <td><span class="badge {{ $suggestion->status->badgeClass() }}">{{ $suggestion->status->label() }}</span></td>
                                <td class="text-muted small">{{ $suggestion->reviewer?->name ?? '—' }}</td>
                                <td class="text-nowrap text-muted small">{{ $suggestion->reviewed_at?->toAppTz()->format('M j, Y g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
@endsection
