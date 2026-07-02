<div class="row g-4">
    <div class="col-xl-4">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-plus-circle me-2"></i>Create Destination
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.alerts.destinations.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="label" class="form-label">Label</label>
                        <input type="text" id="label" name="label" value="{{ old('label') }}" class="form-control @error('label') is-invalid @enderror" required>
                        @error('label')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="type" class="form-label">Type</label>
                        <select id="type" name="type" class="form-select @error('type') is-invalid @enderror" required>
                            @foreach(['webhook' => 'Webhook', 'email' => 'Email', 'mcp' => 'MCP Agent'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('type', 'webhook') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Webhook URL or Email</label>
                        <input type="text" id="address" name="address" value="{{ old('address') }}" class="form-control @error('address') is-invalid @enderror" autocomplete="off">
                        @error('address')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="mcp_token_label" class="form-label">MCP Token Label</label>
                        <select id="mcp_token_label" name="mcp_token_label" class="form-select @error('mcp_token_label') is-invalid @enderror">
                            <option value="">Choose a scoped token</option>
                            @foreach($mcpTokens as $token)
                                <option value="{{ $token->label }}" @selected(old('mcp_token_label') === $token->label)>{{ $token->label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ "Rotating this token's label re-points or orphans this destination" }}</div>
                        @error('mcp_token_label')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="wake_url" class="form-label">Wake URL</label>
                        <input type="text" id="wake_url" name="wake_url" value="{{ old('wake_url') }}" class="form-control @error('wake_url') is-invalid @enderror" autocomplete="off">
                        @error('wake_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="wake_secret" class="form-label">Wake Secret</label>
                        <input type="password" id="wake_secret" name="wake_secret" class="form-control @error('wake_secret') is-invalid @enderror" autocomplete="new-password">
                        @error('wake_secret')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i>Create
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-list-ul me-2"></i>Destinations
            </div>

            @if($destinations->isEmpty())
                <div class="card-body">
                    <div class="text-muted">No destinations yet.</div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="thead-brand">
                            <tr>
                                <th>Destination</th>
                                <th>Target</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($destinations as $destination)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">
                                            <i class="bi {{ $destination->type === 'webhook' ? 'bi-webhook' : ($destination->type === 'email' ? 'bi-envelope' : 'bi-robot') }} me-1"></i>{{ $destination->label }}
                                        </div>
                                        <div class="text-muted small">{{ strtoupper($destination->type) }}</div>
                                    </td>
                                    <td>
                                        @if($destination->type === 'mcp')
                                            <div><code>{{ $destination->mcp_token_label }}</code></div>
                                            @if($destination->masked_wake_url)
                                                <div class="text-muted small">Wake: {{ $destination->masked_wake_url }}</div>
                                            @endif
                                        @else
                                            <span class="font-monospace small">{{ $destination->masked_address ?? 'not set' }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $destination->enabled ? 'bg-success' : 'bg-secondary' }}">{{ $destination->enabled ? 'Enabled' : 'Disabled' }}</span>
                                        @if($destination->last_delivery_status)
                                            <div class="small mt-1">
                                                <span class="badge bg-light text-dark">{{ $destination->last_delivery_status }}</span>
                                                @if($destination->last_delivery_at)
                                                    <span class="text-muted">{{ $destination->last_delivery_at->format('Y-m-d H:i') }}</span>
                                                @endif
                                            </div>
                                        @endif
                                        @if($destination->last_error)
                                            <div class="text-danger small mt-1">{{ $destination->last_error }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('settings.alerts.destinations.test', $destination) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-outline-primary btn-sm" title="Test send">
                                                <i class="bi bi-send"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('settings.alerts.destinations.toggle', $destination) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm {{ $destination->enabled ? 'btn-outline-warning' : 'btn-outline-success' }}" title="{{ $destination->enabled ? 'Disable' : 'Enable' }}">
                                                <i class="bi {{ $destination->enabled ? 'bi-pause' : 'bi-play' }}"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="bg-light-subtle">
                                        <form method="POST" action="{{ route('settings.alerts.destinations.update', $destination) }}" class="row g-2 align-items-end">
                                            @csrf
                                            @method('PUT')

                                            <div class="col-md-3">
                                                <label class="form-label small" for="label_{{ $destination->id }}">Label</label>
                                                <input type="text" id="label_{{ $destination->id }}" name="label" value="{{ old('label', $destination->label) }}" class="form-control form-control-sm" required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small" for="type_{{ $destination->id }}">Type</label>
                                                <select id="type_{{ $destination->id }}" name="type" class="form-select form-select-sm" required>
                                                    @foreach(['webhook' => 'Webhook', 'email' => 'Email', 'mcp' => 'MCP Agent'] as $value => $label)
                                                        <option value="{{ $value }}" @selected($destination->type === $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small" for="address_{{ $destination->id }}">Address</label>
                                                <input type="text" id="address_{{ $destination->id }}" name="address" value="" placeholder="{{ $destination->masked_address ? $secretMask.' '.$destination->masked_address : '' }}" class="form-control form-control-sm" autocomplete="off">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small" for="mcp_{{ $destination->id }}">MCP Token</label>
                                                <select id="mcp_{{ $destination->id }}" name="mcp_token_label" class="form-select form-select-sm">
                                                    <option value="">Choose</option>
                                                    @foreach($mcpTokens as $token)
                                                        <option value="{{ $token->label }}" @selected($destination->mcp_token_label === $token->label)>{{ $token->label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                                                    <i class="bi bi-check-lg me-1"></i>Save
                                                </button>
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label small" for="wake_url_{{ $destination->id }}">Wake URL</label>
                                                <input type="text" id="wake_url_{{ $destination->id }}" name="wake_url" value="" placeholder="{{ $destination->masked_wake_url ? $secretMask.' '.$destination->masked_wake_url : '' }}" class="form-control form-control-sm" autocomplete="off">
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label small" for="wake_secret_{{ $destination->id }}">Wake Secret</label>
                                                <input type="password" id="wake_secret_{{ $destination->id }}" name="wake_secret" class="form-control form-control-sm" autocomplete="new-password">
                                            </div>
                                        </form>
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
