<?php

namespace App\Services\Chet;

use App\Services\Tactical\TacticalReadOnlyToolset;

class ChetDataSurfaceToolExecutor
{
    public function execute(string $toolName, array $input, ?int $clientId): mixed
    {
        if (TacticalReadOnlyToolset::handles($toolName)) {
            if ($clientId === null) {
                return ['error' => 'client_id is required for '.$toolName.'.'];
            }

            return app(TacticalReadOnlyToolset::class)->execute($toolName, $input, $clientId);
        }

        if (TeamsChatReadToolset::handles($toolName)) {
            return app(TeamsChatReadToolset::class)->execute($toolName, $input);
        }

        return ['error' => "Unknown tool: {$toolName}"];
    }
}
