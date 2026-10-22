<?php

namespace App\Models;

use App\Enums\ContractType;
use App\Services\Signing\TemplateMerge;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class AgreementTemplate extends Model
{
    protected $guarded = ['id'];

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
            if (! ContractType::tryFrom($template->contract_type) || $template->kind !== 'agreement') {
                throw new InvalidArgumentException('signing_template_kind_invalid');
            }
            $template->body = app(TemplateMerge::class)->purify($template->body);
        });
    }
}
