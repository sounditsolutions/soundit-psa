<?php

namespace Tests\Feature\Clients;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ClientService
    {
        return new ClientService;
    }

    private function makeInvoice(Client $client, InvoiceStatus $status): Invoice
    {
        return Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-DEL-'.$status->value.'-'.$client->id,
            'invoice_date' => now()->subDays(30),
            'due_date' => now()->subDays(10),
            'subtotal' => '100.00',
            'tax' => '0.00',
            'total' => '100.00',
            'status' => $status,
        ]);
    }

    public function test_client_with_no_invoices_can_be_deleted(): void
    {
        $client = Client::factory()->create();

        $this->service()->deleteClient($client);

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    /**
     * Regression for psa-droo: the unpaid-invoice guard previously queried a
     * nonexistent 'sent' status, so it never fired and a client with an
     * outstanding (posted) invoice could be soft-deleted.
     */
    public function test_client_with_posted_invoice_cannot_be_deleted(): void
    {
        $client = Client::factory()->create();
        $this->makeInvoice($client, InvoiceStatus::Posted);

        try {
            $this->service()->deleteClient($client);
            $this->fail('Expected a RuntimeException blocking deletion of a client with an unpaid invoice.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unpaid invoice', $e->getMessage());
        }

        $this->assertNotSoftDeleted('clients', ['id' => $client->id]);
    }

    /**
     * The guard follows Invoice::scopeUnpaid() — anything that is not Paid and
     * not Void is outstanding, so a Draft invoice also blocks deletion.
     */
    public function test_client_with_draft_invoice_cannot_be_deleted(): void
    {
        $client = Client::factory()->create();
        $this->makeInvoice($client, InvoiceStatus::Draft);

        $this->expectException(\RuntimeException::class);

        try {
            $this->service()->deleteClient($client);
        } finally {
            $this->assertNotSoftDeleted('clients', ['id' => $client->id]);
        }
    }

    public function test_client_with_only_paid_invoice_can_be_deleted(): void
    {
        $client = Client::factory()->create();
        $this->makeInvoice($client, InvoiceStatus::Paid);

        $this->service()->deleteClient($client);

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    public function test_client_with_only_void_invoice_can_be_deleted(): void
    {
        $client = Client::factory()->create();
        $this->makeInvoice($client, InvoiceStatus::Void);

        $this->service()->deleteClient($client);

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }
}
