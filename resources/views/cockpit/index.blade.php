@extends('layouts.app')

@section('title', 'Cockpit')

@php
    $replyTypes = ['send_reply', 'propose_resolution', 'stage_email', 'stage_public_note'];
    $closureTypes = ['propose_close', 'propose_merge'];
    $isAccountAction = fn (string $type): bool => str_starts_with($type, 'tactical_stage_') || str_starts_with($type, 'cipp_stage_');

    $replyDrafts = $drafts->filter(fn ($run) => in_array($run->action_type, $replyTypes, true));
    $closureDrafts = $drafts->filter(fn ($run) => in_array($run->action_type, $closureTypes, true));
    $actionDrafts = $drafts->filter(fn ($run) => $isAccountAction($run->action_type));
    $initialSummary = "{$counts['replies']} replies · {$counts['closures']} closures · {$counts['actions']} actions · {$counts['intake']} intake · {$counts['flagged']} flagged · {$counts['needs']} need you";

    $badgeFor = function ($run): array {
        return match ($run->action_type) {
            'propose_close' => ['bg-warning-subtle text-warning-emphasis border border-warning-subtle', 'Proposed close', 'bi-archive'],
            'propose_merge' => ['bg-warning-subtle text-warning-emphasis border border-warning-subtle', 'Proposed merge', 'bi-intersect'],
            'propose_resolution' => ['bg-info-subtle text-info-emphasis border border-info-subtle', 'Proposed resolution', 'bi-send'],
            'stage_email' => ['bg-success-subtle text-success-emphasis border border-success-subtle', 'Staged email', 'bi-envelope'],
            'stage_public_note' => ['bg-info-subtle text-info-emphasis border border-info-subtle', 'Staged public note', 'bi-journal-text'],
            'tactical_stage_script' => ['bg-danger text-white', 'Tactical script', 'bi-terminal'],
            'tactical_stage_command' => ['bg-danger text-white', 'Tactical command', 'bi-terminal'],
            'tactical_stage_reboot' => ['bg-danger text-white', 'Tactical reboot', 'bi-arrow-clockwise'],
            'tactical_stage_shutdown' => ['bg-danger text-white', 'Tactical shutdown', 'bi-power'],
            'tactical_stage_recover_mesh' => ['bg-danger text-white', 'Tactical recovery', 'bi-tools'],
            'tactical_stage_maintenance' => ['bg-danger text-white', 'Tactical maintenance', 'bi-wrench-adjustable'],
            'tactical_stage_stop_service' => ['bg-danger text-white', 'Tactical stop service', 'bi-stop-circle'],
            'tactical_stage_restart_service' => ['bg-danger text-white', 'Tactical restart service', 'bi-arrow-repeat'],
            'tactical_stage_install_approved_patches' => ['bg-danger text-white', 'Tactical patch install', 'bi-cloud-download'],
            'tactical_stage_reset_patch_policies' => ['bg-danger text-white', 'Tactical policy reset', 'bi-shield-exclamation'],
            'tactical_stage_run_policy_task_all' => ['bg-danger text-white', 'Tactical policy task', 'bi-broadcast-pin'],
            'cipp_stage_disable_user_sign_in' => ['bg-danger text-white', 'CIPP disable sign-in', 'bi-person-lock'],
            'cipp_stage_enable_user_sign_in' => ['bg-danger text-white', 'CIPP enable sign-in', 'bi-person-check'],
            'cipp_stage_revoke_user_sessions' => ['bg-danger text-white', 'CIPP revoke sessions', 'bi-key'],
            'cipp_stage_remove_user_mfa_methods' => ['bg-danger text-white', 'CIPP remove MFA', 'bi-shield-x'],
            'cipp_stage_set_legacy_per_user_mfa' => ['bg-danger text-white', 'CIPP legacy MFA', 'bi-shield-lock'],
            'cipp_stage_assign_user_license' => ['bg-danger text-white', 'CIPP assign license', 'bi-person-badge'],
            'cipp_stage_remove_user_license' => ['bg-danger text-white', 'CIPP remove license', 'bi-person-dash'],
            'cipp_stage_convert_mailbox' => ['bg-danger text-white', 'CIPP mailbox convert', 'bi-envelope-gear'],
            'cipp_stage_set_mailbox_forwarding' => ['bg-danger text-white', 'CIPP mailbox forwarding', 'bi-envelope-arrow-up'],
            'cipp_stage_set_mailbox_gal_visibility' => ['bg-danger text-white', 'CIPP GAL visibility', 'bi-eye'],
            'cipp_stage_set_mailbox_out_of_office' => ['bg-danger text-white', 'CIPP out of office', 'bi-calendar2-week'],
            'cipp_stage_set_mailbox_delegate' => ['bg-danger text-white', 'CIPP mailbox delegate', 'bi-people'],
            'cipp_stage_remove_directory_role' => ['bg-danger text-white', 'CIPP directory role removal', 'bi-person-x'],
            'cipp_stage_release_quarantine_message' => ['bg-danger text-white', 'CIPP quarantine release', 'bi-envelope-check'],
            'cipp_stage_add_tenant_allow_entry' => ['bg-danger text-white', 'CIPP tenant allow-list', 'bi-shield-plus'],
            'cipp_stage_wipe_device' => ['bg-danger text-white', 'CIPP device wipe', 'bi-device-hdd'],
            'cipp_stage_reassign_onedrive' => ['bg-danger text-white', 'CIPP OneDrive handover', 'bi-cloud-arrow-up'],
            'cipp_stage_create_user' => ['bg-danger text-white', 'CIPP create user', 'bi-person-plus'],
            default => ['bg-primary-subtle text-primary-emphasis border border-primary-subtle', 'Reply', 'bi-send'],
        };
    };

    $confidenceClass = function ($run): string {
        $confidence = (float) ($run->confidence ?? data_get($run->proposed_meta, 'confidence', 0));

        return $confidence >= 0.85 ? 'success' : ($confidence >= 0.7 ? 'warning' : 'danger');
    };

    $confidenceLabel = function ($run): ?string {
        $confidence = $run->confidence ?? data_get($run->proposed_meta, 'confidence');

        return $confidence === null ? null : number_format((float) $confidence * 100, 0).'%';
    };

    $reasons = fn ($run): string => implode(' · ', array_filter((array) data_get($run->proposed_meta, 'reasons', [])));
@endphp

@section('content')
<div
    class="cockpit-shell"
    x-data="cockpitQueue({ counts: @js($counts), csrf: @js(csrf_token()) })"
    @submit.capture="submit($event)"
    @click="handleClick($event)"
    @click.window="disarmFromOutside($event)"
    @keydown.window="key($event)"
