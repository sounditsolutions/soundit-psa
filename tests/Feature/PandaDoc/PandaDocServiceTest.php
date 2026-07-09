<?php

namespace Tests\Feature\PandaDoc;

use App\Enums\PandaDocStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PandaDocDocument;
use App\Services\PandaDoc\PandaDocClient;
use App\Services\PandaDoc\PandaDocService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PandaDocServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(Response ...$responses): PandaDocService
    {
        $http = new GuzzleClient([
            'base_uri' => 'https://api.pandadoc.com',
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]);

        return new PandaDocService(new PandaDocClient($http));
    }

    private function contract(): Contract
    {
        $client = Client::create(['name' => 'Acme Corp']);

        return Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
        ]);
    }

    public function test_create_from_template_persists_a_tracking_record(): void
    {
        $service = $this->service(new Response(201, [], json_encode([
            'id' => 'DOC1',
            'name' => 'Managed Services — Acme Corp',
            'status' => 'document.draft',
        ])));

        $contract = $this->contract();

        $document = $service->createFromTemplate(
            $contract,
            'TPL-1',
            'Master Services Agreement',
            'jane@acme.test',
            'Jane Doe',
            null,
            null,
        );

        $this->assertDatabaseHas('pandadoc_documents', [
            'id' => $document->id,
            'contract_id' => $contract->id,
            'pandadoc_id' => 'DOC1',
            'recipient_email' => 'jane@acme.test',
        ]);
        $this->assertSame(PandaDocStatus::Draft, $document->refresh()->status);
    }

    public function test_send_marks_document_sent_and_stamps_sent_at(): void
    {
        $service = $this->service(new Response(200, [], json_encode(['status' => 'document.sent'])));

        $document = PandaDocDocument::create([
            'contract_id' => $this->contract()->id,
            'pandadoc_id' => 'DOC2',
            'name' => 'MSA',
            'status' => PandaDocStatus::Draft,
        ]);

        $service->send($document);

        $document->refresh();
        $this->assertSame(PandaDocStatus::Sent, $document->status);
        $this->assertNotNull($document->sent_at);
    }

    public function test_sync_status_downloads_signed_pdf_when_completed(): void
    {
        Storage::fake('local');

        // First response: document detail (completed). Second: the PDF download.
        $service = $this->service(
            new Response(200, [], json_encode(['status' => 'document.completed'])),
            new Response(200, [], '%PDF signed'),
        );

        $document = PandaDocDocument::create([
            'contract_id' => $this->contract()->id,
            'pandadoc_id' => 'DOC3',
            'name' => 'MSA',
            'status' => PandaDocStatus::Sent,
        ]);

        $service->syncStatus($document);

        $document->refresh();
        $this->assertSame(PandaDocStatus::Completed, $document->status);
        $this->assertNotNull($document->completed_at);
        $this->assertNotNull($document->signed_disk_path);
        Storage::disk('local')->assertExists($document->signed_disk_path);
    }

    public function test_handle_webhook_event_updates_status(): void
    {
        $service = $this->service(); // no client calls for a declined status

        $document = PandaDocDocument::create([
            'contract_id' => $this->contract()->id,
            'pandadoc_id' => 'DOC4',
            'name' => 'MSA',
            'status' => PandaDocStatus::Sent,
        ]);

        $service->handleWebhookEvent(['id' => 'DOC4', 'status' => 'document.declined']);

        $this->assertSame(PandaDocStatus::Declined, $document->refresh()->status);
    }

    public function test_handle_webhook_event_ignores_unknown_document(): void
    {
        $service = $this->service();

        // Should neither throw nor create anything.
        $service->handleWebhookEvent(['id' => 'UNKNOWN', 'status' => 'document.completed']);

        $this->assertDatabaseCount('pandadoc_documents', 0);
    }
}
