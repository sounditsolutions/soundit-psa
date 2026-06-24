@extends('layouts.app')

@section('title', 'Integrations')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-10 col-lg-8">
        <h2 class="section-title">Integrations</h2>

        <div class="alert alert-light border mb-4 d-flex align-items-center gap-3">
            <i class="bi bi-sliders fs-5 text-muted"></i>
            <div class="small">
                Looking for timezone or other app settings?
                <a href="{{ route('settings.general') }}">General Settings</a>
            </div>
        </div>

        {{-- Category tabs --}}
        <ul class="nav nav-pills mb-4 flex-wrap gap-1" id="integrationTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="billing-tab" data-bs-toggle="pill" data-bs-target="#billing" type="button" role="tab">
                    <i class="bi bi-credit-card me-1"></i>Billing
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="rmm-tab" data-bs-toggle="pill" data-bs-target="#rmm" type="button" role="tab">
                    <i class="bi bi-pc-display me-1"></i>RMM & Monitoring
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="licensing-tab" data-bs-toggle="pill" data-bs-target="#licensing" type="button" role="tab">
                    <i class="bi bi-key me-1"></i>Licensing
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="communications-tab" data-bs-toggle="pill" data-bs-target="#communications" type="button" role="tab">
                    <i class="bi bi-chat-dots me-1"></i>Communications
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="ai-tab" data-bs-toggle="pill" data-bs-target="#ai" type="button" role="tab">
                    <i class="bi bi-robot me-1"></i>AI & Automation
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="portal-tab" data-bs-toggle="pill" data-bs-target="#portal" type="button" role="tab">
                    <i class="bi bi-person-lines-fill me-1"></i>Client Portal
                </button>
            </li>
        </ul>

        <div class="tab-content" id="integrationTabContent">

        {{-- ============================================================ --}}
        {{-- BILLING TAB --}}
        {{-- ============================================================ --}}
        <div class="tab-pane fade show active" id="billing" role="tabpanel">

        {{-- QuickBooks Online Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-receipt me-2"></i>QuickBooks Online
                </div>
                @if($qboConnected)
                    <span class="badge bg-success">Connected</span>
                @elseif($qboClientId)
                    <span class="badge bg-warning text-dark">Not connected</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Connect to QuickBooks Online for invoice sync and payment tracking.
                    Create an app at
                    <a href="https://developer.intuit.com" target="_blank">developer.intuit.com</a>,
                    set the redirect URI to
                    <code>{{ route('auth.qbo.callback') }}</code>.
                </p>

                <form method="POST" action="{{ route('settings.integrations.qbo.update') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="qbo_client_id" class="form-label">Client ID</label>
                            <input type="text"
                                   class="form-control @error('client_id') is-invalid @enderror"
                                   id="qbo_client_id"
                                   name="client_id"
                                   value="{{ old('client_id', $qboClientId ?? '') }}"
                                   required>
                            @error('client_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="qbo_client_secret" class="form-label">Client Secret</label>
                            <input type="password"
                                   class="form-control @error('client_secret') is-invalid @enderror"
                                   id="qbo_client_secret"
                                   name="client_secret"
                                   placeholder="{{ $qboHasSecret ? 'Leave blank to keep current' : 'Enter Client Secret' }}">
                            @error('client_secret')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="qbo_environment" class="form-label">Environment</label>
                        <select class="form-select" id="qbo_environment" name="environment">
                            <option value="sandbox" {{ $qboEnvironment === 'sandbox' ? 'selected' : '' }}>Sandbox</option>
                            <option value="production" {{ $qboEnvironment === 'production' ? 'selected' : '' }}>Production</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="qbo_webhook_verifier_token" class="form-label">Webhook Verifier Token</label>
                        <input type="password"
                               class="form-control"
                               id="qbo_webhook_verifier_token"
                               name="webhook_verifier_token"
                               placeholder="{{ $qboHasWebhookToken ? 'Leave blank to keep current' : 'From QBO Developer portal' }}">
                    </div>

                    @if($qboConnected)
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="qbo_default_income_account_id" class="form-label">
                                Default Income Account
                                <i class="bi bi-info-circle text-muted ms-1" data-bs-toggle="tooltip"
                                   title="Used as IncomeAccountRef when creating new Service items in QBO. Per-SKU override is available on each SKU."></i>
                            </label>
                            <select class="form-select" id="qbo_default_income_account_id" name="default_income_account_id">
                                <option value="">(Auto — first Income account)</option>
                                @foreach($qboIncomeAccounts as $acct)
                                    <option value="{{ $acct['Id'] }}" {{ ($qboDefaultIncomeId ?? '') === $acct['Id'] ? 'selected' : '' }}>
                                        {{ $acct['Name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="qbo_default_expense_account_id" class="form-label">
                                Default Expense Account
                                <i class="bi bi-info-circle text-muted ms-1" data-bs-toggle="tooltip"
                                   title="Used as ExpenseAccountRef on items with a unit cost > 0. Prefers Cost of Goods Sold accounts."></i>
                            </label>
                            <select class="form-select" id="qbo_default_expense_account_id" name="default_expense_account_id">
                                <option value="">(Auto — first COGS or Expense account)</option>
                                @foreach($qboExpenseAccounts as $acct)
                                    <option value="{{ $acct['Id'] }}" {{ ($qboDefaultExpenseId ?? '') === $acct['Id'] ? 'selected' : '' }}>
                                        {{ $acct['Name'] }} ({{ $acct['AccountType'] }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @endif

                    @if($qboConnected)
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="bi bi-check-circle text-success me-1"></i>
                            Realm ID: {{ $qboRealmId }}
                            @if($qboTokenExpiresAt)
                                <br>Token expires: {{ $qboTokenExpiresAt }}
                            @endif
                        </small>
                    </div>
                    @endif

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Credentials
                        </button>

                        @if($qboClientId && !$qboConnected)
                            <a href="{{ route('auth.qbo') }}" class="btn btn-outline-success">
                                <i class="bi bi-link-45deg me-1"></i>Connect to QuickBooks
                            </a>
                        @endif
                    </div>
                </form>

                @if($qboConnected)
                <div class="mt-3 pt-3 border-top">
                    <div class="d-flex gap-2 mb-3 flex-wrap">
                        <a href="{{ route('settings.qbo-clients.index') }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-diagram-3 me-1"></i>Client Matching
                        </a>
                        <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="collapse" data-bs-target="#qbo-webhook-setup">
                            <i class="bi bi-broadcast me-1"></i>Webhook Setup
                        </button>
                        <form method="POST" action="{{ route('settings.qbo.disconnect') }}" class="d-inline"
                              onsubmit="return confirm('Disconnect from QuickBooks?')">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-x-circle me-1"></i>Disconnect
                            </button>
                        </form>
                    </div>

                    <div class="collapse mb-3" id="qbo-webhook-setup">
                        <div class="card card-body bg-light">
                            <h6 class="fw-bold mb-2"><i class="bi bi-broadcast me-1"></i>Webhook Setup</h6>
                            <p class="small text-muted mb-2">
                                Webhooks push payment and status changes from QBO in real time.
                                If not configured, invoices sync every 4 hours automatically.
                            </p>
                            <ol class="small mb-0">
                                <li>In the <a href="https://developer.intuit.com" target="_blank" rel="noopener noreferrer">Intuit Developer Portal</a>,
                                    go to your app &rarr; <strong>Webhooks</strong>.</li>
                                <li>Set the endpoint URL to:
                                    <code class="user-select-all">{{ url('/api/webhooks/qbo') }}</code>
                                </li>
                                <li>Copy the <strong>Verifier Token</strong> from the portal and paste it
                                    into the field above, then click <strong>Save Credentials</strong>.</li>
                                <li>Subscribe to <strong>Invoice</strong> events.</li>
                            </ol>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('settings.integrations.qbo.update') }}">
                        @csrf
                        <div class="form-check form-switch">
                            <input type="hidden" name="auto_push_invoices" value="0">
                            <input type="checkbox" class="form-check-input" id="qbo_auto_push"
                                   name="auto_push_invoices" value="1"
                                   {{ $qboAutoPush ? 'checked' : '' }}
                                   onchange="this.form.submit()">
                            <label class="form-check-label" for="qbo_auto_push">
                                Auto-push draft invoices to QBO
                            </label>
                        </div>
                        <p class="text-muted small mb-0 mt-1">
                            When enabled, draft invoices are automatically pushed to QBO every 4 hours.
                            When disabled, invoices stay as drafts until manually pushed. Payment status is always synced regardless.
                        </p>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- Stripe Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-stripe me-2"></i>Stripe (Invoicing)
                </div>
                @if($stripeConnected ?? false)
                    <span class="badge bg-success">Connected</span>
                @elseif($stripeConfigured ?? false)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Connect to Stripe for invoice creation and payment tracking.
                    Get your API key from <a href="https://dashboard.stripe.com/apikeys" target="_blank">Stripe Dashboard &rarr; API keys</a>.
                    Use the <strong>Secret key</strong> (starts with <code>sk_</code>).
                </p>

                <form method="POST" action="{{ route('settings.integrations.stripe.update') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label for="stripe_secret_key" class="form-label">Secret Key</label>
                            <input type="password" class="form-control" id="stripe_secret_key" name="secret_key"
                                   value=""
                                   placeholder="{{ ($stripeConfigured ?? false) ? '••••••••' : 'sk_test_... or sk_live_...' }}">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="stripe_mode" class="form-label">Mode</label>
                            <select class="form-select" id="stripe_mode" name="mode">
                                <option value="test" {{ ($stripeMode ?? 'test') === 'test' ? 'selected' : '' }}>Test</option>
                                <option value="live" {{ ($stripeMode ?? 'test') === 'live' ? 'selected' : '' }}>Live</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Save Stripe Settings</button>
                        <button type="button" class="btn btn-outline-secondary" id="test-stripe-btn"
                                onclick="testConnection('stripe')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                    <div id="test-result-stripe" class="alert mt-2" style="display:none;"></div>
                </form>

                @if($stripeConnected ?? false)
                <div class="mt-3 pt-3 border-top">
                    <div class="d-flex gap-2 mb-3">
                        <a href="{{ route('settings.stripe-customers.index') }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-diagram-3 me-1"></i>Customer Matching
                        </a>
                        <form method="POST" action="{{ route('invoices.import-stripe') }}" class="d-inline"
                              onsubmit="return confirm('Import historical invoices from Stripe? This may take a moment for large histories.')">
                            @csrf
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-cloud-download me-1"></i>Import Invoices
                            </button>
                        </form>
                    </div>
                    <form method="POST" action="{{ route('settings.integrations.stripe.update') }}">
                        @csrf
                        <div class="form-check form-switch">
                            <input type="hidden" name="auto_push_invoices" value="0">
                            <input type="checkbox" class="form-check-input" id="stripe_auto_push"
                                   name="auto_push_invoices" value="1"
                                   {{ $stripeAutoPush ?? false ? 'checked' : '' }}
                                   onchange="this.form.submit()">
                            <label class="form-check-label" for="stripe_auto_push">
                                Auto-push draft invoices to Stripe
                            </label>
                        </div>
                        <p class="text-muted small mb-0 mt-1">
                            When enabled, draft invoices are automatically pushed to Stripe every 4 hours.
                            Payment status is always synced regardless.
                        </p>
                    </form>
                </div>
                @endif

                @if($stripeConfigured)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="stripe">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="stripe_enabled" {{ $stripeEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="stripe_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        </div>{{-- /billing tab --}}

        {{-- ============================================================ --}}
        {{-- RMM & MONITORING TAB --}}
        {{-- ============================================================ --}}
        <div class="tab-pane fade" id="rmm" role="tabpanel">

        {{-- NinjaRMM Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-pc-display me-2"></i>NinjaRMM
                </div>
                @if($ninjaConnected)
                    <span class="badge bg-success">Connected</span>
                @elseif($ninjaClientId)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Connect to NinjaRMM to sync device data (hostname, OS, hardware, online status).
                    Create an API application in
                    <a href="https://app.ninjarmm.com/#/administration/apps" target="_blank">NinjaRMM Admin &rarr; Apps</a>
                    with <strong>Client Credentials</strong> grant type and <strong>Monitoring</strong> scope.
                </p>

                <form method="POST" action="{{ route('settings.integrations.ninja.update') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="ninja_client_id" class="form-label">Client ID</label>
                        <input type="text"
                               class="form-control @error('client_id') is-invalid @enderror"
                               id="ninja_client_id"
                               name="client_id"
                               value="{{ old('client_id', $ninjaClientId) }}"
                               placeholder="Enter NinjaRMM Client ID"
                               required>
                        @error('client_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="ninja_client_secret" class="form-label">Client Secret</label>
                        <input type="password"
                               class="form-control @error('client_secret') is-invalid @enderror"
                               id="ninja_client_secret"
                               name="client_secret"
                               placeholder="{{ $ninjaClientId ? 'Leave blank to keep current' : 'Enter Client Secret' }}">
                        @error('client_secret')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    @if($ninjaConnectedAt)
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="bi bi-check-circle text-success me-1"></i>Last connected: {{ $ninjaConnectedAt }}
                        </small>
                    </div>
                    @endif

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Credentials
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="test-ninja-btn" onclick="testConnection('ninja')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                </form>

                <div id="test-result-ninja" class="mt-3" style="display: none;"></div>

                @if($ninjaConnected)
                <div class="mt-3 pt-3 border-top d-flex gap-2 flex-wrap">
                    <a href="{{ route('settings.ninja-orgs.index') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-diagram-3 me-1"></i>Organization Mapping
                    </a>
                    <form method="POST" action="{{ route('settings.integrations.ninja.sync-backup') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-cloud-arrow-down me-1"></i>Sync Backup Usage
                        </button>
                    </form>
                </div>
                @endif

                @if($ninjaClientId)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="ninja">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="ninja_enabled" {{ $ninjaEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="ninja_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- Level RMM Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-pc-display-horizontal me-2"></i>Level RMM
                </div>
                @if($levelConnected)
                    <span class="badge bg-success">Connected</span>
                @elseif($levelHasApiKey)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Connect to Level RMM to sync device data (hostname, OS, hardware, online status).
                    Generate an API key in
                    <a href="https://app.level.io/api-keys" target="_blank">Level &rarr; API Keys</a>.
                </p>

                <form method="POST" action="{{ route('settings.integrations.level.update') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="level_api_key" class="form-label">API Key</label>
                        <input type="password"
                               class="form-control @error('api_key') is-invalid @enderror"
                               id="level_api_key"
                               name="api_key"
                               placeholder="{{ $levelHasApiKey ? 'Leave blank to keep current' : 'Enter Level API Key' }}">
                        @error('api_key')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="level_webhook_secret" class="form-label">
                            Webhook Secret
                            <i class="bi bi-info-circle text-muted ms-1" data-bs-toggle="tooltip" data-bs-placement="top"
                               title="Used to verify webhook signatures from Level. Generate a secret here, then paste it into Level's webhook configuration."></i>
                        </label>
                        <div class="input-group">
                            <input type="text"
                                   class="form-control font-monospace @error('webhook_secret') is-invalid @enderror"
                                   id="level_webhook_secret"
                                   name="webhook_secret"
                                   value="{{ old('webhook_secret', $levelWebhookSecret ?? '') }}"
                                   placeholder="Click Generate to create a secret"
                                   readonly>
                            <button type="button" class="btn btn-outline-secondary" id="level-generate-secret-btn"
                                    onclick="generateLevelWebhookSecret()">
                                <i class="bi bi-key me-1"></i>Generate
                            </button>
                            @if($levelWebhookSecret)
                            <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard('level_webhook_secret')">
                                <i class="bi bi-clipboard me-1"></i>Copy
                            </button>
                            @endif
                        </div>
                        <div class="form-text">Copy this secret and paste it into Level's webhook configuration.</div>
                        @error('webhook_secret')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="level_install_account_token" class="form-label">
                            Install Account Token
                            <i class="bi bi-info-circle text-muted ms-1" data-bs-toggle="tooltip" data-bs-placement="top"
                               title="Used to build per-group install keys for the client portal self-service installer. Tenant-wide token — configure once."></i>
                        </label>
                        <input type="password"
                               class="form-control @error('install_account_token') is-invalid @enderror"
                               id="level_install_account_token"
                               name="install_account_token"
                               placeholder="{{ $levelHasInstallAccountToken ? 'Leave blank to keep current' : 'Paste the part before the colon in a Level install key' }}">
                        @if($levelHasInstallAccountToken)
                            <div class="form-text text-success">
                                <i class="bi bi-check-circle-fill me-1"></i>Token configured. The field stays blank on reload for security — leave it blank to keep the current value.
                            </div>
                        @endif
                        <div class="form-text">
                            Open any group's "Add Device" dialog in the Level dashboard. The install key looks like
                            <code>abc123:40123</code>. Paste the part <strong>before the colon</strong> here. Same token for every group.
                        </div>
                        @error('install_account_token')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    @if($levelConnectedAt)
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="bi bi-check-circle text-success me-1"></i>Last connected: {{ $levelConnectedAt }}
                        </small>
                    </div>
                    @endif

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Credentials
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="test-level-btn" onclick="testConnection('level')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                </form>

                <div id="test-result-level" class="mt-3" style="display: none;"></div>

                @if($levelConnected)
                <div class="mt-3 pt-3 border-top d-flex gap-2 flex-wrap">
                    <a href="{{ route('settings.level-groups.index') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-diagram-3 me-1"></i>Group Mapping
                    </a>
                    <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="collapse" data-bs-target="#level-webhook-setup">
                        <i class="bi bi-broadcast me-1"></i>Webhook Setup
                    </button>
                </div>

                <div class="collapse mt-3" id="level-webhook-setup">
                    <div class="card card-body bg-light">
                        <h6 class="fw-bold mb-2"><i class="bi bi-broadcast me-1"></i>Webhook Setup</h6>
                        <p class="small text-muted mb-2">
                            Webhooks push device changes to this PSA in real time, so you don't have to wait for the next scheduled sync.
                        </p>
                        <ol class="small mb-0">
                            <li>Generate a webhook secret above (if you haven't already) and <strong>Save Credentials</strong>.</li>
                            <li>In Level, go to <strong>Settings &rarr; Webhooks &rarr; Add webhook</strong>.</li>
                            <li>Set the URL to:
                                <code class="user-select-all">{{ url('/api/webhooks/level') }}</code>
                            </li>
                            <li>Paste the webhook secret from above into the <strong>Secret</strong> field.</li>
                            <li>Enable events: <strong>Device created</strong>, <strong>Device updated</strong>, <strong>Device deleted</strong> (or select all).</li>
                            <li>Click <strong>Add webhook</strong>.</li>
                        </ol>
                    </div>
                </div>
                @endif

                @if($levelHasApiKey)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="level">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="level_enabled" {{ $levelEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="level_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- ScreenConnect (ConnectWise Control) Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-display me-2"></i>ScreenConnect (ConnectWise Control)
                </div>
                @if($screenconnectEnabled && $screenconnectConfigured)
                    <span class="badge bg-success">Active</span>
                @elseif($screenconnectConfigured)
                    <span class="badge bg-warning text-dark">Configured (disabled)</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Receive session events from ScreenConnect (ConnectWise Control) via webhook automations.
                    Used for remote session tracking and asset enrichment.
                </p>

                <form method="POST" action="{{ route('settings.integrations.screenconnect.update') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="screenconnect_base_url" class="form-label">Instance URL</label>
                        <input type="text"
                               class="form-control @error('base_url') is-invalid @enderror"
                               id="screenconnect_base_url"
                               name="base_url"
                               value="{{ old('base_url', $screenconnectBaseUrl) }}"
                               placeholder="https://your-instance.screenconnect.com">
                        @error('base_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="screenconnect_webhook_secret" class="form-label">Webhook Secret</label>
                        <div class="input-group">
                            <input type="text"
                                   class="form-control font-monospace"
                                   id="screenconnect_webhook_secret"
                                   value="{{ $screenconnectWebhookSecret }}"
                                   placeholder="Click Save to generate"
                                   readonly>
                            @if($screenconnectWebhookSecret)
                            <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard('screenconnect_webhook_secret')">
                                <i class="bi bi-clipboard me-1"></i>Copy
                            </button>
                            @endif
                        </div>
                        <div class="form-text">Auto-generated on first save. Used to authenticate incoming webhooks.</div>
                    </div>

                    @if(! $screenconnectWebhookSecret)
                        <input type="hidden" name="generate_secret" value="1">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save &amp; Generate Secret
                        </button>
                    @else
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Save
                            </button>
                            <button type="submit" class="btn btn-outline-warning" name="generate_secret" value="1"
                                    onclick="return confirm('Regenerate webhook secret? You will need to update the URL in ScreenConnect.')">
                                <i class="bi bi-key me-1"></i>Regenerate Secret
                            </button>
                        </div>
                    @endif
                </form>

                @if($screenconnectConfigured)
                <div class="mt-3 pt-3 border-top">
                    <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="collapse" data-bs-target="#screenconnect-webhook-setup">
                        <i class="bi bi-broadcast me-1"></i>Webhook Setup
                    </button>
                </div>

                <div class="collapse mt-3" id="screenconnect-webhook-setup">
                    <div class="card card-body bg-light">
                        <h6 class="fw-bold mb-2"><i class="bi bi-broadcast me-1"></i>Webhook Setup</h6>
                        <p class="small text-muted mb-2">
                            Configure a ScreenConnect session event trigger to send data to this PSA.
                        </p>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Webhook URL</label>
                            <div class="input-group input-group-sm">
                                <input type="text"
                                       class="form-control font-monospace"
                                       id="screenconnect_webhook_url"
                                       value="{{ url('/api/webhooks/screenconnect/' . $screenconnectWebhookSecret) }}"
                                       readonly>
                                <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard('screenconnect_webhook_url')">
                                    <i class="bi bi-clipboard me-1"></i>Copy
                                </button>
                            </div>
                        </div>

                        <ol class="small mb-3">
                            <li>In ScreenConnect, go to <strong>Admin &rarr; Automations</strong>.</li>
                            <li>Click <strong>+ Create Automation</strong> and choose <strong>Session Event</strong>.</li>
                            <li>Set <strong>Event Filter</strong> to:
<pre class="mt-1 mb-1 p-2 bg-white border rounded small">Event.EventType = 'Connected' OR Event.EventType = 'Disconnected' OR Event.EventType = 'ProcessedGuestInfoUpdate'</pre>
                            </li>
                            <li>Add an action with:
                                <ul class="mt-1">
                                    <li><strong>HTTP Method:</strong> <code>POST</code></li>
                                    <li><strong>URL:</strong> paste the <strong>Webhook URL</strong> above</li>
                                    <li><strong>Content Type:</strong> <code>application/json</code></li>
                                    <li><strong>Body:</strong> <code>{*:json}</code></li>
                                </ul>
                            </li>
                            <li>Save the automation.</li>
                        </ol>
                    </div>
                </div>

                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="screenconnect">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="screenconnect_enabled" {{ $screenconnectEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="screenconnect_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- Tactical RMM Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-pc-display me-2"></i>Tactical RMM
                </div>
                @if($tacticalConnected)
                    <span class="badge bg-success">Connected</span>
                @elseif($tacticalConfigured)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Connect to Tactical RMM to sync device data (hostname, OS, hardware, online status).
                    Enter your Tactical RMM API URL and API key from
                    <strong>Settings &rarr; Global Settings &rarr; API Keys</strong> in Tactical RMM.
                </p>

                <form method="POST" action="{{ route('settings.integrations.tactical.update') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="tactical_api_url" class="form-label">API URL</label>
                        <input type="text"
                               class="form-control @error('api_url') is-invalid @enderror"
                               id="tactical_api_url"
                               name="api_url"
                               value="{{ old('api_url', $tacticalApiUrl) }}"
                               placeholder="https://api-rmm.example.com"
                               required>
                        @error('api_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- psa-6h5r: the web dashboard base, distinct from the API
                         URL above. Optional; when blank, the asset-page
                         "Open in Tactical" link stays hidden. --}}
                    <div class="mb-3">
                        <label for="tactical_web_url" class="form-label">Tactical web dashboard URL <span class="text-muted">(optional)</span></label>
                        <input type="text"
                               class="form-control @error('web_url') is-invalid @enderror"
                               id="tactical_web_url"
                               name="web_url"
                               value="{{ old('web_url', $tacticalWebUrl) }}"
                               placeholder="https://rmm.example.com">
                        @error('web_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            The Tactical RMM <strong>dashboard</strong> base (where you log in) — distinct from the API URL above.
                            Used only for the asset-page <em>Open in Tactical</em> link, which is hidden until this is set.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="tactical_api_key" class="form-label">API Key</label>
                        <input type="password"
                               class="form-control @error('api_key') is-invalid @enderror"
                               id="tactical_api_key"
                               name="api_key"
                               placeholder="{{ $tacticalConfigured ? 'Leave blank to keep current' : 'Enter API Key' }}">
                        @error('api_key')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Credentials
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="test-tactical-btn" onclick="testConnection('tactical')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                </form>

                <div id="test-result-tactical" class="mt-3" style="display: none;"></div>

                {{-- Webhook health (P1 trust signal) — sourced from tactical_webhooks --}}
                <div class="mt-3 pt-3 border-top">
                    <div class="d-flex align-items-center flex-wrap gap-3 small">
                        <span class="text-muted">
                            <i class="bi bi-activity me-1"></i>Webhook health
                        </span>
                        <span title="Most recent alert webhook received from Tactical">
                            Last alert received:
                            <strong>{{ $tacticalWebhookLastAt ?? 'never' }}</strong>
                        </span>
                        <span title="Alert webhooks successfully processed in the last 24 hours">
                            <i class="bi bi-check-circle text-success me-1"></i>{{ $tacticalWebhookProcessed24h }} processed (24h)
                        </span>
                        @if($tacticalWebhookFailed > 0)
                            <span class="badge bg-danger" title="Webhooks that exhausted retries — investigate the queue worker / logs">
                                <i class="bi bi-exclamation-triangle me-1"></i>{{ $tacticalWebhookFailed }} failed
                            </span>
                        @endif
                    </div>
                </div>

                @if($tacticalConnected)
                <div class="mt-3 pt-3 border-top d-flex gap-2 flex-wrap">
                    <a href="{{ route('settings.tactical-sites.index') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-diagram-3 me-1"></i>Site Mapping
                    </a>
                    <form method="POST" action="{{ route('settings.integrations.tactical.sync-devices') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-cloud-arrow-down me-1"></i>Sync Devices
                        </button>
                    </form>
                    <form method="POST" action="{{ route('settings.integrations.tactical.sync-scripts') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-terminal me-1"></i>Sync Scripts
                        </button>
                    </form>
                    <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="collapse" data-bs-target="#tactical-webhook-setup">
                        <i class="bi bi-broadcast me-1"></i>Webhook Setup
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="tacticalProvisionAlerts(this)">
                        <i class="bi bi-lightning-charge me-1"></i>Provision alert&rarr;ticket
                    </button>
                </div>
                <div id="tactical-provision-result" class="mt-2" style="display: none;"></div>

                <div class="mt-3">
                    <form method="POST" action="{{ route('settings.integrations.tactical.update') }}" class="d-flex align-items-center gap-2">
                        @csrf
                        <input type="hidden" name="api_url" value="{{ \App\Support\TacticalConfig::get('api_url') }}">
                        <label class="form-label mb-0 small fw-bold">Alert ticket threshold:</label>
                        <select name="alert_min_severity" class="form-select form-select-sm" style="width: 160px;" onchange="this.form.submit()">
                            <option value="error" {{ \App\Support\TacticalConfig::alertMinSeverity() === 'error' ? 'selected' : '' }}>Error only</option>
                            <option value="warning" {{ \App\Support\TacticalConfig::alertMinSeverity() === 'warning' ? 'selected' : '' }}>Warning + Error</option>
                            <option value="info" {{ \App\Support\TacticalConfig::alertMinSeverity() === 'info' ? 'selected' : '' }}>All (incl. Info)</option>
                        </select>
                    </form>
                </div>

                <div class="collapse mt-3" id="tactical-webhook-setup">
                    <div class="card card-body bg-light">
                        <h6 class="fw-bold mb-2"><i class="bi bi-broadcast me-1"></i>Alert Webhook Setup</h6>
                        <p class="small text-muted mb-2">
                            Configure Tactical RMM to push alert notifications to this PSA.
                            When a check fails or an agent goes overdue, a ticket is created automatically.
                            When the alert resolves, the ticket is resolved.
                        </p>

                        {{-- Step 1: Generate webhook key --}}
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Step 1: Webhook Key</label>
                            @if(session('tactical_webhook_key'))
                                <div class="alert alert-success small mb-2">
                                    <strong>Key generated!</strong> Copy the values below into Tactical. This key will not be shown again.
                                </div>
                            @endif
                            @if(\App\Support\TacticalConfig::get('webhook_key'))
                                <p class="small text-success mb-1"><i class="bi bi-check-circle me-1"></i>Webhook key is configured.</p>
                                <form method="POST" action="{{ route('settings.integrations.tactical.update') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="api_url" value="{{ \App\Support\TacticalConfig::get('api_url') }}">
                                    <input type="hidden" name="generate_webhook_key" value="1">
                                    <button type="submit" class="btn btn-outline-warning btn-sm"
                                            onclick="return confirm('Generate a new key? The old key will stop working.')">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Regenerate Key
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('settings.integrations.tactical.update') }}">
                                    @csrf
                                    <input type="hidden" name="api_url" value="{{ \App\Support\TacticalConfig::get('api_url') }}">
                                    <input type="hidden" name="generate_webhook_key" value="1">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="bi bi-key me-1"></i>Generate Webhook Key
                                    </button>
                                </form>
                            @endif
                        </div>

                        {{-- Step 2: Webhook URL --}}
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Step 2: Create Webhook in Tactical</label>
                            <p class="small mb-2">In Tactical RMM, go to <strong>Settings &rarr; Global Settings &rarr; Web Hooks</strong> and click <strong>Add</strong>.</p>

                            <label class="form-label small">Webhook URL</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control font-monospace" id="tactical_webhook_url"
                                       value="{{ url('/api/webhooks/tactical') }}" readonly>
                                <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard('tactical_webhook_url')">
                                    <i class="bi bi-clipboard me-1"></i>Copy
                                </button>
                            </div>

                            <label class="form-label small">Headers</label>
                            @php
                                $webhookKeyDisplay = session('tactical_webhook_key') ?? '(generate key above first)';
                                $headersJson = json_encode([
                                    'Content-Type' => 'application/json',
                                    'Authorization' => 'Bearer ' . $webhookKeyDisplay,
                                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            @endphp
                            <div class="input-group input-group-sm mb-2">
                                <textarea class="form-control font-monospace small" id="tactical_webhook_headers" rows="4" readonly>{{ $headersJson }}</textarea>
                                <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard('tactical_webhook_headers')">
                                    <i class="bi bi-clipboard me-1"></i>Copy
                                </button>
                            </div>
                        </div>

                        {{-- Step 3: Create two webhooks --}}
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Step 3: Webhook Body</label>
                            <p class="small mb-2">Create <strong>two</strong> webhooks — one for alert failure and one for alert resolved. Use the same URL and headers for both, but different body payloads:</p>

                            <label class="form-label small">Alert Failure Body <span class="text-muted">(name this webhook "PSA Alert Failure")</span></label>
                            @php
                                $failureBody = json_encode([
                                    'event' => 'alert_failure',
                                    'alert_id' => '{{alert.id}}',
                                    'agent_id' => '{{agent.agent_id}}',
                                    'hostname' => '{{agent.hostname}}',
                                    'monitoring_type' => '{{agent.monitoring_type}}',
                                    'client_name' => '{{client.name}}',
                                    'site_name' => '{{site.name}}',
                                    'alert_message' => '{{alert.message}}',
                                    'alert_type' => '{{alert.alert_type}}',
                                    'severity' => '{{alert.severity}}',
                                    'check_name' => '{{alert.assigned_check}}',
                                    'check_output' => '{{alert.get_result.stdout}}',
                                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            @endphp
                            <div class="input-group input-group-sm mb-3">
                                <textarea class="form-control font-monospace small" id="tactical_failure_body" rows="12" readonly>{{ $failureBody }}</textarea>
                                <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard('tactical_failure_body')">
                                    <i class="bi bi-clipboard me-1"></i>Copy
                                </button>
                            </div>

                            <label class="form-label small">Alert Resolved Body <span class="text-muted">(name this webhook "PSA Alert Resolved")</span></label>
                            @php
                                $resolvedBody = json_encode([
                                    'event' => 'alert_resolved',
                                    'alert_id' => '{{alert.id}}',
                                    'agent_id' => '{{agent.agent_id}}',
                                    'hostname' => '{{agent.hostname}}',
                                    'monitoring_type' => '{{agent.monitoring_type}}',
                                    'client_name' => '{{client.name}}',
                                    'site_name' => '{{site.name}}',
                                    'alert_message' => '{{alert.message}}',
                                    'alert_type' => '{{alert.alert_type}}',
                                    'severity' => '{{alert.severity}}',
                                    'check_name' => '{{alert.assigned_check}}',
                                    'check_output' => '{{alert.get_result.stdout}}',
                                    'action_stdout' => '{{alert.action_stdout}}',
                                    'action_stderr' => '{{alert.action_stderr}}',
                                    'action_retcode' => '{{alert.action_retcode}}',
                                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            @endphp
                            <div class="input-group input-group-sm mb-2">
                                <textarea class="form-control font-monospace small" id="tactical_resolved_body" rows="12" readonly>{{ $resolvedBody }}</textarea>
                                <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard('tactical_resolved_body')">
                                    <i class="bi bi-clipboard me-1"></i>Copy
                                </button>
                            </div>
                        </div>

                        {{-- Step 4: Alert Template --}}
                        <div class="mb-0">
                            <label class="form-label small fw-bold">Step 4: Create Alert Template</label>
                            <ol class="small mb-0">
                                <li>In Tactical, go to <strong>Settings &rarr; Alerts Manager</strong> and click <strong>Add</strong>.</li>
                                <li>Name the template (e.g., "PSA Integration").</li>
                                <li>Under <strong>Alert Failure Actions</strong>, select the <strong>"PSA Alert Failure"</strong> webhook.</li>
                                <li>Under <strong>Alert Resolved Actions</strong>, select the <strong>"PSA Alert Resolved"</strong> webhook.</li>
                                <li>Save the template.</li>
                                <li>Apply the template globally via <strong>Settings &rarr; Global Settings &rarr; General &rarr; Alert Template</strong>, or per-client/site/policy as needed.</li>
                            </ol>
                        </div>
                    </div>
                </div>
                @endif

                @if($tacticalConfigured)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="tactical">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="tactical_enabled" {{ $tacticalEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="tactical_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- Comet Backup --}}
        <div class="card mb-4" id="comet-section">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div>
                    <i class="bi bi-cloud-arrow-up me-2"></i>
                    <strong>Comet Backup</strong>
                </div>
                @if(\App\Support\CometConfig::isConfigured())
                    <span class="badge bg-success">Connected</span>
                @else
                    <span class="badge bg-secondary">Not Configured</span>
                @endif
            </div>
            <div class="card-body">
                @if(\App\Support\CometConfig::isConfigured())
                    <div class="alert alert-success small mb-3">
                        <i class="bi bi-check-circle me-1"></i>
                        Connected to <strong>{{ \App\Support\CometConfig::get('comet_server_url') }}</strong>
                        <button type="button" class="btn btn-outline-secondary btn-sm ms-2" id="test-comet-btn" onclick="testConnection('comet')">
                            Test Connection
                        </button>
                        <div id="test-result-comet" class="mt-2" style="display: none;"></div>
                    </div>
                @endif

                {{-- Auto-configure from Account Portal --}}
                <div class="mb-3">
                    <label class="form-label">Comet Account API Token</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="comet_account_token"
                               value="{{ \App\Support\CometConfig::get('comet_account_token') ? '••••••••' : '' }}"
                               placeholder="Paste token from account.cometbackup.com/manage_api_tokens">
                        <button type="button" class="btn btn-primary" onclick="cometAutoConfigure()">
                            <i class="bi bi-gear me-1"></i>{{ \App\Support\CometConfig::isConfigured() ? 'Reconnect' : 'Connect' }}
                        </button>
                    </div>
                    <small class="text-muted">
                        Generate a token at <a href="https://account.cometbackup.com/manage_api_tokens" target="_blank" rel="noopener">account.cometbackup.com/manage_api_tokens</a>.
                        The PSA will automatically discover your server URL and admin credentials.
                    </small>
                    <div id="comet-auto-configure-result" class="mt-2" style="display: none;"></div>
                </div>

                @if(\App\Support\CometConfig::isConfigured())
                    {{-- Sync --}}
                    <hr>
                    <form action="{{ route('settings.integrations.comet.sync-backup') }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Sync Backup Usage
                        </button>
                    </form>

                    {{-- Alert toggle --}}
                    <hr>
                    <form action="{{ route('settings.integrations.comet.update') }}" method="POST" class="d-inline-flex align-items-center gap-2">
                        @csrf
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="comet_alert_enabled" value="1"
                                   {{ \App\Support\CometConfig::alertsEnabled() ? 'checked' : '' }}
                                   onchange="this.form.submit()">
                            <label class="form-check-label">Create tickets for failed backups</label>
                        </div>
                    </form>

                    {{-- Webhook Setup --}}
                    <hr>
                    <h6><i class="bi bi-broadcast me-1"></i>Webhook Setup</h6>
                    <p class="small text-muted mb-3">
                        Configure Comet to push backup job results to this PSA.
                        When a backup fails, a ticket is created automatically.
                        When the next backup succeeds, the ticket is resolved.
                    </p>

                    @php $cometWebhookKey = \App\Support\CometConfig::get('comet_webhook_key'); @endphp

                    {{-- Step 1: Webhook Key --}}
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Step 1: Webhook Key</label>
                        @if($cometWebhookKey)
                            <p class="small text-success mb-1"><i class="bi bi-check-circle me-1"></i>Webhook key is configured.</p>
                            <form action="{{ route('settings.integrations.comet.update') }}" method="POST" class="d-inline">
                                @csrf
                                <input type="hidden" name="generate_webhook_key" value="1">
                                <button type="submit" class="btn btn-outline-warning btn-sm"
                                        onclick="return confirm('Generate a new key? The old key will stop working.')">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Regenerate Key
                                </button>
                            </form>
                        @else
                            <form action="{{ route('settings.integrations.comet.update') }}" method="POST">
                                @csrf
                                <input type="hidden" name="generate_webhook_key" value="1">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-key me-1"></i>Generate Webhook Key
                                </button>
                            </form>
                        @endif
                    </div>

                    @if($cometWebhookKey)
                    {{-- Step 2: Configure in Comet --}}
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Step 2: Create Event Streamer in Comet</label>
                        <p class="small mb-2">In your Comet server admin panel, go to <strong>Settings &rarr; Email &amp; Webhooks &rarr; Custom Webhook</strong>.</p>

                        <label class="form-label small">Webhook URL</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control font-monospace" id="comet_webhook_url"
                                   value="{{ url('/api/webhooks/comet') }}" readonly>
                            <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard('comet_webhook_url')">
                                <i class="bi bi-clipboard me-1"></i>Copy
                            </button>
                        </div>

                        <label class="form-label small">Authorization Header Value</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control font-monospace" id="comet_webhook_auth"
                                   value="Bearer {{ $cometWebhookKey }}" readonly>
                            <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard('comet_webhook_auth')">
                                <i class="bi bi-clipboard me-1"></i>Copy
                            </button>
                        </div>
                    </div>

                    {{-- Step 3: Event types --}}
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Step 3: Event Configuration</label>
                        <p class="small mb-2">In the webhook streamer settings, configure these options:</p>
                        <ul class="small mb-0">
                            <li>Add a custom header: <code>Authorization</code> with the Bearer value above</li>
                            <li>Enable event type: <strong>Job completed</strong></li>
                            <li>Leave other event types disabled (only job completion is needed)</li>
                        </ul>
                    </div>

                    <div class="alert alert-light border small mb-0">
                        <strong>How it works:</strong> Comet sends a webhook when any backup job finishes.
                        The PSA checks the status &mdash; failed jobs (status 7002) create a P3 ticket.
                        Successful jobs (status 5000) auto-resolve any matching open ticket for that device.
                        Warnings and cancellations are ignored.
                    </div>
                    @endif

                    {{-- Client mapping is now done per-client on the client detail page via integration cards --}}
                @endif
            </div>
        </div>

        </div>{{-- /rmm tab --}}

        {{-- ============================================================ --}}
        {{-- LICENSING TAB --}}
        {{-- ============================================================ --}}
        <div class="tab-pane fade" id="licensing" role="tabpanel">

        {{-- Mesh Email Security Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-envelope-check me-2"></i>Mesh Email Security
                </div>
                @if($meshConnected ?? false)
                    <span class="badge bg-success">Connected</span>
                @elseif($meshHasApiKey ?? false)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Connect to Mesh Email Security to sync license counts for billing.
                    Get your API key from the Mesh admin portal under Settings &rarr; API.
                </p>

                <form method="POST" action="{{ route('settings.integrations.mesh.update') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="mesh_api_key" class="form-label">API Key</label>
                            <input type="password"
                                   class="form-control"
                                   id="mesh_api_key"
                                   name="api_key"
                                   value=""
                                   placeholder="{{ ($meshHasApiKey ?? false) ? '••••••••' : 'Enter API key' }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="mesh_base_url" class="form-label">Base URL</label>
                            <input type="url"
                                   class="form-control"
                                   id="mesh_base_url"
                                   name="base_url"
                                   value="{{ $meshBaseUrl ?? 'https://hub-us.emailsecurity.app' }}"
                                   placeholder="https://hub-us.emailsecurity.app">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Save Mesh Settings</button>
                        <button type="button" class="btn btn-outline-secondary" id="test-mesh-btn"
                                onclick="testConnection('mesh')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                    <div id="test-result-mesh" class="alert mt-2" style="display:none;"></div>
                </form>

                @if($meshConnected ?? false)
                <div class="mt-3 pt-3 border-top">
                    <a href="{{ route('settings.mesh-customers.index') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-diagram-3 me-1"></i>Customer Mapping
                    </a>
                    <form method="POST" action="{{ route('settings.integrations.mesh.sync') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Sync Licenses Now
                        </button>
                    </form>
                </div>
                @endif

                @if($meshHasApiKey)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="mesh">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="mesh_enabled" {{ $meshEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="mesh_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- CIPP / Microsoft 365 Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-microsoft me-2"></i>CIPP / Microsoft 365
                </div>
                @if($cippConnected ?? false)
                    <span class="badge bg-success">Connected</span>
                @elseif($cippConfigured ?? false)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Connect to CIPP (CyberDrain Improved Partner Portal) to sync M365 license counts.
                    Requires an Azure AD app registration with client credentials.
                </p>

                <form method="POST" action="{{ route('settings.integrations.cipp.update') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="cipp_api_url" class="form-label">CIPP API URL</label>
                            <input type="url" class="form-control" id="cipp_api_url" name="api_url"
                                   value="{{ $cippApiUrl ?? '' }}"
                                   placeholder="https://your-cipp.azurewebsites.net">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="cipp_tenant_id" class="form-label">Azure AD Tenant ID</label>
                            <input type="text" class="form-control" id="cipp_tenant_id" name="tenant_id"
                                   value="{{ $cippTenantId ?? '' }}"
                                   placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="cipp_client_id" class="form-label">Client ID</label>
                            <input type="text" class="form-control" id="cipp_client_id" name="client_id"
                                   value="{{ $cippClientId ?? '' }}"
                                   placeholder="OAuth client ID">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="cipp_client_secret" class="form-label">Client Secret</label>
                            <input type="password" class="form-control" id="cipp_client_secret" name="client_secret"
                                   value=""
                                   placeholder="{{ ($cippHasSecret ?? false) ? '••••••••' : 'Enter client secret' }}">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="cipp_application_id" class="form-label">Application ID <small class="text-muted">(optional)</small></label>
                            <input type="text" class="form-control" id="cipp_application_id" name="application_id"
                                   value="{{ $cippApplicationId ?? '' }}"
                                   placeholder="Defaults to Client ID">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Save CIPP Settings</button>
                        <button type="button" class="btn btn-outline-secondary" id="test-cipp-btn"
                                onclick="testConnection('cipp')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                    <div id="test-result-cipp" class="alert mt-2" style="display:none;"></div>
                </form>

                @if($cippConnected ?? false)
                <div class="mt-3 pt-3 border-top">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <a href="{{ route('settings.cipp-tenants.index') }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-diagram-3 me-1"></i>Tenant Mapping
                        </a>
                        <form method="POST" action="{{ route('settings.integrations.cipp.sync') }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-arrow-repeat me-1"></i>Sync Licenses Now
                            </button>
                        </form>
                        <button type="button" class="btn btn-outline-success btn-sm" id="cipp-contact-preview-btn"
                                onclick="previewCippSync('contacts')">
                            <i class="bi bi-people me-1"></i>Sync Contacts...
                        </button>
                        <button type="button" class="btn btn-outline-info btn-sm" id="cipp-device-preview-btn"
                                onclick="previewCippSync('devices')">
                            <i class="bi bi-laptop me-1"></i>Sync Devices...
                        </button>
                    </div>

                    <div class="d-flex flex-column gap-2 mb-2">
                        <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                            @csrf
                            <input type="hidden" name="integration" value="cipp_contact_sync">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                       id="cipp_contact_sync_enabled" {{ ($cippContactSyncEnabled ?? false) ? 'checked' : '' }} onchange="this.form.submit()">
                                <label class="form-check-label" for="cipp_contact_sync_enabled">
                                    Auto-sync contacts daily
                                    <small class="text-muted d-block">Syncs M365 users + mailbox size + MFA status at 05:55</small>
                                </label>
                            </div>
                        </form>
                        <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                            @csrf
                            <input type="hidden" name="integration" value="cipp_device_sync">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                       id="cipp_device_sync_enabled" {{ ($cippDeviceSyncEnabled ?? false) ? 'checked' : '' }} onchange="this.form.submit()">
                                <label class="form-check-label" for="cipp_device_sync_enabled">
                                    Auto-sync devices daily
                                    <small class="text-muted d-block">Syncs Intune devices + Defender state at 05:59</small>
                                </label>
                            </div>
                        </form>
                    </div>

                    <div id="cipp-sync-preview" class="mt-3" style="display:none;">
                        <div id="cipp-sync-preview-loading" class="alert alert-info">
                            <span class="spinner-border spinner-border-sm me-1"></span> Running preview — this may take a moment...
                        </div>
                        <div id="cipp-sync-preview-result" style="display:none;">
                            <div class="alert alert-info mb-2">
                                <strong>Preview:</strong>
                                <span id="cipp-sync-preview-summary"></span>
                            </div>
                            <div id="cipp-sync-preview-details" style="max-height: 300px; overflow-y: auto;"></div>
                            <div id="cipp-sync-preview-errors" class="alert alert-warning mt-2" style="display:none;"></div>
                            <div class="mt-2">
                                <form method="POST" id="cipp-sync-confirm-form" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="bi bi-check-circle me-1"></i>Confirm &amp; Sync
                                    </button>
                                </form>
                                <button type="button" class="btn btn-outline-secondary btn-sm ms-1" onclick="document.getElementById('cipp-sync-preview').style.display='none'">
                                    Cancel
                                </button>
                            </div>
                        </div>
                        <div id="cipp-sync-preview-error" class="alert alert-danger" style="display:none;"></div>
                    </div>
                </div>
                @endif

                @if($cippConfigured)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="cipp">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="cipp_enabled" {{ $cippEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="cipp_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- Huntress EDR/ITDR Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-shield-check me-2"></i>Huntress EDR / ITDR
                </div>
                @if($huntressConnected ?? false)
                    <span class="badge bg-success">Connected</span>
                @elseif($huntressConfigured ?? false)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Syncs EDR, ITDR, and SAT license counts from Huntress for billing. Runs daily at 05:00 or on demand.
                </p>
                <div class="alert alert-light border small mb-3">
                    <strong>Setup:</strong>
                    <ol class="mb-0 ps-3 mt-1">
                        <li>In Huntress, go to <strong>Account &rarr; API Credentials</strong> and generate an API key.</li>
                        <li>Enter the API Key and API Secret below and click <strong>Save</strong>, then <strong>Test Connection</strong>.</li>
                        <li>Click <strong>Organization Mapping</strong> to map Huntress organizations to your clients. Use <strong>Auto-Match by Name</strong> for bulk matching.</li>
                        <li>Only mapped organizations will have their licenses synced. Unmapped organizations are ignored.</li>
                    </ol>
                    <hr class="my-2">
                    <strong>Shared Huntress accounts:</strong> If multiple MSPs share a Huntress account, each MSP maps only their own clients' organizations. Unmapped orgs are skipped during sync.
                </div>

                <form method="POST" action="{{ route('settings.integrations.huntress.update') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="huntress_api_key" class="form-label">API Key</label>
                            <input type="password"
                                   class="form-control"
                                   id="huntress_api_key"
                                   name="api_key"
                                   value=""
                                   placeholder="{{ ($huntressConfigured ?? false) ? '••••••••' : 'Enter API key' }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="huntress_api_secret" class="form-label">API Secret</label>
                            <input type="password"
                                   class="form-control"
                                   id="huntress_api_secret"
                                   name="api_secret"
                                   value=""
                                   placeholder="{{ ($huntressConfigured ?? false) ? '••••••••' : 'Enter API secret' }}">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Save Huntress Settings</button>
                        <button type="button" class="btn btn-outline-secondary" id="test-huntress-btn"
                                onclick="testConnection('huntress')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                    <div id="test-result-huntress" class="alert mt-2" style="display:none;"></div>
                </form>

                @if($huntressConnected ?? false)
                <div class="mt-3 pt-3 border-top">
                    <a href="{{ route('settings.huntress-orgs.index') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-diagram-3 me-1"></i>Organization Mapping
                    </a>
                    <form method="POST" action="{{ route('settings.integrations.huntress.sync') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Sync Licenses Now
                        </button>
                    </form>
                </div>
                @endif

                @if($huntressConfigured)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="huntress">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="huntress_enabled" {{ $huntressEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="huntress_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- Servosity Backup Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-cloud-check me-2"></i>Servosity Backup
                </div>
                @if($servosityConnected ?? false)
                    <span class="badge bg-success">Connected</span>
                @elseif($servosityConfigured ?? false)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Syncs M365 and server backup license counts from Servosity for billing. Runs daily at 05:45 or on demand.
                </p>
                <div class="alert alert-light border small mb-3">
                    <strong>Setup:</strong>
                    <ol class="mb-0 ps-3 mt-1">
                        <li>In the Servosity portal, copy your <strong>API Token</strong>.</li>
                        <li>Enter the token below and click <strong>Save</strong>, then <strong>Test Connection</strong>.</li>
                        <li>Click <strong>Company Mapping</strong> to map Servosity companies to your clients. Use <strong>Auto-Match by Name</strong> for bulk matching.</li>
                        <li>Only mapped companies will have their licenses synced. Unmapped companies are ignored.</li>
                    </ol>
                </div>

                <form method="POST" action="{{ route('settings.integrations.servosity.update') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="servosity_api_token" class="form-label">API Token</label>
                            <input type="password"
                                   class="form-control"
                                   id="servosity_api_token"
                                   name="api_token"
                                   value=""
                                   placeholder="{{ ($servosityConfigured ?? false) ? '••••••••' : 'Enter API token' }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="servosity_base_url" class="form-label">Base URL <small class="text-muted">(optional)</small></label>
                            <input type="url"
                                   class="form-control"
                                   id="servosity_base_url"
                                   name="base_url"
                                   value=""
                                   placeholder="https://api.servosity.com">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="servosity_totp_secret" class="form-label">TOTP Secret <small class="text-muted">(for automated credential creation)</small></label>
                            <input type="password"
                                   class="form-control"
                                   id="servosity_totp_secret"
                                   name="totp_secret"
                                   value=""
                                   placeholder="{{ \App\Support\ServosityConfig::get('totp_secret') ? '••••••••' : 'Base32 secret from authenticator setup' }}">
                            <small class="text-muted">The base32 secret from your authenticator app MFA setup. The TOTP enrollment ID is detected automatically.</small>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Save Servosity Settings</button>
                        <button type="button" class="btn btn-outline-secondary" id="test-servosity-btn"
                                onclick="testConnection('servosity')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                    <div id="test-result-servosity" class="alert mt-2" style="display:none;"></div>
                </form>

                @if($servosityConnected ?? false)
                <div class="mt-3 pt-3 border-top">
                    <a href="{{ route('settings.servosity-companies.index') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-diagram-3 me-1"></i>Company Mapping
                    </a>
                    <form method="POST" action="{{ route('settings.integrations.servosity.sync') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Sync Licenses Now
                        </button>
                    </form>
                    @if($servosityConnectedAt)
                    <small class="text-muted ms-2">Last connected: {{ $servosityConnectedAt }}</small>
                    @endif
                </div>
                @endif

                @if($servosityConfigured)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="servosity">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="servosity_enabled" {{ $servosityEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="servosity_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- Control D DNS Security Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-shield-lock me-2"></i>Control D DNS Security
                </div>
                @if($controldConnected ?? false)
                    <span class="badge bg-success">Connected</span>
                @elseif($controldConfigured ?? false)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Syncs endpoint and router device counts from Control D for billing. Runs daily at 05:10 or on demand.
                </p>
                <div class="alert alert-light border small mb-3">
                    <strong>Setup:</strong>
                    <ol class="mb-0 ps-3 mt-1">
                        <li>In the Control D admin portal, copy your <strong>API Key</strong> (Bearer token).</li>
                        <li>Enter the key below and click <strong>Save</strong>, then <strong>Test Connection</strong>.</li>
                        <li>Click <strong>Organization Mapping</strong> to map Control D sub-organizations to your clients. Use <strong>Auto-Match by Name</strong> for bulk matching (exact, case-insensitive).</li>
                        <li>Only mapped sub-organizations will have their device counts synced. Unmapped sub-orgs are ignored.</li>
                    </ol>
                </div>

                <form method="POST" action="{{ route('settings.integrations.controld.update') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="controld_api_key" class="form-label">API Key</label>
                            <input type="password"
                                   class="form-control"
                                   id="controld_api_key"
                                   name="api_key"
                                   value=""
                                   placeholder="{{ ($controldConfigured ?? false) ? '••••••••' : 'Enter API key' }}">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Save Control D Settings</button>
                        <button type="button" class="btn btn-outline-secondary" id="test-controld-btn"
                                onclick="testConnection('controld')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                </form>
                <div id="test-result-controld" class="alert mt-2" style="display:none;"></div>

                @if($controldConnected ?? false)
                <div class="border-top pt-3 mt-3 d-flex gap-2 flex-wrap">
                    <a href="{{ route('settings.controld-orgs.index') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-diagram-3 me-1"></i>Organization Mapping
                    </a>
                    <form method="POST" action="{{ route('settings.integrations.controld.sync') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Sync Licenses Now
                        </button>
                    </form>
                    <form method="POST" action="{{ route('settings.integrations.controld.sync-devices') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-pc-display me-1"></i>Sync Devices Now
                        </button>
                    </form>
                </div>

                {{-- Analytics Status --}}
                @if(\App\Support\ControlDConfig::isAnalyticsConfigured())
                <div class="border-top pt-3 mt-3">
                    <span class="text-muted small">
                        <i class="bi bi-activity me-1"></i>DNS Analytics: <strong>{{ \App\Support\ControlDConfig::get('stats_endpoint') }}</strong>
                        <span class="text-success ms-1">(auto-detected)</span>
                    </span>
                </div>
                @endif
                @endif

                @if($controldConfigured)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="controld">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="controld_enabled" {{ $controldEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="controld_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- Zorus DNS Security Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-shield-check me-2"></i>Zorus DNS Security
                </div>
                @if($zorusConnected ?? false)
                    <span class="badge bg-success">Connected</span>
                @elseif($zorusConfigured ?? false)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Syncs endpoint, filtering, and CyberSight counts from Zorus for billing. Enriches assets with DNS agent data. Runs daily at 05:18 or on demand.
                </p>
                <div class="alert alert-light border small mb-3">
                    <strong>Setup:</strong>
                    <ol class="mb-0 ps-3 mt-1">
                        <li>In the Zorus admin portal, generate an <strong>API Key</strong>.</li>
                        <li>Enter the key below and click <strong>Save</strong>, then <strong>Test Connection</strong>.</li>
                        <li>Click <strong>Customer Mapping</strong> to map Zorus customers to your clients. Use <strong>Auto-Match by Name</strong> for bulk matching (exact, case-insensitive).</li>
                        <li>Only mapped customers will have their endpoint counts and device data synced. Unmapped customers are ignored.</li>
                    </ol>
                </div>

                <form method="POST" action="{{ route('settings.integrations.zorus.update') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="zorus_api_key" class="form-label">API Key</label>
                            <input type="password"
                                   class="form-control"
                                   id="zorus_api_key"
                                   name="api_key"
                                   value=""
                                   placeholder="{{ ($zorusConfigured ?? false) ? '••••••••' : 'Enter API key' }}">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Save Zorus Settings</button>
                        <button type="button" class="btn btn-outline-secondary" id="test-zorus-btn"
                                onclick="testConnection('zorus')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                </form>
                <div id="test-result-zorus" class="alert mt-2" style="display:none;"></div>

                @if($zorusConnected ?? false)
                <div class="border-top pt-3 mt-3 d-flex gap-2 flex-wrap">
                    <a href="{{ route('settings.zorus-customers.index') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-diagram-3 me-1"></i>Customer Mapping
                    </a>
                    <form method="POST" action="{{ route('settings.integrations.zorus.sync') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Sync Licenses Now
                        </button>
                    </form>
                    <form method="POST" action="{{ route('settings.integrations.zorus.sync-devices') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-pc-display me-1"></i>Sync Devices Now
                        </button>
                    </form>
                </div>
                @endif

                @if($zorusConfigured)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="zorus">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="zorus_enabled" {{ $zorusEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="zorus_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- AppRiver M365 Licensing Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-microsoft me-2"></i>AppRiver (OpenText) M365 Licensing
                </div>
                @if($appriverConnected ?? false)
                    <span class="badge bg-success">Connected</span>
                @elseif($appriverConfigured ?? false)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Syncs M365 subscription seat counts from AppRiver (reseller view). Shows assigned vs total for utilization tracking. Supports seat count changes for onboarding/offboarding. Runs daily at 05:50 or on demand.
                </p>
                <div class="alert alert-light border small mb-3">
                    <strong>Setup:</strong>
                    <ol class="mb-0 ps-3 mt-1">
                        <li>In the <strong>OpenText Cloud Management Portal</strong> (cp.appriver.com), go to <strong>Integrations &gt; API</strong> and create API credentials.</li>
                        <li>Enter the <strong>Client ID</strong> and <strong>Client Secret</strong> below and click <strong>Save</strong>, then <strong>Test Connection</strong>.</li>
                        <li>Click <strong>Customer Mapping</strong> to map AppRiver customers to your clients. Use <strong>Auto-Match by Name</strong> for bulk matching.</li>
                        <li>Only mapped customers will have their subscriptions synced. Unmapped customers are ignored.</li>
                    </ol>
                </div>
                @if($appriverConfigured && $appriverConnected)
                <div class="alert alert-info border small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>CIPP overlap:</strong> AppRiver provides the reseller-side view of M365 licensing (what you purchased and assigned). If you also use CIPP, both coexist — they track different perspectives with separate license records.
                </div>
                @endif

                <form method="POST" action="{{ route('settings.integrations.appriver.update') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="appriver_client_id" class="form-label">Client ID</label>
                            <input type="password"
                                   class="form-control"
                                   id="appriver_client_id"
                                   name="client_id"
                                   value=""
                                   placeholder="{{ ($appriverConfigured ?? false) ? '••••••••' : 'Enter Client ID' }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="appriver_client_secret" class="form-label">Client Secret</label>
                            <input type="password"
                                   class="form-control"
                                   id="appriver_client_secret"
                                   name="client_secret"
                                   value=""
                                   placeholder="{{ ($appriverConfigured ?? false) ? '••••••••' : 'Enter Client Secret' }}">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Save AppRiver Settings</button>
                    </div>
                </form>

                @if($appriverConfigured)
                <div class="mt-3 pt-3 border-top">
                    @if($appriverConnected)
                        <a href="{{ route('settings.appriver-customers.index') }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-diagram-3 me-1"></i>Customer Mapping
                        </a>
                        <form method="POST" action="{{ route('settings.integrations.appriver.sync') }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-arrow-repeat me-1"></i>Sync Licenses Now
                            </button>
                        </form>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="test-appriver-btn"
                                onclick="testConnection('appriver')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                        @if($appriverConnectedAt)
                        <small class="text-muted ms-2">Last connected: {{ $appriverConnectedAt }}</small>
                        @endif
                    @else
                        <a href="{{ route('auth.appriver') }}" class="btn btn-primary btn-sm">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Connect to AppRiver
                        </a>
                        <small class="text-muted ms-2">You will be redirected to log in with your AppRiver admin credentials.</small>
                    @endif
                    <div id="test-result-appriver" class="alert mt-2" style="display:none;"></div>
                </div>
                @endif

                @if($appriverConfigured)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="appriver">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="appriver_enabled" {{ $appriverEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="appriver_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- Printix Cloud Print Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-printer me-2"></i>Printix (Tungsten Automation)
                </div>
                @if($printixConnected ?? false)
                    <span class="badge bg-success">Connected</span>
                @elseif($printixConfigured ?? false)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Syncs Printix cloud print license counts (user licenses and printing users). Runs daily at 05:52 or on demand.
                </p>

                <form method="POST" action="{{ route('settings.integrations.printix.update') }}">
                    @csrf
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Client ID</label>
                            <input type="text" class="form-control" name="client_id"
                                   value="{{ \App\Support\PrintixConfig::get('client_id') }}" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Client Secret</label>
                            <input type="password" class="form-control" name="client_secret"
                                   placeholder="{{ ($printixHasSecret ?? false) ? '••••••••' : '' }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Partner ID</label>
                            <input type="text" class="form-control" name="partner_id"
                                   value="{{ $printixPartnerId ?? '' }}" required placeholder="UUID">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Save Printix Settings</button>
                        <button type="button" class="btn btn-outline-secondary" id="test-printix-btn"
                                onclick="testConnection('printix')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                    <div id="test-result-printix" class="alert mt-2" style="display:none;"></div>
                </form>

                @if($printixConnected ?? false)
                <div class="mt-3 pt-3 border-top">
                    <a href="{{ route('settings.printix-tenants.index') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-diagram-3 me-1"></i>Tenant Mapping
                    </a>
                    <form method="POST" action="{{ route('settings.integrations.printix.sync') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Sync Licenses Now
                        </button>
                    </form>
                </div>
                @endif

                @if($printixConfigured)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="printix">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="printix_enabled" {{ $printixEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="printix_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        </div>{{-- /licensing tab --}}

        {{-- ============================================================ --}}
        {{-- COMMUNICATIONS TAB --}}
        {{-- ============================================================ --}}
        <div class="tab-pane fade" id="communications" role="tabpanel">

        {{-- Plivo Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-telephone me-2"></i>Plivo (Phone System)
                </div>
                @if($plivoConnectedAt)
                    <span class="badge bg-success">Connected</span>
                @elseif($plivoAuthId)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Connect to Plivo for inbound/outbound calling and IVR.
                    Get your credentials from
                    <a href="https://console.plivo.com/dashboard/" target="_blank">Plivo Console</a>.
                </p>

                <form method="POST" action="{{ route('settings.integrations.plivo.update') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="plivo_auth_id" class="form-label">Auth ID</label>
                            <input type="text"
                                   class="form-control @error('auth_id') is-invalid @enderror"
                                   id="plivo_auth_id"
                                   name="auth_id"
                                   value="{{ old('auth_id', $plivoAuthId) }}"
                                   required>
                            @error('auth_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="plivo_auth_token" class="form-label">Auth Token</label>
                            <input type="password"
                                   class="form-control @error('auth_token') is-invalid @enderror"
                                   id="plivo_auth_token"
                                   name="auth_token"
                                   placeholder="{{ $plivoHasToken ? 'Leave blank to keep current' : 'Enter Auth Token' }}">
                            @error('auth_token')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="plivo_did_number" class="form-label">DID Number</label>
                            <input type="text"
                                   class="form-control @error('did_number') is-invalid @enderror"
                                   id="plivo_did_number"
                                   name="did_number"
                                   value="{{ old('did_number', $plivoDidNumber) }}"
                                   placeholder="14255551234"
                                   required>
                            @error('did_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="plivo_app_id" class="form-label">App ID</label>
                            <input type="text"
                                   class="form-control @error('app_id') is-invalid @enderror"
                                   id="plivo_app_id"
                                   name="app_id"
                                   value="{{ old('app_id', $plivoAppId) }}"
                                   required>
                            @error('app_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="plivo_webhook_secret" class="form-label">Webhook Secret</label>
                        <input type="password"
                               class="form-control @error('webhook_secret') is-invalid @enderror"
                               id="plivo_webhook_secret"
                               name="webhook_secret"
                               placeholder="{{ $plivoHasWebhookSecret ? 'Leave blank to keep current' : 'Enter Webhook Secret' }}">
                        <div class="form-text">Used to authenticate Plivo webhook callbacks.</div>
                        @error('webhook_secret')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    @if($plivoConnectedAt)
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="bi bi-check-circle text-success me-1"></i>Last connected: {{ $plivoConnectedAt }}
                        </small>
                    </div>
                    @endif

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Credentials
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="test-plivo-btn" onclick="testConnection('plivo')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                </form>

                <div id="test-result-plivo" class="mt-3" style="display: none;"></div>

                @if($plivoHasWebhookSecret)
                <div class="border-top pt-3 mt-3">
                    <h6 class="fw-semibold mb-2"><i class="bi bi-link-45deg me-1"></i>Webhook URLs</h6>
                    <p class="text-muted small mb-2">Use these URLs when configuring PHLO callback nodes in Plivo Console.</p>
                    @php
                        $webhookBase = url('/api/plivo/' . \App\Support\PlivoConfig::get('webhook_secret'));
                    @endphp
                    <div class="mb-2">
                        <label class="form-label small mb-1">Main Webhook URL</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control bg-light" readonly value="{{ $webhookBase }}/webhook" id="plivo-webhook-url">
                            <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('plivo-webhook-url').value); this.innerHTML='<i class=\'bi bi-check\'></i>'; setTimeout(() => this.innerHTML='<i class=\'bi bi-clipboard\'></i>', 1500)">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small mb-1">Browser Answer URL</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control bg-light" readonly value="{{ $webhookBase }}/browser-answer" id="plivo-browser-url">
                            <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('plivo-browser-url').value); this.innerHTML='<i class=\'bi bi-check\'></i>'; setTimeout(() => this.innerHTML='<i class=\'bi bi-clipboard\'></i>', 1500)">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>
                </div>
                @endif

                @if($plivoAuthId)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="plivo">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="plivo_enabled" {{ $plivoEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="plivo_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>
        {{-- Microsoft Graph (Email) Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-envelope me-2"></i>Microsoft Graph (Email)
                </div>
                @if($graphConnectedAt)
                    <span class="badge bg-success">Connected</span>
                @elseif($graphMailbox)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Read emails from a shared mailbox via Microsoft Graph API.
                    Uses the same Entra app registration as SSO.
                    Requires <strong>Mail.Read</strong> (Application) permission with admin consent in
                    <a href="https://portal.azure.com" target="_blank">Azure Portal</a>.
                </p>

                <form method="POST" action="{{ route('settings.integrations.graph.update') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="graph_mailbox" class="form-label">Mailbox Address</label>
                        <input type="email"
                               class="form-control @error('mailbox') is-invalid @enderror"
                               id="graph_mailbox"
                               name="mailbox"
                               value="{{ old('mailbox', $graphMailbox) }}"
                               placeholder="helpdesk@yourcompany.com"
                               required>
                        <div class="form-text">The shared mailbox to monitor for incoming emails.</div>
                        @error('mailbox')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    @if($graphConnectedAt)
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="bi bi-check-circle text-success me-1"></i>Last connected: {{ $graphConnectedAt }}
                        </small>
                    </div>
                    @endif

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Mailbox
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="test-graph-btn" onclick="testConnection('graph')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                </form>

                <div id="test-result-graph" class="mt-3" style="display: none;"></div>

                <hr class="my-3">

                <form method="POST" action="{{ route('settings.integrations.graph.update-signature') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="email_signature" class="form-label">Email Signature</label>
                        <textarea class="form-control @error('email_signature') is-invalid @enderror"
                                  id="email_signature"
                                  name="email_signature"
                                  rows="4"
                                  placeholder="e.g. Thanks,&#10;Jane Doe&#10;Acme MSP&#10;(123) 456-7890">{{ old('email_signature', $graphEmailSignature) }}</textarea>
                        @error('email_signature')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">Plain text, appended to all outbound emails from this mailbox. Line breaks are preserved.</div>
                    </div>

                    <div class="form-check mt-3">
                        <input type="checkbox" name="email_auto_ticket" value="1"
                               class="form-check-input" id="email_auto_ticket"
                               {{ $emailAutoTicket ? 'checked' : '' }}>
                        <label class="form-check-label" for="email_auto_ticket">
                            Auto-create tickets from inbound emails
                        </label>
                        <div class="form-text">
                            When enabled, inbound emails from known clients automatically create a new
                            ticket if no existing conversation is found. Emails from unknown senders are
                            never auto-ticketed regardless of this setting. Auto-created tickets default
                            to P3 priority, Incident type, and no assignee — triage manually as needed.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary mt-3">
                        <i class="bi bi-check-lg me-1"></i>Save Signature
                    </button>
                </form>

                @if($graphMailbox)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="graph">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="graph_enabled" {{ $graphEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="graph_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- Tier2Tickets / HelpDesk Buttons Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-mouse me-2"></i>Tier2Tickets / HelpDesk Buttons
                </div>
                @if($t2tConfigured)
                    <span class="badge bg-success">Configured</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    ConnectWise Manage API compatibility layer for
                    <a href="https://www.tier2tickets.com" target="_blank">Tier2Tickets</a>.
                    In T2T, select <strong>ConnectWise Manage</strong> as the PSA and enter the API URL and key below.
                </p>

                <div class="mb-3">
                    <label class="form-label">Ticket System API Endpoint (for T2T)</label>
                    <div class="input-group">
                        <input type="text" class="form-control bg-light font-monospace" id="t2t_api_url" value="{{ $t2tApiUrl }}" readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('t2t_api_url')">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                    <div class="form-text">
                        Paste this into T2T's <strong>Ticket System API endpoint</strong> field.
                        Do not include <code>https://</code> — T2T adds the protocol automatically.
                    </div>
                </div>

                @if(session('t2t_generated_key'))
                <div class="alert alert-warning">
                    <i class="bi bi-key me-1"></i>
                    <strong>Ticket System API Key (copy now):</strong>
                    <div class="input-group mt-1">
                        <input type="text" class="form-control font-monospace bg-light" id="t2t_full_key" value="{{ session('t2t_generated_key') }}" readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('t2t_full_key')">
                            <i class="bi bi-clipboard"></i> Copy
                        </button>
                    </div>
                    <small class="d-block mt-1">This key will not be shown again. Paste it into T2T's <strong>Ticket System API Key</strong> field.</small>
                </div>
                @endif

                <form method="POST" action="{{ route('settings.integrations.t2t.update') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="t2t_company_id" class="form-label">Company ID</label>
                        <input type="text"
                               class="form-control"
                               id="t2t_company_id"
                               name="company_id"
                               value="{{ $t2tCompanyId }}"
                               placeholder="SoundPSA">
                        <div class="form-text">
                            Used as the CompanyId portion of generated API keys (e.g., <code>{{ $t2tCompanyId }}+pubkey:privatekey</code>).
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="t2t_api_key" class="form-label">Private Key (manual override)</label>
                        <input type="password"
                               class="form-control @error('api_key') is-invalid @enderror"
                               id="t2t_api_key"
                               name="api_key"
                               placeholder="{{ $t2tConfigured ? 'Leave blank to keep current' : 'Generate a key below or enter manually' }}">
                        @error('api_key')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            Only the private key portion (after the <code>:</code>) is stored and validated.
                            Use <strong>Generate</strong> below for the recommended workflow.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="t2t_system_user_id" class="form-label">System User (for audit trail)</label>
                        <select class="form-select" id="t2t_system_user_id" name="system_user_id">
                            <option value="">Auto (first admin user)</option>
                            @foreach($t2tUsers as $user)
                                <option value="{{ $user->id }}" {{ $t2tSystemUserId == $user->id ? 'selected' : '' }}>
                                    {{ $user->name }}{{ !$user->is_active ? ' (Inactive)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            T2T-created tickets and status changes are attributed to this user.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="t2t_callback_url" class="form-label">Ticket Notifications Webhook URL</label>
                        <input type="url" class="form-control font-monospace"
                               id="t2t_callback_url"
                               name="callback_url"
                               value="{{ $t2tCallbackUrl }}"
                               placeholder="https://...execute-api.../production/v101/thook/...">
                        <div class="form-text">
                            T2T webhook URL for ticket status change notifications. Found in T2T's ConnectWise integration docs or provided by T2T support.
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Settings
                        </button>
                    </div>
                </form>

                <hr class="my-3">

                <form method="POST" action="{{ route('settings.integrations.t2t.generate-key') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-warning"
                            onclick="return confirm('This will replace the current API key. Continue?')">
                        <i class="bi bi-key me-1"></i>Generate New API Key
                    </button>
                    <div class="form-text mt-1">
                        Generates a full <code>CompanyId+PublicKey:PrivateKey</code> string ready to paste into T2T.
                    </div>
                </form>

                @if($t2tConfigured)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="t2t">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="t2t_enabled" {{ $t2tEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="t2t_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        {{-- Huntress Incident Reports Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-shield-exclamation me-2"></i>Huntress Incident Reports
                </div>
                @if($huntressCwConfigured)
                    <span class="badge bg-success">Configured</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Receives Huntress security incident alerts as tickets via ConnectWise-compatible webhooks.
                    Severity mapping: CRITICAL &rarr; P1, HIGH &rarr; P2, LOW &rarr; P3.
                </p>
                <div class="alert alert-light border small mb-3">
                    <strong>Setup:</strong>
                    <ol class="mb-0 ps-3 mt-1">
                        <li>Click <strong>Generate New Credentials</strong> below to create the API key.</li>
                        <li>In Huntress, go to <strong>Integrations &rarr; Add &rarr; ConnectWise Manage</strong>.</li>
                        <li>Copy the four values (Host, Company ID, Public Key, Private Key) into the Huntress form.</li>
                        <li>Huntress will auto-map organizations to your clients by name. Fix any unmatched ones manually.</li>
                        <li>On the <strong>Ticket Routing</strong> step: set Source to <strong>Huntress</strong>. Leave Subtype and Item blank.</li>
                        <li>Skip the <strong>Billing</strong> tab &mdash; license sync is handled by the Licensing integration above, not through CW billing.</li>
                    </ol>
                    <hr class="my-2">
                    <strong>Shared Huntress accounts:</strong> Incidents for unmapped organizations are silently dropped. Only incidents matching your mapped clients create tickets.
                </div>

                @if(session('huntress_cw_generated'))
                @php $hcwKey = session('huntress_cw_generated'); @endphp
                <div class="alert alert-warning">
                    <i class="bi bi-key me-1"></i>
                    <strong>New credentials generated — copy these into the Huntress portal now:</strong>
                    <div class="row mt-2">
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">ConnectWise Host</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control font-monospace bg-light" id="huntress_cw_host_gen" value="{{ $hcwKey['host'] }}" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('huntress_cw_host_gen')">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">Company ID</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control font-monospace bg-light" id="huntress_cw_company_gen" value="{{ $hcwKey['company_id'] }}" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('huntress_cw_company_gen')">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">Public Key</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control font-monospace bg-light" id="huntress_cw_public_gen" value="{{ $hcwKey['public_key'] }}" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('huntress_cw_public_gen')">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">Private Key</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control font-monospace bg-light" id="huntress_cw_private_gen" value="{{ $hcwKey['private_key'] }}" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('huntress_cw_private_gen')">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <small class="d-block mt-1">The private key will not be shown again after you leave this page.</small>
                </div>
                @endif

                <div class="row mb-3">
                    <div class="col-md-6 mb-2">
                        <label class="form-label">ConnectWise Host</label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-light font-monospace" id="huntress_cw_host" value="{{ $huntressCwHost }}" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('huntress_cw_host')">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label">Company ID</label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-light font-monospace" id="huntress_cw_company" value="{{ $huntressCwCompanyId }}" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('huntress_cw_company')">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label">Public Key</label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-light font-monospace" id="huntress_cw_public" value="{{ $huntressCwPublicKey ?: '—' }}" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('huntress_cw_public')">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label">Private Key</label>
                        <div class="input-group">
                            <input type="password" class="form-control bg-light font-monospace" id="huntress_cw_private" value="{{ $huntressCwConfigured ? '••••••••••••••••' : '' }}" readonly>
                        </div>
                        <div class="form-text">This is the secret validated by Sound PSA. Masked for security.</div>
                    </div>
                </div>

                <form method="POST" action="{{ route('settings.integrations.huntress-cw.update') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="huntress_cw_system_user_id" class="form-label">System User (for audit trail)</label>
                        <select class="form-select" id="huntress_cw_system_user_id" name="system_user_id">
                            <option value="">Auto (first admin user)</option>
                            @foreach($huntressCwUsers as $user)
                                <option value="{{ $user->id }}" {{ $huntressCwSystemUserId == $user->id ? 'selected' : '' }}>
                                    {{ $user->name }}{{ !$user->is_active ? ' (Inactive)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            Huntress incident tickets and status changes are attributed to this user.
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Settings
                        </button>
                    </div>
                </form>

                <hr class="my-3">

                <form method="POST" action="{{ route('settings.integrations.huntress-cw.generate-key') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-warning"
                            onclick="return confirm('This will invalidate current credentials immediately. Huntress will stop sending incidents until you update credentials in the Huntress portal. Continue?')">
                        <i class="bi bi-key me-1"></i>Generate New Credentials
                    </button>
                    <div class="form-text mt-1">
                        Generates ConnectWise Host, Company ID, Public Key, and Private Key ready to paste into the Huntress portal.
                    </div>
                </form>
            </div>
        </div>

        {{-- Ticket Automation Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header">
                <i class="bi bi-clock-history me-2"></i>Ticket Automation
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.integrations.tickets.update') }}">
                    @csrf
                    <div class="mb-3" style="max-width: 300px;">
                        <label for="auto_close_resolved_days" class="form-label">Auto-close resolved tickets after</label>
                        <div class="input-group input-group-sm">
                            <input type="number"
                                   class="form-control @error('auto_close_resolved_days') is-invalid @enderror"
                                   id="auto_close_resolved_days"
                                   name="auto_close_resolved_days"
                                   value="{{ old('auto_close_resolved_days', $autoCloseResolvedDays ?? 0) }}"
                                   min="0" max="365">
                            <span class="input-group-text">days</span>
                        </div>
                        @error('auto_close_resolved_days')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">Set to 0 to disable. Tickets in "Resolved" status for longer than this will be automatically closed daily at 6 AM.</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </form>
            </div>
        </div>

        {{-- Avatars Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header">
                <i class="bi bi-person-circle me-2"></i>Avatars
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Contact avatars are fetched from <a href="https://gravatar.com" target="_blank">Gravatar</a> using their email address.
                    Client logos are fetched from <a href="https://debounce.com/logo-api/" target="_blank">DeBounce</a> using their website domain.
                    No API keys required.
                </p>
                <form method="POST" action="{{ route('settings.integrations.avatars.update') }}">
                    @csrf
                    <div class="mb-3" style="max-width: 300px;">
                        <label for="gravatar_default" class="form-label">Gravatar fallback image</label>
                        <select class="form-select form-select-sm" id="gravatar_default" name="gravatar_default">
                            @foreach(\App\Support\AvatarHelper::GRAVATAR_DEFAULTS as $value => $label)
                                <option value="{{ $value }}" {{ ($gravatarDefault ?? '404') === $value ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">What to show when a contact has no Gravatar account. "Initials" uses the app's built-in colored initials.</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </form>
            </div>
        </div>

        </div>{{-- /communications tab --}}

        {{-- ============================================================ --}}
        {{-- AI & AUTOMATION TAB --}}
        {{-- ============================================================ --}}
        <div class="tab-pane fade" id="ai" role="tabpanel">

        {{-- AI Provider Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-robot me-2"></i>AI Provider
                </div>
                @if($aiConnectedAt)
                    <span class="badge bg-success">Connected</span>
                @elseif($aiHasKey)
                    <span class="badge bg-warning text-dark">Not tested</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Configure an AI provider for ticket triage, thread matching, and other intelligent features.
                    Get an API key from
                    <a href="https://console.anthropic.com/" target="_blank">Anthropic</a> or
                    <a href="https://platform.openai.com/" target="_blank">OpenAI</a>.
                </p>

                <form method="POST" action="{{ route('settings.integrations.ai.update') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="ai_provider" class="form-label">Provider</label>
                            <select class="form-select @error('provider') is-invalid @enderror"
                                    id="ai_provider"
                                    name="provider"
                                    onchange="updateAiModelPlaceholder()">
                                <option value="anthropic" {{ old('provider', $aiProvider) === 'anthropic' ? 'selected' : '' }}>Claude (Anthropic)</option>
                                <option value="openai" {{ old('provider', $aiProvider) === 'openai' ? 'selected' : '' }}>OpenAI</option>
                            </select>
                            @error('provider')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="ai_api_key" class="form-label">API Key</label>
                            <input type="password"
                                   class="form-control @error('api_key') is-invalid @enderror"
                                   id="ai_api_key"
                                   name="api_key"
                                   placeholder="{{ $aiHasKey ? 'Leave blank to keep current' : 'Enter API Key' }}">
                            @error('api_key')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="ai_model" class="form-label">
                            Model
                            <small class="text-muted">(leave blank for default)</small>
                        </label>
                        <input type="text"
                               class="form-control @error('model') is-invalid @enderror"
                               id="ai_model"
                               name="model"
                               value="{{ old('model', $aiModel) }}"
                               placeholder="{{ \App\Support\AiConfig::defaultModel($aiProvider) }}">
                        @error('model')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="ai_reply_guidelines" class="form-label">
                            Reply Guidelines
                            <small class="text-muted">(optional)</small>
                        </label>
                        <textarea class="form-control @error('reply_guidelines') is-invalid @enderror"
                                  id="ai_reply_guidelines"
                                  name="reply_guidelines"
                                  rows="4"
                                  maxlength="2000"
                                  placeholder="e.g., Always greet clients by first name. Keep responses concise. Sign off with 'Best regards' followed by 'The Acme Team'.">{{ old('reply_guidelines', $aiReplyGuidelines) }}</textarea>
                        <div class="form-text">Communication style guidelines for AI-drafted replies. The AI will follow these when generating client-facing emails.</div>
                        @error('reply_guidelines')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    @if($aiConnectedAt)
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="bi bi-check-circle text-success me-1"></i>Last connected: {{ $aiConnectedAt }}
                        </small>
                    </div>
                    @endif

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Settings
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="test-ai-btn" onclick="testConnection('ai')">
                            <i class="bi bi-plug me-1"></i>Test Connection
                        </button>
                    </div>
                </form>

                <div id="test-result-ai" class="mt-3" style="display: none;"></div>

                @if($aiHasKey)
                <div class="border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                        @csrf
                        <input type="hidden" name="integration" value="ai">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1"
                                   id="ai_enabled" {{ $aiEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                            <label class="form-check-label" for="ai_enabled">Integration enabled</label>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>
        {{-- Call Transcription Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-file-earmark-text me-2"></i>Call Transcription
                </div>
                @if($transcriptionConfigured)
                    <span class="badge bg-success">Configured</span>
                @else
                    <span class="badge bg-secondary">Not configured</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Transcribe call recordings using OpenAI Whisper (speech-to-text), then analyze with your configured AI provider above.
                    @if($aiProvider === 'openai' && $aiHasKey)
                        <br><i class="bi bi-info-circle me-1"></i>Your AI provider is OpenAI, so Whisper will use that key automatically. A dedicated key below is optional.
                    @else
                        Requires an OpenAI API key for Whisper (separate from your AI provider above).
                    @endif
                </p>

                <form method="POST" action="{{ route('settings.integrations.transcription.update') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="openai_api_key" class="form-label">OpenAI API Key (for Whisper)</label>
                        <input type="password"
                               class="form-control @error('openai_api_key') is-invalid @enderror"
                               id="openai_api_key"
                               name="openai_api_key"
                               placeholder="{{ $transcriptionHasKey ? 'Leave blank to keep current' : 'Enter OpenAI API Key' }}">
                        @if($aiProvider === 'openai' && $aiHasKey)
                            <div class="form-text">Optional — falls back to your AI provider key above.</div>
                        @else
                            <div class="form-text">Required for Whisper speech-to-text. Get one at <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>.</div>
                        @endif
                        @error('openai_api_key')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" name="auto_transcribe" value="1"
                               class="form-check-input" id="auto_transcribe"
                               {{ $transcriptionAutoEnabled ? 'checked' : '' }}>
                        <label class="form-check-label" for="auto_transcribe">
                            Auto-transcribe calls with recordings
                        </label>
                        <div class="form-text">
                            When enabled, calls with recordings are automatically transcribed after the recording webhook arrives.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="min_seconds" class="form-label">
                            Minimum Duration (seconds)
                            <small class="text-muted">(skip short calls)</small>
                        </label>
                        <input type="number"
                               class="form-control @error('min_seconds') is-invalid @enderror"
                               id="min_seconds"
                               name="min_seconds"
                               value="{{ old('min_seconds', $transcriptionMinSeconds) }}"
                               min="0" max="3600"
                               style="max-width: 120px">
                        <div class="form-text">Calls shorter than this are skipped when auto-transcribing. Default: 30.</div>
                        @error('min_seconds')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Settings
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="test-transcription-btn" onclick="testConnection('transcription')">
                            <i class="bi bi-plug me-1"></i>Test Whisper
                        </button>
                    </div>
                </form>

                <div id="test-result-transcription" class="mt-3" style="display: none;"></div>
            </div>
        </div>

        {{-- AI Ticket Triage Card --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-robot me-2"></i>AI Ticket Triage
                </div>
                @if($triageEnabled ?? false)
                    <span class="badge bg-success">Enabled</span>
                @else
                    <span class="badge bg-secondary">Disabled</span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Automatically classifies, enriches, and prepares new tickets for technician review using your configured AI provider.
                    Deterministic stages (junk filter, contact resolution, asset matching) work without AI.
                </p>

                <form method="POST" action="{{ route('settings.integrations.triage.update') }}">
                    @csrf

                    <div class="form-check mb-2">
                        <input type="checkbox" name="triage_enabled" value="1"
                               class="form-check-input" id="triage_enabled"
                               {{ ($triageEnabled ?? false) ? 'checked' : '' }}>
                        <label class="form-check-label" for="triage_enabled">
                            <strong>Enable AI Triage</strong>
                        </label>
                    </div>

                    <div class="form-check mb-2">
                        <input type="checkbox" name="triage_auto_new_tickets" value="1"
                               class="form-check-input" id="triage_auto_new"
                               {{ ($triageAutoNew ?? false) ? 'checked' : '' }}>
                        <label class="form-check-label" for="triage_auto_new">
                            Auto-triage new tickets
                        </label>
                        <div class="form-text">Run the triage pipeline automatically when a ticket is created.</div>
                    </div>

                    <div class="form-check mb-2">
                        <input type="checkbox" name="triage_auto_review" value="1"
                               class="form-check-input" id="triage_auto_review"
                               {{ ($triageAutoReview ?? false) ? 'checked' : '' }}>
                        <label class="form-check-label" for="triage_auto_review">
                            Auto-review open tickets
                        </label>
                        <div class="form-text">Periodically review open tickets for status updates and auto-assign unassigned tickets.</div>
                    </div>

                    <div class="mb-3 ms-4" style="max-width: 250px;">
                        <label for="triage_review_frequency" class="form-label">Review frequency</label>
                        <div class="input-group input-group-sm">
                            <input type="number"
                                   class="form-control"
                                   id="triage_review_frequency"
                                   name="triage_review_frequency_minutes"
                                   value="{{ old('triage_review_frequency_minutes', $triageReviewFrequency ?? 60) }}"
                                   min="5" max="1440" step="5">
                            <span class="input-group-text">minutes</span>
                        </div>
                        <div class="form-text">How often to run the auto-review. Default: 60 minutes.</div>
                    </div>

                    <div class="form-check mb-2 ms-4">
                        <input type="checkbox" name="triage_review_auto_close" value="1"
                               class="form-check-input" id="triage_review_auto_close"
                               {{ ($triageReviewAutoClose ?? false) ? 'checked' : '' }}>
                        <label class="form-check-label" for="triage_review_auto_close">
                            Auto-close resolved/junk tickets
                        </label>
                        <div class="form-text">When enabled, tickets assessed as resolved or junk above the confidence threshold are automatically closed.</div>
                    </div>

                    <div class="mb-3 ms-4" style="max-width: 250px;">
                        <label for="triage_review_threshold" class="form-label">
                            Auto-close confidence threshold
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="number"
                                   class="form-control"
                                   id="triage_review_threshold"
                                   name="triage_review_auto_close_threshold"
                                   value="{{ old('triage_review_auto_close_threshold', $triageReviewThreshold ?? 80) }}"
                                   min="50" max="100" step="5">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">Higher = more conservative (fewer auto-closes). 80% is recommended.</div>
                    </div>

                    <div class="mb-3">
                        <label for="triage_default_assignee" class="form-label">Default Assignee</label>
                        <select name="triage_default_assignee_id" class="form-select form-select-sm" id="triage_default_assignee" style="max-width: 250px;">
                            <option value="">None</option>
                            @foreach($users ?? [] as $u)
                                <option value="{{ $u->id }}" {{ ($triageDefaultAssignee ?? '') == $u->id ? 'selected' : '' }}>
                                    {{ $u->name }}{{ !$u->is_active ? ' (Inactive)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Fallback assignee when the client has no primary technician.</div>
                    </div>

                    <div class="mb-3">
                        <label for="triage_system_user" class="form-label">System User (AI Actor)</label>
                        <select name="triage_system_user_id" class="form-select form-select-sm" id="triage_system_user" style="max-width: 250px;">
                            <option value="">First user (default)</option>
                            @foreach($users ?? [] as $u)
                                <option value="{{ $u->id }}" {{ ($triageSystemUser ?? '') == $u->id ? 'selected' : '' }}>
                                    {{ $u->name }}{{ !$u->is_active ? ' (Inactive)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">The user identity that AI triage uses for notes and status changes.</div>
                    </div>

                    {{-- Pipeline Stages --}}
                    <hr>
                    <h6 class="fw-bold mb-3">Pipeline Stages</h6>
                    <p class="text-muted small mb-3">Disable individual stages. Deterministic stages (contact resolution, junk filter, asset assignment) work without AI.</p>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="triage_stage_contact_resolution" id="triage_stage_contact_resolution" value="1" @checked($triageStages['contact_resolution'] ?? true)>
                                <label class="form-check-label" for="triage_stage_contact_resolution">Contact Resolution</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="triage_stage_junk_filter" id="triage_stage_junk_filter" value="1" @checked($triageStages['junk_filter'] ?? true)>
                                <label class="form-check-label" for="triage_stage_junk_filter">Junk Filter</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="triage_stage_classification" id="triage_stage_classification" value="1" @checked($triageStages['classification'] ?? true)>
                                <label class="form-check-label" for="triage_stage_classification">Classification</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="triage_stage_asset_assignment" id="triage_stage_asset_assignment" value="1" @checked($triageStages['asset_assignment'] ?? true)>
                                <label class="form-check-label" for="triage_stage_asset_assignment">Asset Assignment</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="triage_stage_technical_triage" id="triage_stage_technical_triage" value="1" @checked($triageStages['technical_triage'] ?? true)>
                                <label class="form-check-label" for="triage_stage_technical_triage">Technical Triage</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="triage_stage_conversation_review" id="triage_stage_conversation_review" value="1" @checked($triageStages['conversation_review'] ?? true)>
                                <label class="form-check-label" for="triage_stage_conversation_review">Conversation Review</label>
                            </div>
                        </div>
                    </div>

                    {{-- Advanced Settings --}}
                    <hr>
                    <h6 class="fw-bold mb-3">Advanced</h6>

                    <div class="mb-3" style="max-width: 250px;">
                        <label for="triage_model" class="form-label">AI Model Override</label>
                        <input type="text" class="form-control form-control-sm" id="triage_model" name="triage_model"
                               value="{{ old('triage_model', $triageModel ?? '') }}"
                               placeholder="{{ \App\Support\AiConfig::model() }}">
                        <div class="form-text">Leave blank to use the default AI model.</div>
                    </div>

                    <div class="mb-3" style="max-width: 250px;">
                        <label for="triage_max_tokens" class="form-label">Max Tokens per Run</label>
                        <div class="input-group input-group-sm">
                            <input type="number" class="form-control" id="triage_max_tokens" name="triage_max_tokens_per_run"
                                   value="{{ old('triage_max_tokens_per_run', $triageMaxTokens ?? 200000) }}"
                                   min="10000" max="1000000" step="10000">
                            <span class="input-group-text">tokens</span>
                        </div>
                        <div class="form-text">Token budget (input + output) per triage run. Default: 200,000.</div>
                    </div>

                    <div class="mb-3" style="max-width: 250px;">
                        <label for="triage_daily_tokens" class="form-label">Daily Token Limit</label>
                        <div class="input-group input-group-sm">
                            <input type="number" class="form-control" id="triage_daily_tokens" name="triage_daily_token_limit"
                                   value="{{ old('triage_daily_token_limit', $triageDailyTokens ?? 2000000) }}"
                                   min="100000" max="50000000" step="100000">
                            <span class="input-group-text">tokens</span>
                        </div>
                        <div class="form-text">Daily ceiling across all triage runs. Default: 2,000,000.</div>
                    </div>

                    <div class="mb-3" style="max-width: 250px;">
                        <label for="triage_batch_size" class="form-label">Review Batch Size</label>
                        <div class="input-group input-group-sm">
                            <input type="number" class="form-control" id="triage_batch_size" name="triage_review_batch_size"
                                   value="{{ old('triage_review_batch_size', $triageBatchSize ?? 20) }}"
                                   min="1" max="100">
                            <span class="input-group-text">tickets</span>
                        </div>
                        <div class="form-text">Max tickets per auto-review cron run. Default: 20.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Triage Settings
                    </button>
                </form>
            </div>
        </div>

        {{-- AI Assistant Card --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center">
                <span>
                    <i class="bi bi-chat-dots me-2"></i>AI Assistant
                    @if($assistantEnabled)
                        <span class="badge bg-success ms-2">Active</span>
                    @else
                        <span class="badge bg-secondary ms-2">Disabled</span>
                    @endif
                </span>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    The AI Assistant provides contextual chat on ticket and client pages. Requires Anthropic AI provider.
                </p>

                <form method="POST" action="{{ route('settings.integrations.assistant.update') }}">
                    @csrf
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="assistant_enabled" name="assistant_enabled" {{ $assistantEnabled ? 'checked' : '' }}>
                        <label class="form-check-label" for="assistant_enabled"><strong>Enable AI Assistant</strong></label>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-auto">
                            <label for="assistant_max_messages" class="form-label">Messages per Conversation</label>
                            <input type="number" class="form-control form-control-sm" id="assistant_max_messages" name="assistant_max_messages"
                                   value="{{ old('assistant_max_messages', $assistantMaxMessages) }}"
                                   min="10" max="500" style="width: 120px;">
                            <div class="form-text">Max messages before requiring a new conversation. Default: 50.</div>
                        </div>
                        <div class="col-auto">
                            <label for="assistant_daily_token_limit" class="form-label">Daily Token Limit (per user)</label>
                            <div class="input-group input-group-sm" style="width: 200px;">
                                <input type="number" class="form-control" id="assistant_daily_token_limit" name="assistant_daily_token_limit"
                                       value="{{ old('assistant_daily_token_limit', $assistantDailyTokens) }}"
                                       min="50000" max="10000000" step="50000">
                                <span class="input-group-text">tokens</span>
                            </div>
                            <div class="form-text">Default: 500,000.</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Assistant Settings
                    </button>
                </form>
            </div>
        </div>

        {{-- AI Technician Card --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center">
                <span>
                    <i class="bi bi-robot me-2"></i>AI Technician
                    @if($technicianEnabled)
                        <span class="badge bg-success ms-2">Active</span>
                    @else
                        <span class="badge bg-secondary ms-2">Disabled</span>
                    @endif
                </span>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Supervised foundation (Phase 0). When enabled, the AI Technician acknowledges new tickets with a clearly-disclosed AI message, authored by your configured AI actor (set in AI Triage). All other actions require approval — coming in a later phase. Off by default.
                </p>
                <p class="text-muted small">
                    <i class="bi bi-info-circle me-1"></i><strong>Requires a distinct AI System User</strong> (set in AI Triage). A ticket created by that same user is not auto-acknowledged — inbound tickets (email, the portal, another technician) are.
                </p>

                <form method="POST" action="{{ route('settings.integrations.technician.update') }}">
                    @csrf
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="technician_enabled" name="technician_enabled" {{ $technicianEnabled ? 'checked' : '' }}>
                        <label class="form-check-label" for="technician_enabled"><strong>Enable AI Technician</strong></label>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="technician_auto_ack" name="technician_auto_ack" {{ $technicianAutoAck ? 'checked' : '' }}>
                        <label class="form-check-label" for="technician_auto_ack"><strong>Auto-acknowledge new tickets</strong></label>
                    </div>

                    <hr class="my-3">
                    <h6 class="text-muted text-uppercase small mb-2">Notify (Plan 1C)</h6>
                    <div class="mb-3">
                        <label class="form-label small" for="technician_teams_webhook_url">Teams webhook URL</label>
                        <input type="url" class="form-control" id="technician_teams_webhook_url" name="technician_teams_webhook_url"
                               value="{{ $technicianTeamsWebhook }}" placeholder="https://…webhook.office.com/… (or a Power Automate Workflow URL)">
                        <div class="form-text">Paste an incoming-webhook / Power Automate Workflow URL for the operator chat. Optional — email is the fallback.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small" for="technician_notify_email">Notify email (fallback)</label>
                        <input type="email" class="form-control" id="technician_notify_email" name="technician_notify_email" value="{{ $technicianNotifyEmail }}">
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="technician_digest_enabled" name="technician_digest_enabled" {{ $technicianDigestEnabled ? 'checked' : '' }}>
                        <label class="form-check-label" for="technician_digest_enabled">Send a daily digest</label>
                    </div>
                    <div class="row">
                        <div class="col mb-3">
                            <label class="form-label small" for="technician_digest_time">Digest time (local)</label>
                            <input type="time" class="form-control" id="technician_digest_time" name="technician_digest_time" value="{{ $technicianDigestTime }}">
                        </div>
                        <div class="col mb-3">
                            <label class="form-label small" for="technician_heartbeat_interval">Worker-down alert after (min)</label>
                            <input type="number" min="1" class="form-control" id="technician_heartbeat_interval" name="technician_heartbeat_interval" value="{{ $technicianHeartbeatInterval }}">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Technician Settings
                    </button>
                </form>
            </div>
        </div>

        </div>{{-- /ai tab --}}

        {{-- Client Portal tab --}}
        <div class="tab-pane fade" id="portal" role="tabpanel">

        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center">
                <span>
                    <i class="bi bi-person-lines-fill me-2"></i>Client Portal
                </span>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    The client portal lets your clients view tickets, invoices, devices, and service agreements.
                    Manage portal users from each client's detail page.
                </p>

                <form method="POST" action="{{ route('settings.integrations.portal.update') }}">
                    @csrf

                    <div class="mb-3 form-check form-switch">
                        <input type="hidden" name="portal_enabled" value="0">
                        <input type="checkbox" class="form-check-input" name="portal_enabled" id="portal_enabled" value="1"
                               {{ App\Models\Setting::getValue('portal_enabled', '0') === '1' ? 'checked' : '' }}>
                        <label class="form-check-label" for="portal_enabled"><strong>Enable Client Portal</strong></label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="portal_company_name" class="form-control" placeholder="Your Company"
                               value="{{ App\Models\Setting::getValue('portal_company_name', '') }}">
                        <div class="form-text">Displayed in the portal header and emails. Defaults to app name if blank.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Logo URL</label>
                        <input type="url" name="portal_logo_url" class="form-control" placeholder="https://..."
                               value="{{ App\Models\Setting::getValue('portal_logo_url', '') }}">
                        <div class="form-text">Full URL to your company logo. Displayed in the portal navbar.</div>
                    </div>

                    <hr>
                    <h6>External Billing Links <span class="text-muted small fw-normal">(optional)</span></h6>
                    <p class="text-muted small">
                        If using Stripe, payment links appear on invoices automatically. These fields are for MSPs
                        using external billing portals like BenjiPays or custom order forms.
                    </p>

                    <div class="mb-3">
                        <label class="form-label">Billing Portal URL</label>
                        <input type="url" name="portal_billing_url" class="form-control" placeholder="https://billing.example.com/..."
                               value="{{ App\Models\Setting::getValue('portal_billing_url', '') }}">
                        <div class="form-text">External billing portal link. Shown as a nav item in the portal.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Billing Portal Label</label>
                        <input type="text" name="portal_billing_label" class="form-control" placeholder="Billing Portal"
                               value="{{ App\Models\Setting::getValue('portal_billing_label', '') }}">
                        <div class="form-text">Label for the billing link. Defaults to "Billing Portal".</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Prepaid Time Order URL</label>
                        <input type="text" name="portal_order_url" class="form-control" placeholder="https://example.com/order?cid={client_id}"
                               value="{{ App\Models\Setting::getValue('portal_order_url', '') }}">
                        <div class="form-text">Use <code>{client_id}</code> as a placeholder — it will be replaced with the client's ID at runtime.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Portal Settings
                    </button>
                </form>
            </div>
        </div>

        </div>{{-- /portal tab --}}

        </div>{{-- /tab-content --}}
    </div>
</div>
@endsection

@push('scripts')
<script>
// Initialize Bootstrap tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

// Tab persistence via URL hash + sessionStorage (survives redirects after form saves)
(function() {
    const storageKey = 'integrations-active-tab';
    const hash = window.location.hash.replace('#', '');
    const saved = sessionStorage.getItem(storageKey);
    const target = hash || saved;
    if (target) {
        const tab = document.querySelector('#integrationTabs button[data-bs-target="#' + target + '"]');
        if (tab) new bootstrap.Tab(tab).show();
    }
    document.querySelectorAll('#integrationTabs button[data-bs-toggle="pill"]').forEach(btn => {
        btn.addEventListener('shown.bs.tab', function(e) {
            const id = e.target.dataset.bsTarget.replace('#', '');
            history.replaceState(null, null, '#' + id);
            sessionStorage.setItem(storageKey, id);
        });
    });
})();

function generateLevelWebhookSecret() {
    const array = new Uint8Array(32);
    crypto.getRandomValues(array);
    const hex = Array.from(array, b => b.toString(16).padStart(2, '0')).join('');
    const input = document.getElementById('level_webhook_secret');
    input.value = hex;
    input.removeAttribute('readonly');
}

function copyToClipboard(inputId) {
    const input = document.getElementById(inputId);
    navigator.clipboard.writeText(input.value).then(() => {
        const btn = input.nextElementSibling?.nextElementSibling || input.parentElement.querySelector('[onclick*="copyToClipboard"]');
        if (btn) {
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check me-1"></i>Copied';
            setTimeout(() => { btn.innerHTML = original; }, 2000);
        }
    });
}

const aiModelDefaults = { anthropic: 'claude-sonnet-4-6', openai: 'gpt-4o' };
function updateAiModelPlaceholder() {
    const provider = document.getElementById('ai_provider').value;
    document.getElementById('ai_model').placeholder = aiModelDefaults[provider] || '';
}

function previewCippSync(type) {
    const config = {
        contacts: {
            btn: document.getElementById('cipp-contact-preview-btn'),
            url: '{{ route("settings.integrations.cipp.sync-contacts") }}',
            confirmUrl: '{{ route("settings.integrations.cipp.sync-contacts") }}',
            icon: '<i class="bi bi-people me-1"></i>Sync Contacts...',
            label: 'contacts',
        },
        devices: {
            btn: document.getElementById('cipp-device-preview-btn'),
            url: '{{ route("settings.integrations.cipp.sync-devices") }}',
            confirmUrl: '{{ route("settings.integrations.cipp.sync-devices") }}',
            icon: '<i class="bi bi-laptop me-1"></i>Sync Devices...',
            label: 'devices',
        },
    };

    const c = config[type];
    if (!c) return;

    const preview = document.getElementById('cipp-sync-preview');
    const loading = document.getElementById('cipp-sync-preview-loading');
    const result = document.getElementById('cipp-sync-preview-result');
    const summary = document.getElementById('cipp-sync-preview-summary');
    const error = document.getElementById('cipp-sync-preview-error');
    const confirmForm = document.getElementById('cipp-sync-confirm-form');

    c.btn.disabled = true;
    c.btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Previewing...';
    preview.style.display = 'block';
    loading.style.display = 'block';
    result.style.display = 'none';
    error.style.display = 'none';

    // Set the confirm form action for this sync type
    confirmForm.action = c.confirmUrl;

    fetch(c.url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ dry_run: true }),
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        loading.style.display = 'none';
        if (data.error) {
            error.textContent = data.error;
            error.style.display = 'block';
        } else {
            const parts = [];
            if (data.created) parts.push(data.created + ' new');
            if (data.updated) parts.push(data.updated + ' updated');
            if (data.deactivated) parts.push(data.deactivated + ' deactivated');
            if (data.errors) parts.push(data.errors + ' error(s)');
            summary.textContent = parts.length ? parts.join(', ') : 'No changes needed.';

            // Render details table
            const details = document.getElementById('cipp-sync-preview-details');
            if (data.details && data.details.length > 0) {
                const actionBadge = (a) => {
                    if (a === 'create') return '<span class="badge bg-success">New</span>';
                    if (a === 'deactivate') return '<span class="badge bg-danger">Deactivate</span>';
                    return '<span class="badge bg-secondary">Update</span>';
                };
                let html = '<table class="table table-sm table-hover mb-0" style="font-size: 0.85rem;">';
                html += '<thead><tr><th style="width:90px">Action</th><th>Client</th><th>Name</th><th>Email/Hostname</th></tr></thead><tbody>';
                const order = {create: 0, deactivate: 1, update: 2};
                data.details.sort((a, b) => (order[a.action] ?? 9) - (order[b.action] ?? 9));
                data.details.forEach(d => {
                    html += `<tr><td>${actionBadge(d.action)}</td><td>${d.client || ''}</td><td>${d.name || ''}</td><td class="text-muted">${d.email || ''}</td></tr>`;
                });
                html += '</tbody></table>';
                details.innerHTML = html;
                details.style.display = 'block';
            } else {
                details.innerHTML = '';
                details.style.display = 'none';
            }

            // Render errors
            const errorsDiv = document.getElementById('cipp-sync-preview-errors');
            if (data.errorMessages && data.errorMessages.length > 0) {
                errorsDiv.innerHTML = '<strong>Errors:</strong><ul class="mb-0">' + data.errorMessages.map(m => '<li>' + m + '</li>').join('') + '</ul>';
                errorsDiv.style.display = 'block';
            } else {
                errorsDiv.style.display = 'none';
            }

            result.style.display = 'block';
        }
        c.btn.disabled = false;
        c.btn.innerHTML = c.icon;
    })
    .catch(err => {
        loading.style.display = 'none';
        error.textContent = 'Preview failed: ' + err.message;
        error.style.display = 'block';
        c.btn.disabled = false;
        c.btn.innerHTML = c.icon;
    });
}

function testConnection(service) {
    const btn = document.getElementById('test-' + service + '-btn');
    const result = document.getElementById('test-result-' + service);

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing...';
    result.style.display = 'none';

    const routes = {
        stripe: '{{ route("settings.integrations.stripe.test") }}',
        ninja: '{{ route("settings.integrations.ninja.test") }}',
        level: '{{ route("settings.integrations.level.test") }}',
        mesh: '{{ route("settings.integrations.mesh.test") }}',
        cipp: '{{ route("settings.integrations.cipp.test") }}',
        huntress: '{{ route("settings.integrations.huntress.test") }}',
        servosity: '{{ route("settings.integrations.servosity.test") }}',
        controld: '{{ route("settings.integrations.controld.test") }}',
        zorus: '{{ route("settings.integrations.zorus.test") }}',
        appriver: '{{ route("settings.integrations.appriver.test") }}',
        printix: '{{ route("settings.integrations.printix.test") }}',
        tactical: '{{ route("settings.integrations.tactical.test") }}',
        comet: '{{ route("settings.integrations.comet.test") }}',
        plivo: '{{ route("settings.integrations.plivo.test") }}',
        graph: '{{ route("settings.integrations.graph.test") }}',
        ai: '{{ route("settings.integrations.ai.test") }}',
        transcription: '{{ route("settings.integrations.transcription.test") }}',
    };

    fetch(routes[service], {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json',
        },
    })
    .then(r => r.json())
    .then(data => {
        result.style.display = 'block';
        if (data.success) {
            result.innerHTML = '<div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i>' + data.message + '</div>';
        } else {
            result.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-x-circle me-1"></i>' + data.message + '</div>';
        }
    })
    .catch(() => {
        result.style.display = 'block';
        result.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-x-circle me-1"></i>Request failed. Check network connection.</div>';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug me-1"></i>Test Connection';
    });
}

function tacticalProvisionAlerts(btn) {
    const resultDiv = document.getElementById('tactical-provision-result');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Provisioning…';
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Provisioning Tactical alert&rarr;ticket pipeline…</span>';

    fetch('{{ route("settings.integrations.tactical.provision-alerts") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        if (data.success) {
            let html = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + data.message + '</span>';
            if (data.warning) {
                html += '<br><span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>' + data.warning + '</span>';
            }
            resultDiv.innerHTML = html;
        } else {
            resultDiv.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + data.message + '</span>';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        resultDiv.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Request failed: ' + err + '</span>';
    });
}

function cometAutoConfigure() {
    const token = document.getElementById('comet_account_token').value;
    if (!token || token === '••••••••') {
        alert('Please enter your Comet Account API token');
        return;
    }

    const resultDiv = document.getElementById('comet-auto-configure-result');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Connecting to Comet portal...</span>';

    fetch('{{ route("settings.integrations.comet.auto-configure") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ comet_account_token: token }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            resultDiv.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + data.message + '</span>';
            setTimeout(() => location.reload(), 1500);
        } else {
            resultDiv.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + data.message + '</span>';
        }
    })
    .catch(err => {
        resultDiv.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Request failed: ' + err + '</span>';
    });
}

</script>
@endpush
