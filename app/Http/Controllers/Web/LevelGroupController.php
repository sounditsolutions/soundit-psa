<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Level\LevelClient;
use App\Services\Level\LevelClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LevelGroupController extends Controller
{
    public function index(LevelClient $level)
    {
        try {
            $groups = $level->getGroups();
        } catch (LevelClientException) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Could not connect to Level RMM. Check credentials.');
        }

        // Sort groups by name
        usort($groups, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        // Build mapping: level_group_id → client
        $mappedClients = Client::whereNotNull('level_group_id')
            ->get(['id', 'name', 'level_group_id'])
            ->keyBy('level_group_id');

        return view('settings.level-groups', [
            'groups' => $groups,
            'mappedClients' => $mappedClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        DB::transaction(function () use ($mappings) {
            // First, clear all existing level_group_id mappings
            Client::whereNotNull('level_group_id')->update(['level_group_id' => null]);

            // Apply new mappings
            foreach ($mappings as $levelGroupId => $clientId) {
                if ($clientId) {
                    Client::where('id', $clientId)->update(['level_group_id' => $levelGroupId]);
                }
            }
        });

        $mapped = collect($mappings)->filter()->count();

        return redirect()->route('settings.level-groups.index')
            ->with('success', "Saved {$mapped} group mapping(s).");
    }

    public function apiGroups(LevelClient $level)
    {
        try {
            $groups = $level->getGroups();
            usort($groups, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

            return response()->json($groups);
        } catch (LevelClientException) {
            return response()->json([], 503);
        }
    }
}
