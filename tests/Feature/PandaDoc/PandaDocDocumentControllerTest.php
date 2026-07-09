<?php

namespace Tests\Feature\PandaDoc;

use App\Enums\PandaDocStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PandaDocDocument;
use App\Models\Setting;
use App\Models\User;
use App\Services\PandaDoc\PandaDocClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PandaDocDocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingStaff(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
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

    /**
     * Bind a PandaDocClient with canned responses so the resolved service never
     * touches the network.
     */
    private function bindFakeClient(Response ...$responses): void
    {
        $http = new GuzzleClient([
            'base_uri' => 'https://api.pandadoc.com',
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]);

        $this->instance(PandaDocClient::class, new PandaDocClient($http));
    }

    public function test_store_creates_agreement_and_logs_activity(): void
    {
        $this->actingStaff();
        Setting::setEncrypted('pandadoc_api_key', 'pd-key');
        $this->bindFakeClient(new Response(201, [], json_encode([
            'id' => 'DOCA',
            'name' => 'Managed Services — Acme Corp',
            'status' => 'document.draft',
        ])));

        $contract = $this->contract();

        $response = $this->post(route('contracts.pandadoc.store', $contract), [
            'template_id' => 'TPL-1',
            'template_name' => 'MSA',
            'recipient_email' => 'jane@acme.test',
            'recipient_name' => 'Jane Doe',
        ]);

        $response->assertRedirect(route('contracts.show', $contract));
        $this->assertDatabaseHas('pandadoc_documents', [
            'contract_id' => $contract->id,
            'pandadoc_id' => 'DOCA',
        ]);
        $this->assertDatabaseHas('contract_activities', [
            'contract_id' => $contract->id,
            'action' => 'pandadoc_created',
        ]);
    }

    public function test_store_is_blocked_when_not_configured(): void
    {
        $this->actingStaff();
        $contract = $this->contract();

        $response = $this->post(route('contracts.pandadoc.store', $contract), [
            'template_id' => 'TPL-1',
            'recipient_email' => 'jane@acme.test',
            'recipient_name' => 'Jane Doe',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('pandadoc_documents', 0);
    }

    public function test_download_returns_signed_pdf(): void
    {
        Storage::fake('local');
        $this->actingStaff();
        $contract = $this->contract();

        Storage::disk('local')->put('pandadoc-documents/'.$contract->id.'/DOCB.pdf', '%PDF signed');

        $document = PandaDocDocument::create([
            'contract_id' => $contract->id,
            'pandadoc_id' => 'DOCB',
            'name' => 'MSA',
            'status' => PandaDocStatus::Completed,
            'signed_disk_path' => 'pandadoc-documents/'.$contract->id.'/DOCB.pdf',
        ]);

        $response = $this->get(route('contracts.pandadoc.download', [$contract, $document]));

        $response->assertOk();
    }

    public function test_download_404_when_no_signed_pdf(): void
    {
        Storage::fake('local');
        $this->actingStaff();
        $contract = $this->contract();

        $document = PandaDocDocument::create([
            'contract_id' => $contract->id,
            'pandadoc_id' => 'DOCC',
            'name' => 'MSA',
            'status' => PandaDocStatus::Sent,
        ]);

        $this->get(route('contracts.pandadoc.download', [$contract, $document]))->assertNotFound();
    }

    public function test_destroy_soft_deletes_the_agreement(): void
    {
        $this->actingStaff();
        $contract = $this->contract();

        // Completed → delete() will not attempt a void call (no network needed).
        $document = PandaDocDocument::create([
            'contract_id' => $contract->id,
            'pandadoc_id' => 'DOCD',
            'name' => 'MSA',
            'status' => PandaDocStatus::Completed,
        ]);

        $this->delete(route('contracts.pandadoc.destroy', [$contract, $document]))
            ->assertRedirect(route('contracts.show', $contract));

        $this->assertSoftDeleted('pandadoc_documents', ['id' => $document->id]);
    }

    public function test_cross_contract_access_is_404(): void
    {
        Storage::fake('local');
        $this->actingStaff();
        $contractA = $this->contract();
        $contractB = $this->contract();

        $document = PandaDocDocument::create([
            'contract_id' => $contractA->id,
            'pandadoc_id' => 'DOCE',
            'name' => 'MSA',
            'status' => PandaDocStatus::Completed,
            'signed_disk_path' => 'pandadoc-documents/'.$contractA->id.'/DOCE.pdf',
        ]);

        // Requesting under the wrong contract must not resolve.
        $this->get(route('contracts.pandadoc.download', [$contractB, $document]))->assertNotFound();
    }
}
