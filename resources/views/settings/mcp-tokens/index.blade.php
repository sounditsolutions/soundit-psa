@extends('layouts.app')

@section('title', 'MCP Tokens')

@section('content')
<div class="row mb-3">
    <div class="col">
        <h4 class="section-title mb-0">MCP Tokens</h4>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($newToken)
    <div class="alert alert-warning">
        <div class="fw-semibold mb-2">{{ $newTokenLabel }}</div>
        <input class="form-control font-monospace" value="{{ $newToken }}" readonly>
    </div>
@endif

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card card-static shadow-sm">
            <div class="card-header"><i class="bi bi-plus-lg me-2"></i>Create Token</div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.mcp-tokens.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="label" class="form-label">Label</label>
                        <input id="label" name="label" class="form-control @error('label') is-invalid @enderror" value="{{ old('label') }}" required>
                        @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    @error('tools')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                    @error('tools.0')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

                    @foreach($groups as $group)
                        <div class="mb-3">
                            <div class="fw-semibold small mb-1">{{ $group['label'] }}</div>
                            @foreach($group['tools'] as $tool)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="tools[]" value="{{ $tool['name'] }}" id="new_{{ $tool['name'] }}" @checked(in_array($tool['name'], old('tools', []), true))>
                                    <label class="form-check-label" for="new_{{ $tool['name'] }}">
                                        <code>{{ $tool['name'] }}</code>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @endforeach

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-key me-1"></i>Create
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card card-static shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="thead-brand">
                        <tr>
                            <th>Label</th>
                            <th>Tools</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tokens as $token)
                            <tr>
                                <td>
                                    <a href="{{ route('settings.mcp-tokens.show', $token) }}" class="fw-semibold text-decoration-none">{{ $token->label }}</a>
                                    <div class="small text-muted font-monospace">{{ $token->token_prefix ?? 'legacy import' }}</div>
                                </td>
                                <td>{{ $token->tools === null ? 'full' : count($token->tools) }}</td>
                                <td>
                                    @if($token->isRevoked())
                                        <span class="badge bg-secondary">Revoked</span>
                                    @else
                                        <span class="badge bg-success">Active</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @unless($token->isRevoked())
                                        <form method="POST" action="{{ route('settings.mcp-tokens.revoke', $token) }}" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-outline-danger btn-sm" title="Revoke">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">No tokens.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
