{{--
    The order confirmations' "pay link not ready yet" state (prepaid and shop).
    Copy pending approval; a change is a string swap here.

    The "or call us at ..." clause appears only when PortalConfig::supportPhone()
    is set. The confirmation poll swaps this block for Pay Now when the link
    arrives and otherwise leaves it in place.

    Expects: nothing (reads PortalConfig)
--}}
@php($supportPhone = \App\Support\PortalConfig::supportPhone())
<div class="card mb-4" id="paymentPending" role="status">
    <div class="card-body">
        <h6 class="mb-1">Your pay link is on its way</h6>
        <p class="mb-1">It usually appears right here within a minute. It will also be on this invoice under <a href="{{ route('portal.invoices.index') }}">Invoices</a>, so you can pay later.</p>
        @if($supportPhone)
            <p class="text-muted mb-0">Not there after a few minutes? Reply to any email from us or call us at {{ $supportPhone }}, and we'll send it to you.</p>
        @else
            <p class="text-muted mb-0">Not there after a few minutes? Reply to any email from us and we'll send it to you.</p>
        @endif
    </div>
</div>
