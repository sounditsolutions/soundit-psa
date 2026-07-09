<?php

namespace Tests\Feature\PandaDoc;

use App\Enums\PandaDocStatus;
use App\Jobs\ProcessPandaDocWebhook;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PandaDocDocument;
use App\Models\PandaDocWebhook;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PandaDocWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'shared-webhook-key';

    private function configureSecret(): void
    {
        Setting::setEncrypted('pandadoc_webhook_secret', $this->secret);
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    private function postWebhook(array $payload, ?string $signature = null)
    {
        $raw = json_encode($payload);
        $signature ??= hash_hmac('sha256', $raw, $this->secret);

        return $this->call(
            'POST',
            '/api/webhooks/pandadoc?signature='.$signature,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $raw,
        );
    }

    public function test_valid_signature_stores_event_and_dispatches_job(): void
    {
        $this->configureSecret();
        Queue::fake();

        $response = $this->postWebhook([
            ['event' => 'document_state_changed', 'data' => ['id' => 'DOC9', 'status' => 'document.completed', 'name' => 'MSA']],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('pandadoc_webhooks', [
            'document_id' => 'DOC9',
            'event_type' => 'document_state_changed',
            'document_status' => 'document.completed',
            'status' => 'pending',
        ]);
        Queue::assertPushed(ProcessPandaDocWebhook::class);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->configureSecret();

        $response = $this->postWebhook(
            [['event' => 'document_state_changed', 'data' => ['id' => 'DOC9']]],
            signature: 'deadbeef',
        );

        $response->assertStatus(401);
        $this->assertDatabaseCount('pandadoc_webhooks', 0);
    }

    public function test_missing_shared_key_rejects_request(): void
    {
        // No secret configured.
        $response = $this->postWebhook(
            [['event' => 'document_state_changed', 'data' => ['id' => 'DOC9']]],
            signature: 'anything',
        );

        $response->assertStatus(401);
    }

    public function test_job_reconciles_document_status(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
        ]);

        $document = PandaDocDocument::create([
            'contract_id' => $contract->id,
            'pandadoc_id' => 'DOC10',
            'name' => 'MSA',
            'status' => PandaDocStatus::Sent,
        ]);

        $webhook = PandaDocWebhook::create([
            'event_type' => 'document_state_changed',
            'document_id' => 'DOC10',
            'document_status' => 'document.viewed',
            'payload' => ['event' => 'document_state_changed', 'data' => ['id' => 'DOC10', 'status' => 'document.viewed']],
        ]);

        (new ProcessPandaDocWebhook($webhook->id))->handle(app(\App\Services\PandaDoc\PandaDocService::class));

        $this->assertSame(PandaDocStatus::Viewed, $document->refresh()->status);
        $this->assertSame('processed', $webhook->refresh()->status);
    }
}
