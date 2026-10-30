{{-- AutoElevate attention row, inside the Sources AutoElevate panel (design-r3 §2.2, §4: the
     Security-tab card moved here with its five states). Written by AutoElevateAssetSyncService
     and Sync now; no vendor call is made from this page. The linked state is the health strip;
     the AUDIT callout and the four unlinked states (C-56: an unlinked asset says WHY) keep the
     card's existing wording. Expects $asset and $item (an AssetSources::attention row). --}}
@php
    $ae = \App\Services\AutoElevate\AutoElevateAssetSyncService::assetLinkState($asset);
@endphp
@if($item['key'] === 'mode' && $item['title'] === 'Audit mode')
    <div class="alert alert-warning py-1 px-2 small mb-0" role="status" data-audit-callout>
        <i class="bi bi-eye me-1"></i><strong>Audit mode:</strong> elevation requests are only logged on this machine, not enforced.
    </div>
@elseif($item['key'] === 'link')
    <div id="autoelevate-asset-state" data-state="{{ $ae['state'] }}" @if($ae['reason']) data-reason="{{ $ae['reason'] }}" @endif>
        <strong>{{ $item['title'] }}</strong>
        @if($ae['state'] === 'not_mapped')
            <p class="text-muted small mb-0" role="status">
                <i class="bi bi-link-45deg me-1"></i><strong>Client not mapped.</strong>
                This asset's client is not linked to an AutoElevate company, so no AutoElevate data was read for it.
                @if(auth()->user()?->isAdmin())
                    <a href="{{ route('settings.autoelevate-companies.index') }}" class="ms-1">Map companies</a>
                @endif
            </p>
        @elseif($ae['state'] === 'read_failed')
            <div class="alert alert-danger small mb-0 mt-1" role="alert">
                <i class="bi bi-exclamation-triangle me-1"></i><strong>AutoElevate read failed</strong> ({{ $ae['reason'] }}) on the last sync for this client.
                This is not evidence that the machine is absent from AutoElevate.
            </div>
        @elseif($ae['state'] === 'no_match')
            <p class="text-muted small mb-0" role="status">
                <i class="bi bi-info-circle me-1"></i><strong>No AutoElevate match.</strong>
                The last sync read this client's AutoElevate computers and none matched this asset's hostname
                @if($asset->hostname)(<span class="font-monospace">{{ $asset->hostname }}</span>)@endif
                uniquely.
            </p>
        @else
            <p class="text-muted small mb-0" role="status">
                <i class="bi bi-hourglass me-1"></i><strong>Not synced yet.</strong>
                This asset's client is mapped to AutoElevate, but no asset sync has been recorded for this asset under its current hostname and company mapping.
            </p>
        @endif
    </div>
@else
    <strong>{{ $item['title'] }}</strong>
    <span class="text-muted small ms-2">{{ $item['evidence'] }}</span>
@endif
