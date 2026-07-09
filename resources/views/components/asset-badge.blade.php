{{-- Expects: asset model instance --}}
@props(['asset' => null, 'link' => true, 'popover' => true, 'fallback' => '—'])

@if($asset)
    @php
        $statusLabel = $asset->statusBadge;
        $dotColor = match($statusLabel) {
            'Online' => '#198754',
            'Offline' => '#dc3545',
            'Stale' => '#ffc107',
            default => '#6c757d',
        };
        $displayName = $asset->hostname ?: $asset->name;
    @endphp
    <div class="d-inline-flex align-items-center gap-1 asset-badge-wrapper"
         @if($popover) data-asset-id="{{ $asset->id }}" @endif
    >
        <span class="d-inline-block rounded-circle flex-shrink-0 asset-status-dot"
              style="width:8px;height:8px;background:{{ $dotColor }};"></span>
        @if($link)
            <a href="{{ route('assets.show', $asset) }}" class="text-decoration-none text-truncate"
               style="max-width: 200px" {{ $attributes }}>{{ $displayName }}</a>
        @else
            <span class="text-truncate" style="max-width: 200px" {{ $attributes }}>{{ $displayName }}</span>
        @endif
    </div>
@else
    <span class="text-muted">{{ $fallback }}</span>
@endif

@once
@push('scripts')
<script>
(function() {
    const cache = {};
    let activePopover = null;
    let activeWrapper = null;
    let hideTimeout = null;

    function cancelHide() {
        if (hideTimeout) { clearTimeout(hideTimeout); hideTimeout = null; }
    }

    function scheduleHide() {
        cancelHide();
        hideTimeout = setTimeout(function() { dismissActive(); }, 150);
    }

    function onEnter(e) {
        const wrapper = e.target.closest('.asset-badge-wrapper[data-asset-id]');
        if (!wrapper) {
            // Check if entering the popover body itself
            if (e.target.closest('.popover')) { cancelHide(); return; }
            return;
        }

        cancelHide();
        const assetId = wrapper.dataset.assetId;
        const dot = wrapper.querySelector('.asset-status-dot');

        // Already showing for this wrapper
        if (activeWrapper === wrapper && activePopover) return;

        dismissActive();

        if (cache[assetId]) {
            showPopover(wrapper, dot, cache[assetId]);
            return;
        }

        // Show loading spinner
        activePopover = new bootstrap.Popover(wrapper, {
            html: true,
            content: '<div class="text-center py-2"><div class="spinner-border spinner-border-sm" role="status"></div></div>',
            trigger: 'manual',
            placement: 'auto',
        });
        activePopover.show();
        activeWrapper = wrapper;

        fetch('/assets/' + assetId + '/quick-look', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.ok ? r.json() : Promise.reject(r); })
        .then(function(data) {
            cache[assetId] = data;
            dismissActive();

            // Update dot color regardless of hover state
            if (dot && data.status_color) {
                dot.style.background = data.status_color;
            }

            // Only show popover if still hovering
            if (wrapper.matches(':hover')) {
                showPopover(wrapper, dot, data);
            }
        })
        .catch(function() {
            dismissActive();
            if (wrapper.matches(':hover')) {
                showPopover(wrapper, dot, { _error: true, hostname: wrapper.textContent.trim() });
            }
        });
    }

    function onLeave(e) {
        const wrapper = e.target.closest('.asset-badge-wrapper[data-asset-id]');
        const popover = e.target.closest('.popover');
        if (wrapper || popover) {
            scheduleHide();
        }
    }

    function dismissActive() {
        cancelHide();
        if (activePopover) {
            activePopover.dispose();
            activePopover = null;
            activeWrapper = null;
        }
    }

    function showPopover(wrapper, dot, data) {
        if (data._error) {
            var content = '<strong>' + esc(data.hostname) + '</strong><br><span class="text-muted">Status unavailable</span>';
            activePopover = new bootstrap.Popover(wrapper, { html: true, content: content, trigger: 'manual', placement: 'auto' });
            activePopover.show();
            activeWrapper = wrapper;
            return;
        }

        if (dot && data.status_color) {
            dot.style.background = data.status_color;
        }

        var html = '<strong>' + esc(data.hostname || data.name) + '</strong>';
        if (data.asset_type) html += ' <small class="text-muted">(' + esc(data.asset_type) + ')</small>';

        // Status + uptime
        html += '<br><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + data.status_color + ';"></span> ';
        html += esc(data.status);
        if (data.uptime) html += ' &middot; Up ' + esc(data.uptime);
        if (data.needs_reboot) html += ' <i class="bi bi-arrow-repeat text-warning" title="Reboot needed"></i>';

        if (data.contract) html += '<br><small class="text-muted"><i class="bi bi-file-earmark-text me-1"></i>' + esc(data.contract) + '</small>';
        if (data.os) html += '<br><small class="text-muted"><i class="bi bi-pc-display me-1"></i>' + esc(data.os) + '</small>';
        if (data.serial) html += '<br><small class="text-muted">S/N: ' + esc(data.serial) + '</small>';
        if (data.warranty_end) {
            var expired = new Date(data.warranty_end) < new Date();
            html += '<br><small class="' + (expired ? 'text-danger' : 'text-muted') + '"><i class="bi bi-shield-check me-1"></i>Warranty: ' + (expired ? 'Expired ' : 'Until ') + esc(data.warranty_end) + '</small>';
        }
        if (data.last_seen) html += '<br><small class="text-muted">Last seen: ' + esc(data.last_seen) + '</small>';

        if (data.rmm_url) {
            var label = data.rmm === 'ninja' ? 'View in Ninja' : 'View in Level';
            html += '<br><a href="' + esc(data.rmm_url) + '" target="_blank" class="small" onclick="event.stopPropagation();">' + label + ' <i class="bi bi-box-arrow-up-right"></i></a>';
        }

        activePopover = new bootstrap.Popover(wrapper, {
            html: true,
            content: html,
            trigger: 'manual',
            placement: 'auto',
            sanitize: false,
        });
        activePopover.show();
        activeWrapper = wrapper;
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    document.addEventListener('mouseenter', onEnter, true);
    document.addEventListener('mouseleave', onLeave, true);
})();
</script>
@endpush
@endonce
