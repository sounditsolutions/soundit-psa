<?php

namespace App\Console\Commands;

use App\Services\Mesh\MeshAllowRuleReaper;
use App\Support\MeshConfig;
use Illuminate\Console\Command;

/**
 * #1018 — expire the allow rules Mesh will not expire itself.
 *
 * Scheduled daily. Exits FAILURE when anything was left live, so a silent
 * scheduler does not become the reason an allow rule outlives its approval.
 */
class MeshReapAllowRules extends Command
{
    protected $signature = 'mesh:reap-allow-rules';

    protected $description = 'Delete Mesh allow rules past their PSA-recorded expiry, proving absence by re-read';

    public function handle(MeshAllowRuleReaper $reaper): int
    {
        if (! MeshConfig::isConfigured()) {
            $this->error('Mesh is not configured. Add the API key in Settings → Integrations.');

            return self::FAILURE;
        }

        $counts = $reaper->reap();

        $this->info(sprintf(
            'Examined %d expired allow rule(s): %d reaped, %d unresolved, %d failed.',
            $counts['examined'],
            $counts['reaped'],
            $counts['unresolved'],
            $counts['failed'],
        ));

        return ($counts['unresolved'] + $counts['failed']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
