@extends('layouts.app')

@section('title', 'Phone Directory')

@php
    use App\Enums\PhoneDirectoryListType;
    $isAllowedTab = $activeType === PhoneDirectoryListType::Allowed;
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="section-title mb-1">Phone Directory</h1>
        <p class="text-muted small mb-0">
            Numbers here are looked up by the Plivo PHLO during inbound IVR.
            <strong>Blocked</strong> entries return <code>blocked: true</code> and are hung up on.
            <strong>Allowed</strong> entries return <code>allowed: true</code> (with their label) so non-client vendors and outside techs ring through with their name announced.
        </p>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('info'))
    <div class="alert alert-info alert-dismissible fade show">{{ session('info') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link {{ ! $isAllowedTab ? 'active' : '' }}"
           href="{{ route('phone-directory.index', ['tab' => 'blocked']) }}">
            <i class="bi bi-shield-x me-1"></i>Blocked
            <span class="badge bg-secondary ms-1">{{ $counts['blocked'] }}</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $isAllowedTab ? 'active' : '' }}"
           href="{{ route('phone-directory.index', ['tab' => 'allowed']) }}">
            <i class="bi bi-shield-check me-1"></i>Allowed
            <span class="badge bg-secondary ms-1">{{ $counts['allowed'] }}</span>
        </a>
    </li>
</ul>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Add a Number</div>
    <div class="card-body">
        <form method="POST" action="{{ route('phone-directory.store') }}" class="row g-2 align-items-end">
            @csrf
            <input type="hidden" name="list_type" value="{{ $activeType->value }}">
            <div class="col-md-3">
                <label for="phone_number" class="form-label">Phone Number</label>
                <input type="text" name="phone_number" id="phone_number"
                       class="form-control @error('phone_number') is-invalid @enderror"
                       placeholder="(555) 123-4567 or +15551234567" required>
                @error('phone_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @if($isAllowedTab)
                <div class="col-md-4">
                    <label for="label" class="form-label">Label <small class="text-muted">(spoken in greeting)</small></label>
                    <input type="text" name="label" id="label" maxlength="255"
                           class="form-control @error('label') is-invalid @enderror"
                           placeholder="e.g. Vendor escalation contact">
                    @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="reason" class="form-label">Notes <small class="text-muted">(optional)</small></label>
                    <input type="text" name="reason" id="reason" maxlength="500"
                           class="form-control"
                           placeholder="e.g. Azure escalation contact">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100"><i class="bi bi-shield-check me-1"></i>Allow</button>
                </div>
            @else
                <div class="col-md-7">
                    <label for="reason" class="form-label">Reason <small class="text-muted">(optional)</small></label>
                    <input type="text" name="reason" id="reason" maxlength="500"
                           class="form-control"
                           placeholder="e.g. Persistent solar-panel solicitor">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-danger w-100"><i class="bi bi-shield-x me-1"></i>Block</button>
                </div>
            @endif
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi {{ $isAllowedTab ? 'bi-shield-check' : 'bi-shield-x' }} me-2"></i>{{ $activeType->label() }} List
        </span>
        <form method="GET" action="{{ route('phone-directory.index') }}" class="d-flex gap-2">
            <input type="hidden" name="tab" value="{{ $activeType->value }}">
            <input type="text" name="q" value="{{ $search }}" class="form-control form-control-sm" style="width:200px;" placeholder="Search…">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
            @if($search)<a href="{{ route('phone-directory.index', ['tab' => $activeType->value]) }}" class="btn btn-sm btn-outline-secondary">Clear</a>@endif
        </form>
    </div>
    <div class="card-body p-0">
        <form method="POST" action="{{ route('phone-directory.bulk-destroy') }}"
              onsubmit="return confirm('Remove the selected entries?')">
            @csrf @method('DELETE')
            <input type="hidden" name="tab" value="{{ $activeType->value }}">

            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(c => c.checked = this.checked)"></th>
                        <th>Number</th>
                        @if($isAllowedTab)
                            <th>Label</th>
                            <th>Notes</th>
                        @else
                            <th>Reason</th>
                        @endif
                        <th>Added By</th>
                        <th>Added</th>
                        <th style="width:160px;" class="text-end">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Remove selected</button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                @forelse($entries as $entry)
                    <tr>
                        <td><input type="checkbox" class="row-check" name="ids[]" value="{{ $entry->id }}"></td>
                        <td class="font-monospace">{{ \App\Support\PhoneNumber::format($entry->phone_number) }}</td>
                        @if($isAllowedTab)
                            <td>{{ $entry->label ?: '—' }}</td>
                            <td class="text-muted small">{{ $entry->reason ?: '—' }}</td>
                        @else
                            <td class="text-muted small">{{ $entry->reason ?: '—' }}</td>
                        @endif
                        <td class="small">{{ $entry->addedBy?->name ?? '—' }}</td>
                        <td class="small text-muted">{{ $entry->created_at?->toAppTz()->diffForHumans() }}</td>
                        <td></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $isAllowedTab ? 7 : 6 }}" class="text-center text-muted py-4">No entries on the {{ strtolower($activeType->label()) }} list yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </form>
    </div>
    @if($entries->hasPages())
        <div class="card-footer">{{ $entries->links() }}</div>
    @endif
</div>
@endsection
