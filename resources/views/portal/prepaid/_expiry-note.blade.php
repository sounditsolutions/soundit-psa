{{-- Upcoming prepaid-time expiry for a contract's current balance. Renders
     nothing unless the balance is scheduled to lapse. Expects: $contract. --}}
@if($contract->next_prepay_expiry)
    <div class="text-muted small mt-1">
        <i class="bi bi-hourglass-split me-1"></i>Next expiry: {{ number_format($contract->next_prepay_expiry['hours'], 1) }}h on {{ $contract->next_prepay_expiry['expiry_date']->toAppTz()->format('M j, Y') }}
    </div>
@endif
