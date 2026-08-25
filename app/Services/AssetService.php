<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AssetService
{
    /**
     * External identity column => the vendor companion columns carried with it.
     *
     * The identity columns are what RMM/vendor syncs and webhooks match on
     * (several write `deleted_at => null` straight through on a match, e.g.
     * NinjaSyncService/LevelSyncService and their webhook paths), so a merged
     * duplicate MUST end with every one of these cleared. Necessary but NOT
     * sufficient: those same syncs fall back to a serial_number match guarded by
     * `whereNull(<vendor id>)`, and clearing the vendor ids is exactly what makes
     * the tombstone eligible for that fallback — so mergeAssets clears the
     * duplicate's serial_number too.
     * ninja_id / level_id / halo_id / controld_device_id / zorus_endpoint_id /
     * comet_device_id / m365_device_id / screenconnect_session_id are UNIQUE
     * indexes, which forces the clear-duplicate-then-save-survivor order below.
     */
    private const EXTERNAL_IDENTITY_COLUMNS = [
        'ninja_id' => ['ninja_url', 'ninja_synced_at'],
        'level_id' => ['level_url', 'level_synced_at'],
        'halo_id' => [],
        'tactical_asset_id' => [],
        'controld_device_id' => ['controld_profile_name', 'controld_status', 'controld_agent_status', 'controld_agent_version', 'controld_last_seen_at', 'controld_synced_at'],
        'zorus_endpoint_id' => ['zorus_group_name', 'zorus_filtering_enabled', 'zorus_cybersight_enabled', 'zorus_agent_version', 'zorus_agent_state', 'zorus_last_seen_at', 'zorus_synced_at'],
        'comet_device_id' => ['comet_username', 'comet_backup_enabled', 'backup_cloud_bytes', 'backup_local_bytes', 'backup_revisions_bytes', 'backup_synced_at'],
        'servosity_dr_backup_id' => ['servosity_backup_enabled', 'servosity_backup_password'],
        'm365_device_id' => ['m365_compliance_state', 'm365_is_compliant', 'm365_enrollment_type', 'm365_os_version', 'm365_last_sync_at', 'm365_device_owner_type', 'm365_defender_status', 'm365_defender_version', 'm365_last_scan_at', 'm365_synced_at'],
        'screenconnect_session_id' => ['screenconnect_online', 'screenconnect_client_version', 'screenconnect_last_seen_at', 'screenconnect_synced_at'],
    ];

    /** Plain descriptive fields carried onto the survivor only where it is blank. */
    private const FILL_BLANK_COLUMNS = [
        'serial_number', 'hostname', 'asset_type', 'os', 'cpu', 'ram_gb',
        'disk_summary', 'ip_address', 'last_user', 'warranty_start',
        'warranty_end', 'last_seen_at', 'last_boot_at',
    ];
    public function getAssetList(array $filters): LengthAwarePaginator
    {
        $query = Asset::query()->with(['client', 'users' => fn ($q) => $q->wherePivot('is_primary', true)]);

        // Include soft-deleted assets if requested
        if (! empty($filters['show_deleted'])) {
            $query->withTrashed();
        }

        // Active scope (default: active only)
        // When show_deleted is on, include inactive trashed records automatically
        // (the show_deleted branch also surfaces soft-deleted rows regardless of is_active)
        if (empty($filters['show_inactive'])) {
            if (! empty($filters['show_deleted'])) {
                $query->where(fn ($q) => $q->where('assets.is_active', true)->orWhereNotNull('assets.deleted_at'));
            } else {
                $query->where('assets.is_active', true);
            }
        }

        // Search
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Client
        if (! empty($filters['client_id'])) {
            $query->where('assets.client_id', $filters['client_id']);
        }

        // Asset type
        if (! empty($filters['asset_type'])) {
            $query->where('asset_type', $filters['asset_type']);
        }

        // Status (rmm_online column)
        if (! empty($filters['status'])) {
            match ($filters['status']) {
                // "online" must exclude assets whose rmm_online froze true while the
                // sync went quiet — a last_seen_at older than the staleness window is
                // Stale, not Online (psa-wedk). A null last_seen_at fails open to online,
                // matching Asset::isRmmDataStale().
                'online' => $query->where('rmm_online', true)
                    ->where(function ($q) {
                        $q->whereNull('last_seen_at')
                            ->orWhere('last_seen_at', '>', now()->subHours(Asset::rmmStaleAfterHours()));
                    }),
                // "offline" must also catch an asset whose rmm_online froze true
                // while the sync went quiet. The sweep that stops seeing a
                // Tactical agent deliberately leaves the flag at its last
                // OBSERVED value, so a decommissioned machine would otherwise
                // match NEITHER pill — the "online" branch above already excludes
                // it — and be unfindable. Its badge still reads "Stale", not
                // "Offline": this widens what the filter FINDS, it does not claim
                // we observed the machine down. Exact mirror of the "online"
                // predicate, so online plus offline covers every non-null flag.
                'offline' => $query->where(function ($q) {
                    $q->where('rmm_online', false)
                        ->orWhere(function ($stale) {
                            $stale->where('rmm_online', true)
                                ->whereNotNull('last_seen_at')
                                ->where('last_seen_at', '<=', now()->subHours(Asset::rmmStaleAfterHours()));
                        });
                }),
                'unknown' => $query->whereNull('rmm_online'),
                default => null,
            };
        }

        // RMM linkage
        if (! empty($filters['rmm'])) {
            if ($filters['rmm'] === 'linked') {
                $query->where(fn ($q) => $q->whereNotNull('ninja_id')->orWhereNotNull('level_id'));
            } elseif ($filters['rmm'] === 'unlinked') {
                $query->whereNull('ninja_id')->whereNull('level_id');
            }
        }

        // Health (cached health score) — "unhealthy" = a known Poor score.
        // Unknown (null) scores are excluded: we can't call an unmonitored
        // device unhealthy.
        if (($filters['health'] ?? '') === 'unhealthy') {
            $query->whereNotNull('assets.health_score')
                ->where('assets.health_score', '<', \App\Enums\AssetHealthGrade::FAIR_THRESHOLD);
        }

        // User assignment
        if (! empty($filters['user_assignment'])) {
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
            'health' => 'assets.health_score',
        ];

        $sortKey = $filters['sort'] ?? 'hostname';
        $sortColumn = $allowedSorts[$sortKey] ?? 'assets.hostname';
        $sortDirection = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        // LEFT JOIN only when sorting by client name
        if ($sortColumn === 'clients.name') {
            $query->select('assets.*')->leftJoin('clients', 'assets.client_id', '=', 'clients.id');
        }

        // Nulls last for timestamp and nullable columns
        if (in_array($sortColumn, ['assets.last_seen_at', 'assets.rmm_online', 'assets.asset_type', 'assets.os', 'assets.health_score'])) {
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

    /**
     * External-identity columns where BOTH rows carry a value and the values
     * differ. Two live vendor identities is two devices, not a duplicate —
     * every caller must refuse the merge and name the pairs. Public so the
     * MCP propose path can refuse at proposal time, not only at approval.
     *
     * @return array<string, array{survivor: mixed, duplicate: mixed}> column => both values
     */
    public function assetMergeIdentityConflicts(Asset $survivor, Asset $duplicate): array
    {
        $conflicts = [];
        foreach (array_keys(self::EXTERNAL_IDENTITY_COLUMNS) as $column) {
            $survivorValue = $survivor->getAttribute($column);
            $duplicateValue = $duplicate->getAttribute($column);
            if ($survivorValue !== null && $duplicateValue !== null
                && (string) $survivorValue !== (string) $duplicateValue) {
                $conflicts[$column] = ['survivor' => $survivorValue, 'duplicate' => $duplicateValue];
            }
        }

        return $conflicts;
    }

    /**
     * Both rows carrying their OWN Tactical agent record — the same two-live-agents
     * situation as a differing vendor id. The two link directions populate
     * INDEPENDENTLY (assets.tactical_asset_id on the asset row, tactical_assets.asset_id
     * pointing back), so each side is resolved from BOTH: a pair where the survivor is
     * linked only by the column and the duplicate only by its detail row is still two
     * live agents, and reading one direction alone would let mergeAssets repoint them
     * onto a single asset. The SAME detail row on both sides is one agent double-linked,
     * not a conflict — mirroring assetMergeIdentityConflicts. Public for the same reason
     * as that method: mergeAssets throws on this, so the MCP propose path and the
     * approval revalidation must refuse it too rather than let an un-approvable pair
     * reach the cockpit and blow up at approval.
     */
    public function assetMergeHasTacticalConflict(Asset $survivor, Asset $duplicate): bool
    {
        $survivorAgents = $this->tacticalAgentRowIds($survivor);
        $duplicateAgents = $this->tacticalAgentRowIds($duplicate);

        return $survivorAgents !== [] && $duplicateAgents !== []
            && count(array_unique(array_merge($survivorAgents, $duplicateAgents))) > 1;
    }

    /**
     * Every Tactical detail row this asset is linked to, by detail-row id, across BOTH
     * link directions — tactical_assets.asset_id pointing at the asset, and the asset's
     * own tactical_asset_id column. Either can be populated without the other, so the
     * conflict guard must read both. A column pointing at a row that no longer exists
     * still counts: a refusal is the safe side of a stale link.
     *
     * @return array<int, int>
     */
    private function tacticalAgentRowIds(Asset $asset): array
    {
        $ids = DB::table('tactical_assets')->where('asset_id', $asset->id)->pluck('id')->all();

        $linked = $asset->getAttribute('tactical_asset_id');
        if ($linked !== null && $linked !== '') {
            $ids[] = $linked;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Merge a duplicate asset into a surviving asset within the same client (#584).
     *
     * Repoints every reference to the duplicate (ticket links, alerts, user and
     * contract assignments, Tactical detail row and action logs, ScreenConnect
     * events), carries blank-only descriptive fields and any external vendor
     * identity the survivor lacks, clears EVERY external identity AND the
     * serial_number on the duplicate (the RMM sync/webhook paths match on vendor
     * id and FALL BACK to a serial_number match guarded by `whereNull(<vendor
     * id>)`, then write `deleted_at => null` straight through, never restore() —
     * so clearing the ids alone would leave the tombstone newly eligible for the
     * serial leg), then tombstones the duplicate: merged_into_asset_id set,
     * inactive, soft-deleted. Mirrors PersonService::mergePeople: query-builder repoints
     * (no model events), pessimistic locking, one transaction.
     *
     * A retired (soft-deleted) duplicate is accepted — retire is exactly the
     * wrong tool this replaces, so the common repair case is a duplicate
     * someone already retired. The survivor must be live.
     *
     * Moved contract assignments are stored as `manual` with no rule_id so
     * rule reconciliation never strips the consolidated link — same reasoning
     * as mergePeople.
     *
     * @return array{tickets:int,tickets_folded:int,halo_link_conflicts:array<int,string>,alerts:int,users:int,contracts:int,tactical_logs:int,screenconnect_events:int,reactivated_survivor:bool,billing_warnings:array<int,string>,carried_identities:array<int,string>}
     */
    public function mergeAssets(Asset $survivor, Asset $duplicate, int $mergedByUserId): array
    {
        if ($survivor->id === $duplicate->id) {
            throw new \InvalidArgumentException('Cannot merge an asset into itself.');
        }

        if ($survivor->client_id === null || $survivor->client_id !== $duplicate->client_id) {
            throw new \InvalidArgumentException('Cannot merge assets from different clients. A cross-client duplicate is a mislinked asset — fix the client link first.');
        }

        return DB::transaction(function () use ($survivor, $duplicate, $mergedByUserId) {
            // Pessimistic lock both rows for the duration of the merge. The
            // duplicate may already be soft-deleted (retired); the survivor may not.
            $survivor = Asset::lockForUpdate()->findOrFail($survivor->id);
            $duplicate = Asset::withTrashed()->lockForUpdate()->findOrFail($duplicate->id);

            // Re-run the caller-facing guards against the LOCKED rows, not the
            // pre-lock snapshots: a concurrent reassignment between the caller's
            // fetch and this transaction would otherwise let a cross-client merge
            // through — the one invariant this service says must never break.
            if ($survivor->id === $duplicate->id) {
                throw new \InvalidArgumentException('Cannot merge an asset into itself.');
            }
            if ($survivor->client_id === null || $survivor->client_id !== $duplicate->client_id) {
                throw new \InvalidArgumentException('Cannot merge assets from different clients. A cross-client duplicate is a mislinked asset — fix the client link first.');
            }

            if ($survivor->merged_into_asset_id !== null) {
                throw new \RuntimeException("Asset #{$survivor->id} was itself merged away and cannot survive a merge.");
            }
            if ($duplicate->merged_into_asset_id !== null) {
                throw new \RuntimeException("Asset #{$duplicate->id} was already merged into asset #{$duplicate->merged_into_asset_id}.");
            }

            $conflicts = $this->assetMergeIdentityConflicts($survivor, $duplicate);
            if ($conflicts !== []) {
                $pairs = [];
                foreach ($conflicts as $column => $values) {
                    $pairs[] = "{$column} (survivor: {$values['survivor']}, duplicate: {$values['duplicate']})";
                }
                throw new \RuntimeException('Refusing to merge: both assets carry a live external identity that differs — two live agents is two devices, not a duplicate. Conflicting: '.implode('; ', $pairs).'.');
            }

            // Both assets carrying their OWN Tactical agent record is the same
            // two-live-agents situation, whichever direction each side is linked by
            // (assets.tactical_asset_id, or tactical_assets.asset_id pointing back) —
            // the guard resolves both so a mixed-direction pair cannot slip past and
            // have the repoint below collapse two agents onto one asset.
            $duplicateHasTactical = DB::table('tactical_assets')->where('asset_id', $duplicate->id)->exists();
            if ($this->assetMergeHasTacticalConflict($survivor, $duplicate)) {
                throw new \RuntimeException('Refusing to merge: both assets have a linked Tactical agent record — two live agents is two devices, not a duplicate.');
            }

            // Ticket links — move the ones the survivor lacks (keeping is_primary /
            // halo_asset_id on the moved row). For a ticket BOTH assets are linked to
            // the duplicate's row can't move (unique ticket_id+asset_id), so fold its
            // pivot data into the survivor's row before dropping it: is_primary and the
            // Halo binding live only on that row and would otherwise be hard-deleted.
            $survivorTicketIds = DB::table('ticket_asset')->where('asset_id', $survivor->id)->pluck('ticket_id')->all();
            $ticketCount = DB::table('ticket_asset')
                ->where('asset_id', $duplicate->id)
                ->whereNotIn('ticket_id', $survivorTicketIds)
                ->update(['asset_id' => $survivor->id]);

            $foldedTicketCount = 0;
            $haloLinkConflicts = [];
            foreach (DB::table('ticket_asset')->where('asset_id', $duplicate->id)->get() as $sharedLink) {
                $survivorLink = DB::table('ticket_asset')
                    ->where('asset_id', $survivor->id)
                    ->where('ticket_id', $sharedLink->ticket_id)
                    ->first();
                if (! $survivorLink) {
                    continue;
                }

                $folded = [];
                if (empty($survivorLink->halo_asset_id) && ! empty($sharedLink->halo_asset_id)) {
                    $folded['halo_asset_id'] = $sharedLink->halo_asset_id;
                } elseif (! empty($sharedLink->halo_asset_id)
                    && (string) $survivorLink->halo_asset_id !== (string) $sharedLink->halo_asset_id) {
                    // Both rows carry a Halo binding and they DIFFER. The duplicate's row is
                    // hard-deleted below (unique ticket_id+asset_id blocks a move), so record the
                    // divergence rather than dropping it silently — same policy as the asset_type
                    // divergence: the survivor's value is kept, never rewritten, and never silent.
                    $haloLinkConflicts[] = "ticket #{$sharedLink->ticket_id} (kept '{$survivorLink->halo_asset_id}', dropped '{$sharedLink->halo_asset_id}')";
                }
                // Never create a second primary asset on the ticket — same rule as
                // the user assignments below.
                if (! $survivorLink->is_primary && $sharedLink->is_primary
                    && ! DB::table('ticket_asset')
                        ->where('ticket_id', $sharedLink->ticket_id)
                        ->where('asset_id', '!=', $duplicate->id)
                        ->where('is_primary', true)
                        ->exists()) {
                    $folded['is_primary'] = true;
                }

                if ($folded !== []) {
                    DB::table('ticket_asset')
                        ->where('asset_id', $survivor->id)
                        ->where('ticket_id', $sharedLink->ticket_id)
                        ->update($folded);
                    $foldedTicketCount++;
                }
            }

            DB::table('ticket_asset')->where('asset_id', $duplicate->id)->delete();

            // Alerts, Tactical action history, ScreenConnect events — plain FK repoints.
            $alertCount = DB::table('alerts')->where('asset_id', $duplicate->id)->update(['asset_id' => $survivor->id]);
            $tacticalLogCount = DB::table('tactical_action_logs')->where('asset_id', $duplicate->id)->update(['asset_id' => $survivor->id]);
            $screenconnectCount = DB::table('screenconnect_events')->where('asset_id', $duplicate->id)->update(['asset_id' => $survivor->id]);

            // User assignments — move the ones the survivor lacks, preserving
            // primary/last-seen, stored as manual so nothing auto-strips them.
            // Never create a second primary user on the survivor.
            $survivorPersonIds = $survivor->users()->pluck('people.id')->all();
            $survivorHasPrimaryUser = $survivor->users()->wherePivot('is_primary', true)->exists();
            $movedUsers = 0;
            foreach ($duplicate->users as $person) {
                if (! in_array($person->id, $survivorPersonIds, true)) {
                    $isPrimary = (bool) $person->pivot->is_primary && ! $survivorHasPrimaryUser;
                    $survivor->users()->attach($person->id, [
                        'is_primary' => $isPrimary,
                        'assignment_source' => 'manual',
                        'last_seen_at' => $person->pivot->last_seen_at,
                    ]);
                    $survivorHasPrimaryUser = $survivorHasPrimaryUser || $isPrimary;
                    $movedUsers++;
                }
            }
            $duplicate->users()->detach();

            // Contract assignments — move the ones the survivor lacks; manual,
            // no rule_id, so rule reconciliation never strips the moved link.
            $survivorContractIds = $survivor->contracts()->pluck('contracts.id')->all();
            $movedContracts = 0;
            foreach ($duplicate->contracts as $contract) {
                if (! in_array($contract->id, $survivorContractIds, true)) {
                    $survivor->contracts()->attach($contract->id, [
                        'assigned_at' => $contract->pivot->assigned_at ?? now(),
                        'assignment_source' => 'manual',
                    ]);
                    $movedContracts++;
                }
            }
            $duplicate->contracts()->detach();

            // Tactical detail row — repoint to the survivor (the both-sides case
            // was refused above).
            if ($duplicateHasTactical) {
                DB::table('tactical_assets')->where('asset_id', $duplicate->id)->update(['asset_id' => $survivor->id]);
            }

            // Plain descriptive fields — fill blanks only, never overwrite.
            foreach (self::FILL_BLANK_COLUMNS as $column) {
                $survivorValue = $survivor->getAttribute($column);
                if (($survivorValue === null || $survivorValue === '') && $duplicate->getAttribute($column) !== null) {
                    $survivor->{$column} = $duplicate->getAttribute($column);
                }
            }

            // External identities — carry each one the survivor lacks, with its
            // vendor companion fields (blank-only), so the next sync binds to the
            // survivor instead of the tombstone.
            $carriedIdentities = [];
            foreach (self::EXTERNAL_IDENTITY_COLUMNS as $column => $companions) {
                if ($survivor->getAttribute($column) === null && $duplicate->getAttribute($column) !== null) {
                    $survivor->{$column} = $duplicate->getAttribute($column);
                    $carriedIdentities[] = $column;
                    foreach ($companions as $companion) {
                        if ($survivor->getAttribute($companion) === null && $duplicate->getAttribute($companion) !== null) {
                            $survivor->{$companion} = $duplicate->getAttribute($companion);
                        }
                    }
                }
            }

            // Clear EVERY external identity on the duplicate — carried or not.
            // This, not any restore guard, is what stops the sync/webhook paths
            // resurrecting the tombstone on the next device event.
            foreach (array_keys(self::EXTERNAL_IDENTITY_COLUMNS) as $column) {
                $duplicate->{$column} = null;
            }

            // Billing safety: BillingService::countAssets counts not-trashed +
            // is_active + billed asset_type, so retiring a live duplicate into an
            // inactive survivor would silently drop a still-deployed device from the
            // client's billed count. Reactivate the survivor instead. asset_type is
            // fill-blank-only (never overwritten), so a survivor that already carries a
            // different type is REPORTED rather than rewritten — the technician decides
            // which type is right, but the divergence is never silent.
            $duplicateWasLive = ! $duplicate->trashed() && (bool) $duplicate->is_active;
            $reactivatedSurvivor = false;
            $billingWarnings = [];
            if ($duplicateWasLive && ! $survivor->is_active) {
                $survivor->is_active = true;
                $reactivatedSurvivor = true;
            }
            $duplicateType = $duplicate->getAttribute('asset_type');
            if ($duplicateWasLive && $duplicateType !== null && $duplicateType !== ''
                && (string) $survivor->asset_type !== (string) $duplicateType) {
                $billingWarnings[] = "Device type kept as '{$survivor->asset_type}'; the merged live duplicate was '{$duplicateType}' — confirm the type is right for billing.";
            }

            // Audit notes: a record line on the survivor, a tombstone on the duplicate.
            $merger = User::find($mergedByUserId)?->name ?? 'Unknown';
            $when = now()->toDateString();
            $moved = [];
            foreach ([
                [$ticketCount, 'ticket', 'tickets'],
                [$alertCount, 'alert', 'alerts'],
                [$movedUsers, 'user assignment', 'user assignments'],
                [$movedContracts, 'contract', 'contracts'],
                [$tacticalLogCount, 'Tactical action log', 'Tactical action logs'],
                [$screenconnectCount, 'ScreenConnect event', 'ScreenConnect events'],
            ] as [$n, $one, $many]) {
                if ($n) {
                    $moved[] = "{$n} ".($n === 1 ? $one : $many);
                }
            }
            $movedSummary = $moved ? ' Moved: '.implode(', ', $moved).'.' : '';
            $carriedSummary = $carriedIdentities ? ' Carried identities: '.implode(', ', $carriedIdentities).'.' : '';
            $foldedSummary = $foldedTicketCount
                ? " Kept Halo/primary link data from {$foldedTicketCount} shared ticket link".($foldedTicketCount === 1 ? '' : 's').'.'
                : '';
            $haloConflictSummary = $haloLinkConflicts
                ? ' Halo link divergence on '.implode('; ', $haloLinkConflicts).' — the duplicate carried a different halo_asset_id and that binding was dropped; confirm the right binding in Halo.'
                : '';
            $billingSummary = $reactivatedSurvivor ? ' Reactivated this asset: it absorbed a live device.' : '';
            $warningSummary = $billingWarnings ? ' '.implode(' ', $billingWarnings) : '';
            $duplicateLabel = ($duplicate->hostname ?: $duplicate->name)." (#{$duplicate->id})";
            $survivorLabel = ($survivor->hostname ?: $survivor->name)." (#{$survivor->id})";

            $survivor->notes = trim(($survivor->notes ? $survivor->notes."\n\n" : '')
                ."Merged duplicate asset '{$duplicateLabel}' on {$when} by {$merger}.{$movedSummary}{$foldedSummary}{$haloConflictSummary}{$carriedSummary}{$billingSummary}{$warningSummary}");

            // The tombstone's serial_number is a resurrection key in its own right:
            // NinjaSyncService/LevelSyncService fall back to
            // withTrashed()->where('serial_number', …)->whereNull('ninja_id'/'level_id')
            // and write `deleted_at => null` straight through — and clearing the vendor
            // ids above is precisely what makes this row eligible for that fallback. The
            // serial is already carried to the survivor where it was blank, so clear it
            // here and keep the record in the tombstone note.
            $clearedSerial = $duplicate->serial_number;
            // The serial only reaches the survivor through FILL_BLANK_COLUMNS, i.e. only when
            // the survivor's own serial was blank. When the survivor already carried a
            // different serial the cleared value now lives on NO row, so the tombstone note
            // must say that rather than claim matching binds to the survivor.
            $serialCarriedToSurvivor = $clearedSerial !== null && $clearedSerial !== ''
                && (string) $survivor->serial_number === (string) $clearedSerial;
            $duplicate->serial_number = null;

            $duplicate->merged_into_asset_id = $survivor->id;
            $duplicate->is_active = false;
            $duplicate->rmm_online = null;
            $duplicate->notes = trim(($duplicate->notes ? $duplicate->notes."\n\n" : '')
                ."Merged into '{$survivorLabel}' on {$when} by {$merger}."
                .($clearedSerial
                    ? ($serialCarriedToSurvivor
                        ? " Serial number '{$clearedSerial}' cleared from this tombstone so RMM serial-number matching binds to the survivor."
                        : " Serial number '{$clearedSerial}' cleared from this tombstone so an RMM serial-number match cannot resurrect it. The survivor kept its own serial '{$survivor->serial_number}', so '{$clearedSerial}' is now on NO asset — if it is the device's real serial, set it on the survivor or the next sync will create a new asset.")
                    : ''));

            // Persist the duplicate FIRST: it clears the duplicate's UNIQUE
            // external IDs so the survivor can take them on its own save without
            // colliding on the unique indexes.
            $duplicate->save();
            $survivor->save();

            // Preserve the original retire timestamp if the duplicate was
            // already soft-deleted before the merge.
            if (! $duplicate->trashed()) {
                $duplicate->delete();
            }

            return [
                'tickets' => $ticketCount,
                'tickets_folded' => $foldedTicketCount,
                'halo_link_conflicts' => $haloLinkConflicts,
                'alerts' => $alertCount,
                'users' => $movedUsers,
                'contracts' => $movedContracts,
                'tactical_logs' => $tacticalLogCount,
                'screenconnect_events' => $screenconnectCount,
                'reactivated_survivor' => $reactivatedSurvivor,
                'billing_warnings' => $billingWarnings,
                'carried_identities' => $carriedIdentities,
            ];
        });
    }

    /**
     * Deliberately offboard (soft-delete) an Asset at operator request.
     * Assets are only ever soft-deleted here and in mergeAssets() (tombstoning
     * the merged-away duplicate) — RMM sync jobs must NEVER call delete() on an
     * Asset; they clear only their own vendor fields.
     */
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
