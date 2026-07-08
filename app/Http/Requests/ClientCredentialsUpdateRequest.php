<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientCredentialsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credentials' => ['nullable', 'string', 'max:20000'],
            'credentials_updated_at' => ['nullable', 'date'],
        ];
    }
}
