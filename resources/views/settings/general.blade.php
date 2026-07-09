@extends('layouts.app')

@section('title', 'General Settings')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <h2 class="section-title">General Settings</h2>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-globe me-2"></i>Display Timezone
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    All timestamps in the app will display in this timezone. The database always stores dates in UTC.
                </p>

                <form method="POST" action="{{ route('settings.general.update') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="app_timezone" class="form-label">Timezone</label>
                        <select class="form-select @error('app_timezone') is-invalid @enderror"
                                id="app_timezone"
                                name="app_timezone"
                                required>
                            @php
                                $groups = [
                                    'Americas' => [
                                        'America/New_York'       => 'Eastern Time — New York',
                                        'America/Chicago'        => 'Central Time — Chicago',
                                        'America/Denver'         => 'Mountain Time — Denver',
                                        'America/Phoenix'        => 'Mountain Time — Phoenix (no DST)',
                                        'America/Los_Angeles'    => 'Pacific Time — Los Angeles',
                                        'America/Anchorage'      => 'Alaska Time — Anchorage',
                                        'Pacific/Honolulu'       => 'Hawaii Time — Honolulu',
                                        'America/Puerto_Rico'    => 'Atlantic Time — Puerto Rico',
                                        'America/Toronto'        => 'Eastern Time — Toronto',
                                        'America/Vancouver'      => 'Pacific Time — Vancouver',
                                        'America/Winnipeg'       => 'Central Time — Winnipeg',
                                        'America/Halifax'        => 'Atlantic Time — Halifax',
                                        'America/St_Johns'       => 'Newfoundland — St. Johns',
                                        'America/Sao_Paulo'      => 'Brasília — São Paulo',
                                        'America/Argentina/Buenos_Aires' => 'Argentina — Buenos Aires',
                                        'America/Mexico_City'    => 'Central Time — Mexico City',
                                        'America/Bogota'         => 'Colombia — Bogotá',
                                    ],
                                    'Europe / Africa' => [
                                        'UTC'                    => 'UTC',
                                        'Europe/London'          => 'GMT/BST — London',
                                        'Europe/Dublin'          => 'GMT/IST — Dublin',
                                        'Europe/Paris'           => 'CET — Paris',
                                        'Europe/Berlin'          => 'CET — Berlin',
                                        'Europe/Rome'            => 'CET — Rome',
                                        'Europe/Madrid'          => 'CET — Madrid',
                                        'Europe/Amsterdam'       => 'CET — Amsterdam',
                                        'Europe/Brussels'        => 'CET — Brussels',
                                        'Europe/Zurich'          => 'CET — Zurich',
                                        'Europe/Stockholm'       => 'CET — Stockholm',
                                        'Europe/Warsaw'          => 'CET — Warsaw',
                                        'Europe/Helsinki'        => 'EET — Helsinki',
                                        'Europe/Athens'          => 'EET — Athens',
                                        'Europe/Istanbul'        => 'TRT — Istanbul',
                                        'Europe/Moscow'          => 'MSK — Moscow',
                                        'Africa/Cairo'           => 'EET — Cairo',
                                        'Africa/Johannesburg'    => 'SAST — Johannesburg',
                                        'Africa/Lagos'           => 'WAT — Lagos',
                                        'Africa/Nairobi'         => 'EAT — Nairobi',
                                    ],
                                    'Asia / Pacific' => [
                                        'Asia/Dubai'             => 'GST — Dubai',
                                        'Asia/Kolkata'           => 'IST — Kolkata',
                                        'Asia/Dhaka'             => 'BST — Dhaka',
                                        'Asia/Bangkok'           => 'ICT — Bangkok',
                                        'Asia/Singapore'         => 'SGT — Singapore',
                                        'Asia/Hong_Kong'         => 'HKT — Hong Kong',
                                        'Asia/Shanghai'          => 'CST — Shanghai',
                                        'Asia/Tokyo'             => 'JST — Tokyo',
                                        'Asia/Seoul'             => 'KST — Seoul',
                                        'Australia/Perth'        => 'AWST — Perth',
                                        'Australia/Adelaide'     => 'ACST — Adelaide',
                                        'Australia/Darwin'       => 'ACST — Darwin',
                                        'Australia/Brisbane'     => 'AEST — Brisbane (no DST)',
                                        'Australia/Sydney'       => 'AEST — Sydney',
                                        'Australia/Melbourne'    => 'AEST — Melbourne',
                                        'Pacific/Auckland'       => 'NZST — Auckland',
                                        'Pacific/Fiji'           => 'FJT — Fiji',
                                    ],
                                ];
                            @endphp

                            @foreach($groups as $group => $zones)
                                <optgroup label="{{ $group }}">
                                    @foreach($zones as $tz => $label)
                                        <option value="{{ $tz }}" {{ $appTimezone === $tz ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('app_timezone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            Current server time: {{ now()->setTimezone($appTimezone)->format('Y-m-d H:i T') }}
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Settings
                    </button>
                </form>
            </div>
        </div>
        {{-- Invoice Numbering --}}
        <div class="card card-static shadow-sm mt-4">
            <div class="card-header">
                <i class="bi bi-receipt me-2"></i>Invoice Numbering
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Invoices are numbered <code>{{ $invoicePrefix }}-XXXXX</code>. To continue numbering from a prior system,
                    set the starting number below. The PSA will use whichever is higher: this number or the next in sequence.
                </p>

                <form method="POST" action="{{ route('settings.general.billing-numbering') }}">
                    @csrf
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="next_number" class="form-label">Next Invoice Number</label>
                            <div class="input-group">
                                <span class="input-group-text">{{ $invoicePrefix }}-</span>
                                <input type="number" class="form-control" id="next_number" name="next_number"
                                       value="{{ $invoiceNextNumber }}" min="1" placeholder="e.g. 12277">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Save
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- Empty Invoice Suppression --}}
        <div class="card card-static shadow-sm mt-4">
            <div class="card-header">
                <i class="bi bi-skip-forward me-2"></i>Empty Invoice Suppression
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.general.billing-skip-zero') }}">
                    @csrf
                    <div class="form-check form-switch">
                        <input type="hidden" name="billing_skip_zero_invoices" value="0">
                        <input type="checkbox" class="form-check-input" id="billing_skip_zero_invoices"
                               name="billing_skip_zero_invoices" value="1"
                               {{ $billingSkipZero ? 'checked' : '' }}
                               onchange="this.form.submit()">
                        <label class="form-check-label" for="billing_skip_zero_invoices">
                            Skip empty invoices during billing runs
                        </label>
                    </div>
                    <div class="form-text mt-2">
                        When enabled, billing runs will skip profiles with no line items or where all quantities resolve to zero.
                        Profiles with $0-priced lines at qty &gt; 0 still generate as a record of coverage.
                        Individual profiles can override this setting.
                    </div>
                </form>
            </div>
        </div>

        {{-- Prepaid Time Expiration --}}
        <div class="card card-static shadow-sm mt-4">
            <div class="card-header">
                <i class="bi bi-hourglass-split me-2"></i>Prepaid Time Expiration
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.general.prepay-expiry') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-auto">
                        <label for="prepay_expiry_months" class="form-label small text-muted mb-1">
                            Prepaid time expires after (months):
                        </label>
                        <input type="number" name="prepay_expiry_months" id="prepay_expiry_months"
                               class="form-control form-control-sm" style="max-width: 150px;"
                               value="{{ $prepayExpiryMonths }}"
                               min="1" max="120" step="1" placeholder="No expiration">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check-lg me-1"></i>Save
                        </button>
                    </div>
                    <div class="form-text mt-2">
                        Global default applied to hours-based prepaid credits when a contract does not set its own
                        expiration policy. Leave blank for no expiration. Individual contracts can override this
                        (including opting a single contract out with a "0 = never expire" override).
                        Applies to credits added from now on — no backfill of existing balances.
                    </div>
                </form>
            </div>
        </div>

        {{-- Billing Asset Type Mapping --}}
        <div class="card card-static shadow-sm mt-4">
            <div class="card-header">
                <i class="bi bi-pc-display me-2"></i>Billing — Asset Type Mapping
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Choose which asset types count as "workstations" and "servers" for billing quantity resolution.
                    These are populated from actual device types in your asset database.
                </p>

                @if(empty($allAssetTypes))
                    <div class="alert alert-info mb-0">No asset types found. Sync devices from NinjaRMM or Level first.</div>
                @else
                    <form method="POST" action="{{ route('settings.general.billing-types') }}">
                        @csrf

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Workstation Types</label>
                                <p class="text-muted small">Used by "Per Workstation" quantity on recurring profiles.</p>
                                @foreach($allAssetTypes as $type)
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input"
                                               name="workstation_types[]" value="{{ $type }}"
                                               id="ws_{{ Str::slug($type) }}"
                                               {{ in_array($type, $workstationTypes) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="ws_{{ Str::slug($type) }}">{{ $type }}</label>
                                    </div>
                                @endforeach
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Server Types</label>
                                <p class="text-muted small">Used by "Per Server" quantity on recurring profiles.</p>
                                @foreach($allAssetTypes as $type)
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input"
                                               name="server_types[]" value="{{ $type }}"
                                               id="srv_{{ Str::slug($type) }}"
                                               {{ in_array($type, $serverTypes) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="srv_{{ Str::slug($type) }}">{{ $type }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary mt-3">
                            <i class="bi bi-check-lg me-1"></i>Save Asset Type Mapping
                        </button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Client Wiki --}}
        <div class="card card-static shadow-sm mt-4">
            <div class="card-header">
                <i class="bi bi-journal-text me-2"></i>Client Wiki
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Auto-maintained client environment documentation. The master switch controls the whole module.
                    Mining spends AI tokens on each closed ticket, and a short hot-summary is recomposed after
                    tickets that change facts — both draw from one shared daily token budget (set below). Expect
                    roughly $2–8/day at the default budgets. Nightly maintenance recomposes only clients whose
                    facts changed since the last run (hash-skip), so steady-state nightly cost is near-zero.
                    <code>wiki:backfill</code> is a separate, operator-initiated, dry-run-first spend that
                    populates history from closed tickets and respects the same daily ceiling. The daily
                    ceiling is a hard cap on total wiki AI spend — no single operation can exceed it.
                </p>
                <form method="POST" action="{{ route('settings.general.wiki') }}">
                    @csrf
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="wiki_enabled" name="wiki_enabled" value="1"
                               @checked(\App\Support\WikiConfig::isEnabled())>
                        <label class="form-check-label" for="wiki_enabled">Enable the Client Wiki module</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="wiki_auto_mine" name="wiki_auto_mine" value="1"
                               @checked((bool) \App\Models\Setting::getValue('wiki_auto_mine'))>
                        <label class="form-check-label" for="wiki_auto_mine">
                            Mine closed tickets into wiki facts (spends AI tokens; requires the module enabled above)
                        </label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="wiki_maintenance_enabled" name="wiki_maintenance_enabled" value="1"
                               @checked(\App\Support\WikiConfig::maintenanceEnabled())>
                        <label class="form-check-label" for="wiki_maintenance_enabled">
                            Run nightly maintenance (staleness sweep, contradiction detection, stale-overview regen)
                        </label>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="wiki_model">Model override</label>
                            <input class="form-control" id="wiki_model" name="wiki_model"
                                   value="{{ \App\Models\Setting::getValue('wiki_model') }}" placeholder="(uses AI default)">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="wiki_max_tokens_per_run">Tokens per mining run</label>
                            <input type="number" class="form-control" id="wiki_max_tokens_per_run" name="wiki_max_tokens_per_run"
                                   value="{{ \App\Support\WikiConfig::maxTokensPerRun() }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="wiki_daily_token_limit">Daily token ceiling</label>
                            <input type="number" class="form-control" id="wiki_daily_token_limit" name="wiki_daily_token_limit"
                                   value="{{ \App\Support\WikiConfig::dailyTokenLimit() }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="wiki_staleness_days_volatile">Staleness window (days)</label>
                            <input type="number" class="form-control" id="wiki_staleness_days_volatile" name="wiki_staleness_days_volatile"
                                   value="{{ \App\Support\WikiConfig::stalenessDaysVolatile() }}" min="1">
                            <div class="form-text">Volatile facts un-reaffirmed longer than this are flagged stale (default 90).</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="wiki_backfill_batch_size">Backfill batch size</label>
                            <input type="number" class="form-control" id="wiki_backfill_batch_size" name="wiki_backfill_batch_size"
                                   value="{{ \App\Support\WikiConfig::backfillBatchSize() }}" min="1" max="500">
                            <div class="form-text">Max tickets dispatched per <code>wiki:backfill --execute</code> run (default 25).</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">Save Wiki Settings</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
