<?php

namespace App\Services\Triage;

use App\Models\Asset;
use App\Models\Ticket;
use App\Services\Ninja\NinjaClient;
use Illuminate\Support\Facades\Log;

/**
 * Matches workstation assets to tickets for auto-assignment.
 * Ported from HaloClaude triage/asset_matcher.py, using local DB + NinjaRMM.
 */
class AssetMatcher
{
    // Asset types that represent workstations/computers.
    private const WORKSTATION_TYPES = [
        'workstation', 'desktop', 'laptop', 'notebook', 'computer', 'pc',
    ];

    /**
     * Attempt to find and link a workstation asset to a ticket.
     */
    public static function match(Ticket $ticket): ?Asset
    {
        // Skip if ticket already has assets linked
        if ($ticket->assets()->count() > 0) {
            Log::debug('[Triage] Asset matching skipped — assets already linked', ['ticket_id' => $ticket->id]);

            return null;
        }

        $ticket->loadMissing(['contact', 'client']);

        $person = $ticket->contact;
        $clientId = $ticket->client_id;

        if (! $clientId) {
            Log::debug('[Triage] Asset matching skipped — no client on ticket', ['ticket_id' => $ticket->id]);

            return null;
        }

        // === Strategy 1: Person's assigned assets ===
        if ($person) {
            $asset = self::tryPersonAssignedAssets($person, $clientId);
            if ($asset) {
                self::linkAsset($ticket, $asset, 'person_assignment');

                return $asset;
            }
        }

        // === Strategy 2: NinjaRMM last-logged-on-user ===
        if ($person) {
            $asset = self::tryNinjaLastUser($person, $clientId);
            if ($asset) {
                self::linkAsset($ticket, $asset, 'ninja_last_user');

                return $asset;
            }
        }

        // === Strategy 3: Asset name contains user name ===
        if ($person) {
            $asset = self::tryAssetNameMatch($person, $clientId);
            if ($asset) {
                self::linkAsset($ticket, $asset, 'name_match');

                return $asset;
            }
        }

        // === Strategy 4: Hostname from ticket text ===
        $summaryText = ($ticket->subject ?? '')."\n".($ticket->description ?? '');
        $hostnames = HostnameExtractor::extractHostnames($summaryText);
        if ($hostnames) {
            foreach ($hostnames as $hostname) {
                $asset = Asset::where('client_id', $clientId)
                    ->whereRaw('UPPER(hostname) = ?', [strtoupper($hostname)])
                    ->first();

                if (! $asset) {
                    $asset = Asset::where('client_id', $clientId)
                        ->whereRaw('UPPER(name) = ?', [strtoupper($hostname)])
                        ->first();
                }

                if ($asset) {
                    self::linkAsset($ticket, $asset, 'hostname_in_text');

                    return $asset;
                }
            }
        }

        Log::info('[Triage] Asset matching: no workstation found', ['ticket_id' => $ticket->id]);

        return null;
    }

    // ── Strategy Implementations ──

    /**
     * Strategy 1: Find workstation assets linked to this person via contracts.
     */
    private static function tryPersonAssignedAssets(\App\Models\Person $person, int $clientId): ?Asset
    {
        // Check assets linked via contract_person -> contract_asset relationships
        $contractIds = $person->contracts()->pluck('contracts.id');

        if ($contractIds->isEmpty()) {
            // Fallback: look for assets with last_user matching the person
            return self::findAssetByLastUser($person, $clientId);
        }

        $assets = Asset::where('client_id', $clientId)
            ->whereHas('contracts', fn ($q) => $q->whereIn('contracts.id', $contractIds))
            ->get();

        $workstations = $assets->filter(fn ($a) => self::isWorkstation($a));

        if ($workstations->isEmpty()) {
            return self::findAssetByLastUser($person, $clientId);
        }

        if ($workstations->count() === 1) {
            return $workstations->first();
        }

        // Multiple: prefer NinjaRMM-linked
        $ninjaLinked = $workstations->filter(fn ($a) => $a->ninja_id);
        if ($ninjaLinked->count() === 1) {
            return $ninjaLinked->first();
        }

        // Prefer name matching user
        foreach ($workstations as $ws) {
            $identifier = $ws->hostname ?? $ws->name ?? '';
            if (ContactResolver::nameMatchesUser($identifier, $person->full_name, $person->email)) {
                return $ws;
            }
        }

        return $ninjaLinked->first() ?: $workstations->first();
    }

