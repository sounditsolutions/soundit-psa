<?php

namespace App\Services\Signing;

use App\Enums\QuantityType;
use App\Models\SignableDocument;
use HTMLPurifier;
use HTMLPurifier_Config;

class TemplateMerge
{
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
        $table = '<table><thead><tr><th>DRAFT — Description</th><th>DRAFT — Quantity basis</th><th>DRAFT — Quantity</th><th>DRAFT — Rate</th><th>DRAFT — Taxable</th><th>DRAFT — Option group</th><th>DRAFT — Term</th></tr></thead><tbody>';
        foreach ($document->rendered_lines as $line) {
            $basis = QuantityType::from($line['quantity_type']);
            $tiers = $line['pricing_tiers'] ?? [];
            $rate = (string) ($line['unit_price'] ?? '');
            if ($tiers !== []) {
                $bands = [];
                foreach ($tiers as $tier) {
                    $bands[] = 'DRAFT — Up to '.($tier['up_to'] ?? 'unlimited').': '.$tier['unit_price'];
                }
                $rate = implode('; ', $bands);
            }
            $cells = [
                $line['description'], 'DRAFT — '.$basis->label(),
                $basis === QuantityType::Fixed ? $line['fixed_quantity'] : 'DRAFT — variable',
                $rate, 'DRAFT — '.(! empty($line['is_taxable']) ? 'Yes' : 'No'),
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
