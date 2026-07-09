@extends('layouts.app')

@section('title', 'MCP Token · '.$token->label)

@php
    $readOnly = $token->isRevoked();
    $grantedList = collect($token->tools ?? []);
    $allTools = collect($integrationGroups)->flatMap(fn ($g) => collect($g['tiers'])->flatMap(fn ($t) => $t['tools']));
    $totalCount = $allTools->count();
    $grantedCount = $grantedList->count();
    $sensitiveGranted = $allTools->filter(fn ($t) => $t['sensitive'] && $grantedList->contains($t['name']))->count();
    $grantedWithInstr = $allTools->filter(fn ($t) => $grantedList->contains($t['name']) && ! empty($toolInstructions[$t['name']] ?? ''));
@endphp

@push('styles')
<style>
    .mcp-cfg .mono { font-family: var(--bs-font-monospace); }
    .mcp-cfg .meta-sep { color: var(--bs-border-color); }

    /* one-time secret + banners */
    .mcp-secret-input { font-family: var(--bs-font-monospace); background: #fff; }

    /* header name (rename in place) */
    .mcp-name-input {
        font-size: 1.4rem; font-weight: 700; border: 1px solid transparent; background: transparent;
        padding: .15rem .5rem; border-radius: .4rem; max-width: 460px; color: var(--bs-emphasis-color);
    }
    .mcp-name-input:hover:not(:disabled) { background: var(--bs-tertiary-bg); }
    .mcp-name-input:focus { background: #fff; border-color: var(--bs-border-color); box-shadow: 0 0 0 .2rem rgba(26,54,93,.12); outline: none; }

    /* summary + toolbar */
    .mcp-summary { background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color-translucent); border-radius: .6rem; }
    .mcp-search .bi { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); color: var(--bs-secondary-color); }
    .mcp-search input { padding-left: 2.1rem; }

    /* integration group */
    .mcp-group { border: 1px solid var(--bs-border-color); border-radius: .6rem; overflow: hidden; }
    .mcp-group + .mcp-group { margin-top: .6rem; }
    .mcp-group.filtered-out { display: none; }
    .mcp-group-head {
        display: flex; align-items: center; gap: .85rem; width: 100%; text-align: left;
        border: 0; background: transparent; padding: .85rem 1rem; color: inherit;
    }
    .mcp-group-head:hover { background: var(--bs-tertiary-bg); }
    .mcp-group-icon { width: 38px; height: 38px; border-radius: .55rem; display: grid; place-items: center; color: #fff; flex: none; }
    .mcp-group-icon .bi { font-size: 1.05rem; }
    .mcp-count { font-size: .82rem; font-weight: 700; white-space: nowrap; }
    .mcp-count .g { color: #0f9d63; }
    .mcp-meter { width: 118px; height: 6px; border-radius: 3px; background: var(--bs-secondary-bg); overflow: hidden; margin-top: 4px; }
    /* gold grant meter (theme accent) — more granted is not "bad" */
    .mcp-meter .fill { display: block; height: 100%; background: var(--accent, #fed136); transition: width .3s ease; }
    .mcp-chev { color: var(--bs-secondary-color); transition: transform .2s; }
    .mcp-group.open .mcp-chev { transform: rotate(90deg); }
    .mcp-group-body { display: none; border-top: 1px solid var(--bs-border-color); }
    .mcp-group.open .mcp-group-body { display: block; }
    .mcp-scroll { max-height: 460px; overflow-y: auto; }

    /* tier */
    .mcp-tier + .mcp-tier { border-top: 1px solid var(--bs-border-color); }
    .mcp-tier-head {
        position: sticky; top: 0; z-index: 2; display: flex; align-items: center; gap: .6rem;
        padding: .55rem 1rem; background: var(--bs-body-bg); border-bottom: 1px solid var(--bs-border-color);
        font-size: .74rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--bs-secondary-color);
    }
    .mcp-tier.sensitive .mcp-tier-head { background: #fff8ec; color: #b45309; border-bottom-color: #fbdca0; }
    .mcp-tier-count { font-weight: 600; text-transform: none; letter-spacing: 0; }

    /* tool row */
    .mcp-tool { display: flex; gap: .8rem; align-items: flex-start; padding: .6rem 1rem; border-bottom: 1px solid var(--bs-border-color); }
    .mcp-tool:last-child { border-bottom: 0; }
    .mcp-tool.hidden { display: none; }
    .mcp-tool.granted { background: rgba(15,157,99,.06); }
    .mcp-tool.granted.sensitive { background: rgba(217,119,6,.08); }
    .mcp-tool .tool-name { font-family: var(--bs-font-monospace); font-size: .82rem; font-weight: 600; color: var(--bs-emphasis-color); }
    .mcp-tool .tool-desc { font-size: .8rem; color: var(--bs-secondary-color); margin-top: 2px; }
    .badge-sensitive { background: #fff8ec; color: #b45309; border: 1px solid #fbdca0; }

    /* switch colours: granted = green, sensitive = amber */
    .form-switch .tool-switch { cursor: pointer; }
    .tool-switch:checked { background-color: #0f9d63; border-color: #0f9d63; }
    .tool-switch.sensitive { border-color: #e2b366; }
    .tool-switch.sensitive:checked { background-color: #d97706; border-color: #d97706; }
    .tool-switch:focus { box-shadow: 0 0 0 .2rem rgba(15,157,99,.2); }
    .tool-switch.sensitive:focus { box-shadow: 0 0 0 .2rem rgba(217,119,6,.25); }

    .mcp-instr { margin-top: .5rem; }
    .mcp-instr .glob { font-size: .72rem; color: #b45309; }

    .mcp-noresults { display: none; text-align: center; padding: 2.5rem 1rem; color: var(--bs-secondary-color); }
    .mcp-noresults.show { display: block; }

    #mcpToast {
        position: fixed; bottom: 1.3rem; left: 50%; transform: translate(-50%, 1rem);
        background: #0f1e33; color: #fff; padding: .6rem 1.1rem; border-radius: 2rem; font-size: .85rem; font-weight: 600;
        box-shadow: 0 .8rem 2rem rgba(15,30,51,.35); opacity: 0; pointer-events: none; transition: opacity .2s, transform .2s; z-index: 1090;
    }
    #mcpToast.show { opacity: 1; transform: translate(-50%, 0); }
    #mcpToast .bi { color: var(--accent, #fed136); }
</style>
@endpush

@section('content')
<div class="mcp-cfg" data-token-id="{{ $token->id }}">

<a href="{{ route('settings.mcp-tokens.index') }}" class="btn btn-sm btn-link text-decoration-none ps-0 mb-2" aria-label="Back to all tokens">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>All tokens
</a>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
@endif

{{-- one-time secret (only right after creation) --}}
@if($newToken)
    <div class="alert alert-success shadow-sm" role="alert">
        <div class="fw-semibold mb-1"><i class="bi bi-check-circle me-1"></i>Token created</div>
        <div class="small text-danger fw-semibold mb-2">Copy the secret now. For security it will not be shown again.</div>
        <div class="input-group">
            <input type="text" class="form-control mcp-secret-input" id="mcpNewToken" value="{{ $newToken }}" readonly aria-label="New token secret">
            <button type="button" class="btn btn-outline-secondary" id="mcpCopySecret"><i class="bi bi-clipboard me-1"></i>Copy</button>
        </div>
    </div>
@endif

@if($token->tools === null)
    <div class="alert alert-danger d-flex align-items-start" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
        <div><strong>Legacy full surface.</strong> This token can call every tool. Grant specific tools below and save to scope it down.</div>
    </div>
@endif

{{-- draft safety banner --}}
@if($token->isDraft())
    <div class="alert alert-warning d-flex align-items-center shadow-sm" role="alert">
        <i class="bi bi-shield-lock-fill me-2 fs-5"></i>
        <div class="flex-grow-1">
            <strong>This token is a draft.</strong> It is inactive and grants no tools, so it can't authenticate yet. Grant the tools it needs, set its behaviour, then activate it.
        </div>
        <form method="POST" action="{{ route('settings.mcp-tokens.activate', $token) }}" class="ms-3 flex-shrink-0">
            @csrf
            <button type="submit" class="btn btn-primary"><i class="bi bi-play-fill me-1"></i>Activate token</button>
        </form>
    </div>
@endif

{{-- header --}}
<div class="d-flex align-items-start gap-3 mb-2 flex-wrap">
    <div class="rounded d-grid" style="width:46px;height:46px;place-items:center;background:linear-gradient(135deg,#1a365d,#234179);color:#fff;flex:none;">
        <i class="bi bi-key-fill fs-5"></i>
    </div>
    <div class="flex-grow-1 min-width-0">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <form id="mcpRenameForm" method="POST" action="{{ route('settings.mcp-tokens.rename', $token) }}" class="d-inline-flex align-items-center">
                @csrf
                @method('PATCH')
                <input type="text" name="label" id="mcpTokenLabel" class="mcp-name-input" value="{{ $token->label }}"
                       maxlength="100" spellcheck="false" autocomplete="off" @disabled($readOnly)
                       aria-label="Token name">
                <span id="mcpRenameStatus" class="ms-1 small text-success" style="display:none;"><i class="bi bi-check2"></i></span>
            </form>
            @include('settings.mcp-tokens._state_badge', ['state' => $token->state()])
            @if($token->ai_actor)
                <span class="badge rounded-pill bg-info-subtle text-info-emphasis border"><i class="bi bi-robot me-1"></i>AI actor</span>
            @endif
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap text-muted small mt-2">
            <span class="mono"><i class="bi bi-hash me-1"></i>{{ $token->token_prefix ?? '—' }}</span>
            <span><i class="bi bi-clock me-1"></i>Created {{ $token->created_at?->toAppTz()->format('Y-m-d H:i') }}</span>
            <span><i class="bi bi-activity me-1"></i>{{ $token->last_used_at ? 'Last used '.$token->last_used_at->diffForHumans() : 'Never used' }}</span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-shrink-0">
        @if($token->isActive())
            <form method="POST" action="{{ route('settings.mcp-tokens.pause', $token) }}">@csrf<button class="btn btn-outline-secondary"><i class="bi bi-pause-fill me-1"></i>Pause</button></form>
        @elseif($token->isPaused())
            <form method="POST" action="{{ route('settings.mcp-tokens.resume', $token) }}">@csrf<button class="btn btn-outline-success"><i class="bi bi-play-fill me-1"></i>Resume</button></form>
        @endif
        @unless($readOnly)
            <form method="POST" action="{{ route('settings.mcp-tokens.regenerate', $token) }}"
                  onsubmit="return confirm(@js('Regenerate secret for \''.$token->label.'\'? This immediately invalidates the current secret — any client using it stops working until you paste the new one. The new secret is shown once.'))">
                @csrf
                <button class="btn btn-outline-warning">
                    <i class="bi bi-arrow-clockwise me-1"></i>Regenerate secret
                </button>
            </form>
            <form method="POST" action="{{ route('settings.mcp-tokens.revoke', $token) }}"
                  onsubmit="return confirm(@js($token->isDraft() ? 'Discard this draft token?' : 'Revoke token \''.$token->label.'\'? It will stop authenticating immediately.'))">
                @csrf @method('DELETE')
                <button class="btn btn-outline-danger">
                    <i class="bi bi-{{ $token->isDraft() ? 'trash' : 'x-circle' }} me-1"></i>{{ $token->isDraft() ? 'Discard' : 'Revoke' }}
                </button>
            </form>
        @endunless
    </div>
</div>

{{-- tabs --}}
<ul class="nav nav-tabs mt-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-tools" type="button"><i class="bi bi-key me-1"></i>Tools <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis" id="toolsTabCount">{{ $grantedCount }}</span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-behavior" type="button"><i class="bi bi-compass me-1"></i>Behaviour</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-trust" type="button"><i class="bi bi-shield-check me-1"></i>Trust &amp; scope</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-alerts" type="button"><i class="bi bi-bell me-1"></i>Alerts</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-activity" type="button"><i class="bi bi-clock-history me-1"></i>Activity</button></li>
</ul>

<div class="tab-content pt-4">

    {{-- ===== TOOLS ===== --}}
    <div class="tab-pane fade show active" id="tab-tools" role="tabpanel">
        <div class="mcp-summary d-flex align-items-center gap-3 flex-wrap p-3 mb-3">
            <div class="fw-semibold"><span id="sumGranted">{{ $grantedCount }}</span> of {{ $totalCount }} tools granted</div>
            <span class="vr d-none d-md-block"></span>
            <div class="small"><span class="badge badge-sensitive rounded-pill" id="sumSensitive">{{ $sensitiveGranted }}</span> sensitive enabled</div>
            <div class="small text-muted d-none d-lg-block"><i class="bi bi-info-circle me-1"></i>Changes save as you go. Ungranted-by-default tools stay dormant until you turn them on.</div>
            <div class="ms-auto btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="mcpExpandAll">Expand all</button>
                <button class="btn btn-outline-secondary" id="mcpCollapseAll">Collapse all</button>
            </div>
        </div>

        <div class="d-flex gap-2 flex-wrap align-items-center mb-3">
            <div class="mcp-search position-relative flex-grow-1" style="min-width:220px;">
                <i class="bi bi-search"></i>
                <input type="text" class="form-control" id="mcpToolSearch" placeholder="Search all {{ $totalCount }} tools by name or description…" autocomplete="off" spellcheck="false" aria-label="Search tools" @disabled($readOnly)>
            </div>
            <div class="btn-group btn-group-sm mcp-filter" role="group" data-filter="grant" aria-label="Filter by grant status">
                <button class="btn btn-outline-secondary active" data-val="all">All</button>
                <button class="btn btn-outline-secondary" data-val="granted">Granted</button>
                <button class="btn btn-outline-secondary" data-val="ungranted">Ungranted</button>
            </div>
            <div class="btn-group btn-group-sm mcp-filter" role="group" data-filter="tier" aria-label="Filter by tier">
                <button class="btn btn-outline-secondary active" data-val="all">All tiers</button>
                <button class="btn btn-outline-secondary" data-val="standard">Standard</button>
                <button class="btn btn-outline-secondary" data-val="sensitive">Sensitive</button>
            </div>
        </div>

        <div id="mcpGroups">
            @foreach($integrationGroups as $key => $group)
                @php $groupGranted = $group['tiers'] ? collect($group['tiers'])->flatMap(fn ($t) => $t['tools'])->filter(fn ($t) => $grantedList->contains($t['name']))->count() : 0; @endphp
                <div class="mcp-group {{ $key === 'psa' ? 'open' : '' }}" data-integration="{{ $key }}">
                    <button type="button" class="mcp-group-head" aria-expanded="{{ $key === 'psa' ? 'true' : 'false' }}">
                        <span class="mcp-group-icon" style="background: {{ $group['accent'] }};"><i class="bi {{ $group['icon'] }}"></i></span>
                        <span class="flex-grow-1 min-width-0">
                            <span class="fw-semibold d-flex align-items-center gap-2 flex-wrap">
                                {{ $group['label'] }}
                                @if($group['sensitive_count'] > 0)
                                    <span class="badge badge-sensitive rounded-pill" style="font-size:.62rem;"><i class="bi bi-shield-exclamation me-1"></i>has sensitive</span>
                                @endif
                            </span>
                            <span class="d-block text-muted small">{{ $group['blurb'] }}</span>
                        </span>
                        <span class="text-end me-2">
                            <span class="mcp-count"><span class="g group-granted">{{ $groupGranted }}</span> / {{ $group['total'] }}</span>
                            <span class="mcp-meter"><span class="fill" style="width: {{ $group['total'] ? round($groupGranted / $group['total'] * 100) : 0 }}%;"></span></span>
                        </span>
                        <i class="bi bi-chevron-right mcp-chev"></i>
                    </button>
                    <div class="mcp-group-body">
                        <div class="mcp-scroll">
                            @foreach($group['tiers'] as $tier)
                                @php $tierGranted = collect($tier['tools'])->filter(fn ($t) => $grantedList->contains($t['name']))->count(); @endphp
                                <div class="mcp-tier {{ $tier['sensitive'] ? 'sensitive' : '' }}" data-tier-sensitive="{{ $tier['sensitive'] ? '1' : '0' }}">
                                    <div class="mcp-tier-head">
                                        <span>@if($tier['sensitive'])<i class="bi bi-shield-lock me-1"></i>@else<i class="bi bi-eye me-1"></i>@endif{{ $tier['label'] }}@if($tier['sensitive']) · sensitive @endif</span>
                                        <span class="mcp-tier-count text-muted"><span class="tier-granted">{{ $tierGranted }}</span>/{{ count($tier['tools']) }}</span>
                                        @unless($readOnly)
                                            <span class="ms-auto btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-secondary mcp-bulk" data-act="grant" data-sensitive="{{ $tier['sensitive'] ? '1' : '0' }}">Grant shown</button>
                                                <button type="button" class="btn btn-outline-secondary mcp-bulk" data-act="revoke">Revoke</button>
                                            </span>
                                        @endunless
                                    </div>
                                    @foreach($tier['tools'] as $tool)
                                        @php $isGranted = $grantedList->contains($tool['name']); $hasInstr = ! empty($toolInstructions[$tool['name']] ?? ''); @endphp
                                        <div class="mcp-tool {{ $isGranted ? 'granted' : '' }} {{ $tool['sensitive'] ? 'sensitive' : '' }}"
                                             data-tool="{{ $tool['name'] }}"
                                             data-name="{{ strtolower($tool['name']) }}"
                                             data-desc="{{ strtolower($tool['description']) }}"
                                             data-tier="{{ $tool['sensitive'] ? 'sensitive' : 'standard' }}"
                                             data-granted="{{ $isGranted ? '1' : '0' }}">
                                            <div class="form-check form-switch m-0 pt-1">
                                                <input type="checkbox" class="form-check-input tool-switch {{ $tool['sensitive'] ? 'sensitive' : '' }}"
                                                       data-tool="{{ $tool['name'] }}" @checked($isGranted) @disabled($readOnly)
                                                       aria-label="Grant {{ $tool['name'] }}">
                                            </div>
                                            <div class="flex-grow-1 min-width-0">
                                                <div class="tool-name d-flex align-items-center gap-2 flex-wrap">
                                                    {{ $tool['name'] }}
                                                    @if($tool['sensitive'])<span class="badge badge-sensitive rounded-pill" style="font-size:.6rem;">Sensitive</span>@endif
                                                    <span class="badge rounded-pill bg-info-subtle text-info-emphasis border tool-instr-flag" style="font-size:.6rem; {{ $hasInstr ? '' : 'display:none;' }}"><i class="bi bi-card-text me-1"></i>instruction</span>
                                                </div>
                                                @if($tool['description'] !== '')<div class="tool-desc">{{ $tool['description'] }}</div>@endif
                                                <div class="mcp-instr" style="{{ $isGranted ? '' : 'display:none;' }}">
                                                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none mcp-instr-toggle" style="font-size:.75rem;">
                                                        <i class="bi bi-sliders2 me-1"></i>{{ $hasInstr ? 'Edit shared instruction' : 'Add shared instruction' }}
                                                    </button>
                                                    <div class="mcp-instr-body mt-1" style="display:none;">
                                                        <textarea class="form-control form-control-sm tool-instr-input" rows="2" data-tool="{{ $tool['name'] }}" maxlength="5000" placeholder="Guidance for {{ $tool['name'] }}…" aria-label="Shared instruction for {{ $tool['name'] }}" @disabled($readOnly)>{{ $toolInstructions[$tool['name']] ?? '' }}</textarea>
                                                        <div class="glob mt-1"><i class="bi bi-globe2 me-1"></i>Shared — applies to <code>{{ $tool['name'] }}</code> on every token.</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mcp-noresults" id="mcpNoResults">
            <div class="mb-2"><i class="bi bi-search fs-3"></i></div>
            No tools match <strong id="mcpNoQuery"></strong>. Try a different term or clear the filters.
        </div>
    </div>

    {{-- ===== BEHAVIOUR ===== --}}
    <div class="tab-pane fade" id="tab-behavior" role="tabpanel">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card card-static shadow-sm">
                    <div class="card-header d-flex align-items-center"><i class="bi bi-compass me-2"></i>Directive
                        <span class="badge rounded-pill bg-light text-dark border ms-auto">Per token</span></div>
                    <div class="card-body">
                        <p class="text-muted small">The system directive prepended to this token's calls. It sets who this token is and how it behaves. Unique to this token.</p>
                        <textarea class="form-control" id="mcpDirective" rows="6" maxlength="20000" aria-label="Directive" @disabled($readOnly)>{{ $token->directiveOrDefault() }}</textarea>
                        <div class="small text-muted mt-2" id="mcpDirectiveStatus"><i class="bi bi-info-circle me-1"></i>Saves automatically.</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card card-static shadow-sm">
                    <div class="card-header d-flex align-items-center"><i class="bi bi-sliders me-2"></i>Tool instructions
                        <span class="badge badge-sensitive rounded-pill ms-auto"><i class="bi bi-globe2 me-1"></i>Shared</span></div>
                    <div class="card-body">
                        <p class="text-muted small">Extra guidance attached to a tool. These are <strong>shared</strong>: an instruction on a tool applies on every token that grants it. Edit each one inline on its row in the Tools tab.</p>
                        <div id="mcpInstrRollup">
                            @forelse($grantedWithInstr as $tool)
                                <div class="border-bottom py-2">
                                    <code class="small">{{ $tool['name'] }}</code>
                                    <div class="small text-muted mt-1">{{ $toolInstructions[$tool['name']] }}</div>
                                </div>
                            @empty
                                <div class="text-muted small">No shared instructions on this token's granted tools yet.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== TRUST & SCOPE ===== --}}
    <div class="tab-pane fade" id="tab-trust" role="tabpanel">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card card-static shadow-sm">
                    <div class="card-header"><i class="bi bi-shield-check me-2"></i>Trust &amp; scope</div>
                    <div class="card-body">
                        <div class="d-flex gap-3 py-2 border-bottom">
                            <div class="form-check form-switch m-0 pt-1">
                                <input type="checkbox" class="form-check-input mcp-trust" id="flagAiActor" data-flag="ai_actor" @checked($token->ai_actor) @disabled($readOnly)>
                            </div>
                            <div>
                                <label for="flagAiActor" class="fw-semibold mb-0">Attribute actions to an AI <code class="small text-muted">ai_actor</code></label>
                                <div class="text-muted small">Notes, replies, and changes made with this token are recorded as performed by an AI assistant, not a staff member. Turn on for agent tokens so the audit trail and any client-facing attribution stay honest.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 py-2">
                            <div class="form-check form-switch m-0 pt-1">
                                <input type="checkbox" class="form-check-input mcp-trust" id="flagScope" data-flag="require_explicit_client_scope" @checked($token->require_explicit_client_scope) @disabled($readOnly)>
                            </div>
                            <div>
                                <label for="flagScope" class="fw-semibold mb-0">Require explicit client scope <code class="small text-muted">require_explicit_client_scope</code> <span class="badge bg-success-subtle text-success-emphasis border rounded-pill ms-1">Recommended</span></label>
                                <div class="text-muted small">Every tool call must name the client it acts on. The token can't read or act across all clients at once. Keep on for any token an agent drives.</div>
                            </div>
                        </div>
                        <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Saves automatically.</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card card-static shadow-sm">
                    <div class="card-header"><i class="bi bi-key me-2"></i>Credential</div>
                    <div class="card-body">
                        <div class="mb-2"><div class="form-label small fw-semibold mb-1">Server endpoint</div><code class="small">{{ url('/api/mcp/staff') }}</code></div>
                        <div class="mb-2"><div class="form-label small fw-semibold mb-1">Token prefix</div><code class="small">{{ $token->token_prefix ?? '—' }}</code></div>
                        <p class="text-muted small mb-0">The full secret is shown only once, at creation. If it is lost, discard this token and create a new one.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== ALERTS ===== --}}
    <div class="tab-pane fade" id="tab-alerts" role="tabpanel">
        <div class="card card-static shadow-sm" style="max-width:640px;">
            <div class="card-header"><i class="bi bi-bell me-2"></i>Alerts Hub Destinations</div>
            <div class="card-body">
                <p class="text-muted small">Signal destinations of type <strong>MCP</strong> linked to this token deliver alerts into its inbox for the agent to poll.</p>
                @forelse($linkedSignalDestinations as $destination)
                    <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2 bg-body-tertiary">
                        <span><i class="bi bi-bell me-2 text-secondary"></i>{{ $destination->label }}</span>
                        <form method="POST" action="{{ route('settings.mcp-tokens.signal-destinations.unlink', [$token, $destination]) }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm" title="Unlink" aria-label="Unlink"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                        </form>
                    </div>
                @empty
                    <div class="text-muted small mb-3">No MCP destinations linked.</div>
                @endforelse

                @if($availableSignalDestinations->isNotEmpty() && ! $readOnly)
                    <form method="POST" action="{{ route('settings.mcp-tokens.signal-destinations.link', $token) }}" class="mt-3">
                        @csrf
                        <div class="input-group">
                            <select name="signal_destination_id" class="form-select">
                                @foreach($availableSignalDestinations as $destination)
                                    <option value="{{ $destination->id }}">{{ $destination->label }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-outline-primary"><i class="bi bi-link-45deg me-1"></i>Link destination</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>

    {{-- ===== ACTIVITY ===== --}}
    <div class="tab-pane fade" id="tab-activity" role="tabpanel">
        <div class="card card-static shadow-sm">
            <div class="card-header d-flex align-items-center"><i class="bi bi-clock-history me-2"></i>Audit<span class="small text-muted ms-auto">Last 50 events</span></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="thead-brand"><tr><th>Time</th><th>Method</th><th>Tool</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($auditLogs as $log)
                            <tr>
                                <td class="small text-muted">{{ $log->created_at?->toAppTz()->format('Y-m-d H:i') }}</td>
                                <td><code class="small">{{ $log->method }}</code></td>
                                <td class="small">{{ $log->tool_name }}</td>
                                <td><span class="badge rounded-pill {{ $log->status === 'success' ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }} border">{{ $log->status }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted small">No audit rows.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="mcpToast"><i class="bi bi-check-circle me-1"></i><span id="mcpToastMsg">Saved</span></div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const cfg = {
        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        tools: @json(route('settings.mcp-tokens.tools', $token)),
        directive: @json(route('settings.mcp-tokens.directive', $token)),
        trust: @json(route('settings.mcp-tokens.trust-flags', $token)),
        rename: @json(route('settings.mcp-tokens.rename', $token)),
        instructions: @json(route('settings.mcp-tokens.tool-instructions')),
        readOnly: @json($readOnly),
    };
    // Full global instruction map, so a single edit re-saves the whole set
    // without wiping instructions for tools not shown on this page.
    let INSTRUCTIONS = @json((object) $toolInstructions);

    const root = document.querySelector('.mcp-cfg');
    if (!root) return;
    const toastEl = document.getElementById('mcpToast');
    const toastMsg = document.getElementById('mcpToastMsg');
    let toastTimer;
    function toast(msg, ok = true) {
        toastMsg.textContent = msg;
        toastEl.querySelector('.bi').className = ok ? 'bi bi-check-circle me-1' : 'bi bi-exclamation-circle me-1';
        toastEl.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toastEl.classList.remove('show'), 1900);
    }

    async function api(url, method, body) {
        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        let data = {};
        try { data = await res.json(); } catch (e) {}
        return { ok: res.ok, status: res.status, data };
    }
    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

    /* ---------- copy secret ---------- */
    const copyBtn = document.getElementById('mcpCopySecret');
    if (copyBtn) copyBtn.addEventListener('click', () => {
        const inp = document.getElementById('mcpNewToken');
        if (inp && navigator.clipboard) navigator.clipboard.writeText(inp.value).then(() => toast('Secret copied'));
    });

    if (cfg.readOnly) return; // revoked tokens are read-only

    /* ---------- rename in place ---------- */
    const labelInput = document.getElementById('mcpTokenLabel');
    const renameStatus = document.getElementById('mcpRenameStatus');
    let lastLabel = labelInput ? labelInput.value : '';
    async function saveLabel() {
        if (!labelInput) return;
        const val = labelInput.value.trim();
        if (val === '' || val === lastLabel) { labelInput.value = lastLabel; return; }
        const r = await api(cfg.rename, 'PATCH', { label: val });
        if (r.ok) { lastLabel = val; renameStatus.style.display = ''; setTimeout(() => renameStatus.style.display = 'none', 1500); toast('Name saved'); }
        else { labelInput.value = lastLabel; toast(r.data.message || 'Could not rename', false); }
    }
    if (labelInput) {
        document.getElementById('mcpRenameForm').addEventListener('submit', (e) => { e.preventDefault(); labelInput.blur(); });
        labelInput.addEventListener('blur', saveLabel);
        labelInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); labelInput.blur(); } });
    }

    /* ---------- grants (auto-save) ---------- */
    const groupsRoot = document.getElementById('mcpGroups');
    function grantedTools() { return [...groupsRoot.querySelectorAll('.tool-switch:checked')].map(s => s.dataset.tool); }
    const saveGrants = debounce(async () => {
        const r = await api(cfg.tools, 'PATCH', { tools: grantedTools() });
        if (r.ok) toast('Tool grants saved'); else toast('Could not save grants', false);
    }, 400);

    function setRowGranted(row, on) {
        row.dataset.granted = on ? '1' : '0';
        row.classList.toggle('granted', on);
        const instr = row.querySelector('.mcp-instr');
        if (instr) instr.style.display = on ? '' : 'none';
    }
    groupsRoot.addEventListener('change', (e) => {
        const sw = e.target.closest('.tool-switch');
        if (!sw) return;
        const row = sw.closest('.mcp-tool');
        if (sw.checked && sw.classList.contains('sensitive')) {
            if (!confirm('Grant the sensitive tool "' + sw.dataset.tool + '"?\n\nIt is ungranted by default. The agent will be able to act with it.')) {
                sw.checked = false; return;
            }
        }
        setRowGranted(row, sw.checked);
        updateCounts();
        saveGrants();
    });

    /* ---------- accordion ---------- */
    groupsRoot.addEventListener('click', (e) => {
        const head = e.target.closest('.mcp-group-head');
        if (head) { const g = head.closest('.mcp-group'); const open = g.classList.toggle('open'); head.setAttribute('aria-expanded', open ? 'true' : 'false'); return; }

        const instrToggle = e.target.closest('.mcp-instr-toggle');
        if (instrToggle) { const body = instrToggle.parentElement.querySelector('.mcp-instr-body'); body.style.display = body.style.display === 'none' ? '' : 'none'; return; }

        const bulk = e.target.closest('.mcp-bulk');
        if (bulk) {
            const tier = bulk.closest('.mcp-tier');
            const rows = [...tier.querySelectorAll('.mcp-tool')].filter(r => !r.classList.contains('hidden'));
            if (bulk.dataset.act === 'grant') {
                const targets = rows.filter(r => r.querySelector('.tool-switch:not(:checked)'));
                if (bulk.dataset.sensitive === '1' && targets.length && !confirm('Grant ' + targets.length + ' sensitive tool(s)?\n\nThey are ungranted by default. Grant deliberately.')) return;
                targets.forEach(r => { const s = r.querySelector('.tool-switch'); s.checked = true; setRowGranted(r, true); });
                if (targets.length) toast('Granted ' + targets.length + ' tool' + (targets.length === 1 ? '' : 's'));
            } else {
                const targets = rows.filter(r => r.querySelector('.tool-switch:checked'));
                targets.forEach(r => { const s = r.querySelector('.tool-switch'); s.checked = false; setRowGranted(r, false); });
                if (targets.length) toast('Revoked ' + targets.length + ' tool' + (targets.length === 1 ? '' : 's'));
            }
            updateCounts();
            saveGrants();
        }
    });

    document.getElementById('mcpExpandAll').addEventListener('click', () => groupsRoot.querySelectorAll('.mcp-group').forEach(g => { g.classList.add('open'); g.querySelector('.mcp-group-head').setAttribute('aria-expanded', 'true'); }));
    document.getElementById('mcpCollapseAll').addEventListener('click', () => groupsRoot.querySelectorAll('.mcp-group').forEach(g => { g.classList.remove('open'); g.querySelector('.mcp-group-head').setAttribute('aria-expanded', 'false'); }));

    /* ---------- counts ---------- */
    function updateCounts() {
        let granted = 0, sensitive = 0;
        groupsRoot.querySelectorAll('.mcp-group').forEach(g => {
            let gGr = 0;
            g.querySelectorAll('.mcp-tier').forEach(t => {
                let tGr = 0;
                t.querySelectorAll('.mcp-tool').forEach(r => { if (r.dataset.granted === '1') { tGr++; gGr++; granted++; if (r.dataset.tier === 'sensitive') sensitive++; } });
                const tc = t.querySelector('.tier-granted'); if (tc) tc.textContent = tGr;
            });
            const gc = g.querySelector('.group-granted'); if (gc) gc.textContent = gGr;
            const total = g.querySelectorAll('.mcp-tool').length;
            const fill = g.querySelector('.mcp-meter .fill'); if (fill) fill.style.width = (total ? Math.round(gGr / total * 100) : 0) + '%';
        });
        document.getElementById('sumGranted').textContent = granted;
        document.getElementById('sumSensitive').textContent = sensitive;
        document.getElementById('toolsTabCount').textContent = granted;
    }

    /* ---------- search / filter ---------- */
    let fGrant = 'all', fTier = 'all', fQuery = '';
    function applyFilter() {
        const q = fQuery.trim().toLowerCase();
        let any = false;
        groupsRoot.querySelectorAll('.mcp-group').forEach(g => {
            let gVis = 0;
            g.querySelectorAll('.mcp-tier').forEach(t => {
                let tVis = 0;
                t.querySelectorAll('.mcp-tool').forEach(r => {
                    let ok = true;
                    if (q && !(r.dataset.name.includes(q) || r.dataset.desc.includes(q))) ok = false;
                    if (fGrant === 'granted' && r.dataset.granted !== '1') ok = false;
                    if (fGrant === 'ungranted' && r.dataset.granted === '1') ok = false;
                    if (fTier !== 'all' && r.dataset.tier !== fTier) ok = false;
                    r.classList.toggle('hidden', !ok);
                    if (ok) { tVis++; gVis++; any = true; }
                });
                t.style.display = tVis ? '' : 'none';
            });
            g.classList.toggle('filtered-out', gVis === 0);
            if ((q || fGrant !== 'all' || fTier !== 'all') && gVis > 0) { g.classList.add('open'); g.querySelector('.mcp-group-head').setAttribute('aria-expanded', 'true'); }
        });
        const nr = document.getElementById('mcpNoResults');
        nr.classList.toggle('show', !any);
        document.getElementById('mcpNoQuery').textContent = q ? '"' + fQuery + '"' : 'these filters';
    }
    const search = document.getElementById('mcpToolSearch');
    if (search) search.addEventListener('input', () => { fQuery = search.value; applyFilter(); });
    root.querySelectorAll('.mcp-filter').forEach(grp => grp.addEventListener('click', (e) => {
        const btn = e.target.closest('button'); if (!btn) return;
        grp.querySelectorAll('button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        if (grp.dataset.filter === 'grant') fGrant = btn.dataset.val; else fTier = btn.dataset.val;
        applyFilter();
    }));

    /* ---------- directive (auto-save) ---------- */
    const directive = document.getElementById('mcpDirective');
    if (directive) {
        const saveDirective = debounce(async () => {
            const r = await api(cfg.directive, 'PATCH', { directive: directive.value });
            if (r.ok) toast('Directive saved'); else toast('Could not save directive', false);
        }, 700);
        directive.addEventListener('input', saveDirective);
    }

    /* ---------- trust flags (auto-save) ---------- */
    root.querySelectorAll('.mcp-trust').forEach(sw => sw.addEventListener('change', async () => {
        const body = {};
        root.querySelectorAll('.mcp-trust').forEach(s => { body[s.dataset.flag] = s.checked ? 1 : 0; });
        const r = await api(cfg.trust, 'PATCH', body);
        if (r.ok) toast('Trust controls saved'); else toast('Could not save', false);
    }));

    /* ---------- shared instructions (auto-save whole map) ---------- */
    const saveInstructions = debounce(async (changedTool) => {
        const map = Object.assign({}, INSTRUCTIONS);
        groupsRoot.querySelectorAll('.tool-instr-input').forEach(t => {
            const v = t.value.trim();
            if (v === '') delete map[t.dataset.tool]; else map[t.dataset.tool] = v;
        });
        const r = await api(cfg.instructions, 'PATCH', { tool_instructions: map });
        if (r.ok) { INSTRUCTIONS = map; toast('Shared instruction saved'); }
        else toast('Could not save instruction', false);
    }, 700);
    groupsRoot.addEventListener('input', (e) => {
        const ta = e.target.closest('.tool-instr-input');
        if (!ta) return;
        const flag = ta.closest('.mcp-tool').querySelector('.tool-instr-flag');
        if (flag) flag.style.display = ta.value.trim() === '' ? 'none' : '';
        saveInstructions(ta.dataset.tool);
    });
})();
</script>
@endpush
