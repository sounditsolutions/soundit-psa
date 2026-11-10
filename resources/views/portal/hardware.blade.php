@extends('portal.layouts.app')
@section('title', __('carbon_hardware.title'))
@section('content')
<h1 class="h3 mb-3">{{ __('carbon_hardware.title') }}</h1>
@if($errors->any())
<div class="alert alert-danger">{{ $errors->first() }}</div>
@endif
@if($reason)
<div class="alert alert-warning">{{ __('carbon_hardware.'.$reason) }}
    <a href="{{ route('portal.tickets.create') }}" class="btn btn-outline-primary btn-sm">{{ __('carbon_hardware.open_ticket') }}</a>
</div>
@endif
<form method="POST" action="{{ route('portal.hardware.store') }}" id="hardware-form">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ $token }}">
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">{{ __('carbon_hardware.approved') }}</div>
                <div class="table-responsive"><table class="table mb-0">
                    <thead><tr><th>{{ __('carbon_hardware.select') }}</th><th>{{ __('carbon_hardware.label') }}</th><th>{{ __('carbon_hardware.availability') }}</th><th>{{ __('carbon_hardware.sell_price') }}</th><th>{{ __('carbon_hardware.quantity') }}</th></tr></thead>
                    <tbody>
                    @forelse($models as $model)
                        <tr>
                            <td><input type="checkbox" class="form-check-input hardware-select" data-row="{{ $loop->index }}" aria-label="{{ __('carbon_hardware.select') }} {{ $model->label }}" @disabled($reason)></td>
                            <td>{{ $model->label }}<div class="text-muted small">{{ $model->mfg_part_no }}</div></td>
                            <td>{{ __('carbon_hardware.unknown') }}</td>
                            <td>{{ $model->sell_price }}</td>
                            <td>
                                <input type="hidden" class="hardware-line-{{ $loop->index }}" name="lines[{{ $loop->index }}][model_id]" value="{{ $model->id }}" disabled>
                                <input type="number" class="form-control hardware-quantity hardware-line-{{ $loop->index }}" name="lines[{{ $loop->index }}][quantity]" value="1" min="1" max="1000000" data-price="{{ $model->sell_price }}" aria-label="{{ __('carbon_hardware.quantity') }} {{ $model->label }}" disabled>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ __('carbon_hardware.empty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>
        </div>
        <div class="col-lg-4"><div class="card"><div class="card-body">
            <p>{{ __('carbon_hardware.estimate') }}: <strong id="hardware-total">0.00</strong></p>
            <h2 class="h6">{{ __('carbon_hardware.ship_to') }}</h2>
            <p>{{ implode(', ', array_filter($address)) }}</p>
            <label for="hardware-po" class="form-label">{{ __('carbon_hardware.po') }}</label>
            <input id="hardware-po" class="form-control mb-3" name="endUserPoNumber" maxlength="255" @disabled($reason)>
            <button type="submit" class="btn btn-primary" @disabled($reason || $models->isEmpty())>{{ __('carbon_hardware.submit') }}</button>
            <p class="text-muted small mt-2">{{ __('carbon_hardware.not_charged') }}</p>
        </div></div></div>
    </div>
</form>
<div class="card mt-3">
    <div class="card-header">{{ __('carbon_hardware.orders') }}</div>
    <div class="table-responsive"><table class="table mb-0">
        <thead><tr><th>{{ __('carbon_hardware.date') }}</th><th>{{ __('carbon_hardware.ticket') }}</th><th>{{ __('carbon_hardware.estimate') }}</th><th>{{ __('carbon_hardware.status') }}</th></tr></thead>
        <tbody>@forelse($orders as $order)
            <tr><td>{{ $order->created_at->toAppTz()->format('Y-m-d H:i') }}</td><td>
                @if($order->ticket_id && ($portalPerson->company_wide_access || $portalPerson->id === $order->person_id))
                    <a href="{{ route('portal.tickets.show', $order->ticket_id) }}">#{{ $order->ticket_id }}</a>
                @endif
            </td><td>{{ $order->estimated_total }}</td><td>{{ __('carbon_hardware.'.$order->status) }}</td></tr>
        @empty
            <tr><td colspan="4">{{ __('carbon_hardware.no_orders') }}</td></tr>
        @endforelse</tbody>
    </table></div>
</div>
{{ $orders->links() }}
<script>
document.querySelectorAll('.hardware-select').forEach(input => input.addEventListener('change', () => {
    document.querySelectorAll('.hardware-line-' + input.dataset.row).forEach(field => field.disabled = !input.checked);
    updateHardwareTotal();
}));
function updateHardwareTotal() {
    let cents = 0;
    document.querySelectorAll('.hardware-quantity:not(:disabled)').forEach(input => {
        cents += Math.round(Number(input.dataset.price) * 100) * Number(input.value);
    });
    document.getElementById('hardware-total').textContent = (cents / 100).toFixed(2);
}
document.querySelectorAll('.hardware-quantity').forEach(input => input.addEventListener('input', updateHardwareTotal));
</script>
@endsection
