<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Pagination\LengthAwarePaginator;

class AssetService
{
    public function getAssetList(array $filters): LengthAwarePaginator
    {
        $query = Asset::query()->with(['client', 'users' => fn ($q) => $q->wherePivot('is_primary', true)]);

        // Include soft-deleted assets if requested
        if (!empty($filters['show_deleted'])) {
            $query->withTrashed();
        }

        // Active scope (default: active only)
        // When show_deleted is on, include inactive trashed records automatically
        // (Ninja sets is_active=false alongside soft-delete)
        if (empty($filters['show_inactive'])) {
            if (!empty($filters['show_deleted'])) {
                $query->where(fn ($q) => $q->where('assets.is_active', true)->orWhereNotNull('assets.deleted_at'));
            } else {
                $query->where('assets.is_active', true);
            }
        }

        // Search
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Client
        if (!empty($filters['client_id'])) {
            $query->where('assets.client_id', $filters['client_id']);
        }

        // Asset type
        if (!empty($filters['asset_type'])) {
            $query->where('asset_type', $filters['asset_type']);
        }

        // Status (rmm_online column)
        if (!empty($filters['status'])) {
            match ($filters['status']) {
                'online' => $query->where('rmm_online', true),
                'offline' => $query->where('rmm_online', false),
                'unknown' => $query->whereNull('rmm_online'),
                default => null,
            };
        }

        // RMM linkage
        if (!empty($filters['rmm'])) {
            if ($filters['rmm'] === 'linked') {
                $query->where(fn ($q) => $q->whereNotNull('ninja_id')->orWhereNotNull('level_id'));
            } elseif ($filters['rmm'] === 'unlinked') {
                $query->whereNull('ninja_id')->whereNull('level_id');
            }
        }

        // User assignment
        if (!empty($filters['user_assignment'])) {
            if ($filters['user_assignment'] === 'unassigned') {
                $query->whereDoesntHave('users');
            } elseif ($filters['user_assignment'] === 'assigned') {
                $query->whereHas('users');
            }
        }

        // Sorting
        $allowedSorts = [
            'hostname' => 'assets.hostname',
            'name' => 'assets.name',
            'type' => 'assets.asset_type',
            'client' => 'clients.name',
            'os' => 'assets.os',
            'last_seen' => 'assets.last_seen_at',
            'status' => 'assets.rmm_online',
        ];

        $sortKey = $filters['sort'] ?? 'hostname';
        $sortColumn = $allowedSorts[$sortKey] ?? 'assets.hostname';
        $sortDirection = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        // LEFT JOIN only when sorting by client name
        if ($sortColumn === 'clients.name') {
            $query->select('assets.*')->leftJoin('clients', 'assets.client_id', '=', 'clients.id');
        }

        // Nulls last for timestamp and nullable columns
        if (in_array($sortColumn, ['assets.last_seen_at', 'assets.rmm_online', 'assets.asset_type', 'assets.os'])) {
            $query->orderByRaw("{$sortColumn} IS NULL");
        }

        $query->orderBy($sortColumn, $sortDirection);

        // Stable secondary sort
        if ($sortColumn !== 'assets.hostname') {
            $query->orderBy('assets.hostname', 'asc');
        }

        return $query->paginate(50)->withQueryString();
    }


    public function createAsset(array $data): Asset
    {
        return Asset::create($data);
    }

    public function updateAsset(Asset $asset, array $data): Asset
    {
        // Clear rmm_online if the RMM link is being removed so the accessor
        // falls back to last_seen_at instead of showing stale status
        $ninjaCleared = array_key_exists('ninja_id', $data) && empty($data['ninja_id']) && $asset->ninja_id;
        $levelCleared = array_key_exists('level_id', $data) && empty($data['level_id']) && $asset->level_id;

        if ($ninjaCleared || $levelCleared) {
            $data['rmm_online'] = null;
        }

        $asset->update($data);

        return $asset->fresh();
    }

    public function deleteAsset(Asset $asset): void
    {
        // Block deletion if asset has open tickets
        $openTickets = $asset->tickets()
            ->whereIn('status', ['new', 'in_progress', 'pending_client', 'pending_third_party'])
            ->count();

        if ($openTickets > 0) {
            throw new \RuntimeException("Cannot delete asset with {$openTickets} open ticket(s). Resolve or close them first.");
        }

        $asset->delete();
    }
}
