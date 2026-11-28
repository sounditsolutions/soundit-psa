{{--
    The hourly-rate anchor above a block's price (Sku::portalHourlyAnchor()):
    "N hours at our hourly rate ($X/h) $Y / Block price / Special price".
    Renders nothing when there is no anchor: no single active 60-minute SKU in
    the block's category, or a value not higher than the block price.
    The special-price row appears only while the promo is in force.
    Copy pending approval; a change is a string swap here.

    Expects: $anchor (array|null), $sku, $unitPrice, $promoInForce
--}}
@if($anchor)
    <table class="portal-price-steps small mt-1" aria-label="How this price compares">
        <tr>
            <td>{{ rtrim(rtrim(number_format($anchor['hours'], 2, '.', ''), '0'), '.') }} {{ $anchor['hours'] == 1 ? 'hour' : 'hours' }} at our hourly rate (${{ number_format($anchor['rate'], 2) }}/h)</td>
            <td class="text-end">${{ number_format($anchor['value'], 2) }}</td>
        </tr>
        <tr>
            <td>Block price</td>
            <td class="text-end">${{ number_format($sku->unit_price, 2) }}</td>
        </tr>
        @if($promoInForce)
            <tr class="fw-semibold">
                <td>Special price</td>
                <td class="text-end">${{ number_format($unitPrice, 2) }}</td>
            </tr>
        @endif
    </table>
@endif