>
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1"><i class="bi bi-robot me-2"></i>Cockpit</h1>
            <div class="text-muted small" x-text="summaryText()">{{ $initialSummary }}</div>
        </div>
        <button class="btn btn-outline-secondary btn-sm" type="button" @click="showHelp = true" title="Keyboard shortcuts">
            <i class="bi bi-keyboard me-1"></i>Shortcuts
        </button>
    </div>

    <div class="cockpit-filter-strip sticky-top bg-brand-light py-2 mb-3">
        <div class="d-flex gap-2 overflow-auto pb-1">
            @foreach ([
                'all' => ['All', 'total'],
                'replies' => ['Replies', 'replies'],
                'closures' => ['Closures', 'closures'],
                'actions' => ['Actions', 'actions'],
                'intake' => ['Intake', 'intake'],
                'flagged' => ['Flagged', 'flagged'],
                'needs' => ['Needs you', 'needs'],
            ] as $filterKey => [$label, $countKey])
                <button
                    type="button"
                    class="btn btn-sm rounded-pill cockpit-filter"
                    :class="filter === '{{ $filterKey }}' ? 'btn-primary' : 'btn-light border'"
                    @click="setFilter('{{ $filterKey }}')"
                >
                    {{ $label }}
                    <span class="badge rounded-pill ms-1" :class="filter === '{{ $filterKey }}' ? 'text-bg-light' : 'text-bg-secondary'" x-text="counts.{{ $countKey }}">{{ $counts[$countKey] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="cockpit-all-clear text-center py-5" x-cloak x-show="counts.total === 0">
        <div class="cockpit-clear-mark mx-auto mb-3"><i class="bi bi-check2"></i></div>
        <h2 class="h5 mb-1">You're all clear</h2>
        <p class="text-muted mb-0">New proposals from the AI Technician will appear here when they are staged.</p>
    </div>

    {{-- psa-3q0c (psa-rmus FIX 2): correction-driven "left as-is" outcomes. When you decline +
         correct a proposal and the re-assessment decides to leave the ticket as-is, the old card is
         superseded — so this small note confirms your correction WAS acted on (re-assessed → left
         as-is, with the reason) instead of the card silently vanishing. Informational: it sits
         outside the counts-driven filter strip and self-clears after 48h. --}}
    @if($reassessedLeftAsIs->isNotEmpty())
        <section class="cockpit-section mb-4" data-section-key="reassessed-left-as-is">
            <div class="cockpit-section-head">
                <h2><i class="bi bi-arrow-repeat me-2"></i>Re-assessed from your correction</h2>
                <span class="badge rounded-pill text-bg-light border">{{ $reassessedLeftAsIs->count() }}</span>
            </div>
            <div class="vstack gap-2">
                @foreach ($reassessedLeftAsIs as $outcome)
                    <a href="{{ route('tickets.show', $outcome->ticket->id) }}" class="card cockpit-needs-link text-decoration-none text-reset">
                        <div class="card-body py-2 small">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span class="fw-semibold">{{ $outcome->ticket->subject ?? 'Ticket #'.$outcome->ticket->id }}</span>
                                @if($outcome->ticket->client)<span class="badge rounded-pill bg-light text-dark border">{{ $outcome->ticket->client->name }}</span>@endif
                                <span class="ms-auto text-muted">{{ optional($outcome->at)->diffForHumans() }}</span>
                            </div>
                            <div class="text-muted">{{ $outcome->note }}</div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section class="cockpit-section mb-4" data-section-key="replies" x-show="visible('replies')" :class="{ 'cockpit-section-empty': counts.replies === 0 }">
        <div class="cockpit-section-head">
            <h2><i class="bi bi-envelope me-2"></i>Replies &amp; sends</h2>
            <span class="badge rounded-pill text-bg-light border" x-text="counts.replies">{{ $counts['replies'] }}</span>
        </div>
        <div class="vstack gap-3">
            @foreach ($replyDrafts as $run)
                @php($badge = $badgeFor($run))
                @php($sendLabel = $run->action_type === 'stage_public_note' ? 'Publish public note' : ($run->action_type === 'stage_email' ? 'Send email' : 'Send this'))
                @php($sendIcon = $run->action_type === 'stage_public_note' ? 'bi-journal-text' : 'bi-send')
                <article class="card cockpit-item" data-cockpit-item data-section="replies" data-label="{{ e(optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id) }}">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 small">
                            <a href="{{ route('tickets.show', $run->ticket_id) }}" class="fw-semibold text-decoration-none">
                                {{ optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id }}
                            </a>
                            @if($run->ticket?->client)
                                <span class="badge rounded-pill bg-light text-dark border">{{ $run->ticket->client->name }}</span>
                            @endif
                            <span class="badge rounded-pill {{ $badge[0] }}"><i class="bi {{ $badge[2] }} me-1"></i>{{ $badge[1] }}</span>
                            <span class="ms-auto text-muted">{{ optional($run->created_at)->diffForHumans() }}</span>
                        </div>

                        @php($bodyLabel = $run->action_type === 'stage_public_note' ? 'Public note (edit before publishing)' : 'Message to the client (edit before sending)')
                        <label class="form-label small text-muted mb-1" for="body-{{ $run->id }}">{{ $bodyLabel }}</label>
                        <textarea class="form-control mb-2" id="body-{{ $run->id }}" name="body" rows="4" form="approve-{{ $run->id }}">{{ $run->proposed_content }}</textarea>
                        <p class="text-muted small mb-2">
                            <i class="bi bi-info-circle me-1"></i>A disclosure line ("— Sent by {{ \App\Support\TechnicianConfig::aiActorName() }}, an AI assistant for our team.") is added automatically.
                        </p>

                        @if($reasonText = $reasons($run))
                            <p class="text-muted small mb-2">Why: {{ $reasonText }}@if($confidenceLabel($run)) (confidence {{ $confidenceLabel($run) }})@endif</p>
                        @elseif($confidenceLabel($run))
                            <p class="text-muted small mb-2">Confidence: {{ $confidenceLabel($run) }}</p>
                        @endif
                        @if(!empty($run->proposed_meta['drafted_by']))
                            <p class="text-muted small mb-2">Drafted by: {{ $run->proposed_meta['drafted_by'] }}</p>
                        @endif

                        @php($rv = $recipientViews[$run->id] ?? null)
                        @if($rv && in_array($run->action_type, ['send_reply', 'stage_email', 'propose_resolution'], true))
                            <div class="border rounded p-2 mb-2 bg-body-tertiary" x-data="cockpitRecipients(@js($rv))">
                                <div class="input-group input-group-sm mb-2">
                                    <span class="input-group-text">To</span>
                                    <input type="text" class="form-control" x-model="to" aria-label="To recipient">
                                    <input type="hidden" name="to[]" :value="to" form="approve-{{ $run->id }}">
                                </div>
                                <div class="d-flex flex-wrap gap-1 align-items-center mb-2">
                                    <span class="text-muted small me-1">Cc:</span>
                                    <template x-for="(addr, i) in cc" :key="i">
                                        <span class="badge text-bg-secondary d-inline-flex align-items-center gap-1">
                                            <span x-text="addr"></span>
                                            <input type="hidden" name="cc[]" :value="addr" form="approve-{{ $run->id }}">
                                            <button type="button" class="btn-close btn-close-white" style="font-size:.5rem" @click="cc.splice(i, 1)" aria-label="Remove"></button>
                                        </span>
                                    </template>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0" @click="cc = [...replyAll]">
                                        <i class="bi bi-reply-all me-1"></i>Reply all
                                    </button>
                                </div>
                                <div class="input-group input-group-sm">
                                    <select class="form-select" x-ref="pick" aria-label="Add a recipient">
                                        <option value="">Add a recipient…</option>
                                        @foreach($rv['candidates'] as $cand)
                                            <option value="{{ $cand['email'] }}">{{ $cand['email'] }}@if(!empty($cand['name'])) ({{ $cand['name'] }})@endif — {{ $cand['source'] }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="btn btn-outline-secondary" @click="addCc($refs.pick.value); $refs.pick.value = ''">Add Cc</button>
                                </div>
                                <p class="text-muted mb-0 mt-1" style="font-size:.75rem"><i class="bi bi-shield-check me-1"></i>Only contacts and people already on this email thread can be added; recipients are re-checked when you approve.</p>
                            </div>
                        @endif

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <form id="approve-{{ $run->id }}" method="POST" action="{{ route('cockpit.approve', $run) }}" data-cockpit-form data-mode="confirmed" data-keybind="approve">
                                @csrf
                                <button type="submit" class="btn btn-success"><i class="bi {{ $sendIcon }} me-1"></i>{{ $sendLabel }}</button>
                            </form>
                            <form method="POST" action="{{ route('cockpit.deny', $run) }}" data-cockpit-form data-mode="optimistic" data-keybind="hold" data-undo-action="hold" data-target-type="run" data-target-id="{{ $run->id }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Hold it</button>
                            </form>
                        </div>

                        <details class="cockpit-correction mt-3 border-top pt-3">
                            <summary class="btn btn-link btn-sm text-decoration-none px-0" data-correction-toggle><i class="bi bi-arrow-repeat me-1"></i>Decline &amp; re-assess</summary>
                            @if(data_get($run->proposed_meta, 'informed_by_correction'))
                                <p class="text-muted small mb-2"><i class="bi bi-arrow-repeat me-1"></i>↻ Re-assessed from your correction.</p>
                            @endif
                            <form method="POST" action="{{ route('cockpit.correct', $run) }}" data-cockpit-form data-mode="confirmed" data-keybind="correct">
                                @csrf
                                <label class="form-label small text-muted mb-1" for="correction-{{ $run->id }}">What did it miss or get wrong?</label>
                                <textarea class="form-control form-control-sm mb-2" id="correction-{{ $run->id }}" name="correction" rows="2" maxlength="2000" required placeholder="The agent will re-assess this ticket with your note."></textarea>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-repeat me-1"></i>Re-assess with this note</button>
                            </form>
                        </details>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="cockpit-empty">No replies waiting.</div>
    </section>

    <section class="cockpit-section mb-4" data-section-key="closures" x-show="visible('closures')" :class="{ 'cockpit-section-empty': counts.closures === 0 }">
        <div class="cockpit-section-head">
            <h2><i class="bi bi-archive me-2"></i>Closures &amp; merges</h2>
            <span class="badge rounded-pill text-bg-light border" x-text="counts.closures">{{ $counts['closures'] }}</span>
        </div>
        <div class="vstack gap-2">
            @foreach ($closureDrafts as $run)
                @php($badge = $badgeFor($run))
                @php($isClose = $run->action_type === 'propose_close')
                @php($mergeMeta = $run->proposed_meta ?? [])
                <article class="card cockpit-item cockpit-row" data-cockpit-item data-section="closures" data-label="{{ e(optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id) }}">
                    <div class="card-body py-2">
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex flex-wrap align-items-center gap-2 small mb-1">
                                    <a href="{{ route('tickets.show', $run->ticket_id) }}" class="fw-semibold text-decoration-none">
                                        {{ optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id }}
                                    </a>
                                    @if($run->ticket?->client)
                                        <span class="badge rounded-pill bg-light text-dark border">{{ $run->ticket->client->name }}</span>
                                    @endif
                                    <span class="badge rounded-pill {{ $badge[0] }}"><i class="bi {{ $badge[2] }} me-1"></i>{{ $badge[1] }}</span>
                                </div>
                                <div class="text-muted small text-truncate">
                                    {{ $run->proposed_content }}
                                    @if(!$isClose)
                                        · Primary: {{ $mergeMeta['primary_display_id'] ?? $mergeMeta['primary_ticket_display_id'] ?? '#'.$run->ticket_id }}
                                        · Secondary: {{ $mergeMeta['secondary_display_id'] ?? $mergeMeta['secondary_ticket_display_id'] ?? '#'.($mergeMeta['secondary_ticket_id'] ?? '?') }}
                                    @endif
                                </div>
                            </div>
                            @if($confidenceLabel($run))
                                <span class="badge rounded-pill text-bg-{{ $confidenceClass($run) }}">{{ $confidenceLabel($run) }}</span>
                            @endif
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <form id="approve-{{ $run->id }}" method="POST" action="{{ route('cockpit.approve', $run) }}" data-cockpit-form data-mode="{{ $isClose ? 'optimistic' : 'confirmed' }}" data-keybind="approve" data-arm="true" @if($isClose) data-undo-action="approve-close" data-target-type="run" data-target-id="{{ $run->id }}" @endif>
                                    @csrf
                                    <button type="submit" class="btn btn-sm {{ $isClose ? 'btn-accent' : 'btn-primary' }}"><i class="bi {{ $isClose ? 'bi-check2' : 'bi-intersect' }} me-1"></i>{{ $isClose ? 'Close' : 'Approve merge' }}</button>
                                </form>
                                <form method="POST" action="{{ route('cockpit.deny', $run) }}" data-cockpit-form data-mode="optimistic" data-keybind="hold" data-undo-action="hold" data-target-type="run" data-target-id="{{ $run->id }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Hold it</button>
                                </form>
                            </div>
                        </div>
                        <details class="cockpit-correction mt-2 border-top pt-2">
                            <summary class="btn btn-link btn-sm text-decoration-none px-0 py-1" data-correction-toggle><i class="bi bi-arrow-repeat me-1"></i>Decline &amp; re-assess</summary>
                            <form method="POST" action="{{ route('cockpit.correct', $run) }}" data-cockpit-form data-mode="confirmed" data-keybind="correct">
                                @csrf
                                <label class="form-label small text-muted mb-1" for="correction-{{ $run->id }}">What did it miss or get wrong?</label>
                                <textarea class="form-control form-control-sm mb-2" id="correction-{{ $run->id }}" name="correction" rows="2" maxlength="2000" required placeholder="The agent will re-assess this ticket with your note."></textarea>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-repeat me-1"></i>Re-assess with this note</button>
                            </form>
                        </details>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="cockpit-empty">Stack cleared. No closures left.</div>
    </section>

    <section class="cockpit-section mb-4" data-section-key="actions" x-show="visible('actions')" :class="{ 'cockpit-section-empty': counts.actions === 0 }">
        <div class="cockpit-section-head cockpit-section-head-danger">
            <h2><i class="bi bi-shield-exclamation me-2"></i>Endpoint &amp; account actions</h2>
            <span class="badge rounded-pill text-bg-danger" x-text="counts.actions">{{ $counts['actions'] }}</span>
        </div>
        <div class="vstack gap-3">
            @foreach ($actionDrafts as $run)
                @php($badge = $badgeFor($run))
                @php($cippInputs = (array)($run->proposed_meta['sensitive_inputs'] ?? []))
                <article class="card cockpit-item cockpit-action-card" data-cockpit-item data-section="actions" data-label="{{ e(optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id) }}">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 small">
                            <a href="{{ route('tickets.show', $run->ticket_id) }}" class="fw-semibold text-decoration-none">
                                {{ optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id }}
                            </a>
                            @if($run->ticket?->client)
                                <span class="badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle">{{ $run->ticket->client->name }}</span>
                            @endif
                            <span class="badge rounded-pill {{ $badge[0] }}"><i class="bi {{ $badge[2] }} me-1"></i>{{ $badge[1] }}</span>
                            <span class="ms-auto text-muted">{{ optional($run->created_at)->diffForHumans() }}</span>
                        </div>

                        <p class="small text-danger-emphasis fw-semibold mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Executes on the endpoint or account when approved</p>
                        <pre class="cockpit-readout mb-2">{{ $run->proposed_content }}</pre>
                        @if(!empty($run->proposed_meta['drafted_by']))
                            <p class="text-muted small mb-2">Drafted by: {{ $run->proposed_meta['drafted_by'] }}</p>
                        @endif

                        @if(in_array('external_smtp', $cippInputs, true))
                            <label class="form-label small text-muted mb-1" for="external-smtp-{{ $run->id }}">External SMTP address</label>
                            <input class="form-control mb-2" id="external-smtp-{{ $run->id }}" name="external_smtp" type="email" form="approve-{{ $run->id }}" required>
                        @endif
                        @if(in_array('internal_message', $cippInputs, true))
                            <label class="form-label small text-muted mb-1" for="internal-message-{{ $run->id }}">Internal auto-reply message</label>
                            <textarea class="form-control mb-2" id="internal-message-{{ $run->id }}" name="internal_message" rows="3" maxlength="2000" form="approve-{{ $run->id }}" required></textarea>
                        @endif
                        @if(in_array('external_message', $cippInputs, true))
                            <label class="form-label small text-muted mb-1" for="external-message-{{ $run->id }}">External auto-reply message</label>
                            <textarea class="form-control mb-2" id="external-message-{{ $run->id }}" name="external_message" rows="3" maxlength="2000" form="approve-{{ $run->id }}" required></textarea>
                        @endif
                        @if(in_array('confirm_device_id', $cippInputs, true))
                            <label class="form-label small text-danger-emphasis fw-semibold mb-1" for="confirm-device-id-{{ $run->id }}">Type the exact Intune device ID from the readout to confirm this device action</label>
                            <input class="form-control mb-2" id="confirm-device-id-{{ $run->id }}" name="confirm_device_id" type="text" maxlength="64" form="approve-{{ $run->id }}" required autocomplete="off" spellcheck="false" placeholder="00000000-0000-0000-0000-000000000000">
                        @endif

                        @if($confidenceLabel($run))
                            <p class="text-muted small mb-2">Confidence: {{ $confidenceLabel($run) }}</p>
                        @endif

                        <div class="d-flex flex-wrap gap-2 align-items-center cockpit-action-bar rounded p-2">
                            <form id="approve-{{ $run->id }}" method="POST" action="{{ route('cockpit.approve', $run) }}" data-cockpit-form data-mode="confirmed" data-keybind="approve" data-arm="true">
                                @csrf
                                <button type="submit" class="btn btn-danger"><i class="bi bi-check2-circle me-1"></i>Approve action</button>
                            </form>
                            <form method="POST" action="{{ route('cockpit.deny', $run) }}" data-cockpit-form data-mode="optimistic" data-keybind="hold" data-undo-action="hold" data-target-type="run" data-target-id="{{ $run->id }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Hold it</button>
                            </form>
                            </div>

                        <details class="cockpit-correction mt-3 border-top pt-3">
                            <summary class="btn btn-link btn-sm text-decoration-none px-0" data-correction-toggle><i class="bi bi-arrow-repeat me-1"></i>Decline &amp; re-assess</summary>
                            <form method="POST" action="{{ route('cockpit.correct', $run) }}" data-cockpit-form data-mode="confirmed" data-keybind="correct">
                                @csrf
                                <label class="form-label small text-muted mb-1" for="correction-{{ $run->id }}">What did it miss or get wrong?</label>
                                <textarea class="form-control form-control-sm mb-2" id="correction-{{ $run->id }}" name="correction" rows="2" maxlength="2000" required placeholder="The agent will re-assess this ticket with your note."></textarea>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-repeat me-1"></i>Re-assess with this note</button>
                            </form>
                        </details>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="cockpit-empty">No endpoint or account actions staged.</div>
    </section>

    <section class="cockpit-section mb-4" data-section-key="intake" x-show="visible('intake')" :class="{ 'cockpit-section-empty': counts.intake === 0 }">
        <div class="cockpit-section-head">
            <h2><i class="bi bi-inbox me-2"></i>Intake: calls &amp; prospects</h2>
            <span class="badge rounded-pill text-bg-light border" x-text="counts.intake">{{ $counts['intake'] }}</span>
        </div>
        <div class="vstack gap-2">
            @foreach ($intake as $run)
                @php($meta = $run->proposed_meta ?? [])
                @php($isCall = ($meta['source'] ?? null) === 'call')
                <article class="card cockpit-item cockpit-row" data-cockpit-item data-section="intake" data-label="{{ $isCall ? 'Call intake' : 'New ticket intake' }}">
                    <div class="card-body py-2">
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold small">{{ $isCall ? '📞 Call → ticket' : 'New ticket' }} @if($run->ticket_id)<a href="{{ route('tickets.show', $run->ticket_id) }}" class="text-decoration-none">#{{ $run->ticket_id }}</a>@else<span>#?</span>@endif looks like open ticket @if(! empty($meta['suggested_ticket_id']))<a href="{{ route('tickets.show', $meta['suggested_ticket_id']) }}" class="text-decoration-none">#{{ $meta['suggested_ticket_id'] }}</a>@else<span>#?</span>@endif</div>
                                <div class="text-muted small text-truncate">{{ $run->proposed_content }} @if(isset($meta['confidence']))({{ (int) round(((float) $meta['confidence']) * 100) }}% confidence)@endif</div>
                            </div>
                            <form method="POST" action="{{ route('cockpit.intake-dismiss', $run) }}" data-cockpit-form data-mode="optimistic" data-keybind="hold" data-undo-action="dismiss-intake" data-target-type="run" data-target-id="{{ $run->id }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary">Dismiss</button>
                            </form>
                        </div>
                    </div>
                </article>
            @endforeach

            @foreach ($intakeSpam as $call)
                <article class="card cockpit-item cockpit-row" data-cockpit-item data-section="intake" data-label="spam call {{ $call->from_number }}">
                    <div class="card-body py-2">
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold small"><a href="{{ route('calls.show', $call) }}" class="text-decoration-none">Call from {{ $call->from_number }}</a> looks like spam <span class="badge rounded-pill text-bg-danger">{{ (int) round(($call->intake_spam_score ?? 0) * 100) }}%</span></div>
                                <div class="text-muted small text-truncate">{{ \Illuminate\Support\Str::limit($call->call_summary, 200) }}</div>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('cockpit.intake-spam-block', $call) }}" data-cockpit-form data-mode="optimistic" data-keybind="approve" data-arm="true" data-undo-action="block-number" data-target-type="call" data-target-id="{{ $call->id }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-shield-x me-1"></i>Block number</button>
                                </form>
                                <form method="POST" action="{{ route('prospects.dismiss', $call) }}" data-cockpit-form data-mode="optimistic" data-keybind="hold" data-undo-action="not-spam" data-target-type="call" data-target-id="{{ $call->id }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-secondary">Not spam</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="cockpit-empty">Intake queue is clear.</div>
    </section>

    <section class="cockpit-section mb-4" data-section-key="flagged" x-show="visible('flagged')" :class="{ 'cockpit-section-empty': counts.flagged === 0 }">
        <div class="cockpit-section-head">
            <h2><i class="bi bi-flag me-2"></i>Flagged for your attention</h2>
            <span class="badge rounded-pill text-bg-light border" x-text="counts.flagged">{{ $counts['flagged'] }}</span>
        </div>
        <div class="vstack gap-3">
            @foreach ($flagged as $run)
                <article class="card cockpit-item" data-cockpit-item data-section="flagged" data-label="{{ e(optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id) }}">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
                            <a href="{{ route('tickets.show', $run->ticket_id) }}" class="fw-semibold text-decoration-none">
                                {{ optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id }}
                            </a>
                            @if($run->ticket?->client)
                                <span class="badge rounded-pill bg-light text-dark border">{{ $run->ticket->client->name }}</span>
                            @endif
                            @php($flagMeta = $run->proposed_meta ?? [])
                            @php($flagCategory = \App\Enums\FlagAttentionCategory::fromInput($flagMeta['category'] ?? null))
                            @php($suppressedEscalation = ($flagMeta['escalation']['status'] ?? null) === 'suppressed')
                            <span class="badge rounded-pill bg-warning text-dark">{{ $flagCategory->label() }}</span>
                            @if($suppressedEscalation)
                                <span class="badge rounded-pill bg-info text-dark">Not re-pinged</span>
                            @endif
                            <span class="ms-auto text-muted">{{ optional($run->created_at)->diffForHumans() }}</span>
                        </div>
                        <p class="text-muted small mb-1">Why the assistant flagged this (it took no action on the ticket):</p>
                        <p class="form-control-plaintext border rounded p-2 mb-2 bg-light small">{{ $run->proposed_content }}</p>
                        @if($suppressedEscalation)
                            <p class="text-muted small mb-2">
                                Not re-pinged: {{ $flagMeta['escalation']['suppression_reason'] ?? 'same client already has human attention' }}
                            </p>
                        @endif
                        <div class="d-flex gap-2">
                            <form method="POST" action="{{ route('cockpit.acknowledge', $run) }}" data-cockpit-form data-mode="optimistic" data-keybind="approve" data-undo-action="ack-flag" data-target-type="run" data-target-id="{{ $run->id }}">
                                @csrf
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>I’ve got it</button>
                            </form>
                            <form method="POST" action="{{ route('cockpit.dismiss', $run) }}" data-cockpit-form data-mode="optimistic" data-keybind="hold" data-undo-action="dismiss-flag" data-target-type="run" data-target-id="{{ $run->id }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Dismiss</button>
                            </form>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="cockpit-empty">No flags right now.</div>
    </section>

    {{-- bd psa-xr84: offline-script queue. An approved staged action whose device was
         offline parks here and auto-runs on reconnect; if its safety window elapses
         first, it moves to the Expired lane below for an explicit re-confirm. Both
         sections render only when they have something to show — there is nothing to
         filter into, so they sit outside the counts-driven filter strip above. --}}
    @if($queued->isNotEmpty())
        <section class="cockpit-section mb-4" data-section-key="queued">
            <div class="cockpit-section-head">
                <h2><i class="bi bi-hourglass-split me-2"></i>Queued — waiting for device</h2>
                <span class="badge rounded-pill text-bg-light border">{{ $queued->count() }}</span>
            </div>
            <div class="vstack gap-3">
                @foreach ($queued as $run)
                    @php($badge = $badgeFor($run))
                    @php($deviceName = $run->proposed_meta['asset_hostname'] ?? $run->queued_agent_id ?? 'the device')
                    <article class="card cockpit-item">
                        <div class="card-body">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
                                <a href="{{ route('tickets.show', $run->ticket_id) }}" class="fw-semibold text-decoration-none">
                                    {{ optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id }}
                                </a>
                                @if($run->ticket?->client)
                                    <span class="badge rounded-pill bg-light text-dark border">{{ $run->ticket->client->name }}</span>
                                @endif
                                <span class="badge rounded-pill {{ $badge[0] }}"><i class="bi {{ $badge[2] }} me-1"></i>{{ $badge[1] }}</span>
                            </div>
                            <p class="text-muted small mb-2">
                                <i class="bi bi-hourglass-split me-1"></i>
                                Queued — waiting for {{ $deviceName }} to come online · expires {{ optional($run->expires_at)->diffForHumans() }}
                                @if($run->coalesce_count > 0)
                                    <span class="text-muted">(+{{ $run->coalesce_count }} duplicate approvals)</span>
                                @endif
                            </p>
                            <form method="POST" action="{{ route('cockpit.cancel', $run) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if($expired->isNotEmpty())
        <section class="cockpit-section mb-4" data-section-key="expired-queue">
            <div class="cockpit-section-head">
                <h2><i class="bi bi-exclamation-octagon me-2"></i>Expired — needs re-confirm</h2>
                <span class="badge rounded-pill text-bg-light border">{{ $expired->count() }}</span>
            </div>
            <div class="vstack gap-3">
                @foreach ($expired as $run)
                    @php($badge = $badgeFor($run))
                    @php($deviceName = $run->proposed_meta['asset_hostname'] ?? $run->queued_agent_id ?? 'the device')
                    <article class="card cockpit-item">
                        <div class="card-body">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
                                <a href="{{ route('tickets.show', $run->ticket_id) }}" class="fw-semibold text-decoration-none">
                                    {{ optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id }}
                                </a>
                                @if($run->ticket?->client)
                                    <span class="badge rounded-pill bg-light text-dark border">{{ $run->ticket->client->name }}</span>
                                @endif
                                <span class="badge rounded-pill {{ $badge[0] }}"><i class="bi {{ $badge[2] }} me-1"></i>{{ $badge[1] }}</span>
                            </div>
                            <p class="text-muted small mb-2">
                                <i class="bi bi-exclamation-octagon me-1"></i>
                                This never auto-ran because {{ $deviceName }} stayed offline past the safety window &mdash; re-confirm to send it back to the approval queue.
                            </p>
                            <form method="POST" action="{{ route('cockpit.reconfirm', $run) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-clockwise me-1"></i>Re-confirm</button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section class="cockpit-section mb-4" data-section-key="needs" x-show="visible('needs')" :class="{ 'cockpit-section-empty': counts.needs === 0 }">
        <div class="cockpit-section-head">
            <h2><i class="bi bi-exclamation-circle me-2"></i>Needs you</h2>
            <span class="badge rounded-pill text-bg-light border" x-text="counts.needs">{{ $counts['needs'] }}</span>
        </div>
        <div class="vstack gap-2">
            @foreach ($needs as $ticket)
                <a href="{{ route('tickets.show', $ticket->id) }}" class="card cockpit-needs-link text-decoration-none text-reset">
                    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2 small">
                        <span class="fw-semibold">{{ $ticket->subject }}</span>
                        @if($ticket->client)<span class="badge rounded-pill bg-light text-dark border">{{ $ticket->client->name }}</span>@endif
                        <span class="ms-auto text-muted">{{ optional($ticket->updated_at)->diffForHumans() }}</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="cockpit-empty">Nothing here.</div>
    </section>

    <div class="cockpit-toasts" aria-live="polite" aria-atomic="false">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="cockpit-toast" :class="'cockpit-toast-' + toast.kind">
                <i class="bi" :class="toast.kind === 'error' ? 'bi-exclamation-triangle' : (toast.kind === 'info' ? 'bi-arrow-repeat' : 'bi-check2-circle')"></i>
                <div class="flex-grow-1">
                    <span x-text="toast.message"></span>
                    {{-- One-time secret readout (e.g. a created user's temp password): shown
                         exactly once, never persisted anywhere — so this toast never
                         auto-dismisses and offers a copy affordance. --}}
                    <div x-show="!!toast.secret" class="d-flex align-items-center gap-2 mt-2">
                        <code class="cockpit-toast-secret user-select-all" x-text="toast.secret"></code>
                        <button type="button" class="btn btn-sm btn-light" @click="copyToastSecret(toast, $event)">Copy</button>
                    </div>
                </div>
                <button x-show="!!toast.undo" type="button" class="btn btn-sm btn-light" @click="toast.undo()">Undo</button>
                <button type="button" class="btn btn-sm btn-link text-white p-0" @click="dismissToast(toast.id)" aria-label="Dismiss"><i class="bi bi-x-lg"></i></button>
            </div>
        </template>
    </div>

    <div class="modal fade" tabindex="-1" x-cloak :class="{ 'show d-block': showHelp }" @click.self="showHelp = false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6">Keyboard Shortcuts</h2>
                    <button type="button" class="btn-close" @click="showHelp = false" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>Move selection</span><span><kbd>j</kbd> <kbd>k</kbd></span></div>
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>Primary action</span><kbd>a</kbd></div>
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>Hold or dismiss</span><kbd>h</kbd></div>
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>Correction note</span><kbd>c</kbd></div>
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>Undo last action</span><kbd>u</kbd></div>
                    <div class="d-flex justify-content-between py-2"><span>Jump sections</span><span><kbd>1</kbd>…<kbd>6</kbd></span></div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-backdrop fade show" x-cloak x-show="showHelp"></div>
</div>
@endsection

@push('styles')
<style>
[x-cloak] { display: none !important; }
.cockpit-filter-strip { top: var(--topbar-height, 56px); z-index: 5; }
.cockpit-filter { white-space: nowrap; }
.cockpit-section-head { display: flex; align-items: center; gap: .5rem; margin: 0 0 .75rem; }
.cockpit-section-head h2 { font-size: .78rem; margin: 0; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
.cockpit-section-head-danger h2 { color: var(--bs-danger-text-emphasis); }
.cockpit-empty { display: none; border: 1px dashed var(--bs-border-color); border-radius: 8px; background: var(--bs-tertiary-bg); color: var(--text-muted); padding: 1rem; text-align: center; }
.cockpit-section-empty > .vstack { display: none !important; }
.cockpit-section-empty > .cockpit-empty { display: block; }
.cockpit-item { transition: opacity .2s ease, transform .2s ease, max-height .28s ease, margin .28s ease; overflow: hidden; }
.cockpit-item.is-selected { border-color: var(--accent); box-shadow: 0 0 0 .2rem rgba(254, 209, 54, .28) !important; }
.cockpit-item.is-leaving { opacity: 0; transform: translateX(12px); max-height: 0 !important; margin: 0 !important; border-width: 0; }
.cockpit-item.is-entering { animation: cockpit-enter .24s ease; }
.cockpit-item.is-shaking { animation: cockpit-shake .28s ease; }
.cockpit-row .card-body { min-height: 58px; }
.min-w-0 { min-width: 0; }
.cockpit-action-card { background: linear-gradient(0deg, rgba(220, 53, 69, .06), #fff 55%); border-color: rgba(220, 53, 69, .25); }
.cockpit-action-bar { background: rgba(220, 53, 69, .08); }
	.cockpit-readout { white-space: pre-wrap; font-size: .85rem; padding: .75rem; border: 1px solid var(--bs-border-color); border-radius: 8px; background: var(--bs-tertiary-bg); color: var(--text-body); }
	.cockpit-correction > summary { list-style: none; cursor: pointer; }
	.cockpit-correction > summary::-webkit-details-marker { display: none; }
	.cockpit-clear-mark { width: 52px; height: 52px; border-radius: 14px; display: grid; place-items: center; background: var(--accent); color: var(--primary-dark); font-size: 1.8rem; }
.cockpit-toasts { position: fixed; z-index: 1080; bottom: 1.25rem; left: 50%; transform: translateX(-50%); display: grid; gap: .5rem; width: min(440px, calc(100vw - 2rem)); }
.cockpit-toast { display: flex; align-items: center; gap: .75rem; padding: .75rem .9rem; border-radius: 10px; color: #fff; background: var(--primary); box-shadow: 0 8px 28px rgba(15, 36, 64, .22); }
.cockpit-toast-error { background: var(--bs-danger); }
.cockpit-toast-info { background: var(--primary-light); }
.cockpit-toast-secret { background: rgba(255, 255, 255, .18); color: #fff; padding: .15rem .45rem; border-radius: 6px; word-break: break-all; }
.btn.is-loading { pointer-events: none; opacity: .72; }
.btn.is-armed { background: var(--bs-danger) !important; border-color: var(--bs-danger) !important; color: #fff !important; }
@keyframes cockpit-enter { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
@keyframes cockpit-shake { 0%, 100% { transform: none; } 25% { transform: translateX(-4px); } 75% { transform: translateX(4px); } }
@media (prefers-reduced-motion: reduce) {
    .cockpit-item, .cockpit-item.is-entering, .cockpit-item.is-shaking { transition: none; animation: none; }
}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('alpine:init', function () {
    // psa-kt82 PR B: recipient block for send_reply/stage_email approval cards.
    Alpine.data('cockpitRecipients', function (rv) {
        return {
            to: (rv && rv.to && rv.to.email) ? rv.to.email : '',
            cc: [],
            replyAll: (rv && rv.reply_all) ? rv.reply_all : [],
            addCc(addr) {
                addr = (addr || '').trim();
                if (addr && addr !== this.to && !this.cc.includes(addr)) {
                    this.cc.push(addr);
                }
            },
        };
    });
    Alpine.data('cockpitQueue', function (config) {
        return {
            counts: config.counts,
            csrf: config.csrf,
            filter: 'all',
            selected: null,
            toasts: [],
            toastId: 1,
            lastUndo: null,
            lastUndoId: null,
            armedForm: null,
            armedTimer: null,
            showHelp: false,

            init() {
                this.$nextTick(() => this.selectFirst());
            },

            visible(section) {
                return this.filter === 'all' || this.filter === section;
            },

            setFilter(section) {
                this.filter = section;
                this.$nextTick(() => this.selectFirst());
            },

            summaryText() {
                return `${this.counts.replies} replies · ${this.counts.closures} closures · ${this.counts.actions} actions · ${this.counts.intake} intake · ${this.counts.flagged} flagged · ${this.counts.needs} need you`;
            },

            applyCounts(counts) {
                if (counts) this.counts = Object.assign({}, this.counts, counts);
            },

            async submit(event) {
                const form = event.target.closest('form[data-cockpit-form]');
                if (!form) return;
                event.preventDefault();

                if (form.dataset.arm === 'true' && !this.isArmed(form)) {
                    if (!form.checkValidity()) {
                        form.reportValidity();
                        return;
                    }
                    this.arm(form);
                    return;
                }

                const item = form.closest('[data-cockpit-item]');
                if (!item || item.classList.contains('is-leaving')) return;

                if (form.dataset.mode === 'optimistic') {
                    const restore = this.removeItem(item);
                    try {
                        const payload = await this.postForm(form);
                        if (!payload.ok) throw new Error(payload.message || 'Action failed');
                        this.applyCounts(payload.counts);
                        const undo = payload.undo?.url ? () => this.undo(payload.undo.url, restore, item) : null;
                        this.addToast('success', payload.message || 'Done.', undo);
                    } catch (error) {
                        restore();
                        this.addToast('error', 'That did not go through. Put back in the queue.');
                    }
                    return;
                }

                await this.confirmedSubmit(form, item);
            },

            async confirmedSubmit(form, item) {
                const button = form.querySelector('button[type="submit"]');
                const body = new FormData(form);
                this.setBusy(item, true);
                if (button) {
                    button.dataset.originalText = button.innerHTML;
                    button.classList.add('is-loading');
                    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Working';
                }

                try {
                    const payload = await this.postForm(form, body);
                    if (!payload.ok) throw new Error(payload.message || 'Action failed');
                    this.applyCounts(payload.counts);
                    this.removeItem(item);
                    this.addToast('success', payload.message || 'Done.', null, payload.secret || null);
                } catch (error) {
                    this.setBusy(item, false);
                    if (button) {
                        button.classList.remove('is-loading', 'is-armed');
                        button.innerHTML = button.dataset.originalText || button.innerHTML;
                    }
                    item.classList.add('is-shaking');
                    setTimeout(() => item.classList.remove('is-shaking'), 320);
                    // The server names the recoverable cause (typed-id mismatch,
                    // cooldown, kill-switch, …) — show it, not a generic dead end.
                    this.addToast('error', error.message || "Couldn't execute. Nothing ran — try again.");
                }
            },

            async postForm(form, body) {
                const controller = new AbortController();
                const cockpitRequestTimeout = setTimeout(() => controller.abort(), 12000);

                try {
                    const response = await fetch(form.action, {
                        method: form.method || 'POST',
                        body: body || new FormData(form),
                        signal: controller.signal,
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                        },
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) throw new Error(payload.message || 'Request failed');
                    return payload;
                } catch (error) {
                    if (error.name === 'AbortError') throw new Error('Request timed out. Put back in the queue.');
                    throw error;
                } finally {
                    clearTimeout(cockpitRequestTimeout);
                }
            },

            async undo(url, restore, item) {
                if (!url) return;
                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Undo failed');
                    restore();
                    this.select(item);
                    this.applyCounts(payload.counts);
                    this.addToast('info', payload.message || 'Action undone.');
                } catch (error) {
                    this.addToast('error', error.message || 'Undo failed.');
                }
            },

            removeItem(item) {
                const parent = item.parentNode;
                const next = item.nextSibling;
                const height = item.offsetHeight;
                if (this.selected === item) this.selectSibling(item);
                item.style.maxHeight = `${height}px`;
                item.classList.add('is-leaving');
                const removeTimer = setTimeout(() => {
                    if (item.classList.contains('is-leaving')) item.remove();
                }, 300);

                return () => {
                    clearTimeout(removeTimer);
                    item.classList.remove('is-leaving');
                    item.style.maxHeight = '';
                    if (next && next.parentNode === parent) parent.insertBefore(item, next);
                    else parent.appendChild(item);
                    item.classList.add('is-entering');
                    setTimeout(() => item.classList.remove('is-entering'), 300);
                };
            },

            addToast(kind, message, undo, secret) {
                const id = this.toastId++;
                const toast = { id, kind: kind === 'error' ? 'error' : kind, message, undo: undo || null, secret: secret || null };
                this.toasts.push(toast);
                if (undo) {
                    this.lastUndoId = id;
                    this.lastUndo = () => { undo(); this.dismissToast(id); };
                }
                // A one-time secret readout must never vanish on a timer — the
                // operator dismisses it after relaying the credential.
                if (!toast.secret) {
                    setTimeout(() => this.dismissToast(id), undo ? 7000 : 4200);
                }
            },

            async copyToastSecret(toast, event) {
                try {
                    await navigator.clipboard.writeText(toast.secret);
                    const button = event?.target?.closest('button');
                    if (button) {
                        button.textContent = 'Copied';
                        setTimeout(() => { button.textContent = 'Copy'; }, 1500);
                    }
                } catch (error) {
                    // Clipboard unavailable (permissions/insecure context): the
                    // readout is user-select-all, so manual copy still works.
                }
            },

            dismissToast(id) {
                if (this.lastUndoId === id) {
                    this.lastUndoId = null;
                    this.lastUndo = null;
                }
                this.toasts = this.toasts.filter((toast) => toast.id !== id);
            },

            handleClick(event) {
                const toggle = event.target.closest('[data-correction-toggle]');
                if (!toggle) return;
                const drawer = toggle.closest('details.cockpit-correction');
                if (!drawer) return;
                setTimeout(() => {
                    if (drawer.open) drawer.querySelector('textarea')?.focus();
                }, 40);
            },

            arm(form) {
                this.disarm();
                const button = form.querySelector('button[type="submit"]');
                if (!button) return;
                this.armedForm = form;
                button.dataset.originalText = button.innerHTML;
                button.classList.add('is-armed');
                button.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Confirm: run now';
                this.armedTimer = setTimeout(() => this.disarm(), 3000);
            },

            isArmed(form) {
                return this.armedForm === form;
            },

            disarm() {
                if (this.armedTimer) clearTimeout(this.armedTimer);
                if (this.armedForm) {
                    const button = this.armedForm.querySelector('button[type="submit"]');
                    if (button) {
                        button.classList.remove('is-armed');
                        button.innerHTML = button.dataset.originalText || button.innerHTML;
                    }
                }
                this.armedForm = null;
                this.armedTimer = null;
            },

            disarmFromOutside(event) {
                if (this.armedForm && !this.armedForm.contains(event.target)) this.disarm();
            },

            setBusy(item, busy) {
                item.querySelectorAll('button,textarea,input').forEach((control) => control.disabled = busy);
            },

            selectable() {
                return Array.from(document.querySelectorAll('[data-cockpit-item]'))
                    .filter((item) => item.offsetParent !== null && !item.classList.contains('is-leaving'));
            },

            select(item) {
                if (this.selected) this.selected.classList.remove('is-selected');
                this.selected = item || null;
                if (this.selected) {
                    this.selected.classList.add('is-selected');
                    this.selected.scrollIntoView({ block: 'nearest' });
                }
            },

            selectFirst() {
                this.select(this.selectable()[0] || null);
            },

            selectSibling(item) {
                const items = this.selectable();
                const index = items.indexOf(item);
                this.select(items[index + 1] || items[index - 1] || null);
            },

            move(delta) {
                const items = this.selectable();
                if (!items.length) return;
                const index = this.selected ? items.indexOf(this.selected) : -1;
                this.select(items[Math.max(0, Math.min(items.length - 1, index + delta))]);
            },

            key(event) {
                if (this.showHelp) {
                    if (event.key === 'Escape') this.showHelp = false;
                    event.preventDefault();
                    return;
                }
                if (event.key === '?') {
                    this.showHelp = true;
                    event.preventDefault();
                    return;
                }
                const target = event.target;
                const typing = target && ['TEXTAREA', 'INPUT'].includes(target.tagName);
                if (typing) {
                    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                        target.form?.requestSubmit();
                        event.preventDefault();
                    }
                    return;
                }
                const sectionKeys = ['replies', 'closures', 'actions', 'intake', 'flagged', 'needs'];
                if (event.key >= '1' && event.key <= '6') {
                    const section = sectionKeys[Number(event.key) - 1];
                    this.setFilter(section);
                    this.$nextTick(() => document.querySelector(`[data-section-key="${section}"]`)?.scrollIntoView({ block: 'start' }));
                    event.preventDefault();
                    return;
                }
                if (event.key === 'j' || event.key === 'ArrowDown') { this.move(1); event.preventDefault(); }
                if (event.key === 'k' || event.key === 'ArrowUp') { this.move(-1); event.preventDefault(); }
                if (event.key === 'a') { this.selected?.querySelector('form[data-keybind="approve"]')?.requestSubmit(); event.preventDefault(); }
                if (event.key === 'h') { this.selected?.querySelector('form[data-keybind="hold"]')?.requestSubmit(); event.preventDefault(); }
                if (event.key === 'c') { this.selected?.querySelector('[data-correction-toggle]')?.click(); event.preventDefault(); }
                if (event.key === 'u' && this.lastUndo) { this.lastUndo(); event.preventDefault(); }
            },
        };
    });
});
</script>
	<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js" integrity="sha384-X9kJyAubVxnP0hcA+AMMs21U445qsnqhnUF8EBlEpP3a42Kh/JwWjlv2ZcvGfphb" crossorigin="anonymous"></script>
@endpush
