<?php

namespace App\Services\Cipp;

use App\Models\Client;
use App\Models\Person;
use App\Services\SyncResult;
use App\Support\AvatarHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CippContactEnrichmentService
{
    /**
     * How long a synced (or checked-and-empty) profile photo stays fresh before
     * we re-poll CIPP. Photos are a per-user API call, so we bound the churn —
     * most M365 photos never change.
     */
    private const PHOTO_TTL_DAYS = 30;

    public function __construct(
        private readonly CippClient $client,
    ) {}

    /**
     * Enrich synced contacts with mailbox + MFA data from CIPP.
     * Operates on existing Person records (requires contact sync to have run first).
     */
    public function enrichContacts(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('cipp_tenant_domain')
            ->operational()
            ->get();

        $result = new SyncResult;

        foreach ($clients as $client) {
            try {
                $this->enrichForClient($client, $result);
            } catch (\Throwable $e) {
                Log::error("[CippEnrich] Failed for client {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        return $result;
    }

    /**
     * Enrich contacts for a single client.
     */
    public function enrichForClient(Client $client, SyncResult $result): void
    {
        $tenantDomain = $client->cipp_tenant_domain;

        // Each enrichment pass is independent — one failure doesn't block others
        try {
            $this->enrichMailboxData($client, $tenantDomain, $result);
        } catch (\Throwable $e) {
            Log::warning("[CippEnrich] Mailbox enrichment failed for {$client->name}: {$e->getMessage()}");
            $result->recordError("Mailbox for {$client->name}: {$e->getMessage()}");
        }

        try {
            $this->enrichMfaData($client, $tenantDomain, $result);
        } catch (\Throwable $e) {
            Log::warning("[CippEnrich] MFA enrichment failed for {$client->name}: {$e->getMessage()}");
            $result->recordError("MFA for {$client->name}: {$e->getMessage()}");
        }

        try {
            $this->enrichInactiveAccounts($client, $tenantDomain, $result);
        } catch (\Throwable $e) {
            Log::warning("[CippEnrich] Inactive accounts failed for {$client->name}: {$e->getMessage()}");
            $result->recordError("Inactive accounts for {$client->name}: {$e->getMessage()}");
        }

        try {
            $this->enrichPhotos($client, $tenantDomain, $result);
        } catch (\Throwable $e) {
            Log::warning("[CippEnrich] Photo sync failed for {$client->name}: {$e->getMessage()}");
            $result->recordError("Photos for {$client->name}: {$e->getMessage()}");
        }
    }

    /**
     * Sync M365 profile photos into local avatars via CIPP's ListUserPhoto.
     *
     * Unlike the other enrichment passes (one tenant-wide list call), photos are
     * fetched one user at a time, so we only touch persons whose photo hasn't
     * been checked within PHOTO_TTL_DAYS. Each fetch is guarded independently —
     * one user's failure never aborts the client's run.
     */
    private function enrichPhotos(Client $client, string $tenantDomain, SyncResult $result): void
    {
        $persons = Person::where('client_id', $client->id)
            ->whereNotNull('cipp_user_id')
            ->where(function ($q) {
                $q->whereNull('avatar_synced_at')
                    ->orWhere('avatar_synced_at', '<', now()->subDays(self::PHOTO_TTL_DAYS));
            })
            ->get(['id', 'cipp_user_id', 'avatar_path', 'avatar_synced_at']);

        foreach ($persons as $person) {
            try {
                $this->syncPhotoForPerson($person, $tenantDomain, $result);
            } catch (\Throwable $e) {
                Log::warning("[CippEnrich] Photo fetch failed for person {$person->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Fetch and store one person's M365 photo. CIPP returns raw image bytes when
     * a photo is set, or a JSON error payload ({"error": {"code": "ImageNotFound"}})
     * when none exists. Either way we stamp avatar_synced_at so the TTL skip holds
     * and we don't re-poll a photoless user every day.
     */
    private function syncPhotoForPerson(Person $person, string $tenantDomain, SyncResult $result): void
    {
        $response = $this->client->getUserPhoto($tenantDomain, $person->cipp_user_id);

        $body = $response['body'] ?? '';
        $contentType = strtolower($response['contentType'] ?? '');

        // No photo: CIPP hands back a JSON error object instead of image bytes.
        $looksLikeJson = str_contains($contentType, 'json')
            || (isset($body[0]) && ($body[0] === '{' || $body[0] === '['));

        if ($body === '' || $looksLikeJson) {
            Person::where('id', $person->id)->update(['avatar_synced_at' => now()]);

            return;
        }

        $jpeg = AvatarHelper::cropToSquareJpeg($body);

        if ($jpeg === null) {
            // Unrecognized/corrupt image data — stamp so we don't retry immediately.
            Person::where('id', $person->id)->update(['avatar_synced_at' => now()]);

            return;
        }

        $path = "avatars/people/{$person->id}.jpg";
        Storage::disk('public')->put($path, $jpeg);

        Person::where('id', $person->id)->update([
            'avatar_path' => $path,
            'avatar_synced_at' => now(),
        ]);
        $result->updated++;
    }

    /**
     * Sync inactive-user status from CIPP. Each pass:
     *   1. Clears cipp_inactive on all Persons for this client (so users that
     *      have re-activated since last sync drop the flag automatically).
     *   2. Flags Persons whose object ID appears in CIPP's inactive list and
     *      captures their last_sign_in_at.
     *
     * CIPP's "inactive" threshold is whatever it's configured for in the
     * customer's CIPP instance — typically 30+ days without sign-in.
     */
    private function enrichInactiveAccounts(Client $client, string $tenantDomain, SyncResult $result): void
    {
        $inactive = $this->client->listInactiveAccounts($tenantDomain);

        if (! is_array($inactive)) {
            return;
        }

        // Build object ID → last sign-in lookup
        $byObjectId = [];
        foreach ($inactive as $row) {
            $objectId = $row['azureAdUserId'] ?? $row['AzureAdUserId'] ?? $row['userId'] ?? null;
            if ($objectId) {
                $byObjectId[$objectId] = $row['lastSignInDateTime'] ?? $row['LastSignInDateTime'] ?? null;
            }
        }

        // Reset the flag on everyone for this client, then set true on the matches.
        Person::where('client_id', $client->id)
            ->where('cipp_inactive', true)
            ->update(['cipp_inactive' => false]);

        if (empty($byObjectId)) {
            return;
        }

        $persons = Person::where('client_id', $client->id)
            ->whereIn('cipp_user_id', array_keys($byObjectId))
            ->get(['id', 'cipp_user_id']);

        foreach ($persons as $person) {
            $lastSignIn = $byObjectId[$person->cipp_user_id] ?? null;
            $updates = [
                'cipp_inactive' => true,
                'cipp_enriched_at' => now(),
            ];
            if ($lastSignIn) {
                try {
                    $updates['last_sign_in_at'] = \Illuminate\Support\Carbon::parse($lastSignIn);
                } catch (\Throwable) {
                    // skip stamp on unparseable date
                }
            }

            Person::where('id', $person->id)->update($updates);
            $result->updated++;
        }
    }

    /**
     * Enrich contacts with mailbox size data from ListMailboxes.
     */
    private function enrichMailboxData(Client $client, string $tenantDomain, SyncResult $result): void
    {
        $mailboxes = $this->client->listMailboxes($tenantDomain);

        if (! is_array($mailboxes) || empty($mailboxes)) {
            return;
        }

        // Build UPN → mailbox data lookup
        $mailboxByUpn = [];
        foreach ($mailboxes as $mb) {
            $upn = mb_strtolower(
                $mb['UPN'] ?? $mb['upn'] ?? $mb['userPrincipalName'] ?? $mb['UserPrincipalName'] ?? ''
            );
            if ($upn) {
                $mailboxByUpn[$upn] = $mb;
            }
        }

        // Update matching persons
        $persons = Person::where('client_id', $client->id)
            ->whereNotNull('cipp_upn')
            ->get(['id', 'cipp_upn']);

        foreach ($persons as $person) {
            $upn = mb_strtolower($person->cipp_upn);
            $mb = $mailboxByUpn[$upn] ?? null;

            if (! $mb) {
                continue;
            }

            $sizeBytes = self::parseMailboxSize(
                $mb['TotalItemSize'] ?? $mb['totalItemSize'] ?? $mb['totalSize'] ?? null
            );
            $itemCount = $mb['ItemCount'] ?? $mb['itemCount'] ?? $mb['totalItems'] ?? null;

            $updates = ['cipp_enriched_at' => now()];
            if ($sizeBytes !== null) {
                $updates['mailbox_size_bytes'] = $sizeBytes;
            }
            if ($itemCount !== null) {
                $updates['mailbox_item_count'] = (int) $itemCount;
            }

            // Forwarding info — top BEC indicator. Always update (including clearing to null
            // when a forward is removed) so we don't keep stale data once an admin fixes things.
            $updates['mailbox_forwarding_smtp'] = self::normalizeAddress(
                $mb['ForwardingSmtpAddress'] ?? $mb['forwardingSmtpAddress'] ?? null
            );
            $updates['mailbox_forwarding_internal'] = self::normalizeAddress(
                $mb['ForwardingAddress'] ?? $mb['forwardingAddress'] ?? null
            );
            $deliverAndForward = $mb['DeliverToMailboxAndForward'] ?? $mb['deliverToMailboxAndForward'] ?? null;
            $updates['mailbox_deliver_and_forward'] = $deliverAndForward === null ? null : (bool) $deliverAndForward;

            Person::where('id', $person->id)->update($updates);
            $result->updated++;
        }
    }

    /**
     * Normalize Exchange forwarding addresses. They can arrive as raw SMTP,
     * "smtp:foo@bar.com", or a recipient DN. We only want the email portion
     * for SMTP; for internal addresses we keep whatever Exchange returned.
     */
    public static function normalizeAddress(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        // "smtp:user@example.com" or "SMTP:user@example.com"
        if (preg_match('/^smtp:(.+)$/i', $value, $m)) {
            return mb_strtolower(trim($m[1]));
        }

        return trim($value);
    }

    /**
     * Enrich contacts with MFA status from ListMFAUsers.
     */
    private function enrichMfaData(Client $client, string $tenantDomain, SyncResult $result): void
    {
        $mfaUsers = $this->client->listMFAUsers($tenantDomain);

        if (! is_array($mfaUsers) || empty($mfaUsers)) {
            return;
        }

        // Build UPN → MFA status lookup
        $mfaByUpn = [];
        foreach ($mfaUsers as $mu) {
            $upn = mb_strtolower(
                $mu['UPN'] ?? $mu['upn'] ?? $mu['userPrincipalName'] ?? $mu['UserPrincipalName'] ?? ''
            );
            if ($upn) {
                $mfaByUpn[$upn] = $mu;
            }
        }

        // Update matching persons
        $persons = Person::where('client_id', $client->id)
            ->whereNotNull('cipp_upn')
            ->get(['id', 'cipp_upn']);

        foreach ($persons as $person) {
            $upn = mb_strtolower($person->cipp_upn);
            $mu = $mfaByUpn[$upn] ?? null;

            if (! $mu) {
                continue;
            }

            // CIPP ListMFAUsers returns MFARegistration as boolean or string
            $mfaRegistered = $mu['MFARegistration'] ?? $mu['mfaRegistration'] ?? null;
            if ($mfaRegistered === null) {
                // Fallback: check PerUser field
                $perUser = $mu['PerUser'] ?? $mu['perUser'] ?? '';
                $mfaRegistered = ! empty($perUser) && strtolower($perUser) !== 'disabled';
            }

            Person::where('id', $person->id)->update([
                'mfa_enabled' => (bool) $mfaRegistered,
                'cipp_enriched_at' => now(),
            ]);
            $result->updated++;
        }
    }

    /**
     * Parse mailbox size from CIPP's mixed format.
     * Handles: integer bytes, "1.234 GB (1325432789 bytes)", "0 bytes", null.
     */
    public static function parseMailboxSize(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Already an integer
        if (is_int($value)) {
            return $value;
        }

        $value = (string) $value;

        // Try to extract bytes from parenthetical: "1.234 GB (1325432789 bytes)"
        if (preg_match('/\(([0-9,]+)\s*bytes?\)/i', $value, $matches)) {
            return (int) str_replace(',', '', $matches[1]);
        }

        // Plain number string
        if (is_numeric($value)) {
            return (int) $value;
        }

        // "0 bytes" or similar
        if (preg_match('/^([0-9,]+)\s*bytes?$/i', $value, $matches)) {
            return (int) str_replace(',', '', $matches[1]);
        }

        return null;
    }
}
