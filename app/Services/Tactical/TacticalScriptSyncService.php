<?php

namespace App\Services\Tactical;

use App\Models\TacticalScript;
use Illuminate\Support\Facades\Log;

class TacticalScriptSyncService
{
    public function __construct(
        private readonly TacticalClient $client,
    ) {}

    public function syncScripts(): array
    {
        $scripts = $this->client->getScripts();

        $stats = ['synced' => 0, 'created' => 0];

        foreach ($scripts as $script) {
            $scriptId = $script['id'] ?? null;
            if (! $scriptId) {
                continue;
            }

            // Skip deprecated scripts
            $category = $script['category'] ?? '';
            if (strtoupper($category) === 'DEPRECATED') {
                continue;
            }

            $tacticalScript = TacticalScript::updateOrCreate(
                ['tactical_script_id' => $scriptId],
                [
                    'name' => $script['name'] ?? 'Unknown',
                    'description' => $script['description'] ?? null,
                    'shell' => $script['shell'] ?? 'powershell',
                    'category' => $category ?: null,
                    'default_timeout' => $script['default_timeout'] ?? 90,
                    'supported_platforms' => $script['supported_platforms'] ?? null,
                    'hidden' => $script['hidden'] ?? false,
                    'synced_at' => now(),
                ]
            );

            if ($tacticalScript->wasRecentlyCreated) {
                $stats['created']++;
            }
            $stats['synced']++;
        }

        // Remove scripts no longer in Tactical
        $syncedIds = collect($scripts)->pluck('id')->filter()->all();
        $removed = TacticalScript::whereNotIn('tactical_script_id', $syncedIds)->delete();
        $stats['removed'] = $removed;

        Log::info('[TacticalSync] Script sync complete', $stats);

        return $stats;
    }
}
