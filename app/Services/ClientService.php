<?php

namespace App\Services;

use App\Helpers\MarkdownRenderer;
use App\Models\Client;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

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

    /**
     * Merge a duplicate client into a surviving client.
     *
     * Repoints every client-owned domain record — tickets, assets, people,
     * phone calls, emails, contracts, invoices, licenses — onto the survivor,
     * re-parents the duplicate's reseller children, fills only the survivor's
     * BLANK profile fields from the duplicate, writes an audit note on both,
     * then soft-deletes the duplicate. Mirrors PersonService::mergePeople():
     * query-builder repoints (no model events), pessimistic locking, all inside
     * one transaction. Raw `DB::table` updates deliberately move soft-deleted
     * rows too, so nothing is left pointing at the tombstoned client.
     *
     * Scope boundary: auxiliary tables that merely reference a client — alerts,
     * ninja_alerts, the wiki knowledge base, and the technician/tooling-gap
     * AI logs — are intentionally NOT repointed. They are nullable
     * monitoring/AI-log rows (nullOnDelete/cascade) that don't define client
     * identity; leaving them on the soft-deleted duplicate keeps them harmlessly
     * out of the survivor's operational views.
     *
     * Unlike deleteClient(), merge does NOT block on open tickets / active
     * contracts / unpaid invoices — consolidating those is the whole point.
     *
     * @return array{tickets:int,assets:int,people:int,calls:int,emails:int,contracts:int,invoices:int,licenses:int,reseller_children:int}
     */
    public function mergeClients(Client $survivor, Client $duplicate, int $mergedByUserId): array
    {
        if ($survivor->id === $duplicate->id) {
            throw new \InvalidArgumentException('Cannot merge a client into itself.');
        }

        return DB::transaction(function () use ($survivor, $duplicate, $mergedByUserId) {
            // Pessimistic lock both rows for the duration of the merge
            $survivor = Client::lockForUpdate()->findOrFail($survivor->id);
            $duplicate = Client::lockForUpdate()->findOrFail($duplicate->id);

            // Repoint the client-owned FKs via the query builder: no observers
            // fire and soft-deleted rows move too (people/contracts/invoices use
            // SoftDeletes), so no reference is orphaned on the tombstoned client.
            $counts = [];
            foreach ([
                'tickets' => 'tickets',
                'assets' => 'assets',
                'people' => 'people',
                'calls' => 'phone_calls',
                'emails' => 'emails',
                'contracts' => 'contracts',
                'invoices' => 'invoices',
            ] as $key => $table) {
                $counts[$key] = DB::table($table)
                    ->where('client_id', $duplicate->id)
                    ->update(['client_id' => $survivor->id]);
            }

            // Licenses carry a unique index on (license_type_id, client_id,
            // vendor_ref). Move the ones that won't collide; a duplicate row that
            // would collide (same type + same NON-NULL vendor_ref) is redundant
            // with the survivor's and is dropped. A NULL vendor_ref never
            // collides (NULLs are distinct in a unique index), so it always moves.
            $movedLicenses = 0;
            foreach (DB::table('licenses')->where('client_id', $duplicate->id)->get() as $license) {
                $collides = $license->vendor_ref !== null
                    && DB::table('licenses')
                        ->where('client_id', $survivor->id)
                        ->where('license_type_id', $license->license_type_id)
                        ->where('vendor_ref', $license->vendor_ref)
                        ->exists();
                if ($collides) {
                    DB::table('licenses')->where('id', $license->id)->delete();
                } else {
                    DB::table('licenses')->where('id', $license->id)->update(['client_id' => $survivor->id]);
                    $movedLicenses++;
                }
            }
            $counts['licenses'] = $movedLicenses;

            // Re-parent the duplicate's reseller children onto the survivor.
            // Exclude the survivor itself so a "survivor resold BY the duplicate"
            // link can't become a self-reseller — that dangling link is nulled
            // below instead.
            $counts['reseller_children'] = DB::table('clients')
                ->where('reseller_id', $duplicate->id)
                ->where('id', '!=', $survivor->id)
                ->update(['reseller_id' => $survivor->id]);
            if ((int) $survivor->reseller_id === $duplicate->id) {
                $survivor->reseller_id = null; // a client cannot be its own reseller
            }

            // Fill only BLANK profile fields on the survivor — never overwrite.
            // None of these columns are unique, so copying can't collide.
            // Integration mapping IDs (stripe/qbo/ninja/…) are intentionally NOT
            // carried: they are 1:1 external links that retire with the duplicate
            // and can be re-mapped in Settings.
            foreach ([
                'phone', 'phone_display', 'email', 'website',
                'address_line1', 'address_line2', 'city', 'state', 'postcode',
                'primary_tech_id',
            ] as $field) {
                if (blank($survivor->{$field}) && filled($duplicate->{$field})) {
                    $survivor->{$field} = $duplicate->{$field};
                }
            }

            // Audit: a record line on the survivor and a tombstone on the duplicate
            $merger = User::find($mergedByUserId)?->name ?? 'Unknown';
            $when = now()->toDateString();
            $moved = [];
            foreach ([
                [$counts['tickets'], 'ticket', 'tickets'],
                [$counts['assets'], 'device', 'devices'],
                [$counts['people'], 'contact', 'contacts'],
                [$counts['calls'], 'call', 'calls'],
                [$counts['emails'], 'email', 'emails'],
                [$counts['contracts'], 'contract', 'contracts'],
                [$counts['invoices'], 'invoice', 'invoices'],
                [$counts['licenses'], 'license', 'licenses'],
            ] as [$n, $one, $many]) {
                if ($n) {
                    $moved[] = "{$n} ".($n === 1 ? $one : $many);
                }
            }
            $movedSummary = $moved ? ' Moved: '.implode(', ', $moved).'.' : '';

            $survivor->notes = trim(($survivor->notes ? $survivor->notes."\n\n" : '')
                ."Merged client '{$duplicate->name}' (#{$duplicate->id}) on {$when} by {$merger}.{$movedSummary}");
            $duplicate->notes = trim(($duplicate->notes ? $duplicate->notes."\n\n" : '')
                ."Merged into '{$survivor->name}' (#{$survivor->id}) on {$when} by {$merger}.");

            $duplicate->save();
            $survivor->save();

            $duplicate->delete();

            return $counts;
        });
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
