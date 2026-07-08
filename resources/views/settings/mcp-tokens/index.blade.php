@extends('layouts.app')

@section('title', 'MCP Tokens')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <div>
        <h4 class="section-title mb-1">MCP Tokens</h4>
        <div class="text-muted small">
            Bearer tokens for the staff MCP server. Each token grants a curated subset of tools.
            <code class="ms-1">{{ url('/api/mcp/staff') }}</code>
        </div>
    </div>
    <form method="POST" action="{{ route('settings.mcp-tokens.store') }}" class="flex-shrink-0">
        @csrf
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Create token
        </button>
    </form>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="card card-static shadow-sm">
    @if($tokens->isEmpty())
        <div class="card-body text-center py-5">
            <div class="mb-3"><i class="bi bi-key fs-1 text-muted"></i></div>
            <h5 class="mb-2">No tokens yet</h5>
            <p class="text-muted mb-3 mx-auto" style="max-width: 42ch;">
                Create a token to give an agent a scoped set of tools. It starts inactive so you can configure it safely before it goes live.
            </p>
            <form method="POST" action="{{ route('settings.mcp-tokens.store') }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Create token</button>
            </form>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th>Token</th>
                        <th>Status</th>
                        <th>Tools granted</th>
                        <th>Trust</th>
                        <th>Created</th>
                        <th>Last used</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tokens as $token)
                        <tr class="{{ $token->isRevoked() ? 'opacity-50' : '' }}">
                            <td>
                                <a href="{{ route('settings.mcp-tokens.show', $token) }}" class="text-decoration-none fw-semibold">
                                    {{ $token->label }}
                                </a>
                                <div class="font-monospace text-muted small">{{ $token->token_prefix ?? '—' }}</div>
                            </td>
                            <td>@include('settings.mcp-tokens._state_badge', ['state' => $token->state()])</td>
                            <td class="small">
                                @if($token->tools === null)
                                    <span class="badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Full surface · legacy
                                    </span>
                                @elseif(count($token->tools) === 0)
                                    <span class="text-muted">No tools granted</span>
                                @else
                                    <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border me-1">{{ count($token->tools) }}</span>
                                    @foreach(array_slice($token->tools, 0, 3) as $tool)
                                        <code class="small me-1">{{ $tool }}</code>
                                    @endforeach
                                    @if(count($token->tools) > 3)
                                        <span class="text-muted">+{{ count($token->tools) - 3 }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="small">
                                @if($token->ai_actor)
                                    <span class="badge rounded-pill bg-info-subtle text-info-emphasis border">AI actor</span>
                                @endif
                                @if($token->require_explicit_client_scope)
                                    <span class="badge rounded-pill bg-light text-dark border">Client-scoped</span>
                                @endif
                                @unless($token->ai_actor || $token->require_explicit_client_scope)
                                    <span class="text-muted">Standard</span>
                                @endunless
                            </td>
                            <td class="small text-muted">{{ $token->created_at?->toAppTz()->format('Y-m-d H:i') }}</td>
                            <td class="small text-muted">{{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'never' }}</td>
                            <td class="text-end">
                                <a href="{{ route('settings.mcp-tokens.show', $token) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-gear me-1"></i>Open
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="text-muted small mt-3">
    <i class="bi bi-info-circle me-1"></i>
    Creating a token opens its configuration page. New tokens start inactive with no tools granted, so a token is never briefly live with the wrong permissions while you set it up.
</div>
@endsection
