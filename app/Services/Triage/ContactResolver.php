<?php

namespace App\Services\Triage;

use App\Models\Asset;
use App\Models\Person;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Support\AiConfig;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the correct client/contact for tickets that arrive unlinked.
 * Ported from HaloClaude triage/user_matcher.py, using local DB instead of Halo API.
 */
class ContactResolver
{
    /**
     * Attempt to resolve and link the correct client/contact for a ticket.
     */
    public static function resolve(Ticket $ticket, ?AiClient $ai = null): ?ContactResolution
    {
        // Skip if ticket already has both client and contact
        if ($ticket->client_id && $ticket->contact_id) {
            Log::debug('[Triage] Contact resolution skipped — already linked', ['ticket_id' => $ticket->id]);

            return null;
        }

        $text = self::collectTicketText($ticket);
        if (! trim($text)) {
            Log::debug('[Triage] No searchable text for contact resolution', ['ticket_id' => $ticket->id]);

            return null;
        }

        // Narrow text for hostname extraction (subject + description only, no notes)
        $summaryText = ($ticket->subject ?? '')."\n".strip_tags($ticket->description ?? '');

        // === Strategy 1: Email address matching ===
        $emails = HostnameExtractor::extractEmails($text);
        if ($emails) {
            Log::debug('[Triage] Extracted emails for resolution', [
                'ticket_id' => $ticket->id,
                'emails' => array_slice($emails, 0, 5),
            ]);

            foreach ($emails as $email) {
                $resolution = self::tryEmailMatch($ticket, $email);
                if ($resolution) {
                    self::applyResolution($ticket, $resolution);

                    return $resolution;
                }
            }
        }

        // === Strategy 2: Regex hostname matching ===
        $hostnames = HostnameExtractor::extractHostnames($summaryText);
        if ($hostnames) {
            Log::debug('[Triage] Extracted hostnames for resolution', [
                'ticket_id' => $ticket->id,
                'hostnames' => array_slice($hostnames, 0, 5),
            ]);

            foreach ($hostnames as $hostname) {
                $resolution = self::tryHostnameMatch($ticket, $hostname);
                if ($resolution) {
                    self::applyResolution($ticket, $resolution);

                    return $resolution;
                }
            }
        }

        // === Strategy 3: All-caps tokens (off by default) ===
        if (\App\Support\TriageConfig::stageEnabled('contact_allcaps')) {
            $alreadyTried = array_flip($hostnames);
            $capsTokens = HostnameExtractor::extractAllCapsTokens($summaryText, $hostnames);
            if ($capsTokens) {
                Log::debug('[Triage] Trying all-caps tokens', [
                    'ticket_id' => $ticket->id,
                    'tokens' => array_slice($capsTokens, 0, 5),
                ]);

                foreach ($capsTokens as $token) {
                    $resolution = self::tryHostnameMatch($ticket, $token);
                    if ($resolution) {
                        $resolution = new ContactResolution(
                            person: $resolution->person,
                            client: $resolution->client,
                            asset: $resolution->asset,
                            method: 'allcaps_token',
                        );
                        self::applyResolution($ticket, $resolution);

                        return $resolution;
                    }
                }
            }
        }

        // === Strategy 4: AI hostname extraction (last resort) ===
        if ($ai && AiConfig::isConfigured()) {
            $aiHostname = self::aiExtractHostname($ai, $summaryText);
            if ($aiHostname && ! in_array($aiHostname, $hostnames)) {
                Log::info('[Triage] AI extracted hostname', [
                    'ticket_id' => $ticket->id,
                    'hostname' => $aiHostname,
                ]);

                $resolution = self::tryHostnameMatch($ticket, $aiHostname);
                if ($resolution) {
                    $resolution = new ContactResolution(
                        person: $resolution->person,
                        client: $resolution->client,
                        asset: $resolution->asset,
                        method: 'ai_extraction',
                    );
                    self::applyResolution($ticket, $resolution);

                    return $resolution;
                }
            }
        }

        Log::info('[Triage] Contact resolution: no match found', ['ticket_id' => $ticket->id]);

        return null;
    }

    // ── Strategy Implementations ──

    /**
     * Strategy 1: Search People by email address.
     */
    private static function tryEmailMatch(Ticket $ticket, string $email): ?ContactResolution
    {
        $person = Person::whereEmailMatch($email)
            ->active()
            ->first();

        if (! $person) {
            return null;
        }

        Log::info('[Triage] Email match', [
            'ticket_id' => $ticket->id,
            'email' => $email,
            'person_id' => $person->id,
            'client_id' => $person->client_id,
        ]);

        return new ContactResolution(
            person: $person,
            client: $person->client,
            method: 'email',
        );
    }

    /**
     * Strategy 2: Search Assets by hostname, then resolve person under that client.
     */
    private static function tryHostnameMatch(Ticket $ticket, string $hostname): ?ContactResolution
    {
        // Search local assets by hostname (case-insensitive)
        $asset = Asset::whereRaw('UPPER(hostname) = ?', [strtoupper($hostname)])
            ->first();

        // Also try the name field
        if (! $asset) {
            $asset = Asset::whereRaw('UPPER(name) = ?', [strtoupper($hostname)])
                ->first();
        }

        if (! $asset || ! $asset->client_id) {
            return null;
        }

        Log::info('[Triage] Hostname match', [
            'ticket_id' => $ticket->id,
            'hostname' => $hostname,
            'asset_id' => $asset->id,
            'client_id' => $asset->client_id,
        ]);

        // Resolve the contact under this client
        $person = self::resolvePersonForClient($asset->client_id, $ticket, $asset);

        return new ContactResolution(
            person: $person,
            client: $asset->client,
            asset: $asset,
            method: 'hostname',
        );
    }

