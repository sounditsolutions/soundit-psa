<?php

namespace App\Http\Requests;

use App\Enums\PersonType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'person_type' => ['sometimes', 'required', 'string', Rule::in(array_column(PersonType::cases(), 'value'))],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
            'additional_emails' => ['nullable', 'array', 'max:10'],
            'additional_emails.*.email' => ['required', 'email', 'max:255'],
            'additional_emails.*.label' => ['nullable', 'string', 'max:50'],
        ];
    }
}
