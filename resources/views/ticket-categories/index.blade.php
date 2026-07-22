@extends('layouts.app')

@section('title', 'Ticket Categories')

@section('content')
<div class="row mb-3">
    <div class="col d-flex justify-content-between align-items-center">
        <h4 class="section-title mb-0">Ticket Categories</h4>
        <a href="{{ route('ticket-categories.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Category
        </a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="badge bg-light text-dark border">{{ $stats['active'] }} active {{ Str::plural('node', $stats['active']) }}</span>
    <a href="{{ route('ticket-categories.index', ['sop_status' => 'none']) }}" class="text-decoration-none">
        <span class="badge {{ $stats['gaps'] > 0 ? 'bg-warning text-dark' : 'bg-light text-dark border' }}"
              title="Active categories with no SOP written yet">
            {{ $stats['gaps'] }} coverage {{ Str::plural('gap', $stats['gaps']) }}
        </span>
    </a>
    <a href="{{ route('ticket-categories.index', ['stale' => 90]) }}" class="text-decoration-none">
        <span class="badge bg-light text-dark border" title="Active categories not updated in 90+ days">
            {{ $stats['stale'] }} stale (90d)
        </span>
    </a>
</div>

<form method="GET" action="{{ route('ticket-categories.index') }}" class="mb-3">
    <div class="row g-2 align-items-center">
        <div class="col-auto">
            <input type="search" name="q" class="form-control form-control-sm" placeholder="Search name…"
                   value="{{ $q }}" style="min-width: 200px;">
        </div>
        <div class="col-auto">
            <select name="sop_status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Any SOP status</option>
                <option value="none" {{ $sopStatus === 'none' ? 'selected' : '' }}>No SOP (coverage gap)</option>
                <option value="draft" {{ $sopStatus === 'draft' ? 'selected' : '' }}>Draft</option>
                <option value="reviewed" {{ $sopStatus === 'reviewed' ? 'selected' : '' }}>Reviewed</option>
            </select>
        </div>
        <div class="col-auto">
            <select name="stale" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Any age</option>
                <option value="30" {{ $stale === 30 ? 'selected' : '' }}>Not updated in 30d</option>
                <option value="90" {{ $stale === 90 ? 'selected' : '' }}>Not updated in 90d</option>
                <option value="180" {{ $stale === 180 ? 'selected' : '' }}>Not updated in 180d</option>
            </select>
        </div>
        <div class="col-auto">
            <select name="active" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="1" {{ $active === '1' ? 'selected' : '' }}>Active only</option>
                <option value="all" {{ $active === 'all' ? 'selected' : '' }}>All</option>
                <option value="0" {{ $active === '0' ? 'selected' : '' }}>Retired only</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
            @if($filtering)
                <a href="{{ route('ticket-categories.index') }}" class="btn btn-link btn-sm">Clear</a>
            @endif
        </div>
    </div>
</form>

@if($filtering)
    @if($nodes->isEmpty())
        <div class="alert alert-info">No categories match these filters.</div>
    @else
        <div class="card shadow-sm card-static">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-brand">
                        <tr>
                            <th>Category</th>
                            <th style="width: 110px;">SOP</th>
                            <th class="d-none d-md-table-cell" style="width: 100px;">Type</th>
                            <th class="text-end d-none d-md-table-cell" style="width: 90px;">Tickets</th>
                            <th class="d-none d-lg-table-cell" style="width: 160px;">Updated</th>
                            <th class="text-center" style="width: 90px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($nodes as $n)
                            <tr class="{{ $n->is_active ? '' : 'opacity-50' }}">
                                <td>
                                    <a href="{{ route('ticket-categories.show', $n) }}" class="text-decoration-none fw-semibold">
                                        {{ $n->pathString() }}
                                    </a>
                                </td>
                                <td><span class="badge {{ $n->sop_status->badgeClass() }}">{{ $n->sop_status->label() }}</span></td>
                                <td class="d-none d-md-table-cell">
                                    @if($n->record_type_hint)
                                        <span class="badge {{ $n->record_type_hint->badgeClass() }}">{{ $n->record_type_hint->label() }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-end d-none d-md-table-cell">{{ $n->tickets_count > 0 ? number_format($n->tickets_count) : '-' }}</td>
                                <td class="d-none d-lg-table-cell"><small class="text-muted">{{ $n->updated_at->diffForHumans() }}</small></td>
                                <td class="text-center">
                                    @if($n->is_active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Retired</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@else
    @if($roots->isEmpty())
        <div class="alert alert-info">
            No ticket categories yet. <a href="{{ route('ticket-categories.create') }}">Create the first one</a>
            to start building the taxonomy (Category / Subcategory / Item).
        </div>
    @else
        <div class="card shadow-sm card-static">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-brand">
                        <tr>
                            <th>Category</th>
                            <th style="width: 110px;">SOP</th>
                            <th class="d-none d-md-table-cell" style="width: 100px;">Type</th>
                            <th class="text-end d-none d-md-table-cell" style="width: 90px;">Tickets</th>
                            <th class="d-none d-lg-table-cell" style="width: 160px;">Updated</th>
                            <th class="text-end" style="width: 110px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($roots as $root)
                            @include('ticket-categories._tree_row', ['node' => $root, 'depth' => 1])
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endif
@endsection
