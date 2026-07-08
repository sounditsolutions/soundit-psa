@extends('layouts.app')

@section('title', $client->name . ' - Portal Management')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('clients.show', $client) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to {{ $client->name }}
        </a>
    </div>
</div>

<h4 class="section-title mb-4">Portal Management — {{ $client->name }}</h4>

<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    When the portal is enabled, notes marked <strong>Public</strong> will be visible to clients.
    Private notes remain staff-only.
</div>

@if(!$graphConfigured)
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <strong>Email not configured.</strong> Graph mailbox must be set up in
        <a href="{{ route('settings.integrations') }}">Settings &gt; Integrations &gt; Microsoft Graph</a>
        before you can send portal invites or password resets.
    </div>
@endif

<div class="card">
    <div class="card-header">
        <h6 class="mb-0">Contacts with Email</h6>
    </div>
    <div class="card-body p-0">
        @if($contacts->isEmpty())
            <p class="text-muted p-3 mb-0">No contacts with email addresses found for this client.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Portal Status</th>
                            <th>Access Level</th>
                            <th>Last Login</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contacts as $person)
                            <tr>
                                <td><x-person-badge :person="$person" :size="24" /></td>
                                <td class="text-muted small">{{ $person->email }}</td>
                                <td>
                                    @if($person->portal_enabled)
                                        <span class="badge bg-success">Enabled</span>
                                    @else
                                        <span class="badge bg-secondary">Disabled</span>
                                    @endif
                                </td>
                                <td>
                                    @if($person->portal_enabled)
                                        @if($person->company_wide_access)
                                            <span class="badge bg-info text-dark">Company-wide</span>
                                        @else
                                            <span class="badge bg-light text-dark">Own tickets</span>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-muted small">
                                    {{ $person->portal_last_login_at?->toAppTz()->diffForHumans() ?? '—' }}
                                </td>
                                <td class="text-end">
                                    @if($person->portal_enabled)
                                        {{-- Toggle access level --}}
                                        <form method="POST" action="{{ route('clients.portal.toggle-access', $client) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="person_id" value="{{ $person->id }}">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Toggle access level">
                                                <i class="bi bi-people me-1"></i>{{ $person->company_wide_access ? 'Own Only' : 'Company-wide' }}
                                            </button>
                                        </form>

                                        {{-- Reset password --}}
                                        <form method="POST" action="{{ route('clients.portal.reset-password', $client) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="person_id" value="{{ $person->id }}">
                                            <button type="submit" class="btn btn-sm btn-outline-warning" {{ !$graphConfigured ? 'disabled' : '' }}
                                                    title="{{ !$graphConfigured ? 'Graph email not configured' : 'Send password reset email' }}">
                                                <i class="bi bi-key"></i>
                                            </button>
                                        </form>

                                        {{-- View as (impersonate) --}}
                                        <form method="POST" action="{{ route('clients.portal.impersonate', $client) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="person_id" value="{{ $person->id }}">
                                            <button type="submit" class="btn btn-sm btn-outline-info" title="View portal as this person">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </form>

                                        {{-- Disable --}}
                                        <form method="POST" action="{{ route('clients.portal.toggle', $client) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="person_id" value="{{ $person->id }}">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Disable portal access">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        </form>
                                    @else
                                        {{-- Invite --}}
                                        <form method="POST" action="{{ route('clients.portal.invite', $client) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="person_id" value="{{ $person->id }}">
                                            <button type="submit" class="btn btn-sm btn-primary" {{ !$graphConfigured ? 'disabled' : '' }}
                                                    title="{{ !$graphConfigured ? 'Graph email not configured' : 'Send portal invite' }}">
                                                <i class="bi bi-send me-1"></i>Invite
                                            </button>
                                        </form>
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
@endsection