    /**
     * Try to find the correct person under a client.
     */
    private static function resolvePersonForClient(int $clientId, Ticket $ticket, ?Asset $asset = null): ?Person
    {
        $people = Person::where('client_id', $clientId)
            ->active()
            ->get();

        if ($people->isEmpty()) {
            return null;
        }

        // Single active person — use them
        if ($people->count() === 1) {
            return $people->first();
        }

        // Try name matching against ticket text
        $ticketText = strtolower(($ticket->subject ?? '').' '.($ticket->description ?? ''));
        foreach ($people as $person) {
            $name = strtolower($person->full_name);
            if ($name && strlen($name) > 2 && str_contains($ticketText, $name)) {
                return $person;
            }
        }

        // Try matching from asset's last_user field
        if ($asset && $asset->last_user) {
            foreach ($people as $person) {
                if (self::nameMatchesUser($asset->last_user, $person->full_name, $person->email)) {
                    Log::debug('[Triage] Person resolved via asset last_user', [
                        'last_user' => $asset->last_user,
                        'person' => $person->full_name,
                    ]);

                    return $person;
                }
            }
        }

        return null;
    }

    // ── AI Hostname Extraction ──

    private static function aiExtractHostname(AiClient $ai, string $text): ?string
    {
        $truncated = mb_substr(strip_tags($text), 0, 4000);

        try {
            $response = $ai->complete(
                'You are a hostname extraction assistant.',
                'Extract the computer hostname or device name from this IT support ticket text. '
                ."Look for the machine name that the ticket is about (e.g. DESKTOP-ABC123, SHERRI, BASSMAN, ACCOUNTING, PC7, etc.).\n\n"
                ."Respond with ONLY the hostname in uppercase, or NONE if no hostname is found.\n\n"
                ."TICKET TEXT:\n{$truncated}",
                100,
            );

            $hostname = strtoupper(trim($response->text));
            if ($hostname && $hostname !== 'NONE' && strlen($hostname) <= 30) {
                return $hostname;
            }
        } catch (\Throwable $e) {
            Log::warning('[Triage] AI hostname extraction failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    // ── Helpers ──

    /**
     * Apply a contact resolution to a ticket (update client_id and/or contact_id).
     */
    private static function applyResolution(Ticket $ticket, ContactResolution $resolution): void
    {
        $updates = [];

        if ($resolution->client && ! $ticket->client_id) {
            $updates['client_id'] = $resolution->client->id;
        }

        if ($resolution->person && ! $ticket->contact_id) {
            $updates['contact_id'] = $resolution->person->id;
        }

        if ($updates) {
            $ticket->update($updates);
            Log::info('[Triage] Contact resolution applied', [
                'ticket_id' => $ticket->id,
                'method' => $resolution->method,
                'updates' => $updates,
            ]);
        }
    }

    /**
     * Collect searchable text from a ticket (subject, description, notes).
     */
    private static function collectTicketText(Ticket $ticket): string
    {
        $parts = [];

        if ($ticket->subject) {
            $parts[] = $ticket->subject;
        }
        if ($ticket->description) {
            $parts[] = strip_tags($ticket->description);
        }
        if ($ticket->reported_by) {
            $parts[] = $ticket->reported_by;
        }

        // Include first few note bodies for email extraction
        $notes = $ticket->notes()->limit(5)->get();
        foreach ($notes as $note) {
            if ($note->body) {
                $parts[] = strip_tags($note->body);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Check if a device username plausibly matches a person's name.
     * Handles: DOMAIN\first.last, first.last, FirstName LastName, flastname.
     * Ported from HaloClaude asset_matcher.py _name_matches_user().
     */
    public static function nameMatchesUser(string $deviceUser, string $userName, ?string $userEmail = null): bool
    {
        if (! $deviceUser || ! $userName) {
            return false;
        }

        $deviceLower = strtolower(trim($deviceUser));
        // Strip domain prefix (DOMAIN\user)
        if (str_contains($deviceLower, '\\')) {
            $deviceLower = explode('\\', $deviceLower, 2)[1];
        }

        $userLower = strtolower(trim($userName));
        $parts = explode(' ', $userLower);

        if (count($parts) < 2) {
            return str_contains($deviceLower, $parts[0]);
        }

        $first = $parts[0];
        $last = end($parts);

        // Exact patterns
        $patterns = [
            "{$first}.{$last}", "{$first} {$last}", "{$first}{$last}",
            "{$last}.{$first}", "{$last} {$first}", "{$last}{$first}",
        ];

        if (in_array($deviceLower, $patterns, true)) {
            return true;
        }

        // flast pattern (first initial + last name)
        if ($deviceLower === $first[0].$last) {
            return true;
        }

        // Email prefix match
        if ($userEmail) {
            $emailPrefix = strtolower(explode('@', $userEmail, 2)[0]);
            if ($deviceLower === $emailPrefix) {
                return true;
            }
        }

        // Both first AND last in string
        if (str_contains($deviceLower, $first) && str_contains($deviceLower, $last)) {
            return true;
        }

        return false;
    }
}
