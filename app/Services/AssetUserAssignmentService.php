<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssetUserAssignmentService
{
    public function assignAll(?int $clientId = null, bool $dryRun = false): array
    {
        $query = Asset::whereNotNull('last_user')
            ->where('last_user', '!=', '')
            ->where('is_active', true)
            ->whereNotNull('client_id');

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $stats = ['processed' => 0, 'matched' => 0, 'already_linked' => 0, 'no_match' => 0, 'ambiguous' => 0];

        $query->chunk(100, function ($assets) use (&$stats, $dryRun) {
            foreach ($assets as $asset) {
                $stats['processed']++;
                $result = $this->assignForAsset($asset, $dryRun);
                $stats[$result]++;
            }
        });

        return $stats;
    }

    public function assignForAsset(Asset $asset, bool $dryRun = false): string
    {
        $lastUser = trim($asset->last_user);
        if (! $lastUser) {
            return 'no_match';
        }

        $username = $this->parseUsername($lastUser);
        $clientId = $asset->client_id;

        // Tier 1: Exact UPN/email match
        $person = $this->matchByUpnOrEmail($lastUser, $username, $clientId);

        // Tier 2: Name-based fuzzy match
        if (! $person) {
            $result = $this->matchByName($username, $clientId);
            if ($result === 'ambiguous') {
                return 'ambiguous';
            }
            $person = $result;
        }

        if (! $person) {
            return 'no_match';
        }

        // Check if already linked
        $existing = DB::table('asset_person')
            ->where('asset_id', $asset->id)
            ->where('person_id', $person->id)
            ->first();

        if ($existing) {
            if (! $dryRun) {
                DB::table('asset_person')
                    ->where('id', $existing->id)
                    ->update(['last_seen_at' => now(), 'updated_at' => now()]);
            }

            return 'already_linked';
        }

        if (! $dryRun) {
            DB::table('asset_person')->insert([
                'asset_id' => $asset->id,
                'person_id' => $person->id,
                'is_primary' => false,
                'assignment_source' => 'auto',
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::debug('[AssetUserAssignment] Linked', [
                'asset' => $asset->hostname ?? $asset->name,
                'person' => $person->full_name,
                'last_user' => $lastUser,
            ]);
        }

        return 'matched';
    }

    private function parseUsername(string $lastUser): string
    {
        if (str_contains($lastUser, '\\')) {
            return Str::after($lastUser, '\\');
        }
        if (str_contains($lastUser, '@')) {
            return Str::before($lastUser, '@');
        }

        return $lastUser;
    }

    private function matchByUpnOrEmail(string $lastUser, string $username, int $clientId): ?Person
    {
        if (str_contains($lastUser, '@')) {
            $person = Person::where('client_id', $clientId)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereRaw('LOWER(cipp_upn) = ?', [strtolower($lastUser)])
                    ->orWhereRaw('LOWER(email) = ?', [strtolower($lastUser)]))
                ->first();
            if ($person) {
                return $person;
            }
        }

        if (! str_contains($lastUser, '@')) {
            $person = Person::where('client_id', $clientId)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereRaw('LOWER(cipp_upn) LIKE ?', [strtolower($username).'@%'])
                    ->orWhereRaw('LOWER(email) LIKE ?', [strtolower($username).'@%']))
                ->first();
            if ($person) {
                return $person;
            }
        }

        return null;
    }

    /**
     * @return Person|string|null Person if unambiguous, 'ambiguous' if multiple, null if none
     */
    private function matchByName(string $username, int $clientId): Person|string|null
    {
        $username = strtolower($username);

        $candidates = Person::where('client_id', $clientId)
            ->where('is_active', true)
            ->get(['id', 'first_name', 'last_name', 'email', 'cipp_upn']);

        $matches = [];

        foreach ($candidates as $person) {
            $firstName = strtolower($person->first_name ?? '');
            $lastName = strtolower($person->last_name ?? '');

            if ($firstName && $username === $firstName) {
                $matches[] = $person;

                continue;
            }

            if ($firstName && $lastName) {
                $patterns = [
                    $firstName[0].$lastName,
                    $firstName.'.'.$lastName,
                    $firstName.$lastName,
                    $lastName.$firstName[0],
                    $firstName.'_'.$lastName,
                ];

                if (in_array($username, $patterns, true)) {
                    $matches[] = $person;
                }
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            return 'ambiguous';
        }

        return null;
    }
}
