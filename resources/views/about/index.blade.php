@extends('layouts.app')

@section('title', 'About')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <h2 class="section-title">About</h2>

        {{-- Current Version --}}
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>Current Version
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted" style="width:140px;">Commit</th>
                            <td>
                                <code>{{ $current['commit_short'] }}</code>
                                @if($current['commit_hash'] !== 'unknown')
                                    <a href="https://github.com/YOUR_ORG/your-psa-repo/commit/{{ $current['commit_hash'] }}"
                                       target="_blank" class="ms-2 text-muted small" title="View on GitHub">
                                        <i class="bi bi-github"></i>
                                    </a>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Branch</th>
                            <td>{{ $current['branch'] }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Commit Date</th>
                            <td>
                                @if($current['commit_date'])
                                    {{ \Carbon\Carbon::parse($current['commit_date'])->toAppTz()->format('M j, Y g:i A T') }}
                                @else
                                    <span class="text-muted">Unknown</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Deployed</th>
                            <td>
                                @if($current['deploy_timestamp'])
                                    {{ \Carbon\Carbon::parse($current['deploy_timestamp'])->toAppTz()->format('M j, Y g:i A T') }}
                                @else
                                    <span class="text-muted">Unknown</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">PHP</th>
                            <td>{{ PHP_VERSION }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Laravel</th>
                            <td>{{ app()->version() }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Available Updates --}}
        <div class="card card-static shadow-sm mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cloud-download me-2"></i>Available Updates</span>
                <button type="button" class="btn btn-sm btn-outline-light" id="btn-check-updates">
                    <i class="bi bi-arrow-clockwise me-1"></i>Check for Updates
                </button>
            </div>
            <div class="card-body" id="updates-body">
                @if($updates['checked_at'])
                    @if($updates['error'])
                        <div class="alert alert-danger mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>{{ $updates['error'] }}
                        </div>
                    @elseif($updates['commits_behind'] === 0)
                        <div class="text-success">
                            <i class="bi bi-check-circle me-1"></i>You are up to date.
                        </div>
                    @else
                        <div class="alert alert-warning mb-3">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <strong>{{ $updates['commits_behind'] }}</strong> update(s) available.
                        </div>
                        @if(!empty($updates['available_commits']))
                            <ul class="list-unstyled mb-0">
                                @foreach($updates['available_commits'] as $commit)
                                    <li class="py-1 {{ !$loop->last ? 'border-bottom' : '' }}">
                                        <code class="me-2">{{ $commit['hash'] }}</code>
                                        {{ $commit['subject'] }}
                                        <span class="text-muted small ms-2">{{ $commit['date'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            @if($updates['commits_behind'] > count($updates['available_commits']))
                                <div class="text-muted small mt-2">
                                    Showing {{ count($updates['available_commits']) }} of {{ $updates['commits_behind'] }} commits.
                                </div>
                            @endif
                        @endif
                    @endif
                    <div class="text-muted small mt-3">
                        Last checked: {{ \Carbon\Carbon::parse($updates['checked_at'])->toAppTz()->format('M j, Y g:i A T') }}
                    </div>
                @else
                    <p class="text-muted mb-0">
                        Click "Check for Updates" to see if a newer version is available.
                    </p>
                @endif
            </div>
        </div>

        {{-- Recent History --}}
        <div class="card card-static shadow-sm mt-4">
            <div class="card-header">
                <i class="bi bi-clock-history me-2"></i>Recent History
            </div>
            <div class="card-body" id="history-body">
                @if(!empty($updates['recent_history']))
                    <ul class="list-unstyled mb-0">
                        @foreach($updates['recent_history'] as $commit)
                            <li class="py-1 {{ !$loop->last ? 'border-bottom' : '' }}">
                                <code class="me-2">{{ $commit['hash'] }}</code>
                                {{ $commit['subject'] }}
                                <span class="text-muted small ms-2">{{ $commit['date'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-muted mb-0">
                        Commit history will appear after the first update check.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('btn-check-updates')?.addEventListener('click', async function () {
    const btn = this;
    const updatesBody = document.getElementById('updates-body');
    const historyBody = document.getElementById('history-body');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Checking...';

    try {
        const res = await fetch('{{ route("about.check-updates") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
        });
        const data = await res.json();

        if (data.error) {
            updatesBody.innerHTML = '<div class="alert alert-danger mb-0">'
                + '<i class="bi bi-exclamation-triangle me-1"></i>' + escapeHtml(data.error)
                + '</div>'
                + '<div class="text-muted small mt-3">Last checked: ' + new Date().toLocaleString() + '</div>';
        } else if (data.commits_behind === 0) {
            updatesBody.innerHTML = '<div class="text-success">'
                + '<i class="bi bi-check-circle me-1"></i>You are up to date.</div>'
                + '<div class="text-muted small mt-3">Last checked: ' + new Date().toLocaleString() + '</div>';
        } else {
            let html = '<div class="alert alert-warning mb-3">'
                + '<i class="bi bi-exclamation-triangle me-1"></i>'
                + '<strong>' + data.commits_behind + '</strong> update(s) available.</div>';
            if (data.available_commits && data.available_commits.length > 0) {
                html += '<ul class="list-unstyled mb-0">';
                data.available_commits.forEach(function(c, i) {
                    const border = i < data.available_commits.length - 1 ? ' border-bottom' : '';
                    html += '<li class="py-1' + border + '">'
                        + '<code class="me-2">' + escapeHtml(c.hash) + '</code>'
                        + escapeHtml(c.subject)
                        + '<span class="text-muted small ms-2">' + escapeHtml(c.date) + '</span>'
                        + '</li>';
                });
                html += '</ul>';
                if (data.commits_behind > data.available_commits.length) {
                    html += '<div class="text-muted small mt-2">Showing '
                        + data.available_commits.length + ' of ' + data.commits_behind + ' commits.</div>';
                }
            }
            html += '<div class="text-muted small mt-3">Last checked: ' + new Date().toLocaleString() + '</div>';
            updatesBody.innerHTML = html;
        }

        // Update recent history
        if (data.recent_history && data.recent_history.length > 0) {
            let histHtml = '<ul class="list-unstyled mb-0">';
            data.recent_history.forEach(function(c, i) {
                const border = i < data.recent_history.length - 1 ? ' border-bottom' : '';
                histHtml += '<li class="py-1' + border + '">'
                    + '<code class="me-2">' + escapeHtml(c.hash) + '</code>'
                    + escapeHtml(c.subject)
                    + '<span class="text-muted small ms-2">' + escapeHtml(c.date) + '</span>'
                    + '</li>';
            });
            histHtml += '</ul>';
            historyBody.innerHTML = histHtml;
        }

        // Update navbar badge
        const badge = document.getElementById('update-badge');
        if (badge) {
            if (data.commits_behind > 0) {
                badge.textContent = data.commits_behind;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }
    } catch (err) {
        updatesBody.innerHTML = '<div class="alert alert-danger mb-0">'
            + '<i class="bi bi-exclamation-triangle me-1"></i>Failed to check for updates: '
            + escapeHtml(err.message) + '</div>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Check for Updates';
    }
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}
</script>
@endpush
