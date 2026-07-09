<?php

namespace App\Services\PandaDoc;

use App\Enums\PandaDocStatus;
use App\Models\Contract;
use App\Models\PandaDocDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Business logic for PandaDoc agreements: create a document from a template,
 * send it for signature, sync its status, and archive the signed PDF locally.
 *
 * The Guzzle client is injected so tests can supply a mock-backed transport
 * (mirrors the TacticalClient testable-seam pattern).
 */
class PandaDocService
{
    public function __construct(
        private readonly PandaDocClient $client,
    ) {}

    /**
     * Create a PandaDoc document from a template for the given contract and
     * persist a tracking record. PandaDoc processes creation asynchronously,
     * so the document is not yet sendable — poll or wait for `draft` before send.
     */
    public function createFromTemplate(
        Contract $contract,
        string $templateId,
        ?string $templateName,
        string $recipientEmail,
        string $recipientName,
        ?string $documentName = null,
        ?int $userId = null,
    ): PandaDocDocument {
        $contract->loadMissing('client');

        [$firstName, $lastName] = $this->splitName($recipientName);
        $name = $documentName ?: ($contract->name.' — '.($contract->client->name ?? 'Agreement'));

        $payload = [
            'name' => $name,
            'template_uuid' => $templateId,
            'recipients' => [
                [
                    'email' => $recipientEmail,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ],
            ],
            'tokens' => $this->buildTokens($contract),
            'metadata' => [
                'psa_contract_id' => (string) $contract->id,
            ],
        ];

        $response = $this->client->createDocument($payload);

        $pandaDocId = $response['id'] ?? null;
        if (! $pandaDocId) {
            throw new PandaDocClientException('PandaDoc did not return a document id on creation.');
        }

        $document = PandaDocDocument::create([
            'contract_id' => $contract->id,
            'created_by' => $userId,
            'pandadoc_id' => $pandaDocId,
            'name' => $response['name'] ?? $name,
            'status' => PandaDocStatus::fromApi($response['status'] ?? 'draft'),
            'template_id' => $templateId,
            'template_name' => $templateName,
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
        ]);

        Log::info('[PandaDoc] Document created', [
            'document_id' => $document->id,
            'contract_id' => $contract->id,
            'pandadoc_id' => $pandaDocId,
        ]);

        return $document;
    }

    /**
     * Send a draft document to its recipient for signature.
     */
    public function send(PandaDocDocument $document, ?string $subject = null, ?string $message = null): void
    {
        $payload = array_filter([
            'subject' => $subject,
            'message' => $message,
            'silent' => false,
        ], fn ($v) => $v !== null);

        $response = $this->client->sendDocument($document->pandadoc_id, $payload);

        $document->update([
            'status' => PandaDocStatus::fromApi($response['status'] ?? 'sent'),
            'sent_at' => now(),
        ]);

        Log::info('[PandaDoc] Document sent', [
            'document_id' => $document->id,
            'pandadoc_id' => $document->pandadoc_id,
        ]);
    }

    /**
     * Pull the current status from PandaDoc and reconcile the local record.
     */
    public function syncStatus(PandaDocDocument $document): void
    {
        $response = $this->client->getDocument($document->pandadoc_id);
        $this->applyStatus($document, $response['status'] ?? null);
    }

    /**
     * Apply a status update delivered by a webhook `data` object.
     */
    public function handleWebhookEvent(array $data): void
    {
        $pandaDocId = $data['id'] ?? null;
        if (! $pandaDocId) {
            return;
        }

        $document = PandaDocDocument::where('pandadoc_id', $pandaDocId)->first();
        if (! $document) {
            Log::debug('[PandaDoc] Webhook for unknown document', ['pandadoc_id' => $pandaDocId]);

            return;
        }

        $this->applyStatus($document, $data['status'] ?? null);
    }

    /**
     * Normalize + persist a new status, downloading the signed PDF on completion.
     */
    private function applyStatus(PandaDocDocument $document, ?string $apiStatus): void
    {
        $status = PandaDocStatus::fromApi($apiStatus);

        $attrs = ['status' => $status];
        if ($status->isSigned() && ! $document->completed_at) {
            $attrs['completed_at'] = now();
        }
        $document->update($attrs);

        if ($status->isSigned() && ! $document->hasSignedPdf()) {
            $this->downloadSignedPdf($document);
        }
    }

    /**
     * Download the completed PDF from PandaDoc and store it on the local disk.
     * Failures are logged but non-fatal — the status update already persisted.
     */
    public function downloadSignedPdf(PandaDocDocument $document): void
    {
        try {
            $bytes = $this->client->downloadDocument($document->pandadoc_id);
            $path = "pandadoc-documents/{$document->contract_id}/{$document->pandadoc_id}.pdf";
            Storage::disk('local')->put($path, $bytes);

            $document->update([
                'signed_disk_path' => $path,
                'completed_at' => $document->completed_at ?? now(),
            ]);

            Log::info('[PandaDoc] Signed PDF archived', [
                'document_id' => $document->id,
                'path' => $path,
            ]);
        } catch (\Throwable $e) {
            Log::error('[PandaDoc] Failed to download signed PDF', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort void in PandaDoc, then soft-delete the local record.
     */
    public function delete(PandaDocDocument $document): void
    {
        if (! $document->status->isTerminal()) {
            try {
                $this->client->voidDocument($document->pandadoc_id);
            } catch (\Throwable $e) {
                Log::warning('[PandaDoc] Void on delete failed (continuing)', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $document->delete();
    }

    /**
     * Build PandaDoc template tokens from contract fields. Templates reference
     * these by name (e.g. {{Contract.Name}}); unknown tokens are ignored.
     */
    private function buildTokens(Contract $contract): array
    {
        return [
            ['name' => 'Contract.Name', 'value' => (string) $contract->name],
            ['name' => 'Client.Name', 'value' => (string) ($contract->client->name ?? '')],
            ['name' => 'Contract.StartDate', 'value' => $contract->start_date?->format('Y-m-d') ?? ''],
            ['name' => 'Contract.EndDate', 'value' => $contract->end_date?->format('Y-m-d') ?? ''],
        ];
    }

    /**
     * @return array{0: string, 1: string} first name, last name
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
