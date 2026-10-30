<?php

namespace Tests\Feature\Contracts;

use App\Models\AgreementTemplate;
use App\Models\Client;
use App\Models\Contract;
use App\Models\SignableDocument;
use App\Services\Signing\PdfRenderer;
use App\Services\Signing\SigningAcceptanceStale;
use App\Services\Signing\SigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SigningProposalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function scenario(): array
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
        $proposalTemplate = AgreementTemplate::create([
            'name' => 'Example proposal', 'kind' => 'proposal', 'contract_type' => 'breakfix',
            'body' => '<p>DRAFT — Proposal</p>{{lines_table}}', 'is_active' => true,
            'requires_countersign' => false, 'signing_steps' => 1,
        ]);
        $agreementTemplate = AgreementTemplate::create([
            'name' => 'Example agreement', 'contract_type' => 'breakfix',
            'body' => '<p>DRAFT — Agreement</p>{{lines_table}}', 'is_active' => true,
            'requires_countersign' => true, 'signing_steps' => 2,
        ]);

        return [$contract, $proposalTemplate, $agreementTemplate];
    }

    private function generate(Contract $contract, AgreementTemplate $template): SignableDocument
    {
        return app(SigningService::class)->generate($contract, $template, ['name' => 'Alex Example', 'email' => 'alex@example.test']);
    }

    private function acceptProposal(SignableDocument $proposal): void
    {
        $token = str_repeat('A', 48);
        $proposal->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        app(SigningService::class)->accept($token, 'Alex Example', $proposal->pdf_sha256, '127.0.0.1', str_repeat('x', 600));
    }

    public function test_acceptance_and_snapshot_generate_preserve_evidence_across_identical_new_versions(): void
    {
        [$contract, $proposalTemplate, $agreementTemplate] = $this->scenario();
        $proposal = $this->generate($contract, $proposalTemplate);
        $this->assertSame('proposal', $proposal->kind);
        $this->assertSame(1, $proposal->version);
        $this->assertTrue(Storage::disk('local')->exists($proposal->pdf_path));
        $this->acceptProposal($proposal);
        $snapshot = $proposal->snapshots()->sole();
        $this->assertSame('acceptance', $snapshot->role);
        $this->assertSame($proposal->rendered_lines, $snapshot->lines);
        $this->assertSame($proposal->rendered_lines_sha256, $snapshot->lines_sha256);
        $this->assertSame($proposal->pdf_sha256, $snapshot->pdf_sha256);
        $this->assertSame(512, strlen($snapshot->user_agent));
        $this->assertNull($proposal->fresh()->token_hash);
        $this->assertTrue($proposal->fresh()->isFinal());
        $this->assertFalse($proposal->fresh()->isVoidable());
        $line = $contract->lines()->firstOrFail();
        $copy = $line->replicate();
        $copy->version = 2;
        $copy->save();
        $agreement = $this->generate($contract, $agreementTemplate);
        $this->assertSame($snapshot->lines, $agreement->rendered_lines);
        $this->assertSame($snapshot->lines_sha256, $agreement->rendered_lines_sha256);
        $this->assertSame($snapshot->id, $agreement->accepted_snapshot_id);
        $this->assertSame(1, $agreement->lines_version);
        $copy->update(['unit_price' => '999.00']);
        $this->assertSame($snapshot->lines, $agreement->fresh()->rendered_lines);
        foreach (['kind' => 'invalid', 'accepted_snapshot_id' => null] as $field => $value) {
            try {
                $agreement->fresh()->update([$field => $value]);
                $this->fail('Evidence changed');
            } catch (\LogicException $error) {
                $this->assertSame('signing_document_evidence_immutable', $error->getMessage());
            }
        }
        $this->assertSame('agreement', $agreement->fresh()->kind);
        $this->assertSame($snapshot->id, $agreement->fresh()->accepted_snapshot_id);
    }

    public function test_newest_unaccepted_proposal_blocks_an_older_acceptance(): void
    {
        [$contract, $proposalTemplate, $agreementTemplate] = $this->scenario();
        foreach ([false, true] as $olderAccepted) {
            if ($olderAccepted) {
                $proposal = $this->generate($contract, $proposalTemplate);
                $this->acceptProposal($proposal);
                $this->generate($contract, $proposalTemplate);
            }
            $before = SignableDocument::all()->toJson();
            $files = Storage::disk('local')->allFiles();
            try {
                $this->generate($contract, $agreementTemplate);
                $this->fail('Missing newest acceptance accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('signing_acceptance_missing', $error->getMessage());
            }
            $this->assertSame($before, SignableDocument::all()->toJson());
            $this->assertSame($files, Storage::disk('local')->allFiles());
        }
    }

    public function test_snapshot_integrity_refuses_tampering_without_document_or_file(): void
    {
        [$contract, $proposalTemplate, $agreementTemplate] = $this->scenario();
        $proposal = $this->generate($contract, $proposalTemplate);
        $this->acceptProposal($proposal);
        \Illuminate\Support\Facades\DB::table('signing_snapshots')->where('signable_document_id', $proposal->id)->update(['lines' => '[]']);
        $records = [];
        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$records): void {
            $records[] = [$event->level, $event->message, $event->context];
        });
        $files = Storage::disk('local')->allFiles();
        try {
            $this->generate($contract, $agreementTemplate);
            $this->fail('Tampered acceptance used');
        } catch (\RuntimeException $error) {
            $this->assertSame('signing_acceptance_integrity', $error->getMessage());
        }
        $this->assertContains(['warning', 'signing_acceptance_integrity', ['exception_class' => \RuntimeException::class]], $records);
        $this->assertStringNotContainsString('alex@example.test', json_encode($records));
        $this->assertDatabaseCount('signable_documents', 1);
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_missing_acceptance_snapshot_refuses_with_integrity_copy_and_no_writes(): void
    {
        [$contract, $proposalTemplate, $agreementTemplate] = $this->scenario();
        $proposal = $this->generate($contract, $proposalTemplate);
        $this->acceptProposal($proposal);
        [$otherContract, $otherProposalTemplate, $otherAgreementTemplate] = $this->scenario();
        $otherProposal = $this->generate($otherContract, $otherProposalTemplate);
        $this->acceptProposal($otherProposal);
        $otherAgreement = $this->generate($otherContract, $otherAgreementTemplate);
        $liveToken = str_repeat('W', 48);
        $otherAgreement->update(['status' => 'sent', 'token_hash' => hash('sha256', $liveToken), 'token_expires_at' => now()->addDay()]);
        \Illuminate\Support\Facades\DB::table('signing_snapshots')->where('signable_document_id', $proposal->id)->where('role', 'acceptance')->delete();
        $this->assertDatabaseCount('signing_snapshots', 1);
        $this->assertNotNull($otherAgreement->fresh()->token_hash);
        $this->assertSame('accepted', $proposal->fresh()->status);
        $records = [];
        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$records): void {
            $records[] = [$event->level, $event->message, $event->context];
        });
        $documents = SignableDocument::all()->toJson();
        $tokens = \Illuminate\Support\Facades\DB::table('signable_documents')->orderBy('id')->get(['id', 'token_hash', 'token_expires_at'])->toJson();
        $proposalToken = \Illuminate\Support\Facades\DB::table('signable_documents')->where('id', $proposal->id)->value('token_hash');
        $snapshots = \Illuminate\Support\Facades\DB::table('signing_snapshots')->orderBy('id')->get()->toJson();
        $files = Storage::disk('local')->allFiles();
        $response = $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']))
            ->post(route('signing.generate', $contract), ['template_id' => $agreementTemplate->id, 'recipient_name' => 'Alex Example', 'recipient_email' => 'alex@example.test']);
        $response->assertStatus(409);
        $this->assertSame("DRAFT — The accepted proposal's stored lines are missing or do not match their recorded hash. (signing_acceptance_integrity)", $response->getContent());
        $this->assertSame($proposalToken, \Illuminate\Support\Facades\DB::table('signable_documents')->where('id', $proposal->id)->value('token_hash'));
        $this->assertSame($tokens, \Illuminate\Support\Facades\DB::table('signable_documents')->orderBy('id')->get(['id', 'token_hash', 'token_expires_at'])->toJson());
        $this->assertSame($snapshots, \Illuminate\Support\Facades\DB::table('signing_snapshots')->orderBy('id')->get()->toJson());
        $this->assertContains(['warning', 'signing_acceptance_integrity', ['exception_class' => \RuntimeException::class]], $records);
        $this->assertSame($documents, SignableDocument::all()->toJson());
        $this->assertDatabaseCount('signable_documents', 3);
        $this->assertSame(0, SignableDocument::where('contract_id', $contract->id)->where('kind', 'agreement')->count());
        $this->assertSame($otherAgreement->id, app(SigningService::class)->view($liveToken)->id);
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_service_kind_cross_checks_refuse_without_spending_token_or_snapshot(): void
    {
        [$contract, $proposalTemplate, $agreementTemplate] = $this->scenario();
        $agreementTemplate->update(['signing_steps' => 1]);
        foreach ([[$proposalTemplate, 'sign'], [$agreementTemplate, 'accept']] as [$template, $method]) {
            $document = $this->generate($contract, $template);
            $token = str_repeat('B', 48);
            $document->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
            try {
                app(SigningService::class)->{$method}($token, 'Alex', $document->pdf_sha256, '127.0.0.1', 'Example agent');
                $this->fail('Wrong kind accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('signing_kind_mismatch', $error->getMessage());
            }
            $this->assertCount(0, $document->snapshots);
            $this->assertSame('sent', $document->fresh()->status);
            $this->assertSame(hash('sha256', $token), $document->fresh()->token_hash);
            $document->update(['token_hash' => null]);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contentChanges')]
    public function test_each_content_change_refuses_with_named_http_diff(string $field, mixed $value): void
    {
        [$contract, $proposalTemplate, $agreementTemplate] = $this->scenario();
        $proposal = $this->generate($contract, $proposalTemplate);
        $this->acceptProposal($proposal);
        if ($field === 'sku_id') {
            $value = \App\Models\Sku::create(['name' => 'Example sku', 'sku_code' => 'EXAMPLE-DIFF', 'unit_price' => '1.00', 'unit_cost' => '0', 'default_quantity_type' => 'fixed', 'is_taxable' => false, 'is_active' => true])->id;
        }
        $contract->lines()->firstOrFail()->update([$field => $value]);
        $files = Storage::disk('local')->allFiles();
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']))
            ->post(route('signing.generate', $contract), ['template_id' => $agreementTemplate->id, 'recipient_name' => 'Alex Example', 'recipient_email' => 'alex@example.test'])
            ->assertStatus(409)->assertViewIs('contracts.signing-diff')->assertSee('<td>'.$field.'</td>', false);
        $this->assertDatabaseCount('signable_documents', 1);
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public static function contentChanges(): array
    {
        return [
            ['sku_id', null], ['unit_price', '126.00'], ['pricing_tiers', [['up_to' => null, 'unit_price' => '100.00']]],
            ['fixed_quantity', '3.00'], ['quantity_type', 'per_user'], ['description', 'Changed example'],
            ['is_taxable', true], ['option_group', 'Example options'], ['term', 'Monthly'],
        ];
    }

    public function test_added_and_reordered_lines_refuse_without_writes(): void
    {
        [$contract, $proposalTemplate, $agreementTemplate] = $this->scenario();
        $first = $contract->lines()->firstOrFail();
        $second = $first->replicate();
        $second->description = 'Second example';
        $second->sort_order = 1;
        $second->save();
        $proposal = $this->generate($contract, $proposalTemplate);
        $this->acceptProposal($proposal);
        foreach (['added', 'reordered'] as $change) {
            if ($change === 'added') {
                $third = $second->replicate();
                $third->sort_order = 2;
                $third->save();
            } else {
                $third->delete();
                $first->update(['sort_order' => 3]);
            }
            $files = Storage::disk('local')->allFiles();
            try {
                $this->generate($contract, $agreementTemplate);
                $this->fail('Changed lines accepted');
            } catch (SigningAcceptanceStale $error) {
                $this->assertSame('signing_acceptance_stale', $error->getMessage());
                $this->assertSame($change === 'added' ? 'added' : 'description', $error->rows[0]['field']);
            }
            $this->assertDatabaseCount('signable_documents', 1);
            $this->assertSame($files, Storage::disk('local')->allFiles());
        }
    }

    public function test_public_proposal_acceptance_spends_link_and_has_no_session_or_pdf_on_confirmation(): void
    {
        [$contract, $proposalTemplate] = $this->scenario();
        $proposal = $this->generate($contract, $proposalTemplate);
        $token = str_repeat('C', 48);
        $proposal->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        $page = $this->get('/sign/'.$token)->assertOk()->assertSee('Accept selections and pricing')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $pdf = $this->get('/sign/'.$token.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $page->assertSee(hash('sha256', $pdf->getContent()));
        $this->post('/sign/'.$token, ['typed_name' => 'Alex Example', 'pdf_sha256' => $proposal->pdf_sha256])
            ->assertOk()->assertSee('Selections accepted')->assertDontSee($token)->assertDontSee('<object', false)->assertDontSee('href=', false)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertCount(1, $proposal->snapshots);
        $this->assertNull($proposal->fresh()->token_hash);
        $this->post('/sign/'.$token, ['typed_name' => 'Alex Example', 'pdf_sha256' => $proposal->pdf_sha256])->assertSee('Link unavailable');
        $this->assertCount(1, $proposal->fresh()->snapshots);
        foreach (['signing.public', 'signing.pdf'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertContains('throttle:30,1', $route->gatherMiddleware());
            $middleware = app('router')->gatherRouteMiddleware($route);
            foreach ([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class] as $excluded) {
                $this->assertNotContains($excluded, $middleware);
            }
        }
    }

    public function test_acceptance_refusals_do_not_write_snapshots(): void
    {
        [$contract, $proposalTemplate] = $this->scenario();
        foreach (['expired', 'void', 'hash', 'blank'] as $case) {
            $document = $this->generate($contract, $proposalTemplate);
            $token = str_repeat('D', 48);
            $document->update(['status' => $case === 'void' ? 'void' : 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => $case === 'expired' ? now()->subDay() : now()->addDay()]);
            $before = $document->fresh()->getRawOriginal();
            $this->post('/sign/'.$token, ['typed_name' => $case === 'blank' ? ' ' : 'Alex', 'pdf_sha256' => $case === 'hash' ? str_repeat('f', 64) : $document->pdf_sha256])->assertSee('Link unavailable');
            $this->assertCount(0, $document->snapshots);
            $this->assertSame($before, $document->fresh()->getRawOriginal());
            $document->update(['token_hash' => null]);
        }
    }

    public function test_unsupported_step_values_refuse_at_model_and_generate(): void
    {
        [$contract, , $template] = $this->scenario();
        foreach ([0, 3] as $steps) {
            try {
                $template->fresh()->update(['signing_steps' => $steps]);
                $this->fail('Invalid model steps accepted');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('signing_template_steps_invalid', $error->getMessage());
            }
            \Illuminate\Support\Facades\DB::table('agreement_templates')->where('id', $template->id)->update(['signing_steps' => $steps]);
            try {
                $this->generate($contract, $template);
                $this->fail('Invalid stored steps accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('signing_multistep_unsupported', $error->getMessage());
            }
            $this->assertDatabaseCount('signable_documents', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('volumeChanges')]
    public function test_b10_rechecks_sku_volume_card_on_snapshot_path(string $change): void
    {
        [$contract, $proposalTemplate, $agreementTemplate] = $this->scenario();
        $sku = \App\Models\Sku::create(['name' => 'Example backup', 'sku_code' => 'EXAMPLE-BACKUP', 'unit_price' => '1.00', 'unit_cost' => '0', 'default_quantity_type' => 'per_backup_storage_gb', 'is_taxable' => false, 'is_active' => true]);
        $contract->lines()->firstOrFail()->update(['sku_id' => $sku->id, 'quantity_type' => 'per_backup_storage_gb']);
        if ($change !== 'added') {
            $sku->backupStorageTiers()->create(['up_to_gb' => 500, 'unit_price' => '0.18']);
            $sku->backupStorageTiers()->create(['up_to_gb' => 2000, 'unit_price' => '0.14']);
        }
        $proposal = $this->generate($contract, $proposalTemplate)->fresh();
        $this->acceptProposal($proposal);
        $snapshot = $proposal->snapshots()->sole();
        $this->assertSame($proposal->rendered_lines_sha256, $snapshot->lines_sha256);
        $agreement = $this->generate($contract, $agreementTemplate)->fresh();
        $this->assertSame(hash('sha256', $agreement->getRawOriginal('rendered_lines')), $agreement->rendered_lines_sha256);
        $this->assertSame($snapshot->lines, $agreement->rendered_lines);
        $liveToken = str_repeat('V', 48);
        $agreement->update(['status' => 'sent', 'token_hash' => hash('sha256', $liveToken), 'token_expires_at' => now()->addDay()]);
        $this->assertSame($agreement->id, app(SigningService::class)->view($liveToken)->id);
        $this->assertDatabaseCount('signing_snapshots', 1);
        $this->assertNotNull($agreement->fresh()->token_hash);
        match ($change) {
            'added' => $sku->backupStorageTiers()->create(['up_to_gb' => 500, 'unit_price' => '0.18']),
            'price' => $sku->backupStorageTiers()->where('up_to_gb', 500)->update(['unit_price' => '0.19']),
            'bound' => $sku->backupStorageTiers()->where('up_to_gb', 500)->update(['up_to_gb' => 501]),
            'removed' => $sku->backupStorageTiers()->where('up_to_gb', 500)->delete(),
        };
        $documents = SignableDocument::all()->toJson();
        $snapshots = \Illuminate\Support\Facades\DB::table('signing_snapshots')->get()->toJson();
        $files = Storage::disk('local')->allFiles();
        $response = $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']))
            ->post(route('signing.generate', $contract), ['template_id' => $agreementTemplate->id, 'recipient_name' => 'Alex Example', 'recipient_email' => 'alex@example.test']);
        $this->assertSame(hash('sha256', $liveToken), $agreement->fresh()->token_hash);
        $response->assertStatus(409)->assertViewIs('contracts.signing-diff')->assertSee('Product volume rate card')->assertDontSee('sku_volume_tiers');
        $this->assertSame($documents, SignableDocument::all()->toJson());
        $this->assertSame($snapshots, \Illuminate\Support\Facades\DB::table('signing_snapshots')->get()->toJson());
        $this->assertSame($agreement->id, app(SigningService::class)->view($liveToken)->id);
        $this->assertDatabaseCount('signable_documents', 2);
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('proposalHashes')]
    public function test_proposal_hash_anchor(?string $hash, bool $passes): void
    {
        [$contract, $proposalTemplate, $agreementTemplate] = $this->scenario();
        $proposal = $this->generate($contract, $proposalTemplate);
        $this->acceptProposal($proposal);
        if (! $passes && $hash === null) {
            // The current schema is NOT NULL. Model a legacy null at hydration,
            // without changing the schema or bypassing the generate query.
            \Illuminate\Support\Facades\Event::listen('eloquent.retrieved: '.SignableDocument::class, function (SignableDocument $document) use ($proposal): void {
                if ($document->id === $proposal->id) {
                    $document->rendered_lines_sha256 = null;
                }
            });
        } elseif (! $passes) {
            \Illuminate\Support\Facades\DB::table('signable_documents')->where('id', $proposal->id)->update(['rendered_lines_sha256' => $hash]);
        }
        $records = [];
        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$records): void {
            $records[] = [$event->level, $event->message, $event->context];
        });
        $files = Storage::disk('local')->allFiles();
        if ($passes) {
            $agreement = $this->generate($contract, $agreementTemplate);
            $this->assertSame($proposal->rendered_lines_sha256, $agreement->rendered_lines_sha256);
            $this->assertDatabaseCount('signable_documents', 2);
            $this->assertSame([], $records);
        } else {
            try {
                $this->generate($contract, $agreementTemplate);
                $this->fail('Unanchored acceptance generated');
            } catch (\RuntimeException $error) {
                $this->assertSame('signing_acceptance_integrity', $error->getMessage());
            }
            $this->assertSame([['warning', 'signing_acceptance_integrity', ['exception_class' => \RuntimeException::class]]], $records);
            $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/i', json_encode($records));
            $this->assertDatabaseCount('signable_documents', 1);
            $this->assertSame($files, Storage::disk('local')->allFiles());
        }
    }

    public static function proposalHashes(): array
    {
        return ['matching' => [null, true], 'tampered' => [str_repeat('f', 64), false], 'null' => [null, false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('diffCards')]
    public function test_volume_diff_exact_cells_and_removed_card(bool $removed): void
    {
        [$contract, $proposalTemplate, $agreementTemplate] = $this->scenario();
        $sku = \App\Models\Sku::create(['name' => 'Example storage', 'sku_code' => 'EXAMPLE-CELLS', 'unit_price' => '0', 'unit_cost' => '0', 'default_quantity_type' => 'per_backup_storage_gb']);
        $contract->lines()->firstOrFail()->update(['sku_id' => $sku->id, 'quantity_type' => 'per_backup_storage_gb']);
        foreach ([[500, '0.18'], [2000, '0.14'], [null, '0.10']] as [$bound, $price]) {
            $sku->backupStorageTiers()->create(['up_to_gb' => $bound, 'unit_price' => $price]);
        }
        $proposal = $this->generate($contract, $proposalTemplate);
        $this->acceptProposal($proposal);
        if ($removed) {
            $sku->backupStorageTiers()->delete();
            $this->assertSame(0, $sku->backupStorageTiers()->count());
        } else {
            foreach ([[500, '0.20'], [2000, '0.16'], [null, '0.12']] as [$bound, $price]) {
                $sku->backupStorageTiers()->where('up_to_gb', $bound)->update(['unit_price' => $price]);
            }
        }
        $response = $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']))
            ->post(route('signing.generate', $contract), ['template_id' => $agreementTemplate->id, 'recipient_name' => 'Alex Example', 'recipient_email' => 'alex@example.test']);
        $response->assertStatus(409)->assertViewIs('contracts.signing-diff')->assertDontSee('sku_volume_tiers');
        $rows = $response->viewData('diff')->rows;
        $this->assertCount(1, $rows);
        $this->assertSame('sku_volume_tiers', $rows[0]['field']);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $cells = (new \DOMXPath($dom))->query('//table/tbody/tr/td');
        $this->assertSame('Product volume rate card', $cells->item(1)->textContent);
        $accepted = 'up to 500 GB: 0.18; up to 2000 GB: 0.14; above that: 0.10';
        $this->assertSame($accepted, $cells->item(2)->textContent);
        $this->assertSame($removed ? '' : 'up to 500 GB: 0.20; up to 2000 GB: 0.16; above that: 0.12', $cells->item(3)->textContent);
        if ($removed) {
            $this->assertNull($rows[0]['current']);
        }
        $this->assertSame(\App\Services\Signing\TemplateMerge::volumeBands($rows[0]['accepted']), $cells->item(2)->textContent);
    }

    public static function diffCards(): array
    {
        return ['changed' => [false], 'all removed' => [true]];
    }

    public static function volumeChanges(): array
    {
        return [['added'], ['price'], ['bound'], ['removed']];
    }

    public function test_full_proposal_and_agreement_lifecycle_never_writes_billing_or_contract_lines(): void
    {
        [$contract, $proposalTemplate, $agreementTemplate] = $this->scenario();
        $db = \Illuminate\Support\Facades\DB::class;
        $db::table('prepay_transactions')->insert(['contract_id' => $contract->id, 'halo_id' => 123, 'hours' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $db::table('invoices')->insert(['contract_id' => $contract->id, 'client_id' => $contract->client_id, 'invoice_number' => 'EXAMPLE-1', 'invoice_date' => '2026-01-01', 'due_date' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()]);
        $db::table('recurring_invoice_profiles')->insert(['contract_id' => $contract->id, 'name' => 'Example profile', 'next_run_date' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['prepay_transactions', 'invoices', 'recurring_invoice_profiles'] as $table) {
            $this->assertGreaterThan(0, $db::table($table)->count());
        }
        $before = [];
        foreach (['invoices', 'invoice_lines', 'prepay_transactions', 'recurring_invoice_profiles', 'recurring_invoice_profile_lines', 'contracts', 'contract_lines'] as $table) {
            $before[$table] = $db::table($table)->get()->toJson();
        }
        \App\Models\Setting::setValue('portal_company_name', 'Northwind MSP');
        $token = null;
        $subjects = [];
        $this->mock(\App\Services\EmailService::class)->shouldReceive('sendNew')->twice()->andReturnUsing(function ($to, $subject, $body, $name, $cc = null, $userId = null, $redact = []) use (&$token, &$subjects) {
            preg_match('~/sign/([A-Za-z0-9]{48})~', $body, $matches);
            $token = $matches[1];
            $this->assertSame(count($subjects) === 0 ? 'Proposal from Northwind MSP ready for review' : 'Agreement from Northwind MSP ready for review', $subject);
            $subjects[] = $subject;
            $this->assertNotEmpty($redact);

            return new \App\Models\Email;
        });
        $service = app(SigningService::class);
        $proposal = $this->generate($contract, $proposalTemplate);
        $service->send($proposal);
        $service->accept($token, 'Alex Example', $proposal->pdf_sha256, '127.0.0.1', 'Example');
        $agreement = $this->generate($contract, $agreementTemplate);
        $service->send($agreement);
        $service->sign($token, 'Alex Example', $agreement->pdf_sha256, '127.0.0.1', 'Example');
        $this->assertSame(['Proposal from Northwind MSP ready for review', 'Agreement from Northwind MSP ready for review'], $subjects);
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $service->countersign($agreement, $admin, '127.0.0.1', 'Example');
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $db::table($table)->get()->toJson(), $table);
        }
        $this->assertTrue($agreement->fresh()->isFinal());
        $this->actingAs($admin)->get(route('contracts.show', $contract))->assertSee('Download accepted proposal')->assertSee('From proposal v1')->assertSee('Proposal · Example proposal')->assertSee('Agreement · Example agreement (two-step)');
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'tech']))->get(route('contracts.show', $contract))->assertDontSee('Download accepted proposal');
    }

    public function test_removing_all_live_lines_refuses_with_acceptance_diff_without_writes(): void
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
        $proposalTemplate = AgreementTemplate::create([
            'name' => 'Example proposal', 'kind' => 'proposal', 'contract_type' => 'breakfix',
            'body' => '<p>DRAFT — Proposal</p>{{lines_table}}', 'is_active' => true,
            'requires_countersign' => false, 'signing_steps' => 1,
        ]);
        $agreementTemplate = AgreementTemplate::create([
            'name' => 'Example agreement', 'contract_type' => 'breakfix',
            'body' => '<p>DRAFT — Agreement</p>{{lines_table}}', 'is_active' => true,
            'requires_countersign' => true, 'signing_steps' => 2,
        ]);
        $recipient = ['name' => 'Alex Example', 'email' => 'alex@example.test'];
        $service = app(SigningService::class);
        $proposal = $service->generate($contract, $proposalTemplate, $recipient);
        $token = str_repeat('A', 48);
        $proposal->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        $service->accept($token, 'Alex Example', $proposal->pdf_sha256, '127.0.0.1', 'Example agent');
        $snapshot = $proposal->snapshots()->where('role', 'acceptance')->firstOrFail();
        $this->assertSame('accepted', $proposal->fresh()->status);
        $agreement = $service->generate($contract, $agreementTemplate, $recipient);
        $this->assertSame($snapshot->lines, $agreement->rendered_lines);
        $this->assertSame($snapshot->id, $agreement->accepted_snapshot_id);
        $before = SignableDocument::all()->toJson();
        $files = Storage::disk('local')->allFiles('signing');
        $contract->lines()->delete();
        try {
            $service->generate($contract, $agreementTemplate, $recipient);
            $this->fail('Removed accepted lines generated');
        } catch (SigningAcceptanceStale $error) {
            $this->assertSame('signing_acceptance_stale', $error->getMessage());
            $this->assertCount(1, $error->rows);
            $this->assertSame('removed', $error->rows[0]['field']);
            $this->assertSame('Example support', $error->rows[0]['accepted']['description']);
            $this->assertNull($error->rows[0]['current']);
        }
        $this->assertSame($before, SignableDocument::all()->toJson());
        $this->assertSame($files, Storage::disk('local')->allFiles('signing'));
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']))
            ->post(route('signing.generate', $contract), ['template_id' => $agreementTemplate->id, 'recipient_name' => $recipient['name'], 'recipient_email' => $recipient['email']])
            ->assertStatus(409)->assertViewIs('contracts.signing-diff')->assertSee('removed')->assertSee('Generate new proposal');
        $this->assertSame($before, SignableDocument::all()->toJson());
    }
}
