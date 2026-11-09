@if($hardwareModels->contains(fn ($row) => $row->carbon_price !== null && bccomp($row->carbon_price, $row->sell_price, 2) >= 0))
    <div class="alert alert-warning" role="alert">{{ __('carbon_hardware.margin_warning') }}</div>
@endif
@if(!$hardwareEnabled)
    <div class="alert alert-secondary">{{ __('carbon_hardware.unavailable') }}</div>
@elseif(!$client->carbon_customer_id)
    <div class="alert alert-secondary">{{ __('carbon_hardware.unmapped') }}</div>
@endif
@if($hardwareLookupError)
    <div class="alert alert-danger">{{ $hardwareLookupError }}</div>
@endif
<div class="card mb-3">
    <div class="card-header">{{ __('carbon_hardware.mapping') }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('clients.hardware.mapping', $client) }}">
            @csrf
            <label for="hardware-customer" class="form-label">{{ __('carbon_hardware.mapping') }}</label>
            <select class="form-select mb-2" name="carbon_customer_id" id="hardware-customer" @disabled(!$hardwareLive || $hardwareLookupError)>
                <option value="">{{ __('carbon_hardware.none') }}</option>
                @foreach($hardwareCustomers as $customer)
                    <option value="{{ $customer['id'] }}" @selected($client->carbon_customer_id === $customer['id'])>{{ $customer['name'] }}</option>
                @endforeach
            </select>
            <input type="hidden" name="enabled" value="0">
            <div class="form-check form-switch mb-2">
                <input type="checkbox" class="form-check-input" name="enabled" value="1" id="hardware-client-enabled" @checked($client->carbon_hardware_ordering_enabled)>
                <label for="hardware-client-enabled" class="form-check-label">{{ __('carbon_hardware.client_switch') }}</label>
            </div>
            <button class="btn btn-primary btn-sm" @disabled(!$hardwareLive || $hardwareLookupError)>{{ __('carbon_hardware.save') }}</button>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">{{ __('carbon_hardware.approved') }}</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="thead-brand"><tr>
                @foreach(['part', 'label', 'carbon_price', 'sell_price', 'margin', 'availability'] as $heading)
                    <th>{{ __('carbon_hardware.'.$heading) }}</th>
                @endforeach
                <th></th>
            </tr></thead>
            <tbody>
            @forelse($hardwareModels as $model)
                @php($warning = $model->carbon_price !== null && bccomp($model->carbon_price, $model->sell_price, 2) >= 0)
                <tr>
                    <td><code>{{ $model->mfg_part_no }}</code></td>
                    <td>{{ $model->label }} @if($warning)<span class="badge bg-warning text-dark">{{ __('carbon_hardware.margin_warning') }}</span>@endif</td>
                    <td>{{ $model->carbon_price ?? __('carbon_hardware.unknown') }}</td>
                    <td>
                        <form method="POST" action="{{ route('clients.hardware.model', [$client, 'price']) }}">
                            @csrf
                            <input type="hidden" name="model_id" value="{{ $model->id }}">
                            <label class="visually-hidden" for="price-{{ $model->id }}">{{ __('carbon_hardware.sell_price') }}</label>
                            <input class="form-control form-control-sm" id="price-{{ $model->id }}" name="sell_price" type="number" min="0" max="99999999.99" step="0.01" required value="{{ $model->sell_price }}">
                            <div class="form-check small">
                                <input class="form-check-input" id="confirm-{{ $model->id }}" type="checkbox" name="confirm_below_cost" value="1">
                                <label class="form-check-label" for="confirm-{{ $model->id }}">{{ __('carbon_hardware.confirm') }}</label>
                            </div>
                            <button class="btn btn-outline-primary btn-sm" @disabled(!$hardwareEnabled || !$client->carbon_customer_id)>{{ __('carbon_hardware.save') }}</button>
                        </form>
                    </td>
                    <td>{{ $model->carbon_price !== null && (float) $model->sell_price > 0 ? number_format(((float) $model->sell_price - (float) $model->carbon_price) / (float) $model->sell_price * 100, 1).'%' : __('carbon_hardware.unknown') }}</td>
                    <td>{{ __('carbon_hardware.'.($model->availability ?? 'unknown')) }}</td>
                    <td><form method="POST" action="{{ route('clients.hardware.model', [$client, 'remove']) }}">@csrf
                        <input type="hidden" name="model_id" value="{{ $model->id }}">
                        <button class="btn btn-outline-danger btn-sm" @disabled(!$hardwareEnabled || !$client->carbon_customer_id)>{{ __('carbon_hardware.remove') }}</button>
                    </form></td>
                </tr>
            @empty
                <tr><td colspan="7">{{ __('carbon_hardware.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('clients.hardware.model', [$client, 'add']) }}">
            @csrf
            <label class="form-label" for="hardware-part">{{ __('carbon_hardware.part') }}</label>
            <input class="form-control mb-2" id="hardware-part" name="mfg_part_no" maxlength="255" required>
            <label class="form-label" for="hardware-price">{{ __('carbon_hardware.sell_price') }}</label>
            <input class="form-control mb-2" id="hardware-price" name="sell_price" type="number" min="0" max="99999999.99" step="0.01" required>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="confirm_below_cost" value="1" id="hardware-confirm">
                <label class="form-check-label" for="hardware-confirm">{{ __('carbon_hardware.confirm') }}</label>
            </div>
            <button class="btn btn-primary btn-sm" @disabled(!$hardwareEnabled || !$client->carbon_customer_id)>{{ __('carbon_hardware.add') }}</button>
        </form>
    </div>
</div>
