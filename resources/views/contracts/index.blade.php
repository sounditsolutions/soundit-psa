@extends('layouts.app')

@section('title', $client->name . ' Contracts')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('clients.show', $client) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to {{ $client->name }}
        </a>
    </div>
</div>

<div class="row mb-3">
    <div class="col d-flex align-items-center justify-content-between">
        <h4 class="section-title mb-0">Contracts &mdash; {{ $client->name }}</h4>
        <a href="{{ route('contracts.create', $client) }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Contract
        </a>
    </div>
</div>

@if($contracts->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="bi bi-file-earmark-text" style="font-size: 3rem;"></i>
        <p class="mt-3">No contracts for this client.</p>
        <a href="{{ route('contracts.create', $client) }}" class="btn btn-primary btn-sm">Create a Contract</a>
    </div>
@else
    <div class="card shadow-sm card-static">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Billing</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Assignments</th>
                        <th>Profiles</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($contracts as $contract)
                        <tr class="cursor-pointer" onclick="window.location='{{ route('contracts.show', $contract) }}'">
                            <td>
                                <a href="{{ route('contracts.show', $contract) }}" class="text-decoration-none fw-semibold">
                                    {{ $contract->name }}
                                </a>
                                @if($contract->documents_count > 0)
                                    <i class="bi bi-file-earmark-text text-muted ms-1" title="{{ $contract->documents_count }} document(s)"></i>
                                @endif
                            </td>
                            <td class="small">{{ $contract->type->label() }}</td>
                            <td><span class="badge {{ $contract->status->badgeClass() }}">{{ $contract->status->label() }}</span></td>
                            <td class="small">{{ $contract->billing_period->label() }}</td>
                            <td class="small">{{ $contract->start_date->format('M j, Y') }}</td>
                            <td class="small">{{ $contract->end_date?->format('M j, Y') ?? '-' }}</td>
                            <td class="small">
                                @if($contract->people_count + $contract->assets_count + $contract->licenses_count > 0)
                                    <span title="People">{{ $contract->people_count }}<i class="bi bi-people ms-1 me-2 text-muted"></i></span>
                                    <span title="Assets">{{ $contract->assets_count }}<i class="bi bi-display ms-1 me-2 text-muted"></i></span>
                                    <span title="Licenses">{{ $contract->licenses_count }}<i class="bi bi-key ms-1 text-muted"></i></span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="small">{{ $contract->profiles_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $contracts->links() }}
    </div>
@endif
@endsection

@push('styles')
<style>
.cursor-pointer { cursor: pointer; }
</style>
@endpush
