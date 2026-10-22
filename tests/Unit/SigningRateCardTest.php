<?php

namespace Tests\Unit;

use App\Models\SignableDocument;
use App\Services\Signing\TemplateMerge;
use Tests\TestCase;

class SigningRateCardTest extends TestCase
{
    public function test_flat_rate_uses_frozen_document_and_escapes_content(): void
    {
        $document = new SignableDocument(['rendered_lines' => [[
            'description' => '<script>example</script>',
            'quantity_type' => 'fixed', 'fixed_quantity' => '3.00',
            'unit_price' => '125.00', 'pricing_tiers' => null,
            'is_taxable' => true, 'option_group' => 'Example option', 'term' => 'Monthly',
        ]]]);
        $result = (new TemplateMerge)->merge('{{client.name}} {{lines_table}} {{unknown}}', ['client.name' => '<b>Example</b>'], $document);
        $this->assertStringContainsString('125.00', $result['html']);
        $this->assertStringContainsString('3.00', $result['html']);
        $this->assertStringContainsString('DRAFT — Fixed', $result['html']);
        $this->assertStringContainsString('DRAFT — Yes', $result['html']);
        $this->assertStringContainsString('Example option', $result['html']);
        $this->assertStringContainsString('Monthly', $result['html']);
        $this->assertStringContainsString('&lt;script&gt;', $result['html']);
        $this->assertStringContainsString('&lt;b&gt;Example&lt;/b&gt;', $result['html']);
        $this->assertSame(['{{unknown}}'], $result['unknown_tokens']);
    }

    public function test_tiered_dynamic_rate_shows_all_bands_without_flat_rate_or_fixed_quantity(): void
    {
        $document = new SignableDocument(['rendered_lines' => [[
            'description' => 'Example service', 'quantity_type' => 'per_user',
            'fixed_quantity' => '999.00', 'unit_price' => '888.00',
            'pricing_tiers' => [['up_to' => 10, 'unit_price' => '25.00'], ['up_to' => null, 'unit_price' => '20.00']],
            'is_taxable' => false,
        ]]]);
        $html = (new TemplateMerge)->merge('{{lines_table}}', [], $document)['html'];
        $this->assertStringContainsString('DRAFT — Per User', $html);
        $this->assertStringContainsString('DRAFT — variable', $html);
        $this->assertStringContainsString('DRAFT — Up to 10: 25.00', $html);
        $this->assertStringContainsString('DRAFT — Up to unlimited: 20.00', $html);
        $this->assertStringNotContainsString('888.00', $html);
        $this->assertStringNotContainsString('999.00', $html);
    }
}
