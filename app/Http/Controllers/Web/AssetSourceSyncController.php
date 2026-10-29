<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\AssetSources\SourceSyncPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /assets/{asset}/sources/{source}/sync: the Sources panel's "Sync now".
 * Every staff user may call it (the `auth` group, no admin gate; the one staff
 * role). {source} is constrained to the closed list at the route.
 *
 * The reply is always 200 JSON in the shared shape; a refusal is carried by
 * `outcome` + `reason`, with `retry_after_s` where a wait applies.
 */
class AssetSourceSyncController extends Controller
{
    public function __invoke(Request $request, Asset $asset, string $source, SourceSyncPipeline $pipeline): JsonResponse
    {
        $reply = $pipeline->run($asset->loadMissing(['client', 'tacticalAsset']), $source, $request->user());
        $reply['message'] = SourceSyncPipeline::sentence($reply['reason']);

        return response()->json($reply);
    }
}
