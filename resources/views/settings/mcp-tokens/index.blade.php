@extends('layouts.app')

@section('title', 'MCP Tokens')

@section('content')
<div class="row mb-3">
    <div class="col">
        <h4 class="section-title mb-1">MCP Tokens</h4>
        <div class="text-muted small">
            <code>{{ url('/api/mcp/staff') }}</code>
        </div>
    </div>
</div>

@if($newToken)
    <div class="alert alert-success shadow-sm" role="alert">
        <div class="fw-semibold mb-2">
            <i class="bi bi-check-circle me-1"></i>Token "{{ $newTokenLabel }}" created
        </div>
        <div class="small text-danger fw-semibold mb-2">Copy this now - it will not be shown again.</div>
        <div class="input-group">
            <input type="text" class="form-control font-monospace" id="mcp_new_token" value="{{ $newToken }}" readonly>
            <button type="button" class="btn btn-outline-secondary" onclick="copyMcpToken()">
                <i class="bi bi-clipboard me-1"></i>Copy
            </button>
        </div>
    </div>
@endif

<div class="row g-4">
    <div class="col-xl-5">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-plus-circle me-2"></i>Create Token
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.mcp-tokens.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="label" class="form-label">Label</label>
                        <input type="text"
                               class="form-control @error('label') is-invalid @enderror"
                               id="label"
                               name="label"
                               value="{{ old('label') }}"
                               maxlength="100"
                               placeholder="chet"
                               required>
                        <div class="form-text">Letters, numbers, underscore, dot, colon, and dash.</div>
                        @error('label')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Allowed Tools</label>
                        @error('tools')
                            <div class="text-danger small mb-2">{{ $message }}</div>
                        @enderror
                        @error('tools.0')
                            <div class="text-danger small mb-2">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex flex-column gap-3">
                        @foreach($groups as $key => $group)
                            <fieldset class="border rounded p-3 {{ $group['sensitive'] ? 'border-warning' : 'border-light-subtle' }}">
                                <legend class="float-none w-auto px-1 mb-2 fs-6">
                                    {{ $group['label'] }}
                                    @if($group['sensitive'])
                                        <span class="badge bg-warning text-dark ms-1">sensitive</span>
                                    @endif
                                </legend>

                                @forelse($group['tools'] as $tool)
                                    @php $id = 'tool_'.$key.'_'.$loop->index; @endphp
                                    <div class="form-check mb-2">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               name="tools[]"
                                               value="{{ $tool['name'] }}"
                                               id="{{ $id }}"
                                               {{ in_array($tool['name'], old('tools', []), true) ? 'checked' : '' }}>
                                        <label class="form-check-label w-100" for="{{ $id }}">
                                            <code>{{ $tool['name'] }}</code>
                                            @if($tool['description'] !== '')
                                                <span class="text-muted small d-block">{{ $tool['description'] }}</span>
                                            @endif
                                        </label>
                                    </div>
                                @empty
                                    <div class="text-muted small">No tools in this group.</div>
                                @endforelse
                            </fieldset>
                        @endforeach
                    </div>

                    <button type="submit" class="btn btn-primary mt-3">
                        <i class="bi bi-key me-1"></i>Create Token
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-list-ul me-2"></i>Existing Tokens
            </div>

            @if($tokens->isEmpty())
                <div class="card-body">
                    <div class="text-muted">No tokens yet.</div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="thead-brand">
                            <tr>
                                <th>Label</th>
                                <th>Prefix</th>
                                <th>Tools</th>
                                <th>Created</th>
                                <th>Last Used</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tokens as $token)
                                <tr class="{{ $token->isRevoked() ? 'text-muted' : '' }}">
                                    <td class="fw-semibold">
                                        <a href="{{ route('settings.mcp-tokens.show', $token) }}" class="text-decoration-none">
                                            {{ $token->label }}
                                        </a>
                                    </td>
                                    <td class="font-monospace small">{{ $token->token_prefix ?? '-' }}</td>
                                    <td class="small">
                                        @if($token->tools === null)
                                            <span class="badge bg-danger">full surface</span>
                                        @else
                                            <span class="text-muted">{{ count($token->tools) }}</span>
                                            @foreach(array_slice($token->tools, 0, 5) as $tool)
                                                <span class="badge bg-light text-dark border">{{ $tool }}</span>
                                            @endforeach
                                            @if(count($token->tools) > 5)
                                                <span class="text-muted">+{{ count($token->tools) - 5 }}</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="small">{{ $token->created_at?->toAppTz()->format('Y-m-d H:i') }}</td>
                                    <td class="small">{{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'never' }}</td>
                                    <td class="text-center">
                                        @if($token->isRevoked())
                                            <span class="badge bg-secondary">Revoked</span>
                                        @else
                                            <span class="badge bg-success">Active</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @unless($token->isRevoked())
                                            <form method="POST"
                                                  action="{{ route('settings.mcp-tokens.revoke', $token) }}"
                                                  class="d-inline"
                                                  onsubmit="return confirm(@js('Revoke token "'.$token->label.'"? It will stop authenticating immediately.'))">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                                    <i class="bi bi-x-circle me-1"></i>Revoke
                                                </button>
                                            </form>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function copyMcpToken() {
    const input = document.getElementById('mcp_new_token');
    if (!input || !navigator.clipboard) {
        return;
    }

    navigator.clipboard.writeText(input.value).then(() => {
        const button = input.nextElementSibling;
        if (!button) {
            return;
        }

        const original = button.innerHTML;
        button.innerHTML = '<i class="bi bi-check me-1"></i>Copied';
        window.setTimeout(() => {
            button.innerHTML = original;
        }, 2000);
    });
}
</script>
@endpush
