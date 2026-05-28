<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryKeys = array_keys(config('tickets.categories', []));

        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'required', Rule::enum(TicketType::class)],
            'priority' => ['sometimes', 'required', Rule::enum(TicketPriority::class)],
            'category' => ['nullable', 'string', 'max:100', Rule::in($categoryKeys)],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'contact_id' => ['nullable', 'exists:people,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'due_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $category = $this->input('category');
            $subcategory = $this->input('subcategory');

            if ($category && $subcategory) {
                $validSubs = config("tickets.categories.{$category}", []);
                if (!in_array($subcategory, $validSubs, true)) {
                    $validator->errors()->add('subcategory', 'Invalid subcategory for the selected category.');
                }
            }
        });
    }
}
