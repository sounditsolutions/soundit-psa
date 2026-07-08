<?php

namespace App\Services\T2T;

use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\TicketService;
use App\Support\T2TConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class T2TService
{
    public function __construct(
        private readonly TicketService $ticketService,
    ) {}

    // ── Contact Operations ──

    // Sentinel ID for T2T's "unregistered" fallback contact — not a real Person record.
    // When T2T can't find a real contact, it looks up unregistered@helpdeskbuttons.com.
    // We return a synthetic contact so T2T proceeds with ticket creation, then we
    // set contact_id = null on the ticket so it lands in triage unassigned.
    public const UNREGISTERED_SENTINEL_ID = 99999999;

    /**
     * Search for contacts by email, optionally scoped to a company.
     */
    private const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'live.com',
        'icloud.com', 'aol.com', 'protonmail.com', 'me.com', 'msn.com',
    ];

    public function findContactsByEmail(string $email, ?int $companyId = null): array
    {
        // Return synthetic contact for T2T's fallback lookup
        if (str_contains(strtolower($email), 'unregistered@helpdeskbuttons.com')) {
            return [T2TFieldMapper::syntheticUnregisteredContact(self::UNREGISTERED_SENTINEL_ID)];
        }

        // 1. Exact email match on active clients
        $query = Person::with('client')
            ->whereHas('client') // Exclude contacts whose client is soft-deleted
            ->whereEmailMatch($email);

        if ($companyId) {
            $query->where('client_id', $companyId);
        }

        $results = $query->limit(25)->get();

        if ($results->isNotEmpty()) {
            return $results
                ->map(fn (Person $p) => T2TFieldMapper::personToCwContact($p))
                ->values()
                ->all();
        }

        // 2. No match — try to resolve company by email domain and create a stub contact
        $domain = strtolower(substr($email, strrpos($email, '@') + 1));
        if (! $domain || in_array($domain, self::FREE_EMAIL_DOMAINS)) {
            return [];
        }

        // 2a. Match client by website domain
        $client = Client::where('website', 'like', "%{$domain}%")
            ->active()
            ->first();

        // 2b. Match client via existing people with the same email domain
        if (! $client) {
            $domainPerson = Person::whereEmailDomain($domain)
                ->whereHas('client')
                ->whereNotNull('client_id')
                ->first();
            $client = $domainPerson?->client;
        }

        if (! $client) {
            return [];
        }

        // Create stub contact under the resolved client
        $nameParts = explode('@', strtolower($email));
        $localPart = $nameParts[0];
        $namePieces = preg_split('/[._\-+]/', $localPart, 2);
        $firstName = ucfirst($namePieces[0] ?? '');
        $lastName = ucfirst($namePieces[1] ?? '');

        $person = Person::create([
            'client_id' => $client->id,
            'first_name' => $firstName ?: $email,
            'last_name' => $lastName,
            'email' => strtolower($email),
            'is_active' => true,
        ]);

        $person->load('client');

        Log::info('[T2T] Created stub contact from email domain', [
            'person_id' => $person->id,
            'email' => $email,
            'client_id' => $client->id,
            'client' => $client->name,
        ]);

        return [T2TFieldMapper::personToCwContact($person)];
    }

    /**
     * Create a new contact from CW-format data.
     */
    public function createContact(array $data): ?array
    {
        $email = null;
        $phone = null;

        foreach ($data['communicationItems'] ?? [] as $item) {
            $type = strtolower($item['communicationType'] ?? $item['type']['name'] ?? '');
            if ($type === 'email' && ! $email) {
                $email = $item['value'];
            }
            if ($type === 'phone' && ! $phone) {
                $phone = $item['value'];
            }
        }

        $clientId = $data['company']['id'] ?? null;
        $client = $clientId ? Client::find($clientId) : null;

        if (! $client) {
            return null;
        }

        // Deduplicate by email within client
        if ($email) {
            $existing = Person::whereEmailMatch($email)
                ->where('client_id', $client->id)
                ->with('client')
                ->first();

            if ($existing) {
                return T2TFieldMapper::personToCwContact($existing);
            }
        }

        $person = Person::create([
            'client_id' => $client->id,
            'first_name' => $data['firstName'] ?? 'Unknown',
            'last_name' => $data['lastName'] ?? '',
            'email' => $email ? strtolower($email) : null,
            'phone' => $phone,
            'is_active' => true,
        ]);

        $person->load('client');

        Log::info('[T2T] Created contact', [
            'person_id' => $person->id,
            'email' => $email,
            'client_id' => $client->id,
        ]);

        return T2TFieldMapper::personToCwContact($person);
    }

    // ── Configuration (Asset) Operations ──

    /**
     * Search for assets by hostname, scoped to client if provided.
     */
    public function findConfigurationsByHostname(string $hostname, ?int $companyId = null): array
    {
        $query = Asset::with('client')
            ->where('hostname', 'like', "%{$hostname}%")
            ->active();

        if ($companyId) {
            $query->where('client_id', $companyId);
        }

        return $query->limit(25)->get()
            ->map(fn (Asset $a) => T2TFieldMapper::assetToCwConfiguration($a))
            ->values()
            ->all();
    }

    // ── Ticket Operations ──

    /**
     * Create a ticket from CW-format data with explicit field allowlisting.
     */
    public function createTicketFromCw(array $data): array
    {
        $clientId = $data['company']['id'] ?? null;
        $contactId = $data['contact']['id'] ?? null;
        $priorityCwId = $data['priority']['id'] ?? 3;
        $typeCwId = $data['type']['id'] ?? 1;

        // Discard the synthetic "unregistered" sentinel — ticket lands with no contact
        if ((int) $contactId === self::UNREGISTERED_SENTINEL_ID) {
            $contactId = null;
        }

        // Discard synthetic company ID 0 (unregistered fallback)
        if ((int) $clientId === 0) {
            $clientId = null;
        }

        // Validate client
        if ($clientId && ! Client::where('id', $clientId)->exists()) {
            throw new \InvalidArgumentException('Company not found');
        }

        // Validate contact belongs to client
        if ($contactId && $clientId) {
            if (! Person::where('id', $contactId)->where('client_id', $clientId)->exists()) {
                $contactId = null; // Ignore cross-client contact reference
            }
        }

        $priority = T2TFieldMapper::cwPriorityToTicketPriority((int) $priorityCwId);
        $type = T2TFieldMapper::cwTypeToTicketType((int) $typeCwId);

        // Duplicate detection: same contact + helpdesk_button + open + last 5 min
        if ($contactId) {
            $duplicate = Ticket::where('contact_id', $contactId)
                ->where('source', TicketSource::HelpdeskButton->value)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->open()
                ->first();

            if ($duplicate) {
                $duplicate->load(['client', 'contact']);
                Log::info('[T2T] Duplicate ticket detected, returning existing', [
                    'ticket_id' => $duplicate->id,
                ]);

                return T2TFieldMapper::ticketToCwFormat($duplicate);
            }
        }

        // Resolve hostname from configuration data for audit note
        $hostname = null;
        $configId = $data['configuration']['id'] ?? $data['configurationId'] ?? null;
        if ($configId) {
            $hostname = Asset::where('id', $configId)->value('hostname');
        }

        $systemUserId = T2TConfig::systemUserId();

        if (! $systemUserId) {
            throw new \RuntimeException('T2T system user not configured and no users exist in the system');
        }

        // Explicit field allowlist — never pass raw CW data to Ticket::create()
        $ticketData = [
            'subject' => mb_substr($data['summary'] ?? 'Helpdesk Button Submission', 0, 255),
            'description' => mb_substr($data['initialDescription'] ?? '', 0, 65535),
            'client_id' => $clientId,
            'contact_id' => $contactId,
            'priority' => $priority->value,
            'type' => $type->value,
            'source' => TicketSource::HelpdeskButton->value,
        ];

        $ticket = DB::transaction(function () use ($ticketData, $configId, $hostname, $contactId, $systemUserId) {
            $ticket = $this->ticketService->createTicket($ticketData, $systemUserId);

            // Attach asset if provided and valid
            if ($configId && Asset::where('id', $configId)->exists()) {
                $ticket->assets()->attach($configId, ['is_primary' => true]);
            }

            // Create initial audit note. Include the user's typed message so
            // it stays visible in the timeline (and in the AI context built
            // from notes) even after the ticket is merged into another.
            $contact = $contactId ? Person::find($contactId) : null;
            $noteBody = 'Submitted via HelpDesk Button';
            if ($contact) {
                $noteBody .= ' by '.$contact->full_name;
            }
            if ($hostname) {
                $noteBody .= ' from '.$hostname;
            }
            $noteBody .= '.';

            $userMessage = trim($ticketData['description'] ?? '');
            if ($userMessage !== '') {
                $maxLen = 8000;
                if (mb_strlen($userMessage) > $maxLen) {
                    $userMessage = mb_substr($userMessage, 0, $maxLen)."\n\n[truncated]";
                }
                $quoted = '> '.str_replace("\n", "\n> ", $userMessage);
                $noteBody .= "\n\n**Message:**\n\n{$quoted}";
            }

            if ($systemUserId) {
                $this->ticketService->addNote(
                    $ticket,
                    $noteBody,
                    NoteType::StatusChange,
                    true,
                    $systemUserId,
                );
            }

            return $ticket;
        });

        $ticket->load(['client', 'contact']);

        Log::info('[T2T] Created ticket', [
            'ticket_id' => $ticket->id,
            'client_id' => $clientId,
            'contact_id' => $contactId,
            'source' => 'helpdesk_button',
        ]);

        return T2TFieldMapper::ticketToCwFormat($ticket);
    }

    /**
     * Update a ticket's status from CW-format PATCH data.
     */
    public function updateTicketFromCw(Ticket $ticket, array $data): array
    {
        $systemUserId = T2TConfig::systemUserId();

        if (! $systemUserId) {
            throw new \RuntimeException('T2T system user not configured and no users exist in the system');
        }

        // Handle JSON Patch format: [{op, path, value}, ...]
        if (isset($data[0]['op'])) {
            foreach ($data as $op) {
                $this->applyPatchOperation($ticket, $op, $systemUserId);
            }
        } else {
            // Flat update format
            if (isset($data['status']['id'])) {
                $newStatus = T2TFieldMapper::cwStatusToTicketStatus((int) $data['status']['id']);
                if ($ticket->status !== $newStatus) {
                    $this->ticketService->changeStatus(
                        $ticket, $newStatus, $systemUserId, 'Updated via Helpdesk Button'
                    );
                    $ticket->refresh();
                }
            }

            if (isset($data['priority']['id'])) {
                $newPriority = T2TFieldMapper::cwPriorityToTicketPriority((int) $data['priority']['id']);
                $this->ticketService->updateTicket($ticket, [
                    'priority' => $newPriority->value,
                    'priority_order' => $newPriority->sortOrder(),
                ]);
                $ticket->refresh();
            }
        }

        $ticket->load(['client', 'contact']);

        return T2TFieldMapper::ticketToCwFormat($ticket);
    }

    /**
     * Add a note to a ticket from CW-format data.
     */
    public function addNoteFromCw(Ticket $ticket, string $body, bool $isInternal, int $systemUserId): array
    {
        $note = $this->ticketService->addNote(
            $ticket,
            $body,
            NoteType::Note,
            $isInternal,
            $systemUserId,
        );

        Log::info('[T2T] Added note to ticket', [
            'ticket_id' => $ticket->id,
            'note_id' => $note->id,
        ]);

        return [
            'id' => $note->id,
            'ticketId' => $ticket->id,
            'text' => $note->body,
            'internalAnalysisFlag' => $note->is_private,
            'dateCreated' => $note->created_at?->toIso8601String(),
            '_info' => [
                'lastUpdated' => $note->updated_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Register a callback URL (validated for HTTPS and non-private IPs).
     */
    public function registerCallback(string $url): void
    {
        $parsed = parse_url($url);

        // Must be HTTPS
        if (($parsed['scheme'] ?? '') !== 'https') {
            throw new \InvalidArgumentException('Callback URL must use HTTPS');
        }

        $host = $parsed['host'] ?? '';

        // Reject private/reserved IP ranges
        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \InvalidArgumentException('Callback URL must not point to private/reserved IP ranges');
        }

        Setting::setValue('t2t_callback_url', $url);

        Log::info('[T2T] Callback URL registered', ['url' => $url]);
    }

    // ── Condition Parsing ──

    /**
     * Parse email from CW conditions string.
     * Example: communicationItems/value like "%user@example.com%"
     */
    public static function parseEmailFromConditions(string $conditions): ?string
    {
        if (preg_match('/communicationItems\/value\s+like\s+["\']%?(.+?)%?["\']/i', $conditions, $m)) {
            return self::sanitizeLikeValue($m[1]);
        }
        if (preg_match('/email\s+like\s+["\']%?(.+?)%?["\']/i', $conditions, $m)) {
            return self::sanitizeLikeValue($m[1]);
        }

        return null;
    }

    /**
     * Parse hostname from CW conditions string.
     * Example: deviceIdentifier like "%HOSTNAME%"
     */
    public static function parseHostnameFromConditions(string $conditions): ?string
    {
        if (preg_match('/deviceIdentifier\s+like\s+["\']%(.+?)%["\']/i', $conditions, $m)) {
            return self::sanitizeLikeValue($m[1]);
        }
        if (preg_match('/name\s+like\s+["\']%(.+?)%["\']/i', $conditions, $m)) {
            return self::sanitizeLikeValue($m[1]);
        }

        return null;
    }

    /**
     * Parse company ID from CW conditions string.
     * Example: company/id=123
     */
    public static function parseCompanyIdFromConditions(string $conditions): ?int
    {
        if (preg_match('/company\/id\s*=\s*(\d+)/', $conditions, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Parse ticket ID from CW conditions string.
     */
    public static function parseTicketIdFromConditions(string $conditions): ?int
    {
        if (preg_match('/\bid\s*=\s*(\d+)/', $conditions, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    // ── Private Helpers ──

    private function applyPatchOperation(Ticket $ticket, array $op, ?int $systemUserId): void
    {
        $path = $op['path'] ?? '';
        $value = $op['value'] ?? null;

        if ($op['op'] === 'replace' && $path === 'status/id' && $value) {
            $newStatus = T2TFieldMapper::cwStatusToTicketStatus((int) $value);
            if ($ticket->status !== $newStatus) {
                $this->ticketService->changeStatus(
                    $ticket, $newStatus, $systemUserId, 'Updated via Helpdesk Button'
                );
                $ticket->refresh();
            }
        }

        if ($op['op'] === 'replace' && $path === 'priority/id' && $value) {
            $newPriority = T2TFieldMapper::cwPriorityToTicketPriority((int) $value);
            $this->ticketService->updateTicket($ticket, [
                'priority' => $newPriority->value,
                'priority_order' => $newPriority->sortOrder(),
            ]);
            $ticket->refresh();
        }
    }

    /**
     * Strip LIKE wildcards from parsed condition values.
     */
    private static function sanitizeLikeValue(string $value): string
    {
        return str_replace(['%', '_'], '', $value);
    }
}
