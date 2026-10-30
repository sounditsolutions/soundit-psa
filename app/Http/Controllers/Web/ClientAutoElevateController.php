<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Services\AutoElevate\AutoElevateReadException;
use App\Services\AutoElevate\AutoElevateReadService;
use App\Support\AutoElevateConfig;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only AutoElevate panel on the client page (stage 2). Returns the panel partial for
 * one client in exactly one of five states, each named explicitly so a blank table can
 * never be mistaken for "no machines" (C-56):
 *
 *   switched_off — the integration is switched off in Settings (autoelevate_enabled=0, Q-5 A);
 *                  no vendor call is made and nothing cached is shown
 *   not_mapped   — the client has no autoelevate_company_id (no vendor call is made)
 *   empty        — mapped; the vendor answered and returned zero computers
 *   failed       — mapped; the read was degraded (configuration/transport/status/drift/paging)
 *   ok           — mapped; one or more computers
 *
 * The panel is fetched on demand when the Integrations tab opens (the vendor rate-limits each
 * route; see RATE LIMITS in AutoElevateReadService). Successful reads are cached for 60 seconds per company;
 * a failure is never cached, so the next open retries.
 */
class ClientAutoElevateController extends Controller
{
    public const CACHE_TTL = 60;

    public function __construct(private readonly AutoElevateReadService $reads) {}

    public function computers(Client $client)
    {
        return response()->view('clients.partials.autoelevate', self::panelData($client, $this->reads));
    }

    /**
     * @return array{state: string, reason: ?string, computers: list<array<string, mixed>>, client: Client, linkedAssets?: array<string, Asset>}
     */
    public static function panelData(Client $client, AutoElevateReadService $reads): array
    {
        // OFF means OFF: no vendor call and no cached list while the switch is off.
        if (! AutoElevateConfig::isSwitchedOn()) {
            return ['state' => 'switched_off', 'reason' => null, 'computers' => [], 'client' => $client];
        }

        $companyId = $client->autoelevate_company_id;
        if ($companyId === null || $companyId === '') {
            return ['state' => 'not_mapped', 'reason' => null, 'computers' => [], 'client' => $client];
        }

        if (! AutoElevateConfig::isConfigured()) {
            return ['state' => 'failed', 'reason' => 'configuration', 'computers' => [], 'client' => $client];
        }

        $cacheKey = 'autoelevate_computers_'.strtolower($companyId);
        $computers = Cache::get($cacheKey);
        if (! is_array($computers)) {
            try {
                $computers = $reads->computersForCompany($companyId);
            } catch (AutoElevateReadException $e) {
                return ['state' => 'failed', 'reason' => $e->reason, 'computers' => [], 'client' => $client];
            }
            Cache::put($cacheKey, $computers, self::CACHE_TTL);
        }

        return [
            'state' => $computers === [] ? 'empty' : 'ok',
            'reason' => null,
            'computers' => $computers,
            'client' => $client,
            'linkedAssets' => self::linkedAssets($client, $computers),
        ];
    }

    /**
     * Stage 3a "linked asset" column: computer id => this client's live asset carrying that
     * link (written by AutoElevateAssetSyncService). Scoped to THIS client, so a stale link on
     * another client's asset can never be shown here; trashed assets are excluded by SoftDeletes.
     *
     * @param  list<array<string, mixed>>  $computers
     * @return array<string, Asset>
     */
    public static function linkedAssets(Client $client, array $computers): array
    {
        $ids = array_values(array_filter(array_map(fn ($c) => $c['id'] ?? null, $computers)));
        if ($ids === []) {
            return [];
        }

        $byComputer = [];
        Asset::where('client_id', $client->id)
            ->whereIn('autoelevate_computer_id', $ids)
            ->orderBy('id')
            ->get(['id', 'client_id', 'name', 'hostname', 'autoelevate_computer_id'])
            ->each(function (Asset $asset) use (&$byComputer) {
                $byComputer[strtolower($asset->autoelevate_computer_id)] ??= $asset;
            });

        return $byComputer;
    }
}
