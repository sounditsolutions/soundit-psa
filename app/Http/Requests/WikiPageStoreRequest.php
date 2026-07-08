<?php

namespace App\Http\Requests;

use App\Enums\WikiPageKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WikiPageStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // staff-only app; auth middleware gates access
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9\/\-]*$/'],
            'kind' => ['required', Rule::enum(WikiPageKind::class)],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'parent_page_id' => ['nullable', 'integer', 'exists:wiki_pages,id'],
            'body_md' => ['nullable', 'string', 'max:200000'],
        ];
    }
}
