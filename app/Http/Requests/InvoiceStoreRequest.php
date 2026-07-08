<?php

namespace App\Http\Requests;

use App\Models\Contract;
use Illuminate\Foundation\Http\FormRequest;

class InvoiceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'contract_id' => ['nullable', 'integer', 'exists:contracts,id'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku_id' => ['nullable', 'integer', 'exists:skus,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.is_taxable' => ['boolean'],
            'lines.*.prepaid_time_minutes' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $contractId = $this->input('contract_id');
            $clientId = $this->input('client_id');

            if ($contractId && $clientId) {
                $belongs = Contract::where('id', $contractId)
                    ->where('client_id', $clientId)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('contract_id', 'The selected contract does not belong to this client.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'An invoice must have at least one line item.',
            'lines.min' => 'An invoice must have at least one line item.',
            'lines.*.description.required' => 'Each line must have a description.',
            'lines.*.quantity.required' => 'Each line must have a quantity.',
            'lines.*.quantity.min' => 'Quantity must be at least 0.01.',
            'lines.*.unit_price.required' => 'Each line must have a unit price.',
        ];
    }
}
