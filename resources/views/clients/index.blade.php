@extends('layouts.app')

@section('title', 'Clients')

@section('content')
<div class="row mb-3">
    <div class="col d-flex justify-content-between align-items-center">
        <h4 class="section-title mb-0">Clients</h4>
        <a href="{{ route('clients.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Client
        </a>
    </div>
</div>

<form method="GET" action="{{ route('clients.index') }}" class="mb-3">
    <div class="input-group">
        <input type="text" name="search" class="form-control" placeholder="Search by name, phone, or email..."
               value="{{ $search }}" autofocus>
        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
        @if($search)
            <a href="{{ route('clients.index') }}" class="btn btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
        @endif
    </div>
</form>

<div class="btn-group btn-group-sm mb-3" role="group" aria-label="Filter clients by stage">
    @foreach(['all' => 'All', 'active' => 'Active', 'prospect' => 'Prospects'] as $stageKey => $stageLabel)
        <a href="{{ route('clients.index', array_filter(['stage' => $stageKey, 'search' => $search])) }}"
           class="btn {{ $stage === $stageKey ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $stageLabel }}</a>
    @endforeach
</div>

@if($clients->isEmpty())
    <div class="alert alert-info">
        @if($search)
            No clients match "{{ $search }}".
        @else
            No clients found.
        @endif
    </div>
@else
    <div class="card shadow-sm card-static">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th class="text-center">People</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($clients as $client)
                        <tr>
                            <td>
                                <x-client-badge :client="$client" :size="24" />
                                @if($client->stage === \App\Enums\ClientStage::Prospect)
                                    <span class="badge bg-warning text-dark badge-prospect ms-1">Prospect</span>
                                @endif
                                @if($client->reseller)
                                    <i class="bi bi-arrow-up-right-circle text-info ms-1" title="Reseller: {{ $client->reseller->name }}"></i>
                                @endif
                            </td>
                            <td>
                                @if($client->phone_display)
                                    <a href="#" data-phone="{{ $client->phone }}" class="text-decoration-none dial-link">
                                        {{ $client->phone_display }}
                                    </a>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>{{ $client->email ?? '-' }}</td>
                            <td class="text-center">
                                @if($client->people_count > 0)
                                    <span class="badge bg-light text-dark">{{ $client->people_count }}</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('clients.show', $client) }}"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-arrow-right"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $clients->links() }}
    </div>
@endif
@endsection
