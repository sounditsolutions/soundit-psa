<?php

namespace Tests\Feature\Signing;

use App\Models\AgreementTemplate;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Setting;
use App\Models\SignableDocument;
use App\Services\Signing\PdfRenderer;
use App\Services\Signing\SigningService;
use App\Support\PortalConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * #7762: approved signing copy (no "DRAFT — " prefix) and white-label sender name.
 * The subjects take :company from the Branding company name (PortalConfig::companyName()),
 * falling back to config('app.name') when the stored name is ''. Synthetic data only.
 */
class SigningWhiteLabelCopyTest extends TestCase
{
    use RefreshDatabase;

    private const MSP = 'Northwind MSP';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->app->instance(PdfRenderer::class, new class extends PdfRenderer
        {
            public function render(string $html): string
            {
                return '%PDF-example '.$html;
            }
        });
    }

    private function contract(): Contract
    {
        $contract = Contract::withoutEvents(fn () => Contract::create([
            'client_id' => Client::factory()->create()->id, 'name' => 'Example agreement',
            'type' => 'breakfix', 'status' => 'active', 'start_date' => '2026-01-01',
        ]));
        $contract->lines()->create([
            'version' => 1, 'source' => 'example', 'description' => 'Example support',
            'quantity_type' => 'fixed', 'fixed_quantity' => '2.00', 'unit_price' => '125.00',
            'is_taxable' => false, 'sort_order' => 0,
        ]);

        return $contract;
    }

    private function document(string $kind): SignableDocument
    {
        $template = AgreementTemplate::create([
            'name' => 'Example '.$kind, 'kind' => $kind, 'contract_type' => 'breakfix',
            'body' => '<p>Example '.$kind.'</p>{{lines_table}}', 'is_active' => true,
            'requires_countersign' => false, 'signing_steps' => 1,
        ]);

        return app(SigningService::class)->generate($this->contract(), $template, ['name' => 'Alex Example', 'email' => 'alex@example.test']);
    }

    /** @return array{0: string, 1: string} the [subject, body] SigningService::send() hands the mailer */
    private function sentMail(string $kind): array
    {
        config(['signing.enabled' => true]);
        $document = $this->document($kind);
        $sent = null;
        $this->mock(\App\Services\EmailService::class)->shouldReceive('sendNew')->once()
            ->andReturnUsing(function ($to, $subject, $body) use (&$sent) {
                $sent = [$subject, $body];

                return new \App\Models\Email;
            });
        app(SigningService::class)->send($document);
        $this->assertNotNull($sent);

        return $sent;
    }

    private function assertApprovedOutput(string $rendered): void
    {
        $this->assertStringNotContainsString('DRAFT', $rendered);
        $this->assertDoesNotMatchRegularExpression('/sound\s*it/i', $rendered);
    }

    public function test_both_mail_subjects_name_the_branding_company_and_carry_no_draft_or_sound_it(): void
    {
        Setting::setValue('portal_company_name', self::MSP);
        foreach (['agreement' => 'Agreement', 'proposal' => 'Proposal'] as $kind => $word) {
            [$subject, $body] = $this->sentMail($kind);
            $this->assertSame($word.' from '.self::MSP.' ready for review', $subject, $kind);
            $this->assertMatchesRegularExpression('~^Review your '.$kind.' using this link:\nhttp\S+/sign/[A-Za-z0-9]{48}$~D', $body, $kind);
            $this->assertApprovedOutput($subject."\n".$body);
        }
    }

    public function test_blank_branding_name_falls_back_to_the_app_name_in_the_subject(): void
    {
        config(['app.name' => 'Example PSA']);
        Setting::setValue('portal_company_name', '');
        $this->assertSame('', PortalConfig::companyName(), 'positive control: a stored blank is returned as-is');
        [$subject] = $this->sentMail('agreement');
        $this->assertSame('Agreement from Example PSA ready for review', $subject);
        $this->assertSame('Example PSA', SigningService::senderName());
    }

    public function test_unset_branding_name_uses_portal_config_default(): void
    {
        config(['app.name' => 'Example PSA']);
        [$subject] = $this->sentMail('proposal');
        $this->assertSame('Proposal from Example PSA ready for review', $subject);
    }

    public function test_public_signing_pages_render_no_draft_and_no_sound_it(): void
    {
        config(['signing.enabled' => true]);
        Setting::setValue('portal_company_name', self::MSP);
        foreach (['agreement' => ['Review and sign', 'Signature recorded'], 'proposal' => ['Accept selections and pricing', 'Selections accepted']] as $kind => [$open, $done]) {
            $document = $this->document($kind);
            $token = str_repeat($kind === 'agreement' ? 'D' : 'E', 48);
            $document->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
            $page = $this->get('/sign/'.$token)->assertOk()->assertSee($open)->assertSee('Document signing');
            $this->assertApprovedOutput($page->getContent());
            $after = $this->post('/sign/'.$token, ['typed_name' => 'Alex Example', 'pdf_sha256' => $document->pdf_sha256])->assertOk()->assertSee($done);
            $this->assertApprovedOutput($after->getContent());
        }
        $neutral = $this->get('/sign/'.str_repeat('Z', 48))->assertOk()->assertSee('Link unavailable');
        $this->assertApprovedOutput($neutral->getContent());
    }

    public function test_signing_stays_dormant_by_default(): void
    {
        $this->assertFalse((bool) config('signing.enabled'), 'signing ships disabled');
        config(['signing.enabled' => true]);
        $document = $this->document('agreement');
        config(['signing.enabled' => false]);
        $this->mock(\App\Services\EmailService::class)->shouldReceive('sendNew')->never();
        try {
            app(SigningService::class)->send($document);
            $this->fail('send must refuse while signing is disabled');
        } catch (RuntimeException $error) {
            $this->assertSame('signing_send_unavailable', $error->getMessage());
        }
        $this->assertSame('draft', $document->fresh()->status);
    }
}
