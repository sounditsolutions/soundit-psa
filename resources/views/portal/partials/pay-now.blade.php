{{--
    "Ready to pay?" on the order confirmations. The source is
    App\Support\PortalPayLink, the same rule the portal invoice pages read.
    The BenjiPays source stays a POST (#2065); the others are plain links.

    Expects: $invoice, $payLink (PortalPayLink, not null)
--}}
<div class="card mb-4">
    <div class="card-body text-center">
        <p class="mb-2">Ready to pay?</p>
        @if($payLink->isPost())
            <form method="POST" action="{{ $payLink->url }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-accent btn-lg">
                    <i class="bi bi-credit-card me-1"></i>Pay Now — ${{ number_format($invoice->total, 2) }}
                </button>
            </form>
        @else
            <a href="{{ $payLink->url }}" target="_blank" rel="noopener" class="btn btn-accent btn-lg">
                <i class="bi bi-credit-card me-1"></i>Pay Now — ${{ number_format($invoice->total, 2) }}
            </a>
        @endif
    </div>
</div>
