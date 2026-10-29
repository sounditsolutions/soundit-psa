<?php

namespace Tests\Feature\Contracts;

use App\Models\AgreementTemplate;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Setting;
use App\Services\Signing\PdfRenderer;
use App\Services\Signing\SigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SigningPageBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['signing.enabled' => true]);
        $this->app->instance(PdfRenderer::class, new class extends PdfRenderer
        {
            public function render(string $html): string
            {
                return '%PDF-example '.$html;
            }
        });
    }

    private function page(string $view): \Illuminate\Testing\TestResponse
    {
        $token = str_repeat('B', 48);
        if ($view === 'neutral') {
            return $this->get('/sign/'.$token)->assertOk()->assertViewIs('signing.neutral');
        }
        $kind = in_array($view, ['accept', 'accepted'], true) ? 'proposal' : 'agreement';
        $contract = Contract::withoutEvents(fn () => Contract::create([
            'client_id' => Client::factory()->create()->id, 'name' => 'Example agreement',
            'type' => 'breakfix', 'status' => 'active', 'start_date' => '2026-01-01',
        ]));
        $contract->lines()->create([
            'version' => 1, 'source' => 'example', 'description' => 'Example support',
            'quantity_type' => 'fixed', 'fixed_quantity' => '2.00', 'unit_price' => '125.00',
            'is_taxable' => false, 'sort_order' => 0,
        ]);
        $template = AgreementTemplate::create([
            'name' => 'Example '.$kind, 'kind' => $kind, 'contract_type' => 'breakfix',
            'body' => '<p>Example document</p>{{lines_table}}', 'is_active' => true,
            'requires_countersign' => false, 'signing_steps' => 1,
        ]);
        $document = app(SigningService::class)->generate($contract, $template, ['name' => 'Alex Example', 'email' => 'alex@example.test']);
        $document->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        $response = in_array($view, ['accepted', 'signed'], true)
            ? $this->post('/sign/'.$token, ['typed_name' => 'Alex Example', 'pdf_sha256' => $document->pdf_sha256])
            : $this->get('/sign/'.$token);

        return $response->assertOk()->assertViewIs('signing.'.$view);
    }

    public static function pages(): array
    {
        return ['accept' => ['accept'], 'accepted' => ['accepted'], 'neutral' => ['neutral'], 'public' => ['public'], 'signed' => ['signed']];
    }

    #[DataProvider('pages')]
    public function test_each_signing_page_names_the_msp_without_draft_or_sound_it(string $view): void
    {
        Setting::setValue('portal_company_name', 'Northwind MSP');
        $page = $this->page($view);
        $page->assertSee('Northwind MSP')->assertSee('Document signing');
        $this->assertStringNotContainsString('Sound IT', $page->getContent());
        $this->assertStringNotContainsString('DRAFT', $page->getContent());
    }

    #[DataProvider('pages')]
    public function test_signing_page_escapes_the_msp_name(string $view): void
    {
        Setting::setValue('portal_company_name', '<script>alert(1)</script>');
        $html = $this->page($view)->getContent();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_whitespace_only_sender_name_falls_back_to_app_name(): void
    {
        config(['app.name' => 'Example PSA']);
        Setting::setValue('portal_company_name', '   ');
        $this->assertSame('Example PSA', SigningService::senderName());
    }

    public function test_padded_sender_name_is_trimmed(): void
    {
        Setting::setValue('portal_company_name', '  Northwind MSP  ');
        $this->assertSame('Northwind MSP', SigningService::senderName());
    }
}
