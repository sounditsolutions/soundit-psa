@extends('layouts.app')

@section('title', 'Call Log')

@section('content')
<div class="row mb-4">
    <div class="col">
        <h4 class="section-title">Call Log</h4>
    </div>
    @if($missedCount > 0)
    <div class="col-auto">
        <a href="{{ route('calls.index', ['status' => 'needs-follow-up']) }}" class="btn btn-danger btn-sm">
            <i class="bi bi-telephone-x me-1"></i>{{ $missedCount }} Needs Follow-Up
        </a>
    </div>
    @endif
</div>

{{-- Filters --}}
<div class="card card-static shadow-sm mb-4">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('calls.index') }}" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="{{ $filters['date_to'] ?? '' }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="needs-follow-up" {{ ($filters['status'] ?? '') === 'needs-follow-up' ? 'selected' : '' }}>
                        Needs follow-up
                    </option>
                    @foreach(\App\Enums\CallStatus::cases() as $status)
                        <option value="{{ $status->value }}" {{ ($filters['status'] ?? '') === $status->value ? 'selected' : '' }}>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                    <option value="unknown-caller" {{ ($filters['status'] ?? '') === 'unknown-caller' ? 'selected' : '' }}>
                        Unknown caller — needs follow-up
                    </option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Phone number or client name"
                       value="{{ $filters['search'] ?? '' }}">
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <a href="{{ route('calls.index') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
            </div>
        </form>
    </div>
</div>

{{-- Call list --}}
<div class="card card-static shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="thead-brand">
                <tr>
                    <th>Time</th>
                    <th style="width: 30px"></th>
                    <th>From</th>
                    <th>To</th>
                    <th>Client</th>
                    <th>Duration</th>
                    <th>Status</th>
                    {{-- Answered By shown inline in From/To --}}
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($calls as $call)
                <tr class="{{ $call->needsFollowUp() ? 'table-warning' : '' }}">
                    <td class="text-nowrap">
                        <small>{{ $call->started_at?->toAppTz()->format('d M H:i') ?? '—' }}</small>
                    </td>
                    <td class="text-center">
                        @if($call->direction === \App\Enums\CallDirection::Inbound)
                            <i class="bi bi-telephone-inbound text-success" title="Inbound"></i>
                        @else
                            <i class="bi bi-telephone-outbound text-primary" title="Outbound"></i>
                        @endif
                    </td>
                    <td>
                        @if($call->direction === \App\Enums\CallDirection::Inbound)
                            <a href="{{ route('calls.show', $call) }}">
                                {{ \App\Support\PhoneNumber::format($call->from_number) }}
                            </a>
                            @if($call->person)
                                <small class="d-block text-muted"><x-person-badge :person="$call->person" :size="16" :link="false" /></small>
                            @endif
                            @if($call->from_number)
                                <a href="#" data-phone="{{ $call->from_number }}" class="ms-1 text-decoration-none" title="Call back">
                                    <i class="bi bi-telephone-outbound text-muted small"></i>
                                </a>
                            @endif
                        @else
                            @if($call->answeredBy)
                                <x-user-badge :user="$call->answeredBy" :size="16" />
                            @else
                                <span class="text-muted">Staff</span>
                            @endif
                        @endif
                    </td>
                    <td>
                        @if($call->direction === \App\Enums\CallDirection::Outbound)
                            <a href="{{ route('calls.show', $call) }}">
                                {{ \App\Support\PhoneNumber::format($call->from_number) }}
                            </a>
                            @if($call->person)
                                <small class="d-block text-muted"><x-person-badge :person="$call->person" :size="16" :link="false" /></small>
                            @endif
                            @if($call->from_number)
                                <a href="#" data-phone="{{ $call->from_number }}" class="ms-1 text-decoration-none" title="Call">
                                    <i class="bi bi-telephone-outbound text-muted small"></i>
                                </a>
                            @endif
                        @else
                            @if($call->answeredBy)
                                <x-user-badge :user="$call->answeredBy" :size="16" />
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        @endif
                    </td>
                    <td>
                        @if($call->client)
                            <x-client-badge :client="$call->client" />
                        @elseif($call->halo_client_name)
                            <span class="text-truncate d-inline-block" style="max-width: 200px">{{ $call->halo_client_name }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($call->duration !== null)
                            {{ gmdate($call->duration >= 3600 ? 'H:i:s' : 'i:s', $call->duration) }}
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $call->status->badgeClass() }}">{{ $call->status->label() }}</span>
                    </td>
                    {{-- Answered By now inline in From/To columns --}}
                    <td class="text-end">
                        @if($call->ticket)
                            <x-ticket-badge :ticket="$call->ticket" />
                        @endif
                        @if($call->recording_url)
                            <i class="bi bi-mic text-muted" title="Recording available"></i>
                        @endif
                        @if($call->isTranscribed())
                            <i class="bi bi-file-earmark-text text-success" title="Transcribed"></i>
                        @elseif($call->isTranscribing())
                            <span class="spinner-border spinner-border-sm text-muted" style="width: 14px; height: 14px;" title="Transcribing..."></span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        <i class="bi bi-telephone-x fs-3 d-block mb-2"></i>
                        @if(array_filter($filters))
                            No calls found for the selected filters.
                        @else
                            No calls recorded yet.
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
