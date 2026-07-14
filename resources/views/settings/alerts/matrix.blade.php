@extends('layouts.app')

@section('title', 'Alerts Relay Matrix')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">Alerts Relay Matrix</h1>
            <p class="text-muted mb-0">Choose which alert types relay to each MCP token, and which also fire a nudge. Relayed alerts queue for the token to poll; “also-nudge” types additionally piggyback a notice onto the agent’s next tool call.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="{{ route('settings.alerts.index') }}">
            <i class="bi bi-arrow-left me-1"></i>Alerts Hub
        </a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if (empty($tokens))
        <div class="alert alert-info">
            No active MCP tokens yet. Mint a token on the MCP Tokens page to give the matrix a column to relay to.
        </div>
    @else
        <div class="table-responsive border rounded" style="overflow-x: auto;">
            <table class="table table-sm table-hover align-middle mb-0" style="min-width: 720px;">
                <thead class="table-light">
                    <tr>
                        <th style="min-width: 260px;">Alert type</th>
                        @foreach ($tokens as $token)
                            <th class="text-center" style="min-width: 130px;">
                                <div class="fw-semibold">{{ $token['label'] }}</div>
                                @unless ($token['has_poll_signals'])
                                    <span class="badge bg-warning text-dark" title="This token is not granted poll_signals, so relayed alerts will not be read.">
                                        <i class="bi bi-exclamation-triangle me-1"></i>no poll_signals
                                    </span>
                                @endunless
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($types as $type)
                        @php($routable = $type['routable'])
                        @php($globallyOff = ! $type['globally_enabled'])
                        <tr class="{{ $globallyOff ? 'table-secondary text-muted' : '' }}">
                            <td>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="fw-semibold">{{ $type['label'] }}</span>
                                        <div><code class="small text-muted">{{ $type['key'] }}</code></div>
                                        @unless ($type['live'])
                                            <span class="badge bg-light text-muted border">no emitter yet</span>
                                        @endunless
                                        @unless ($routable)
                                            <span class="badge bg-light text-muted border">not routable</span>
                                        @endunless
                                    </div>
                                    @if ($routable)
                                        <form method="POST" action="{{ route('settings.alerts.matrix.type-toggle') }}" class="ms-2">
                                            @csrf
                                            <input type="hidden" name="type_key" value="{{ $type['key'] }}">
                                            <input type="hidden" name="enabled" value="{{ $globallyOff ? 1 : 0 }}">
                                            <button type="submit" class="btn btn-sm {{ $globallyOff ? 'btn-outline-secondary' : 'btn-outline-success' }}"
                                                title="Global master toggle for this type across all tokens">
                                                <i class="bi {{ $globallyOff ? 'bi-toggle-off' : 'bi-toggle-on' }} me-1"></i>{{ $globallyOff ? 'Disabled' : 'Enabled' }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>

                            @foreach ($tokens as $token)
                                @php($cell = $cells[$token['label']][$type['key']] ?? ['relayed' => false, 'nudge' => false])
                                <td class="text-center">
                                    @if (! $routable)
                                        <span class="text-muted">—</span>
                                    @else
                                        <form method="POST" action="{{ route('settings.alerts.matrix.relay') }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="token_label" value="{{ $token['label'] }}">
                                            <input type="hidden" name="type_key" value="{{ $type['key'] }}">
                                            <input type="hidden" name="relay" value="{{ $cell['relayed'] ? 0 : 1 }}">
                                            <button type="submit"
                                                class="btn btn-sm {{ $cell['relayed'] ? 'btn-success' : 'btn-outline-secondary' }}"
                                                @disabled($globallyOff && ! $cell['relayed'])
                                                aria-label="{{ $cell['relayed'] ? 'Relaying '.$type['label'].' to '.$token['label'].' — activate to stop' : 'Not relaying '.$type['label'].' to '.$token['label'].' — activate to relay' }}"
                                                title="{{ $cell['relayed'] ? 'Relaying — click to stop' : 'Not relaying — click to relay' }}">
                                                <i class="bi {{ $cell['relayed'] ? 'bi-check-lg' : 'bi-dash' }}" aria-hidden="true"></i> Relay
                                            </button>
                                        </form>

                                        {{-- Also-nudge: gold is reserved for the ACTIVE state only. A relayed-but-not-nudging
                                             cell is a neutral outline; an unavailable (not-yet-relayed) cell is muted + disabled,
                                             and the relay-first dependency is exposed via an accessible aria-label, not the title alone. --}}
                                        @php($nudgeClass = $cell['nudge'] ? 'btn-warning text-dark' : 'btn-outline-secondary')
                                        @php($nudgeLabel = $cell['relayed']
                                            ? ($cell['nudge']
                                                ? 'Also-nudging '.$type['label'].' to '.$token['label'].' — activate to stop'
                                                : 'Queue only for '.$type['label'].' to '.$token['label'].' — activate to also nudge')
                                            : 'Relay '.$type['label'].' to '.$token['label'].' first to enable also-nudge')
                                        <form method="POST" action="{{ route('settings.alerts.matrix.nudge') }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="token_label" value="{{ $token['label'] }}">
                                            <input type="hidden" name="type_key" value="{{ $type['key'] }}">
                                            <input type="hidden" name="nudge" value="{{ $cell['nudge'] ? 0 : 1 }}">
                                            <button type="submit"
                                                class="btn btn-sm {{ $nudgeClass }} {{ ! $cell['relayed'] ? 'opacity-50' : '' }}"
                                                @disabled(! $cell['relayed'])
                                                aria-label="{{ $nudgeLabel }}"
                                                title="{{ $nudgeLabel }}">
                                                <i class="bi bi-bell{{ $cell['nudge'] ? '-fill' : '' }}" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-muted small mt-2 mb-0">
            <span class="badge bg-success">Relay</span> queues the alert for this token to poll ·
            a <span class="badge bg-warning text-dark"><i class="bi bi-bell-fill" aria-hidden="true"></i></span> gold bell = also-nudge active (piggybacks awareness on the token’s next tool call); the bell is available only after the type is relayed to that token ·
            a greyed row is globally disabled for every token.
        </p>
    @endif
</div>
@endsection
