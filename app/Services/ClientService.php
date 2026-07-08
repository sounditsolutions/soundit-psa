<?php

namespace App\Services;

use App\Helpers\MarkdownRenderer;
use App\Models\Client;
use App\Support\PhoneNumber;

class ClientService
{
    public function createClient(array $data): Client
    {
        $this->normalizePhone($data);

        return Client::create($data);
    }

    public function updateClient(Client $client, array $data): Client
    {
        $this->normalizePhone($data);

        // Delegate site notes and credentials to their dedicated methods
        if (array_key_exists('site_notes', $data)) {
            $this->updateSiteNotes($client, $data['site_notes']);
            unset($data['site_notes'], $data['site_notes_html'], $data['site_notes_updated_at'], $data['site_notes_updated_by']);
        }

        if (array_key_exists('credentials', $data)) {
            $this->updateCredentials($client, $data['credentials']);
            unset($data['credentials'], $data['credentials_updated_at'], $data['credentials_updated_by']);
        }

        $client->update($data);

        return $client->fresh();
    }

    public function updateSiteNotes(Client $client, ?string $siteNotes, ?string $expectedUpdatedAt = null, ?int $updatedByUserId = null): Client
    {
        $trimmed = $siteNotes ? trim($siteNotes) : null;
        $trimmed = $trimmed ?: null;

        // Concurrent edit detection
        if ($expectedUpdatedAt && $client->site_notes_updated_at) {
            $expected = \Carbon\Carbon::parse($expectedUpdatedAt);
            if (! $expected->equalTo($client->site_notes_updated_at)) {
                $editor = $client->siteNotesUpdatedBy?->name ?? 'someone';
                throw new \RuntimeException(
                    "Site notes were updated by {$editor} at {$client->site_notes_updated_at->diffForHumans()} while you were editing. Please reload and try again."
                );
            }
        }

        // Skip if unchanged
        if ($trimmed === $client->site_notes) {
            return $client;
        }

        $client->update([
            'site_notes' => $trimmed,
            'site_notes_html' => $trimmed ? MarkdownRenderer::render($trimmed) : null,
            'site_notes_updated_at' => now(),
            'site_notes_updated_by' => $updatedByUserId ?? auth()->id(),
        ]);

        return $client;
    }

    public function updateCredentials(Client $client, ?string $credentials, ?string $expectedUpdatedAt = null): Client
    {
        $trimmed = $credentials ? trim($credentials) : null;
        $trimmed = $trimmed ?: null;

        // Concurrent edit detection
        if ($expectedUpdatedAt && $client->credentials_updated_at) {
            $expected = \Carbon\Carbon::parse($expectedUpdatedAt);
            if (! $expected->equalTo($client->credentials_updated_at)) {
                $editor = $client->credentialsUpdatedBy?->name ?? 'someone';
                throw new \RuntimeException(
                    "Credentials were updated by {$editor} at {$client->credentials_updated_at->diffForHumans()} while you were editing. Please reload and try again."
                );
            }
        }

        // Skip if unchanged
        if ($trimmed === $client->credentials) {
            return $client;
        }

        $client->update([
            'credentials' => $trimmed,
            'credentials_updated_at' => now(),
            'credentials_updated_by' => auth()->id(),
        ]);

        return $client;
    }

    public function deleteClient(Client $client): void
    {
        // Block deletion if client has open tickets
        $openTickets = $client->tickets()->whereIn('status', ['new', 'in_progress', 'pending_client', 'pending_third_party'])->count();
        if ($openTickets > 0) {
            throw new \RuntimeException("Cannot delete client with {$openTickets} open ticket(s). Resolve or close them first.");
        }

        // Block deletion if client has active contracts
        $activeContracts = $client->contracts()->where('status', 'active')->count();
        if ($activeContracts > 0) {
            throw new \RuntimeException("Cannot delete client with {$activeContracts} active contract(s). Cancel or expire them first.");
        }

        // Block deletion if client has unpaid invoices
        $unpaidInvoices = $client->invoices()->where('status', 'sent')->count();
        if ($unpaidInvoices > 0) {
            throw new \RuntimeException("Cannot delete client with {$unpaidInvoices} unpaid invoice(s). Mark them as paid or void first.");
        }

        $client->delete();
    }

    private function normalizePhone(array &$data): void
    {
        if (! empty($data['phone'])) {
            $data['phone_display'] = PhoneNumber::format($data['phone']);
            $data['phone'] = PhoneNumber::normalize($data['phone']);
        } else {
            $data['phone'] = null;
            $data['phone_display'] = null;
        }
    }
}
