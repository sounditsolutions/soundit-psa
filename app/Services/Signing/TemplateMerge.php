<?php

namespace App\Services\Signing;

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

    /** @return array{html:string,unknown_tokens:array} */
    public function merge(string $body, array $values, array $lines): array
    {
        $body = $this->purify($body);
        $replacements = [];
        foreach (['client.name', 'contract.name', 'contract.type', 'today', 'signer.name'] as $field) {
            $replacements['{{'.$field.'}}'] = htmlspecialchars((string) ($values[$field] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $table = '<table><thead><tr><th>Description</th><th>Quantity type</th><th>Quantity</th><th>Unit price</th></tr></thead><tbody>';
        foreach ($lines as $line) {
            $table .= '<tr>';
            foreach (['description', 'quantity_type', 'fixed_quantity', 'unit_price'] as $key) {
                $table .= '<td>'.htmlspecialchars((string) ($line[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</td>';
            }
            $table .= '</tr>';
        }
        $replacements['{{lines_table}}'] = $table.'</tbody></table>';
        preg_match_all('/\{\{[^{}]+\}\}/', $body, $matches);

        return [
            'html' => strtr($body, $replacements),
            'unknown_tokens' => array_values(array_diff(array_unique($matches[0]), array_keys($replacements))),
        ];
    }
}
