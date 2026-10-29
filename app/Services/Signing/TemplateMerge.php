<?php

namespace App\Services\Signing;

use App\Enums\QuantityType;
use App\Models\BackupStorageTier;
use App\Models\SignableDocument;
use App\Support\TieredPricing;
use HTMLPurifier;
use HTMLPurifier_Config;

class TemplateMerge
{
    public static function volumeBands(array $tiers): string
    {
        usort($tiers, [BackupStorageTier::class, 'compareRateCard']);
        $bands = [];
        $hasUnlimited = false;
        foreach ($tiers as $tier) {
            $hasUnlimited = $hasUnlimited || $tier['up_to_gb'] === null;
            $bands[] = __('signing.rate_card.'.($tier['up_to_gb'] === null ? 'volume_unlimited_band' : 'volume_band'), ['limit' => $tier['up_to_gb'], 'price' => $tier['unit_price']]);
        }
        if ($tiers !== [] && ! $hasUnlimited) {
            $bands[] = __('signing.rate_card.volume_unlimited_band', ['price' => $tier['unit_price']]);
        }

        return implode('; ', $bands);
    }

    public function purify(string $body): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p,br,strong,em,h1,h2,h3,ul,ol,li,table,thead,tbody,tr,th,td');

        return (new HTMLPurifier($config))->purify($body);
    }

    /** @return array{html:string,unknown_tokens:array,lines_table:bool} */
    public function merge(string $body, array $values, SignableDocument $document): array
    {
        $body = $this->purify($body);
        $replacements = [];
        foreach (['client.name', 'contract.name', 'contract.type', 'today', 'signer.name'] as $field) {
            $replacements['{{'.$field.'}}'] = htmlspecialchars((string) ($values[$field] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $table = '<table><thead><tr><th>'.__('signing.rate_card.description').'</th><th>'.__('signing.rate_card.basis').'</th><th>'.__('signing.rate_card.quantity').'</th><th>'.__('signing.rate_card.rate').'</th><th>'.__('signing.rate_card.taxable').'</th><th>'.__('signing.rate_card.option_group').'</th><th>'.__('signing.rate_card.term').'</th></tr></thead><tbody>';
        foreach ($document->rendered_lines as $line) {
            $basis = QuantityType::from($line['quantity_type']);
            $tiers = $line['pricing_tiers'] ?? [];
            $rate = (string) ($line['unit_price'] ?? '');
            if (TieredPricing::normalize($tiers) !== []) {
                $bands = [];
                foreach ($tiers as $tier) {
                    $bands[] = __('signing.rate_card.'.(isset($tier['up_to']) ? 'band' : 'unlimited_band'), ['limit' => $tier['up_to'] ?? '', 'price' => $tier['unit_price']]);
                }
                $rate = implode('; ', $bands);
            } elseif (! empty($line['sku_volume_tiers'])) {
                $rate = __('signing.rate_card.volume_heading').' '.self::volumeBands($line['sku_volume_tiers']);
            }
            $cells = [
                $line['description'], __('signing.rate_card.'.$basis->value),
                $basis === QuantityType::Fixed ? $line['fixed_quantity'] : __('signing.rate_card.variable'),
                $rate, __('signing.rate_card.'.(! empty($line['is_taxable']) ? 'yes' : 'no')),
                $line['option_group'] ?? '', $line['term'] ?? '',
            ];
            $table .= '<tr>';
            foreach ($cells as $cell) {
                $table .= '<td>'.htmlspecialchars((string) $cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</td>';
            }
            $table .= '</tr>';
        }
        $replacements['{{lines_table}}'] = $table.'</tbody></table>';
        preg_match_all('/\{\{[^{}]+\}\}/', $body, $matches);

        return [
            'html' => strtr($body, $replacements),
            'unknown_tokens' => array_values(array_diff(array_unique($matches[0]), array_keys($replacements))),
            'lines_table' => str_contains($body, '{{lines_table}}'),
        ];
    }
}
