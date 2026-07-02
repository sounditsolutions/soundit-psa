@extends('layouts.app')

@section('title', 'MCP Token')

@section('content')
<div class="row mb-3">
    <div class="col d-flex justify-content-between align-items-center">
        <h4 class="section-title mb-0">{{ $token->label }}</h4>
        <a href="{{ route('settings.mcp-tokens.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i>
        </a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header"><i class="bi bi-key me-2"></i>Tools</div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.mcp-tokens.tools', $token) }}">
                    @csrf
                    @method('PATCH')

                    @error('tools')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                    @error('tools.0')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

                    @foreach($groups as $group)
                        <div class="mb-3">
                            <div class="fw-semibold small mb-1">{{ $group['label'] }}</div>
                            @foreach($group['tools'] as $tool)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="tools[]" value="{{ $tool['name'] }}" id="tool_{{ $tool['name'] }}" @checked(in_array($tool['name'], $token->tools ?? [], true))>
                                    <label class="form-check-label" for="tool_{{ $tool['name'] }}">
                                        <code>{{ $tool['name'] }}</code>
                                        <span class="text-muted small d-block">{{ $tool['description'] }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @endforeach

                    <button class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </form>
            </div>
        </div>

        <div class="card card-static shadow-sm">
            <div class="card-header"><i class="bi bi-compass me-2"></i>Directive</div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.mcp-tokens.directive', $token) }}">
                    @csrf
                    @method('PATCH')
                    <textarea name="directive" class="form-control @error('directive') is-invalid @enderror" rows="6">{{ old('directive', $token->directiveOrDefault()) }}</textarea>
                    @error('directive')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <button class="btn btn-primary btn-sm mt-3">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </form>
            </div>
        </div>

        <div class="card card-static shadow-sm mt-4">
            <div class="card-header"><i class="bi bi-bell me-2"></i>Alerts Hub Destinations</div>
            <div class="card-body">
                @forelse($linkedSignalDestinations as $destination)
                    <div class="d-flex align-items-center justify-content-between border-bottom py-2">
                        <span>{{ $destination->label }}</span>
                        <form method="POST" action="{{ route('settings.mcp-tokens.signal-destinations.unlink', [$token, $destination]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm" title="Unlink">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </form>
                    </div>
                @empty
                    <div class="text-muted small mb-3">No MCP destinations linked.</div>
                @endforelse

                @if($availableSignalDestinations->isNotEmpty())
                    <form method="POST" action="{{ route('settings.mcp-tokens.signal-destinations.link', $token) }}" class="mt-3">
                        @csrf
                        <div class="input-group">
                            <select name="signal_destination_id" class="form-select @error('signal_destination_id') is-invalid @enderror">
                                @foreach($availableSignalDestinations as $destination)
                                    <option value="{{ $destination->id }}">{{ $destination->label }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-outline-primary">
                                <i class="bi bi-link-45deg"></i>
                            </button>
                        </div>
                        @error('signal_destination_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card card-static shadow-sm">
            <div class="card-header"><i class="bi bi-clock-history me-2"></i>Audit</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="thead-brand">
                        <tr>
                            <th>Time</th>
                            <th>Method</th>
                            <th>Tool</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($auditLogs as $log)
                            <tr>
                                <td class="small">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                                <td><code>{{ $log->method }}</code></td>
                                <td>{{ $log->tool_name }}</td>
                                <td>{{ $log->status }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">No audit rows.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
