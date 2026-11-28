{{--
    The portal invoice pages' Pay Online button. Which source it uses is
    App\Support\PortalPayLink, the one rule the order confirmations read too:
    BenjiPays, then the Stripe page, then the QuickBooks billing-portal page.

    BenjiPays stays a POST (#2065): the mint is a vendor write, and
    PortalInvoiceController::payOnline() re-checks it and guards both of its
    exits to Stripe for a partially paid invoice (#1173). The other two are
    plain links. Renders nothing when the invoice offers no pay link.

    Expects: $invoice, $icon (bool)
--}}
@php($payLink = \App\Support\PortalPayLink::for($invoice))
@if($payLink?->isPost())
    {{-- #2065: POST mints a BenjiPays applied link priced at the balance. --}}
    <form method="POST" action="{{ $payLink->url }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-sm btn-accent">
            @if($icon)<i class="bi bi-credit-card me-1"></i>@endif
            Pay Online
        </button>
    </form>
@elseif($payLink)
    <a href="{{ $payLink->url }}" target="_blank" rel="noopener" class="btn btn-sm btn-accent">
        @if($icon)<i class="bi bi-credit-card me-1"></i>@endif
        Pay Online
    </a>
@endif
