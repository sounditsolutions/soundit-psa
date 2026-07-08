@extends('layouts.app')

@section('title', 'Staff')

@section('content')
<div class="row mb-3">
    <div class="col d-flex justify-content-between align-items-center">
        <h4 class="section-title mb-0">Staff Members</h4>
        <a href="{{ route('settings.staff.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Add Staff
        </a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($users->isEmpty())
    <div class="alert alert-info">No staff members found.</div>
@else
    <div class="card shadow-sm card-static">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th class="text-center d-none d-md-table-cell">SSO</th>
                        <th class="text-center" style="width: 80px;">Status</th>
                        <th class="text-end" style="width: 160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                        <tr>
                            <td>
                                <a href="{{ route('settings.staff.edit', $user) }}"
                                   class="text-decoration-none fw-semibold d-flex align-items-center {{ !$user->is_active ? 'text-muted text-decoration-line-through' : '' }}">
                                    <x-avatar :user="$user" :size="28" class="me-2" />{{ $user->name }}
                                    @if($user->is_contractor)
                                        <span class="badge bg-info ms-2">Contractor</span>
                                    @endif
                                </a>
                            </td>
                            <td class="{{ !$user->is_active ? 'text-muted' : '' }}">{{ $user->email }}</td>
                            <td class="text-center d-none d-md-table-cell">
                                @if($user->microsoft_id)
                                    <i class="bi bi-check-circle text-success" title="Linked to Entra ID"></i>
                                @else
                                    <span class="text-muted" title="Not yet linked">&mdash;</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($user->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($user->is_contractor)
                                    <a href="{{ route('contractors.time-pool', $user) }}" class="btn btn-outline-info btn-sm me-1" title="Time Pool">
                                        <i class="bi bi-clock-history"></i>
                                    </a>
                                @endif
                                <a href="{{ route('settings.staff.edit', $user) }}" class="btn btn-outline-primary btn-sm me-1">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                @if($user->id !== auth()->id())
                                    <form method="POST" action="{{ route('settings.staff.toggle-active', $user) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm {{ $user->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                                title="{{ $user->is_active ? 'Deactivate' : 'Activate' }}">
                                            <i class="bi {{ $user->is_active ? 'bi-person-dash' : 'bi-person-check' }}"></i>
                                        </button>
                                    </form>
                                @else
                                    <button class="btn btn-outline-secondary btn-sm" disabled title="Cannot deactivate yourself">
                                        <i class="bi bi-person-dash"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
