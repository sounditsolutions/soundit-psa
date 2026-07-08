<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
            'primary_tech_id' => ['nullable', 'exists:users,id'],
            'reseller_id' => ['nullable', 'exists:clients,id,deleted_at,NULL', Rule::notIn([$this->route('client')?->id])],
            'site_notes' => ['nullable', 'string', 'max:20000'],
            'credentials' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
