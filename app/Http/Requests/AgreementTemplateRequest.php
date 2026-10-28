<?php

namespace App\Http\Requests;

use App\Enums\ContractType;
use App\Models\SignableDocument;
use App\Services\Signing\TemplateMerge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AgreementTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'template_name' => ['required', 'string', 'max:255'],
            'template_kind' => ['required', Rule::in(['agreement', 'proposal'])],
            'template_type' => ['required', Rule::enum(ContractType::class)],
            'template_steps' => ['required', 'integer', Rule::in([1, 2])],
            'template_countersign' => ['required', 'boolean'],
            'template_body' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'template_kind.*' => 'signing_template_kind_invalid',
            'template_type.*' => 'signing_template_type_invalid',
            'template_steps.*' => 'signing_template_steps_invalid',
            'template_body.*' => 'signing_template_body_empty',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $merge = app(TemplateMerge::class);
            $body = $merge->purify($this->string('template_body')->toString());
            if (trim($body) === '') {
                $validator->errors()->add('template_body', 'signing_template_body_empty');

                return;
            }
            $result = $merge->merge($body, [], new SignableDocument(['rendered_lines' => []]));
            if (! $result['lines_table']) {
                $validator->errors()->add('template_body', 'signing_lines_table_missing');
            }
            if ($result['unknown_tokens'] !== []) {
                $validator->errors()->add('template_body', 'signing_unknown_merge_token');
            }
            if ($this->input('template_kind') === 'proposal' && ($this->integer('template_steps') !== 1 || $this->boolean('template_countersign'))) {
                $validator->errors()->add('template_kind', 'signing_template_proposal_invalid');
            }
        }];
    }

    public function templateAttributes(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['template_name'], 'kind' => $data['template_kind'],
            'contract_type' => $data['template_type'], 'signing_steps' => $data['template_steps'],
            'requires_countersign' => $data['template_countersign'], 'body' => $data['template_body'],
        ];
    }
}
