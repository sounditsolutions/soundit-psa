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

    public function test_public_embed_bytes_match_hidden_hash_and_staff_download_is_final_only(): void
    {
        $document = $this->generate();
        $token = str_repeat('P', 48);
        $document->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDays(14)]);
        $page = $this->get('/sign/'.$token)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $pdf = $this->get('/sign/'.$token.'/pdf')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $page->assertSee('value="'.hash('sha256', $pdf->getContent()).'"', false);
        $page->assertSee('<object', false)->assertDontSee('Example support');
        $url = route('signing.download', [$this->contract, $document]);
        $this->get($url)->assertRedirect();
        $user = \App\Models\User::factory()->create(['role' => 'tech']);
        $this->actingAs($user)->get($url)->assertForbidden();
        $this->actingAs($user)->get(route('contracts.show', $this->contract))->assertDontSee('Download signed PDF');
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get($url)->assertNotFound();
        $document->update(['status' => 'signed']);
        $this->get($url)->assertNotFound();
        $document->update(['status' => 'countersigned', 'token_hash' => null]);
        $this->get($url)->assertOk()->assertContent($pdf->getContent());
        Storage::disk('local')->put($document->pdf_path, 'tampered');
        $this->get($url)->assertStatus(409)->assertDontSee('tampered');
        $other = Contract::withoutEvents(fn () => Contract::create([
            'client_id' => $this->contract->client_id, 'name' => 'Other example', 'type' => 'breakfix', 'status' => 'active', 'start_date' => '2026-01-01',
        ]));
        $this->get(route('signing.download', [$other, $document]))->assertNotFound();
        Contract::withoutEvents(fn () => $this->contract->delete());
        $this->get($url)->assertNotFound();
    }

    public function test_switch_off_hides_card_and_refuses_both_surfaces(): void
    {
        $document = $this->generate();
        config(['signing.enabled' => false]);
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('contracts.show', $this->contract))->assertDontSee('Download signed PDF')->assertDontSee('Recipient email');
        $this->get(route('signing.download', [$this->contract, $document]))->assertNotFound();
        $this->post(route('signing.generate', $this->contract))->assertNotFound();
        foreach (['send', 'void', 'countersign'] as $action) {
            $this->post(route('signing.action', [$this->contract, $document, $action]))->assertNotFound();
        }
        $this->get('/sign/'.str_repeat('A', 48))->assertOk()->assertSee('DRAFT — Link unavailable');
    }

    public function test_expiry_void_replay_and_pdf_changes_refuse_without_snapshot(): void
    {
        $service = app(SigningService::class);
        foreach (['expired', 'void', 'tampered', 'form'] as $case) {
            $document = $this->generate();
            $token = \Illuminate\Support\Str::random(48);
            $document->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
            if ($case === 'expired') {
                $document->update(['token_expires_at' => now()->subSecond()]);
            } elseif ($case === 'void') {
                $service->void($document);
                $this->assertNull($document->fresh()->token_hash);
            } elseif ($case === 'tampered') {
                Storage::disk('local')->put($document->pdf_path, 'changed');
            }
            $this->post('/sign/'.$token, ['typed_name' => 'Alex', 'pdf_sha256' => $case === 'form' ? str_repeat('f', 64) : $document->pdf_sha256])->assertSee('Link unavailable')->assertDontSee('Signature recorded');
            $this->assertCount(0, $document->snapshots);
            $this->assertNull($document->fresh()->viewed_at);
        }
        $document = $this->generate();
        $token = str_repeat('R', 48);
        $document->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        $form = ['typed_name' => 'Alex', 'pdf_sha256' => $document->pdf_sha256];
        $this->post('/sign/'.$token, $form)->assertOk()->assertSee('DRAFT — Signature recorded')->assertDontSee('Link unavailable');
        $this->post('/sign/'.$token, $form)->assertOk()->assertSee('DRAFT — Link unavailable')->assertDontSee('Signature recorded');
        $this->assertCount(1, $document->snapshots);
        $this->assertNull($document->fresh()->token_hash);
    }

    public function test_snapshot_failure_rolls_back_signature_and_logs_no_token(): void
    {
        $document = $this->generate();
        $token = str_repeat('L', 48);
        $document->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        $path = config('logging.channels.single.path');
        $this->assertSame(storage_path('logs/laravel.log'), $path);
        $offset = is_file($path) ? filesize($path) : 0;
        config(['logging.default' => 'single', 'logging.channels.single.level' => 'debug']);
        \Illuminate\Support\Facades\Log::debug('synthetic-positive-log-control');
        $records = [];
        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$records): void {
            $records[] = [$event->message, $event->context];
        });
        \App\Models\SigningSnapshot::created(fn () => throw new RuntimeException('synthetic '.$token));
        $this->post('/sign/'.$token, ['typed_name' => 'Alex', 'pdf_sha256' => $document->pdf_sha256])->assertOk()->assertDontSee($token);
        $this->assertCount(0, $document->snapshots);
        $this->assertSame(hash('sha256', $token), $document->fresh()->token_hash);
        $written = substr(file_get_contents($path), $offset);
        $this->assertStringContainsString('synthetic-positive-log-control', $written);
        $this->assertStringNotContainsString($token, $written);
        $this->assertStringNotContainsString($token, json_encode($records));
    }

    public function test_send_hash_and_mail_failure_rollback(): void
    {
        $document = $this->generate();
        $link = null;
        $mail = $this->mock(\App\Services\EmailService::class);
        $mail->shouldReceive('sendNew')->once()->andReturnUsing(function ($to, $subject, $body, $name, $cc = null, $userId = null, $redact = []) use (&$link) {
            preg_match('~https?://\\S+~', $body, $matches);
            $link = $matches[0];
            $this->assertContains($link, $redact);

            return new \App\Models\Email;
        });
        app(SigningService::class)->send($document);
        $token = basename($link);
        $this->assertSame(48, strlen($token));
        $this->assertSame(hash('sha256', $token), $document->fresh()->token_hash);
        $this->assertStringNotContainsString($token, json_encode($document->fresh()->getAttributes()));
        $this->assertSame('sent', $document->fresh()->status);
        $this->assertNotNull($document->fresh()->sent_at);
        $second = $this->generate();
        $this->assertNull($document->fresh()->token_hash);
        $mail->shouldReceive('sendNew')->once()->andThrow(new RuntimeException('synthetic mail failure'));
        try {
            app(SigningService::class)->send($second);
            $this->fail('Mail accepted');
        } catch (RuntimeException $error) {
            $this->assertSame('signing_mail_failed', $error->getMessage());
        }
        $second->refresh();
        $this->assertSame('draft', $second->status);
        $this->assertNull($second->token_hash);
        $this->assertNull($second->token_expires_at);
        $this->assertNull($second->sent_at);
    }

    public function test_finality_no_countersign_and_admin_guard(): void
    {
        $this->template->update(['requires_countersign' => false]);
        $document = $this->generate();
        $document->update(['status' => 'signed']);
        $this->assertTrue($document->isFinal());
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('contracts.show', $this->contract))->assertSee('Download signed PDF');
        $this->get(route('signing.download', [$this->contract, $document]))->assertOk();
        $this->template->update(['requires_countersign' => true]);
        $pending = $this->generate();
        $pending->update(['status' => 'signed']);
        $tech = \App\Models\User::factory()->create(['role' => 'tech']);
        try {
            app(SigningService::class)->countersign($pending, $tech, '127.0.0.1', 'Example');
            $this->fail('Non-admin accepted');
        } catch (RuntimeException $error) {
            $this->assertSame('signing_countersign_forbidden', $error->getMessage());
        }
        $this->assertCount(0, $pending->snapshots);
    }

    public function test_headers_throttle_and_snapshot_immutability(): void
    {
        foreach (['/sign/'.str_repeat('X', 48), '/sign/'.str_repeat('X', 48).'/pdf', '/sign/invalid'] as $url) {
            $response = $this->get($url);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $response->assertHeader('Referrer-Policy', 'no-referrer');
        }
        foreach (['signing.public', 'signing.pdf'] as $name) {
            $this->assertContains('throttle:30,1', app('router')->getRoutes()->getByName($name)->gatherMiddleware());
        }
        $document = $this->generate();
        $token = str_repeat('I', 48);
        $document->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        app(SigningService::class)->sign($token, 'Alex', $document->pdf_sha256, '127.0.0.1', str_repeat('x', 600));
        $snapshot = $document->snapshots()->firstOrFail();
        $this->assertSame(512, strlen($snapshot->user_agent));
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $snapshot->update(['typed_name' => 'Changed']) : $snapshot->delete();
                $this->fail('Immutable evidence changed');
            } catch (LogicException $error) {
                $this->assertSame('signing_snapshot_immutable', $error->getMessage());
            }
        }
    }

    public function test_renderer_strips_active_content_and_makes_no_outbound_socket_connection(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        $marker = storage_path('framework/signing-execution-marker');
        @unlink($marker);
        $html = '<img src="http://'.$address.'/image"><script>fetch("http://'.$address.'/script")</script><?php file_put_contents("'.$marker.'", "executed"); ?><p>DRAFT — Safe content</p>';
        try {
            $purified = app(TemplateMerge::class)->purify($html);
            $bytes = (new PdfRenderer)->render($purified);
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertFileDoesNotExist($marker);
            stream_set_blocking($socket, false);
            $this->assertFalse(@stream_socket_accept($socket, 0));
            $client = stream_socket_client('tcp://'.$address);
            $this->assertIsResource($client);
            $accepted = stream_socket_accept($socket, 1);
            $this->assertIsResource($accepted, 'Socket detector positive control');
            fclose($accepted);
            fclose($client);
        } finally {
            fclose($socket);
            @unlink($marker);
        }
    }

    public function test_generate_does_not_change_billing_tables(): void
    {
        $db = \Illuminate\Support\Facades\DB::class;
        $db::table('prepay_transactions')->insert(['contract_id' => $this->contract->id, 'halo_id' => 123, 'hours' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $db::table('invoices')->insert(['contract_id' => $this->contract->id, 'client_id' => $this->contract->client_id, 'invoice_number' => 'EXAMPLE-1', 'invoice_date' => '2026-01-01', 'due_date' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()]);
        $db::table('recurring_invoice_profiles')->insert(['contract_id' => $this->contract->id, 'name' => 'Example profile', 'next_run_date' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['prepay_transactions', 'invoices', 'recurring_invoice_profiles'] as $seeded) {
            $this->assertGreaterThan(0, $db::table($seeded)->count());
        }
        $tables = ['invoices', 'invoice_lines', 'prepay_transactions', 'recurring_invoice_profiles', 'recurring_invoice_profile_lines'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = \Illuminate\Support\Facades\DB::table($table)->get()->toJson();
        }
        $token = null;
        $this->mock(\App\Services\EmailService::class)->shouldReceive('sendNew')->once()->andReturnUsing(function ($to, $subject, $body) use (&$token) {
            preg_match('~/sign/([A-Za-z0-9]{48})~', $body, $matches);
            $token = $matches[1];

            return new \App\Models\Email;
        });
        $document = $this->generate();
        $service = app(SigningService::class);
        $service->send($document);
        $service->sign($token, 'Alex Example', $document->pdf_sha256, '127.0.0.1', 'Example');
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $service->countersign($document, $admin, '127.0.0.1', 'Example');
        $service->void($this->generate());
        foreach ($tables as $table) {
            $this->assertSame($before[$table], \Illuminate\Support\Facades\DB::table($table)->get()->toJson(), $table);
        }
    }

    public function test_unknown_merge_token_refuses_before_file_or_insert(): void
    {
        $this->template->update(['body' => '<p>{{unsupported}}</p>']);
        try {
            $this->generate();
            $this->fail('Unknown token accepted');
        } catch (RuntimeException $error) {
            $this->assertSame('signing_unknown_merge_token', $error->getMessage());
        }
        $this->assertDatabaseCount('signable_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('signing'));
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

    public function test_sku_volume_priced_backup_line_refuses_until_the_volume_card_is_gone(): void
    {
        $sku = \App\Models\Sku::create([
            'name' => 'Example backup storage', 'sku_code' => 'EXAMPLE-BKP', 'unit_price' => '0', 'unit_cost' => '0',
            'default_quantity_type' => \App\Enums\QuantityType::PerBackupStorageGb, 'is_taxable' => false, 'is_active' => true,
        ]);
        $tier = \App\Models\BackupStorageTier::create(['sku_id' => $sku->id, 'up_to_gb' => 100, 'unit_price' => '1.00', 'sort_order' => 0]);
        $this->contract->lines()->create([
            'version' => 1, 'source' => 'example', 'sku_id' => $sku->id, 'description' => 'Example backup storage',
            'quantity_type' => 'per_backup_storage_gb', 'unit_price' => '0.00', 'is_taxable' => false, 'sort_order' => 1,
        ]);
        try {
            $this->generate();
            $this->fail('SKU volume pricing accepted');
        } catch (RuntimeException $error) {
            $this->assertSame('signing_line_price_inherited', $error->getMessage());
        }
        $this->assertDatabaseCount('signable_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('signing'));
        $tier->delete();
        $this->assertSame('draft', $this->generate()->status);
    }

    public function test_void_refuses_final_and_void_rows_and_card_offers_it_only_on_pending_rows(): void
    {
        $service = app(SigningService::class);
        $executed = $this->generate();
        $executed->update(['status' => 'countersigned']);
        $this->template->update(['requires_countersign' => false]);
        $final = $this->generate();
        $final->update(['status' => 'signed']);
        $voided = $this->generate();
        $voided->update(['status' => 'void', 'voided_at' => now()->subDay()]);
        $this->template->update(['requires_countersign' => true]);
        $pending = $this->generate();
        $pending->update(['status' => 'signed']);
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $page = $this->actingAs($admin)->get(route('contracts.show', $this->contract))->assertOk();
        $page->assertSee(route('signing.action', [$this->contract, $pending, 'void']), false);
        foreach ([$executed, $final, $voided] as $row) {
            $page->assertDontSee(route('signing.action', [$this->contract, $row, 'void']), false);
            $before = $row->fresh()->getRawOriginal();
            try {
                $service->void($row);
                $this->fail('Void accepted: '.$row->fresh()->status);
            } catch (RuntimeException $error) {
                $this->assertSame('signing_void_unavailable', $error->getMessage());
            }
            $this->assertSame($before, $row->fresh()->getRawOriginal());
        }
        $this->post(route('signing.action', [$this->contract, $executed, 'void']))
            ->assertRedirect(route('contracts.show', $this->contract))
            ->assertSessionHas('signing_error', fn ($message) => str_contains($message, 'signing_void_unavailable'));
        $this->assertSame('countersigned', $executed->fresh()->status);
        $this->get(route('signing.download', [$this->contract, $executed]))->assertOk();
        $service->void($pending);
        $this->assertSame('void', $pending->fresh()->status);
    }

    public function test_staff_refusals_redirect_with_their_named_error_instead_of_a_server_error(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $form = ['template_id' => $this->template->id, 'name' => 'Alex Example', 'email' => 'alex@example.test'];
        $this->template->update(['signing_steps' => 2]);
        $this->actingAs($admin)->post(route('signing.generate', $this->contract), $form)
            ->assertRedirect(route('contracts.show', $this->contract))
            ->assertSessionHas('signing_error', fn ($message) => str_contains($message, 'signing_multistep_unsupported'));
        $this->assertDatabaseCount('signable_documents', 0);
        $this->template->update(['signing_steps' => 1]);
        $document = $this->generate();
        $document->update(['status' => 'signed']);
        $this->post(route('signing.action', [$this->contract, $document, 'send']))
            ->assertRedirect(route('contracts.show', $this->contract))
            ->assertSessionHas('signing_error', fn ($message) => str_contains($message, 'signing_send_unavailable'));
        $this->assertNull($document->fresh()->token_hash);
        $this->post(route('signing.generate', $this->contract), ['template_id' => 999999] + $form)->assertNotFound();
        $this->template->update(['body' => '<p>{{ client.name }}</p>']);
        $this->followingRedirects()->post(route('signing.generate', $this->contract), $form)
            ->assertOk()->assertSee('(signing_unknown_merge_token)');
        $this->assertDatabaseCount('signable_documents', 1);
    }

    public function test_today_merge_field_uses_the_app_timezone_date(): void
    {
        \App\Models\Setting::updateOrCreate(['key' => 'app_timezone'], ['value' => 'America/Los_Angeles']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-23 01:00:00', 'UTC'));
        $this->template->update(['body' => '<p>DRAFT — Dated {{today}}</p>{{lines_table}}']);
        $bytes = Storage::disk('local')->get($this->generate()->pdf_path);
        $this->assertStringContainsString('DRAFT — Dated 2026-10-22', $bytes);
        $this->assertStringNotContainsString('2026-10-23', $bytes);
    }
}
