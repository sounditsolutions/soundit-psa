@php
    $destination = $destination ?? null;
    $mcpTokens = $mcpTokens ?? collect();
    $secretMask = $secretMask ?? '••••••••';
@endphp

<div class="mb-3">
    <label for="label" class="form-label">Label</label>
    <input type="text" id="label" name="label" value="{{ old('label', $destination->label ?? '') }}" class="form-control @error('label') is-invalid @enderror" required>
    @error('label')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="type" class="form-label">Type</label>
    <select id="type" name="type" class="form-select @error('type') is-invalid @enderror" required>
        @foreach(['webhook' => 'Webhook', 'email' => 'Email', 'mcp' => 'MCP Agent'] as $value => $label)
            <option value="{{ $value }}" @selected(old('type', $destination->type ?? 'webhook') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    @error('type')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3" data-field-for="webhook,email">
    <label for="address" class="form-label">Webhook URL or Email</label>
    <input type="text" id="address" name="address" value="{{ old('address') }}" placeholder="{{ $destination?->masked_address ?? $secretMask }}" class="form-control @error('address') is-invalid @enderror" autocomplete="off">
    @error('address')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3" data-field-for="mcp">
    <label for="mcp_token_label" class="form-label">MCP Token Label</label>
    <select id="mcp_token_label" name="mcp_token_label" class="form-select @error('mcp_token_label') is-invalid @enderror">
        <option value="">Choose a scoped token</option>
        @foreach($mcpTokens as $token)
            <option value="{{ $token->label }}" @selected(old('mcp_token_label', $destination->mcp_token_label ?? null) === $token->label)>{{ $token->label }}</option>
        @endforeach
    </select>
    <div class="form-text">{{ "Rotating this token's label re-points or orphans this destination" }}</div>
    @error('mcp_token_label')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3" data-field-for="mcp">
    <label for="wake_url" class="form-label">Wake URL</label>
    <input type="text" id="wake_url" name="wake_url" value="{{ old('wake_url') }}" placeholder="{{ $destination?->masked_wake_url ?? $secretMask }}" class="form-control @error('wake_url') is-invalid @enderror" autocomplete="off">
    @error('wake_url')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3" data-field-for="mcp">
    <label for="wake_secret" class="form-label">Wake Secret</label>
    <input type="password" id="wake_secret" name="wake_secret" placeholder="{{ $destination?->masked_wake_secret ?? $secretMask }}" class="form-control @error('wake_secret') is-invalid @enderror" autocomplete="new-password">
    @error('wake_secret')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

@push('scripts')
<script>
(function () {
    const typeSelect = document.getElementById('type');
    if (!typeSelect) return;

    function applyType() {
        const type = typeSelect.value;
        document.querySelectorAll('[data-field-for]').forEach((el) => {
            const types = el.dataset.fieldFor.split(',');
            el.classList.toggle('d-none', !types.includes(type));
        });
    }

    typeSelect.addEventListener('change', applyType);
    applyType();
})();
</script>
@endpush