    /**
     * Fallback: find a workstation whose last_user matches the person.
     */
    private static function findAssetByLastUser(\App\Models\Person $person, int $clientId): ?Asset
    {
        $workstations = Asset::where('client_id', $clientId)
            ->whereNotNull('last_user')
            ->get()
            ->filter(fn ($a) => self::isWorkstation($a));

        foreach ($workstations as $ws) {
            if (ContactResolver::nameMatchesUser($ws->last_user, $person->full_name, $person->email)) {
                return $ws;
            }
        }

        return null;
    }

    /**
     * Strategy 2: NinjaRMM last-logged-on-user matching.
     */
    private static function tryNinjaLastUser(\App\Models\Person $person, int $clientId): ?Asset
    {
        try {
            $ninja = app(NinjaClient::class);
            if (! $ninja->isHealthy()) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        // Get client's NinjaRMM workstations from local DB
        $workstations = Asset::where('client_id', $clientId)
            ->whereNotNull('ninja_id')
            ->get()
            ->filter(fn ($a) => self::isWorkstation($a))
            ->take(10); // Cap to avoid excessive API calls

        if ($workstations->isEmpty()) {
            return null;
        }

        foreach ($workstations as $ws) {
            // Check local last_user first (avoids API call)
            if ($ws->last_user && ContactResolver::nameMatchesUser($ws->last_user, $person->full_name, $person->email)) {
                return $ws;
            }
        }

        // If local last_user didn't match, try NinjaRMM API for fresher data
        foreach ($workstations as $ws) {
            try {
                $deviceDetail = $ninja->getDevice($ws->ninja_id);
                $lastUser = $deviceDetail['lastLoggedInUser'] ?? $deviceDetail['lastLoggedOnUser'] ?? null;

                if ($lastUser && ContactResolver::nameMatchesUser($lastUser, $person->full_name, $person->email)) {
                    // Update local cache
                    $ws->update(['last_user' => $lastUser]);

                    return $ws;
                }
            } catch (\Throwable $e) {
                Log::debug('[Triage] NinjaRMM device query failed', [
                    'ninja_id' => $ws->ninja_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Strategy 3: Search client assets whose hostname/name contains user name.
     */
    private static function tryAssetNameMatch(\App\Models\Person $person, int $clientId): ?Asset
    {
        $parts = explode(' ', trim($person->full_name));
        if (count($parts) < 2) {
            return null;
        }

        // Search using last name (more unique)
        $searchTerm = end($parts);

        $assets = Asset::where('client_id', $clientId)
            ->where(function ($q) use ($searchTerm) {
                $q->where('hostname', 'like', "%{$searchTerm}%")
                    ->orWhere('name', 'like', "%{$searchTerm}%");
            })
            ->get();

        $workstations = $assets->filter(fn ($a) => self::isWorkstation($a));

        foreach ($workstations as $ws) {
            $identifier = $ws->hostname ?? $ws->name ?? '';
            if (ContactResolver::nameMatchesUser($identifier, $person->full_name, $person->email)) {
                return $ws;
            }
        }

        return null;
    }

    // ── Helpers ──

    private static function isWorkstation(Asset $asset): bool
    {
        $type = strtolower($asset->asset_type ?? '');
        if (! $type) {
            return false;
        }

        foreach (self::WORKSTATION_TYPES as $keyword) {
            if (str_contains($type, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private static function linkAsset(Ticket $ticket, Asset $asset, string $method): void
    {
        $ticket->assets()->syncWithoutDetaching([
            $asset->id => ['is_primary' => true],
        ]);

        Log::info('[Triage] Asset linked to ticket', [
            'ticket_id' => $ticket->id,
            'asset_id' => $asset->id,
            'hostname' => $asset->hostname,
            'method' => $method,
        ]);
    }
}
