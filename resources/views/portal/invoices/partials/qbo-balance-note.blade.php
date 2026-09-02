{{--
    #1173 / T-22802 — what QuickBooks says is still owed, when that is LESS
    than the invoice total.

    The PSA has no balance column on invoices, so this reads the last
    QBO-sourced status-change audit row (Invoice::qboPartialBalanceLog()),
    which is the only place the number exists. It is therefore AS OF that row's
    timestamp and can be up to one pull cycle old — the date is shown for that
    reason and must not be dropped: an amount presented as current when it is
    not is the same kind of quiet lie this issue exists to fix.

    Renders nothing at all when there is no partial to report, so it is safe to
    include unconditionally.

    Expects: $invoice
--}}
@php($qboBalanceLog = $invoice->qboPartialBalanceLog())
@if($qboBalanceLog)
    <div class="small text-muted mt-1">
        <i class="bi bi-info-circle me-1"></i>Partially paid — ${{ number_format($qboBalanceLog->qbo_balance, 2) }}
        still owed per QuickBooks as of {{ $qboBalanceLog->created_at->format('M j, Y') }}.
        @if($invoice->stripe_invoice_url && $invoice->status->isClientPayable())
            {{-- Pay Online is a hosted payment page created for the FULL
                 invoice amount; nothing here can re-price it. Saying so beside
                 the button is the honest v1 — quietly letting a client pay the
                 total again would be a second complaint of the same kind. --}}
            <br>Paying online will charge the full ${{ number_format($invoice->total, 2) }} —
            contact us to settle just the remaining balance.
        @endif
    </div>
@endif
