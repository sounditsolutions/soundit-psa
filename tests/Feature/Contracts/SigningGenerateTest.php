<?php

namespace Tests\Feature\Contracts;

use App\Models\AgreementTemplate;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractLine;
use App\Models\SignableDocument;
use App\Services\Signing\PdfRenderer;
use App\Services\Signing\SigningService;
use App\Services\Signing\TemplateMerge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class SigningGenerateTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    private AgreementTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        config(['signing.enabled' => true]);
        Storage::fake('local');
        $this->contract = Contract::withoutEvents(fn () => Contract::create([
            'client_id' => Client::factory()->create()->id, 'name' => 'Example agreement',
            'type' => 'breakfix', 'status' => 'active', 'start_date' => '2026-01-01',
        ]));
        $this->contract->lines()->create([
            'version' => 1, 'source' => 'example', 'description' => 'Example support',
            'quantity_type' => 'fixed', 'fixed_quantity' => '2.00', 'unit_price' => '125.00',
            'is_taxable' => false, 'sort_order' => 0,
        ]);
        $this->template = AgreementTemplate::create([
            'name' => 'Example template', 'contract_type' => 'breakfix', 'kind' => 'agreement',
            'body' => '<h1>DRAFT — Agreement</h1>{{lines_table}}', 'is_active' => true,
            'requires_countersign' => true, 'signing_steps' => 1,
        ]);
        $this->app->instance(PdfRenderer::class, new class extends PdfRenderer
        {
            public function render(string $html): string
            {
                return '%PDF-example '.$html;
            }
        });
    }

    private function generate(): SignableDocument
    {
        return app(SigningService::class)->generate($this->contract, $this->template, ['name' => 'Alex Example', 'email' => 'alex@example.test']);
    }

    public function test_generation_hashes_and_rate_card_stay_frozen_after_source_edits(): void
    {
        $document = $this->generate()->fresh();
        $bytes = Storage::disk('local')->get($document->pdf_path);
        $this->assertSame(hash('sha256', $bytes), $document->pdf_sha256);
        $this->assertSame(hash('sha256', $document->getRawOriginal('rendered_lines')), $document->rendered_lines_sha256);
        $before = app(TemplateMerge::class)->merge('{{lines_table}}', [], $document);
        $this->contract->lines()->update(['unit_price' => '999.00', 'fixed_quantity' => '9.00']);
        $this->template->update(['requires_countersign' => false, 'body' => 'DRAFT — Changed']);
        $this->assertSame($before, app(TemplateMerge::class)->merge('{{lines_table}}', [], $document->fresh()));
        $this->assertTrue($document->fresh()->requires_countersign);
        $this->assertSame($bytes, Storage::disk('local')->get($document->pdf_path));
    }

    public function test_all_five_evidence_fields_are_write_once_but_status_is_mutable(): void
    {
        $document = $this->generate();
        foreach (['rendered_lines' => [], 'rendered_lines_sha256' => str_repeat('a', 64), 'requires_countersign' => false, 'pdf_path' => 'changed.pdf', 'pdf_sha256' => str_repeat('b', 64)] as $key => $value) {
            $original = $document->fresh()->getRawOriginal();
            try {
                $document->fresh()->update([$key => $value]);
                $this->fail('Evidence update accepted: '.$key);
            } catch (LogicException $error) {
                $this->assertSame('signing_document_evidence_immutable', $error->getMessage());
            }
            $this->assertSame($original, $document->fresh()->getRawOriginal());
        }
        $document->update(['status' => 'void']);
        $this->assertSame('void', $document->fresh()->status);
    }

    public function test_multistep_refuses_without_a_row_or_file(): void
    {
        $this->template->update(['signing_steps' => 2]);
        try {
            $this->generate();
            $this->fail('Multistep accepted');
        } catch (RuntimeException $error) {
            $this->assertSame('signing_multistep_unsupported', $error->getMessage());
        }
        $this->assertDatabaseCount('signable_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('signing'));
    }

    public function test_missing_price_refuses_without_row_or_file(): void
    {
        // Existing schema forbids NULL prices; exercise the defensive hydrated-input seam.
        ContractLine::retrieved(function (ContractLine $line): void {
            $line->unit_price = null;
            $line->pricing_tiers = null;
        });
        try {
            $this->generate();
            $this->fail('Missing price accepted');
        } catch (RuntimeException $error) {
            $this->assertSame('signing_line_price_missing', $error->getMessage());
        }
        $this->assertDatabaseCount('signable_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('signing'));
    }

    public function test_real_renderer_options_and_pdf_bytes(): void
    {
        $renderer = new PdfRenderer;
        $options = $renderer->instance()->getOptions();
        $this->assertFalse($options->getIsRemoteEnabled());
        $this->assertFalse($options->getIsPhpEnabled());
        $this->assertFalse($options->getIsJavascriptEnabled());
        $this->assertSame([resource_path('signing')], $options->getChroot());
        $this->assertSame(storage_path('app/private/signing-fonts'), $options->getFontDir());
        $this->assertSame(storage_path('app/private/signing-fonts'), $options->getFontCache());
        $this->assertSame('DejaVu Sans', $options->getDefaultFont());
        $this->assertStringStartsWith('%PDF-', $renderer->render('<p>DRAFT — Example agreement</p>'));
        $this->assertTrue(version_compare(\Composer\InstalledVersions::getVersion('dompdf/dompdf'), '3.1.6', '>='));
    }

    public function test_client_and_msp_snapshots_use_frozen_lines_and_spend_token(): void
    {
        $document = $this->generate();
        $frozen = $document->rendered_lines;
        $token = str_repeat('T', 48);
        $document->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDays(14)]);
        $this->contract->lines()->update(['unit_price' => '999.00']);
        $this->template->update(['requires_countersign' => false, 'body' => 'DRAFT — Changed']);
        $service = app(SigningService::class);
        $service->view($token);
        $service->sign($token, 'Alex Example', $document->pdf_sha256, '127.0.0.1', 'Synthetic agent');
        $this->assertNull($document->fresh()->token_hash);
        $this->assertSame('signed', $document->fresh()->status);
        $this->assertFalse($document->fresh()->isFinal());
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $service->countersign($document, $admin, '127.0.0.1', 'Synthetic agent');
        $this->assertTrue($document->fresh()->isFinal());
        $this->assertCount(2, $document->snapshots);
        foreach ($document->snapshots as $snapshot) {
            $this->assertSame($frozen, $snapshot->lines);
            $this->assertSame($document->rendered_lines_sha256, $snapshot->lines_sha256);
        }
    }

    public function test_insert_failure_removes_written_file_and_row(): void
    {
        SignableDocument::creating(fn () => throw new RuntimeException('synthetic_insert_failure'));
        try {
            $this->generate();
            $this->fail('Insert accepted');
        } catch (RuntimeException $error) {
            $this->assertSame('synthetic_insert_failure', $error->getMessage());
        }
        $this->assertDatabaseCount('signable_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('signing'));
    }
}
