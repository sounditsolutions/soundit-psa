<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientSiteNotesUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_notes' => ['nullable', 'string', 'max:20000'],
            'site_notes_updated_at' => ['nullable', 'date'],
        ];
    }
}
