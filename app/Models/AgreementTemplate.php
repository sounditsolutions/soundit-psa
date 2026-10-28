<?php

namespace App\Models;

use App\Enums\ContractType;
use App\Services\Signing\TemplateMerge;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class AgreementTemplate extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'kind' => 'agreement', 'signing_steps' => 1, 'on_complete' => 'none',
        'requires_countersign' => true, 'apply_billing_on_sign' => false, 'is_active' => false,
    ];

    protected function casts(): array
    {
        return [
            'requires_countersign' => 'boolean', 'is_active' => 'boolean',
            'apply_billing_on_sign' => 'boolean', 'signing_steps' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            if (! in_array($template->kind, ['agreement', 'proposal'], true)) {
                throw new InvalidArgumentException('signing_template_kind_invalid');
            }
            if (! is_string($template->contract_type) || ! ContractType::tryFrom($template->contract_type)) {
                throw new InvalidArgumentException('signing_template_type_invalid');
            }
            if (! in_array($template->signing_steps, [1, 2], true)) {
                throw new InvalidArgumentException('signing_template_steps_invalid');
            }
            if (! in_array($template->on_complete, ['none', 'purchase_block'], true)) {
                throw new InvalidArgumentException('signing_template_on_complete_invalid');
            }
            $template->body = app(TemplateMerge::class)->purify($template->body ?? '');
            if (trim($template->body) === '') {
                throw new InvalidArgumentException('signing_template_body_empty');
            }
            if ($template->kind === 'proposal' && ($template->signing_steps !== 1 || $template->requires_countersign)) {
                throw new InvalidArgumentException('signing_template_proposal_invalid');
            }
        });
    }
}
