<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WikiPageUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'body_md' => ['required', 'string', 'max:200000'],
            'change_summary' => ['nullable', 'string', 'max:255'],
            'expected_updated_at' => ['required', 'string'],
        ];
    }
}
