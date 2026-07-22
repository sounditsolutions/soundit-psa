<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\TicketCategory;
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

        // The ticket's CURRENT taxonomy node, grandfathered below even when it
        // is soft-retired. Resolved from the route-bound model (implicit binding
        // on {ticket}), never from request input.
        $currentCategoryId = $this->route('ticket')?->category_id;

        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'required', Rule::enum(TicketType::class)],
            'priority' => ['sometimes', 'required', Rule::enum(TicketPriority::class)],
            'category' => ['nullable', 'string', 'max:100', Rule::in($categoryKeys)],
            'subcategory' => ['nullable', 'string', 'max:100'],
            // ITIL taxonomy node (so-0ftg) — distinct from the legacy free-text
            // pair above. A new assignment must be an ACTIVE node; a retired
            // node can never be hand-*assigned*. But the picker lists only
            // active nodes and the form always posts category_id, so a ticket
            // already sitting on a soft-retired node would otherwise post its
            // own id (re-surfaced + pre-selected in the picker) and be rejected
            // here — nulling the node on any unrelated save. So the ticket's OWN
            // current id is grandfathered through, even when inactive; every
            // OTHER inactive/nonexistent id stays rejected. Nullable = an
            // explicit "Uncategorized" clear (blank => null via the global
            // convert-empty-strings middleware, so the closure is skipped).
            // category_source is stamped by TicketObserver, never from input.
            'category_id' => [
                'nullable',
                function (string $attribute, $value, $fail) use ($currentCategoryId): void {
                    if (blank($value)) {
                        return; // explicit clear — nullable nulls it; nothing to check
                    }

                    if ($currentCategoryId !== null && (int) $value === (int) $currentCategoryId) {
                        return; // grandfather the ticket's own current node, retired or not
                    }

                    $isActiveNode = TicketCategory::query()
                        ->whereKey($value)
                        ->where('is_active', true)
                        ->exists();

                    if (! $isActiveNode) {
                        $fail('The selected SOP category must be an active taxonomy node.');
                    }
                },
            ],
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
                if (! in_array($subcategory, $validSubs, true)) {
                    $validator->errors()->add('subcategory', 'Invalid subcategory for the selected category.');
                }
            }
        });
    }
}
