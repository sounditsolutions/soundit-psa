<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WikiFactCorrectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'statement' => ['required', 'string', 'max:300'],
        ];
    }
}
